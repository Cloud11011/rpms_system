"""Read-only production inventory and optional private-config-free baseline snapshot."""
import collections
import hashlib
import json
from pathlib import Path
import re
import shutil
import sys

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/frontend-standardization-results'
OUT.mkdir(parents=True, exist_ok=True)
paths = sorted([*ROOT.glob('*.php'), *ROOT.glob('includes/**/*.php'),
                *ROOT.glob('assets/css/*.css'), *ROOT.glob('assets/js/*.js')])
entries = []
selectors = collections.defaultdict(list)
for path in paths:
    if path.name == 'config.local.php':
        continue
    source = path.read_text(encoding='utf-8-sig')
    entry = {'file': path.relative_to(ROOT).as_posix(), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
             'html': bool(re.search(r'<(?:html|body|main|section|form|button|dialog)\b', source, re.I)),
             'assets': sorted(set(re.findall(r'assets/(?:css|js)/[\w.-]+', source))),
             'includes': sorted(set(re.findall(r"(?:require|include)(?:_once)?\s*[^;\n]*?['\"]([^'\"]+\.php)['\"]", source))),
             'classes': sorted(set(re.findall(r'class(?:Name)?\s*[=:]\s*[\'\"]([^\'\"]*)', source))),
             'ids': sorted(set(re.findall(r'id\s*=\s*[\'\"]([^\'\"]*)', source))),
             'ui_states': sorted(set(re.findall(r'[^\n]{0,70}(?:empty|loading|disabled|hidden|aria-busy|role=[\'\"]alert|validation)[^\n]{0,100}', source, re.I))),
             'api_consumers': sorted(set(re.findall(r'[\w-]+_api\.php', source))),
             'inline_styles': len(re.findall(r'<style\b|style\s*=', source)),
             'ui_rendering': len(re.findall(r'innerHTML|createElement|insertAdjacentHTML|replaceChildren', source))}
    if path.suffix == '.css':
        entry['imports'] = re.findall(r'@import[^;]+;', source)
        for selector in re.findall(r'([^{}]+)\{', re.sub(r'/\*.*?\*/', '', source, flags=re.S)):
            selector = ' '.join(selector.split())
            if not selector.startswith('@'):
                selectors[selector].append(entry['file'])
    entries.append(entry)
inventory = {'files': entries, 'duplicate_selectors': {k: v for k, v in selectors.items() if len(set(v)) > 1}}
(OUT / 'inventory.json').write_text(json.dumps(inventory, indent=2), encoding='utf-8')
print(json.dumps({'files': len(entries), 'html_php': sum(e['html'] for e in entries if e['file'].endswith('.php')),
                  'css': sum(e['file'].endswith('.css') for e in entries),
                  'js': sum(e['file'].endswith('.js') for e in entries),
                  'duplicate_selectors': len(inventory['duplicate_selectors'])}))
for entry in entries:
    if entry['html'] and entry['file'].endswith('.php'):
        print(entry['file'], '|', ', '.join(entry['assets']), '| inline:', entry['inline_styles'])
if '--snapshot' in sys.argv:
    snapshot = OUT / 'baseline-source'
    if snapshot.exists():
        raise SystemExit('Baseline already exists; never overwrite before evidence.')
    snapshot.mkdir()
    for path in ROOT.glob('*.php'):
        if path.name != 'config.local.php':
            shutil.copy2(path, snapshot / path.name)
    for folder in ['includes', 'assets', 'vendor', 'tests']:
        shutil.copytree(ROOT / folder, snapshot / folder,
                        ignore=shutil.ignore_patterns('*results*', '__pycache__', 'composer-cache', 'ui-restyle-reference'))
    # Only deterministic cached public fonts/icons, checked by SHA-256 by the browser harness.
    cache = 'tests/v10-batch1075-results/remediation/cdn-cache'
    shutil.copytree(ROOT / cache, snapshot / cache)
    print('Baseline snapshot:', snapshot)
