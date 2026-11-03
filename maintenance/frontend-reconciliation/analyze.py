"""Read-only archive comparison and restricted reference extraction.

Never extracts Git history, dependencies, private configuration, uploads, dumps,
or prior audit evidence. The original archive is opened read-only.
"""
import argparse
import csv
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / 'PRISM_V14_FRONTEND_RECONCILIATION'
REFERENCE = ROOT / 'tests/frontend-reconciliation/reference'
PRIVATE = {'.git', 'vendor', 'node_modules', 'uploads', 'storage', 'backups', 'tests', 'tools', '__macosx'}
TEXT = {'.php', '.js', '.cjs', '.json', '.css', '.md', '.txt', '.yml', '.yaml', '.ps1', '.py', '.svg', '.html'}


def allowed(name):
    p = PurePosixPath(name)
    return (bool(name) and not p.is_absolute() and '..' not in p.parts and ':' not in name
            and not any(v.lower() in PRIVATE or v.upper().startswith('PRISM_') for v in p.parts)
            and p.name.lower() not in {'config.local.php', '.env', '.ds_store'}
            and not p.name.lower().startswith('.env.')
            and p.suffix.lower() not in {'.sql', '.log', '.bak', '.zip', '.tar', '.patch'})


def normalized(name, data):
    if data is None:
        return None
    return data.replace(b'\r\n', b'\n').replace(b'\r', b'\n') if PurePosixPath(name).suffix.lower() in TEXT or PurePosixPath(name).name.startswith('.') else data


def classify(name, state):
    if state == 'identical':
        return 'PRESERVE V14', 'none', 'Identical after line-ending normalization.'
    if state == 'v14-only':
        return 'PRESERVE V14', 'high', 'Retain verified V14 functionality and tests missing from ZIP.'
    if name.startswith('assets/css/') or name.startswith('assets/') and PurePosixPath(name).suffix.lower() not in {'.js', '.cjs'}:
        return 'ADOPT', 'low', 'Approved ZIP presentation; preserve exact bytes.'
    if name.startswith('assets/js/'):
        return 'ADAPT', 'high', 'Review DOM/events/request contracts; preserve V14 authorization and workflow.'
    if name.startswith('maintenance/') or 'schema' in name or name in {'composer.json', 'composer.lock', '.gitignore'}:
        return ('ISOLATE' if state == 'zip-only' else 'PRESERVE V14'), 'high', 'Review independently; retain V14 migrations, dependency lock and test suite.'
    if name.startswith('includes/') and name not in {'includes/prism-navigation.php', 'includes/ierb_monitor_view.php', 'includes/academic_year_manager.php', 'includes/upload_modal.php'} or name.endswith('_api.php') or name in {'config.php', 'workflow.php', 'send_followup.php'}:
        return ('ISOLATE' if state == 'zip-only' else 'PRESERVE V14'), 'critical', 'Backend master; port only separately reviewed compatibility additions.'
    return 'ADAPT', 'high', 'Mixed PHP/template: retain V14 services and integrate approved ZIP markup.'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('archive', type=Path)
    parser.add_argument('--extract-reference', action='store_true')
    args = parser.parse_args()
    archive = args.archive.resolve(strict=True)
    tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    baseline = {n for n in tracked if allowed(n) and not n.startswith('maintenance/frontend-reconciliation/')}
    rows = []
    with ZipFile(archive) as z:
        infos = [i for i in z.infolist() if not i.is_dir()]
        roots = {i.filename.split('/')[0] for i in infos}
        if len(roots) != 1:
            raise ValueError('Expected one archive root; review layout before extracting.')
        members = {}
        for i in infos:
            name = i.filename.split('/', 1)[1]
            if not allowed(name):
                continue
            if name.casefold() in {v.casefold() for v in members}:
                raise ValueError('Duplicate or case-colliding archive member.')
            if i.file_size > 20 * 1024 * 1024:
                raise ValueError('Unexpectedly large source member; review separately.')
            members[name] = i
        if args.extract_reference and REFERENCE.exists():
            raise ValueError('Reference already exists; refusing to overwrite.')
        for name in sorted(baseline | set(members)):
            a = normalized(name, (ROOT / name).read_bytes()) if name in baseline else None
            raw_b = z.read(members[name]) if name in members else None
            b = normalized(name, raw_b)
            state = 'identical' if a == b else 'zip-only' if a is None else 'v14-only' if b is None else 'modified'
            decision, risk, reason = classify(name, state)
            rows.append(dict(path=name, state=state, decision=decision, risk=risk, reason=reason,
                             v14_sha256=hashlib.sha256(a).hexdigest() if a is not None else None,
                             zip_sha256=hashlib.sha256(b).hexdigest() if b is not None else None))
            if args.extract_reference and raw_b is not None:
                target = REFERENCE / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(raw_b)
    REPORT.mkdir(exist_ok=True)
    summary = dict(baseline_sha=subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
                   archive=str(archive), archive_sha256=hashlib.file_digest(archive.open('rb'), 'sha256').hexdigest(),
                   archive_files=len(infos), excluded_archive_files=len(infos)-len(members),
                   reference_files=len(members), v14_files=len(baseline),
                   states={s: sum(r['state']==s for r in rows) for s in {'identical', 'modified', 'zip-only', 'v14-only'}},
                   note='Initial dispositions; modified mixed files require hunk-level review. Original ZIP unchanged.')
    (REPORT / 'baseline.json').write_text(json.dumps(summary, indent=2)+'\n', encoding='utf-8')
    with (REPORT / 'reconciliation-matrix.csv').open('w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=rows[0].keys())
        writer.writeheader()
        writer.writerows(rows)
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
