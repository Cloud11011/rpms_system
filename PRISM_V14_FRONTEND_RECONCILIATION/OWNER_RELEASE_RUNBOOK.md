# PRISM V14: owner-operated release runbook

Prepared 2026-11-03 for the branch `reconcile/prism-v14-frontend-20261102` (local commits `51f6858` and `3d3c45d`, plus the uncommitted release changes that Part A commits).

**You run every step.** Nothing in this runbook has been run against GitHub or Hostinger. It was prepared and verified on this machine with disposable databases, simulated mail providers and local Apache only. It supersedes `PRISM_V14_DEPLOYMENT_AND_ROLLBACK_PROCEDURE.md`, which remains for reference.

Evidence behind every statement is in `BATCH_08_FINAL_AUDIT.md`. Where a command or expected value below came from a real test run, that is stated. Where something could **not** be verified without your Hostinger account, it is listed in "Assumptions to check" and has its own stop condition.

---

## 0. Read this first

### 0.1 What this release does
- **Deploys** the reconciled application, which runs correctly on the current **schema 15**. Deploying it changes no database object and does **not** start the notification worker.
- **Migrates** to **schema 16** with the project's tested cron runner (Option A), only after the new code is deployed and verified.
- **Leaves the notification worker disabled.** You decide separately, using the real production queue, when to activate it.

### 0.2 Execution order (important: it differs from the part letters)
The parts are lettered by topic. The Git push to the deployment branch **is the deployment**, so the backup comes first.

| Step | Part | What | Stop here if |
| --- | --- | --- | --- |
| 1 | A.1 to A.3 | Review, verify and commit locally | Any check fails |
| 2 | **C.1** | Production backup, restore proof, schema 15 confirmation | The backup cannot be verified, or the schema is not 15 |
| 3 | B.0 to B.2 | Confirm the deployment mechanism; build and verify the artifact from the pushed code | The artifact is incomplete |
| 4 | A.4 | Push the reconciliation branch (does **not** deploy if the watched branch is as expected) | A deployment starts unexpectedly (Part G.1) |
| 5 | B.3 and A.5 | **Deploy** (the push to the deployment branch, or an upload) | |
| 6 | B.4 to B.8, C.2 | Verify the deployed application on schema 15 | Any check fails (roll back, Part G.1) |
| 7 | D | Schema 16 migration (Option A) | Any stop condition in D.4 |
| 8 | E | Verify the application on schema 16 | Any check fails |
| 9 | F | Notification queue review. **Activation is your separate decision** | The queue differs from the reviewed results |

### 0.3 Placeholders to replace
| Placeholder | Meaning | Example (yours will differ) |
| --- | --- | --- |
| `<ACCOUNT>` | Hostinger account home folder name | `u123456789` |
| `<APP_DIR>` | The folder that holds `index.php` on the server | `/home/<ACCOUNT>/domains/<YOUR-DOMAIN>/public_html` |
| `<PRIV>` | A private folder you create **outside** `public_html` | `/home/<ACCOUNT>/prism-private` |
| `<PHP>` | The PHP binary the cron job uses (check it in B.5) | `/usr/bin/php` |
| `<DEPLOY_BRANCH>` | The branch hPanel deploys (check it in B.0) | `feature/prism-v14-major-revisions` (documented in earlier project reports, **unverified**) |
| `<DB>` | The production database name | |
| `<COMMIT>` | The full SHA of the commit you create in A.3 | |
| `<REPO_DIR>` | This repository on your machine | `C:\PRISM_Workspaces\prism-v14-reconciliation` |

### 0.4 Global stop conditions
**Stop, record the output, and do not improvise a repair** if any of these happens:
- The production backup cannot be verified (C.1).
- The production schema is not exactly `15` before deployment, or not the expected version at any later step.
- The database or migration contract fails verification (`contract` is not `PASS`).
- A required PHP extension is missing.
- The publication artifact is incomplete (B.2).
- The deployed application fails any schema-15 check (B.4 to B.8, C.2).
- Any migration step returns `exit` other than `0`.
- The notification queue differs unexpectedly from the results you reviewed (F).

Never repair a failure with phpMyAdmin DDL, manual file edits on the server, or by deleting tables or rows.

### 0.5 Assumptions that need checking on your Hostinger account
Each is also a step below. Do not skip any.

| # | Assumption | Why it matters | Checked in |
| --- | --- | --- | --- |
| H1 | Hostinger **auto-deploys** the branch named `<DEPLOY_BRANCH>`, and only that branch | A push to it is a deployment. The repository holds no deployment configuration; only earlier project reports say so | B.0 |
| H2 | A Git deployment **keeps** `config.local.php` and `storage/` and does not touch the git-ignored `vendor/` | Losing `config.local.php` takes the site down | B.0, B.4 |
| H3 | Production is on **schema 15**, MariaDB 12.3.2, `utf8mb4_general_ci` (from an earlier export, never queried) | The migration verifiers are pinned to reviewed collations | C.1 |
| H4 | The **cron** PHP has `zip`, `mbstring`, `pdo_mysql`, `posix`, `curl`, argon2id and `proc_open`; web PHP has the upload limits | The runner needs them; `preflight` fails without `zip` | B.5 |
| H5 | hPanel can run a **one-time cron job** and you can read its output and write to `<PRIV>/receipts` | The runner is a cron step | D.1 |
| H6 | **LiteSpeed enforces** the `.htaccess` denials. Only Apache was tested | A Git deployment ships files the allowlist excludes (documents, tooling, SQL) | B.8 |
| H7 | The hPanel backup is **complete and restorable** | It is your only recovery after migration | C.1 |
| H8 | Production runs the pre-reconciliation V14 code (baseline `f3d90c0` or similar) | It is the rollback target and it **cannot run on schema 16** | C.1 |
| H9 | `<APP_DIR>/maintenance/` holds the migration scripts (the allowlist now includes the two that `v16` and `settings` need) | Otherwise the runner steps fail | B.4 |

---

## Part A: Git

All commands run in `<REPO_DIR>` in PowerShell or Git Bash.

### A.1 Review the final state
```
cd <REPO_DIR>
git status
git branch --show-current
git log --oneline f3d90c0..HEAD
git diff --stat HEAD
git config core.autocrlf
```
**Expected:**
- Branch `reconcile/prism-v14-frontend-20261102`.
- Two commits above the baseline: `3d3c45d` and `51f6858`.
- Uncommitted changes limited to: `assets/css/ierbprog.css` (**2 added lines**), documents, tooling under `maintenance/`, and evidence consoles.
- `core.autocrlf` is `true` on this machine (see the warning in B.2).

Check the one runtime change:
```
git diff HEAD -- assets/css/ierbprog.css
```
It must show exactly two added lines: a comment and `@media(max-width:520px) { body.prism-workspace .ierb-pc-head { grid-template-columns:minmax(0,1fr); } body.prism-workspace .ierb-pc-status { justify-self:start; } }`. Anything else: stop.

Check that no application PHP changed since the verified commit:
```
git diff --stat 3d3c45d -- "*.php" ":!maintenance"
```
It must print nothing.

### A.2 Verify before committing
```
python maintenance/frontend-reconciliation/check-syntax.py "$env:TEMP\prism-syntax.json"
node maintenance/onboarding-tests/v14-simplification-smoke.cjs
node maintenance/frontend-reconciliation/checklist-contract.cjs
git diff --check
```
**Expected (from the final run):** syntax `failed: 0` (about 274 files), smoke `65 passed`, checklist `{"status":"PASS","assertions":18}`, and `git diff --check` printing nothing. The "LF will be replaced by CRLF" warnings are normal on this machine.

The full regression suites need disposable databases and local tools; they were run for this release and are not part of your steps. Their results are in `BATCH_08_FINAL_AUDIT.md` §5.

### A.3 Commit
Stage **explicit paths only**. Do not use `git add -A`.

```
git add -- assets/css/ierbprog.css
git add -- maintenance/frontend-reconciliation/settings-tests.php maintenance/frontend-reconciliation/artifact-http-denial.py maintenance/frontend-reconciliation/card-validate-compare.py maintenance/frontend-reconciliation/card-validate.cjs maintenance/frontend-reconciliation/manifest-review.py
git add -- maintenance/release/make-runtime-checksums.py
git add -- PRISM_V14_FRONTEND_RECONCILIATION/OWNER_RELEASE_RUNBOOK.md PRISM_V14_FRONTEND_RECONCILIATION/BATCH_08_FINAL_AUDIT.md PRISM_V14_FRONTEND_RECONCILIATION/BATCH_08_CHANGED_FILE_INVENTORY.md PRISM_V14_FRONTEND_RECONCILIATION/PRISM_V14_DEPLOYMENT_AND_ROLLBACK_PROCEDURE.md PRISM_V14_FRONTEND_RECONCILIATION/visual-parity/visual-parity-120.csv
git add -- PRISM_V14_FRONTEND_RECONCILIATION/claude-v14-fresh-13-console.txt PRISM_V14_FRONTEND_RECONCILIATION/claude-v14-s16-08-console.txt
```
That is every file that `git status` showed as changed or new when this runbook was written, plus the inventory and procedure documents updated alongside it. Run `git status` first: if it lists anything **else**, read it before adding it, and leave out anything you do not recognise.

Check what is staged before committing:
```
git status
git diff --cached --stat
git diff --cached --check
```
There must be no file under `tests/`, `vendor/`, `storage/`, no `config.local.php`, no `.env`, no archive or dump. (`tests/` is git-ignored and `git add -u` never adds ignored files.) Then:
```
git commit -m "release: schema 16 settings, notification safety, approved mobile IERB card, release verification and runbook"
git log -1 --format=%H
```
Record the SHA as `<COMMIT>`.

### A.4 Push the reconciliation branch to GitHub
**Do this at step 4 of §0.2, after C.1 (backup).**

First confirm which branch hPanel watches (B.0). Then:
```
git ls-remote --heads origin
git push -u origin reconcile/prism-v14-frontend-20261102
```
- A new branch on GitHub does **not** deploy if hPanel watches only `<DEPLOY_BRANCH>`. After the push, open hPanel, Git, and look at the deployment log: **no new deployment** should appear.
- The GitHub Actions workflow in the repository triggers on two other branch names, so this push runs no CI.
- If a deployment **does** start, stop and use Part G.1. Your backup (C.1) makes this recoverable.

### A.5 Integrate into the deployment branch (**this is the deployment trigger**)
**Do this only at step 5 of §0.2**, after B.0 to B.2 are complete and C.1 is done. Do **not** push anything to `<DEPLOY_BRANCH>` before then. A push is a deployment.

**Check that the deployment branch has not moved**, then fast-forward. The reconciliation branch is already a direct descendant of `f3d90c0`, so no merge commit is needed:
```
git fetch origin
git rev-parse origin/<DEPLOY_BRANCH>
git merge-base --is-ancestor origin/<DEPLOY_BRANCH> reconcile/prism-v14-frontend-20261102 ; echo "ancestor exit=$LASTEXITCODE"
```
- `git rev-parse` should print `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16` (the baseline).
- The ancestor check must report `exit=0` (PowerShell shows `$LASTEXITCODE`; in Git Bash use `$?`).
- **Stop** if the branch has moved (a different SHA) or the check is not `0`. Someone else pushed; reconcile deliberately, do not force.

```
git switch <DEPLOY_BRANCH>
git pull --ff-only origin <DEPLOY_BRANCH>
git merge --ff-only reconcile/prism-v14-frontend-20261102
git log --oneline -4
git diff origin/<DEPLOY_BRANCH> --stat | tail -1
```
Review the last two outputs: three commits ahead of the remote baseline (`51f6858`, `3d3c45d` and the one from A.3), nothing unexpected. Then, **the trigger**:
```
git push origin <DEPLOY_BRANCH>
```
Never use `--force`. If the push is rejected, stop.

**Alternative (reviewable):** open a pull request on GitHub from `reconcile/prism-v14-frontend-20261102` into `<DEPLOY_BRANCH>` and use "Rebase and merge" (or "Create a merge commit"). Merging deploys exactly as the push does.

---

## Part B: Hostinger code deployment

### B.0 Confirm the deployment mechanism (read-only)
In hPanel, find the Git integration for the site (Websites, Manage, Git; or Advanced, Git). Record:
1. Repository URL (expect `https://github.com/Cloud11011/rpms_system.git`).
2. **Branch** (this is `<DEPLOY_BRANCH>`).
3. **Install path** (this is `<APP_DIR>`, or its parent).
4. Whether **auto-deployment** (a GitHub webhook) is enabled. On GitHub: repository, Settings, Webhooks.
5. The latest deployment in the log (its commit should be `f3d90c0` or a nearby one).

Also record what already exists in `<APP_DIR>`, using a one-time cron job (D.1 shows how to create one):
```
ls -la <APP_DIR>/config.local.php <APP_DIR>/storage <APP_DIR>/vendor/autoload.php > <PRIV>/receipts/files-before.txt 2>&1
```
All three must exist. Keep the output.

**Stop** if hPanel is not using Git deployment: then deployment is by upload (Mode U in B.3), and H1 and H2 do not apply.

### B.1 Gate before deploying
Do not continue until all are true: Part C.1 is complete (backup verified), the rollback target is recorded (C.1 step 4), and you hold the checksum file from B.2.

### B.2 Build and verify the release artifact from the pushed code
**Why a clean checkout:** on this machine `core.autocrlf=true`. A build from the working tree does not match the bytes Hostinger's Linux server receives, so its checksums would never match. Build only from a checkout made with autocrlf off.

Use a **short** path (the repository has deep evidence folders that exceed the Windows path limit otherwise):
```
mkdir C:\prism-rel
git clone --no-checkout https://github.com/Cloud11011/rpms_system.git C:\prism-rel\src
cd C:\prism-rel\src
git config core.autocrlf false
git config core.longpaths true
git checkout <COMMIT>
git rev-parse HEAD
```
(`<COMMIT>` after A.4. If you have not pushed yet, clone from `<REPO_DIR>` instead of the URL.) Then:
```
python -I -B maintenance\release\build-public-runtime.py --output C:\prism-rel\runtime --report C:\prism-rel\runtime-report.json
python -I -B maintenance\frontend-reconciliation\runtime-closure-check.py C:\prism-rel\runtime
python -I -B maintenance\frontend-reconciliation\manifest-review.py
python -I -B maintenance\frontend-reconciliation\artifact-http-denial.py C:\prism-rel\runtime --apache C:\xampp\apache\bin\httpd.exe
python -I -B maintenance\release\make-runtime-checksums.py HEAD --out C:\prism-rel\runtime-sha256.txt
```
**Expected (all verified on this machine):**

| Command | Expected |
| --- | --- |
| `build-public-runtime.py` | `runtime_files: 293`, `unexpected_files: []`, `inventory_verified: true`, `credentials_copied: false`. (`vendor` is not built here: it is git-ignored and your production copy is unchanged because `composer.json` and `composer.lock` did not change) |
| `runtime-closure-check.py` | Exactly one line: `MISSING config.php -> tools/schema-v9-contract.json`, and `missingReferences: 1`. That is baseline code for the historical schema-9 migration and is never run. **Any other `MISSING` line: stop** |
| `manifest-review.py` | `hardFailures: 0`, `cliGuardMissing: 0`, `contentHits: 0`, and 5 `UNREFERENCED (review)` lines for harmless early assets (`student-records-reference.css`, three images, `batch11-public.js`) |
| `artifact-http-denial.py` | `leaks: 0` and `controlsServed: 5` of `5` (about 519 requests). Needs XAMPP's Apache; it starts a throwaway loopback server and stops it |
| `make-runtime-checksums.py` | `files: 293` |

Keep `runtime-sha256.txt`. If any command fails: stop; the artifact is incomplete.

### B.3 Deploy
**Mode G: Git deployment (the documented mechanism).** Run Part A.5. If auto-deploy is on, hPanel deploys after the push. If it is not automatic, press **Deploy** in hPanel, Git. Watch the deployment log for success and note the deployed commit.

**Mode U: manual upload (only if B.0 showed Git deployment is not used).** Upload the contents of `C:\prism-rel\runtime` over `<APP_DIR>`. Never delete or overwrite `config.local.php`, `storage/` or `vendor/`.

Record the time. Do not touch the database in this part.

### B.4 Verify the deployed files
1. Upload `C:\prism-rel\runtime-sha256.txt` to `<PRIV>/runtime-sha256.txt` (File Manager, folder permissions per D.1).
2. One-time cron job:
   ```
   cd <APP_DIR> && sha256sum -c <PRIV>/runtime-sha256.txt --quiet > <PRIV>/receipts/sha-check.txt 2>&1; echo "exit=$?" >> <PRIV>/receipts/sha-check.txt
   ```
   **Expected:** `sha-check.txt` contains only `exit=0`. Any `FAILED` or `No such file` line: stop. Common cause: a Mode U upload built from the Windows working tree instead of the clean clone.
3. One-time cron job:
   ```
   ls -la <APP_DIR>/config.local.php <APP_DIR>/storage <APP_DIR>/vendor/autoload.php <APP_DIR>/maintenance/migrate-prism-settings.php <APP_DIR>/maintenance/migrate-academic-year-settings.php > <PRIV>/receipts/files-after.txt 2>&1
   ```
   All five must exist and `config.local.php` must have the same size and date as in `files-before.txt`. If `config.local.php` or `storage` is missing: stop; restore from your backup (G.3).

(Verified: `sha256sum -c` passes against a no-autocrlf checkout of the commit and reports `FAILED` after a one-byte change.)

### B.5 PHP compatibility and required extensions
Required by `composer.json` and the project's runtime preflight:

| Requirement | Value |
| --- | --- |
| PHP | **8.1 or newer** (tested on 8.2; the project's CI uses 8.2) |
| Extensions | `ctype`, `dom`, `fileinfo`, `iconv`, `libxml`, `mbstring`, `pdo`, `pdo_mysql`, `session`, **`zip`**, `zlib`, plus Argon2id password support |
| Also for the cron runner | `posix` (it verifies file ownership), `proc_open` enabled |
| When Gmail or OpenRouter is enabled | `curl` |
| Web PHP limits | `file_uploads` on, `upload_max_filesize` at least **20M**, `post_max_size` above **20M** |

Check the **web** PHP in hPanel (Advanced, PHP Configuration). The **cron** PHP can differ, so check it too with one-time cron jobs:
```
<PHP> -v > <PRIV>/receipts/php-cron-version.txt 2>&1
<PHP> -m > <PRIV>/receipts/php-cron-modules.txt 2>&1
```
`php-cron-modules.txt` must list `zip`, `posix`, `pdo_mysql`, `mbstring`, `dom`, `fileinfo`, `iconv`, `libxml`, `ctype`, `session`, `zlib`, and `curl` if you plan to use Gmail. **Stop if `zip` or `posix` is missing.** (This development PHP lacked `zip`, and the runner's `preflight` step reported exactly that, so it is a real failure mode.)

### B.6 Smoke tests on schema 15
As an RPMS Administrator, an Adviser and a Student, in a normal browser:

| Check | Expected |
| --- | --- |
| Sign-in, privacy, terms | Load with the ZIP appearance |
| Admin Dashboard, IERB Progress, Student Records, Documents, Reports | Load with real data |
| **IERB Progress on a phone-width window (about 390 px)** | Student names readable; the status badge sits below the name |
| **Settings, Academic Years** | The status line reads exactly: "You can add academic years now. Other academic-year settings will become available after the database update." Add is enabled; the other actions are disabled |
| **Settings, Communication** | Controls are disabled and explain the pending migration |
| Adviser and Student | No Settings link; opening `settings` is refused |
| Reports: generate a Student Progress Report and a project report | A PDF is produced |
| Documents: open one document | It displays; pressing Escape on the page shows no error |
| Account, Legal Policies | Load |

Watch `<APP_DIR>/storage/api_errors.log` for new entries over one working day (read it with a one-time cron job: `tail -n 50 <APP_DIR>/storage/api_errors.log > <PRIV>/receipts/errors-tail.txt 2>&1`).

### B.7 Verify the notification worker is disabled
One-time cron job:
```
<PHP> <APP_DIR>/maintenance/notification-dispatch.php > <PRIV>/receipts/worker-disabled.txt 2>&1; echo "exit=$?" >> <PRIV>/receipts/worker-disabled.txt
```
**Expected, exactly:**
```
{"status":"disabled","message":"Notification worker is not enabled; nothing was read or delivered."}
exit=0
```
Also:
```
grep -c "PRISM_NOTIFICATION_WORKER\|PRISM_MAIL_FALLBACK" <APP_DIR>/config.local.php > <PRIV>/receipts/config-flags.txt 2>&1
```
**Expected `0`.** A non-zero count means `config.local.php` already sets these: open it and confirm that the worker is `false` and the mail fallback is not `true`. If the worker is enabled: stop and set it to `false` before anything else.

Note: any existing hPanel cron job for `notification-dispatch.php` has been failing since schema 15 (the old script required schema 10). With the new code it prints `disabled` and exits 0. Leaving it in place is safe. Do not edit it in this release.

Verified in the built artifact: `PRISM_NOTIFICATION_WORKER_ENABLED=false`, `PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID=null`, `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED=false`; the script prints the line above with no database reachable.

### B.8 LiteSpeed access-control checks
**Required.** If Hostinger deploys from Git, files the allowlist excludes (documents, tooling, SQL) are on the server, and only these rules keep them private. Apache was tested; LiteSpeed was not.

From your machine (PowerShell), replace `<YOUR-DOMAIN>`:
```
$base='https://<YOUR-DOMAIN>'
$urls='/maintenance/hostinger-cron-runner.php','/maintenance/notification-dispatch.php','/maintenance/migrate-prism-settings.php','/maintenance/migrate-academic-year-settings.php','/maintenance/runtime-preflight.php','/includes/schema_v16.php','/includes/admin_settings_schema.json','/includes/notification_worker.php','/vendor/autoload.php','/config.php','/config.local.php','/.htaccess','/.git/config','/composer.json','/composer.lock','/tests/','/storage/api_errors.log','/CLAUDE.md','/PRISM_V14_FRONTEND_RECONCILIATION/BATCH_08_FINAL_AUDIT.md','/PRISM_V14_FRONTEND_RECONCILIATION/schema-16/PRISM_SCHEMA_15_TO_16.sql','/PRISM_V14_FRONTEND_RECONCILIATION/notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql','/maintenance/release/public-runtime-files.json','/maintenance/frontend-reconciliation/security-audit.cjs','/maintenance/frontend-reconciliation/visual-diff.py','/.github/workflows/prism-v14-isolated-regression.yml'
foreach($u in $urls){ $c=(curl.exe -s -o NUL -w "%{http_code}" "$base$u"); "{0}  {1}" -f $c,$u }
```
**Expected:** every line `403` or `404`. **Any `200` is a failure: stop and see G.1.** Controls that must be `200`: `/login.php` and `/assets/css/ierbprog.css`. Also try two variants: `/MAINTENANCE/hostinger-cron-runner.php` and `//maintenance/notification-dispatch.php`, both denied.

(Verified on Apache against the built artifact: 519 requests, 0 leaks, with a negative control that does leak.)

---

## Part C: Schema 15 verification and backups

### C.1 Before deployment: backups and confirmation
1. **Database backup.** hPanel, Databases, Backups (or the backup tool your plan provides): create a **new** backup now. Download a copy. Record its file name, size and SHA-256 (`Get-FileHash <file> -Algorithm SHA256`). **Do not use a phpMyAdmin Export as your backup:** the project's 7R review found it drops a CHECK constraint.
2. **Files backup.** File Manager: compress `<APP_DIR>` **including** `config.local.php` and `storage/`. Download it. Record its SHA-256. Keep a separate copy of `config.local.php`.
3. **Prove the backup restores.** Create a throwaway database and user in hPanel (for example `<ACCOUNT>_restorecheck`). Import the backup into it (phpMyAdmin, Import). Then run the count query below on **both** the restored copy and production and compare:
   ```sql
   SELECT 'users' AS t, COUNT(*) AS n FROM users UNION ALL SELECT 'students',COUNT(*) FROM students UNION ALL SELECT 'advisers',COUNT(*) FROM advisers UNION ALL SELECT 'documents',COUNT(*) FROM documents UNION ALL SELECT 'reports',COUNT(*) FROM reports UNION ALL SELECT 'notifications',COUNT(*) FROM notifications UNION ALL SELECT 'notification_campaigns',COUNT(*) FROM notification_campaigns UNION ALL SELECT 'activity_logs',COUNT(*) FROM activity_logs UNION ALL SELECT 'academic_years',COUNT(*) FROM academic_years UNION ALL SELECT 'ierb_workflows',COUNT(*) FROM ierb_workflows UNION ALL SELECT 'research_projects',COUNT(*) FROM research_projects;
   ```
   Counts may differ only by activity since the backup. **Stop if the restore fails, a table is missing, or counts are far off.** Delete the throwaway database afterwards.
4. **Record the rollback target.** The files backup from step 2 is the code you would restore. Note the deployed commit from hPanel's log (B.0).
5. **Confirm the schema (read-only).** In phpMyAdmin, SQL tab, on the production database:
   ```sql
   SELECT VERSION();
   SELECT @@character_set_database, @@collation_database;
   SELECT k, v FROM schema_meta WHERE k IN ('schema_version','research_onboarding_v1','prism_settings_v1');
   ```
   **Expected:** `schema_version` = `15`; **no** `prism_settings_v1` row; the database collation `utf8mb4_general_ci` and a MariaDB version (earlier export: 12.3.2). **Stop** if `schema_version` is not exactly `15` or `prism_settings_v1` already exists. A different but reviewed collation (`utf8mb4_unicode_ci` or `utf8mb4_uca1400_ai_ci`) is acceptable; any other: stop.
6. **Predict the migration result.** Run:
   ```sql
   SELECT COUNT(*) AS expected_settingsRows_after_v16 FROM (SELECT year_label FROM academic_years UNION SELECT academic_year FROM students WHERE academic_year IS NOT NULL AND TRIM(academic_year)<>'' AND CHAR_LENGTH(academic_year)<=9) y WHERE year_label REGEXP '^[1-9][0-9]{3}-[1-9][0-9]{3}$' AND CAST(RIGHT(year_label,4) AS UNSIGNED)=CAST(LEFT(year_label,4) AS UNSIGNED)+1;
   ```
   Keep the number. Step `v16 apply` (D.3) must report the same `settingsRows`.
7. Run the read-only runner steps `probe` and `preflight` (D.3 rows 1 and 2) **now** and keep their receipts. They need the private file (D.1), and they also prove the runner works on your account before deployment.

### C.2 After deployment: confirm schema 15 still holds
Repeat C.1 step 5, then run `preflight x` (D.3 row 2). **Expected:** `schema_version` `15`, `contract: PASS`, no `prism_settings_v1`. The deployed application must run on schema 15 without any database change. **Stop** if the schema changed or `contract` is not `PASS`.

---

## Part D: Schema 16 migration (Option A, the cron runner)

You approved schema 16 as the target. This is the project's tested no-SSH runner (`maintenance/hostinger-cron-runner.php`). It runs the same PHP migrators the regression suite exercises, with exact contract verification at every step. **phpMyAdmin DDL is not part of this procedure.**

### D.0 Preconditions (all must hold)
- Part B is complete and all its checks passed. The **new code is deployed** (the old code cannot run on schema 16).
- Part C.1 backup is verified and fresh. Take another backup immediately before D.3 row 4 if more than a few hours have passed.
- `probe` and `preflight` have succeeded (C.1 step 7), with `zip` and `posix` present (B.5).
- Quiet window: no document uploads or reviews in progress.

### D.1 Private configuration and folders
1. In File Manager, create `<PRIV>` (for example `/home/<ACCOUNT>/prism-private`) **outside** `public_html`, and inside it `receipts`.
2. Permissions: `<PRIV>` **700** (755 is accepted), `<PRIV>/receipts` **700**.
3. Create `<PRIV>/migration.json` with exactly these five keys (the project's runner requires them):
   ```
   {"dsn":"mysql:host=localhost;dbname=<DB>;charset=utf8mb4","user":"<DB_USER>","password":"<DB_PASSWORD>","database":"<DB>","receiptDir":"/home/<ACCOUNT>/prism-private/receipts"}
   ```
   - `dsn` host is `localhost` on Hostinger shared hosting (as in the project's staging runbook). `database` must equal the `dbname` in the DSN.
   - Use the application's own database credentials. Do **not** paste them anywhere else, and never store them in the repository or the evidence folder.
   - `receiptDir` is the **real absolute path**, with no symbolic link.
4. **Permissions of the file:** `<PRIV>/migration.json` **600** (400 also works). Uploaded files are usually 644, which the runner **refuses**. The runner never changes permissions for you.
5. The runner refuses, **before reading the file or connecting**: a symbolic link; a path that is not canonical; a file inside the application or inside any `public_html`; a file owned by another account; a file or folder writable by group or others; a receipt folder that is group- or world-accessible. The message is `errorClass: unsafe_configuration` and no receipt is written.

Creating a one-time cron job: hPanel, Advanced, Cron Jobs. Choose a custom schedule for the **next minute**, paste the command, and **delete the job after it has run**. Run each line as its **own** job and read its receipt before scheduling the next.

### D.2 Command format
```
<PHP> <APP_DIR>/maintenance/hostinger-cron-runner.php <PRIV>/migration.json <step> <mode>
```
Example with placeholders filled in (the project's staging runbook uses the same shape):
```
/usr/bin/php /home/<ACCOUNT>/domains/<YOUR-DOMAIN>/public_html/maintenance/hostinger-cron-runner.php /home/<ACCOUNT>/prism-private/migration.json probe x
```
- `step` is `probe`, `preflight`, `v16` or `settings`.
- `mode` is `dry-run`, `apply` or `rollback` for `v16` and `settings`. For `probe` and `preflight` the mode is ignored; use `x`.
- Each run prints one JSON receipt to the cron output **and** writes `<PRIV>/receipts/prism-<step>-<mode>-<date>-<time>-<id>.json` (mode 600, never overwritten). Receipts contain no credentials.
- The exit code is in the receipt (`exit`). `0` is success.

### D.3 The seven steps, with expected receipts
**Do not run one until the previous receipt matches.** Values marked (T) were observed in the tested rehearsal on MariaDB 12.3.2; your server's version string and your row counts will differ.

| # | Arguments | Expected receipt |
| --- | --- | --- |
| 1 | `probe x` | `exit: 0`, `connected: true`, `databaseMatches: true`, `database` = your database, `schemaVersion: "15"` (T), `extensions.pdo_mysql: true`, `extensions.posix: true`, **`configurationFile.ownerAndModeVerified: true`** (this is `false` only on Windows) |
| 2 | `preflight x` | `exit: 0`, `stdout.ok: true`, `stdout.database.ok: true`, **`stdout.database.contract: "PASS"`**, `stdout.database.schema_version: "15"`, `missing_objects: []`, `legacy_collation` a reviewed one. On a PHP without `zip` this step exits `1` with `ext-zip` as the only failed capability (T): that is the extension, fix it in B.5 |
| 3 | `v16 dry-run` | `exit: 0`, `stdout: {"ok":true,"mode":"dry-run","to":"16","settingsRows":0,"studentAssignmentsChanged":0}` (T). Nothing is written |
| 4 | `v16 apply` | `exit: 0`, `stdout: {"ok":true,"mode":"apply","to":"16","settingsRows":<N>,"studentAssignmentsChanged":0}` where `<N>` equals the number you predicted in C.1 step 6 (T: 3 in the rehearsal's 3-year catalog). **`studentAssignmentsChanged` must be `0`** |
| 5 | `settings dry-run` | `exit: 0`, `stdout: {"ok":true,"mode":"dry-run","tables":{"prism_settings":false,"prism_deadline_reminder_dispatches":false,"academic_year_removals":false},"migrationApplied":false}` (T) |
| 6 | `settings apply` | `exit: 0`, `stdout: {"ok":true,"mode":"apply","tables":{...all three true...},"migrationApplied":true}` (T) |
| 7 | `preflight x` | `exit: 0`, `stdout.ok: true`, **`stdout.database.contract: "PASS"`**, `stdout.database.schema_version: "16"`, `missing_objects: []` |

The order matters: `settings apply` before `v16 apply` is refused (`precondition_refused`: "The reviewed schema v16 academic-year and notification foundation is required." (T)). Do not skip row 3 or row 5.

### D.4 Stop conditions
**Stop at the first one. Keep the receipt. Do not retry blindly.**

| Receipt shows | Meaning | Do |
| --- | --- | --- |
| `errorClass: unsafe_configuration` (cron output, no receipt) | A file, folder or permission rule in D.1 failed | Fix the named rule; rerun the same step. Nothing was read or changed |
| `errorClass: invalid_invocation` | Wrong step, mode, path or JSON | Fix the command or the JSON; rerun |
| `errorClass: database_mismatch`, or `databaseMatches: false` | The login reaches a different database than `database` | **Stop.** Fix `migration.json`. Never run `apply` |
| `schemaVersion` is not `15` before row 4 | The database is not where you expect | **Stop.** Do not migrate; investigate |
| `contract` is not `PASS`, or `missing_objects` is not empty | The reviewed structure does not match | **Stop.** Do not migrate; keep the full receipt |
| `exit: 1` on any step | A failure | See D.5 |
| `studentAssignmentsChanged` is not `0`, or `settingsRows` differs from the prediction | Unexpected effect | **Stop** and keep the receipt; see D.5 |
| `ext-zip` or `posix` reported missing | PHP extension | Fix B.5; rerun the same step |

Example failure receipts from the rehearsal: `v16 apply` aimed at the wrong database ended `exit: 1`, `errorClass: database_error`, `sqlstate: 42S02`; `settings apply` before schema 16 ended `exit: 1`, `errorClass: precondition_refused`.

### D.5 If a migration step fails
1. **Do not** use phpMyAdmin to create, alter or drop anything, and do not edit rows.
2. Read the failing receipt (`errorClass`, `sqlstate`, `message`). A failure before the version stamp leaves the version at `15` (the version is stamped last, in a transaction), and the migrators are restart-safe.
3. Run `probe x` and `preflight x` to see the true state.
4. Choose:
   - **Cause fixed and state is clean or partial:** rerun the **same** step (`v16 apply` or `settings apply`). A partial table left by an interrupted run is completed and verified.
   - **Settings never used and you want to return to 15:** run the step `settings` with mode `rollback`, then the step `v16` with mode `rollback` (`... migration.json settings rollback`, then `... migration.json v16 rollback`), each as its own job. Expected receipts (T): `settings rollback` gives `"tables":{...all false...},"migrationApplied":false`; `v16 rollback` gives `{"ok":true,"mode":"rollback","to":"15","settingsRows":0,"studentAssignmentsChanged":0}`; then `probe x` shows `schemaVersion: "15"`. **Order matters:** `v16 rollback` before `settings rollback` is refused ("Retain Settings data; remove the separately reviewed Settings extension before base rollback.").
   - **Settings already used** (a setting was saved, or a reminder was queued): rollback is **refused by design** (`precondition_refused`: "Settings migration rollback is blocked while configuration or reminder history exists."). **Do not delete Settings data or audit records.** Fix forward with the new code.
   - **Unrecoverable or uncertain:** restore the hPanel backup (G.4), which returns the database to schema 15. The new code runs on schema 15, so no code rollback is needed for that.
5. After any recovery, confirm with `probe x` and `preflight x` before continuing.

### D.6 If the runner cannot run at all
Only if cron cannot run `proc_open` or the runner cannot be used on your plan: the manual SQL route exists as a **fallback** (`schema-16/PRISM_SCHEMA_16_MIGRATION_GUIDE.md`, Option B). It was tested with the MariaDB command-line client, **not** inside phpMyAdmin itself. Do not start it without telling me why Option A failed.

---

## Part E: Schema 16 application verification
Do these **without saving anything** first.

1. `preflight x` already showed schema 16, `contract: PASS` (D.3 row 7).
2. **Independent database check (read-only).** phpMyAdmin, SQL tab: paste `PRISM_V14_FRONTEND_RECONCILIATION/schema-16/PRISM_SCHEMA_16_VERIFY.sql`. It only reads. **Expected last row:** `overall` = `PASS`, `schema_version` = `16`, `prism_settings_v1` = `1`, table list `schema16 exact`.
3. As an RPMS Administrator, open **Settings**:
   - Academic Years: the status line reads "Changes apply to new registrations. Historical academic years remain in the catalog." Set current, availability and Delete controls are enabled (they act only on click).
   - Communication Settings: the template and reminder controls are enabled.
   - Add Student dialog: academic year choices still list your registration years.
4. Reports, IERB Progress (phone width), Documents, Account, Legal: load as in B.6.
5. Re-run **B.7**: the worker still prints `disabled`.
6. Existing permanent-deletion verification evidence becomes invalid after the migration. Permanent deletion stays unavailable (archiving still works) until you reissue it with the reviewed `deletion-verification` runner step, only if you need permanent deletion.

**Be aware before you save a Setting:** saving **any** Setting (or adding a removal) permanently **ends the rollback window** for schema 16. Once you are satisfied with steps 1 to 5 and have decided to accept that, an optional end-to-end proof is to set the reminder intervals to their current value and confirm the success message.

**Stop** if `VERIFY` is not `PASS` or any page errors. Do not delete anything; see G.2.

---

## Part F: Notification queue review and safe activation
**The worker stays disabled through deployment and migration.** Nothing in this part is run unless you decide to activate it.

### F.1 Review the production queue (read-only)
In phpMyAdmin, SQL tab, on the production database, paste `PRISM_V14_FRONTEND_RECONCILIATION/notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql` and click Go. It opens a `READ ONLY` transaction, returns only ids, counts, statuses, categories and timestamps (never message text, names or addresses), and ends with `ROLLBACK`. It was tested to leave data unchanged and to match the worker's own counts. Save every result grid as CSV with the review time.

| Section | Tells you |
| --- | --- |
| `context` | Schema, review time, highest notification and campaign ids |
| `status` | Counts by delivery status |
| `worker_eligible` | What an **unfenced** worker would pick up now. This is the backlog it must not release |
| `scheduled`, `failed`, `sending_claims` | Overdue scheduled rows, retryable or uncertain failures, stale claims |
| `eligible_categories`, `eligible_recipients`, `eligible_campaigns` | Which categories, recipients and campaigns that backlog touches |
| `fence_preview` | With the default cutoff, how many rows stay held and how many the first run would process |

### F.2 Determine the backlog cutoff immediately before activation
The cutoff (`PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID`) is one notification id. Rows **at or below** it are never delivered, retried, recovered or cancelled by the worker. The web application keeps creating notifications, so the right value is the **current** highest id, read **just before** you activate:
1. Re-run the whole review SQL.
2. Note `max_notification_id` from the `context` section.
3. Compare `worker_eligible` and `fence_preview` with your earlier review. **Stop if the queue differs unexpectedly** (for example a large new eligible group, or stale claims you did not expect).
4. With the recommended cutoff (that max id), `fence_preview` must show `held_backlog_never_touched` = the whole eligible set and `processed_by_first_enabled_run` = `0`.
5. A lower cutoff releases **every** eligible row above it. Preview that exact effect first by changing the `SET @prism_fence` value in the last section. There is no per-row selection. Releasing any historical row needs your own written decision.

### F.3 Decide the mail channel (before activation only)
- `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` **stays `false`.**
- With Gmail credentials in `config.local.php`, mail goes through Gmail only (needs `curl`). A Gmail timeout or server error after acceptance is marked **uncertain**, never retried and never resent through PHP `mail()`.
- **Without** Gmail credentials, PHP `mail()` is the primary transport and may send **real email** through Hostinger's mail server. Decide on that deliberately.

### F.4 Activation (only when you decide)
1. Edit `config.local.php` (untracked, never deployed over). Add, with the number **unquoted**:
   ```
   define('PRISM_NOTIFICATION_WORKER_ENABLED', true);
   define('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID', <max_notification_id>);
   ```
   Anything other than literal `true` keeps the worker off. A missing, negative or quoted cutoff makes it refuse and exit 1.
2. Create or fix the hPanel cron job (for example every 5 minutes): `<PHP> <APP_DIR>/maintenance/notification-dispatch.php --limit=25 --seconds=50`
3. Read the first run's single JSON line: `releaseAfterId` equals your cutoff; `heldBacklog` equals the reviewed eligible count; only rows above the cutoff were selected.
4. Re-run the review SQL `status` and `worker_eligible` sections. Only post-cutoff rows may have moved.

**Stop and switch off** if `heldBacklog` or the counts differ from your review.

### F.5 Switch off
Set `PRISM_NOTIFICATION_WORKER_ENABLED` to `false` (or delete the line). The next run prints `disabled`. A run in progress finishes its bounded batch (at most 25 rows and 50 seconds). Messages already accepted by the provider cannot be recalled. Rows marked `uncertain` need a person to check the Gmail "Sent" folder; they are never retried automatically, and there is no screen for them.

---

## Part G: Rollback and emergency recovery
Choose by **which database version is live**.

### G.1 Roll back the code while the database is still schema 15
(Applies after B.3 to before D.3 row 4, and after any recovery to 15.)

**Preferred: restore the files backup** (C.1 step 2). It does not depend on Git. Restore `<APP_DIR>` from the archive, keeping the current `config.local.php` and `storage/`. Verify with B.6. The previous code runs on schema 15; no database action is needed.

**Via Git** (if Hostinger deploys from Git, the old code must also be what the branch holds, or the next deploy brings the new code back):
```
git switch <DEPLOY_BRANCH>
git revert --no-edit f3d90c0..HEAD
git push origin <DEPLOY_BRANCH>
```
This adds new commits that undo the release (the safe form; never force-push). It redeploys code equal to the baseline `f3d90c0`.

### G.2 After schema 16 is applied
| Situation | Do |
| --- | --- |
| Settings **never used** (no saved setting, no reminder, no removed year) | Runner step `settings` mode `rollback`, then step `v16` mode `rollback` (D.5). Confirm schema 15. **Then** a code rollback (G.1) is possible |
| Settings **in use** | Rollback is refused by design. Keep the data. **Fix forward** with the new code |
| Anyone proposes rolling back the **code only** | **Do not.** The previous code rejects schema 16: `config.php` `migrate()` throws, and its preflight accepts only versions 10 to 15. The site would be down |
| Unsure or unrecoverable | Restore the hPanel database backup (G.4) |

The migration also adds `historical` rows to `academic_years` for existing Student assignments. Rollback does not remove them; they describe existing data and are harmless.

### G.3 If `config.local.php`, `storage/` or `vendor/` went missing after a deploy
They are untracked and ignored, so a normal Git deploy does not touch them. If one is missing:
1. Stop. Do not run any migration.
2. Restore it from the files backup (C.1 step 2). `config.local.php` is the one you saved separately.
3. Re-run B.4.

### G.4 Restore the database backup
This **loses everything written after the backup**: new uploads, reviews, notifications, activity. Use it only when other recovery has failed.
1. Put the site in a safe state (tell users; stop the notification cron job if the worker was ever enabled).
2. hPanel, Backups: restore the backup you verified in C.1. The restored database is at schema 15.
3. The new code runs on schema 15, so no code change is needed. If you also restore old code (G.1), that is fine on schema 15.
4. Run `probe x` and `preflight x`, then B.6.
5. Reconcile `storage/` uploads made since the backup with your files backup.

### G.5 Emergency symptoms
| Symptom | Likely cause | Action |
| --- | --- | --- |
| Every page shows "This deployment supports schema v10 and the authorized v11/v12/v13/v14/v15 extensions only" | **Old code running on schema 16** | Redeploy the new code. Do **not** roll the database back unless G.2 allows it |
| Blank page or HTTP 500 right after a deploy | A missing file or PHP extension | Check `storage/api_errors.log` (B.6) and `sha-check.txt` (B.4). If unresolved, G.1 |
| Pages unstyled | A stylesheet missing from the deployment | B.4 checksum check will list it. Redeploy |
| `unsafe_configuration` from the runner | A permission rule in D.1 | Fix the named rule; nothing was read |
| Worker output is not `disabled` after deploy | `config.local.php` enables it | Set `PRISM_NOTIFICATION_WORKER_ENABLED` to `false` immediately (F.5) |
| A denied URL returns `200` | A LiteSpeed rule is not enforced | Stop. Do not continue the release. Move or remove the exposed file from the server and tell me which URL |

### G.6 Evidence to keep (never the passwords)
The artifact report and checksum file; the backup file names and SHA-256 values; the restore comparison; every cron receipt (they carry no credentials); the review SQL result grids; the B.8 status table; the smoke-test table with dates.

---

## Appendix: what this release contains
- **Frontend:** the reconciled ZIP design on the V14 backend, plus the approved mobile IERB Progress card rule (two lines in `assets/css/ierbprog.css`) and approved wording W1 to W5.
- **Notifications:** a gated, fenced, contract-checked cron worker for schemas 15 and 16, disabled by default; Gmail results classified as sent, rejected, not attempted or uncertain, with no automatic fallback to PHP `mail()`.
- **Settings:** Academic Year and Communication Settings, storable from schema 16; read-only (with Add Academic Year working) on schema 15.
- **Release pipeline:** the publication allowlist now lists the 20 files the reconciliation added.
- **Tests and tooling:** release verification scripts used in Part B, and the schema-16 SQL package (a fallback only).
