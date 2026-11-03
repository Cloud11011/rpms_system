"""Pairwise ZIP-vs-reconciled comparison of visual-parity.cjs captures.

Writes per-pair statistics and side-by-side composites (ZIP | reconciled | difference). The statistics rank
pairs for human review; they do not decide parity, because V14-authoritative data legitimately differs.
Usage: python -I visual-diff.py <review dir>
"""
import json
import sys
from pathlib import Path

from PIL import Image, ImageChops, ImageOps

review = Path(sys.argv[1])
captures = review / 'visual-parity'
out = captures / 'composites'
out.mkdir(exist_ok=True)
report = json.loads((review / 'visual-parity.json').read_text(encoding='utf-8'))
rows = []
for pair in report['pairs']:
    ref = Image.open(captures / pair['variants']['reference']['file']).convert('RGB')
    rec = Image.open(captures / pair['variants']['reconciled']['file']).convert('RGB')
    width, height = max(ref.width, rec.width), max(ref.height, rec.height)
    a = Image.new('RGB', (width, height), 'white'); a.paste(ref, (0, 0))
    b = Image.new('RGB', (width, height), 'white'); b.paste(rec, (0, 0))
    diff = ImageChops.difference(a, b).convert('L')
    changed = sum(diff.point(lambda v: 255 if v > 32 else 0).histogram()[255:])
    # Same-height prefix: layout drift shifts everything below it, so the top of the page is reported separately.
    top = min(ref.height, rec.height, 900)
    top_changed = sum(diff.crop((0, 0, width, top)).point(lambda v: 255 if v > 32 else 0).histogram()[255:])
    stem = Path(pair['variants']['reconciled']['file']).stem.replace('-reconciled', '')
    heat = ImageOps.colorize(diff, black='white', white='red')
    composite = Image.new('RGB', (width * 3 + 40, height), (200, 200, 200))
    for i, img in enumerate((a, b, heat)):
        composite.paste(img, (i * (width + 20), 0))
    scale = min(1.0, 2400 / composite.width)
    if scale < 1:
        composite = composite.resize((int(composite.width * scale), int(composite.height * scale)))
    composite.save(out / f'{stem}.png', optimize=True)
    rows.append({'role': pair['role'], 'route': pair['route'], 'theme': pair['theme'], 'width': pair['width'],
                 'referenceSize': list(ref.size), 'reconciledSize': list(rec.size),
                 'changedRatio': round(changed / (width * height), 4), 'topChangedRatio': round(top_changed / (width * top), 4),
                 'heightDelta': rec.height - ref.height, 'composite': f'composites/{stem}.png'})
rows.sort(key=lambda r: (-r['topChangedRatio'], -r['changedRatio']))
(captures / 'visual-diff.json').write_text(json.dumps({'pairs': rows, 'threshold': 'channel difference > 32 of 255',
    'note': 'Ranking aid only. Data differences where V14 is authoritative are expected; review composites.'}, indent=2), encoding='utf-8')
print(json.dumps({'pairs': len(rows), 'top10': [(r['role'], r['route'], r['theme'], r['width'], r['topChangedRatio'], r['changedRatio']) for r in rows[:10]]}, indent=1))
