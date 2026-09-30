# PRISM release checklist

The UI baseline was committed separately by the project owner as `0ef69bcea2de3783ac2bb6d5dba5fbc899f3afec` (`0ef69bc`), above hardening commit `3394bf34ace411098764bc982c10e8b146fa9e6f`. The current branch `prism-v2-academic-fields` descends from that checkpoint; academic integration remains uncommitted for owner review. See UI_INTEGRATION_REVIEW.md for historical UI results and ACADEMIC_INTEGRATION_REVIEW.md for the academic change inventory and verification. The assistant performed no commit, push, branch switch, live migration, or production deployment.

## Select the reviewed source

Use an explicitly reviewed workspace file inventory, or a later release commit authorized by the project owner. Keep the current working tree intact. Do not restore files from historical diff/patch bundles or follow the old overwrite/delete instructions in CHANGES_README.md.

The new .gitattributes export-ignore rules affect git archive only. They do not filter a manual ZIP, an FTP upload, or a copy of the working directory. Verify the actual deployment manifest.

Exclude secret configuration, .git metadata, runtime storage data/logs/authentication counters, tests, old patch files, and obsolete prototype assets. Preserve the active student.php, role_portal.php, role-portal scripts/styles, and dashboard.css. Include the reviewed PHP/JS/CSS plus security.php and auth_rate_limit.php, and provision required production dependencies through the project's dependency workflow.

The retained student.js, student.css and empty dashboard.js have no current source references. Old diff/patch files are historical artifacts; their contents were not used or opened during this hardening work.

## Configure the deployment

Use APP_ENV=production, a valid public HTTPS APP_BASE_URL, and ALLOW_DEVELOPMENT_PASSWORD_RESPONSE disabled. Configure real setup/recovery email delivery: failed email setup now reports pending access instead of disclosing a production password.

Run tools/check_configuration.php --production on the deployment host. This uses that host's normal application configuration and may initialize its normal storage directories; it does not connect to the database, send email or invoke AI. No configuration values are printed. The assistant has not run this command against private local settings.

Set display_errors=Off and display_startup_errors=Off in the host PHP configuration as well. Application handling protects runtime failures after bootstrap; hosting configuration must also cover failures before bootstrap. Ensure the application can write its protected storage and authentication counter directory.

If a trusted proxy terminates HTTPS, verify its handling of HTTPS/forwarded-protocol headers and prevent clients from overriding trusted proxy metadata. Confirm canonical origins match the real browser requests. Do not trust arbitrary forwarded client-IP headers for throttling.

## Verify before external release

- Confirm migrations, foreign keys and transaction engines against a disposable staging database, then test concurrent upload/review/override/submission/deletion and student/adviser reassignment.
- Test password reset/change races, expired links, inactive accounts, forced changes, multiple sessions and logout cookies with real browsers.
- Test real setup/recovery mail, generic recovery responses under misconfiguration and throttling, notification scheduling, CLI processing, AI failure/fallback and generated report access.
- Verify ordinary-page headers and document-specific CSP, and confirm legitimate forms/AJAX work while cross-site or originless mutation requests fail.
- Verify Apache denies direct access to private storage, includes, tests, .git, logs, SQL, MD, diff and patch artifacts. Verify equivalent rules if deploying without Apache or without AllowOverride.
- Exercise the four UI workflows on mobile and desktop, light/dark themes, and all authorized roles with real data and external fonts/icons.

## Operational limits

Rate limits use fixed windows and protected file locks on one shared filesystem: registration permits eight attempts per peer IP per 15 minutes; recovery permits 20 per peer IP and five per normalized email per 15 minutes, with the existing five-minute account issuance cooldown retained. Shared proxies/NATs may share quotas. Multiple application hosts require shared atomic storage or a perimeter limiter; unrelated local filesystems do not form a global limit.

Counters expire logically; files remain until an operator performs bounded cleanup with authentication traffic stopped. Do not delete counters while workers could be waiting on their locks. Monitor writable storage and disk usage.

File deletion and database commits are not atomic. Reconcile logged orphan-file cleanup failures. Fixture success is not proof of live database concurrency, production email delivery or deployment readiness.

See AUDIT_REVIEW.md for the exact changes, test results and unresolved design/operational limits.

## Academic schema v5 deployment gate

Schema v5 is implemented but has NOT been applied to the local/live database by this work. Pending v4/fresh databases deliberately reject ordinary application DB initialization before any migration DDL. Already-v5 databases need no migration opt-in.

Before any important-database migration, obtain explicit owner authorization, make and verify a database backup, restore a disposable/staging copy, and test the guarded migration there. The intended command is `C:\xampp\php\php.exe tools/migrate-academic.php --apply` with `PRISM_ALLOW_SCHEMA_V5_MIGRATION=1` set only in that approved CLI process. Do not leave this opt-in in routine worker environments. The tool uses the existing application initializer; fresh installs also retain its existing account-seeding behavior. No migration command was run during implementation.

Check all four nullable academic columns and the course width/attributes, compare legacy row counts and course values, verify new/edit/readback through both APIs, repeat migration for idempotence, and exercise retry after a partial DDL failure on disposable data. ALTER TABLE may commit implicitly; rollback is not a backup/recovery plan. Schedule the important-database migration only after staging passes, then verify application behavior and rollback/recovery procedures appropriate to DDL.

The initial Academic Year allowlist is 2025-2026, 2026-2027, 2027-2028, with no automatically active year. Confirm future year additions and official institutional academic-unit labels. Graduate unit mappings and standing/year-level rules remain unresolved and nullable; do not infer them. Reports/PDF academic expansion remains deferred.
