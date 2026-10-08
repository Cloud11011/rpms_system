# PRISM final post-data-gathering remediation report

Date: 2026-10-08. Verdict: **PASS WITH CONDITIONS**. Confirmed security/reliability issues and agreed UI polish are implemented in the local working tree. Production data/configuration were not loaded or modified. Hosting migration, provider delivery and operator smoke checks remain required before release.

## 1. Starting state

| Item | Verified before implementation |
|---|---|
| Workspace | `C:\xampp\htdocs\rpms_system` |
| Branch | `prism-v2-ui-restyle` |
| HEAD | `0e4da332a586c737b64a5ed508ad867b0a963748` |
| Commit message | `Restyle PRISM role workspaces and shared UI` |
| Clean/dirty | Dirty: only `assets/css/prism-ui.css` and `assets/css/research-resources.css` on arrival |
| Fetch | Completed; upstream two commits ahead, ending at `b93202ead8425424912f32e84417014654d826bf` |
| Upstream difference | The later pagination/resources alignment changes affect the two existing local CSS files; no merge/reset/check-out performed |
| Instructions | No `AGENTS.md` found; no sub-agents used |

The two pre-existing CSS edits were preserved. All requested findings were inspected/classified before production-code edits; the pre-edit matrix remains in local ignored `tests/post-gathering-preflight.md`. The prompt's last-known SHA was not assumed to be the checked-out SHA.

## 2. Finding matrix

Severity describes the original issue, not a claim of an observed exploit. “FIXED” means the local implementation and isolated verification passed. Hosting-specific evidence remains conditional.

| Finding | Pre-edit status -> final status | Severity | Evidence and principal files |
|---|---|---|---|
| P1 reset/setup credentials | CONFIRMED -> FIXED | High | New prefixed SHA-256 digests, bounded expiring legacy lookup, digest-replay rejection, sensitive transport/log suppression, no-referrer. `security.php`, `config.php`, `forgot_password_process.php`, `reset_password.php`, `update_password.php`; hardening/auth/email/private-DB checks |
| P2 password spraying | CONFIRMED -> FIXED | High | Existing limiter now consumes source-IP budget before account lookup; 60/15 minutes alongside existing account throttling. `login_process.php`; auth/rate-limit/hardening suites |
| P3 Student history loss | CONFIRMED -> FIXED | High | Both deletion endpoints archive under a row lock and deactivate login, retaining relationships. Adviser active scopes exclude archived rows; Admin retains historical access. `workflow.php`, `students_api.php`, `ierb_api.php`, scope endpoints and management JS; private-DB preservation checks |
| Adviser routine deletion | CONFIRMED -> FIXED | High | Deactivation retains Adviser record and Student assignments; editing preserves inactive assignment until explicit reassignment. `advisers_api.php`, `assets/js/admin-management.js`; CRUD and browser checks |
| P4 Adviser deadline scope | CONFIRMED -> FIXED | High | Creator assignment, standardized groups and original audience determine scope; another Adviser sharing a group does not gain access. `includes/calendar_deadlines.php`, `calendar_deadlines_api.php`; shared-group/private-DB checks |
| P5 stale Sending | CONFIRMED -> FIXED | Medium | Nullable claim timestamp, atomic claim, 15-minute lease and provider-duration mutex. NULL historical Sending untouched. `config.php`, notification helper, worker; four-worker private-DB race |
| P6 duplicate deadlines | CONFIRMED -> FIXED | Medium | Creator-row serialization and 120-second comparison of title, description, date, target and sorted groups; repeat returns existing ID. Deadline API; 12 concurrent creates yield one deadline/notification |
| P7 synchronous bulk mail | CONFIRMED -> FIXED | Medium | Broadcast records commit before transport; over five recipients queue, deadlines always queue, worker batch 20. Notification API/helper/worker and notification JS; synthetic 26-recipient check |
| P8 Office containers | CONFIRMED -> FIXED | High | Bounded ZIP metadata/structure validation without extraction; renamed/malformed/path-traversal/compression-abuse fixtures rejected. `includes/office_container.php`, `documents_api.php`, configuration checker |
| P9 audit gaps | CONFIRMED -> FIXED | Medium | Structured actor/entity/before/after/details for archive, Adviser save/deactivation, deadline create/cancel, notification batches, report generation/deletion and profile updates; old rows retained. Relevant APIs and `workflow.php` |
| P10 group allocation race | CONFIRMED -> FIXED | Medium | Cohort named lock retained until commit/rollback, transactional counter in existing `schema_meta`; no foreign group list exposed. Group helper, Student/IERB APIs; 24 parallel creates across cohorts/Advisers |
| P11 AI false positives | CONFIRMED -> FIXED | Low | Full names remain checked; skip 1-2-character parts and modal “May” only as given name; meaningful surnames/accent/transliteration remain guarded. `reports_api.php`; privacy/format/hardening suites |
| P12 schema parity | CONFIRMED -> FIXED | Medium | Validate PK/FK/delete rules, v7 group type/binary collation, named secondary BTREE indexes and InnoDB; v8 audience engine/index also checked. `config.php`, guarded migration artifacts; incompatible schema cannot stamp v8 |
| P13 reassignment cancellation | CONFIRMED -> FIXED | Medium | Original recipients saved transactionally; cancellation uses original recipients even after reassignment; repeated cancellation queues nothing. Deadline helper/API/schema; private-DB checks |
| P14 document cleanup | ALREADY FIXED -> ALREADY FIXED | Low | Existing post-commit cleanup failure logging retained. `documents_api.php` |
| P14 report cleanup | CONFIRMED -> FIXED | Low | Delete canonical DB row before unlink; log inaccessible orphan on unlink failure. `reports_api.php`; report tests |
| P15 new password strength | CONFIRMED -> FIXED | Medium | Shared minimum 12 Unicode characters, existing 200-byte maximum retained, matching hints/minimums; existing passwords untouched. `security.php`, registration/reset/profile and password forms |
| P16 baseline HTTP policy | ALREADY FIXED -> ALREADY FIXED | Medium | Existing cache/nosniff/frame/referrer/CSP baseline preserved. `security.php`; security suite |
| P16 production HSTS | CONFIRMED -> FIXED | Low | Conditional production HTTPS/public canonical URL only; defaults defined before header reapplication. `security.php`, `config.php`; production/development/HTTP/loopback/proxy cases |
| P17 maintenance exposure | CONFIRMED -> FIXED | Medium | Browser helpers require development and loopback host/IP; CLI remains; tests/schema audit denied. `.htaccess`, Gmail/integration tools; nine actual isolated-Apache denials |
| UI-1 My Documents upload | CONFIRMED -> FIXED | Low | Existing `data-go="submit"` action opens existing upload form. `role_portal.php`; browser checks |
| UI-2 collapsed branding | CONFIRMED -> FIXED | Low | Existing icon/wordmark tied to canonical `.is-collapsed`; saved/mobile state applied during parse. Navigation partial/sidebar CSS and JS; reload/both-theme checks |
| UI-3 collapse glitch | CONFIRMED -> FIXED | Low | Preserve submenu state, synchronize aria/layout, remove competing layout transitions, stop handled Escape propagation. Sidebar/workspace CSS/JS; eight rapid toggles and responsive/keyboard checks |
| UI-4 hierarchy | CONFIRMED -> FIXED | Low | Scoped Admin headings 13px/650 and submenu 12px/500; explicit override of shared important font size. `assets/css/prism-workspace.css`; computed-style browser assertions |
| UI-5 compact footer | CONFIRMED -> FIXED | Low | Original artwork in two exact cropped lossless strips; desktop row, responsive wrap. Footer partial/CSS and two WebP files |
| UI-6 footer links | CONFIRMED -> FIXED | Low | Underlines removed, pink hover/focus retained. Footer CSS; computed-style checks |
| UI-7 social labels | CONFIRMED -> FIXED | Low | Icons only, original URLs and accessible names retained. Footer partial; browser checks |
| UI-8 account Logout | INTENTIONAL DESIGN -> INTENTIONAL | None | Canonical sidebar Logout preserved; no redundant control added |
| UI-9 theme null safety | CONFIRMED -> FIXED | Low | Optional theme control guarded. `dashboard.php` |
| UI-10 resources selection | CONFIRMED -> FIXED | Low | Hash/path-aware aria-location while keeping authenticated page selected. `assets/js/prism-workspace.js`; browser assertions |
| UI-11 account placement | INTENTIONAL DESIGN -> INTENTIONAL | None | Existing guarded placement retained; broad account/role UI regressions pass |
| UI-12 artwork optimization | CONFIRMED -> FIXED | Low | 2880x1920 original preserved; exact strips 1980x255 each, total 251,272 bytes; no artwork redraw/stretch |
| UI-13 resubmissions | INTENTIONAL DESIGN -> INTENTIONAL | None | Truthful unavailable-data notice retained; no fabricated resubmissions |
| UI-14 unused student.js | STALE -> DEFERRED | Low | No active loader found; optional deletion deferred to keep scope narrow; script left in place |
| UI-15 pagination/resources | INTENTIONAL DESIGN -> INTENTIONAL | None | Existing local CSS alignment preserved; pagination/filter/role checks and restyle checks pass; upstream integration left for human review |
| Stage advancement | INTENTIONAL DESIGN -> INTENTIONAL | None | Existing submission-triggered advancement, approvals/formal submission/Admin Override retained |

## 3. Implementation summary

**Credentials/authentication.** Raw reset/setup tokens and generic fallback logging exposed reusable credentials at rest. New issuers send random raw tokens only to the recipient and store `sha256:` plus the SHA-256 digest, fitting the existing VARCHAR(128); no token-column migration. Legacy links still use their existing expiry/used checks, and only original lowercase 64-hex tokens qualify for legacy lookup. A submitted stored digest cannot authenticate. Setup issuance locks the account and invalidates/inserts within its own transaction before email. Sensitive email does not fall back to body logging and provider error echoes are sanitized. Delivery failure is honestly pending/requested without account enumeration. Shared password policy applies on new selection only; source-IP login budget reuses protected existing rate-limit storage. Files are listed in P1/P2/P15 above; behavior changes are limited to new credentials/abuse handling, with existing forced-change/idle/fingerprint controls retained.

**Record lifecycle/authority.** Hard deletion could cascade history. A single nullable `archived_at` timestamp replaces routine Student deletion without changing IERB `status`. Both Admin entry points use the same transaction/lock/audit helper. User login becomes Inactive; Adviser Student/document/progress/audit/follow-up/notification/group/attention scopes exclude archives, including direct/locked document checks. Admin lists retain archived rows and show an Archived badge. Adviser deactivation keeps its row and relationships; the Student edit select includes the retained inactive assignment to prevent accidental clearing. No auto-archive, restore flow or account reset was added. These changes affect workflow/Student/IERB/Adviser APIs, related scoped endpoints and management JS; schema impact is only the nullable Student timestamp.

**Deadlines.** Group visibility and creator-based notifications diverged; cancellation lost the original audience on reassignment. A small FK-backed original-recipient table captures recipients in the canonical deadline transaction. Adviser deadlines stay with creator/current assignment/group scope; Adviser B does not see A's deadline solely through a shared group. Reassignment removes the old deadline from current Student workflow, while its cancellation still reaches the originally notified active record. Admin retains institution-wide authority. Creator-row locking plus a short duplicate window prevents duplicate concurrent canonical rows and delivery; dates/later legitimate repeats remain valid. All email delivery is queued after canonical commit. Files: deadline API/helper and configuration. Schema impact: one additive recipient table; official deadlines/groups are retained.

**Delivery reliability.** A claim had no crash timestamp and large loops waited on providers inside requests. Add nullable `sending_started_at`; due/stale claims are atomic and share a named DB mutex throughout delivery/final status. Lease is 15 minutes, batch is 20, Sent/fresh Sending/legacy NULL Sending are protected. Broadcast persistence completes transactionally before any provider; large cohorts reuse the existing worker. Small broadcasts still try immediate delivery through the same claim/mutex. No new queue service. A crash after provider acceptance but before local Sent remains an at-least-once ambiguity, documented below. Files: notification API/helper/worker/JS and schema configuration.

**Uploads/audit/allocation/AI/schema/tools.** ZIP MIME alone did not establish DOCX/ODT identity; the new metadata validator requires expected files/ODT mime and caps entries at 4,096, expanded total at 100 MB, individual entry at 40 MB and compression ratio at 200 beyond 1 MB; rejects duplicates, unsafe names and encrypted entries. No extraction; legacy formats retain existing MIME checks. Structured audit additions reuse existing audit storage and retain old entries. Report unlink now follows DB deletion and records cleanup failure. Group suffix allocation is serialized per cohort; counters use existing `schema_meta` and avoid whole-Student-table locks. AI improvement affects local output rejection only; outbound de-identification was already correct. Canonical v7 and v8 schema checks reject incompatible definitions rather than rebuild them. CLI-only exact-target migration and disabled-by-default manual SQL are supplied. Production browser helpers add environment checks, with private paths protected by actual Apache rules.

**UI.** Changes reuse existing branding, shell/state/event handlers and upload form. Saved sidebar state is applied early, submenu state survives collapse and scoped font sizes restore hierarchy. Footer strips preserve every logo pixel within exact crop regions: top `(510,625)-(2490,880)`, bottom `(510,945)-(2490,1200)` from the unchanged 2880x1920 PNG. Lossless WebP sizes are 155,302 and 95,970 bytes. Responsive wrapping preserves aspect ratio. Footer links/social accessibility and existing external destinations remain. No API/auth/schema redesign was introduced for presentation fixes.

**Performance review.** Demonstrated provider-blocking bulk work now queues; synthetic cohorts and real private-DB contention were tested. Deadline locking excludes provider calls. Existing server-side pagination/indexes remain and their suites pass. No live cohort counts, query timings, storage growth or mail.log growth were measured because production data were out of scope. No speculative optimization, storage sweep or automatic unknown-file deletion was performed.

## 4. Data preservation

| Measure for real/collected data during this task | Result |
|---|---|
| Students deleted | 0 |
| IERB history deleted | 0 |
| Documents/versions/formal submissions deleted | 0 |
| Reports deleted | 0 |
| Audit rows removed | 0 |
| Adviser records deleted | 0 |
| Official deadlines deleted | 0 |
| Production DB modified | **No** |
| Production configuration/secrets modified | **No** |
| Production seed/reset executed | **No** |
| Commit/push/merge/deploy | **None** |

Tests created/destroyed only synthetic fixtures and disposable databases. The v8 preservation fixture compares complete existing rows (excluding only the new nullable fields) across users, Students, Advisers, IERB history, document versions including formal-submission metadata, reports, activity logs, deadlines and notifications. Archive retains row counts/relationships and is repeat-safe. Existing institutional PNG, private storage, config.local.php and collected records were not changed by development operations.

## 5. Authorization matrix

| Actor/resource | Result verified with isolated cases |
|---|---|
| Student -> own account/record/documents/versions/notifications/progress | Allowed within existing workflow |
| Student -> foreign Student/document/version/private download | Denied; no foreign filter/action options |
| Student -> relevant Admin deadline | Allowed by current group/global scope |
| Student -> Adviser deadline | Original recipient plus current creator assignment and authorized standardized group required |
| Adviser -> currently assigned active Student/document/progress/review/follow-up | Allowed |
| Adviser -> foreign or archived Student/document | Denied/excluded, including direct and locked actions |
| Adviser -> Student create | Existing self-assignment, Stage 1/On Track, no Protocol Code and PI unset retained; protected existing edit fields retained |
| Adviser -> another Adviser's same-group deadline | Denied; same group does not broaden creator authority |
| Admin -> institution-wide management/historical archives/report/audit/deadlines/override | Retained |
| Inactive account / old credential fingerprint / idle session | Existing invalidation retained |

Evidence: auth/security/CRUD/document/academic/alignment/readiness/final/calendar suites, private MariaDB scope/archive cases and all-role browser fixtures. These are not live-user permission tests.

## 6. AI privacy matrix

The external request body has `model`, `messages` (fixed server-selected system prompt and allowlisted user snapshot, each with `role`/`content`) and `temperature=0.3`. Transport headers include content type, API authorization, fixed app referer and app title. Credentials are transport secrets, never Student identity fields.

| Student-derived outbound field | Representation |
|---|---|
| Scope | Fixed administration-wide scope sentence, without identities |
| `recordCount` | Aggregate number of records |
| `stages` | Allowlisted stage -> aggregate count |
| `statuses` | Allowlisted status -> aggregate count |
| `cases` | At most 60 allowlisted case summaries; payload constrained below 11,000 JSON characters |
| `cases[].caseRef` | Artificial sequential `CASE-0001` reference, unrelated to stored ID |
| `cases[].stage` | Allowlisted stage / Not recorded |
| `cases[].status` | Allowlisted status / Not recorded |
| `cases[].requirementsRecorded` | Boolean presence only |
| `cases[].lastSubmissionDate` | Validated ISO calendar date / null |
| `caseDetailsOmitted` | Boolean indicating truncated case detail |

**Identifiable/free-text Student data crosses the model boundary: No.** Names, database IDs, Student numbers, emails, research titles, research groups, Protocol Codes, PI identity, Adviser names, raw requirements/remarks and document contents stay local. Local report tables can contain authorized identity data; local case-reference replacement happens after external generation. Full-name, surname and accent/transliteration output checks remain. No real OpenRouter request was sent; privacy assertions exercised the actual payload/filter construction with synthetic records and mocked transport/fallback behavior.

## 7. Migration result

Production version was not queried. Disposable v7 -> v8 migration passed, including default CLI refusal, repeat behavior, failed-validation version retention and exact manual SQL default refusal/enabled upgrade/repeat. Earlier reset-key/v6 and v7 migration suites also passed. MariaDB used: 10.4.32.

| Schema aspect | Verified expected result |
|---|---|
| Lifecycle | `students.archived_at DATETIME NULL`; existing rows unarchived |
| Claim lease | `notifications.sending_started_at DATETIME NULL`; existing rows retained |
| Audience PK | `(deadline_id,student_id)` |
| Audience FKs | Deadline -> `calendar_deadlines(id)` CASCADE; Student -> `students(id)` RESTRICT |
| Audience index/engine | BTREE `deadline_recipient_student(student_id,deadline_id)`; InnoDB |
| Deadline PK/FK/indexes | Canonical v7 keys, creator SET NULL, date/status and creator indexes validated |
| Group type/collation | VARCHAR(190), utf8mb4_bin, NOT NULL |
| Group PK/FK/index/engine | `(deadline_id,research_group)`, deadline CASCADE, group/deadline lookup, InnoDB |
| Idempotence | Repeat DDL identical; guarded manual repeat preserves definitions and Student values |
| Incompatible schema | Wrong group collation/missing audience index fail without advancing version |
| Collected records | Complete synthetic existing rows retained; archive preserves history/version/submission counts |

Audience backfill first uses historical original-deadline notifications, then current authorized scope only for deadlines with no recoverable recipients. Historical missing evidence is a limitation, not silently invented past assignments. Full operator instructions and safe parity-remediation guidance are in `PRISM_DEPLOYMENT_V8.md`; no production migration was executed.

## 8. Automated test results

All final successful runs below exited 0 with zero failed checks. Exact commands use the local XAMPP PHP executable; `php` in this table means `C:\xampp\php\php.exe`.

| Command | Checks/suites | Passed | Failed | Exit |
|---|---:|---:|---:|---:|
| `python tests/run-final-regression.py` | 22 suites | 22 | 0 | 0 |
| `php tests/password-reset-migration-mysql.php --isolated-server` | 90 | 90 | 0 | 0 |
| `php tests/password-reset-migration-mysql.php --isolated-v7` | 159 | 159 | 0 | 0 |
| `php tests/password-reset-migration-mysql.php --isolated-v8` | 219 | 219 | 0 | 0 |
| `node tests/ui-audit.cjs` | 6,784 | 6,784 | 0 | 0 |
| `node tests/ui-audit.cjs --restyle-only` | 1,516 | 1,516 | 0 | 0 |
| `node tests/ui-audit.cjs --hardening-only` | 342 | 342 | 0 | 0 |
| `python tests/isolated-apache-denial.py` | 9 paths | 9 | 0 | 0 |
| `python tests/post-gathering-verify.py --syntax-only` | 58 PHP/JS syntax checks | 58 | 0 | 0 |
| `git diff --check` | Whitespace check | Pass | 0 | 0 |

The 22-suite runner executes each following command with `php -d extension=zip tests/<file>`; all have failed=0 and exit=0:

| File | Passed checks |
|---|---:|
| `auth-audit.php` | 16 |
| `auth-flows.php` | 35 |
| `session-idle-audit.php` | 25 |
| `security-audit.php` | 46 |
| `rate-limit-audit.php` | 45 |
| `logout-audit.php` | 28 |
| `crud-audit.php` | 56 |
| `document-workflow-audit.php` | 39 |
| `notification-delivery-audit.php` | 290 |
| `research-group-audit.php` | 197 |
| `academic-audit.php` | 591 |
| `academic-api-audit.php` | 1,343 |
| `alignment-audit.php` | 219 |
| `report-format-audit.php` | 274 |
| `backend-audit.php` | 87 |
| `tonight-polish-audit.php` | 100 |
| `readiness-audit.php` | 426 |
| `email-format-audit.php` | 26 |
| `cache-audit.php` | 139 |
| `final-regression-audit.php` | 242 |
| `hardening-audit.php` | 114 |
| `calendar-deadlines-audit.php` | 182 |

CLI logs/structured results: `tests/final-regression-results/cli-results.json` and per-suite logs. Syntax/diff/HTTP evidence: `tests/post-gathering-results/`. Browser counts are final tool outputs; restyle screenshots remain under local test artifacts. Tests use extracted real helpers/endpoints, SQLite or private MariaDB, synthetic pages/API responses and mocked outbound services. No real Student emails/config/DB/provider calls were used.

Private v8 cases include 12 identical concurrent deadline creates, distinct dates/later repeats, cross-Adviser same-group boundaries, reassignment/cancellation, 24 cohort allocations, four workers racing stale claims, fresh/NULL/Sent protection, 26-recipient queue/20-row processing and archived-history/login retention. Sensitive transport tests ensure failed-provider echoed tokens do not reach logs. Office cases include expected DOCX/ODT structures, missing/incorrect structure, renamed binary/ZIP, malformed ZIP, path traversal and extreme compression; non-ZIP types retain existing MIME/path tests. Synthetic coverage is not a claim of testing every vendor-produced Office file.

Full/restyle fixtures cover both themes and Admin/Adviser/Student pages, responsive layouts, pagination, modals/forms and keyboard behavior. Focused polish adds 1440/1280/1024/768/390/375/320 widths, saved branding, rapid collapse/submenu restoration, computed typography, footer layout/links/social labels, upload navigation, resources hash and retained inactive assignment. Intermediate fixture mismatches, a new assertion quoting error and sandbox browser-launch timeouts were corrected/rerun; they are not final successful results. An actual typography failure found by browser checks was fixed and passed afterward.

## 9. Manual test results

Performed: manual source/schema/diff review, review of scope/lifecycle/token/delivery boundaries, inspection of the original artwork/crop approach and review of test evidence. No interactive live application account, real Gmail/OpenRouter delivery, physical-device test, hosting login or production migration was performed.

Read-only requests against the installed local Apache instance timed out/disconnected and were **inconclusive**, not passing access-control checks. A separate private Apache instance loaded the actual repository `.htaccess` without PHP/application bootstrap; all nine paths returned 403/404 (including a private executable-path probe; no executable upload was created). Hosting Apache/LiteSpeed and existing private-file access still need operator verification. Browser verification above was automated headless Chromium with synthetic data, not a manual device session.

## 10. Changed files

The exact tracked diff and new deliverables are listed in the appendix below. `assets/css/prism-ui.css` and `assets/css/research-resources.css` were already modified on arrival and were preserved; their diff is not attributed to this pass. The original institutional PNG and `config.local.php` remain unchanged.

The repository ignores the entire `tests/` directory. Local test changes and evidence are therefore **not in the normal Git diff**. The human must explicitly decide how to preserve test artifacts when committing; `.gitignore` was not changed to force that decision. Production packaging should exclude test fixtures/results.

Local test files updated: `auth-flows.php`, `crud-audit.php`, `document-workflow-audit.php`, `notification-delivery-audit.php`, `research-group-audit.php`, `alignment-audit.php`, `report-format-audit.php`, `tonight-polish-audit.php`, `academic-api-audit.php`, `calendar-deadlines-audit.php`, `password-reset-migration-mysql.php`, `schema-v7-mysql.php`, `email-format-audit.php`, `security-audit.php`, `ui-audit.cjs`, `run-final-regression.py` (all under `tests/`). New durable local verification sources: `tests/hardening-audit.php`, `tests/hardening-concurrency.php`, `tests/schema-v8-mysql.php`, `tests/post-gathering-verify.py`, `tests/isolated-apache-denial.py`, `tests/generate-manual-v8.py`, `tests/post-gathering-preflight.md`. One-time edit/fixture helpers and generated results are inventoried below as local artifacts; none is a production dependency.

## 11. Diff

Exact final `git diff --stat` and `git diff --check` results are appended below. Git's tracked stat excludes the new WebP/container/migration/report files and ignored test sources. Local changes remain uncommitted.

## 12. Remaining issues

| Severity | Condition/deferred item | Reason and mitigation |
|---|---|---|
| High release gate | Production backup, staging rehearsal, canonical-v7/v8 validation and migration | Production mutation is explicitly out of scope; operator must follow the guarded deployment instructions before enabling new code |
| Medium release gate | Actual hosting HTTPS, cookie/session/storage/HTTP-denial rules | Isolated rules pass; actual hosting was not available; perform listed header/private-path checks and exclude helper/test files |
| Medium release gate | Real Gmail and cron delivery | Providers mocked; queues require working worker/credentials; verify controlled setup/reset/review/submission/deadline/bulk messages |
| Medium | Crash between provider acceptance and Sent commit | No cross-provider DB transaction; duplicate email remains possible after this crash; mutex prevents concurrent sends, inspect ambiguous records rather than claiming exactly-once |
| Medium | Historical Sending with NULL claim time | Safe retry cannot be inferred; leave untouched until per-record provider inspection, then explicitly requeue only verified unsent items |
| Medium | Old deadlines without original notification evidence | Past audience cannot be reconstructed completely; current authorized baseline used only when none is recoverable, review historical targets before cancellation |
| Low | Historical mail logs may contain old links | Existing logs were not inspected/scrubbed; protect access and honor retention, existing tokens expire naturally, new credential messages never log bodies |
| Low | AI model/provider verification | Exact current configuration not loaded; human should choose intended explicit model, verify controlled generation and local no-provider fallback |
| Low | Inactive Adviser operational reassignment | Historical assignments deliberately retained; Admin must explicitly assign active Students to another Adviser |
| Low | Optional archive restore / dead student.js cleanup | No restore workflow or unrelated deletion requested; historical Admin access retained and inactive records remain preserved |
| Low | Filesystem orphan reconciliation/log growth | Cross-store atomicity impossible; canonical DB-first deletion logs failure, private files remain inaccessible; no unsafe automatic sweep added, monitor storage/logs operationally |
| Low review gate | Two fetched upstream UI commits and existing dirty CSS | No merge authorized; review/reconcile before final commit/tag; existing local pagination/resources styling and regressions preserved |

No unresolved confirmed local regression remains in the final successful checks. “PASS WITH CONDITIONS” does not mean production integration has been demonstrated.

## 13. Deployment instructions

Use [`PRISM_DEPLOYMENT_V8.md`](PRISM_DEPLOYMENT_V8.md). It contains backup/staging/maintenance order, hosting prerequisites, canonical parity checks and safe remediation guidance, exact guarded CLI command, phpMyAdmin-only additive migration with separate version validation, data comparisons, configuration/header/private-path checks, cron/batch/lease handling, controlled provider/role/workflow/UI smoke tests and release/recovery instructions. Nothing has been deployed.

## 14. Final verdict

**PASS WITH CONDITIONS**: local remediation and automated regression/security/UI/migration verification pass. The human must review the diff, preserve/reconcile the starting local CSS/upstream changes, verify backups/staging, apply v8 through the explicit guarded route and complete hosting/Gmail/OpenRouter/cron smoke checks before release. No commits, pushes, merges, live database changes or deployments were performed.

## Appendix: exact local files and diff evidence

Final inventory and command output follow.

### Tracked files with content changes

- `.htaccess`
- `account.php`
- `advisers_api.php`
- `assets/css/ceu-footer.css`
- `assets/css/dashboard-sidebar.css`
- `assets/css/prism-ui.css`
- `assets/css/prism-workspace.css`
- `assets/css/research-resources.css`
- `assets/js/admin-management.js`
- `assets/js/admin-notifications.js`
- `assets/js/dashboard-sidebar.js`
- `assets/js/ierbprog.js`
- `assets/js/prism-workspace.js`
- `audit_api.php`
- `calendar_deadlines_api.php`
- `change_password_required.php`
- `config.php`
- `dashboard.php`
- `documents_api.php`
- `forgot_password_process.php`
- `ierb_api.php`
- `includes/calendar_deadlines.php`
- `includes/ceu_footer.php`
- `includes/notification_delivery.php`
- `includes/pagination.php`
- `includes/prism-navigation.php`
- `includes/research_groups.php`
- `login_process.php`
- `notifications_api.php`
- `profile_api.php`
- `register.php`
- `register_process.php`
- `reports_api.php`
- `reset_password.php`
- `role_portal.php`
- `security.php`
- `send_followup.php`
- `students_api.php`
- `tools/check_configuration.php`
- `tools/gmail_get_refresh_token.php`
- `tools/process_scheduled_notifications.php`
- `tools/test_integrations.php`
- `update_password.php`
- `workflow.php`

### New reviewable deliverables

- `PRISM_DEPLOYMENT_V8.md`
- `PRISM_POST_GATHERING_REPORT.md`
- `assets/images/ceu-accreditations-bottom.webp`
- `assets/images/ceu-accreditations-top.webp`
- `includes/office_container.php`
- `tools/migrate-hardening.php`
- `tools/schema-v8-manual.sql`

### Exact final git status --short

```text
 M .htaccess
 M account.php
 M advisers_api.php
 M assets/css/ceu-footer.css
 M assets/css/dashboard-sidebar.css
 M assets/css/prism-ui.css
 M assets/css/prism-workspace.css
 M assets/css/research-resources.css
 M assets/js/account.js
 M assets/js/admin-management.js
 M assets/js/admin-notifications.js
 M assets/js/dashboard-sidebar.js
 M assets/js/ierbprog.js
 M assets/js/prism-workspace.js
 M assets/js/role-portal.js
 M audit_api.php
 M calendar_deadlines_api.php
 M change_password_required.php
 M config.php
 M dashboard.php
 M documents_api.php
 M forgot_password_process.php
 M ierb_api.php
 M includes/calendar_deadlines.php
 M includes/ceu_footer.php
 M includes/notification_delivery.php
 M includes/pagination.php
 M includes/prism-navigation.php
 M includes/research_groups.php
 M login_process.php
 M notifications_api.php
 M profile_api.php
 M register.php
 M register_process.php
 M reports_api.php
 M reset_password.php
 M role_portal.php
 M security.php
 M send_followup.php
 M students_api.php
 M tools/check_configuration.php
 M tools/gmail_get_refresh_token.php
 M tools/process_scheduled_notifications.php
 M tools/test_integrations.php
 M update_password.php
 M workflow.php
?? PRISM_DEPLOYMENT_V8.md
?? PRISM_POST_GATHERING_REPORT.md
?? assets/images/ceu-accreditations-bottom.webp
?? assets/images/ceu-accreditations-top.webp
?? includes/office_container.php
?? tools/migrate-hardening.php
?? tools/schema-v8-manual.sql
```

`assets/js/account.js` and `assets/js/role-portal.js` still appear in status metadata, but direct byte comparison matches HEAD exactly and both have empty content diffs. Their original bytes were restored; they are not semantic changes.

### Other ignored local artifacts

- `tests/apply-post-gathering.py` (one-time local helper; not shipped)
- `tests/apply-archive.py` (one-time local helper; not shipped)
- `tests/apply-deadlines.py` (one-time local helper; not shipped)
- `tests/apply-delivery-audit.py` (one-time local helper; not shipped)
- `tests/apply-ui.py` (one-time local helper; not shipped)
- `tests/update-hardening-fixtures.py` (one-time local helper; not shipped)
- `tests/update-deadline-fixtures.py` (one-time local helper; not shipped)
- `tests/repair-ui-fixture.py` (one-time local helper; not shipped)
- `tests/finalize-post-gathering-report.py` (one-time local helper; not shipped)
- `tests/final-regression-results/` (per-suite logs and structured final runner evidence)
- `tests/post-gathering-results/` (syntax, diff, status and isolated/inconclusive HTTP evidence)
- `tests/ui-restyle-results/` and browser-generated test screenshots (local review artifacts)

### git diff --stat

```text
 .htaccess                                 |   3 +-
 account.php                               |   6 +-
 advisers_api.php                          |  17 ++---
 assets/css/ceu-footer.css                 |  15 +++--
 assets/css/dashboard-sidebar.css          |   3 +
 assets/css/prism-ui.css                   |   6 +-
 assets/css/prism-workspace.css            |   5 +-
 assets/css/research-resources.css         |   7 +-
 assets/js/admin-management.js             |  18 +++--
 assets/js/admin-notifications.js          |   2 +-
 assets/js/dashboard-sidebar.js            |  12 +++-
 assets/js/ierbprog.js                     |   6 +-
 assets/js/prism-workspace.js              |  11 ++-
 audit_api.php                             |   2 +-
 calendar_deadlines_api.php                |  25 ++++++-
 change_password_required.php              |   6 +-
 config.php                                | 108 ++++++++++++++++++++++++++----
 dashboard.php                             |   2 +-
 documents_api.php                         |  24 ++++---
 forgot_password_process.php               |   8 +--
 ierb_api.php                              |  38 +++--------
 includes/calendar_deadlines.php           |  67 +++++++++---------
 includes/ceu_footer.php                   |  11 +--
 includes/notification_delivery.php        |  16 ++++-
 includes/pagination.php                   |   2 +-
 includes/prism-navigation.php             |  13 +++-
 includes/research_groups.php              |  31 ++++++++-
 login_process.php                         |   5 +-
 notifications_api.php                     |  39 ++++++++---
 profile_api.php                           |   7 +-
 register.php                              |   4 +-
 register_process.php                      |   4 +-
 reports_api.php                           |  16 +++--
 reset_password.php                        |   9 +--
 role_portal.php                           |   4 +-
 security.php                              |  22 +++++-
 send_followup.php                         |   4 +-
 students_api.php                          |  37 +++-------
 tools/check_configuration.php             |   2 +-
 tools/gmail_get_refresh_token.php         |   2 +-
 tools/process_scheduled_notifications.php |  30 ++++++---
 tools/test_integrations.php               |   2 +-
 update_password.php                       |  13 ++--
 workflow.php                              |  27 +++++++-
 44 files changed, 468 insertions(+), 223 deletions(-)
```

### git diff --check

Exit code 0; no output, no whitespace errors.

The stat includes the two pre-existing CSS edits and excludes new/ignored files. Two JavaScript files rewritten only with different line endings during preparation were restored to their original bytes; they have no final content diff. HEAD and branch remain the starting values.
