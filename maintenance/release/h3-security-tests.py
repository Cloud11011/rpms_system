"""H3 regression tests: synthetic data only, isolated loopback Apache/MariaDB.

Windows prerequisites: XAMPP Apache/MariaDB and PHP 8.3 (override via arguments).
No production URL, credentials or database is accepted. Output is sanitized JSON.
No original evidence contents are read: only tracked filenames become canaries.
"""
import argparse
from datetime import datetime, timedelta, timezone
import http.client
import http.cookiejar
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
BASELINE = "99716b1f55f037081c31c413b90528be6ca26cd1"
CHECKS = []
CREATE_FLAGS = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def check(ok, label):
    CHECKS.append({"check": label, "status": "PASS" if ok else "FAIL"})
    if not ok:
        raise AssertionError(label)


def run(command, env=None, input=None):
    p = subprocess.run(command, input=input, text=True, capture_output=True,
                       env=env, creationflags=CREATE_FLAGS, timeout=120)
    if p.returncode:
        if env and env.get("PRISM_TEST_REVIEW_DIR"):
            write(Path(env["PRISM_TEST_REVIEW_DIR"]) / "last-failed-subprocess.txt", p.stdout + p.stderr)
        raise RuntimeError("Local subprocess failed: " + Path(command[0]).name)
    return p.stdout


def port(start):
    # Avoid Windows' ephemeral outbound port range and existing local services.
    for candidate in range(start, start + 100):
        with socket.socket() as s:
            try:
                s.bind(("127.0.0.1", candidate))
                return candidate
            except OSError:
                pass
    raise RuntimeError("No free isolated loopback port")


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8", newline="\n")


def request(app_port, url, method="GET", headers=None):
    conn = http.client.HTTPConnection("127.0.0.1", app_port, timeout=10)
    try:
        conn.request(method, url, headers=headers or {})
        r = conn.getresponse()
        return r.status, dict(r.getheaders()), r.read()
    finally:
        conn.close()


def main(args):
    spec = importlib.util.spec_from_file_location("h3_builder", ROOT / "maintenance/release/build-public-runtime.py")
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    # A new, private sibling of this source worktree, never a public directory.
    private_root = ROOT.parent / ".h3-local"
    private_root.mkdir(exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="run-", dir=private_root))
    review = work / "fixture"
    review.mkdir()
    app = review / "sandbox/app"
    app_port, db_port = port(18543), port(18643)
    env = {k: v for k, v in os.environ.items() if not k.startswith(("PRISM_", "DB_", "GMAIL_", "OPENROUTER_"))}
    env.update(PRISM_TEST_REVIEW_DIR=str(review), PRISM_TEST_DB_PORT=str(db_port),
               PRISM_TEST_APP_PORT=str(app_port), PRISM_TEST_PHP=args.php,
               PRISM_TEST_MARIADB_BIN=args.mariadb)
    write(work / "run-context.json", json.dumps({"db_port": db_port, "app_port": app_port}))
    apache = None
    database_process = None
    logs = []
    meta = {"scope": "synthetic loopback only; no live services", "baseline": BASELINE,
            "started_at": datetime.now(timezone(timedelta(hours=8))).isoformat(),
            "live_litespeed": "UNVERIFIED", "fixture_schema": "15", "checks": CHECKS}
    try:
        baseline_htaccess = run(["git", "-C", str(ROOT), "show", BASELINE + ":.htaccess"])
        current = (ROOT / ".htaccess").read_text()
        start = current.index("# H3:")
        end = current.index('<Files ".htaccess">')
        # Only additions are allowed. Preserve every original byte after newline normalization.
        reconstructed = current[:start].rstrip() + "\n\n" + current[end:]
        check(reconstructed == baseline_htaccess, "All baseline htaccess rules preserved in order")
        built = builder.build(app, vendor=True)
        inventory = {k: v for k, v in built.items() if k not in {"excluded_tracked_paths", "excluded_vendor_paths"}}
        write(Path(args.result).with_name("runtime-inventory.json"), json.dumps(inventory, indent=2) + "\n")
        meta["runtime_export"] = {k: v for k, v in inventory.items() if k != "files"}
        check(built["inventory_verified"] and not built["unexpected_files"], "Exact runtime output inventory and every source/output SHA-256 verified")
        check(built["evidence_files"] == 0, "Runtime export excludes evidence")
        check(built["tracked_files_examined"] == built["tracked_files_excluded"] + built["runtime_files"], "Tracked runtime and exclusion counts reconcile")
        check(built["vendor_files_examined"] == built["vendor_files_excluded"] + built["vendor_files"], "Dependency runtime and exclusion counts reconcile")
        check(not (app / "includes/schema_v10_seed.json").exists(), "Unused historical seed excluded from runtime export")
        check(not (app / "vendor/vendor").exists() and not (app / "vendor/smalot/pdfparser/README.md").exists(), "Duplicate and development dependency files excluded")
        check((app / "maintenance/.htaccess").read_bytes() == (ROOT / "maintenance/.htaccess").read_bytes(), "Maintenance htaccess preserved byte for byte")
        check(run([args.php, "-r", "require $argv[1]; new \\Smalot\\PdfParser\\Parser(); echo 'OK';", str(app / "vendor/autoload.php")]).strip() == "OK", "Exported dependency autoloader and PDF parser load successfully")
        check(not any(p.name.startswith("PRISM_") for p in app.iterdir()), "No report or evidence root in runtime export")
        check(not (app / "config.local.php").exists(), "Runtime export excludes local credentials")
        check(not (app / "maintenance/ierb-group-tests").exists(), "Runtime export excludes test harnesses")
        check((app / "includes/legal/privacy.md").is_file() and (app / "includes/academic_schema_v15.json").is_file(), "Runtime legal Markdown and schema JSON retained")
        check((app / "maintenance/hostinger-cron-runner.php").is_file(), "Filesystem cron entrypoint retained")
        for dest in [app, ROOT, ROOT.parent, ROOT / "public-output"]:
            try:
                builder.build(dest)
            except ValueError:
                pass
            else:
                check(False, "Unsafe or existing runtime export destination rejected")
        check(True, "Unsafe or existing runtime export destinations rejected")
        check(not builder.is_runtime("PRISM_V14_H3_SECURITY_CLOSURE/review.zip"), "Review ZIP excluded from runtime export")

        # Start ONLY a new database we own, with no system/default configuration.
        # Existing initialize/bootstrap helpers verify @@datadir before any SQL.
        data = review / "sandbox/mysql"
        data.mkdir(parents=True)
        maria = Path(args.mariadb)
        installer = maria / "mariadb-install-db.exe"
        if not installer.is_file():
            installer = maria / "mysql_install_db.exe"
        run([str(installer), "--datadir=" + str(data), "--password=prism_synthetic_only", "--port=" + str(db_port)], env)
        db_log = open(work / "database-console.log", "wb")
        logs.append(db_log)
        server = maria / "mariadbd.exe"
        if not server.is_file():
            server = maria / "mysqld.exe"
        database_process = subprocess.Popen([str(server), "--no-defaults", "--datadir=" + str(data),
            "--innodb-data-home-dir=" + str(data), "--innodb-log-group-home-dir=" + str(data),
            "--skip-innodb-use-native-aio", "--skip-innodb-buffer-pool-load-at-startup", "--innodb-buffer-pool-size=64M",
            "--port=" + str(db_port), "--bind-address=127.0.0.1", "--log-error=" + str(data / "server.err")],
            cwd=data, env=env, stdout=db_log, stderr=db_log, creationflags=CREATE_FLAGS)
        for attempt in range(100):
            if database_process.poll() is not None:
                raise RuntimeError("Disposable database startup failed")
            with socket.socket() as probe:
                if probe.connect_ex(("127.0.0.1", db_port)) == 0:
                    break
            time.sleep(.1)
        else:
            raise RuntimeError("Disposable database did not become ready")
        run([args.php, str(ROOT / "maintenance/ierb-group-tests/initialize.php")], env)
        run([args.php, str(ROOT / "maintenance/ierb-group-tests/seed.php")], env)
        bootstrap = (ROOT / "maintenance/ierb-group-tests/bootstrap.php").as_posix()
        fixture = work / "initialize-h3.php"
        write(fixture, "<?php\nrequire " + json.dumps(bootstrap) + ";\n" + r'''
require_once $root.'/includes/schema_v15.php';
foreach ([12,13,14,15] as $v) {
    putenv('PRISM_ALLOW_SCHEMA_V'.$v.'_MIGRATION=1');
    putenv('PRISM_SCHEMA_V'.$v.'_EXPECT_DB=prism_group_synthetic_v11');
    if ($v===12) putenv('PRISM_IERB_V14_FOUNDATION=1');
    $f='migrate_schema_v'.$v; $f($pdo,'apply');
}
lifecycle_compare_schema($pdo);
$pdo->exec("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,uploaded_at) VALUES ('h3-own',4,'Synthetic Student','Synthetic Student','student','synthetic.pdf','h3-own.pdf','application/pdf',32,'Study Protocol','Stage 1','Pending',NOW()),('h3-other',5,'Synthetic Other','Synthetic Other','student','synthetic-other.pdf','h3-other.pdf','application/pdf',32,'Study Protocol','Stage 1','Pending',NOW())");
$pdo->exec("INSERT INTO reports(id,title,type,filename,generated_by,generated_by_user_id) VALUES ('h3-report','Synthetic H3 report','Summary Report','h3-report.pdf','Synthetic Admin',1)");
echo json_encode(['schema'=>'15','synthetic'=>true]);
''')
        check(json.loads(run([args.php, str(fixture)], env))["schema"] == "15", "Disposable schema 15 fixture initialized and verified")
        config = "<?php\n" + "\n".join([
            "define('DB_HOST','127.0.0.1');", f"define('DB_PORT',{db_port});",
            "define('DB_NAME','prism_group_synthetic_v11');", "define('DB_USER','root');",
            "define('DB_PASS','prism_synthetic_only');", "define('APP_ENV','development');",
            f"define('APP_BASE_URL','http://127.0.0.1:{app_port}');",
            "define('ALLOWED_EMAIL_DOMAINS',['example.test']);", "define('OPENROUTER_API_KEY','');",
            "define('GMAIL_CLIENT_ID','');", "define('GMAIL_CLIENT_SECRET','');",
            "define('GMAIL_REFRESH_TOKEN','');", "define('GMAIL_SENDER_EMAIL','');",
            "define('PRISM_IERB_V14_OPERATIONS',true);"
        ])
        write(app / "config.local.php", config)
        synthetic_pdf = b"%PDF-1.4\nH3 synthetic document only\n%%EOF\n"
        for name in ["h3-own.pdf", "h3-other.pdf"]:
            (app / "storage/documents").mkdir(parents=True, exist_ok=True)
            (app / "storage/documents" / name).write_bytes(synthetic_pdf)
        (app / "storage/reports").mkdir(parents=True, exist_ok=True)
        (app / "storage/reports/h3-report.pdf").write_bytes(synthetic_pdf)
        (app / "sessions").mkdir()
        canary = "H3-SYNTHETIC-CANARY-NO-PRIVATE-CONTENT"
        paths = run(["git", "-C", str(ROOT), "ls-tree", "-r", "--name-only", BASELINE]).splitlines()
        evidence = [p for p in paths if p.startswith("PRISM_")]
        for name in evidence:
            write(app / name, canary)
        # A permissive child must never reopen a protected parent.
        write(app / "PRISM_V14_H3_SECURITY_CLOSURE/.htaccess", "Options +Indexes\nRequire all granted\nRewriteEngine On\nRewriteRule ^ - [L]\n")
        write(app / "PRISM_V14_H3_SECURITY_CLOSURE/canary.json", canary)
        write(app / "PRISM_V14_OCT29_DATABASE_REHEARSAL/canary.png", canary)
        write(app / "PRISM_V13_UI_REMEDIATION_AUDIT/canary.png", canary)
        write(app / "PRISM_DOCUMENT_SUMMARY_DEFENSE/canary.png", canary)
        write(app / "PRISM_THESIS_BACKEND_SCREENSHOTS/canary.png", canary)
        blocked = [".env", ".env.production", ".git/config", ".batch7r-worktree/canary.png",
                   "backups/canary.pdf", "storage/canary.png", "vendor/canary.php", "tests/canary.php",
                   "maintenance/canary.php", "includes/canary.json", "tools/canary.php",
                   "assets/h3/canary.SQL", "assets/h3/canary.sql.gz", "assets/h3/canary.err",
                   "assets/h3/canary.log", "assets/h3/canary.zip", "assets/h3/canary.ini",
                   "assets/h3/canary.csv", "assets/h3/canary.bak.php", "assets/h3/canary.php~",
                   "assets/h3/canary.cjs", "assets/h3/canary.ps1", "assets/h3/canary.db",
                   "assets/h3/canary.pem", "assets/h3/canary.key", "assets/h3/canary.pfx", "assets/h3/canary.LOG",
                   "assets/h3/canary.md", "assets/h3/canary.patch", "assets/h3/canary.diff"]
        for name in blocked:
            write(app / name, canary)
        write(app / "includes/.htaccess", (ROOT / "includes/.htaccess").read_text())
        write(app / "maintenance/.htaccess", (ROOT / "maintenance/.htaccess").read_text())
        write(app / ".well-known/acme-challenge/h3-canary", canary)
        write(app / "h3-empty/canary.txt", canary)
        modules = ["authz_core", "authz_host", "access_compat", "dir", "mime", "log_config", "alias", "autoindex", "rewrite"]
        conf = f'ServerRoot "{Path(args.apache).parent.parent.as_posix()}"\nListen 127.0.0.1:{app_port}\nServerName 127.0.0.1\n'
        for module in modules:
            conf += f'LoadModule {module}_module modules/mod_{module}.so\n'
        php_dir = Path(args.php).parent.as_posix()
        conf += f'LoadFile "{php_dir}/php8ts.dll"\nLoadModule php_module "{php_dir}/php8apache2_4.dll"\nPHPIniDir "{php_dir}"\n'
        conf += f'DocumentRoot "{app.as_posix()}"\nErrorLog "{(work / "apache-error.log").as_posix()}"\nPidFile "{(work / "apache.pid").as_posix()}"\n'
        conf += f'TypesConfig "{Path(args.apache).parent.parent.as_posix()}/conf/mime.types"\nDirectoryIndex index.php index.html\n'
        # Ignore the real workspace's ancestor htaccess; exercise only the copy.
        conf += '<Directory />\nAllowOverride None\nRequire all denied\n</Directory>\n'
        conf += f'<Directory "{app.as_posix()}">\nOptions +FollowSymLinks +Indexes\nAllowOverride All\nRequire all granted\n'
        conf += f'php_admin_value session.save_path "{(app / "sessions").as_posix()}"\nphp_admin_value disable_functions "mail,curl_exec"\n</Directory>\n'
        conf += '<FilesMatch "\\.php$">\nSetHandler application/x-httpd-php\n</FilesMatch>\n'
        conf += f'Alias /prism-subapp/ "{app.as_posix()}/"\n'
        conf_path = work / "httpd.conf"
        write(conf_path, conf)
        run([args.apache, "-t", "-f", str(conf_path)], env)
        log = open(work / "apache-console.log", "wb")
        logs.append(log)
        apache = subprocess.Popen([args.apache, "-f", str(conf_path)], env=env, stdout=log, stderr=log, creationflags=CREATE_FLAGS)
        for attempt in range(100):
            try:
                if request(app_port, "/.well-known/acme-challenge/h3-canary")[0] == 200:
                    break
            except OSError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError("Local Apache did not become ready")
        check(True, "Real Apache serves loopback with per-directory overrides enabled")
        # Prove the original vulnerability using harmless synthetic content only.
        write(app / ".htaccess", baseline_htaccess)
        check(request(app_port, "/PRISM_V14_BATCH_01_EVIDENCE/foundation-tests.json")[0] == 200, "Baseline exposure reproduced using synthetic JSON canary")
        check(request(app_port, "/PRISM_V14_OCT29_DATABASE_REHEARSAL/canary.png")[0] == 200, "Baseline future evidence exposure reproduced using synthetic image canary")
        write(app / ".htaccess", current)
        failures = 0
        for name in evidence:
            status, _, body = request(app_port, "/" + urllib.parse.quote(name, safe="/"))
            failures += status not in (403, 404) or canary.encode() in body
        check(failures == 0, "Every tracked Batch 01-06 and 7R artifact URL denies synthetic content")
        meta["tracked_artifact_urls_tested"] = len(evidence)
        probes = blocked + [".htaccess", "config.php", "config.local.php",
                            "PRISM_V14_H3_SECURITY_CLOSURE/canary.json", "PRISM_V14_OCT29_DATABASE_REHEARSAL/canary.png",
                            "PRISM_V13_UI_REMEDIATION_AUDIT/canary.png", "PRISM_DOCUMENT_SUMMARY_DEFENSE/canary.png",
                            "PRISM_THESIS_BACKEND_SCREENSHOTS/canary.png"]
        for prefix in ["/", "/prism-subapp/"]:
            for name in probes:
                for method in ["GET", "HEAD"]:
                    status, _, body = request(app_port, prefix + name, method, {"Range": "bytes=0-15"})
                    check(status in (403, 404) and canary.encode() not in body,
                          f"Protected canary {probes.index(name)+1} {method} {'subpath' if prefix != '/' else 'root'} denied")
        for suffix in ["/PRISM_V14_H3_SECURITY_CLOSURE/", "/PRISM_V14_H3_SECURITY_CLOSURE/canary.json/extra",
                       "/%50RISM_V14_H3_SECURITY_CLOSURE/canary.json?download=1",
                       "/prism_v14_h3_security_closure/canary.json", "/PRISM_V14_H3_SECURITY_CLOSURE//canary.json",
                       "/h3-empty/", "/assets/", "/PRISM_V14_BATCH_01_EVIDENCE/"]:
            check(request(app_port, suffix)[0] in (403, 404), f"Encoded, PATH_INFO or listing probe {suffix.split('/')[1] if suffix.startswith('/h3-empty') else len(CHECKS)} denied")
        check(request(app_port, "/.well-known/acme-challenge/h3-canary")[0] == 200, "ACME challenge remains accessible")
        for name in [p for p in paths if p.startswith("assets/")]:
            check(request(app_port, "/" + urllib.parse.quote(name, safe="/"))[0] == 200, "Runtime asset " + name + " remains accessible")
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None
        base = f"http://127.0.0.1:{app_port}"
        def call(opener, url, data=None, headers=None):
            req = urllib.request.Request(base + url, data=data, headers={"Origin": base, **(headers or {})})
            try:
                r = opener.open(req, timeout=15)
            except urllib.error.HTTPError as e:
                r = e
            return r.code, r.headers, r.read()
        anonymous = urllib.request.build_opener(NoRedirect())
        for url in ["/", "/login.php", "/privacy.php", "/terms.php", "/register.php"]:
            check(call(anonymous, url)[0] == 200, "Public route " + url + " works")
        for url in ["/login_admin.php", "/login_adviser.php", "/login_students.php"]:
            status, headers, _ = call(anonymous, url)
            check(status == 302 and headers.get("Location") == "login.php", "Legacy login route " + url + " preserves canonical redirect")
        for url in ["/documents_api.php?action=file&id=h3-own", "/documents_api.php?action=file&id=h3-own&download=1", "/document_preview.php?id=h3-own", "/reports_api.php?action=file&id=h3-report"]:
            status, _, body = call(anonymous, url)
            check(status in (302, 401, 403) and synthetic_pdf not in body, "Anonymous document request denied")
        for role, email, pages in [
            ("admin", "admin@example.test", ["dashboard.php", "documents.php", "reports.php", "admin_people.php", "data_export.php", "ierbprog.php", "account.php", "calendar.php"]),
            ("adviser", "adviser2@example.test", ["research_adviser.php", "documents.php", "ierbprog.php", "account.php", "calendar.php"]),
            ("student", "student4@example.test", ["student.php", "role_portal.php"]),
        ]:
            jar = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
            status, headers, _ = call(opener, "/login_process.php", urllib.parse.urlencode({"email": email, "password": "SyntheticPass123!"}).encode())
            check(status == 302 and "login" not in headers.get("Location", ""), role + " authenticates with real password verification")
            for page in pages:
                check(call(opener, "/" + page)[0] == 200, role + " permitted page " + page + " works")
            status, _, profile = call(opener, "/profile_api.php?action=me")
            check(status == 200 and json.loads(profile)["user"]["role"].lower() == role, role + " session identity confirmed by profile API")
            for url in ["/documents_api.php?action=list", "/ierb_api.php?action=v14_contract", "/calendar_deadlines_api.php?action=list"]:
                check(call(opener, url)[0] == 200, role + " permitted API " + url + " works")
            if role == "student":
                status, headers, _ = call(opener, "/documents.php")
                check(status == 302 and headers.get("Location") == "role_portal.php#documents", "Student documents bookmark redirects to permitted portal")
                body = call(opener, "/role_portal.php")[2]
                for section in ["dashboard", "documents", "progress", "calendar", "profile"]:
                    check(('data-section="' + section + '"').encode() in body, "Student portal contains permitted " + section + " section")
            for extra in ["", "&download=1"]:
                status, headers, body = call(opener, "/documents_api.php?action=file&id=h3-own" + extra)
                check(status == 200 and body == synthetic_pdf, role + " authorized document endpoint works" + extra)
                check(("attachment" if extra else "inline") in headers.get("Content-Disposition", ""), role + " document disposition preserved" + extra)
            check(call(opener, "/document_preview.php?id=h3-own")[0] == 200, role + " authorized preview works")
            for url in ["/storage/documents/h3-own.pdf", "/storage/reports/h3-report.pdf", "/PRISM_V14_H3_SECURITY_CLOSURE/canary.json"]:
                check(call(opener, url)[0] in (403, 404), role + " cannot bypass direct-path protections")
            if role != "admin":
                for url in ["/documents_api.php?action=file&id=h3-other", "/documents_api.php?action=file&id=h3-other&download=1", "/document_preview.php?id=h3-other"]:
                    check(call(opener, url)[0] in (403, 404), role + " unrelated document denied")
                check(call(opener, "/reports_api.php?action=list")[0] == 403, role + " admin report API denied")
                check(call(opener, "/data_exports_api.php?action=documents")[0] == 403, role + " admin CSV export denied")
                check(call(opener, "/reports_api.php?action=file&id=h3-report")[0] == 403, role + " admin report file denied")
            else:
                for extra in ["", "&download=1"]:
                    status, headers, body = call(opener, "/reports_api.php?action=file&id=h3-report" + extra)
                    check(status == 200 and body == synthetic_pdf, "Admin authenticated report endpoint preserved" + extra)
                for action in ["students", "advisers", "documents"]:
                    status, headers, _ = call(opener, "/data_exports_api.php?action=" + action)
                    check(status == 200 and "attachment" in headers.get("Content-Disposition", "") and "csv" in headers.get("Content-Disposition", ""), "Admin authenticated " + action + " CSV endpoint preserved")
            check(call(opener, "/logout.php")[0] == 405, role + " logout retains POST-only protection")
            generation = next(c.value for c in jar if c.name == "prism_generation")
            check(call(opener, "/logout.php", b"", {"X-Prism-Generation": generation})[0] == 302, role + " authorized POST logout succeeds")
            status, _, body = call(opener, "/documents_api.php?action=file&id=h3-own")
            check(status in (302, 401, 403) and body != synthetic_pdf, role + " logout revokes document access")
        # A missing mandatory module must not silently serve the evidence.
        apache.terminate()
        apache.wait(timeout=15)
        apache = None
        # Windows Apache's child takes a moment to release the listening socket.
        for attempt in range(100):
            with socket.socket() as probe:
                if probe.connect_ex(("127.0.0.1", app_port)) != 0:
                    break
            time.sleep(.1)
        write(conf_path, conf.replace("LoadModule rewrite_module modules/mod_rewrite.so\n", ""))
        apache = subprocess.Popen([args.apache, "-f", str(conf_path)], env=env, stdout=log, stderr=log, creationflags=CREATE_FLAGS)
        for attempt in range(100):
            try:
                status = request(app_port, "/PRISM_V14_H3_SECURITY_CLOSURE/canary.json")[0]
                break
            except OSError:
                time.sleep(.1)
        check(status == 500, "Missing mod_rewrite fails closed instead of publishing evidence")
        meta["status"] = "PASS"
    except Exception as exc:
        meta["status"] = "FAIL"
        meta["failure_type"] = type(exc).__name__
        meta["failure"] = str(exc) if isinstance(exc, AssertionError) else "Local setup or subprocess failure; inspect private fixture logs"
        raise
    finally:
        if apache is not None:
            apache.terminate()
            try:
                apache.wait(timeout=15)
            except subprocess.TimeoutExpired:
                apache.kill()
                apache.wait(timeout=15)
            meta["disposable_apache_stopped"] = apache.poll() is not None
        if database_process is not None:
            try:
                if database_process.poll() is None:
                    run([args.php, str(ROOT / "maintenance/ierb-group-tests/shutdown.php")], env)
                database_process.wait(timeout=15)
            except Exception:
                # This is precisely the Popen child created above, never another service.
                if database_process.poll() is None:
                    database_process.terminate()
                    database_process.wait(timeout=15)
            meta["disposable_database_stopped"] = database_process.poll() is not None
        for log in logs:
            log.close()
        meta["assertions"] = len(CHECKS)
        meta["finished_at"] = datetime.now(timezone(timedelta(hours=8))).isoformat()
        Path(args.result).write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8", newline="\n")
        print(json.dumps({"status": meta["status"], "assertions": len(CHECKS), "live_litespeed": "UNVERIFIED"}))


if __name__ == "__main__":
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--apache", default="C:/xampp/apache/bin/httpd.exe")
    p.add_argument("--php", default="C:/php-8.3.36/php.exe")
    p.add_argument("--mariadb", default="C:/xampp/mysql/bin")
    p.add_argument("--result", required=True)
    main(p.parse_args())
