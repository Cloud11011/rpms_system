# Batch 1 — Baseline and safety

Date: 2026-11-02 (Asia/Manila). Verdict: IN PROGRESS; current baseline regression is running.

- Workspace: `C:\PRISM_Workspaces\prism-v14-reconciliation`.
- Existing required branch reused: `reconcile/prism-v14-frontend-20261102`.
- Initial HEAD and rollback source: `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16`.
- Initial working tree: clean, including no staged changes. No reset, replacement, checkout, push, merge, PR, deployment or production migration performed.
- GitHub branch-list API independently returned the same SHA for `feature/prism-v14-major-revisions`. The earlier encoded single-branch API URL was rejected; the successful read used `https://api.github.com/repos/Cloud11011/rpms_system/branches?per_page=100`.
- User corrected the archive path to `C:\PRISM_Workspaces\prism-frontend-master\rpms_system_new.zip`.
- Original archive SHA-256: `2c3133af45d4d320ce86e81856ccdbfdc273ef00b68834fdb30829f48f4b4a57`.
- The archive's Git HEAD identifies `770bed25526aa599c89083c382a8b083e8d58d37`, a verified ancestor of the V14 baseline. Its Git directory was inspected only for the HEAD/ref and was not extracted.
- Archive opened read-only. Restricted reference extraction is under ignored `tests/frontend-reconciliation/reference`. No `.git`, vendor, private configuration, uploads, storage, database dumps or prior generated evidence was imported.
- 7,989 archive files; 7,626 excluded; 363 relevant reference files and 343 relevant V14 files. Normalized union: 212 identical, 114 modified, 37 ZIP-only, 17 V14-only. See `baseline.json` and `reconciliation-matrix.csv`; initial decisions require hunk-level review.

## Environment and checks executed

PHP 8.2.12 (XAMPP; ZIP enabled per process), MariaDB 10.4.32 (fresh datadir and loopback port 13981), Node 25.0.0, Python 3.11.4, Composer 2.10.3, Playwright Core 1.64.1, installed Google Chrome. No Docker or production access used.

`composer install` was attempted but could not download through the restricted network. Dependencies were instead copied from the existing local `prism-v14-simplification-validation/vendor`, whose `composer.lock` SHA-256 matches this repository (`285896fe571cd411b7f082448327f54a8f13315351e18732faf1fe10d2738ec7`). Installed metadata matches locked PdfParser 2.12.5 and Symfony polyfill-mbstring 1.43.0. This is not the ZIP's vendor directory. `composer check-platform-reqs --no-dev` passes all 12 requirements.

Commands:

```powershell
python maintenance/frontend-reconciliation/analyze.py 'C:\PRISM_Workspaces\prism-frontend-master\rpms_system_new.zip' --extract-reference
node maintenance/onboarding-tests/v14-simplification-smoke.cjs
git diff --check
& maintenance/onboarding-tests/run-regression.ps1 -MariaDbBin 'C:\xampp\mysql\bin' -ReviewName 'tests/frontend-reconciliation/baseline-runtime' -DbPort 13981 -AppPort 14981
```

Results so far: 65 source/static smoke checks passed; 0 failed. `git diff --check` passed. Synthetic schema-11 exact manifest initialization passed (23 tables). Full regression result is pending; historical assertion totals are not reused.

Evidence: `baseline-checks/smoke.txt`, `baseline-checks/regression-console.txt`, ignored `tests/frontend-reconciliation/baseline-runtime/`.

Files created: this report, `baseline.json`, `reconciliation-matrix.csv`, `PENDING_UI_APPROVAL.md`, baseline check outputs and `maintenance/frontend-reconciliation/analyze.py`. Runtime application files remain unchanged at this checkpoint. Visual changes: none. Rendered parity has not yet been tested.

Next: finish baseline regression; review compatibility candidates against the common ancestor; apply only reviewed changes batch by batch.
