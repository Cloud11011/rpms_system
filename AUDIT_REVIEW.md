# PRISM audit and fixes — 25 September 2026

The review covered the first-party PHP/JavaScript sources, authentication and recovery handlers, document workflow endpoints, report ownership checks, the four updated UI areas, and Apache access restrictions. Existing uncommitted UI work was preserved. No database migration, role definition, API route, request parameter, or existing page ID was changed. No commit was made.

## Confirmed findings resolved

| Priority | Finding and effect | Fix |
| --- | --- | --- |
| High | Review, override, formal submission, and delete could act on a document state read before a concurrent change. Two uploads could also select the same predecessor and both remain current. | Document writes serialize on the student, take a fresh locking document read, and reject changed state with HTTP 409. Upload predecessor/version selection now occurs under the same student lock. Existing approval, submission, and override rules remain in force. |
| Medium | Forced-password-change checks trusted a stale session flag. | The flag is refreshed from the authenticated account row on each request. |
| Medium | Login accepted cross-site POSTs; repeated recovery requests sent repeated messages and invalidated recent links. | Login and recovery handlers reuse the existing same-origin POST guard. Reset issuance has a five-minute per-account cooldown, preserving the generic response and recent link. Reset operations use a consistent user-before-token lock order. |
| Medium | A password change could overwrite a newer password if recovery completed after the request's original credential check. | The update requires the previously verified password hash to still match; a conflict rolls back and requests a new login. |
| Medium | A compressed DOCX could expand its document XML without a read limit. | XML extraction checks uncompressed size and caps the read at 1,500,000 bytes. Oversized XML skips text extraction without changing upload permissions. |
| Medium | Approval-date detection selected unrelated submission dates and normalized impossible dates. | Local extraction requires an affirmative approval/issuance label immediately followed by a supported date. Calendar validation also rejects invalid AI dates. |
| Medium | The Account password handler used `event.currentTarget` after `await`, producing an error after a successful change. Activity/history requests could also replace newer results with stale responses. | Capture the form before awaiting; ignore stale responses; retain accurate pending/error states. |
| UI | Scheduling and Activity Log controls overflowed at some widths; generic input rules enlarged checkboxes. | Scoped responsive grids and checkbox sizing keep controls within their forms. Search remains the widest activity filter, with labels above date fields. |
| UI | Chart columns lacked a common plotting area, and empty charts retained six grid columns. Empty tables kept the populated table's minimum width. | Fixed-height plotting areas preserve ratios; empty chart/table states fit their container. Failed requests are explicitly distinct from zero records. |
| UI | Generated-report history required a mouse; report cards could differ in height when stacked. | Native PDF links support keyboard navigation and secure new tabs; grid rows have equal heights. |
| UI | Stage labels remained editable during saves, and audit metadata was difficult to inspect. | A saving row is disabled and reflects the server-returned label. Existing audit information remains visible; supplemental API metadata is available in a native disclosure. Dynamic values use text nodes or HTML escaping. |

## Initial audit verification completed

- PHP syntax: **48 first-party PHP files passed**, excluding `config.local.php` and dependencies.
- JavaScript syntax: **15 JS/CJS files passed**.
- Browser: **435 isolated assertions passed** using installed headless Chrome, the actual four templates with stubbed authentication, local styles/scripts, and mocked APIs. Widths: 375, 768, 1024, 1280, and 1600 pixels; light/dark themes; admin/adviser views; empty/populated/error states; long labels; literal rendering of hostile-looking text; form payloads; chart proportions; report-link focus; password-form completion.
- Backend: **86 isolated assertions passed**, including real temporary DOCX archives with the ZipArchive extension and simulated stale document states.
- Authentication guards: **12 isolated cases passed** for credential binding, forced changes, role restrictions, and inactive accounts.
- Authentication handlers: **12 isolated checks passed** for login binding, origin rejection, reset cooldown/confirmation, password-change conflicts, and reset completion.
- Existing IDs on all four pages are preserved, with no duplicate IDs.
- `git diff --check` passed.

Commands for the regression suites on XAMPP:

```powershell
node tests/ui-audit.cjs
& 'C:\xampp\php\php.exe' -d extension=zip tests/backend-audit.php
& 'C:\xampp\php\php.exe' tests/auth-audit.php
& 'C:\xampp\php\php.exe' tests/auth-flows.php
```

The test directory is denied by Apache and its PHP programs require CLI execution. The browser suite binds an ephemeral loopback fixture server, uses a temporary browser profile, and blocks external page resources. It requires Node with built-in WebSocket support and an installed Chrome/Edge browser.

## Changed files

- Page markup: `admin_notifications.php`, `admin_ai.php`, `ierbprog.php`, `account.php`.
- Presentation/rendering: `assets/css/workspace-pages.css` (new), `assets/css/ierbprog.css`, `assets/js/admin-notifications.js`, `assets/js/admin-ai.js`, `assets/js/ierbprog.js`, `assets/js/account.js`.
- Backend fixes: `config.php`, `login_process.php`, `forgot_password_process.php`, `update_password.php`, `profile_api.php`, `documents_api.php`, `ai_helpers.php`.
- Verification: `tests/.htaccess`, `tests/ui-audit.cjs`, `tests/backend-audit.php`, `tests/auth-audit.php`, `tests/auth-flows.php`, and this report.

## Practical effects and verification limits

Existing sessions created before credential binding will need to sign in again. Changing or recovering a password invalidates other sessions on their next authenticated request. Recovery emails are limited to one issuance per account within five minutes.

The checks did not execute the live database, migrations, email delivery, or AI integrations. Document concurrency was exercised with fixture database objects, not simultaneous live MySQL connections. Real MySQL transaction behavior, email delivery, and external AI integration still require a staging integration run. Browser fonts/icons from external CDNs were blocked during fixture testing, so those resources were not validated. `config.local.php` was neither opened nor modified, and secrets were not included in the output.

## Approved targeted follow-up - 25 September 2026

This follow-up implements the separately approved batch H1/H2, W1, E1/E2, L2, and N1-N4 after the read-only post-fix verification. The initial audit results above are retained as a historical snapshot. The results below describe the current working tree on branch `prism-v2-fixes`.

| Finding | Current disposition | Change and evidence |
| --- | --- | --- |
| H1/H2 - stale saves/deletes and misleading duplicate errors | Fixed; isolated endpoint regression coverage | Student, adviser and IERB edits/deletes read the target under a transaction lock and return 404 when missing. Only MySQL duplicate-key error 1062 produces a duplicate-ID/email response. Other save errors receive a generic 500; student foreign-key error 1452 retains its invalid-adviser response. Unchanged updates remain successful. |
| W1 - override approval did not advance progress | Fixed; both trigger modes covered | `documents_api.php` calls the existing stage-advancement helper inside the override transaction when the new status is Approved and the trigger is approval. Submission mode still advances on formal submission. Terminal/old-stage documents do not advance again. Override reason, audit data and authorization remain enforced. |
| E1/E2 - reset flash/form message escaping | Fixed; rendered PHP and actual JavaScript handler covered | `reset_password.php` escapes both session messages with htmlspecialchars. `change_password_required.php` builds an error element with textContent instead of inserting a server message as HTML. |
| L2 - filesystem cleanup outside DB transaction | Partially fixed; operational limitation remains | File deletion stays after commit so a failed DB deletion retains its file. Failed unlink operations now log the document ID and cleanup context, including rejected/conflicting/failed uploads. Database and filesystem operations remain non-atomic; a process crash or failed unlink can leave an orphan file. No retry queue or reconciliation job was added. |
| N1 - upload silently moved to a newly advanced stage | Fixed; stage-change fixture covered | Student uploads compare the locked stage with the stage resolved before file processing. A changed stage returns 409 and attempts file cleanup. Admin-selected stages remain unchanged. |
| N2 - reset failure left an uncontrolled transaction/error path | Fixed for the reset handler; failure injection covered | Database connection, lookup and transaction operations are guarded. Failures attempt rollback, log generic diagnostics without credentials/token/SQL, and redirect with a generic error. The user-before-token lock order remains intact. This does not provide global production error handling for other endpoints. |
| N3 - older list responses replaced newer UI results | Fixed; overlapping browser requests covered | Notification history and IERB records use request sequence counters. Obsolete successes/errors cannot change content, display an error, or clear the latest loading state. |
| N4 - adviser ownership became stale before saving | Fixed; reassignment and authorized-save fixtures covered | Student saves recheck adviser ownership on the locked student row before writing. Adviser protocol/PI restrictions and admin-only deletion remain enforced. |

### Latest verification

- **21 touched PHP files:** syntax passed, including new PHP test files; secret configuration excluded.
- **6 touched JS/CJS files:** Node syntax passed. The forced-password page's actual inline handler was also parsed/executed in its isolated regression suite.
- **467 browser assertions:** passed, including 32 new overlapping-request checks for notification and IERB loading. Existing light/dark, five viewport widths, role fixtures, hostile-text rendering and API-payload checks continue to pass.
- **86 backend assertions:** passed with ZipArchive enabled and temporary DOCX fixtures.
- **12 authentication guard cases:** passed.
- **24 authentication flow checks:** passed, including eight injected reset database failures, validation rollback, escaped reset messages, credential binding and unchanged generic recovery confirmation.
- **30 CRUD endpoint cases:** passed for missing/current records, duplicate/unrelated database errors, invalid adviser reference, adviser reassignment, protected fields, reason requirements and role restrictions.
- **24 document workflow cases:** passed for approval/submission modes, stage guards, rollback, version selection, authorization, required reasons and cleanup failures.
- **5 forced-password form cases:** passed for literal hostile-looking server messages, fallback/network errors, mismatch handling, unchanged request parameters and successful redirection.
- All **77 original IDs** across the four UI pages remain present, with no duplicate IDs.
- `git diff --check` passed. No fixture runtime exceptions were reported by the browser suite.

Additional commands:

```powershell
& 'C:\xampp\php\php.exe' tests/crud-audit.php
& 'C:\xampp\php\php.exe' tests/document-workflow-audit.php
node tests/reset-ui-audit.cjs
```

The new PHP tests execute isolated copies of the actual endpoint code with fixture database objects and replaced bootstrap dependencies. The document suite also isolates the actual workflow helper functions. File operations, messages and AI are mocked in that suite. The forced-password suite executes the actual inline handler with a small DOM fixture; it is not a full browser test of the password page.

### Files changed in this batch

- CRUD: `students_api.php`, `advisers_api.php`, `ierb_api.php`.
- Documents: `documents_api.php`.
- Password reset/form handling: `update_password.php`, `reset_password.php`, `change_password_required.php`.
- UI request ordering: `assets/js/admin-notifications.js`, `assets/js/ierbprog.js`.
- Existing suites expanded: `tests/auth-flows.php`, `tests/ui-audit.cjs`.
- New suites: `tests/crud-audit.php`, `tests/document-workflow-audit.php`, `tests/reset-ui-audit.cjs`.
- Documentation: this report.

### Remaining release work

This batch does not close the separate production-hardening findings: P1 global database/error handling; P2 base-URL misconfiguration detection; P3 plaintext temporary-password fallback; P4 global page headers/CSP compatibility; S1 requests missing both Origin and Referer; E3 logout cookie attributes; R1 broader reset/admin-registration throttling; and D1-D3 release hygiene. L1 and L3-L6 were not changed. The recovery cooldown already present is not equivalent to general abuse throttling.

Production readiness is **not established** by these fixtures. Still required on a disposable staging database/environment:

1. Apply and validate the actual schema/migrations and transaction engine; exercise student/adviser creation, identity synchronization and deletion with real foreign keys and affected-row behavior.
2. Use simultaneous MySQL connections for competing upload/review/override/submission/delete requests, plus student reassignment and adviser deletion. Confirm lock waits/deadlock recovery, a single current document version, no unintended progression, correct 404/409 responses and matching audit/history data.
3. Race password recovery, reset completion and signed-in password changes; verify actual session cookies across browsers, old-session invalidation, the current password-change session and account deactivation/forced changes.
4. Exercise real file-storage permissions and failed cleanup; check logs and reconcile any orphan files. Simulated rollback cannot prove behavior after a lost connection during commit.
5. Validate real reset/setup email URLs, delivery, cooldown behavior and notification scheduling through the CLI worker.
6. Exercise external AI responses/failures and local fallback, real generated PDFs and report ownership.
7. Test the deployed Apache access restrictions, production error display/header settings and external font/icon resources, then complete the four UI workflows against real authorized accounts.

Existing uncommitted UI work was preserved. No API route/request parameter, role definition, page ID or database migration was changed. No commit or destructive Git operation was performed. `config.local.php` was not opened or modified.
