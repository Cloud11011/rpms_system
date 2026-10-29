"""Focused publication regressions using synthetic trees; no real Git index writes."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("builder", Path(__file__).with_name("build-public-runtime.py"))
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def main(result):
    checks = []
    def check(ok, label):
        if not ok:
            raise AssertionError(label)
        checks.append({"check": label, "status": "PASS"})
    def refused(call, label):
        try:
            call()
        except ValueError:
            check(True, label)
        else:
            check(False, label)
    private = ROOT.parent / ".h3-local"
    private.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="inventory-", dir=private) as tmp:
        base = Path(tmp)
        source = base / "source"
        source.mkdir()
        contract = {"runtime": [".htaccess", "assets/js/app.js", "includes/contract.json", "index.php", "maintenance/.htaccess"],
                    "vendor": ["vendor/autoload.php", "vendor/package/LICENSE"]}
        for name in contract["runtime"] + contract["vendor"]:
            p = source / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(("synthetic:" + name + "\n").encode())
        # All these paths previously matched broad directory/extension policies.
        canaries = ["assets/review.png", "assets/js/debug.js", "assets/.env", "includes/evidence.json",
                    "includes/schema_v10_seed.json", "includes/config.local.php", "includes/tests/fixture.php",
                    "maintenance/debug.php", "debug.php", "config.local.php", "PRISM_PRIVATE/report.json",
                    "vendor/vendor/autoload.php", "vendor/package/README.md", "vendor/package/tests/test.php"]
        for name in canaries:
            p = source / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b"SYNTHETIC-NON-RUNTIME-CANARY")
        manifest = base / "manifest.json"
        manifest.write_text(json.dumps(contract), encoding="utf-8")
        tracked = sorted(contract["runtime"] + [n for n in canaries if not n.startswith("vendor/")])
        with patch.object(builder, "ROOT", source), patch.object(builder, "MANIFEST", manifest), patch.object(builder, "tracked_files", return_value=tracked):
            report = builder.build(base / "output", vendor=True)
            actual = {p.relative_to(base / "output").as_posix(): p.read_bytes() for p in (base / "output").rglob("*") if p.is_file()}
            check(set(actual) == set(contract["runtime"] + contract["vendor"]), "Only individually approved runtime/dependency paths published")
            check(not set(canaries).intersection(actual), "Tracked development canaries and nested dependency copies excluded")
            check(report["total_files"] == len(actual) and report["total_bytes"] == sum(map(len, actual.values())), "Measured file and byte totals match independent output scan")
            check(all(f["sha256"] == hashlib.sha256(actual[f["path"]]).hexdigest() and f["bytes"] == len(actual[f["path"]]) for f in report["files"]), "Every reported hash and size matches independent output bytes")
            check(report["tracked_files_excluded"] == len(tracked) - len(contract["runtime"]), "Tracked exclusions measured from synthetic inventory")
            check(report["vendor_files_excluded"] == len([n for n in canaries if n.startswith("vendor/")]), "Vendor exclusions measured from synthetic inventory")
            plain = builder.build(base / "without-vendor")
            check(plain["vendor_files"] == 0 and not (base / "without-vendor/vendor").exists(), "Dependency-free export accurately reports zero dependencies")
            refused(lambda: builder.build(base / "output"), "Existing export refused")
            refused(lambda: builder.build(source / "public-output"), "Public-looking output inside source refused")
            with patch.object(builder, "tracked_files", return_value=tracked[1:]):
                refused(lambda: builder.build(base / "missing-tracked"), "Missing tracked runtime file fails closed before creating output")
            (source / "vendor/autoload.php").unlink()
            refused(lambda: builder.build(base / "missing-vendor", True), "Missing approved dependency fails closed")
            (source / "vendor/autoload.php").write_bytes(b"synthetic")
            copyfile = shutil.copyfile
            def corrupt(src, dst):
                copyfile(src, dst)
                Path(dst).write_bytes(b"corrupted during copy")
            with patch.object(builder.shutil, "copyfile", side_effect=corrupt):
                refused(lambda: builder.build(base / "corrupt"), "Copy corruption detected by post-copy SHA-256 verification")
            def extra(src, dst):
                copyfile(src, dst)
                (Path(dst).parent / "unexpected.json").write_bytes(b"unexpected")
            with patch.object(builder.shutil, "copyfile", side_effect=extra):
                refused(lambda: builder.build(base / "extra"), "Unexpected output file detected by complete output scan")
            # A real junction exercises Windows path protection without symlink privilege.
            link = source / "linked-assets"
            if sys.platform == "win32":
                subprocess.run(["cmd", "/c", "mklink", "/J", str(link), str(source / "assets")], check=True, capture_output=True)
            else:
                link.symlink_to(source / "assets", target_is_directory=True)
            try:
                refused(lambda: builder.build(link / "output"), "Linked or junction output path refused before resolution")
                bad = dict(contract, runtime=sorted(contract["runtime"] + ["linked-assets/js/app.js"]))
                manifest.write_text(json.dumps(bad), encoding="utf-8")
                with patch.object(builder, "tracked_files", return_value=sorted(tracked + ["linked-assets/js/app.js"])):
                    refused(lambda: builder.build(base / "linked-source"), "Linked or junction runtime source refused")
            finally:
                if sys.platform == "win32":
                    link.rmdir()  # Removes only the junction itself, never its target.
                else:
                    link.unlink()
    data = {"status": "PASS", "assertions": len(checks), "checks": checks,
            "scope": "Synthetic publication trees; real repository index untouched"}
    Path(result).write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({"status": "PASS", "assertions": len(checks)}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--result", required=True)
    main(parser.parse_args().result)
