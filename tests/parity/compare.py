#!/usr/bin/env python3
"""Compare two parity dumps: python3 compare.py oracle.json candidate.json [--show N]
Exit 1 on any difference. Prints a per-section summary and the first N differences."""
import json, sys
a, b = json.load(open(sys.argv[1])), json.load(open(sys.argv[2]))
show = int(sys.argv[sys.argv.index('--show') + 1]) if '--show' in sys.argv else 8
bad = 0
for sec in [s for s in a if s != 'meta']:
    A, B = a.get(sec, {}), b.get(sec, {})
    if sec not in b:
        print(f'{sec}: MISSING in candidate'); bad += 1; continue
    keys = sorted(set(A) | set(B))
    diffs = [k for k in keys if A.get(k) != B.get(k)]
    print(f'{sec:11} {len(keys) - len(diffs):6}/{len(keys)} same' + (f'   {len(diffs)} DIFFERENT' if diffs else ''))
    for k in diffs[:show]:
        x, y = A.get(k), B.get(k)
        if isinstance(x, dict) and isinstance(y, dict):
            fields = [f for f in sorted(set(x) | set(y)) if x.get(f) != y.get(f)]
            print(f'   {k}: ' + '; '.join(f'{f}: {x.get(f)!r} -> {y.get(f)!r}' for f in fields)[:400])
        else:
            print(f'   {k}: {str(x)[:180]} -> {str(y)[:180]}')
    bad += len(diffs)
sys.exit(1 if bad else 0)
