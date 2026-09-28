# PRISM release checklist

The workspace contains remaining uncommitted hardening and verification files. Earlier fixes are also present in the externally advanced HEAD (05903f0). A release built only from that HEAD would omit the remaining files, including auth_rate_limit.php required by config.php. The assistant did not create a commit or release archive.

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
- Verify Apache denies direct access to private storage, tests, .git, logs, SQL, MD, diff and patch artifacts. Verify equivalent rules if deploying without Apache or without AllowOverride.
- Exercise the four UI workflows on mobile and desktop, light/dark themes, and all authorized roles with real data and external fonts/icons.

## Operational limits

Rate limits use fixed windows and protected file locks on one shared filesystem: registration permits eight attempts per peer IP per 15 minutes; recovery permits 20 per peer IP and five per normalized email per 15 minutes, with the existing five-minute account issuance cooldown retained. Shared proxies/NATs may share quotas. Multiple application hosts require shared atomic storage or a perimeter limiter; unrelated local filesystems do not form a global limit.

Counters expire logically; files remain until an operator performs bounded cleanup with authentication traffic stopped. Do not delete counters while workers could be waiting on their locks. Monitor writable storage and disk usage.

File deletion and database commits are not atomic. Reconcile logged orphan-file cleanup failures. Fixture success is not proof of live database concurrency, production email delivery or deployment readiness.

See AUDIT_REVIEW.md for the exact changes, test results and unresolved design/operational limits.
