# PRISM retention/purge remediation verification

Completed 2026-10-09 in `C:\xampp\htdocs\rpms_system`. This report describes only this remediation. Existing redesign changes and pre-existing review patches remain in the working tree.

## Exact source and test files changed in this remediation

- `advisers_api.php`
- `includes/account_retention.php`
- `tests/account-lifecycle-worker.php`
- `tests/account-retention-concurrency.php`
- `tests/account-retention-extended.php`
- `tests/account-retention-http.php`
- `tests/account-retention-mysql.php`
- `tests/crud-audit.php`
- `PRISM_RETENTION_PURGE_REMEDIATION_REPORT.md` (new verification report).

No other tracked or initially visible untracked file changed relative to the byte-hash baseline taken at the start of this remediation. This includes the existing review patches, protected reports, schema manifests and migration files. UI files were not changed.

Ignored verification artifacts created by this remediation: `tests/retention-remediation-baseline.json`, `tests/retention-remediation-encoding.json`, `tests/retention-remediation-results.json`, `tests/retention-remediation-browser-results.json`, `tests/retention-remediation-regression-results.json`, and `tests/retention-remediation-syntax-results.json`. Disposable database, HTTP application, session and browser fixtures were isolated from installed application configuration.

## Root causes and changes

1. Recovery enforced the current Admin password and acknowledgement, but the exact confirmation phrase existed only in JavaScript. `retention_recover()` now first validates `jobId` as exactly 32 lowercase hexadecimal characters using absolute regular-expression anchors, then requires the string `RECOVER <jobId>` exactly, before reading the journal or performing recovery. Current Admin password verification and `confirmed === true` remain required.
2. Eligibility enforced the grace period for ordinary deletion but did not limit the override to the period it bypasses. `grace_period_override` now refuses accounts whose server-derived seven-day grace period has elapsed and directs the Admin to normal permanent deletion. Archive, Hold and unresolved-workflow gates remain enforced. Rejected overrides create no purge job or override audit and are never converted to another action.
3. Adviser archive compared impact only when `expectedAssignedStudents` was present; the legacy delete route also discarded that field. After locking the Adviser and assigned Students, archive now requires a nonnegative PHP integer and compares it exactly with the locked assignment count. Missing/malformed counts produce validation refusal; stale counts produce conflict refusal, with transaction rollback. The legacy Adviser delete route forwards the count into this same service. Student archive and bulk server-preview count handling remain intact.
4. The reported encoding concern did not exist in actual source. Strict UTF-8 decoding and literal mojibake scans of 161 PHP, JS, CJS, CSS, HTML, JSON and SQL source files found zero invalid encodings and zero suspect sequences. The scan included literal `??` (covering `???`, `???`, `???`), `??`, and replacement-character corruption. Redirected review patches were not used to judge source encoding and were left untouched.

## Exact added and updated test coverage

- `tests/account-retention-mysql.php`: both account roles now test day 6 override success; day 7, day 8 and day 30 override refusal followed by successful normal deletion; no override audit on refusal; day 0 Hold and missing archive timestamp refusal. Existing day 0 success with exact identifier/password/acknowledgement/reason and separate audit remains. Unresolved document states are exercised during the grace period; both roles' Sending notifications and an Adviser's future deadline explicitly block override during grace.
- Adviser service tests reject an omitted count and each of `null`, string `"1"`, float `1.0`, `-1`, `true`, an array and string `"1x"`. Malformed counts preserve assignments and the active profile. Existing stale zero versus actual one and exact-one success assertions remain. Zero-assignment archive rejects omitted count and stale one and succeeds with integer zero. The rearchive fixture now supplies its exact zero impact.
- Recovery service tests exercise both the existing committed/finalization recovery and the existing uncommitted rollback/file-restoration recovery. Each rejects omitted phrase, wrong phrase, extra trailing space, lowercase phrase, wrong current password, false acknowledgement, and job IDs `null`, an array, integer `42`, `../outside`, 32 uppercase `A` characters and 32 lowercase `a` characters followed by a newline. Every refusal verifies unchanged durable job state, exact quarantined bytes and a closed transaction. Existing successful recoveries now supply the exact phrase and retain finalization/file-restoration assertions.
- `tests/account-retention-http.php`: direct HTTP override requests at day 7 and day 8 are refused for both roles, report normal-deletion guidance, create no job and allow normal deletion afterward. Both `account_lifecycle_api.php` archive and legacy `advisers_api.php?action=delete`, with zero and one assigned Student, reject omitted/null/string/negative/boolean/array/stale counts, preserve Adviser/assignment/login state and succeed with the exact integer count.
- Actual HTTP recovery fixtures cover committed and rolled-back journals. Crafted requests with omitted/wrong/trailing-space confirmation, wrong password, false acknowledgement, traversal job ID or array job ID are refused without changing quarantined bytes, account state or job status. Exact phrase/password/acknowledgement requests complete committed recovery or restore rollback bytes and remove the journal. These requests invoke the real PHP API without JavaScript.
- `tests/account-retention-concurrency.php`: under READ COMMITTED and REPEATABLE READ, a fresh zero-impact preview becomes stale while the actual Student save endpoint holds the Adviser lock and assigns a Student. Archive then refuses the stale count, preserving the new assignment, active Adviser and login; a fresh exact-one confirmation succeeds. `tests/account-lifecycle-worker.php` adds an assignment lock gate and executes the actual identity-sync/audit helper declarations without installed configuration.
- `tests/account-retention-extended.php`: existing Adviser bulk archive continues using its server-preview impact and retains stale-preview and exact-count assertions; a subsequent single rearchive now supplies integer zero.
- `tests/crud-audit.php`: valid legacy Adviser delete fixtures now explicitly supply their zero impact. All existing 56 cases and assertions remain; omission bypasses are independently rejected by the service and real HTTP tests above. No assertion was removed or weakened.

## Passing assertions and checks

| Integrated retention group | Assertions |
| --- | ---: |
| Guarded migration/schema | 37 |
| Single account, ownership and retention | 279 |
| File failure/recovery and basic bulk | 137 |
| Extended bulk/provenance/retry/Admin | 37 |
| Concurrency at both isolation levels | 212 |
| Real global/scoped schema verification | 86 |
| Actual isolated HTTP | 267 |
| **Integrated retention total** | **1,055** |

The previous 637-assertion retention run grew by 418 assertions. The integrated run includes the requested recovery, bulk, concurrency and HTTP tests.

- Browser/UI retention: **332 checks, zero failures**, including the existing viewport/theme/role and recovery confirmation checks.
- Existing 16 regression suites: **2,414 checks/cases**, all passing (the suites retain their own assertion/case counting terminology).
- Combined retention, browser and regression checks/cases: **3,801**.
- PHP syntax: **113 files**, all passing.
- JS/CJS syntax: **24 files**, all passing.
- Actual source encoding: **161 files**, zero invalid UTF-8 and zero mojibake hits.
- `git diff --check`: passed; existing Git LF/CRLF notices do not indicate whitespace errors.

Commands: `C:\xampp\php\php.exe tests/account-lifecycle-runner.php --retention`; `node tests/ui-audit.cjs --retention-only`; `C:\xampp\php\php.exe [-d extension=zip] tests/<suite>.php`; `C:\xampp\php\php.exe -l <file>`; `node --check <file>`; `git diff --check`.

The successful final run is recorded in the ignored JSON verification artifacts listed above. During test development, the new successful Student-save fixture exposed missing helper declarations in the isolated test worker; those were supplied from actual source and the complete retention run was rerun successfully. The first regression pass also found ZIP disabled for two container/extraction suites; both passed with the existing installed extension explicitly enabled. No application or PHP installation settings were changed.

| Existing regression suite | Checks/cases |
| --- | ---: |
| `auth-flows` | 48 |
| `crud-audit` | 56 |
| `document-workflow-audit` | 43 |
| `notification-delivery-audit` | 293 |
| `research-group-audit` | 197 |
| `alignment-audit` | 228 |
| `report-format-audit` | 274 |
| `backend-audit` | 87 |
| `hardening-audit` | 114 |
| `calendar-deadlines-audit` | 190 |
| `document-extraction-audit` | 37 |
| `document-summary-audit` | 297 |
| `document-summary-transport-audit` | 41 |
| `archive-operational-audit` | 85 |
| `csv-export-audit` | 93 |
| `academic-migration-audit` | 331 |

## Final Git status

`git status --short` below includes the entire existing working tree, not just the nine remediation source/test/report files listed above. Nothing is staged. HEAD remains `4f4412d08b38977cb85838117f0cf4e70ac96048` on `prism-v2-final-v8`.

```text
 M .gitignore
 M ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md
 M account_lifecycle_api.php
 M admin_people.php
 M advisers_api.php
 M assets/css/admin-management.css
 M assets/js/admin-management.js
 M calendar_deadlines_api.php
 M config.php
 M documents_api.php
 M ierb_api.php
 M includes/account_lifecycle.php
 M includes/account_lifecycle_schema.json
 M includes/notification_delivery.php
 M includes/student_snapshot.php
 M notifications_api.php
 M students_api.php
 M tests/academic-migration-audit.php
 M tests/account-lifecycle-runner.php
 M tests/account-lifecycle-worker.php
 M tests/alignment-audit.php
 M tests/archive-operational-audit.php
 M tests/backend-audit.php
 M tests/calendar-deadlines-audit.php
 M tests/crud-audit.php
 M tests/document-summary-audit.php
 M tests/document-workflow-audit.php
 M tests/ui-audit.cjs
 M tools/schema-v8-manual.sql
 M workflow.php
?? PRISM_AUTH_VISUAL_REFINEMENT_REPORT.md
?? PRISM_HOSTINGER_LIFECYCLE_FIX_REPORT.md
?? PRISM_ITEM8_REMEDIATION_REPORT.md
?? PRISM_RETENTION_PURGE_DESIGN.md
?? PRISM_RETENTION_PURGE_FULL_REVIEW.patch
?? PRISM_RETENTION_PURGE_IMPLEMENTATION_REPORT.md
?? PRISM_RETENTION_PURGE_REMEDIATION_REPORT.md
?? PRISM_RETENTION_PURGE_REVIEW.patch
?? PRISM_UI_ACCOUNT_LIFECYCLE_REVIEW.md
?? PRISM_V8_BEFORE_RECONCILE.patch
?? PRISM_V8_BEFORE_RECONCILE_STATUS.txt
?? assets/js/admin-retention.js
?? includes/account_purge_files.php
?? includes/account_retention.php
?? includes/account_retention_bulk.php
?? tests/account-retention-concurrency.php
?? tests/account-retention-extended.php
?? tests/account-retention-http.php
?? tests/account-retention-migration.php
?? tests/account-retention-mysql.php
?? tests/account-retention-ui.cjs
?? tests/account-retention-upload-worker.php
?? tests/account-retention-verification.php
?? tests/retention-fixture-support.php
?? tools/migrate-retention.php
?? tools/schema-v9-retention-manual.sql
```

No commit, push, merge, tag, deployment, staging, installed DB migration, or production modification was performed. Migration tests ran only on the runner's verified disposable MariaDB instance.
