#!/usr/bin/env python3
"""Compare two content/ trees (oracle vs candidate), ignoring updated_at / updated_by lines.
python3 compare-trees.py <oracle content dir> <candidate content dir>   (exit 1 on difference)"""
import os, re, sys
strip = lambda s: re.sub(r'(?m)^updated_(at|by): .*\n', '', s)
def tree(root):
    out = {}
    for d, _, fs in os.walk(root):
        for f in fs:
            p = os.path.join(d, f); out[os.path.relpath(p, root)] = strip(open(p, encoding='utf-8', errors='replace').read())
    return out
a, b = tree(sys.argv[1]), tree(sys.argv[2])
diff = sorted(k for k in set(a) | set(b) if a.get(k) != b.get(k))
print(f'content files: {len(set(a) | set(b)) - len(diff)} same, {len(diff)} different')
for k in diff[:10]: print('  ', k, '' if k in a else '(only in candidate)', '' if k in b else '(only in oracle)')
sys.exit(1 if diff else 0)
