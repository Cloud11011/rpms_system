# PRISM V14 7R: Isolated Hostinger staging rehearsal runbook

**For:** the PRISM system owner (hPanel operator) and the reviewing developer. **Scope:** a separate staging database only. Nothing here touches the production database, production files or the production application.

**Status:** the procedure is written and every step was rehearsed locally (MariaDB 12.3.2, real schema-11 export). **The Hostinger staging run itself is PENDING, not passed.** Record each step's receipt in the evidence log (§9).

## 0. Rules

- Use **separate credentials** for staging. Never reuse the production database user.
- Keep every credential file **outside `public_html`**, owned by the hosting account, with permissions **600**. The cron wrapper refuses any other configuration file before reading it (§5).
- Each step below needs its own go-ahead. Stop at the first unexpected result; do not improvise repairs in phpMyAdmin.
- The staging application must not send email, call OpenRouter with real data, or run schedulers that could reach real users (§3).

## 1. Create the isolated staging database (hPanel)

1. hPanel → Databases → MySQL Databases: create database `…_prism_staging` and user `…_prism_stg` with a new strong password. Grant that user **only** this database.
2. Record (without the password): database name, user name, host and creation time.

## 2. Read-only checks on PRODUCTION (phpMyAdmin, SELECT only)

Open production in phpMyAdmin and run **only** these statements. Save the result grid as CSV into the evidence log.

```sql
SELECT VERSION();
SELECT @@character_set_database, @@collation_database, @@character_set_collations;
SELECT k, v FROM schema_meta WHERE k = 'schema_version';
SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1;
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME IN ('documents','reports') AND COLUMN_NAME = 'id')
   OR (TABLE_NAME = 'students' AND COLUMN_NAME IN ('research_title','research_group','stage','academic_year')));
-- Decides whether the export's missing CHECK constraint is an export defect or real drift:
SELECT CONSTRAINT_NAME, TABLE_NAME, CHECK_CLAUSE FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE();
SHOW CREATE TABLE staff_registration_code;
SELECT COUNT(*) AS students, SUM(student_id NOT REGEXP '^[0-9]+$') AS non_numeric_ids FROM students;
```

**Expected:** `12.3.2-MariaDB-log`; schema `11`; all six anchor columns `utf8mb4_general_ci`; a `staff_code_singleton` CHECK with clause `slot = 1` (or `` `slot` = 1 ``).

**If the CHECK constraint is missing in production:** STOP. That is genuine v11 drift. The runners will refuse to migrate (correctly), and a separately reviewed repair is required. Do not continue.

## 3. Backup production (faithful copy)

1. hPanel → Files → Backups → **Database backups**: generate and download a backup of the production database. Do **not** rely on a phpMyAdmin *Export*: it was shown to drop the `staff_code_singleton` CHECK constraint and table options.
2. File Manager: compress `storage/` (documents and reports) and download it. Download `config.local.php` separately and store it **offline**, never in the repository.
3. Record the SHA-256 of each file (on the workstation: `certutil -hashfile <file> SHA256`).
4. **Verify the backup:** restore it into a scratch local MariaDB 12.3.2 and run `SHOW CREATE TABLE staff_registration_code` and the counts in §2. If the CHECK is missing from the hPanel backup too, treat backups as unfaithful and escalate before going further.

## 4. Restore into staging and neutralise integrations

1. phpMyAdmin (**staging database only**) → Import the verified backup. If the import came from the phpMyAdmin export instead (lab use only), and §2 confirmed production has the constraint, apply the documented fidelity repair **to staging only**:
   `ALTER TABLE staff_registration_code ADD CONSTRAINT staff_code_singleton CHECK (slot=1);`
2. Sanitise staging (personal data is not needed to rehearse). Redirect every address to a reserved domain, applying the **same** transformation in all three tables, because PRISM links Student, Adviser and login rows by email:
   `UPDATE users SET email = CONCAT(SUBSTRING_INDEX(email,'@',1), '@staging.invalid'); UPDATE students SET email = CONCAT(SUBSTRING_INDEX(email,'@',1), '@staging.invalid'); UPDATE advisers SET email = CONCAT(SUBSTRING_INDEX(email,'@',1), '@staging.invalid');`
   If a unique-key error occurs (two people share a local part on different domains), stop and sanitise those rows individually. Record the row counts. Run this **only** in staging, and add `staging.invalid` to the staging `ALLOWED_EMAIL_DOMAINS`.
3. Deploy the reviewed 7R code to a **separate staging subdomain** whose `config.local.php` points only at staging and sets:
   - `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`, `GMAIL_SENDER_EMAIL` to **empty** (no outbound mail);
   - `OPENROUTER_API_KEY` to **empty** (local summaries only), unless the owner approves a staging key for synthetic documents;
   - no cron jobs for `notification-dispatch.php` or retention cleanup on staging;
   - `PRISM_IERB_V14_OPERATIONS` **not defined** until step 7.
4. Copy the backed-up `storage/` into the staging subdomain's private storage so that file-existence parity can be checked.

## 5. Prove the no-SSH route (staging)

1. Upload `prism-private/staging-migration.json` **outside** `public_html`:
   `{"dsn":"mysql:host=localhost;dbname=<staging db>;charset=utf8mb4","user":"<staging user>","password":"<staging password>","database":"<staging db>","receiptDir":"/home/<account>/prism-private/receipts"}`
   **Configuration file permissions (enforced):**
   - File Manager → right-click the JSON file → Permissions: **600** (owner read and write only; 400 also works). Uploaded files are usually 644, which the runner refuses.
   - The folder that holds it (`prism-private`) must belong to the account and must not be writable by group or others: **700** recommended (755 is accepted).
   - Give the file's real absolute path in the cron command, with no symbolic link anywhere in it.

   Before reading the file, every step checks that it is a regular file outside the application and outside any `public_html`, given by its canonical path, and that the account owns it with no group or other access. It also checks the folder rule above. Otherwise the step stops with `errorClass: unsafe_configuration`, writes no receipt and makes no connection. The message names the rule, never a value.
   - The runner **never changes the permissions itself.** A file that was 644 may already have been readable by others. Fix the mode by hand, and decide whether to rotate the staging password.
   - Ownership is verified with PHP's `posix` extension. Without it, the step stops with a message naming `posix`: enable that extension in hPanel's PHP configuration (PHP extensions). This was not verified on Hostinger.
   - On a Windows development machine only the path rules apply. Windows has no POSIX owner or mode bits, so the receipt says `configurationFile.ownerAndModeVerified: false`.

   Create the receipt directory with permissions **700** (File Manager → Permissions: owner only), and give `receiptDir` as its real absolute path, with no symbolic link. The runner refuses a receipt directory that is group- or world-accessible, a link, inside the application or inside `public_html`. It writes every receipt and evidence file as a new file with mode 600 and never overwrites one.
   For Remote MySQL (Option B) add `"sslCa": "/home/<account>/prism-private/<provider CA>.pem"`. The probe, preflight and every migration step then verify the server certificate with the same rule.
2. hPanel → Advanced → Cron Jobs: add a **one-time** job (set to run in the next minute, then delete it):
   `/usr/bin/php /home/<account>/domains/<staging>/public_html/maintenance/hostinger-cron-runner.php /home/<account>/prism-private/staging-migration.json probe x`
   **Expected receipt:** `connected: true`, `databaseMatches: true`, `schemaVersion: "11"`, `pdo_mysql: true`, `posix: true`, `configurationFile.ownerAndModeVerified: true`; with `sslCa`, `tls.caVerification: true` and a `tls.cipher`. `errorClass: database_mismatch` means the login reaches a database other than `database`: stop. `errorClass: unsafe_configuration` in the cron output (no receipt is written) means a file or folder rule above failed: correct it and run the probe again. If `proc_open` is reported unavailable in later steps, switch to Remote MySQL (Option B, §8).
3. Same pattern with `preflight x`. **Expected:** `database.contract: "PASS"` at schema 11, `legacy_collation: utf8mb4_general_ci`. The preflight is bound to the configured database too.

## 6. Migrate staging 11 → 15

Run each line as its own one-time cron job. Check its receipt before scheduling the next. Expected results are in brackets.

| # | Arguments | Expected receipt |
| --- | --- | --- |
| 1 | `v12 dry-run` | `exit 0`, `to: 12`, `extensionCollation: utf8mb4_general_ci` |
| 2 | `v12 apply` | `exit 0`, `to: 12` |
| 3 | `preflight x` | contract PASS, schema 12 |
| 4 | `v13 dry-run`, then `v13 apply` | `exit 0`, `to: 13`, `membershipsInferred: 0` |
| 5 | `v14 dry-run`, then `v14 apply` | `exit 0`, `to: 14` |
| 6 | `v15 dry-run`, then `v15 apply` | `exit 0`, `to: 15`, historical academic years listed |
| 7 | `preflight x` | contract PASS, schema 15 |

After step 7, re-run the read-only queries from §2 on staging and compare row counts per table with the pre-migration counts. Every legacy table count must be identical. Only `schema_meta.schema_version` changes, and `academic_years` gains the historical rows.

**Failure handling:**
- Any `exit 1`: STOP. Record the receipt (`errorClass`, `sqlstate`, `driverErrno`, `message`).
- Before 15, a step's `rollback` is safe while its new tables are empty.
- After 15, recovery is a restore of the §3 backup.

## 7. Staging workflow tests (staging only)

1. Add `define('PRISM_IERB_V14_OPERATIONS', true);` to the **staging** `config.local.php`.
2. Use synthetic staging accounts (or the sanitised ones) to run the acceptance list in [PRISM_V14_7R_SECURITY_AND_REGRESSION.md](PRISM_V14_7R_SECURITY_AND_REGRESSION.md) §4:
   - enroll a project holder (`v14_enroll`);
   - preview and reconcile members (`v14_project_preview` / `v14_project_reconcile`);
   - designate the PI;
   - uploads by two or more members;
   - formal submission by a non-PI member;
   - Adviser correction request → RPMS reject → request → approve (Amendment required) → corrected uploads → acceptance → resolve;
   - all 13 requirements accepted → group advancement;
   - Final Report formal submission → automatic completion for every member.
3. Repeat failure scenarios:
   - a stale revision, a duplicate request, a concurrent double submission;
   - deactivating `PRISM_IERB_V14_OPERATIONS` (every operation must be refused).
4. Capture screenshots at 390 px and 1440 px for the request, decision and requirement panels.

## 8. Option B (Remote MySQL)

Use this only if cron cannot run `proc_open`.

1. hPanel → Remote MySQL: allow one workstation IP, for the window only.
2. Run the same runners from the workstation:
   `PRISM_MIGRATION_DB_DSN='mysql:host=<host>;port=3306;dbname=<staging db>;charset=utf8mb4' PRISM_MIGRATION_DB_USER=… PRISM_MIGRATION_DB_PASSWORD=… PRISM_MIGRATION_DB_SSL_CA=<CA bundle path> PRISM_ALLOW_SCHEMA_V12_MIGRATION=1 PRISM_SCHEMA_V12_EXPECT_DB=<staging db> php maintenance/migrate-ierb-v14.php dry-run`
3. Remove the Remote MySQL allowance immediately afterwards.

## 9. Evidence to keep (no secrets, no personal data)

- The §2 query results.
- Backup file names and SHA-256 values.
- The restore verification output.
- Every cron receipt (they are credential-free).
- Pre and post per-table row counts.
- File-existence parity counts.
- Workflow test notes and screenshots.

**Do not** store the passwords, `config.local.php` or unsanitised exports with the evidence.

## 10. Addendum (compliance closure, 2026-10-28): test-data cleanup rehearsal on a SECOND staging restore

The owner's revised decision (D12): **preserve every Administrator (1, 13, 46); remove every test Student and Research Adviser** with their test research records.

**Keep the restores separate:**
- Staging restore #1 (§4–§7) stays the **migration compatibility rehearsal** on the original populated data. Do not clean it.
- Clean a **separate** staging database restored from the same verified backup.

**Steps on staging restore #2** (each step needs its own go-ahead):

1. Restore the verified backup into a second staging database (§1, §4); neutralise mail, AI and cron (§4.3).
2. Revalidate the Administrators: `SELECT id, role, status, created_at FROM users WHERE role='admin' ORDER BY id;` must return exactly 1, 13, 46.
3. Deletion-verification evidence for this database:
   - if the staging `config.local.php` has no `PRISM_HARD_DELETE_VERIFICATION_KEY`, add a new random 64-hex key there;
   - run a one-time cron job: `/usr/bin/php …/maintenance/hostinger-cron-runner.php /home/<account>/prism-private/staging-cleanup.json deletion-verification x`. That private JSON adds `deletionVerificationKey` and `"externalDependenciesExcluded": true` to the §5 fields, and follows the same §5 file rules (permissions 600, enforced);
   - paste the evidence file from `receiptDir` (`prism-deletion-verification-evidence-<time>-<id>.json`, mode 600; the receipt names it but does not contain it) into the staging `config.local.php` with `define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', true);`. The evidence is never printed to cron output.
4. The Administrator who will operate the cleanup **signs in now**, before the preview. Sign-in may upgrade an older password hash.
5. Preview: `php maintenance/clean-database-preview.php --retain-admins=1,13,46 --redact` (by cron, or by Remote MySQL with the `PRISM_CLEANUP_DB_*` variables). Keep the JSON; the owner signs `decisionSha256`.
6. Audit retention extract, if RPMS approved option A ([11](PRISM_V14_7R_COMPLIANCE_CLOSURE/11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §4): export `SELECT * FROM activity_logs WHERE id IN (<auditImpact.predictedRemovalIds>)` read-only, record its SHA-256, and store it with the backup.
7. Follow [11_CLEAN_PRODUCTION_DATABASE_PLAN.md](PRISM_V14_7R_COMPLIANCE_CLOSURE/11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §7 steps 7–10 in the Admin UI:
   - cancel Adviser future deadlines;
   - delete the pre-step document versions **with a reason**;
   - archive, then permanently delete, every Student and then every Adviser.
8. End of the cleanup window: `--mode=verify-final --retain-admins=1,13,46 --institution-reports=kept --expect-from=<signed preview>` must print `PASS` with 28 reports remaining.
9. Separately authorized report deletion: Reports → Delete the 28 institution-wide reports, then the same command with `--institution-reports=deleted` must print `PASS`.
10. **Each of the three Administrators signs in** and opens Dashboard, Students, Advisers, Documents, Reports, Calendar, IERB Progress, Notifications and Account.
11. Optional: migrate this cleaned copy 11 → 15 (§6), run preflight, re-issue the evidence at 15, then run the post-cleanup V14 checks:
   - invite an Adviser and a Student;
   - enroll and reconcile a project;
   - Final Report completion.

Production cleanup is a separate, explicitly authorized window after this rehearsal's evidence is approved. It is never combined with the migration window.
