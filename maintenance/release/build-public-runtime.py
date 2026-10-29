"""Create a NEW, verified runtime-only tree locally; never deploy private evidence.

Run: python maintenance/release/build-public-runtime.py --output PATH [--vendor]
Add --report PATH for the exact copied-file inventory, byte counts and SHA-256s.
The adjacent reviewed manifest authorizes individual files, never whole trees.
Install and independently verify locked production dependencies before --vendor.
No network/DB operations. Existing output, symlinks and junctions are refused.
"""
import argparse
import hashlib
import json
import shutil
import subprocess
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[2]
MANIFEST = Path(__file__).with_name("public-runtime-files.json")


def sensitive(name):
    p = PurePosixPath(name)
    return (any(part.startswith("PRISM_") for part in p.parts)
            or any(part.startswith(".") and part != ".htaccess" for part in p.parts)
            or p.name.lower() == "config.local.php"
            or any(part.lower() in {"storage", "tests", "backups", "backup"} for part in p.parts))


def allowlist():
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    groups = {key: data[key] for key in ("runtime", "vendor")}
    for key, names in groups.items():
        if names != sorted(set(names)) or not names:
            raise ValueError("Runtime manifest must contain sorted, unique paths")
        for name in names:
            p = PurePosixPath(name)
            if (p.is_absolute() or p.as_posix() != name or ".." in p.parts
                    or "\\" in name or ":" in name or sensitive(name)
                    or (key == "vendor") != name.startswith("vendor/")):
                raise ValueError("Invalid publication manifest path")
    return groups


def is_runtime(name):
    return name in allowlist()["runtime"]


def tracked_files():
    raw = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    return sorted(set(n for n in raw.decode("utf-8").split("\0") if n))


def safe_source(path):
    # lstat detects Windows junctions/reparse points as well as POSIX symlinks.
    for parent in [path, *path.parents]:
        if parent.is_symlink() or getattr(parent.lstat(), "st_file_attributes", 0) & 0x400:
            raise ValueError("Link or junction in runtime path")
    if not path.resolve().is_relative_to(ROOT) or not path.is_file():
        raise ValueError("Invalid runtime source")


def digest(path):
    data = path.read_bytes()
    return {"bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}


def build(output, vendor=False):
    requested = Path(output).absolute()
    for parent in [requested, *requested.parents]:
        if parent.exists() or parent.is_symlink():
            if parent.is_symlink() or getattr(parent.lstat(), "st_file_attributes", 0) & 0x400:
                raise ValueError("Link or junction in output path")
    output = requested.resolve()
    if output.exists() or output == ROOT or ROOT.is_relative_to(output):
        raise ValueError("Output must be a new directory, never the source or its parent")
    if output.is_relative_to(ROOT) and not output.relative_to(ROOT).parts[0].startswith("."):
        raise ValueError("Use an external staging directory or a private dot directory")
    groups = allowlist()
    names = tracked_files()
    selected = sorted(set(names).intersection(groups["runtime"]))
    if selected != groups["runtime"]:
        raise ValueError("Reviewed runtime file is missing from the tracked inventory")
    vendor_names = []
    if vendor:
        vendor_names = sorted(p.relative_to(ROOT).as_posix() for p in (ROOT / "vendor").rglob("*") if p.is_file())
        if not set(groups["vendor"]).issubset(vendor_names):
            raise ValueError("Install the reviewed locked production dependencies privately before --vendor")
    plan = {name: "runtime" for name in selected}
    if vendor:
        plan.update({name: "vendor" for name in groups["vendor"]})
    expected = {}
    for name, kind in sorted(plan.items()):
        safe_source(ROOT / name)
        expected[name] = {"kind": kind, **digest(ROOT / name)}
    output.mkdir(parents=True)
    for name in expected:
        target = output / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    # Inspect actual output instead of reporting assurances as constant values.
    actual = sorted(p.relative_to(output).as_posix() for p in output.rglob("*") if p.is_file())
    if actual != sorted(expected):
        raise ValueError("Export inventory differs from the reviewed copy plan")
    files = []
    for name in actual:
        measured = digest(output / name)
        if measured != {key: expected[name][key] for key in ("bytes", "sha256")}:
            raise ValueError("Export bytes differ from the reviewed source")
        files.append({"path": name, "kind": expected[name]["kind"], **measured})
    excluded = sorted(set(names) - set(selected))
    return {"tracked_files_examined": len(names), "tracked_files_excluded": len(excluded),
            "runtime_files": sum(f["kind"] == "runtime" for f in files),
            "vendor_files_examined": len(vendor_names),
            "vendor_files_excluded": len(set(vendor_names) - set(actual)),
            "vendor_files": sum(f["kind"] == "vendor" for f in files),
            "total_files": len(files), "total_bytes": sum(f["bytes"] for f in files),
            "evidence_files": sum(any(part.startswith("PRISM_") for part in PurePosixPath(n).parts) for n in actual),
            "credentials_copied": any(sensitive(n) for n in actual),
            "unexpected_files": sorted(set(actual) - set(plan)),
            "inventory_verified": actual == sorted(expected),
            "dependencies_included": vendor, "files": files,
            "excluded_tracked_paths": excluded,
            "excluded_vendor_paths": sorted(set(vendor_names) - set(actual))}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True)
    parser.add_argument("--vendor", action="store_true")
    parser.add_argument("--report", help="Write exact verified inventory outside the runtime tree")
    args = parser.parse_args()
    if args.report and Path(args.report).resolve().is_relative_to(Path(args.output).resolve()):
        parser.error("The inventory report must stay outside the runtime tree")
    report = build(args.output, args.vendor)
    if args.report:
        with Path(args.report).open("x", encoding="utf-8", newline="\n") as stream:
            stream.write(json.dumps(report, indent=2) + "\n")
    print(json.dumps({key: value for key, value in report.items() if key not in {"files", "excluded_tracked_paths", "excluded_vendor_paths"}}, indent=2))
