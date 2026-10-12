"""Build an explicit production inventory; never copy, deploy, or read private configuration."""
from pathlib import Path
import hashlib
import json
import subprocess

ROOT = Path(__file__).resolve().parents[1]
BASELINE = "5b78211a7e63300afacd03b244a6f63bba2da841"


def production(name):
    return name in [".htaccess", "storage/.htaccess"] or name.startswith(("assets/", "includes/")) or (
        "/" not in name and name.endswith(".php") and name != "config.local.php")


def item(name):
    file = ROOT / name
    return {"path": name, "bytes": file.stat().st_size, "sha256": hashlib.sha256(file.read_bytes()).hexdigest()}


def build():
    tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
    changed = subprocess.check_output(["git", "diff", "--name-only", "-z", BASELINE], cwd=ROOT, stderr=subprocess.PIPE).decode().split("\0")
    runtime = sorted(name for name in tracked if name and production(name))
    vendor = sorted(path.relative_to(ROOT).as_posix() for path in (ROOT / "vendor").rglob("*") if path.is_file())
    installed = json.loads((ROOT / "vendor/composer/installed.json").read_text())
    packages = installed["packages"] if isinstance(installed, dict) else installed
    locked = json.loads((ROOT / "composer.lock").read_text())["packages"]
    versions = {package["name"]: package["version"] for package in packages}
    assert versions == {package["name"]: package["version"] for package in locked}, "vendor must match composer.lock"
    manifest = {
        "baseline": BASELINE,
        "implementation_head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
        "deployment_performed": False,
        "platform_prerequisites": {"php_minimum": "8.1", "extensions": ["pdo_mysql", "mbstring", "curl", "iconv", "zlib", "dom", "zip"],
                                   "web_server": "Apache/LiteSpeed with the supplied .htaccess protections honored"},
        "overlay": {"requires_verified_hosting_baseline": BASELINE,
                    "files": [item(name) for name in sorted(changed) if name and production(name)]},
        "complete_runtime_inventory": [item(name) for name in runtime],
        "production_dependencies": {"lock_sha256": hashlib.sha256((ROOT / "composer.lock").read_bytes()).hexdigest(),
                                    "packages": versions, "files": [item(name) for name in vendor],
                                    "build": "composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader"},
        "existing_external_ui_dependencies": ["https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css",
                                              "https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap",
                                              "https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap"],
        "excluded": ["tests/**", "PRISM* reports and manifests", "backups/**", "tools/**",
                     "config.local.php", ".git/**", ".env*", "*.patch", "generated logs and screenshots",
                     "storage/** except storage/.htaccess"],
        "preserve_on_host": ["config.local.php", "database", "storage/documents", "storage/reports",
                             "runtime state", "existing approved maintenance/cron tools"],
        "prerequisites": ["Do not apply overlay to an unverified hosting baseline.",
                          "Upload the entire locked vendor dependency if it is absent or differs.",
                          "Retain dependency licenses; composer.json/lock are build inputs, not public endpoints.",
                          "No migrations, schema files, fixtures, credentials or runtime data are deployment payloads.",
                          "Keep PHP, JavaScript and CSS together on deployment and rollback.",
                          "Verify the existing persistent deletion proof remains valid for the actual deployment context; fail closed otherwise.",
                          "Validate root/includes/storage .htaccess protections and invalidate relevant asset caches."]
    }
    target = ROOT / "PRISM_BATCH10_75_DEPLOY_MANIFEST.json"
    target.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Manifest: {len(manifest['overlay']['files'])} overlay files; {len(runtime)} runtime files; {len(vendor)} locked dependency files.")


if __name__ == "__main__":
    build()
