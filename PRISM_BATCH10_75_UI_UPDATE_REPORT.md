# PRISM Batch 10.75 UI Update report

## Baseline and authorized scope

Baseline branch: `prism-v10-b10-5-persistent-delete-verification`. HEAD: `5b78211a7e63300afacd03b244a6f63bba2da841`. Tracked working tree was clean. Fifteen untracked prior-batch reports/schema contract and `tests/v10-batch105-results.json` were present; preserved. The release-candidate review is historical supporting context, not this branch's baseline or authorization for other work.

Primary specification: all 15 pages of the supplied Batch 10.75 PDF, including the Sol prompt. Embedded images were extracted with the installed PHP PDF parser and visually inspected, including the compact second submissions reference. Python/Poppler are unavailable locally; decoded RGB references were converted for inspection with System.Drawing.

## Read-only inventory and implementation mapping

| Item | Actual files and behavior to preserve |
|---|---|
| UI-01 | `admin_people.php`, `assets/js/admin-management.js`, `documents.php`, `assets/js/documents.js`, `ierbprog.php`, `assets/js/ierbprog.js`, `research_adviser.php`, `assets/js/adviser-dashboard.js`; scoped server pagination/search/sort and existing Actions |
| UI-02 | `login.php`, `assets/css/style.css`, `assets/js/script.js`, `assets/css/auth-background.css`; centered card/photo, email/password POST, visibility toggle, notices/legal links |
| UI-03 | `includes/prism-navigation.php`, `role_portal.php`, `assets/js/prism-workspace.js`, `assets/js/prism-guidance.js`; account menus, unread notifications, existing tour, shared theme/session generation |
| UI-04 | `research_adviser.php`, `assets/js/adviser-dashboard.js`, `assets/js/dashboard-deadlines.js`; scoped review dialog, exact workflow states, server counts, notices and deadlines |
| UI-05 | `includes/prism-navigation.php`, `includes/legal_header.php`, `help_support.php`, shared search CSS; security settings remain reachable from Account; support operations unaffected |
| UI-06 | `admin_legal_policies.php`, `includes/legal_policy.php`, `assets/css/admin-legal.css`; create/save/submit/reject/approve-and-publish, ownership, immutable versions, CSRF/generation/origin and multi/sole Admin checks |
| UI-07 | `dashboard.php`; newest current-document preview capped at five, existing documents route, workflow badges |
| UI-08 | `role_portal.php`, `assets/js/role-portal.js`, `assets/css/role-portal.css`; six real stages, requirements, official header/ring, PI, history/pagination |
| UI-09 | `dashboard.php`, `assets/css/dashboard-overview.css`; existing Stage 1/2/3+4/Completed categories and counts |

Student fields: name/email/ID, research title, group identifier, program, academic unit, year level/year, adviser, stage/progress, protocol/PI. Student Actions: Edit, Archive (Admin), invitation/recovery actions as rendered, archived Restore/Hold/Delete eligibility/override and bulk selection. Adviser account/group views retain their own fields and actions. Document Actions: View, Download, Review, Version History, AI Summary (Admin), Approve, Comment, Deny, Override, Return for Revision, formal submit/force submit, Delete wherever server-authorized. Adviser queue retains Open document and authorized Review. IERB retains Edit/Delete/Add and CSV as role-authorized. No Actions entry is removed to match an illustrative mockup.

## Persistent authentication feasibility (UI-03)

**BLOCKED under frozen v10.** Read-only inspection of `config.php`, `security.php`, `logout.php` and the frozen manifest shows session-only authentication, 30-minute inactivity, credential fingerprint invalidation, session rotation/generation checks, active-account/password-change/legal/profile gates. Existing reset/invitation/staff-registration credential tables serve different, constrained lifecycles and cannot safely be repurposed as per-device persistent authentication storage. No dedicated persistent-token store/revocation lifecycle exists.

Extending the PHP session cookie alone, client storage, or hiding the timer cannot satisfy hashed per-device revocation, strict absolute expiry and replay protection. A separate protected durable store would require a reviewed atomic locking/rotation/revocation/cleanup design and shared-hosting guarantees. A future approved dedicated token store could hold selector/hash, user/device association, absolute activation/expiry, revocation and rotated-token replay evidence, with HTTPS Secure HttpOnly SameSite cookies; require fresh password for sensitive actions; revoke on logout/disable/password/account security events. That infrastructure/design is outside this visual batch and is not implemented. No schema/auth/cookie/timer changes are authorized by this implementation. The visual entry must clearly state unavailable and must never claim enabled persistence.

## Execution and evidence

Tests use the existing private frozen-v10 MariaDB fixture and copied PHP source, not the installed database or local secrets. No commit, push, merge, tag, deployment or application migration.

## UI-01 through UI-09 implementation results

| Item | Status | Implemented result and files |
|---|---|---|
| UI-01 | COMPLETE | Compact record cards with native expandable details in current Student Records, Adviser/group roster, IERB, staff document submissions, Adviser queue and Student My Documents. Original nodes and handlers move into the presentation rather than recreating privileged operations. Contextual View/Review delegates to existing authorized controls; every Actions entry remains. Existing search, server sorting and pagination retained; status pills added. Student My Documents gains scoped filename/type/stage search and sorting through the existing API. `assets/js/prism-ui.js`, `admin-management.js`, `academic-fields.js`, `ierbprog.js`, `documents.js`, `role-portal.js`, `adviser-dashboard.js`, `role_portal.php`, shared `batch1075.css`. |
| UI-02 | COMPLETE | Centered rounded card and original CEU campus photograph/glow preserved. Internal field labels, typography, rectangular soft fields, coherent focus, password visibility, right-aligned Forgot Password, button/access notice/legal links refined. Saved light/dark preference supported. `login.php`, `assets/css/style.css`; login processing unchanged. |
| UI-03 | PARTIAL | Ordered compact theme/bell/tour/unavailable-persistence/profile group; inline sun/moon, bell, compass and shield icons; dynamic Unicode-aware initials from the authenticated rendered name. Name/role and full account menu retained. Existing Quick Tour is invoked; no second walkthrough. Badge changes follow personal inbox reads. Keep Me Signed In is explicitly unavailable, with a keyboard-accessible explanation and no authentication effect. `assets/js/prism-workspace.js`, `notification-history.js`, `adviser-dashboard.js`, `role-portal.js`, `batch1075.css`. Persistence itself is BLOCKED as assessed above. |
| UI-04 | COMPLETE | Only dashboard account shell removed; Account access retained. Queue spans width; Pending/Needs Revision/Approved summary buttons synchronize with pills/select. Approved groups existing Ready/Submitted states using a bounded read filter, without combining stored states. Returned by RPMS remains separate. Equal desktop notifications/calendar panels stack on mobile; existing personal mark-read/all-read endpoints reused. Shared date tiles, deadline dots, selected date and small detail area preserve month navigation, Admin year view, deadline dialogs and personal reminders. `research_adviser.php`, `adviser-dashboard.js`, `dashboard-deadlines.js`, `documents_api.php` (read grouping only), `batch1075.css`. |
| UI-05 | COMPLETE | Duplicate Settings entry removed after confirming Account security section remains. Shared public Back controls become labelled SVG arrows with exact original destinations. Search wrappers own a coherent focus border. Help header retains brand/back/theme and removes only its duplicate Logout; profile Logout and `logout.php` retained. `includes/prism-navigation.php`, `includes/legal_header.php`, `help_support.php`, `batch1075.css`. |
| UI-06 | COMPLETE | Policy tabs show only their own published card/history. Publishing guidance, immutable status, Create Draft/open candidate links, edit/save/submit/reject/approve-and-publish and review metadata remain. Editor Back/Cancel returns to the selected policy; unsaved changes require Continue Editing/Discard Changes; Escape restores focus. Leaving via other navigation uses the browser's unsaved-edit warning. Cancel does not POST, remove the saved draft, approve or publish. `admin_legal_policies.php`, `assets/js/legal-management.js`, `assets/css/admin-legal.css`. Governance service unchanged. |
| UI-07 | COMPLETE | White/appropriate dark file cards with real extension, filename/version, uploader, original protocol/student context, workflow badge and navigation. Very long preview filenames use a two-line visual clamp while full text remains in the accessible link and title; full document tools retain the complete filename. All/Pending/Needs Revision/Submitted pills filter the existing five-item recent preview. Summary explicitly separates shown/preview/full current repository totals. Existing `documents.php` View All route retained. Distinct unavailable/no-upload/no-matching-preview states. `dashboard.php`, `batch1075.css`. |
| UI-08 | COMPLETE | Only journey cards and requirement shells refined; six real stages retain configured descriptions and calculation. Completed green, current pink and upcoming neutral; softly framed requirements. Official header/ring, PI/protocol reveal, history/remarks and pagination preserved. `role_portal.php`, `assets/js/role-portal.js`, `batch1075.css`. |
| UI-09 | COMPLETE | Funnel heading/helper, existing two-column stage grid, accent top borders and 40–58px count numerals. Buttons have active selection, keyboard semantics and scoped server filtering; select again clears. Categories remain Stage 1, Stage 2, Stage 3+4 and Completed. `dashboard.php`, `ierb_api.php` (bounded read filter only), `batch1075.css`. |

### Deliberate reference variances and data definitions

The stage widget's existing SQL aggregates **student records**, not distinct groups. Its revision category counts Stage 3+4 records, not necessarily delayed records. Changing those calculations is forbidden by UI-09. Labels now accurately say student/students; the old misleading Groups/Delayed labels are not copied. No new aggregate calculation was introduced. Stage 5 remains outside this existing four-category widget.

Document/Adviser/Student pills use backend counts for the entire permitted current-document population; search and other filters change the displayed result total. Student Records pills omit unsupported per-status totals and retain the accurate server result count. IERB pills use its existing scoped overview. Repository pills count only the bounded recent preview, explicitly labelled; they never imply global filter counts. All clears the status selection; existing Clear Filters resets all search/sort/filter controls.

Research Groups have no separate standalone route on this branch. Their existing canonical group identifier/label and adviser assignments remain in Student Records and the Adviser roster; no new group schema or management operation was invented. Archived-account retention tables intentionally retain their detailed presentation and existing recovery/deletion controls. Student academic details now explicitly show Not recorded for missing fields. The local profile-photo preview remains on the profile page; topbar avatars always use initials.

## Preserved functions and verified boundaries

- Authentication/session generation, strict cookies, credential fingerprint, mandatory password change, 30-minute inactivity, profile/legal gates, logout and account suspension/archive behavior remain unchanged. No persistent credential is issued or stored.
- Student may upload/revise its own eligible version, preview/download and read history. Student still cannot review, formally submit, Return or override. Assigned Adviser scoping remains enforced on the server. Adviser approval still atomically formally submits the current version to RPMS, with notification/audit/history behavior intact. Return remains an Admin action requiring its established reason; revisions retain old versions and timestamps. Admin historical access, override/deletion reasons and retention eligibility remain authoritative.
- Student identity, email/ID, research/group, program/unit/year/year-level, adviser, official stage/status, protocol/PI and pending requirements remain discoverable in each compact card's details. Current/archived Student and Adviser populations, invitation/recovery actions, selection/bulk controls and all authorized Actions are retained.
- Public Back URLs are unchanged. Help request/response APIs and privacy boundaries are unchanged. Account/security/activity/logout and Navigation Guide remain accessible after redundant Settings/header Logout removal.
- Legal mutation forms retain their original CSRF/generation fields and origin/auth checks. Backend ownership, publication hashes, multi-Admin separation of duties, sole-Admin password/acknowledgment and exact policy reacceptance remain unchanged. Native checks verify cancellation leaves saved drafts and published pointers/content intact.
- Calendar APIs/scoping/time zone, read/unread ownership, document APIs/scoping/file handling, PI and stage rules, provider privacy boundaries and frozen v10 manifest remain authoritative. Only two narrowly scoped read filters were added: approved-document grouping and dashboard-stage category selection.

## Screenshot and accessibility evidence

All paths below are relative to `tests/v10-batch1075-results/`. Original reference figures/contact sheets are under `references/`; source PDF text and every figure were inspected. Captures contain only disposable fixture data.

| UI | Before | After |
|---|---|---|
| UI-01 | `before/1001-admin_students.php-light.png`, `before/1001-documents.php-light.png`, `before/1001-ierbprog.php-light.png` | `after/1001-admin_students.php-1366-light.png`, `after/1001-documents.php-1366-light.png`, `after/1001-ierbprog.php-1366-light.png`; mobile/dark counterparts; final compact cards in `contrast/UI01-*`, Student controls in `contrast/Student-documents-*` |
| UI-02 | `before/UI02-login.png` | `after/UI02-1366-light.png`, `after/UI02-1366-dark.png`, `after/UI02-390-light.png`, `after/UI02-390-dark.png` |
| UI-03 | Topbars in baseline role pages | Topbars in corresponding `after/` role pages; initials, ordered controls and unavailable persistence explanation browser assertions |
| UI-04 | `before/1002-research_adviser.php-light.png` | `after/1002-research_adviser.php-1366-light.png` and 390/dark equivalents; `contrast/Adviser-cards-*` for final responsive cards; `focus/UI04-calendar-*` and `focus/calendar-*` for actual deadline selection |
| UI-05 | `before/1003-help_support.php-light.png`, baseline Student Records search | `after/1003-help_support.php-1366-light.png` and 390/dark equivalents; current record-page search |
| UI-06 | `before/1001-admin_legal_policies.php-light.png` | `after/1001-admin_legal_policies.php-1366-light.png`, mobile/dark variants; `after/UI06-cancel-*` |
| UI-07 | Repository section in `before/1001-dashboard.php-light.png` | `after/UI07-1366-light.png`, `after/UI07-390-dark.png`, other theme counterparts |
| UI-08 | `before/UI08-light.png`, `before/UI08-dark.png` | `after/UI08-1366-light.png`, `after/UI08-390-dark.png`, other theme counterparts |
| UI-09 | Stage section in `before/1001-dashboard.php-light.png` | `after/UI09-1366-light.png`, `after/UI09-390-dark.png`, other theme counterparts |

The full matrix covers 1920x1080, 1366x768, 1024x768, 768x1024, 390x844 and 320x700, light/dark. Twelve route/role combinations cover Admin dashboard, Students, Advisers/groups, documents, IERB and legal policies; Adviser dashboard, Students, documents and IERB; Student portal and Support. Student progress and My Documents are separately opened. Public Login is checked at all six sizes in both themes. Focused follow-up covers real marked deadlines/dialogs for all three roles and Student document search/sort/empty/error handling.

Evidence verifies no page-width overflow, expanded detail wrapping, preserved Actions visibility and Escape closure, labelled icon controls, selectable summary cards and synchronized pills, modal sizing, equal/stacked panels, six-stage journey/PI/ring/history, keyboard policy tabs, unsaved draft confirmation and return policy, preview limits/count definitions, theme behavior and unchanged inactivity expiry. `after/navigation-*.json` records canonical collapsed navigation with hidden caption/backdrop. Screenshots were visually inspected; no live user records were used.

Final selected status pills and Login heading meet the checked 4.5:1 text contrast threshold; initials remain visible on mobile. Compact card widths are checked against their own scroll containers, with mobile content stacked. Final gallery captures use viewport/element clips to avoid the full-page capture artifact on the fixed sidebar.

Focused checks can be reproduced by adding `--uionly1075 --focus1075` or `--uionly1075 --contrast1075` before the final `C:/xampp/mysql/bin` argument in the primary native command.

Headless Chromium is the executed browser. External requests were blocked, so captures use fallback fonts and legacy Font Awesome icons may be absent; new topbar/back/funnel controls use inline SVG and calendar arrows/deadline dots render locally. The existing 2.82MB campus PNG is preserved, with no new full-screen bitmap. Actual CDN typography and other browser engines require the manual checks below; no screen-reader session is claimed.

## Tests and limitations

The complete native/browser run and focused follow-up logs, assertion JSON and individual CLI logs are under `tests/v10-batch1075-results/`. Browser checks are against copied actual PHP pages/APIs on a random loopback port with private sessions and fresh MariaDB 10.4.32. Each DB connection verifies the owned private datadir; frozen manifest parity is checked. Installed `config.local.php` is excluded from copies; fixture provider credentials are empty. Native fixtures construct the already-frozen schema from its manifest; no application migration or installed/production DB access occurs.

| Executed command / suite | Evidence / result |
|---|---|
| `php -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --browser1075 C:/xampp/mysql/bin` | `native-browser.log`: PASS 241 cumulative native/HTTP/legal assertions; PASS 1,739 browser assertions, zero failures/exceptions. Includes 143 workflow atomicity/rollback/concurrency/history cases and 17 multi/sole-Admin governance cases. Cancel's saved draft/title and unchanged publication pointers/content checked in the private DB. |
| Focused calendar/Student document follow-up | `focused-calendar.log`, `focus/browser-results.json`: PASS 132 browser assertions, zero failures/exceptions; 166 cumulative native/governance assertions (overlap with the main run). Real marked deadline selection/details, Adviser sorting, Student search/sort/pills, no-match states, injected service failures and reload recovery. |
| Focused final contrast/card fit | `contrast.log`, `contrast/browser-results.json`: PASS 57 assertions, zero failures/exceptions; 164 overlapping native/governance assertions. Desktop/mobile selected-pill contrast meets 4.5:1, initials remain visible, compact tables fit their own containers; final mobile cards stack without inheriting the old Adviser table width. |
| `node tests/v10-batch1075-regression.cjs` | `cli-results.json` and per-suite logs: **25 of 27 pass**, **2 legacy harness failures**; 128/128 application PHP/JS syntax checks pass. The aggregate command intentionally returns nonzero for the two failures. |

Passing CLI suite counts (mixed case/assertion units, not unique tests): auth flows 52; security boundaries 46; document workflow 48; research group/notifications 197; academic API 1,343; hardening 114; calendar/deadlines 190; extraction 37; document summary 297; summary transport 41; archive scope 85; CSV 93; Batch 2 readonly 71; Batch 3 filters 150/invitations 130/deadlines 50; Batch 4 security 91 total; Batch 4.5 169; routing 30; Batch 5b sessions 36/upload validation 25; Batch 6 validation 73; notification API 213; Support 144; privacy 10. Full logs retain exact output.

The two failing old harnesses are **not passing tests**: `session-idle-audit.php` extracts current auth functions but lacks the legal-acceptance helper mock, and `logout-audit.php` lacks the current HTTPS helper mock used by logout. Neither runtime file nor those old harnesses was changed. The modern Batch 5b session fixture passes 36 cases; the actual HTTP/browser idle test returns `401`, `session_expired` and the expected sign-in message (`after/idle-evidence.json`). The previous browser timeout/selector issues occurred during harness development and were corrected before the passing final evidence. Persistence lifecycle/post-24h tests are unrun because that blocked feature is not implemented.

## Outstanding decisions and manual validation

Only the persistent-authentication subfeature is blocked. A separate explicit review/approval of a concrete durable token-store design and deployment guarantees is needed before any implementation. The unavailable entry must remain disabled until then; no scheme using reset/invitation/audit tables or an extended session cookie alone is acceptable.

For manual validation, use a local non-production v10 fixture with representative Student/assigned Adviser/Admin accounts: confirm every action against real role permissions; expand long/missing academic/group and document details; exercise search/sort/clear/status selection and file/version/review dialogs. Review tabs, saved/unsaved Cancel and pending-candidate approvals with two eligible Admins and a sole Admin. Confirm real scheduled/group deadlines and notification read actions; inspect actual Montserrat/Font Awesome loading in the supported deployed browser engines. Keyboard focus/labels were automated; a real screen-reader read-through remains manual. No live provider/mail, real-account password reset/archive or production hosting validation was attempted. No schema, production data, deployment or Git publication was authorized or performed.

## Changed-file inventory and final diff

Application files are grouped above by UI scope. New runtime files are `assets/css/batch1075.css` and `assets/js/legal-management.js`. Test additions are `tests/v10-batch1075-ui.cjs` and `tests/v10-batch1075-regression.cjs`; `tests/v10-batch10-http.php` adds opt-in UI hooks and verified disposable fixtures. `.gitignore` exposes only the two new test scripts; diagnostic screenshots/logs remain ignored local evidence. This report is a new untracked deliverable. The fifteen baseline untracked files remain untouched.

Final `git diff --check` returns 0. Final application/test syntax is **131/131** (`final-syntax.json`), with the last harness/PHP hook edits additionally parsed/executed by the focused run. The primary matrix plus focused follow-ups report **1,928 passing browser assertion executions**; coverage overlaps and this is not a count of distinct tests. HEAD and branch remain the baseline. Auth/session/workflow/schema-contract files have no diff.

The exact final tracked `git diff --stat` follows. Git's ordinary stat omits the five task additions (this report, two runtime assets and two test scripts) listed above. All edits remain unstaged and uncommitted.


```text
 .gitignore                        |  2 ++
 admin_legal_policies.php          | 15 +++++-----
 assets/css/admin-legal.css        |  9 ++++++
 assets/css/prism-workspace.css    |  1 +
 assets/css/style.css              | 15 ++++++++++
 assets/js/academic-fields.js      |  2 --
 assets/js/admin-management.js     |  6 ++++
 assets/js/adviser-dashboard.js    | 35 ++++++++++++----------
 assets/js/dashboard-deadlines.js  |  9 ++++++
 assets/js/documents.js            | 14 +++++++--
 assets/js/ierbprog.js             |  3 ++
 assets/js/notification-history.js |  1 +
 assets/js/prism-ui.js             | 51 +++++++++++++++++++++++++++++++-
 assets/js/prism-workspace.js      | 40 +++++++++++++++++++++++++
 assets/js/role-portal.js          | 19 +++++++-----
 dashboard.php                     | 61 ++++++++++++++++++++-------------------
 documents_api.php                 |  4 +++
 help_support.php                  |  2 +-
 ierb_api.php                      |  9 ++++++
 includes/legal_header.php         |  2 +-
 includes/prism-navigation.php     |  1 -
 login.php                         |  5 +++-
 research_adviser.php              | 13 +++++----
 role_portal.php                   |  4 +--
 tests/v10-batch10-http.php        | 17 ++++++++++-
 25 files changed, 262 insertions(+), 78 deletions(-)
```

Tracked modified files, exact inventory:

- `.gitignore`
- `admin_legal_policies.php`
- `assets/css/admin-legal.css`
- `assets/css/prism-workspace.css`
- `assets/css/style.css`
- `assets/js/academic-fields.js`
- `assets/js/admin-management.js`
- `assets/js/adviser-dashboard.js`
- `assets/js/dashboard-deadlines.js`
- `assets/js/documents.js`
- `assets/js/ierbprog.js`
- `assets/js/notification-history.js`
- `assets/js/prism-ui.js`
- `assets/js/prism-workspace.js`
- `assets/js/role-portal.js`
- `dashboard.php`
- `documents_api.php`
- `help_support.php`
- `ierb_api.php`
- `includes/legal_header.php`
- `includes/prism-navigation.php`
- `login.php`
- `research_adviser.php`
- `role_portal.php`
- `tests/v10-batch10-http.php`
