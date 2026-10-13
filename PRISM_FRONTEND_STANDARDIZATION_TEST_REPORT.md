# PRISM Batch 11.5 — Frontend Standardization Test Report

Final assessment: **PASS** for the executed local Chromium/XAMPP coverage. No commit, push, merge, tag, deployment, migration or production database operation was performed.

## Source identity

- Branch: `prism-v10-b11-ui-admin-override`
- HEAD: `83a941de4206c4458903805c84b901a9496b67d7`
- Date: 13 October 2026 (Asia/Manila).
- Pre-existing untracked Batch 2–10/schema reports, `tests/v10-batch105-results.json`, and the unrelated root attachment-like file were left intact.
- Protected executable PHP prefixes: 97/97 identical.
- Frozen metadata files: 3/3 byte-identical.
- `git diff --check`: exit 0.

[Integrity and complete modified/added-file inventory](tests/frontend-standardization-results/integrity.json); [Exact source diff, including added source files](tests/frontend-standardization-results/source-diff.patch).

## Environment and isolation

Windows/XAMPP, PHP 8.2.12, MariaDB 10.4.32, Node 25.0.0, Chrome/154.0.8037.98. Headless Chromium was driven through CDP using the installed browser, with Asia/Manila timezone and temporary owned browser profiles. Cached public fonts/icons were SHA-256 verified; external browser requests were intercepted, and isolated configuration disabled real mail/AI providers.

The native fixture creates a random-port MariaDB instance with a freshly owned temporary datadir and verifies `@@datadir` before using it. Frozen v10 metadata builds only this disposable fixture. Application source copies omit `config.local.php`; only their test-only DB connection is replaced. HTTP/session/storage files and synthetic mutations remain inside the owned fixture. Existing concurrent workers are explicitly bound to that fixture storage in the **source-copy harness**, without changing production worker/business code.

## Browser results

| Evidence set | Assertions | Failed | Captures | Meaning |
|---|---:|---:|---:|---|
| Before | 2653 | 60 | 208 | Baseline defects, not release failures. |
| Additional baseline upload form | 73 | 0 | 4 | Baseline defects, not release failures. |
| Valid-reset baseline fixture | 85 | 6 | 4 | Baseline defects, not release failures. |
| Admin year baseline | 73 | 18 | 16 | Baseline defects, not release failures. |
| Final full after matrix | 4505 | 0 | 226 | Executed browser checks. |
| Targeted button geometry | 532 | 0 | 29 | Executed browser checks. |
| Auth sizing and valid reset | 577 | 0 | 28 | Executed browser checks. |
| Superseded calendar width check (fixed in final full matrix) | 1729 | 4 | 104 | Superseded diagnostics; rerun in the final matrix. |
| Authorized deadline cancellation tone | 157 | 0 | 12 | Executed browser checks. |
| Admin year view/legend | 133 | 0 | 16 | Executed browser checks. |
| Final shared Today/deadline/marker state | 1057 | 0 | 72 | Executed browser checks. |

The full matrix covers 41 role/route/state scenarios at **1920×1080, 1366×768, 1024×768, 768×1024, 390×844 and 320×700**, in light and dark modes. The final targeted run checks the last toolbar/compact-button sizing refinements on affected routes at the same six sizes and both themes.

Executed checks include horizontal page/control fit, authorized destination, stored theme, accessible icon names, Actions-menu keyboard entry/viewport placement/Escape focus return, exclusion of restricted actions, calendar geometry/legend/Today, native dialog Tab/Escape focus, required override reason, Tour/profile focus return, reduced motion, real deadline details, pending-request loading and injected-service error states, empty document search, personal reminder storage/edit controls, legal unsaved-change protection and photo-preview keyboard order.

[Full browser assertions, samples, exceptions and screenshots](tests/frontend-standardization-results/after/browser-results.json); [Final targeted button/geometry results](tests/frontend-standardization-results/after-targeted/browser-results.json); [Before/after gallery](tests/frontend-standardization-results/comparison-gallery.html).

## Functional and security regression

| Executed verification | Result | Evidence |
|---|---|---|
| Existing CLI preservation suites | 27/27 pass | [Results and authentication rerun](tests/frontend-standardization-results/cli-final.json) |
| Authentication flow cases | PASS: 52 | [Log](tests/frontend-standardization-results/auth-final.log) |
| Final production PHP/JS syntax | 132/132 pass | [Results](tests/frontend-standardization-results/syntax-final.json) |
| Native + real HTTP workflow, scope, concurrency and legal governance | PASS: 482 total | [Log](tests/frontend-standardization-results/backend-final.log) |
| Batch 11 mandatory atomic audit/override/authorization/rollback assertions | PASS: 245 within the native/HTTP run | [Log](tests/frontend-standardization-results/backend-final.log) |
| Persistent permanent-delete evidence, retention/account restrictions, drift/tamper and real HTTP guards | PASS | [Log](tests/frontend-standardization-results/retention-final.log) |

The CLI suites exercise login/logout/idle timeout, authentication restrictions, object/role authorization, document workflow/extraction/summary transport, research groups and academics, archive operational visibility, CSV protection, filters/invitations/deadline scoping, upload validation, notification ownership/generation/legal gates, and support privacy. The native/HTTP run exercises actual Student uploads, Adviser approval, RPMS formal submissions, returned revisions/version history, forced audit-failure rollback and concurrency, inactive/Pending/expired/legal/password gates, bounded pagination, and draft/reject/publish/reacceptance governance. Retention tests perform deletion/drift/failure cases only in a separately owned disposable database.

## Failures resolved and limitations

- The first source-copy setup lacked an isolated worker storage binding, then accidentally duplicated a fixture hook on rerun. Both harness issues were corrected; initial diagnostic logs are retained. No application database was involved.
- An initial authentication template test failed because a newly added direct require resolved from the eval harness directory. The stable `prism_frontend_styles()` helper resolved that presentation-loading compatibility issue; all 52 authentication cases were rerun successfully.
- Initial after-browser validation found the legacy dashboard table clipping and an invalid readiness assumption after an empty search. The layout was fixed; the new harness now accepts a real empty result as ready. Its diagnostic JSON is retained as `after-initial-failure.json`.
- The first retention run could not find the read-only verifier in the isolated source copy. A later combined native/HTTP invocation also collided on a synthetic account ID. The verifier was copied into the test source and the two suites were run in separate disposable databases: 203 native retention/verification assertions and 12 real HTTP assertions passed (219 including fixture checks). Initial failure logs are retained.
- Actual screenshot review caught an oversized Registration card, repeated Student weekdays and a squeezed report-history heading; these were corrected and rerendered. Narrow Admin date targets were verified after removing the superseded forced padding rule. Invite/Add controls were corrected to the 44px shared size.
- A broad DevTools interception pattern raced cancelled background API requests and produced a protocol error. The final harness intercepts cached HTTPS assets and the specific deadline test endpoint; application exceptions and unexpected protocol failures still fail the run. The final full matrix passed without this error.
- The final calendar comparison removed the legacy Student Today size/color override and normalized deadline dots. The state check awaits finite calendar color transitions before comparing settled computed styles; 1,057 final cross-role assertions passed.
- The original synthetic reset token omitted the stored digest prefix. It was corrected in the fixture only; valid reset form visibility was then explicitly tested.
- Browser coverage is the installed Chromium on Windows with viewport emulation. Safari, Firefox, physical touch devices, screen-reader audio output and print/PDF visual review were not executed. They are not reported as passed.
- Real external email/AI delivery and production-provider availability were intentionally not exercised. Existing isolated delivery/privacy/workflow checks passed; external providers are not part of this frontend change.
- Screenshots contain synthetic fixtures. Before and after fixture workflow counts can differ because the after security suite prepares additional states; no pixel-identical fixture-data claim is made.
- Existing unrelated legacy `!important` declarations and specialized stylesheet rules remain where their consumers still need them. New shared rules use importance only for native hidden behavior and reduced-motion enforcement.
- Temporary browser profile cleanup can be delayed by Windows file locks; any retained owned-profile path is recorded in the cleanup artifact. Application/datadir server teardown is performed by the native harness.

## Modified and added files

- Modified: `.gitignore`
- Modified: `account.php`
- Modified: `account_setup.php`
- Modified: `admin_ai.php`
- Modified: `admin_legal_policies.php`
- Modified: `admin_notifications.php`
- Modified: `admin_people.php`
- Modified: `assets/css/batch1075.css`
- Modified: `assets/css/batch11.css`
- Modified: `assets/css/calendar.css`
- Modified: `assets/css/dashboard-overview.css`
- Modified: `assets/css/onboarding.css`
- Modified: `assets/css/prism-ui.css`
- Modified: `assets/css/prism-workspace.css`
- Modified: `assets/css/student-dashboard.css`
- Modified: `assets/js/calendar-deadlines.js`
- Modified: `assets/js/documents.js`
- Modified: `assets/js/prism-workspace.js`
- Modified: `assets/js/role-calendar.js`
- Modified: `assets/js/role-portal.js`
- Modified: `calendar.php`
- Modified: `change_password_required.php`
- Modified: `complete_profile.php`
- Modified: `dashboard.php`
- Modified: `data_export.php`
- Modified: `documents.php`
- Modified: `forgot_password.php`
- Modified: `help_support.php`
- Modified: `ierbprog.php`
- Modified: `includes/assets.php`
- Modified: `includes/public_landing.php`
- Modified: `includes/public_legal.php`
- Modified: `legal_consent.php`
- Modified: `login.php`
- Modified: `register.php`
- Modified: `reports.php`
- Modified: `research_adviser.php`
- Modified: `reset_password.php`
- Modified: `role_portal.php`
- Modified: `staff_registration_manage.php`
- Added: `assets/css/prism-tokens.css`
- Added: `assets/css/prism-components.css`
- Added: `assets/css/prism-calendar.css`
- Added: `assets/js/prism-calendar-presentation.js`
- Added: `includes/frontend_styles.php`
- Added: `tests/frontend-standardization-browser.cjs`
- Added: `tools/frontend-inventory.py`
- Added: `tools/verify-frontend-standardization.cjs`
- Added: `tools/frontend-contact-sheets.cjs`
- Added: `tools/frontend-standardization-report.py`
- Added: `PRISM_FRONTEND_DESIGN_SYSTEM_REPORT.md`
- Added: `PRISM_FRONTEND_CONSISTENCY_AUDIT.md`
- Added: `PRISM_FRONTEND_STANDARDIZATION_TEST_REPORT.md`

Raw screenshots, JSON samples, private-config-free source snapshots, logs, gallery and contact sheets are under `tests/frontend-standardization-results/` (ignored evidence directory). No production documents/configuration are included.

## Final git diff --stat

This is the exact tracked diff. Added files are deliberately unstaged and therefore appear in the inventory above, not in Git's ordinary tracked diff statistic.

```text
 .gitignore                        |   1 +
 account.php                       |   2 +-
 account_setup.php                 |   3 +-
 admin_ai.php                      |   4 +-
 admin_legal_policies.php          |   4 +-
 admin_notifications.php           |   4 +-
 admin_people.php                  |   3 +-
 assets/css/batch1075.css          |   7 ---
 assets/css/batch11.css            |   8 +--
 assets/css/calendar.css           |   1 -
 assets/css/dashboard-overview.css |   2 +-
 assets/css/onboarding.css         |   8 +--
 assets/css/prism-ui.css           | 120 ++------------------------------------
 assets/css/prism-workspace.css    |  89 ++--------------------------
 assets/css/student-dashboard.css  |   1 -
 assets/js/calendar-deadlines.js   |   2 +-
 assets/js/documents.js            |   2 +-
 assets/js/prism-workspace.js      |   5 --
 assets/js/role-calendar.js        |   7 ---
 assets/js/role-portal.js          |   1 +
 calendar.php                      |   4 +-
 change_password_required.php      |   9 +--
 complete_profile.php              |   3 +-
 dashboard.php                     |   2 +-
 data_export.php                   |   2 +-
 documents.php                     |   2 +-
 forgot_password.php               |   7 ++-
 help_support.php                  |   1 +
 ierbprog.php                      |   4 +-
 includes/assets.php               |   6 ++
 includes/public_landing.php       |   1 +
 includes/public_legal.php         |   1 +
 legal_consent.php                 |   3 +-
 login.php                         |   4 +-
 register.php                      |   5 +-
 reports.php                       |   3 +-
 research_adviser.php              |   2 +-
 reset_password.php                |   7 ++-
 role_portal.php                   |   4 +-
 staff_registration_manage.php     |   2 +-
 40 files changed, 75 insertions(+), 271 deletions(-)
```
