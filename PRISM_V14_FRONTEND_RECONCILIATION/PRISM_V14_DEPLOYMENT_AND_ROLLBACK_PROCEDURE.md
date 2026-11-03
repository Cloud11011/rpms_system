# PRISM V14 reconciliation: deployment and rollback procedure

> **Superseded 2026-11-03 by `OWNER_RELEASE_RUNBOOK.md`.** That runbook is the owner-operated release procedure (corrected order: backup before the deployment push; checksum verification; the tested cron runner; the notification cutoff). Use it, not this document. This one is kept for reference only.

Prepared 2026-11-03 for the branch `reconcile/prism-v14-frontend-20261102`.

**Status: prepared, never executed.** Nothing in this document has been run against Hostinger, and no production credential, database, cron job or e-mail provider was touched while writing it.

Each phase below needs **its own written approval**. Approving one phase approves nothing after it. Stop at every "Gate" and wait.

## 0. What this procedure assumes

| Assumption | Source | Check before Phase A |
| --- | --- | --- |
| Production is on schema 15 | Stated by the owner; not queried | Run the read-only `probe` and `preflight` steps (Phase A.4) |
| MariaDB 12.3.2 with `utf8mb4_general_ci` | An earlier export; not queried | `PRISM_SCHEMA_16_VERIFY.sql` and the `probe` receipt report the real server and collation |
| Production runs the pre-reconciliation V14 code | Baseline `f3d90c0` or similar | Record the exact production file set (A.2). It is the rollback target |
| Hostinger shared hosting, LiteSpeed, no SSH | `CLAUDE.md` | Operations use hPanel File Manager, phpMyAdmin and one-shot cron jobs |
| `config.local.php` exists on production and is not part of any deployment | `.gitignore`, `config.php` | Never overwrite it. This procedure never needs a new constant in it except at Phase F |

## 1. Release gates (all must be green before Phase B)

| Gate | Evidence |
| --- | --- |
| Full current-V14 regression on a disposable **schema 15** database | 408 pass (`tests/claude-v14-fresh-12`) |
| Same on **schema 16** + Settings | 418 pass (`tests/claude-v14-s16-07`) |
| Broad suite, current phases | foundation 99, forms UI 134, 7R 120, closure 96, extended 120 and 96, extension migration, approval race, rollback (`claude-broad-v14-15`) |
| Notification worker and provider scenarios | 98 and 103 on MariaDB 12.3.2 and 10.4.32 |
| Settings and schema scenarios | 75, 40, 17; SQL route 60 on three server and collation combinations; Option A runner 16 |
| Security matrix | 9 checks, 56 pages and APIs × 4 identities, in both regression runs |
| Apache `.htaccess` test (H3) | 388 pass |
| Publication builder test | 15 pass |
| Public tree closure | Only the baseline schema-9 reference remains (`runtime-closure-check.py`) |
| Static gates | syntax, smoke 65, checklist 18, `git diff --check` |
| ZIP unchanged | SHA-256 `2c3133af…4b57` |
| The **allowlist additions** (20 files) are approved by you | Batch 8 report §6 |
| The **IERB Progress mobile card** decision is made | Batch 8 report §4 |

## 2. Phase A: prepare (no production change)

1. **Freeze.** Agree a change window and an owner who can restore from backup.
2. **Record the rollback target.** Download the production application files (hPanel → Files → File Manager → compress `public_html`, **excluding** `config.local.php` and `storage/`). Keep it outside the web root. Record its SHA-256.
3. **Backups.**
   - hPanel → Databases → Backups: take a database backup.
   - Do **not** rely on a phpMyAdmin export. The 7R review found that it drops a CHECK constraint.
   - Back up `storage/` (uploaded documents and reports).
4. **Read-only checks.** Use the project's one-shot cron runner as described in `PRISM_V14_7R_STAGING_MIGRATION_RUNBOOK.md` §5: `probe x`, then `preflight x`. Expect:
   - `schemaVersion` 15
   - `databaseMatches: true`
   - `contract: PASS`
   - Required PHP extensions present, including `zip`. A missing `zip` is a stop condition.
5. **Cron jobs.** List the existing hPanel cron jobs. Note any `notification-dispatch.php` job: it has been failing since schema 15 because the old script required schema 10.

**Gate A:** all checks recorded; backup verified restorable.

## 3. Phase B: build and verify the release artifact (no production change)

```
python maintenance/release/build-public-runtime.py --output <new empty folder> --vendor --report <report.json>
python maintenance/frontend-reconciliation/runtime-closure-check.py <that folder>
```

- The builder refuses an existing output folder, links and anything sensitive. Expect `unexpected_files: []`, `inventory_verified: true`.
- The closure check may report only `config.php → tools/schema-v9-contract.json` (baseline, git-ignored, migration-only).
- Confirm the artifact has no `config.local.php`, `tests/`, `PRISM_*`, `*.sql`, `*.cjs` or `maintenance/frontend-reconciliation/`.
- Install dependencies from `composer.lock` and verify them independently before `--vendor`. `composer.json` and `composer.lock` are unchanged from the baseline.
- Keep `report.json` (every file with its SHA-256) as the deployment record.

**Gate B:** artifact verified.

## 4. Phase C: deploy the code to the schema-15 production (approval 1)

The reconciled code runs correctly on schema 15. Deploying it changes **no database object** and **does not start the notification worker**.

1. Upload the verified artifact over the application files. Leave `config.local.php` and `storage/` untouched.
2. If the host caches PHP or assets, purge that cache.
3. Smoke test (read-only), as an RPMS Administrator, an Adviser and a Student:

| Check | Expected |
| --- | --- |
| Sign-in pages, privacy and terms | Load, ZIP appearance |
| Admin Dashboard, IERB Progress, Student Records, Reports | Load with real data |
| **Settings → Academic Years** | Shows exactly: "You can add academic years now. Other academic-year settings will become available after the database update." Add is enabled; policy actions are disabled |
| **Settings → Communication** | Controls disabled with the pending-migration message |
| Adviser and Student | No Settings link; Settings URL refused |
| Reports → generate a Student or project report | PDF generated |
| Run the existing notification cron job once, or `php maintenance/notification-dispatch.php` | Prints `{"status":"disabled",…}`, exit code 0, no database connection |
| `preflight x` | `contract: PASS`, schema 15 |

4. Watch the application error log (`storage/api_errors.log`) for new entries for one working day.

**Gate C:** all smoke checks pass. **Do not proceed to Phase D, E or F without a new approval.**

### Rollback for Phase C (schema still 15)
Redeploy the file set recorded in A.2. The previous code runs on schema 15. No database action is needed. The notification worker is unaffected (the previous script simply keeps failing on schema 15 as before).

## 5. Phase D: production queue review (approval 2, read-only)

1. phpMyAdmin → PRISM database → SQL tab → paste `notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql` → Go. It opens a `READ ONLY` transaction and returns only ids, counts, statuses, categories and timestamps.
2. Save every result grid with the review time.
3. Decide, in writing, what happens to the held backlog (see `NOTIFICATION_WORKER_ACTIVATION_GUIDE.md` §3). Recommended: hold all of it.

**Gate D:** you approve the fence rule. Note: the fence value must be re-read immediately before Phase F.

### Rollback for Phase D
Nothing to roll back; the script writes nothing.

## 6. Phase E: schema 16 (approval 3)

Follow `schema-16/PRISM_SCHEMA_16_MIGRATION_GUIDE.md`. In short:

1. Backup (A.3) taken again immediately before, in a quiet window.
2. **Option A (preferred; no SSH; exact PHP verifiers)**: one-shot cron jobs for `preflight`, `v16 dry-run`, `v16 apply`, `settings dry-run`, `settings apply`, `preflight`. Check each receipt before scheduling the next.
3. **Option B (manual SQL)**: `PRISM_SCHEMA_16_VERIFY.sql` (expect `NOT_MIGRATED`), `PRISM_SCHEMA_15_TO_16.sql` (expect `MIGRATED_TO_16`), `PRISM_SCHEMA_16_VERIFY.sql` (expect `PASS`), then the `preflight` step.
4. Sign in as an RPMS Administrator, open **Settings** and save one harmless value to confirm the application accepts the schema.
5. Existing permanent-deletion verification evidence becomes invalid after the migration; reissue it with the reviewed `deletion-verification` step if permanent deletion is needed.

**Stop conditions:** any `PRISM ABORT`, `FAIL`, non-zero receipt exit, or `contract` other than `PASS`. Keep the output and do not retry blindly.

### Rollback for Phase E: read this before approving
| Situation | What to do |
| --- | --- |
| Script stopped before stamping (version still 15, extra tables) | Run `PRISM_SCHEMA_16_VERIFY.sql`, then `PRISM_SCHEMA_16_ROLLBACK.sql`. It removes only empty tables. Rehearsed on three server and collation combinations |
| Migrated to 16, **no Settings data or Settings audit exists yet** | Run `PRISM_SCHEMA_16_ROLLBACK.sql`, confirm schema 15, **then** redeploy the old files if needed |
| Migrated to 16, Settings **in use** | Rollback is refused by design. Do not delete Settings data. Fix forward with the new code, or restore the hPanel backup with the old files and accept losing everything written since the backup |
| Anyone proposes to roll back the **code only** after migration | **Do not.** The pre-reconciliation code rejects schema 16 (`config.php` `migrate()` throws; its preflight accepts only 10 to 15). The site would be down |

Rollback never reverts the historical catalog rows (`academic_years` rows with `historical = 1`) that the migration adds for existing Student assignments. They describe existing data and are harmless.

## 7. Phase F: notification worker (approval 4)

Prerequisites: Phase C complete and stable; Phase D done; you have decided the mail channel (Gmail credentials, or PHP `mail()`), and whether `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` stays `false` (the recommended and default value).

1. Re-run the review SQL §0 and §9; note `max_notification_id`.
2. Edit `config.local.php` (not deployed by anything):
   ```php
   define('PRISM_NOTIFICATION_WORKER_ENABLED', true);
   define('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID', <max_notification_id>); // an integer, unquoted
   ```
3. Create or fix the hPanel cron job: `php <path>/maintenance/notification-dispatch.php --limit=25 --seconds=50`.
4. Read the first run's single JSON line: `releaseAfterId` equals your fence, `heldBacklog` matches the review, and only post-fence rows moved.
5. Re-run review SQL §1 and §2.

### Rollback / switch-off for Phase F
Set `PRISM_NOTIFICATION_WORKER_ENABLED` to `false` (or delete the line). The next run prints `disabled`. A run in progress finishes its bounded batch (at most 25 rows and 50 seconds).

Messages already accepted by Gmail cannot be recalled. Rows marked `uncertain` need a person to check the Gmail "Sent" folder; they are never retried automatically.

## 8. Why a normal code deployment cannot dispatch the queue

- `config.php` defines `PRISM_NOTIFICATION_WORKER_ENABLED = false` and `PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID = null` unless `config.local.php` defined them first. Nothing reads these from the environment.
- The only caller of `notification_worker_run()` is `maintenance/notification-dispatch.php`. No web request, no cron-runner step and no release script calls it.
- While disabled, that script prints `disabled`, exits 0 and does not connect to the database.
- Once enabled, it refuses to run without an integer fence, on schemas other than 15 or 16, or when the reviewed database contract does not verify.
- Rows at or below the fence are never delivered, retried, recovered or cancelled. The campaign-completion repair also skips campaigns made only of such rows.
- The web application still sends notifications for **new** immediate events during the request, exactly as before. That behaviour is not gated by the worker switch.

## 9. Post-deployment evidence to keep

- The artifact report (`report.json`) and its SHA-256.
- Receipts from Phase A.4 and each migration step.
- The review SQL result grids.
- The smoke-test table with dates and the person who ran each check.
- The `.htaccess` check on the production host. The Apache test verified the rules on Apache; **LiteSpeed was not verified**. After Phase C, request a few denied URLs (for example `/maintenance/runtime-preflight.php`, `/tests/`, `/PRISM_V14_FRONTEND_RECONCILIATION/` and `/config.php`) and confirm each returns 403 or 404.
