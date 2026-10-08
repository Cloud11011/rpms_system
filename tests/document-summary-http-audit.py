"""Actual Apache .htaccess with synthetic static files, no PHP application or credentials."""
from pathlib import Path
import tempfile, subprocess, socket, time, urllib.request, urllib.error, shutil, json

root = Path(__file__).resolve().parent.parent
parent = Path(tempfile.gettempdir()).resolve()
temporary = Path(tempfile.mkdtemp(prefix='prism-summary-apache-', dir=parent)).resolve()
assert temporary.parent == parent and temporary.name.startswith('prism-summary-apache-')
process = None
paths = ['vendor/autoload.php','vendor/composer/installed.json','composer.json','composer.lock',
         'storage/documents/probe.pdf','tests/probe.php','config.local.php','.git/config','tools/check-document-summary.php']
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
try:
    public = temporary / 'public'; public.mkdir()
    shutil.copyfile(root / '.htaccess', public / '.htaccess')
    (public / 'normal.txt').write_text('Public fixture', encoding='utf-8')
    for name in paths:
        target = public / name; target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text('Synthetic private fixture', encoding='utf-8')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0)); port = probe.getsockname()[1]
    apache = Path('C:/xampp/apache')
    conf = f'ServerRoot "{apache.as_posix()}"\nListen 127.0.0.1:{port}\nServerName localhost\n'
    for module in ['authz_core','authz_host','access_compat','rewrite']:
        conf += f'LoadModule {module}_module modules/mod_{module}.so\n'
    conf += f'PidFile "{(temporary / "httpd.pid").as_posix()}"\nErrorLog "{(temporary / "error.log").as_posix()}"\n'
    conf += f'DocumentRoot "{public.as_posix()}"\n<Directory "{public.as_posix()}">\nAllowOverride All\nRequire all granted\n</Directory>\n'
    (temporary / 'httpd.conf').write_text(conf, encoding='utf-8')
    command = [str(apache / 'bin/httpd.exe'), '-f', (temporary / 'httpd.conf').as_posix()]
    syntax = subprocess.run(command + ['-t'], capture_output=True, text=True)
    assert syntax.returncode == 0, syntax.stderr
    process = subprocess.Popen(command + ['-X'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        try:
            with opener.open(f'http://127.0.0.1:{port}/normal.txt', timeout=1) as response:
                assert response.status == 200 and response.read() == b'Public fixture'
            break
        except OSError: time.sleep(.1)
    else: raise RuntimeError('Isolated Apache did not start')
    results = []
    for method in ['GET','HEAD']:
        for name in paths:
            request = urllib.request.Request(f'http://127.0.0.1:{port}/{name}', method=method)
            try:
                with opener.open(request, timeout=3) as response: status = response.status
            except urllib.error.HTTPError as error: status = error.code
            results.append(dict(path=name,method=method,status=status,passed=status in [403,404]))
    dest = root / 'tests' / 'document-summary-results'; dest.mkdir(exist_ok=True)
    (dest / 'http-denial.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
    assert all(r['passed'] for r in results), results
    print(f'PASS: {len(results)+1} actual Apache HTTP checks (GET/HEAD denials and public control). No production PHP executed.')
finally:
    if process is not None:
        process.terminate(); process.wait(timeout=10)
    assert temporary.parent == parent and temporary.name.startswith('prism-summary-apache-')
    shutil.rmtree(temporary)
