"""Syntax gate for application and maintained test sources; no configuration execution."""
import argparse
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def main():
    p = argparse.ArgumentParser()
    p.add_argument('output', type=Path)
    p.add_argument('--php', default='C:/xampp/php/php.exe')
    args = p.parse_args()
    results = []
    paths = list(ROOT.glob('*.php'))
    for folder in ['includes', 'assets', 'maintenance']:
        paths.extend(v for v in (ROOT/folder).rglob('*') if v.is_file() and v.suffix in {'.php', '.js', '.cjs'})
    for source in sorted(set(paths)):
        command = [args.php, '-l', str(source)] if source.suffix == '.php' else ['node', '--check', str(source)]
        run = subprocess.run(command, capture_output=True, text=True)
        results.append({'file':source.relative_to(ROOT).as_posix(), 'status':'PASS' if run.returncode == 0 else 'FAIL',
                        **({'diagnostic':run.stdout+run.stderr} if run.returncode else {})})
    report = {'passed':sum(r['status']=='PASS' for r in results), 'failed':sum(r['status']=='FAIL' for r in results),
              'scope':'PHP lint and standalone JavaScript parsing only; no runtime or visual claim.', 'checks':results}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2)+'\n', encoding='utf-8')
    print(json.dumps({k:v for k,v in report.items() if k != 'checks'}))
    raise SystemExit(1 if report['failed'] else 0)


if __name__ == '__main__':
    main()
