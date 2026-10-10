# PRISM final-v9 narrow pre-commit remediation

Completed on 2026-10-09. All four requested fixes are implemented and the requested regressions pass. This report describes changes relative to the working tree at the start of this remediation, which already contained the combined-v9 implementation. Source schema remains 9; no schema, migration, manifest, onboarding architecture, or retention policy was changed.

**Root causes and resulting behavior**

1. Complete Profile omitted its form method/action, so a browser without its submit handler could use GET. The form now explicitly posts to `complete_profile.php`, displays a noscript notice and a persistent loading/failure notice, and starts with submission disabled. The JavaScript enables submission only after mounting the catalog and registering the protected JSON submit handler. Forced native POST and crafted GET only render the page, discard supplied fields, and cannot complete the profile or reflect those fields. There is no alternate server-side completion service. Normal JavaScript still uses the authorized JSON POST service and redirects to the role landing page without profile fields in the URL.
2. Complete-record Student save checked only Adviser status/archive state, while invitation assignment checked completion. The existing `onboarding_active_adviser()` is now the shared authoritative validator for all paths that assign a non-null Adviser. It requires a transaction, locks the Adviser profile and corresponding login, checks existence, Active status, no archive, non-null completion, unambiguous same-role identity ownership, an Active login, and matching email/name/Employee ID/username/ref_id. It rejects malformed IDs before conversion. Student save now returns controlled 422 validation errors and 409 state conflicts. Unassigned remains valid.
3. Ordinary recovery did not distinguish first-time invitation setup from an accepted account. The shared invitation-state check now blocks reset issuance whenever the user's invitation has `accepted_at IS NULL`. It runs under the existing user lock and also locks the invitation. Public Forgot Password confirmation is unchanged for invited, accepted, complete, and unknown addresses. Previously issued reset credentials are also refused by the reset form and consumption endpoint until invitation acceptance. Reset never accepts an invitation. Invitation acceptance retains its existing reset invalidation transaction.
4. The Pending page linked to `change_password_required.php` after invitation acceptance had already cleared `must_change_password`, causing a redirect back to onboarding. The Password security link is removed. Sign out remains available. Pending authorization guards are unchanged.

**Assignment source audit**

| Mutation path | Authoritative validation |
|---|---|
| Admin complete-record Student creation | `students_api.php?action=save` -> shared validator before INSERT |
| Admin complete-record Student edit/reassignment | Same validator before UPDATE |
| Adviser complete-record Student creation/edit | Authenticated Adviser is derived server-side and validated; locked Student ownership is enforced |
| Admin Pending Student assignment | `onboarding_assign()` -> shared validator before UPDATE |
| Admin Student invitation assignment | `onboarding_invite()` -> shared validator before INSERT |
| Adviser-created Student invitation | Authenticated Adviser is derived server-side, validated, and assigned; browser `adviserId` is forbidden |
| Adviser archive/purge | Existing transaction clears assignments to NULL; this does not create an Adviser assignment |
| Adviser deletion FK | Existing ON DELETE SET NULL only clears assignment |
| IERB complete-record creation | Does not supply `adviser_id`; new record is Unassigned |
| Self-completion, registration, profile updates | No Adviser assignment mutation |

Every source path that establishes a non-null Student Adviser assignment uses the shared validator. Null clearing in archive/purge/FK behavior is preserved. This conclusion comes from source mutation review and direct API/race tests, rather than option-list filtering.

**Exact review-visible files changed in this remediation**

Production files (7):

- `complete_profile.php`
- `assets/js/onboarding.js`
- `includes/account_onboarding.php`
- `students_api.php`
- `forgot_password_process.php`
- `reset_password.php`
- `update_password.php`

Existing test/harness files (10):

- `tests/auth-flows.php`
- `tests/crud-audit.php`
- `tests/retention-fixture-support.php`
- `tests/account-lifecycle-worker.php`
- `tests/account-retention-http.php`
- `tests/account-onboarding-http.php`
- `tests/account-onboarding-mysql.php`
- `tests/account-onboarding-concurrency.php`
- `tests/account-onboarding-ui.cjs`
- `tests/ui-audit.cjs`

Other review files (3):

- `.gitignore`: exposes only the new HTTP remediation test.
- `tests/account-onboarding-remediation-http.php`: new private HTTP regression module.
- `PRISM_FINAL_V9_PRECOMMIT_REMEDIATION_REPORT.md`: this new report.

Ignored local verification logs/JSON are under `tests/final-regression-results`; the existing isolated reset browser harness also writes its local JSON under `tests/release-candidate-results`. They are test results, not production verification evidence. Existing design/implementation/review reports, user patches, both SQL migration files, and the schema manifest were preserved against the start-of-remediation SHA-256 baseline. Proper UTF-8 punctuation was retained.

**Tests added and compatibility updates**

- `account-onboarding-remediation-http.php`: 170 additional real private HTTP/DB assertions. Both roles test explicit POST, disabled submit, noscript state, removed password link, discarded native POST/crafted GET fields, no reflection or redirect, no mutation, JSON GET refusal, and successful JSON POST. Four assignment paths each reject Pending, archived, inactive, incomplete, missing-login, inactive-login, wrong-role-login, mismatched-identity, and missing Advisers. Active complete and Unassigned cases succeed with authoritative stored assignments. Array, boolean, malformed string, zero, and oversized IDs are rejected. Both roles test generic recovery for unaccepted/complete/unknown addresses, no unaccepted reset issuance, unchanged invitation, denied pre-existing reset, unchanged password/acceptance, original invitation usability, invalidation of old resets, accepted-Pending recovery and reset consumption, profile completion, and completed-account recovery.
- `auth-flows.php`: four additional cases for unaccepted issuance suppression, accepted-Pending recovery, denial of previously issued reset consumption, and hidden unaccepted reset form. Existing confirmation equality and all prior cases remain.
- `account-onboarding-mysql.php`: eight additional assertions across both roles for first-time setup state, accepted-Pending recovery eligibility, old reset invalidation, and unchanged Pending completion state.
- `account-onboarding-concurrency.php`: 32 additional assertions for Pending assignment/archive and invitation-assignment/archive races at READ COMMITTED and REPEATABLE READ. Controlled outcomes and final login/assignment invariants are checked.
- `account-onboarding-ui.cjs`: 54 additional checks. These cover absent looping links across both roles/six sizes/two themes; successful protected JSON completion for both roles; and disabled-JavaScript/unavailable-script native POST behavior with clean URLs and no reflected PII.
- Existing CRUD fixtures now model the actual locked completed Adviser and coherent login. The lifecycle worker loads the pure shared validator and recognizes its locked SQL for the existing assignment/archive race gate. Existing HTTP fixtures serve the actual recovery pages and expose response headers. The browser harness captures native completion POSTs, simulates an unavailable onboarding script, and avoids waiting on disabled animation callbacks during no-JS navigation. Existing assertions were retained.

**Executed verification and updated totals**

`C:\xampp\php\php.exe tests/account-lifecycle-runner.php --retention` passed against its disposable MariaDB 10.4.32 instance: **1,872 assertions**, previously 1,662. This includes the genuine-v8 PHP/manual final-v9 migrations and failure/retry checks, retention/purge/recovery/files/bulk suites, verifier boundaries, actual private HTTP application copy, onboarding services, and concurrency at both supported isolation levels.

| Private MariaDB suite | Assertions |
|---|---:|
| Guarded PHP/manual migration and schema | 37 |
| Single-account ownership/retention/files | 279 |
| File failure/recovery and basic bulk | 137 |
| Extended provenance/crash/Adviser bulk/Admin | 37 |
| Retention concurrency | 212 |
| Global/scoped schema verifier | 86 |
| Actual HTTP | 484 |
| Final-v9 migration/backfill/failure | 85 |
| Onboarding service/lifecycle | 315 |
| Onboarding concurrency | 200 |
| Total | 1,872 |

Actual HTTP comprises 267 retained checks plus 217 onboarding checks (47 retained + 170 new). Service increased 307 -> 315; onboarding races 168 -> 200. All prior suites retained their passing counts.

- `node tests/ui-audit.cjs --onboarding-only`: **599 checks, zero failures**, previously 545.
- `node tests/ui-audit.cjs --retention-only`: **332 checks, zero failures**, unchanged.
- `node tests/password-reset-browser.cjs`: **34 HTTPS reset/setup checks, zero failures**. Every request is intercepted and fulfilled with isolated fixtures; no production request is sent.
- PHP syntax: **140 current PHP files passed**, excluding private configuration, vendor and historical reference copies.
- JS/CJS syntax: **32 checks passed** (31 standalone files and the existing async-context snippet checked in its required wrapper).
- Actual UTF-8 decoding passed for 221 current/review source files; 22 protected migration/report/patch/manifest hashes match the baseline.
- `git diff --check`: passed. No staged files.

All 31 existing CLI regression suites passed using `C:\xampp\php\php.exe -d extension=zip tests/<suite>.php`:

| Suite | Passing checks/assertions/cases as reported |
|---|---:|
| auth-audit | 16 |
| auth-flows | 52 (was 48) |
| session-idle-audit | 25 |
| security-audit | 46 |
| rate-limit-audit | 45 |
| logout-audit | 28 |
| crud-audit | 56 |
| document-workflow-audit | 43 |
| notification-delivery-audit | 293 |
| research-group-audit | 197 |
| academic-audit | 591 |
| academic-api-audit | 1,343 |
| alignment-audit | 228 |
| report-format-audit | 274 |
| backend-audit | 87 |
| tonight-polish-audit | 99 |
| readiness-audit | 426 |
| email-format-audit | 26 |
| cache-audit | 149 |
| final-regression-audit | 242 |
| hardening-audit | 114 |
| calendar-deadlines-audit | 190 |
| document-extraction-audit | 37 |
| document-summary-audit | 297 |
| document-summary-transport-audit | 41 |
| archive-operational-audit | 85 |
| csv-export-audit | 93 |
| academic-migration-audit | 331 |
| schema-v6-audit | 392 |
| migration-cli-audit | 145 assertions / 16 cases |
| deadline-migration-cli-audit | 145 assertions / 16 cases |

Student CRUD was also rerun after the controlled validation-response fix: all 56 cases passed. Initial new checks exposed that missing 422 mapping and the no-JS browser harness animation wait; both were corrected and their final suites passed. No outstanding test failures remain.

Branch remains `prism-v2-final-v8`; HEAD remains `be75eaf6d4c46bdeca7e1a6f889d2ccfa18dd0dc`. The worktree contains the pre-existing implementation/review changes as well as this narrow remediation; those additional dirty files were not edited by this remediation. No installed database was accessed or migrated. JavaScript is required for profile completion; its safe HTML fallback intentionally does not save profile values.

**Ending git status --short**

```
 M .gitignore
 M ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md
 M admin_people.php
 M advisers_api.php
 M assets/js/admin-management.js
 M assets/js/admin-retention.js
 M config.php
 M dashboard.php
 M data_exports_api.php
 M forgot_password_process.php
 M ierb_api.php
 M includes/account_lifecycle_schema.json
 M includes/account_retention.php
 M includes/account_retention_bulk.php
 M includes/calendar_deadlines.php
 M includes/pagination.php
 M includes/research_groups.php
 M includes/student_snapshot.php
 M index.php
 M notifications_api.php
 M register_process.php
 M reports_api.php
 M reset_password.php
 M send_followup.php
 M students_api.php
 M tests/account-lifecycle-runner.php
 M tests/account-lifecycle-worker.php
 M tests/account-retention-http.php
 M tests/account-retention-migration.php
 M tests/account-retention-mysql.php
 M tests/archive-operational-audit.php
 M tests/auth-flows.php
 M tests/calendar-deadlines-audit.php
 M tests/crud-audit.php
 M tests/csv-export-audit.php
 M tests/notification-delivery-audit.php
 M tests/retention-fixture-support.php
 M tests/tonight-polish-audit.php
 M tests/ui-audit.cjs
 M tools/schema-v8-manual.sql
 M tools/schema-v9-retention-manual.sql
 M update_password.php
 M workflow.php
?? PRISM_AUTH_VISUAL_REFINEMENT_REPORT.md
?? PRISM_COMBINED_V9_DESIGN.md
?? PRISM_COMBINED_V9_IMPLEMENTATION_REPORT.md
?? PRISM_FINAL_COMBINED_V9_REVIEW.patch
?? PRISM_FINAL_V9_PRECOMMIT_REMEDIATION_REPORT.md
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
?? account_invitation_api.php
?? account_setup.php
?? assets/css/onboarding.css
?? assets/js/account-setup.js
?? assets/js/invitations.js
?? assets/js/onboarding.js
?? complete_profile.php
?? includes/account_onboarding.php
?? tests/academic-api-audit.php
?? tests/account-onboarding-concurrency.php
?? tests/account-onboarding-http.php
?? tests/account-onboarding-migration.php
?? tests/account-onboarding-mysql.php
?? tests/account-onboarding-remediation-http.php
?? tests/account-onboarding-ui.cjs
```

No commit, push, merge, tag, deployment, staging, installed database migration,
production database access, production verification installation, or production
modification performed.
