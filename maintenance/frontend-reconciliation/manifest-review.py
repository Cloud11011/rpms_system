"""Final review of the publication allowlist (maintenance/release/public-runtime-files.json).

1. Every entry is a tracked file on disk, with no duplicate or unsorted entry.
2. No entry is sensitive by the builder's own rules, by extension, or by content (key/token patterns, real-looking credentials).
3. Necessity: every non-entry-point file is referenced by another allowlisted file; root pages and APIs are HTTP entry points;
   maintenance scripts are CLI entry points and must carry their own PHP_SAPI guard (defense in depth beside .htaccess).
4. For a list of added paths, who references each one.
Usage: python -I manifest-review.py [--added <file with one path per line>] [--out report.json]
Reads only. Exit 1 on any hard failure; unreferenced files are reported for human review (exit 0)."""
import argparse
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ap = argparse.ArgumentParser()
ap.add_argument('--added')
ap.add_argument('--out')
args = ap.parse_args()
spec = importlib.util.spec_from_file_location('builder', ROOT / 'maintenance/release/build-public-runtime.py')
builder = importlib.util.module_from_spec(spec)
sys.dont_write_bytecode = True
spec.loader.exec_module(builder)
manifest = json.loads((ROOT / 'maintenance/release/public-runtime-files.json').read_text(encoding='utf-8'))
runtime = manifest['runtime']
tracked = set(subprocess.run(['git', 'ls-files'], cwd=ROOT, capture_output=True, text=True).stdout.split('\n'))
fail = []
if runtime != sorted(set(runtime)):
    fail.append('runtime entries are not sorted and unique')
for name in runtime:
    if name not in tracked:
        fail.append(f'not tracked by git: {name}')
    if not (ROOT / name).is_file():
        fail.append(f'missing on disk: {name}')
    if builder.sensitive(name):
        fail.append(f'sensitive by the builder rules: {name}')
DENY_EXT = re.compile(r'\.(sql|sqlite3?|db|dump|bak|old|orig|log|pem|key|crt|p12|pfx|jks|env|ini|ya?ml|csv|zip|gz|tar|ps1|py|cjs|patch|diff)$', re.I)
for name in runtime:
    if DENY_EXT.search(name):
        fail.append(f'forbidden extension: {name}')
SECRET = re.compile(r'BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{30,}|ya29\.[0-9A-Za-z_-]{20,}|sk-[A-Za-z0-9]{24,}|ghp_[A-Za-z0-9]{30,}|xox[bap]-[0-9A-Za-z-]{10,}')
CRED = re.compile(r"""(?i)(password|secret|refresh_token|api_key)['"]?\s*[:=>]+\s*['"][A-Za-z0-9/+_=-]{12,}['"]""")
content_hits = []
texts = {}
for name in runtime:
    p = ROOT / name
    if p.suffix.lower() in ('.php', '.js', '.css', '.json', '.md', '.htaccess', '.svg', '') or p.name == '.htaccess':
        t = p.read_text(encoding='utf-8', errors='replace')
        texts[name] = t
        if SECRET.search(t):
            content_hits.append(f'secret pattern in {name}')
        for m in CRED.finditer(t):
            # Real credentials would be long literal strings; code that reads constants or variables never matches.
            content_hits.append(f'credential-like literal in {name}: {m.group(0)[:40]}')
fail += content_hits
# Necessity.
entry, cli, referenced, unreferenced = [], [], [], []
guard_missing = []
others = {k: v for k, v in texts.items()}
for name in runtime:
    base = Path(name).name
    if '/' not in name and name.endswith('.php'):
        entry.append(name)
        continue
    if name.startswith('maintenance/') and name.endswith('.php'):
        cli.append(name)
        # The guard must be the first ACTIVE statement: only comments, blanks, declare/use and require_once (which only define functions) may precede it.
        in_doc = False; guard = False
        for line in texts[name].splitlines():
            t = line.strip()
            if in_doc:
                in_doc = "*/" not in t
                continue
            if t.startswith("/*"):
                in_doc = "*/" not in t
                continue
            if not t or t.startswith(("<?php", "//", "#", "*")) or re.match(r"(declare|use|require_once|require)", t):
                continue
            guard = bool(re.search(r"PHP_SAPI\s*(!==|===|!=|==)\s*'cli'|php_sapi_name\(\)", t))
            break
        if not guard:
            guard_missing.append(name)
        continue
    if base == '.htaccess':
        referenced.append(name)
        continue
    refs = [o for o, t in others.items() if o != name and (base in t)]
    (referenced if refs else unreferenced).append(name)
    if refs:
        texts.setdefault('__refs__', {})
fail += [f'maintenance script whose first active statement is not its own CLI guard: {n}' for n in guard_missing]
added = []
if args.added:
    for name in [l.strip() for l in Path(args.added).read_text(encoding='utf-8').splitlines() if l.strip()]:
        base = Path(name).name
        refs = sorted(o for o, t in others.items() if o != name and base in t and not o.startswith('__'))
        added.append({'path': name, 'inAllowlist': name in runtime, 'trackedByGit': name in tracked, 'referencedBy': refs[:6] or ('HTTP entry point' if '/' not in name and name.endswith('.php') else ('CLI entry point (cron runner step)' if name.startswith('maintenance/') else []))})
report = {'runtimeEntries': len(runtime), 'httpEntryPoints': len(entry), 'cliEntryPoints': len(cli), 'referencedSupportFiles': len(referenced),
          'unreferencedForReview': sorted(unreferenced), 'cliGuardMissing': guard_missing, 'contentHits': content_hits, 'hardFailures': fail, 'added': added}
print(json.dumps({k: (v if not isinstance(v, list) else len(v)) for k, v in report.items() if k != 'added'}))
for u in report['unreferencedForReview']:
    print('UNREFERENCED (review):', u)
for a in added:
    print('ADDED', a['path'], '<-', a['referencedBy'] if isinstance(a['referencedBy'], str) else ', '.join(a['referencedBy']))
for f in fail:
    print('FAIL', f)
if args.out:
    Path(args.out).write_text(json.dumps(report, indent=1), encoding='utf-8')
sys.exit(1 if fail else 0)
