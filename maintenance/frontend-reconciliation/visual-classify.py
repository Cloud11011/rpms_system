"""Classify every ZIP-vs-reconciled capture pair from visual-parity.json (all 120, no sampling).

Per pair: visible-text differences, control-inventory differences (tag+text), computed-style differences of
matching controls, and the pixel statistics from visual-diff.json. Output is grouped per route so a reviewer judges
each distinct difference once and applies the judgement to all of that route's light/dark, 390/1440 captures.
Usage: python -I visual-classify.py <review dir>
"""
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

review = Path(sys.argv[1])
data = json.loads((review / 'visual-parity.json').read_text(encoding='utf-8'))
pixels = {(r['role'], r['route'], r['theme'], r['width']): r
          for r in json.loads((review / 'visual-parity' / 'visual-diff.json').read_text(encoding='utf-8'))['pairs']}
STYLE = ['fontFamily', 'fontSize', 'fontWeight', 'color', 'backgroundColor', 'borderRadius', 'paddingTop', 'paddingLeft',
         'borderTopColor', 'borderTopWidth', 'boxShadow', 'textTransform', 'letterSpacing']
rows = []
for pair in data['pairs']:
    ref, rec = pair['variants']['reference']['inventory'], pair['variants']['reconciled']['inventory']
    refText, recText = Counter(ref['text']), Counter(rec['text'])
    onlyRef = sorted((refText - recText).elements())
    onlyRec = sorted((recText - refText).elements())
    # Controls: match by (tag, text) in document order; style differences only where both sides have the control.
    def group(items):
        g = defaultdict(list)
        for item in items:
            g[(item['tag'], item['text'])].append(item)
        return g
    gRef, gRec = group(ref['items']), group(rec['items'])
    styleDiffs, sizeDiffs = [], []
    for key in sorted(set(gRef) & set(gRec)):
        for a, b in zip(gRef[key], gRec[key]):
            diff = {k: [a['style'][k], b['style'][k]] for k in STYLE if a['style'][k] != b['style'][k]}
            if diff:
                styleDiffs.append({'control': f'{key[0]}:{key[1]}', 'class': b['cls'] or a['cls'], 'diff': diff})
            if abs(a['w'] - b['w']) > 2 or abs(a['h'] - b['h']) > 2:
                sizeDiffs.append({'control': f'{key[0]}:{key[1]}', 'ref': [a['w'], a['h']], 'rec': [b['w'], b['h']]})
    # Attribute wording (placeholders, tooltips, accessible names) is invisible to innerText: compare it per matched control.
    attrDiffs = []
    for key in sorted(set(gRef) & set(gRec)):
        for a, b in zip(gRef[key], gRec[key]):
            for field in ('placeholder', 'title', 'ariaLabel'):
                if a.get(field, '') != b.get(field, ''):
                    attrDiffs.append(f"{key[0]}:{key[1]} [{field}] ZIP={a.get(field, '')!r} reconciled={b.get(field, '')!r}")
    ctlOnlyRef = sorted(f'{t}:{x}' for (t, x) in set(gRef) - set(gRec) if x)
    ctlOnlyRec = sorted(f'{t}:{x}' for (t, x) in set(gRec) - set(gRef) if x)
    wrapZip = [f"{b['tag']}.{b['cls']}:{b['text']} ({b['w']}x{b['h']}, {b['lines']} lines)" for b in ref.get('wrapAnomalies', [])]
    wrapRec = [f"{b['tag']}.{b['cls']}:{b['text']} ({b['w']}x{b['h']}, {b['lines']} lines)" for b in rec.get('wrapAnomalies', [])]
    px = pixels[(pair['role'], pair['route'], pair['theme'], pair['width'])]
    rows.append({'role': pair['role'], 'route': pair['route'], 'theme': pair['theme'], 'width': pair['width'],
                 'textOnlyZip': onlyRef, 'textOnlyReconciled': onlyRec, 'controlsOnlyZip': ctlOnlyRef, 'controlsOnlyReconciled': ctlOnlyRec,
                 'attributeDiffs': attrDiffs,
                 'wrapAnomaliesZip': wrapZip, 'wrapAnomaliesReconciled': wrapRec,
                 'styleDiffs': styleDiffs, 'sizeDiffs': sizeDiffs, 'matchedControls': len(set(gRef) & set(gRec)),
                 'topChangedRatio': px['topChangedRatio'], 'changedRatio': px['changedRatio'], 'heightDelta': px['heightDelta']})
out = review / 'visual-parity' / 'visual-classification.json'
out.write_text(json.dumps(rows, indent=1), encoding='utf-8')

# Per-route consolidation: the union of distinct differences across the route's four captures.
routes = defaultdict(list)
for r in rows:
    routes[(r['role'], r['route'])].append(r)
summary = []
for (role, route), rs in sorted(routes.items()):
    summary.append({
        'role': role, 'route': route, 'captures': len(rs),
        'textOnlyZip': sorted({t for r in rs for t in r['textOnlyZip']}),
        'textOnlyReconciled': sorted({t for r in rs for t in r['textOnlyReconciled']}),
        'controlsOnlyZip': sorted({t for r in rs for t in r['controlsOnlyZip']}),
        'controlsOnlyReconciled': sorted({t for r in rs for t in r['controlsOnlyReconciled']}),
        'attributeDiffs': sorted({d for r in rs for d in r['attributeDiffs']}),
        'wrapAnomaliesZip': sorted({d for r in rs for d in r['wrapAnomaliesZip']}),
        'wrapAnomaliesReconciled': sorted({d for r in rs for d in r['wrapAnomaliesReconciled']}),
        'styleDiffs': sorted({(d['control'], tuple(sorted(d['diff']))) for r in rs for d in r['styleDiffs']}),
        'maxTopChanged': max(r['topChangedRatio'] for r in rs), 'maxChanged': max(r['changedRatio'] for r in rs),
    })
(review / 'visual-parity' / 'visual-route-summary.json').write_text(json.dumps(summary, indent=1, default=list), encoding='utf-8')
print(json.dumps({'pairs': len(rows), 'routes': len(summary),
                  'pairsWithStyleDiffs': sum(bool(r['styleDiffs']) for r in rows),
                  'pairsWithControlDiffs': sum(bool(r['controlsOnlyZip'] or r['controlsOnlyReconciled']) for r in rows),
                  'pairsWithTextDiffs': sum(bool(r['textOnlyZip'] or r['textOnlyReconciled']) for r in rows),
                  'pairsWithWrapAnomalyReconciled': sum(bool(r['wrapAnomaliesReconciled']) for r in rows),
                  'pairsWithWrapAnomalyZip': sum(bool(r['wrapAnomaliesZip']) for r in rows),
                  'pairsWithNoDifference': sum(not (r['styleDiffs'] or r['controlsOnlyZip'] or r['controlsOnlyReconciled'] or r['textOnlyZip'] or r['textOnlyReconciled']) for r in rows)}))
