# Student and Adviser retention/purge implementation report

Implemented locally on `prism-v2-final-v8` from starting HEAD `4f4412d08b38977cb85838117f0cf4e70ac96048`. No installed application database or Hostinger/production database was used. Application tests ran in separately initialized private MariaDB datadirs with random loopback ports and credentials; browser tests used isolated templates/mocked APIs. The changes remain unstaged for review.

## Starting source-control evidence

```text
git branch --show-current
prism-v2-final-v8

git rev-parse HEAD
4f4412d08b38977cb85838117f0cf4e70ac96048

git log -3 --oneline
4f4412d Polish shared navigation and workspace UI
bfe5cf3 Add shared-hosting lifecycle verification and deletion UI fixes
390bedb Refine PRISM login and registration styling
```

`git merge-base --is-ancestor bfe5cf3 HEAD` succeeded. Initial `git status --short` contained only the protected modified `tools/schema-v8-manual.sql` and the six named untracked reports/patches. Their SHA-256 values still match the captured initial values. The SQL's existing Git diff belongs to the user and is excluded from this implementation. No capstone paper was changed.

The exhaustive read-only design was written before replacing destructive logic: [PRISM_RETENTION_PURGE_DESIGN.md](PRISM_RETENTION_PURGE_DESIGN.md). The actual dependency matrix below contains separate Student and Adviser policies for every production table, filesystem reference and attribution class. Ambiguous legacy report/AI ownership was stopped specifically and preserved; no unrelated institutional artifact was guessed away.

## Files changed

- `.gitignore`
- `ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md`
- `account_lifecycle_api.php`
- `admin_people.php`
- `advisers_api.php`
- `assets/css/admin-management.css`
- `assets/js/admin-management.js`
- `calendar_deadlines_api.php`
- `config.php`
- `documents_api.php`
- `ierb_api.php`
- `includes/account_lifecycle.php`
- `includes/account_lifecycle_schema.json`
- `includes/notification_delivery.php`
- `includes/student_snapshot.php`
- `notifications_api.php`
- `students_api.php`
- `tests/academic-migration-audit.php`
- `tests/account-lifecycle-runner.php`
- `tests/account-lifecycle-worker.php`
- `tests/alignment-audit.php`
- `tests/archive-operational-audit.php`
- `tests/backend-audit.php`
- `tests/calendar-deadlines-audit.php`
- `tests/crud-audit.php`
- `tests/document-summary-audit.php`
- `tests/document-workflow-audit.php`
- `tests/ui-audit.cjs`
- `workflow.php`

## New files

- `PRISM_RETENTION_PURGE_DESIGN.md`
- `PRISM_RETENTION_PURGE_IMPLEMENTATION_REPORT.md`
- `assets/js/admin-retention.js`
- `includes/account_purge_files.php`
- `includes/account_retention.php`
- `includes/account_retention_bulk.php`
- `tests/account-retention-concurrency.php`
- `tests/account-retention-extended.php`
- `tests/account-retention-http.php`
- `tests/account-retention-migration.php`
- `tests/account-retention-mysql.php`
- `tests/account-retention-ui.cjs`
- `tests/account-retention-upload-worker.php`
- `tests/account-retention-verification.php`
- `tests/retention-fixture-support.php`
- `tools/migrate-retention.php`
- `tools/schema-v9-retention-manual.sql`

Generated ignored test logs/results/screenshots remain test artifacts, not application files. The test ignore exceptions expose the new suites for review; private purge storage is ignored. The protected pre-existing artifacts remain separate and unchanged.

## Schema and migration

Schema version is **9**, upgraded additively from reviewed v8: 16 tables, 179 columns, 55 index components, 10 unchanged FK components, zero triggers. Compared with v8 this adds one table, 29 columns (including ten job columns), and 18 index components. No broad cascades or FK disabling were added. Production purge paths require `@@foreign_key_checks=1` and the exact reviewed manifest.

Both Students/Advisers gain `retention_hold`, `retention_hold_at`, `retention_hold_by`, `retention_hold_reason`, `purge_postponed_at`, `purge_postponed_reason`, and `restored_at`. Advisers gain `archived_at`. Reports gain nullable `owner_student_id`; AI outputs gain nullable `owner_student_id` and `owner_document_id`; official deadlines gain historical `creator_name`. `account_purge_jobs` stores random job ID, role/account ID, former identifier, Admin actor ID, method, status, manifest hash, creation/completion timestamps. No target profile/email/content is copied into this job.

Retention/ownership/recipient/audit and stored-file/version indexes support bounded discovery. Newly generated one-Student `Student Report` ownership is stamped only after locked snapshot validation; a one-member aggregate remains aggregate. Legacy ownership stays NULL. Legacy inactive Advisers without reliable archive dates start at migration time; existing Student dates stay unchanged. Deadline creator names backfill only from their linked live users.

Guarded PHP migration: `PRISM_ALLOW_SCHEMA_V9_MIGRATION=1`, exact `PRISM_MIGRATION_EXPECT_DB`, then `php tools/migrate-retention.php --apply`. Normal web requests never execute pending migration DDL. Earlier explicit migration commands retain their v6/v7/v8 targets. PHP and the separate phpMyAdmin script validate incompatible existing shapes and are retry safe. MariaDB DDL commits implicitly; failures may leave additive columns but do not stamp v9 prematurely. The exact manual SQL was tested against private MariaDB, including default refusal, repeat execution, preserved values and incompatible-column refusal. The manual script requires stored-routine privileges; provider execution is an alternative if unavailable.

The installed database has not been migrated. Reviewed code, v9 schema and manifest must be deployed together under a separately authorized maintenance/backup window, then fresh verifier evidence generated. Migration flags must not stay enabled. [Operator instructions](ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md) retain the Hostinger no-SSH Remote MySQL/File Manager/phpMyAdmin procedure and secret-handling commands.

## Student and Adviser dependency matrix

| ENTITY / TABLE / FILE | REFERENCE | CURRENT FK / RELATIONSHIP | STUDENT POLICY | ADVISER POLICY | PURGE ORDER | FAILURE CONSEQUENCE |
|---|---|---|---|---|---|---|
| students | id, student_id, user_id, adviser_id | user→users SET NULL; adviser→advisers SET NULL | DELETE target only | DETACH adviser_id=NULL, including archived Students; do not delete Students | Child cleanup then profile | Roll back all DB changes |
| advisers | id, employee_id, user_id | user→users SET NULL | PRESERVE | DELETE target after detach | After assignments | Roll back |
| users | user_id, email, username, ref_id, identity provenance | Legacy explicit/attested/literal ownership; no role FK | DELETE uniquely proven login | DELETE uniquely proven login | After profile/tokens/detach | Ambiguous claims block; never guess |
| password_resets | user_id; setup and reset share this table | users CASCADE | DELETE owned tokens | DELETE owned tokens | Before login | Roll back |
| sessions | $_SESSION.user_id and credential_fingerprint | PHP session files; current_user queries live users every request | Inactive/absent login invalidates access | Same | Archive/purge login | In-flight requests still require writer revalidation |
| documents | student_id (all versions), supersedes_id, ai_summary | students SET NULL; supersedes is text, no FK | DELETE all proven owned rows/versions and summaries; BLOCK WHILE ACTIVE current unsubmitted workflow | PRESERVE uploaded_by/reviewed_by/rpms_submitted_by/override_by display text | Before Student | External version edge or ambiguous legacy ownership blocks target |
| storage/documents/* | documents.stored_name | Private basename file path | FILESYSTEM DELETE exclusive files only | PRESERVE Student documents | Quarantine before commit, unlink after commit | Restore before rollback completion or retain recovery journal; never claim completion |
| ierb_history | student_id, actor | students CASCADE; actor historical text | Explicit DELETE owned history | PRESERVE actor text | Before Student | Roll back |
| notifications | recipient_type/id/email/name, created_by | No FK; sender display name only | DELETE proven Student recipients; BLOCK Sending or held delivery mutex | DETACH own recipient id, cancel queued delivery; PRESERVE sender text and other recipients | Before profile | Sending/mutex postpones; mixed legacy recipient ambiguity blocks |
| calendar_deadline_recipients | student_id, deadline_id | students RESTRICT; deadline CASCADE | DELETE Student snapshot membership | PRESERVE other Student evidence | Before Student | Roll back |
| calendar_deadlines | creator_user_id, status, deadline_date | users SET NULL | DETACH creator linkage if owned; PRESERVE institutional deadline | BLOCK future Active authored deadlines; DETACH cancelled/past author; retain display attribution | Before login | Postpone active ownership; never delete deadline |
| calendar_deadline_groups | deadline_id, research_group | deadline CASCADE | PRESERVE | PRESERVE | None | Never remove institutional group audience |
| reports | filename, generated_by_user_id, type | No FK, immutable author authorization | DELETE explicitly proven exclusive owner; PRESERVE aggregate/ambiguous snapshots | DETACH author id; PRESERVE generated_by text | Before Student, files finalized afterward | Shared file blocks; ambiguous legacy Student Report retained for operator provenance review |
| storage/reports/* | reports.filename | Private PDF basename path | FILESYSTEM DELETE only proven exclusive Student report | PRESERVE | Quarantine/commit/finalize | Same durable recovery model |
| ai_outputs | source_ref, created_by, prompt/output | No FK; no current production writer | DELETE explicit exclusive owner/document; PRESERVE aggregate/ambiguous legacy output | PRESERVE historical creator text | Before documents/Student | No undocumented source_ref/name guessing |
| activity_logs | student_id, entity_type/id, user_email, legacy student_saved detail | No FK; identity history includes old email/ID/name fields | DELETE attributable personal/account/document evidence; retain minimal new deletion accountability | DETACH entity id for Adviser/user; PRESERVE historical full name | Before minimal purge audit | Mandatory new audit failure rolls back |
| schema_meta | schema_version, group allocators | Independent | PRESERVE | PRESERVE | None | Manifest mismatch fails purge |
| stage_labels | configured workflow labels | Independent | PRESERVE | PRESERVE | None | Never delete |
| storage/mail.log / api_errors.log | historical shared operational logs | Aggregate log, no exclusive provenance | PRESERVE shared operational artifact; no new target profile logging | PRESERVE | None | Do not rewrite shared logs by guessed ownership |
| new account_purge_jobs / private recovery manifests | random job id, method, actor id, account identifier, safe file hashes | Recovery journal, no live-account FK | PRESERVE minimal accountability; discard file manifest after completion | Same | Atomic with deletion audit; completion after files | Pending job visible and explicitly retried by Admin |


Final implementation additionally preserves recycled old email/identifier evidence when currently claimed by another identity; inconsistent explicit AI ownership blocks instead of deleting cross-account outputs. Late Student-owned workflow audits revalidate the locked surviving Student before writing.

## Account behavior

- **Archive:** always required before any Student/Adviser purge. Student archive preserves dependencies and deactivates its proven login/tokens. Adviser archive transactionally makes every currently assigned Student Unassigned, deactivates the login and writes mandatory audit. Single/bulk confirmation binds and rechecks assignment impact.
- **Restore:** clears archive/Hold/postponement, records restoration and cancels that countdown. A uniquely linked login is required; credentials rotate to invalidate old sessions and a new hashed one-time 24-hour setup token is issued. The Admin copies/delivers the link securely; no automatic email is claimed. Adviser assignments remain Unassigned. Re-archive starts new dates.
- **Grace:** authoritative DB `NOW()` in +08:00; manual purge requires `DATE_ADD(archived_at, INTERVAL 7 DAY)`. Active/day 0/day 6 fail; day 7 succeeds for both roles.
- **Exceptional override:** one archived account, current active Admin password, exact Student/Employee ID, irreversible acknowledgement and a 5?500-character reason. Only grace is bypassed; all Hold/workflow/schema/ownership/audit/file/transaction gates remain. Distinct `student_grace_period_override_purge` / `adviser_grace_period_override_purge` actions and method.
- **Retention:** six calendar months via `DATE_ADD(..., INTERVAL 6 MONTH)`, including month-end clamping. Three-day warning is evaluated on screen reads. The Admin explicitly reviews/runs cleanup, independently for either role. No unattended purge, cron or scheduled notification promise.
- **Hold:** structured state, any Admin can place/remove it on an archived account; audited, blocks all purge methods. Restore clears it as part of reactivation.
- **Unresolved work:** current unsubmitted document (pending review/revision/formal submission), Sending/leased notification, future Active official deadline owned by the target login. These postpone purge for Admin review. Past activity, IERB stage/status and historical documents do not permanently block. The recorded diagnostic is the last review event; live state controls current eligibility. Concurrent writers serialize/revalidate live parent rows.
- **Admin:** separate self-only lifecycle and last-active-Admin protections retained; no Admin bulk/retention/grace policy.

Student purge deletes all proven applicable owned children, versions/summaries, progress/history, recipient snapshots/notices, exclusive AI/reports/files, attributable personal audits and credentials before deleting profile/login. Necessary accountability retains former Student ID, Admin actor, timestamp, method, and operational override reason when applicable. The new deletion audit does not retain Student email/title/full name/profile/document data. Historical institutional aggregates and unprovable legacy artifacts remain under the documented preserve exception.

Adviser purge detaches remaining assignments/live author links, removes credentials/profile/login and keeps historical reviewer/uploader/IERB actor/report author/notification sender names. Own recipient notices become `historical_adviser` with no live ID; queued notices are cancelled and personal lookup excludes them. Historical names/emails cannot relink those notices to a future matching Adviser. Past/cancelled deadlines keep creator display text while clearing live login identity.

Current Student Records, document/IERB progress and Adviser CSV/workload outputs were exercised after purge and exclude removed accounts. Existing dashboard counts, current progress/AI report population and group/workload queries derive from surviving live Student/Adviser rows; historical aggregate artifacts remain unchanged, including prior members.

## Transaction and filesystem outcomes

Common purge lock `prism_migrate` coordinates the existing migration/verifier architecture; account/Admin/login/child locks revalidate actual target and ownership. Each account has its own transaction. Known 1451 dependencies roll back; no unknown dependency is removed. Notification delivery and purge use the same notification mutex, including immediate delivery outside provider transactions. Adviser selection is locked/revalidated before Student assignment. IERB edits and actual upload persistence also check pending recovery.

Preparation verifies safe private regular basenames, non-linked/exclusive references, sizes and SHA-256, then writes/flushed an immutable DB/server-bound random-job manifest. Existing bytes move by same-volume rename into private quarantine while locks remain held. AI/dependency cleanup, profile/login deletion, minimal mandatory audit and job insertion commit together. Precommit failure restores files before releasing locks and rolls back DB state. Postcommit finalization deletes only matching quarantined files, marks the job complete and removes the manifest. `ok=true` is returned only after completion.

Finalization/uncertain commit is reported incomplete with job ID; HTTP 202 distinguishes committed-but-pending. A failed filesystem/audit operation does not claim success. Retry never deletes a newly reused source filename or overwrites a conflicting source. Explicit password-protected Admin recovery finalizes a proven committed job or restores a positively uncommitted exact account/reference set. Disk journals and unfinished DB jobs are listed; a missing/corrupt journal requires operator review and cannot be fabricated into a new file plan. Incomplete recovery blocks that account's lifecycle/writes. No background recovery was added.

## Bulk and UI

Individuals, current page and all matching filters/search are supported for both roles. Server-bound immutable selections reconstruct the same validated scope as list APIs; sorting/pagination are excluded. Duplicate/injected/out-of-scope IDs, invented filters, cross-session use and unsupported actions fail. Material filter/search changes clear selection, including changes while asynchronous selection/preview is resolving. Persistent counts/scope stay visible.

Bulk Archive/Restore/Hold/remove-Hold/manual purge/cleanup use a server preview, current password and exact `APPLY N ACCOUNTS` or `PURGE N ACCOUNTS` phrase plus acknowledgement. Eligible membership/state/count and Adviser assignment impact are recomputed before execution and per account under locks. Even same-count changed membership fails stale confirmation. No bulk grace override exists.

At most 10,000 selected accounts; at most 25 per HTTP chunk, with a ten-second budget checked between targets. Each account commits independently; the UI issues explicit continuation requests and reports completed/skipped/recovery-required outcomes and reasons. Cursor caches handle lost-response retries; mandatory audit markers recover a DB commit before a lost session checkpoint. Even a complete DB job status is reported recovery-required if its disk journal remains; status alone cannot conceal incomplete journal cleanup. Session previews refresh a 15-minute lifetime during progress. Up to twelve preview slots are bounded; incomplete progress is protected from incidental eviction. A safe single-account failure does not undo another commit; a new confirmed preview retries skipped targets. Stale remaining state requires fresh review, never silent eligibility acceptance.

Single destructive controls remain visible with state-specific disabling/explanation. Kept 40px action dimensions, responsive 760px native dialog, stacked labels, eye toggle, focus/keyboard behavior, dark/light themes and long-identity layout. Removed the obsolete test/demo checkbox. Added reason only for override, Restore/Hold controls, selection toolbar, cleanup review and explicit recovery. Passwords clear on failures. Missing-journal jobs remain visible for operator review.

## Verification/security preserved

`includes/account_lifecycle_schema.php` and `tools/verify-account-lifecycle-schema.php` are unchanged. Only the reviewed inventory changes to v9. Explicit `globally_privileged` / `schema_scoped_shared_hosting`, exact manifest/DB/server bindings, live grants/metadata revalidation, `prism_migrate`, 24-hour evidence expiry, schema-change exclusion and fail-closed behavior remain. Scoped evidence always has `complete_visibility=false`, with its explicit external-dependency assurance. No global-to-scoped fallback or boolean bypass was added. Real schema-only users and both actual verifier CLI entry points were exercised privately.

Admin authorization/password/exact targeting, same-origin mutation checks, setup/reset hashing/session fingerprint invalidation, ownership ambiguity refusals, transactional mandatory audit and active-workflow protections remain enforced. Production has not been contacted or enabled. Hidden cross-schema CASCADE/SET NULL and external DDL/writes are still operational exclusions, not claims proven by scoped visibility.

## Validation commands and exact final totals

All passing totals below count one final successful run, not earlier reruns.

| Command / suite | Assertions/checks/cases | Result |
|---|---:|---|
| `C:\xampp\php\php.exe tests/account-lifecycle-runner.php --retention` | **637** | PASS |
| `node tests/ui-audit.cjs --retention-only` | **332** | PASS |
| `php -d extension=zip tests/auth-flows.php` | 48 | PASS |
| `php -d extension=zip tests/crud-audit.php` | 56 | PASS |
| `php -d extension=zip tests/document-workflow-audit.php` | 43 | PASS |
| `php -d extension=zip tests/notification-delivery-audit.php` | 293 | PASS |
| `php -d extension=zip tests/research-group-audit.php` | 197 | PASS |
| `php -d extension=zip tests/alignment-audit.php` | 228 | PASS |
| `php -d extension=zip tests/report-format-audit.php` | 274 | PASS |
| `php -d extension=zip tests/backend-audit.php` | 87 | PASS |
| `php -d extension=zip tests/hardening-audit.php` | 114 | PASS |
| `php -d extension=zip tests/calendar-deadlines-audit.php` | 190 | PASS |
| `php -d extension=zip tests/document-extraction-audit.php` | 37 | PASS |
| `php -d extension=zip tests/document-summary-audit.php` | 297 | PASS |
| `php -d extension=zip tests/document-summary-transport-audit.php` | 41 | PASS |
| `php -d extension=zip tests/archive-operational-audit.php` | 85 | PASS |
| `php -d extension=zip tests/csv-export-audit.php` | 93 | PASS |
| `php -d extension=zip tests/academic-migration-audit.php` | 331 | PASS |

Retained regression total: **2,414** across sixteen suites. The private retention total comprises 37 bootstrap/migration assertions (including exact manual SQL), 181 single-account/data ownership assertions, 41 file failure/recovery/basic bulk assertions, 37 provenance/large-set/partial-crash/bulk/Admin assertions, 190 concurrent checks at both isolation levels, 86 genuine global/scoped verification checks, and 65 actual HTTP checks. All ten requested race categories are covered; late audit recreation and actual multipart upload are also exercised.

Browser checks cover both roles at 1920?1080, 1366?768, 1024?768, 768?1024, 390?844 and 375?812, each light/dark (24 configurations), keyboard checkboxes/selection, pagination/sort/scope changes, 100-character IDs, hostile long names/XSS, grace/Hold/open states, native modal/scroll/password/focus, server phrase/stale confirmation, chunk results, expired gate, non-Admin UI and missing-journal recovery.

Changed/new PHP syntax: **35 files passed**. JS/CJS syntax: **4 files passed**. `git diff --check` passed. Untracked new files were also checked for syntax; protected SQL was never rewritten. Verifier PHP is byte-for-byte unchanged against HEAD; FK manifest is unchanged. Final HEAD/branch/index and protected-artifact hashes are checked separately below.

Legacy regression harnesses were adapted only to new explicit include boundaries, fixture fields, delivery mutex/state and actual v9 version guards; their unrelated security/CRUD/export/workflow assertions remain. A small SQLite-only date-syntax adapter supports legacy read-only fixtures; actual retention authorization/time/concurrency uses MariaDB. No existing regression test was deleted or weakened to force a pass.

The untouched legacy `--lifecycle` runner was also invoked: it verifies its original v8 metadata (15 tables/150 columns/37 index components/10 FKs) then refuses because the new production manifest requires v9. That suite intentionally embeds the obsolete v8/test-demo/history-block policy and is **not a passing acceptance suite for this redesign**. Its refusal is recorded, not hidden or changed to accept purge. New v9 suites explicitly replace those policy assertions while preserving security checks. No claim is made that every old-policy suite passes.

Ignored final regression/syntax JSON logs under `tests/` preserve command output for local review. Browser screenshots/results are disposable isolated test artifacts; they contain synthetic data only.

## Limitations and later paper changes

1. Legacy exclusive Student Report/AI provenance is unprovable and those artifacts remain preserved. Ambiguous legacy documents/recipients, shared files/versions/AI ownership or conflicting logins block the affected account for review. Historical aggregate PDFs/AI and shared operational logs retain their existing historical content by explicit policy.
2. A migrated/reviewed hosting copy and fresh bound verifier evidence are prerequisites; no production migration/verification/deployment was performed. Manual migration needs stored-routine privileges or provider assistance. DDL rollback is not transactional.
3. Tested on XAMPP MariaDB 10.4.32 at READ COMMITTED and REPEATABLE READ. Actual hosting versions, filesystem semantics, permissions, added writers and direct SQL/imports require staging/maintenance coordination. Scoped mode cannot see hidden external dependencies.
4. File preparation caps 2,000 owned document/report records and 256 MiB; bulk caps 10,000 IDs/25 targets per request. The between-account time budget cannot interrupt one slow hash/transaction. A very large/ambiguous account needs a separately reviewed operator plan.
5. Quarantine assumes private same-volume storage. Power loss, missing/corrupt journals, inconsistent DB/storage backups or external filesystem changes can require manual operator recovery. Disk journaling/fsync does not guarantee whole-machine power-loss atomicity. Never delete a journal to bypass refusal.
6. Batch resume depends on the same session/token lifetime; a reload or expired/stale preview can require a fresh confirmation for remaining targets. Lost restore links require a new password reset. No unattended purge/recovery/email warning exists.
7. Historical Adviser attribution is display text with detached IDs, not a reusable live account identity. Restore does not reconstruct assignments. Operational reasons must avoid copying unnecessary personal contents.

Do not edit the capstone paper in this coding task. Later revise account-management/use cases; archive and 7-day/6-month timing; privacy/data minimization and aggregate exceptions; Hold/restore/override policy; workflow/historical Adviser attribution; bulk administration and accessibility; schema/transaction/filesystem design; deployment/Hostinger assurance/recovery operations; evaluation/concurrency/test sections. The operator documentation is already rewritten for the implemented policy.

Final source-control checks: branch and HEAD unchanged; `git diff --cached --name-only` empty; all task changes unstaged; `git diff --check` passed. All seven protected SHA-256 hashes match the initial snapshot.

No commit, push, merge, tag, deployment, or production modification performed.
