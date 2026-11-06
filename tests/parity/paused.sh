#!/bin/bash
# Simulates a Statamic release renaming something Flatpack relies on: renames Store::$fileChangesHandled
# throughout the app's copy of Statamic, then checks Flatpack pauses (status says so, the Stache runs on
# its own store, build and refresh fall back to Statamic's warm) and reads still match plain Statamic.
# Puts Statamic's files back however it ends.
# tests/parity/paused.sh <flatpack app> <oracle dump to match> <out dir>
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; C="$1"; EXPECT="$2"; OUT="$3"
PHP=("${PHP_BIN:-php}" -d memory_limit=-1)
BACKUP="$(mktemp -d)"
FILES=$(grep -rl fileChangesHandled "$C/vendor/statamic/cms/src")
for f in $FILES; do mkdir -p "$BACKUP$(dirname "$f")"; cp "$f" "$BACKUP$f"; done
restore() { for f in $FILES; do cp "$BACKUP$f" "$f"; done; rm -rf "$BACKUP"; }
trap restore EXIT
sed -i.bak 's/fileChangesHandled/fileChangesWereHandled/g' $FILES && for f in $FILES; do rm -f "$f.bak"; done

cd "$C"
export FLATPACK_ENABLED=true
"${PHP[@]}" please flatpack:status 2>&1 | grep -q "Flatpack is paused" || { echo "    flatpack:status doesn't say Flatpack is paused"; exit 1; }
store=$("${PHP[@]}" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("statamic.stache.cache_store");')
[ "$store" != flatpack ] || { echo "    the Stache is still on Flatpack's store"; exit 1; }
"${PHP[@]}" please flatpack:build -q || { echo "    flatpack:build failed while paused"; exit 1; }
"${PHP[@]}" please flatpack:refresh -q || { echo "    flatpack:refresh failed while paused"; exit 1; }
"${PHP[@]}" "$HERE/dump.php" "$C" "$OUT/c-paused.json" >/dev/null || { echo "    reading content failed while paused"; exit 1; }
python3 "$HERE/compare.py" "$EXPECT" "$OUT/c-paused.json" --show 5
