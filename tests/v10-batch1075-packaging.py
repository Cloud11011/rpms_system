"""Verify the proposed tracked release sources without local secrets or ignored test helpers.

Copies Git-listed sources plus the explicitly proposed new tests into a disposable
checkout. vendor is the locked, separately installed production dependency, not Git
source. No application configuration/database is loaded by the CLI regression suite.
"""
from pathlib import Path
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "tests/v10-batch1075-results/remediation"
PROPOSED = ["tests/session-idle-audit.php", "tests/security-audit.php",
            "tests/logout-audit.php", "tests/v10-batch1075-packaging.py", "tests/v10-batch1075-cache-assets.py"]
PHP = os.environ.get("PRISM_PHP_BINARY", "C:/xampp/php/php.exe" if Path("C:/xampp/php/php.exe").exists() else "php")


def run():
    OUTPUT.mkdir(parents=True, exist_ok=True)
    tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
    sources = sorted(set(tracked + PROPOSED) - {""})
    sources = [name for name in sources if not name.startswith("backups/")
               and not ("/" not in name and name.endswith((".md", ".patch", ".txt")))
               and name != "tests/v10-batch105-results.json"]
    with tempfile.TemporaryDirectory(prefix="prism-b1075-checkout-") as scratch:
        snapshot = Path(scratch).resolve()
        assert snapshot.parent == Path(tempfile.gettempdir()).resolve()
        assert snapshot.name.startswith("prism-b1075-checkout-")
        for name in sources:
            target = snapshot / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, target)
        shutil.copytree(ROOT / "vendor", snapshot / "vendor")
        assert not (snapshot / "config.local.php").exists()
        assert not (snapshot / "backups").exists()
        assert not (snapshot / "storage/prism.sqlite").exists()
        env = os.environ.copy()
        env["PRISM_PHP_BINARY"] = PHP
        env.pop("PRISM_AUDIT_SOURCE_ROOT", None)
        suite = subprocess.run(["node", "tests/v10-batch1075-regression.cjs"], cwd=snapshot,
                               env=env, capture_output=True, text=True)
        (OUTPUT / "clean-checkout-cli.log").write_text(suite.stdout + suite.stderr, encoding="utf-8")
        results = json.loads((snapshot / "tests/v10-batch1075-results/remediation/cli/cli-results.json").read_text())
        results["checkout"] = {"tracked_sources_plus_proposed_tests": True,
                               "private_configuration_copied": False,
                               "ignored_helpers_copied": False,
                               "vendor_source": "installed composer.lock production dependencies",
                               "source_hashes": {name: hashlib.sha256((snapshot / name).read_bytes()).hexdigest()
                                                 for name in sources}}
        (OUTPUT / "clean-checkout-results.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
        print(suite.stdout, end="")
        if suite.returncode:
            raise SystemExit(suite.returncode)
        if "--native105" in sys.argv:
            bin_dir = str(Path(PHP).resolve().parents[1] / "mysql/bin")
            # These original suites use overlapping account IDs and each requires a fresh private fixture.
            for phase, flags in [("native", ["--only105", "--batch105"]),
                                 ("http", ["--only105", "--http105"])]:
                native = subprocess.run([PHP, "-d", "extension=zip", "tests/v10-batch7-native.php",
                                         *flags, bin_dir], cwd=snapshot, env=env, capture_output=True, text=True)
                (OUTPUT / ("deletion-" + phase + ".log")).write_text(native.stdout + native.stderr, encoding="utf-8")
                generated = snapshot / "tests/v10-batch105-results.json"
                if phase == "native" and generated.exists():
                    shutil.copyfile(generated, OUTPUT / "deletion-results.json")
                print(native.stdout, end="", flush=True)
                if native.returncode:
                    print(native.stderr, file=sys.stderr)
                    raise SystemExit(native.returncode)
    # Baseline behavior is exported from Git, without changing either branch or production source.
    with tempfile.TemporaryDirectory(prefix="prism-b1075-baseline-") as scratch:
        baseline = Path(scratch).resolve()
        assert baseline.parent == Path(tempfile.gettempdir()).resolve()
        assert baseline.name.startswith("prism-b1075-baseline-")
        for name in ["config.php", "logout.php", "login_process.php"]:
            (baseline / name).write_bytes(subprocess.check_output(["git", "show", "5b78211:" + name], cwd=ROOT))
        comparison = []
        for label, source in [("Batch 10.5", baseline), ("Batch 10.75", ROOT)]:
            env = os.environ.copy()
            env["PRISM_AUDIT_SOURCE_ROOT"] = str(source)
            for name in ["session-idle-audit", "logout-audit"]:
                result = subprocess.run([PHP, str(ROOT / "tests" / (name + ".php"))], env=env,
                                        capture_output=True, text=True)
                comparison.append({"source": label, "suite": name, "exit": result.returncode,
                                   "stdout": result.stdout, "stderr": result.stderr})
        (OUTPUT / "baseline-harness-results.json").write_text(json.dumps(comparison, indent=2), encoding="utf-8")
        for result in comparison:
            print(result["source"], result["suite"], result["stdout"].strip())
        if any(result["exit"] or result["stderr"] for result in comparison):
            raise SystemExit("Baseline/current harness comparison failed")


if __name__ == "__main__":
    run()
