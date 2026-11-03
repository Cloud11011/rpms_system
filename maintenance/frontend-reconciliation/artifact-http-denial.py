"""Public-HTTP denial check for a BUILT runtime artifact (never the repository).

Serves the artifact with real Apache, per-directory overrides on and NO PHP handler, so a file the rules fail to deny
would be returned as plain source with status 200 and cannot hide behind a script's own CLI guard.
Every real file under maintenance/, includes/ and vendor/ is requested (GET and HEAD), plus path-trick variants of the
security-critical ones. Controls: runtime assets and root pages must still be served.
Usage: python -I artifact-http-denial.py <artifact dir> --apache C:/xampp/apache/bin/httpd.exe [--result out.json]
Loopback only; synthetic content only; the artifact is read, never written."""
import argparse
import http.client
import json
import socket
import subprocess
import sys
import tempfile
import time
import urllib.parse
from pathlib import Path

ap = argparse.ArgumentParser()
ap.add_argument('artifact')
ap.add_argument('--apache', default='C:/xampp/apache/bin/httpd.exe')
ap.add_argument('--result')
args = ap.parse_args()
tree = Path(args.artifact).resolve()
if not (tree / 'index.php').is_file():
    sys.exit('Not a built runtime artifact (no index.php).')
if (tree / 'config.local.php').exists():
    sys.exit('The artifact contains config.local.php.')


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def request(port, path, method='GET', headers=None):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
    try:
        conn.request(method, path, headers=headers or {})
        r = conn.getresponse()
        return r.status, r.read(4096)
    finally:
        conn.close()


work = Path(tempfile.mkdtemp(prefix='artifact-denial-'))
port = free_port()
apache_root = Path(args.apache).parent.parent.as_posix()
modules = ['authz_core', 'authz_host', 'access_compat', 'dir', 'mime', 'log_config', 'alias', 'autoindex', 'rewrite']
conf = f'ServerRoot "{apache_root}"\nListen 127.0.0.1:{port}\nServerName 127.0.0.1\n'
conf += ''.join(f'LoadModule {m}_module modules/mod_{m}.so\n' for m in modules)
conf += f'DocumentRoot "{tree.as_posix()}"\nErrorLog "{(work / "error.log").as_posix()}"\nPidFile "{(work / "apache.pid").as_posix()}"\n'
conf += f'TypesConfig "{apache_root}/conf/mime.types"\nDirectoryIndex index.php index.html\n'
conf += '<Directory />\nAllowOverride None\nRequire all denied\n</Directory>\n'
conf += f'<Directory "{tree.as_posix()}">\nOptions +FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\n'
(work / 'httpd.conf').write_text(conf, encoding='utf-8')
subprocess.run([args.apache, '-t', '-f', str(work / 'httpd.conf')], check=True, capture_output=True)
log = open(work / 'console.log', 'wb')
apache = subprocess.Popen([args.apache, '-f', str(work / 'httpd.conf')], stdout=log, stderr=log,
                          creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
results = {'denied': [], 'leaked': [], 'controls': [], 'variants': []}
try:
    for _ in range(100):
        try:
            if request(port, '/assets/')[0] in (200, 403, 404):
                break
        except OSError:
            time.sleep(0.1)
    else:
        sys.exit('Apache did not start.')
    files = sorted(p.relative_to(tree).as_posix() for p in tree.rglob('*') if p.is_file())
    guarded = [f for f in files if f.split('/')[0] in ('maintenance', 'includes', 'vendor')]
    for name in guarded:
        sample = (tree / name).read_bytes()[:24]
        for method in ('GET', 'HEAD'):
            status, body = request(port, '/' + urllib.parse.quote(name, safe='/'), method)
            ok = status in (403, 404) and (not sample.strip() or sample not in body)
            (results['denied'] if ok else results['leaked']).append([method, name, status])
    # Path-trick variants of the most sensitive entries.
    critical = ['maintenance/hostinger-cron-runner.php', 'maintenance/migrate-prism-settings.php', 'maintenance/migrate-academic-year-settings.php',
                'maintenance/notification-dispatch.php', 'maintenance/runtime-preflight.php', 'maintenance/v14-operations.php',
                'includes/admin_settings_store.php', 'includes/schema_v16.php', 'includes/admin_settings_schema.json',
                'includes/notification_worker.php', 'vendor/autoload.php', 'config.php', '.htaccess', 'maintenance/.htaccess', 'includes/.htaccess']
    for name in critical:
        if not (tree / name).exists():
            results['leaked'].append(['EXISTS', name, 'critical file missing from the artifact'])
            continue
        head, _, tail = name.rpartition('/')
        variants = ['/' + name.upper(), '/' + name + '/extra', '/' + name + '%00', '//' + name, '/./' + name,
                    '/' + urllib.parse.quote(name[0], safe='') + name[1:], '/' + name + '?download=1', '/' + name + '%20']
        if head:
            variants += [f'/{head}/../{name}', f'/{head}/{urllib.parse.quote(tail[0], safe="")}{tail[1:]}', f'/{head}/']
        for path in variants:
            try:
                status, body = request(port, path)
            except Exception as e:  # a refused or malformed request is also a denial
                status, body = 400, b''
            sample = (tree / name).read_bytes()[:24]
            ok = status in (400, 403, 404) and (not sample.strip() or sample not in body)
            results['variants'].append([path, status, ok])
            if not ok:
                results['leaked'].append(['VARIANT', path, status])
    for name in ('assets/css/ierbprog.css', 'assets/js/communication-settings.js', 'index.php', 'login.php', 'admin_settings.php'):
        if (tree / name).is_file():
            status, _ = request(port, '/' + name)
            results['controls'].append([name, status, status == 200])
            if status != 200:
                results['leaked'].append(['CONTROL', name, status])
    summary = {'artifact': str(tree), 'filesInArtifact': len(files), 'guardedFilesRequested': len(guarded), 'requests': len(results['denied']) + len(results['variants']),
               'deniedRequests': len(results['denied']), 'variantRequests': len(results['variants']), 'controlsServed': sum(c[2] for c in results['controls']),
               'controls': len(results['controls']), 'leaks': len(results['leaked'])}
    print(json.dumps(summary))
    for leak in results['leaked'][:20]:
        print('LEAK', leak)
    if args.result:
        Path(args.result).write_text(json.dumps({'summary': summary, **results}, indent=1), encoding='utf-8')
    code = 1 if results['leaked'] else 0
finally:
    subprocess.run(['taskkill', '/F', '/T', '/PID', str(apache.pid)], capture_output=True)
    log.close()
sys.exit(code)
