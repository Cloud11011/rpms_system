"""Pixel comparison of card-validate.cjs captures: committed CSS ("old") versus working-tree CSS ("new") per theme and width.
Widths of 521px and above must be pixel-identical (the approved rule only applies at 520px and below).
Usage: python -I card-validate-compare.py <review dir>   (exit 1 if a wide capture differs)"""
import json
import sys
from pathlib import Path

from PIL import Image, ImageChops

folder = Path(sys.argv[1]) / 'visual-parity' / 'card-validate'
meta = json.loads((folder / 'card-validate.json').read_text(encoding='utf-8'))
rows, failed = [], False
for width in meta['widths']:
    for theme in ('light', 'dark'):
        a = Image.open(folder / f'ierbprog-{theme}-{width}-old.png').convert('RGB')
        b = Image.open(folder / f'ierbprog-{theme}-{width}-new.png').convert('RGB')
        same_size = a.size == b.size
        diff = ImageChops.difference(a, b.resize(a.size) if not same_size else b) if same_size else None
        changed = 0 if diff is None else sum(diff.convert('L').point(lambda v: 255 if v else 0).histogram()[255:])
        identical = same_size and changed == 0
        rows.append({'theme': theme, 'width': width, 'oldSize': list(a.size), 'newSize': list(b.size), 'identical': identical, 'changedPixels': changed})
        if width >= 521 and not identical:
            failed = True
for r in rows:
    expected = 'must be identical' if r['width'] >= 521 else 'rule applies'
    print(f"{r['theme']:5} {r['width']:5}  {'IDENTICAL' if r['identical'] else 'differs  '}  old {r['oldSize']} new {r['newSize']}  ({expected})")
(folder / 'card-validate-compare.json').write_text(json.dumps(rows, indent=2), encoding='utf-8')
print('RESULT:', 'FAIL - a width of 521px or more changed' if failed else 'PASS - nothing at 521px and above changed')
sys.exit(1 if failed else 0)
