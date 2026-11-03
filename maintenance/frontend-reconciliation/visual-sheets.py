"""One review sheet per route that differs: all four captures (390/1440 x light/dark), ZIP on the left, reconciled on the right.
Usage: python -I visual-sheets.py <review dir> [route-substring ...]
"""
import json
import sys
from pathlib import Path

from PIL import Image, ImageDraw

review = Path(sys.argv[1])
only = sys.argv[2:]
captures = review / 'visual-parity'
rows = json.loads((captures / 'visual-classification.json').read_text(encoding='utf-8'))
pairs = json.loads((review / 'visual-parity.json').read_text(encoding='utf-8'))['pairs']
differing = {(r['role'], r['route']) for r in rows
             if r['styleDiffs'] or r['controlsOnlyZip'] or r['controlsOnlyReconciled'] or r['textOnlyZip'] or r['textOnlyReconciled']}
out = captures / 'sheets'
out.mkdir(exist_ok=True)
for role, route in sorted(differing):
    if only and not any(o in route or o == role for o in only):
        continue
    group = {(p['theme'], p['width']): p for p in pairs if p['role'] == role and p['route'] == route}
    cells = []
    for width, crop, scale in ((1440, 1250, 0.42), (390, 1500, 0.60)):
        for theme in ('light', 'dark'):
            p = group[(theme, width)]
            imgs = [Image.open(captures / p['variants'][v]['file']).convert('RGB') for v in ('reference', 'reconciled')]
            tiles = []
            for img in imgs:
                tile = img.crop((0, 0, img.width, min(img.height, crop)))
                tiles.append(tile.resize((max(1, int(tile.width * scale)), max(1, int(tile.height * scale)))))
            w = sum(t.width for t in tiles) + 8
            h = max(t.height for t in tiles) + 18
            cell = Image.new('RGB', (w, h), (120, 120, 120))
            cell.paste(tiles[0], (0, 18)); cell.paste(tiles[1], (tiles[0].width + 8, 18))
            ImageDraw.Draw(cell).text((4, 3), f'{theme} {width}px   ZIP | reconciled', fill=(255, 255, 255))
            cells.append(cell)
    # Desktop column (light over dark) and mobile column (light over dark).
    col1 = Image.new('RGB', (max(c.width for c in cells[:2]), sum(c.height for c in cells[:2]) + 8), (60, 60, 60))
    y = 0
    for c in cells[:2]:
        col1.paste(c, (0, y)); y += c.height + 8
    col2 = Image.new('RGB', (max(c.width for c in cells[2:]), sum(c.height for c in cells[2:]) + 8), (60, 60, 60))
    y = 0
    for c in cells[2:]:
        col2.paste(c, (0, y)); y += c.height + 8
    sheet = Image.new('RGB', (col1.width + col2.width + 12, max(col1.height, col2.height)), (60, 60, 60))
    sheet.paste(col1, (0, 0)); sheet.paste(col2, (col1.width + 12, 0))
    name = f"{role}-{route.replace('.php', '').replace('#', '_').replace('/', '_')}.png"
    sheet.save(out / name, optimize=True)
    print(name, sheet.size)
