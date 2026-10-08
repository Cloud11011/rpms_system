# PRISM v8 operator deployment instructions

## Admin document summaries: additional prerequisites

This change has not been deployed. The hosting environment has not been inspected remotely.
Before deploying this version, build dependencies from the supplied `composer.lock` using
`composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader`.
The direct PDF dependency is pinned to stable **smalot/pdfparser 2.12.5**; retain its license files.
Ship the resulting **entire `vendor/` directory** with the application: Hostinger may not run
Composer in its deployment path. Never rely on a development machine's unshipped dependencies.
Run `composer check-platform-reqs --no-dev` where Composer is available, and run
`php tools/check-document-summary.php` with the hosting PHP configuration. Confirm PHP 8.1+,
zlib, iconv, mbstring, XML/DOM, ZIP (DOCX), cURL (AI), and readable `vendor/autoload.php` in
the **web runtime**, which may have different extensions/settings from CLI. Local ZIP is
disabled by default; local test commands explicitly use `-d extension=zip`.

Provide at least 128 MB PHP memory (256 MB recommended; larger files may be rejected at the
lower setting) and at least 60 seconds request time, with sufficient hosting worker limits.
Admin PDF extraction temporarily caps PHP memory at the lower of the configured limit and
256 MB, limits parsing to 20 seconds in web requests, and checks a 15-second processing budget.
It caps files at 20 MB, each decoded stream at 8 MB, inspected streams at 64 MB, objects at
15,000, font mappings at 100,000 entries, extracted text at 200,000 bytes and examined pages
at 200. The mature parser initially builds its raw object structures before some application
checks; PHP's finite memory/time limit and safe fatal-response handler remain the final boundary.
No shell command, worker process, remote file upload or OCR service is used. Upload-time
approval-date extraction remains the existing lightweight local operation.

The summary-specific CMap adapter normalizes legal compact CMap end delimiters before the
locked parser initializes fonts, preventing a real publisher PDF's ligature array from being
misread as a huge scalar range. Keep `includes/summary_pdf_parser.php` with the application;
do not replace it with an unmodified direct parser call or edit `vendor` as a deployment fix.

Ensure Apache/LiteSpeed honors the root `.htaccess` and its rewrite rules. Verify direct HTTP
requests to `vendor/autoload.php`, `vendor/composer/installed.json`, `composer.json`,
`composer.lock`, private storage and tests receive 403/404, while server-side autoload works.
When those rules are not supported, configure equivalent hosting-level denials before release.

Only the authenticated Admin `summarize` POST action transmits redacted text. Input is bounded
below the existing 12,000-character transport limit; later method/ethics passages may be sampled.
Document responses opt into sensitive transport logging, a 900-token output limit and a 64 KB
response limit. Existing aggregate calls retain their transport defaults and model configuration.
Known linked student/group/adviser identities, student IDs, email addresses, protocol codes and
obvious labeled identifiers are removed locally; arbitrary prose cannot be guaranteed anonymous.
The review aid explicitly discloses truncated coverage. Scanned/image-only, encrypted and
unreadable PDFs require a readable text-based copy; no OCR has been added.

`documents.ai_summary` contains summary text only. Source/partial indicators are returned for
the current generation and recorded in content-free audit details. A saved summary reopened
without that in-memory response shows **Source not recorded**. No schema change is required.
No workflow fields or approval dates are updated by generation or regeneration.

Tests and their fixtures were previously ignored wholesale. The narrowly scoped `.gitignore`
exceptions now expose the new summary tests, changed regression sources and attributed format
fixtures for review; caches and generated result logs remain ignored. Existing broader local
regression fixtures are still used by the local regression runner.

Prepared 2026-10-08. These are review instructions; deployment and production migration have **not** been performed. Preserve the live configuration, secrets, database and private files. Local verification used PHP 8.2.12 and disposable MariaDB 10.4.32. Confirm the hosting PHP/MariaDB versions and extensions separately.

## 1. Review and back up

1. Review the complete local diff and `PRISM_POST_GATHERING_REPORT.md`. The starting branch was `prism-v2-ui-restyle`, HEAD `0e4da332a586c737b64a5ed508ad867b0a963748`. Fetch found two later upstream UI commits; neither was merged. Reconcile those changes and the two existing local CSS edits before choosing the final commit/tag. No commit or push has been made for this task.
2. Back up the **entire** database, including schema, data, keys and constraints. Back up `storage/documents`, `storage/reports` and protected configuration separately. Verify that the backup can be restored to a disposable database. Store backups outside the public web root. Do not run a reset, reseed or table rebuild.
3. Record row counts and representative values for users, students, advisers, IERB history, all document versions, formal-submission fields, reports, activity logs, notifications and official deadlines/groups. Include archived records when counting after migration. Keep the counts privately; do not publish research data.
4. Build a staging copy with isolated credentials and synthetic email recipients. Disable outbound Gmail/OpenRouter and cron until controlled verification. Run the v8 upgrade and repeat it on this copy before touching production.

## 2. Confirm prerequisites and canonical v7

Use InnoDB and `utf8mb4`. Enable `pdo_mysql`, `mbstring`, `fileinfo`, `zip` and `curl` in both web and CLI PHP. ZIP validation refuses DOCX/ODT when ZipArchive is unavailable. Configure writable private storage and authentication-rate-limit storage without allowing public file access. Check PHP upload/post limits against the application's 20 MB maximum.

In phpMyAdmin, select the exact database and inspect:

```sql
SELECT DATABASE();
SELECT v FROM schema_meta WHERE k='schema_version';
SHOW CREATE TABLE calendar_deadlines;
SHOW CREATE TABLE calendar_deadline_groups;
```

Expected v7 deadline schema:

| Table | Required structure |
|---|---|
| `calendar_deadlines` | InnoDB; PK `(id)`; BTREE `deadline_date_status(deadline_date,status)` and `deadline_creator(creator_user_id)`; FK `creator_user_id -> users(id) ON DELETE SET NULL` |
| `calendar_deadline_groups` | InnoDB; `research_group VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL`; PK `(deadline_id,research_group)`; BTREE `deadline_group_lookup(research_group,deadline_id)`; FK `deadline_id -> calendar_deadlines(id) ON DELETE CASCADE` |

The automatic v8 migration validates v7 and refuses incompatible schema before stamping v8. Existing working but incompatible tables are not automatically rebuilt.

If group type/collation differs, inspect the complete DDL, existing keys and these data checks on staging:

```sql
SELECT deadline_id, CHAR_LENGTH(research_group) AS group_length
FROM calendar_deadline_groups WHERE CHAR_LENGTH(research_group)>190;
SELECT deadline_id, BINARY research_group, COUNT(*) AS copies
FROM calendar_deadline_groups
GROUP BY deadline_id, BINARY research_group HAVING COUNT(*)>1;
```

If either returns rows, stop and review the records; never truncate or delete them to force the upgrade. Only after a verified backup and staging demonstration that all values/keys survive, an operator can apply the precise type/collation repair:

```sql
ALTER TABLE calendar_deadline_groups MODIFY research_group
VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
```

For an absent secondary index, add only the named index listed above after checking for an equivalent or conflicting existing definition. For wrong FK/PK/engine definitions, obtain a reviewed, installation-specific repair with orphan checks on staging; do not drop/rebuild tables or remove records. Databases older than v7 need the existing guarded migration path verified against their own backup first. The provided manual SQL is specifically for canonical v7/v8.

## 3. Apply v8 during maintenance

Pause application writes and cron for the maintenance window. Do not serve new application code against pending v7 schema: ordinary requests intentionally refuse migration. There are two operator routes.

### Route A: hosting CLI

Use the exact configured database name and the actual hosting PHP binary/path. The helper requires all three explicit authorizations and checks `DB_NAME` before connecting:

```sh
PRISM_ALLOW_SCHEMA_V8_MIGRATION=1 PRISM_MIGRATION_EXPECT_DB=exact_database_name php /full/path/to/prism/tools/migrate-hardening.php --apply
```

The helper invokes migration directly without the `db()` account bootstrap/seed path. The migration is serialized with a DB lock, additive and retryable. It validates before advancing the schema version. An error can leave completed additive DDL because MariaDB/MySQL DDL may commit implicitly; preserve the version, fix only the stated incompatibility on staging, then retry. Never mark a partially failed migration complete manually.

Remove the migration environment variables after use. Do not leave migration opt-ins in cron or normal web configuration. Repeat on staging to demonstrate idempotence. A current v8 database returns without pending DDL; a read-only schema inspection remains necessary.

### Route B: phpMyAdmin without SSH

Use [`tools/schema-v8-manual.sql`](tools/schema-v8-manual.sql) privately. Do not upload it as a public endpoint. The file **refuses mutations by default**. After the backup, canonical-v7 review and successful staging run, edit only:

```sql
SET @PRISM_EXPECT_DB = 'the_exact_selected_database_name';
SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 1;
```

Select that database explicitly, run the whole file, and inspect every error/result. It adds missing columns and the original-recipient table, then backfills recipients. It deliberately does **not** advance the schema version. Compare DDL and record counts, repeat the script and confirm unchanged definitions/counts. Only after all validations below pass, execute the separately commented statement:

```sql
UPDATE schema_meta SET v='8' WHERE k='schema_version' AND v='7';
```

The exact default refusal, enabled v7 upgrade, repeat behavior and resulting canonical schema were tested on disposable MariaDB. The human must validate the hosting results; `CREATE TABLE IF NOT EXISTS` alone does not validate an incompatible existing table.

## 4. Verify additive schema and retained data

```sql
SELECT v FROM schema_meta WHERE k='schema_version';
SHOW CREATE TABLE students;
SHOW CREATE TABLE notifications;
SHOW CREATE TABLE calendar_deadline_recipients;
SHOW CREATE TABLE calendar_deadlines;
SHOW CREATE TABLE calendar_deadline_groups;
```

Expected version is 8. New fields are `students.archived_at DATETIME NULL` and `notifications.sending_started_at DATETIME NULL`. Existing Students remain unarchived. The new audience table is InnoDB, with PK `(deadline_id,student_id)`, BTREE `deadline_recipient_student(student_id,deadline_id)`, FK to deadlines with CASCADE and FK to Students with RESTRICT. Confirm the canonical v7 definitions above too.

Compare every pre-migration count and representative history/version/submission value. New recipient/counter metadata is expected; existing collected rows must survive unchanged. Old deadline audience is recovered from original notification messages. Where no original notification history exists, the migration uses the creator's currently authorized audience. Review those historical deadlines manually because past assignments cannot be inferred reliably.

## 5. Production configuration and private paths

Preserve `config.local.php` and real secret values. The operator should verify:

- `APP_ENV=production`, canonical public HTTPS `APP_BASE_URL`, development password disclosure disabled, and Admin self-registration disabled according to the existing configuration.
- One-time `PRISM_INITIAL_ADMIN_PASSWORD` bootstrap environment is unset. Do not invoke seeding against existing data.
- Hosting enforces HTTPS. Session cookies are Secure, HttpOnly and SameSite=Lax; session lifetime supports the application's 1,800-second idle limit. Check the hosting proxy HTTPS convention rather than blindly trusting arbitrary public forwarding headers.
- Server/application timestamps match the existing Asia/Manila / `+08:00` convention.
- Gmail OAuth and OpenRouter credentials are private; select the intended explicit `OPENROUTER_MODEL` rather than relying on `openrouter/auto` when a fixed provider/model is required.

Run, on the configured hosting environment:

```sh
php /full/path/to/prism/tools/check_configuration.php --production
```

This checks configuration format and required extensions without connecting to DB/providers or printing values; it is not proof of Gmail/AI delivery.

Exclude `tests/`, test results, SQL/backups/diffs, `.git/`, and browser OAuth/integration helpers from the public deployment package. Keep the necessary CLI migration helper and cron worker private. If helper files remain, their production/loopback guards and server denial rules must work. Verify actual hosting HTTP responses for `storage/`, `storage/mail.log`, an existing private document/report, `config.local.php`, `.git/config`, `schema-audit.txt`, `tests/` and maintenance tools: 403/404 with no file contents. Verify that uploaded files cannot execute. Local isolated Apache passed these protections; the installed local Apache instance was inconclusive, and hosting rules still require verification.

Inspect HTTPS response headers: nosniff, frame protection, Referrer-Policy and CSP frame-ancestors/base-uri. Production HTTPS emits `Strict-Transport-Security: max-age=31536000`; no preload/includeSubDomains was added. Reset-token pages must emit `Referrer-Policy: no-referrer`. Do not enable HSTS while production HTTP is intentionally supported.

## 6. Cron and delivery verification

Install the existing CLI worker with the actual hosting paths; for example, once per minute:

```cron
* * * * * /usr/bin/php /full/path/to/prism/tools/process_scheduled_notifications.php
```

Each run considers at most 20 due/stale records. Larger broadcasts (over 5 recipients) and all official-deadline delivery use the queue. Verify cron output and the scheduled/sent/failed/logged states using controlled recipients. At one run per minute, capacity is at most 20 attempts/minute and can be lower during provider latency; choose the schedule based on the actual cohort and Gmail limits.

Workers claim atomically, retain a per-notification DB mutex during delivery, and recover timestamped Sending rows older than 15 minutes. Fresh Sending and Sent rows are not retried. Legacy Sending rows whose new timestamp is NULL are intentionally untouched. For each such legacy row, verify provider delivery and business intent before explicitly requeuing that particular row. Never reset all Sending/Sent rows.

If a process crashes after the provider accepted an email but before the DB stored Sent, a retry can send a duplicate. The provider API offers no transaction with the local DB; named locks prevent concurrent sends, not this crash ambiguity. Retain notification records, inspect delivery status and handle isolated ambiguous cases manually.

## 7. Controlled final smoke test and release

With controlled test accounts/recipients, verify login, shared-IP throttling, forced password change, setup/reset, invalid/expired/reused links, password-induced session invalidation and idle logout. Use newly issued setup/reset links; credential delivery failures must show pending/requested results without logging tokens. Old mail logs may already contain historical links: restrict access and follow the institution's retention policy; do not delete collected logs blindly.

Verify own/foreign Student boundaries, assigned/foreign Adviser boundaries, historical Admin review, Student archive preservation, Adviser deactivation and explicit reassignment, document upload/versioning/review/remarks/formal submission/Admin Override, deadline double-click and cancellation after reassignment, queued broadcast delivery and report authorization. Use valid DOC/DOCX/ODT/PDF/TXT/RTF/PNG/JPG samples and a rejected renamed ZIP. Avoid testing destructive operations on collected records.

Verify controlled Gmail setup/reset, review, formal-submission, deadline and bulk emails. Verify the intended OpenRouter model and deterministic no-key/provider-failure fallback using synthetic data; identifiable Student fields must remain local. Check Admin/Adviser/Student screens in both themes on the supported browsers/devices, including 1440/1280/1024/768/390/375/320 widths. Test upload discovery, one correct sidebar logo, saved collapse, keyboard/Escape, submenu restoration, resource hash selection, footer/social links and pagination.

Only the human operator should choose the reviewed commit/tag, restore writes, enable cron and release. If problems arise, retain both backup and post-upgrade records, place the app back into maintenance and diagnose; do not drop additive fields/tables or restore an old DB over newly collected data without a reconciliation plan.
