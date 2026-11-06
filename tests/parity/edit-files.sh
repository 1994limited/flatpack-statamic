#!/bin/bash
# Out-of-band file changes (hand edits, git pull, deploy), the same in any app dir.
# tests/parity/edit-files.sh <app dir>
set -euo pipefail
C="$1/content/collections"
f1=$(ls "$C/blog/en/"*.md | sort | sed -n 3p)                       # hand edit a title
perl -pi -e "s/^title: .*/title: 'Edited on disk'/" "$f1"
f2=$(grep -l page_builder "$C/pages/en/"*.md | sort | sed -n 5p)      # hand edit inside a page builder
perl -0pi -e 's/(\n {6,}text: )[^\n]*/$1Edited block text/' "$f2"
f3=$(ls "$C/team/en/"*.md | sort | sed -n 2p)                           # delete a file
rm "$f3"
cat > "$C/blog/en/2026-03-04.added-on-disk.md" <<'MD'
---
id: 22222222-3333-4444-8555-666666666666
title: 'Added on disk'
tags:
  - tag-2
---
MD
f5=$(ls "$C/blog/en/"*.md | sort | sed -n 4p)                        # rename (slug change on disk)
mv "$f5" "$(dirname "$f5")/$(basename "$f5" | cut -d. -f1).renamed-on-disk.md"
t="$1/content/trees/collections/en/pages.yaml"                       # reorder the tree: swap two top-level pages
python3 - "$t" <<'PY'
import sys, re
p = sys.argv[1]; s = open(p).read()
blocks = re.split(r'(?m)^(?=  -\n)', s)
head, items = blocks[0], blocks[1:]
if len(items) > 3: items[1], items[2] = items[2], items[1]
open(p, 'w').write(head + ''.join(items))
PY
touch -t 203001010000 "$f1" "$f2" "$C/blog/en/2026-03-04.added-on-disk.md" "$t"   # mtimes move forward
echo "edited $(basename "$f1"), $(basename "$f2"); deleted $(basename "$f3"); added one; renamed $(basename "$f5"); reordered the pages tree"
