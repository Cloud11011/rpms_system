# PRISM Item 8 final remediation report

Completed 2026-10-09. **APPROVE ITEM 8 FOR COMMIT WITH PRODUCTION CONDITIONS.** No commit, staging, push, merge, tag, deployment, or installed-database migration was performed. Hard deletion remains disabled by default. This report supersedes the Item 8 implementation/verification claims in the earlier UI/account lifecycle report; Items 1–7 were preserved and regression-tested.

## 1. Branch, HEAD, and working-tree status

Branch: `prism-v2-final-v8`. HEAD: `d4b9ac6fb3d1ef2f8d275ece9ea08fda84b5214c`, unchanged from the supplied baseline. No changes are staged. Changes remain local and unstaged. The complete porcelain status is retained in `tests/lifecycle-results/remediation-status.txt`.

The protected manual SQL file was already modified, and the two protected reconciliation artifacts were already untracked when remediation started. Those pre-existing states were preserved; their bytes match the starting hashes below.

## 2. Exact remediation files

Application code and verification:

```text
.gitignore
account_lifecycle_api.php
advisers_api.php
config.php
ierb_api.php
includes/account_identity.php
includes/account_lifecycle.php
includes/account_lifecycle_schema.json
includes/account_lifecycle_schema.php
includes/notification_delivery.php
includes/student_snapshot.php
notifications_api.php
profile_api.php
reports_api.php
students_api.php
workflow.php
tools/verify-account-lifecycle-schema.php
```

Tests and documentation:

```text
tests/account-lifecycle-http.php
tests/account-lifecycle-mysql.php
tests/account-lifecycle-remediation.php
tests/account-lifecycle-runner.php
tests/account-lifecycle-worker.php
tests/alignment-audit.php
tests/archive-operational-audit.php
tests/crud-audit.php
tests/document-workflow-audit.php
tests/notification-delivery-audit.php
tests/report-format-audit.php
tests/research-group-audit.php
tests/schema-v8-mysql.php
tests/ui-audit.cjs
ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md
PRISM_ITEM8_REMEDIATION_REPORT.md
```

Several lifecycle files were already untracked from Item 8 and were updated in place. Notification/research-group fixtures previously excluded by the broad test ignore rule are now explicitly reviewable, as are the new remediation/HTTP fixtures. Older CRUD/format mocks remain limited to their original validation/format scopes; real ownership, provenance, rollback, and locking are tested against MariaDB and actual endpoints.

## 3. Git diff --stat

The full tracked working-tree diff against HEAD, including the earlier Items 1–7 work, is **32 files changed, 369 insertions, 61 deletions**. Standard `git diff --stat` excludes untracked files, including the lifecycle helpers, manifest, new integration tests, and reports. Exact output is retained in `tests/lifecycle-results/remediation-diff-stat.txt`:

```text
 .gitignore                          |  7 ++++
 account.php                         | 18 ++++++++
 admin_people.php                    | 14 +++++++
 advisers_api.php                    | 20 ++++-----
 assets/css/admin-management.css     | 13 ++++++
 assets/css/ierbprog.css              |  2 +-
 assets/css/prism-workspace.css      | 17 ++++++++
 assets/css/research-resources.css   |  5 +++
 assets/css/student-dashboard.css    |  8 ++++
 assets/js/account.js                | 18 ++++++++
 assets/js/admin-management.js       | 53 ++++++++++++++++++++----
 assets/js/role-portal.js            | 12 ++++--
 config.php                          |  8 +++-
 ierb_api.php                        |  5 +++
 includes/ceu_footer.php             |  2 +-
 includes/notification_delivery.php  | 27 +++++++++---
 includes/prism-navigation.php       |  4 +-
 includes/research_resources.php     |  6 ++-
 notifications_api.php               |  7 ++--
 profile_api.php                     | 19 +++++++--
 reports_api.php                     | 29 ++++++++++---
 role_portal.php                     | 10 ++---
 students_api.php                    |  7 +++-
 tests/alignment-audit.php           |  8 +++-
 tests/archive-operational-audit.php |  1 +
 tests/crud-audit.php                 | 10 +++++
 tests/document-workflow-audit.php   |  5 ++-
 tests/report-format-audit.php       |  4 ++
 tests/schema-v8-mysql.php            |  1 +
 tests/ui-audit.cjs                  | 82 +++++++++++++++++++++++++++++++++++--
 tools/schema-v8-manual.sql          |  4 +-
 workflow.php                        |  4 +-
 32 files changed, 369 insertions(+), 61 deletions(-)
```

The manual SQL entry above is a pre-existing diff, not a remediation edit. No Items 1–7 product/UI files were redesigned during remediation.

## 4. R1: Adviser notification dependencies

Deletion checks structured `recipient_type='adviser'` plus the immutable Adviser `recipient_id`, regardless of stale/missing email/name snapshots. Current attribution remains protected. Legacy notifications without a usable ID must resolve to exactly one current Adviser through all provided identity fields; unresolved or conflicting attribution blocks deletion. Identity changes themselves remain archive-only, protecting old-name/email attribution without relying on a later best-effort audit.

MariaDB fixtures prove 409 for ID-only, stale snapshots with the correct ID, attributable legacy identity, and ambiguous legacy identity. Structured and uniquely attributable legacy notifications belonging to another Adviser remain preserved and do not block the target's deletion.

## 5. R2: durable atomic provenance

New Student/Adviser creation records a mandatory creation fingerprint and confirmed login ID in `activity_logs` within the creation transaction. Existing identity edits capture the locked entity/login relationship and insert mandatory change evidence before commit. Evidence uses `entity_type` and the immutable database primary key; changed fields store only minimal old/new IDs, names, emails, and login relationships. No passwords, password hashes, reset tokens, API credentials, or document bodies are recorded.

Student save, IERB save, Adviser save, and Student/Adviser profile-name updates use this protocol. If evidence cannot be inserted, entity/login changes roll back. Unrelated operational audit remains best-effort.

Deletion requires one trustworthy unchanged creation marker matching the current canonical identity. Any identity edit, missing legacy baseline, inconsistent login relationship, or protected profile history blocks deletion and directs the Admin to Archive. Existing records are never given retrospective creation provenance.

Tests cover real HTTP creation and edits for Student/IERB/Adviser, Student/Adviser profile updates, actual denied provenance INSERT rollback, retained old/new values, renamed identities with old-name document attribution, and legacy accounts without sufficient provenance.

## 6. R3: final persistence and concurrency

Notification persistence locks Adviser recipients first where present, then Student primary keys in numeric order. It revalidates existence, archive/active state, captured identity, and available group/adviser scope before INSERT. Stale recipients are skipped. Single-recipient workflow notifications use the same final guard. Delivery occurs after an owned transaction commits; a caller-owned transaction queues delivery for the worker and never calls a provider while holding its locks. Existing production workflow calls continue to commit business changes before notification delivery.

Report PDF/AI generation stays outside the final database transaction. Final persistence locks the captured Student IDs in numeric order, checks every captured Student field except the derived Adviser display name, and checks captured Student-report history. Missing, archived, reassigned, or materially changed Student data aborts with 409. Only the newly generated unsuccessful report file is discarded; lifecycle never removes uploaded files or historical report files. AI privacy boundaries are unchanged.

The actual disposable MariaDB default was queried and verified as REPEATABLE READ. Races explicitly run under **READ COMMITTED and REPEATABLE READ**, without depending on implicit isolation defaults. For both notification and report writers, capture is paused before persistence while Archive or Archive-then-delete completes: no late notification/report row is inserted. In the reverse order, the writer pauses with its Student lock held, a separate Archive worker's pending locking SELECT is observed in the server process list, then the writer commits. Archive completes, and permanent deletion conflicts with the preserved output. Both winner orders retain integrity.

Hard deletion locks the target entity before checking dependencies. Ordered Admin primary-key locking avoids a locking role scan across unrelated logins. There is no provider call inside these persistence transactions.

## 7. R4: complete verification and qualified topology

Read-only metadata verification against the private MariaDB 10.4.32 instance established **15 InnoDB tables, 150 columns, 37 index components, 10 foreign keys, zero triggers, schema version 8**. The reviewed manifest includes column defaults, prefix/type information for indexes, and fully qualified FK table/column/constraint identities and UPDATE/DELETE rules. Current-database schema fields use a portable local marker; external schema names retain distinct prefixed identities, avoiding same-name or marker-name collisions.

A separately authorized CLI verifier requires direct global SELECT and TRIGGER visibility before claiming completeness. Schema-scoped/incompletely recognized grants are refused. It reads the actual selected deployment database in a read-only transaction and emits an approval bound to the exact manifest and database/server identity. Ordinary successful INFORMATION_SCHEMA access is never treated as approval.

Tests cover privileged and restricted verification through both helpers and the actual CLI, injected metadata permission denial, unverified/mismatched approval, version/table/column/index/FK/trigger drift, and external incoming RESTRICT/CASCADE/SET NULL references. The same-name cross-schema FK substitution preserves the exact reviewed index list and explicitly proves rejection because **foreign_keys** differ. No production schema was changed; all hostile DDL fixtures lived inside the disposable instance.

## 8. Exact production gate

Defaults in `config.php` are:

```php
PRISM_HARD_DELETE_SCHEMA_VERIFIED = false
PRISM_HARD_DELETE_VERIFICATION = []
```

Setting the boolean alone cannot enable deletion. After authorized verification of the actual deployment, protected `config.local.php` must contain both exact definitions emitted by:

```text
php tools/verify-account-lifecycle-schema.php --verify --schema-changes-excluded
```

The approval includes manifest SHA-256, database name, server hostname/port/server ID/version, `complete_visibility=true`, and `schema_changes_excluded=true`. Current manifest SHA-256: `d6f1639d702d6562fe430009bed5b92a06385c3b619438df56282718d353b22e`.

Every hard-delete request checks approval/binding and compares current visible metadata. Hard deletion also holds the existing `prism_migrate` advisory mutex until commit/rollback; application migrations already use that mutex. This is not a global DDL lock. Operators must exclude concurrent DDL, including external-schema references hidden from a limited web account, throughout the enabled period; disable/reverify before and after maintenance. If complete authorized verification is unavailable, permanent deletion stays disabled and Archive remains available.

The complete operator procedure is in `ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md`. Installed application configuration was not enabled or deployed by this task.

## 9. R5: archive ownership and token invalidation

Archive centralizes ownership resolution across explicit `user_id`, role, username, `ref_id`, current email, and recorded immutable provenance. Candidate login rows are locked and exactly one owned login is required; other entity claims or conflicting identities return 409 and roll back. A valid explicit UID works even when the login's email is stale. Email-only guesses do not establish ownership.

The confirmed login becomes Inactive and every outstanding reset/setup token for that UID becomes used in the same transaction. Student/Adviser records, assignments, documents, histories, and attribution remain intact. Tests cover both roles with aligned identity, explicit UID/stale email, username/ref fallback, conflicting two-login legacy identity, reset tokens, and setup tokens. Existing `action=delete` routes still mean Archive/Deactivate.

## 10. R6: response codes

| Code | Outcome | Evidence |
|---|---|---|
| 400 | Malformed JSON/non-object request | Actual HTTP and parser tests |
| 403 | Wrong role, cross-origin request, another Admin target | Actual production guards and helper tests |
| 404 | Lifecycle target missing | Actual HTTP; stale edit after delete |
| 405 | Unsupported method; Allow: POST | Actual GET/PUT requests |
| 409 | State, ownership, dependency, schema, last-Admin conflict | MariaDB/HTTP/concurrency tests |
| 422 | Wrong password/typed confirmation or validation | Actual HTTP and helper tests |
| 500 | Unexpected/internal/persistence error | Actual denied audit INSERT; sanitized response |
| 503 | Retryable runtime/database unavailability | Actual row-lock timeout; retryable SQL error mapping |

The existing authentication guard retains 401 for a missing/invalidated session. Error responses do not expose SQL, credentials, stack traces, or internal diagnostics. Front-end handling remains compatible.

## 11. Mixed last-Admin race

Admin A self-archive versus Admin B self-delete returned one 200 and one 409 under each tested isolation level, leaving exactly one active Admin. Existing archive/archive and delete/delete races also passed. Password validation uses the locked current Admin row. Self-only authorization and the final active-Admin check execute within the same transaction and shared ordered lock set.

## 12. Actual endpoint security coverage

A temporary application copy and private PHP HTTP server use only the verified disposable database. Actual `api_require_login()`, `require_post_same_origin()`, login/session fingerprint handling, and cookie expiration are exercised without worker guard replacements. Tests include missing session, Student/Adviser role denial, GET/PUT, missing/hostile Origin, malformed JSON, validation, missing target, default-disabled gate, self-only Admin scope, successful self-archive/delete, cookie expiration, and rejected replay of the previous session.

Actual creation/edit/profile endpoints also verify provenance. External mail/provider functions are disabled in this HTTP fixture. No installed application data or credentials are used. CLI concurrency workers deliberately preserve a captured actor/request to test state changing after authentication; those worker stubs are not presented as HTTP guard evidence.

## 13. Mandatory audit permission failures

A real restricted MariaDB account can read/update/delete fixture data and insert ordinary data but cannot INSERT into `activity_logs`. Student permanent delete, Adviser permanent delete, Admin self-archive, and Admin self-delete each fail and preserve every captured entity/login/history/token/audit row. The same four failures run through actual HTTP endpoints and return sanitized 500 responses. Mandatory identity/provenance failures also roll back the actual Student, IERB, Adviser, and profile edits. Earlier injected audit/dependency failures remain covered.

## 14. Password and reactivation races

A lifecycle worker authenticates/captures the old Admin password while another transaction changes the locked password. The lifecycle request waits, then rejects the stale password with 422 and retains the Student. Student/Adviser reactivation committed while deletion waits on their row causes 409 and preserves the now-active entity, under both tested isolation levels.

Conversely, permanent deletion wins after stale edit/password requests are captured: Student/Adviser save receives 404, Admin password update receives 409, and no account is recreated. The existing Admin password endpoint uses its conditional previous-hash update. Student has no new restore endpoint; its synthetic reactivation race uses only isolated data to exercise the existing locked state contract.

## 15. Final regression totals

| Suite | Final result |
|---|---|
| Full existing CLI regression | 27/27 suites; 5,108 checks |
| Combined MariaDB lifecycle, remediation, concurrency, real HTTP | 769 assertions: 318 existing + 451 remediation |
| Existing disposable v8 integration | 221 assertions |
| HTTPS reset/setup browser | 34 checks; zero failures |
| Focused lifecycle/Items 1–7 UI | 890 checks; zero failures |
| All-role shell/typography UI | 2,640 checks; zero failures |
| Full isolated UI audit | 6,981 checks; zero failures |
| Additional visual/font-loading shell audit | 4,834 checks; zero failures |

Required-gate total: **16,643** checks/assertions. Including the additional visual suite: **21,477**. Counts overlap across suites and are not distinct scenarios. Targeted CRUD and report alignment checks passed again after the final authorization/provider assertions.

The full CLI includes password-reset/setup/auth/session, notification, report/AI privacy/format, document workflow/summary, deadline, archive, and CSV/export regression. Items 1–7 retain the centered 26×26 day highlight, accessible icon actions, cleaned microcopy/calendar headings, Contact Us footer, full branding phrase, Adviser chart clipping fix, and IERB resource.

Initial test failures were corrected before these final passing runs: old mocks lacked new helper/transaction contracts; the disposable FK rebuild renamed its supporting index; a stale-edit worker lacked its stage constant. Browser sandbox debugging failed and isolated browser runs used approved execution outside the sandbox. The extra font-loading suite initially ran in the mode that intentionally blocks fonts, then passed with `--visual-only`. The full UI harness now ignores only the exact canceled Fetch interception ID during navigation; application/CSP/other errors still fail.

Evidence: `tests/lifecycle-results/remediation-lifecycle.log`, `integration-results.json`, `metadata.json`, `remediation-final-results.json`, `remediation-syntax.json`; `tests/final-regression-results/cli-results.json` and per-suite logs; `tests/release-candidate-results/typography-ui.json`; HTTPS reset browser results. Required browser totals were also captured from their completed tool runs.

Reproduction:

```text
python tests/run-final-regression.py
C:\xampp\php\php.exe tests/account-lifecycle-runner.php --lifecycle
C:\xampp\php\php.exe tests/password-reset-migration-mysql.php --isolated-v8
node tests/password-reset-browser.cjs
node tests/ui-audit.cjs --lifecycle-ui-only
node tests/ui-audit.cjs --release-typography-only
node tests/ui-audit.cjs
node tests/ui-audit.cjs --shell-polish-only --visual-only
git diff --check
```

## 16. Syntax

**34 changed/new PHP files and four JavaScript files passed syntax checks.** Results list every file in `tests/lifecycle-results/remediation-syntax.json`. No new production JavaScript change was needed for R1–R6; the prior Items 1–8 scripts were rechecked.

## 17. Whitespace and staging checks

`git diff --check` passed. No changes are staged and HEAD remains unchanged. Untracked remediation sources/documentation are included in the review inventory, though standard diff/stat does not include them.

## 18. Protected file hashes

All three were checked again after implementation and match their initial values:

| Protected file | SHA-256 |
|---|---|
| tools/schema-v8-manual.sql | DF99E2A0C8F99E2FBE74EAC08BF6AF5D50FBA270BE738F4D63077E1902AFD403 |
| PRISM_V8_BEFORE_RECONCILE.patch | B139C3C891D9B0BD5BA51731691FAC5D81045364F15014BCA00B258D903AD126 |
| PRISM_V8_BEFORE_RECONCILE_STATUS.txt | 749E1542AE844A9C8CD887E3DBBDA4B6879617223BED1DC3FDDBB40F702BD399 |

All remain unstaged.

## 19. Remaining limitations and production conditions

Hard deletion is intentionally unavailable until the actual deployment receives complete authorized verification. Hosting restrictions must not be bypassed by setting an unsubstantiated flag. The web account's limited visible runtime inventory cannot discover a newly hidden external FK after approval; the documented DDL exclusion and fresh verification are mandatory. The existing migration mutex coordinates application migrations only.

Production must verify/use one of the explicitly tested isolation levels, READ COMMITTED or REPEATABLE READ, and validate any additional Student/reference writer against the parent-lock protocol. Uncoordinated direct SQL/import jobs and other isolation levels are outside this validation. No installed/production database verification or enabling configuration was claimed or performed here.

Legacy and identity-edited Student/Adviser records remain archive-only. Existing report/AI ambiguity may block deletion globally because schema v8 has no report membership table. Ambiguous login ownership requires Admin review before archive can safely deactivate it. These conservative conflicts preserve attribution and do not manufacture history or clean dependencies away.

Caller-owned notification transactions queue through the existing scheduled worker; provider delivery remains best-effort. Failed new report files are discarded where possible, while deletion never touches institutional files. Archive audit for Student/Adviser remains ordinary best-effort; missing Adviser archive evidence prevents later deletion. All permanent-delete evidence and Admin self-archive evidence are mandatory transaction dependencies.

## 20. Final verdict

**APPROVE ITEM 8 FOR COMMIT WITH PRODUCTION CONDITIONS.** R1–R6 and the additional focused review gaps are remediated and the final required regression gate passed. Approval to commit is a review verdict only: nothing was staged or committed, and hard deletion remains default-disabled. Enable it only after the actual deployment/operator prerequisites above are satisfied. Stop for user review.
