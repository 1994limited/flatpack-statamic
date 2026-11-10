#!/bin/bash
# Parity: the same app twice, one with plain Statamic (the oracle), one with Flatpack, compared
# through reads, saves through the API, a rebuild, and files changed on disk then refreshed.
# tests/parity/run.sh <oracle app> <flatpack app> <out dir>
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; O="$1"; C="$2"; OUT="$3"; mkdir -p "$OUT"
PHP=("${PHP_BIN:-php}" -d memory_limit=-1)
fails=0
check() { local name="$1"; shift; if "$@"; then echo "  PASS  $name"; else echo "  FAIL  $name"; fails=$((fails+1)); fi; }
oracle() { FLATPACK_ENABLED=false "${PHP[@]}" "$@"; }
flatpack() { FLATPACK_ENABLED=true "${PHP[@]}" "$@"; }
reset() { "${PHP[@]}" "$HERE/generate.php" "$1" "${PARITY_ENTRIES:-120}" >/dev/null; rm -rf "$1/storage/statamic" "$1/storage/framework/cache/data"; mkdir -p "$1/storage/framework/cache/data"; }
build() { (cd "$C" && flatpack please flatpack:build -q) || { echo "  build failed:"; (cd "$C" && flatpack please flatpack:build 2>&1 | head -15); exit 1; }; }
same() { check "$1" python3 "$HERE/compare.py" "$OUT/o-$2.json" "$OUT/c-$2.json" --show 5; }
dump() { oracle "$HERE/dump.php" "$O" "$OUT/o-$1.json" >/dev/null && flatpack "$HERE/dump.php" "$C" "$OUT/c-$1.json" >/dev/null; }

echo "Statamic $(cd "$O" && "${PHP[@]}" -r 'require "vendor/autoload.php"; echo Composer\InstalledVersions::getPrettyVersion("statamic/cms");')"
reset "$O"; reset "$C"; build
dump cold; same "cold read" cold
dump warm; same "warm read" warm

oracle "$HERE/dirty.php" "$O" "$OUT/o-dirty.json" >/dev/null && flatpack "$HERE/dirty.php" "$C" "$OUT/c-dirty.json" >/dev/null
check "change tracking: same original values and dirty state" python3 -c "
import json,sys
a=json.load(open(sys.argv[1])); b=json.load(open(sys.argv[2]))
[print('   ', k, a[k], '->', b.get(k)) for k in a if a[k]!=b.get(k)]
sys.exit(0 if a==b and len(a)>10 else 1)" "$OUT/o-dirty.json" "$OUT/c-dirty.json"

oracle "$HERE/mutate.php" "$O" "$OUT/o-mutate.json" >/dev/null
flatpack "$HERE/mutate.php" "$C" "$OUT/c-mutate.json" >/dev/null
check "saves: same steps and outcomes" python3 -c "
import json,sys
a=[(d['step'],d['ref'],d['error']) for d in json.load(open(sys.argv[1]))]; b=[(d['step'],d['ref'],d['error']) for d in json.load(open(sys.argv[2]))]
errs=[x for x in a if x[2]]
[print('   ', x, '->', y) for x,y in zip(a,b) if x!=y]; [print('    oracle error:', e) for e in errs]
sys.exit(0 if a==b and not errs else 1)" "$OUT/o-mutate.json" "$OUT/c-mutate.json"
check "saves: identical files written" python3 "$HERE/compare-trees.py" "$O/content" "$C/content"
dump saved; same "reads after saves" saved

oracle "$HERE/clear.php" "$O" >/dev/null; rm -rf "$C/storage/statamic/flatpack"; build
dump rebuilt; same "rebuilt from the saved files" rebuilt

reset "$O"; reset "$C"; build
"$HERE/edit-files.sh" "$O" >/dev/null; "$HERE/edit-files.sh" "$C" >/dev/null
oracle "$HERE/clear.php" "$O" >/dev/null
(cd "$C" && flatpack please flatpack:refresh -q) || { echo "  refresh failed:"; (cd "$C" && flatpack please flatpack:refresh 2>&1 | head -15); fails=$((fails+1)); }
dump edited; same "files changed on disk, then flatpack:refresh" edited

# A deploy into a new release directory with shared storage: the app is at a new path and the
# compiled content, which holds absolute paths, comes with it. A build there, or a refresh, has
# to give the same answers from the new path.
M="$C-moved"; rm -rf "$M" "$M-again"; cp -R "$C" "$M"
(cd "$M" && flatpack please flatpack:build -q) || { echo "  build after moving failed:"; (cd "$M" && flatpack please flatpack:build 2>&1 | head -15); fails=$((fails+1)); }
flatpack "$HERE/dump.php" "$M" "$OUT/c-moved.json" >/dev/null
check "moved to a new directory, then flatpack:build" python3 "$HERE/compare.py" "$OUT/o-edited.json" "$OUT/c-moved.json" --show 5
cp -R "$M" "$M-again"
(cd "$M-again" && flatpack please flatpack:refresh -q) || { echo "  refresh after moving failed:"; (cd "$M-again" && flatpack please flatpack:refresh 2>&1 | head -15); fails=$((fails+1)); }
flatpack "$HERE/dump.php" "$M-again" "$OUT/c-moved-again.json" >/dev/null
check "moved again, then flatpack:refresh" python3 "$HERE/compare.py" "$OUT/o-edited.json" "$OUT/c-moved-again.json" --show 5
rm -rf "$M" "$M-again"

# Switched off, Flatpack must stay out of the way: Statamic's own commands work, and
# flatpack:build / flatpack:refresh fall back to Statamic's own clear + warm.
check "switched off: stache:warm, flatpack:build and flatpack:refresh still work" bash -c "cd '$O' && FLATPACK_ENABLED=false '${PHP[0]}' please stache:warm -q && FLATPACK_ENABLED=false '${PHP[0]}' please flatpack:build -q && FLATPACK_ENABLED=false '${PHP[0]}' please flatpack:refresh -q"

# A Statamic release that renames something Flatpack reaches inside of: Flatpack must pause and
# leave the site on Statamic's own Stache, with the same answers as plain Statamic.
check "Statamic changed something Flatpack relies on: Flatpack pauses, reads still match" "$HERE/paused.sh" "$C" "$OUT/o-edited.json" "$OUT"

echo "$fails failing check(s)"; exit $fails
