"""Static dependency closure of a public runtime tree: every literal include and asset reference must resolve inside it.

Usage: python -I runtime-closure-check.py <tree> [--allowlist <manifest.json>]
With --allowlist the tree is first assembled from the repository restricted to that manifest's runtime entries
(use it to test an old manifest without publishing anything). Reads only; writes nothing outside a temporary folder.
"""
import json
import re
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
args = sys.argv[1:]
manifest = None
if '--allowlist' in args:
    i = args.index('--allowlist')
    manifest = Path(args[i + 1])
    del args[i:i + 2]
if manifest is not None:
    names = json.loads(manifest.read_text(encoding='utf-8'))['runtime']
    tree = Path(tempfile.mkdtemp(prefix='closure-'))
    for name in names:
        src = ROOT / name
        if src.is_file():
            (tree / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, tree / name)
else:
    tree = Path(args[0])

INCLUDE = re.compile(r"""\b(?:require|include)(?:_once)?\s*\(?\s*(?:__DIR__|dirname\(__DIR__\))\s*\.\s*['"]([^'"$]+)['"]""")
INCLUDE_PLAIN = re.compile(r"""\b(?:require|include)(?:_once)?\s*\(?\s*['"]([^'"$]+\.php)['"]""")
READ = re.compile(r"""(?:file_get_contents|is_file|file_exists)\s*\(\s*(?:__DIR__|dirname\(__DIR__\))\s*\.\s*['"]([^'"$]+)['"]""")
ASSET = re.compile(r"""asset_url\(\s*['"]([^'"$]+)['"]""")
REF = re.compile(r"""(?:href|src)=["'](assets/[^"'?#$<]+)""")
missing = {}
checked = 0
for php in sorted(tree.rglob('*.php')):
    rel = php.relative_to(tree).as_posix()
    if rel.startswith('vendor/'):
        continue
    text = php.read_text(encoding='utf-8', errors='replace')
    base = php.parent
    refs = []
    for rx in (INCLUDE, READ):
        for m in rx.finditer(text):
            target = m.group(1)
            # dirname(__DIR__).'/x' is relative to the parent folder; __DIR__.'/x' to the file's own folder.
            anchor = base.parent if 'dirname(__DIR__)' in text[max(0, m.start()):m.end()] else base
            refs.append(('include', (anchor / target.lstrip('/')).resolve()))
    for m in INCLUDE_PLAIN.finditer(text):
        refs.append(('include', (base / m.group(1)).resolve()))
    for rx in (ASSET, REF):
        for m in rx.finditer(text):
            refs.append(('asset', (tree / m.group(1)).resolve()))
    for kind, target in refs:
        checked += 1
        try:
            target.relative_to(tree.resolve())
        except ValueError:
            continue
        if not target.exists():
            missing.setdefault(rel, set()).add(target.relative_to(tree.resolve()).as_posix())
# The cron runner names its step scripts through a variable, which the literal patterns above cannot follow.
runner = tree / 'maintenance' / 'hostinger-cron-runner.php'
if runner.is_file():
    for step, script in re.findall(r"'([a-z0-9]+)'\s*=>\s*'(migrate-[a-z0-9-]+\.php)'", runner.read_text(encoding='utf-8', errors='replace')):
        checked += 1
        if not (tree / 'maintenance' / script).is_file():
            missing.setdefault('maintenance/hostinger-cron-runner.php', set()).add('maintenance/' + script + f'  (runner step {step})')
for rel, targets in sorted(missing.items()):
    for t in sorted(targets):
        print(f'MISSING  {rel}  ->  {t}')
print(json.dumps({'tree': str(tree) if manifest is None else '(assembled from allowlist)', 'phpFiles': sum(1 for _ in tree.rglob('*.php')),
                  'referencesChecked': checked, 'missingReferences': sum(len(v) for v in missing.values()), 'filesWithMissing': len(missing)}))
if manifest is not None:
    shutil.rmtree(tree, ignore_errors=True)
sys.exit(1 if missing else 0)
