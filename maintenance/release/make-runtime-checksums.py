"""Checksums of the reviewed runtime files exactly as committed (no working-tree line-ending conversion).

Writes one `sha256sum`-format line per allowlisted runtime file, taken from the git blobs of the given revision, so a Linux
server holding a Git checkout or an upload built from that revision can verify itself with `sha256sum -c`.
Reads Git only. Never connects anywhere.
Usage: python -I maintenance/release/make-runtime-checksums.py <revision> --out <file>
       (on the server, from the application folder:  sha256sum -c <file> --quiet; echo exit=$?)
"""
import argparse
import hashlib
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ap = argparse.ArgumentParser()
ap.add_argument('revision')
ap.add_argument('--out', required=True)
args = ap.parse_args()


def git(*a, binary=False):
    r = subprocess.run(['git', *a], cwd=ROOT, capture_output=True)
    if r.returncode:
        sys.exit('git ' + ' '.join(a) + ' failed: ' + r.stderr.decode('utf-8', 'replace').strip())
    return r.stdout if binary else r.stdout.decode('utf-8')


commit = git('rev-parse', '--verify', args.revision + '^{commit}').strip()
manifest = json.loads(git('show', f'{commit}:maintenance/release/public-runtime-files.json'))
names = manifest['runtime']
if names != sorted(set(names)):
    sys.exit('The allowlist at this revision is not sorted and unique.')
lines = []
for name in names:
    blob = git('show', f'{commit}:{name}', binary=True)
    lines.append(f'{hashlib.sha256(blob).hexdigest()}  {name}')
out = Path(args.out)
out.write_text('\n'.join(lines) + '\n', encoding='utf-8', newline='\n')
print(json.dumps({'commit': commit, 'files': len(lines), 'output': str(out), 'sha256OfChecksumFile': hashlib.sha256(out.read_bytes()).hexdigest()}))
