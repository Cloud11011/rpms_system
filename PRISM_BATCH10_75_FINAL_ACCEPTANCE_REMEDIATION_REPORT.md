# PRISM Batch 10.75 — Final Acceptance Remediation

Final recommendation: **GO for the authorized Batch 10.75 scope.**

**BATCH 10.75 ACCEPTANCE PASSED — READY FOR USER-AUTHORIZED COMMIT AND DEPLOYMENT.** No commit, push, merge, tag or deployment was performed. The 24-hour persistence feature remains blocked and explicitly unavailable.

## Scope and references

Branch: `prism-v10-b10-75-ui-update`. Implementation HEAD remains `11d02d3463ec738b8122bc8289c86b8b10eb94a3`. Batch 10.5 behavioral baseline: `5b78211a7e63300afacd03b244a6f63bba2da841`.

Primary references were all 15 pages of `C:/xampp/htdocs/PRISM_Batch10_75_UI_Update_Master_with_Sol_Prompt.pdf`, `PRISM_BATCH10_75_UI_UPDATE_REPORT.md`, and the independent final acceptance NO-GO findings supplied in this session. Document prompts were treated as specification context; the user's explicit remediation scope and restrictions governed execution. The older implementation report describes its historical precommit state and failed legacy harnesses; this report supersedes those results for final acceptance. Its original implementation files are already tracked at the implementation HEAD.

The earlier pasted user request describes a read-only acceptance audit. The later Final Acceptance Remediation request authorizes the narrow local corrections performed here; its prohibitions on production operations and Git publication remain in force.

The exact blockers were incomplete tablet evidence, obscured/cropped captures (especially UI-01/UI-03/UI-07), insufficient actual keyboard/Actions validation, three ignored runner dependencies, two baseline harness failures, and an incomplete explicit production release inventory. Existing untracked prior-batch reports/schema contract and the original `tests/v10-batch105-results.json` were preserved.

## Corrections

- Removed the legacy frozen-first-column stacking context from compact cards. Open Actions/filter/profile menus now rise above the Admin sidebar's isolated content layer. This closes confirmed mobile menu obscuring without changing their controls or permissions.
- Removed the old first-cell maximum width from compact cards. Student My Documents inherited a 300-pixel table-cell cap that squeezed filename/status columns on desktop/tablet. The corrected 768-pixel fixture has a 655-pixel cell within a 657-pixel table; a new acceptance assertion checks available card width at every size/theme.
- Clamped the Admin/Adviser profile dropdown within the viewport and repositioned it on resize/scroll. The confirmed mobile dropdown previously extended beyond the left edge.
- Constrained the existing dashboard toolbar/group width below 400 pixels so its controls wrap. With actual fonts/icons loaded, the 320-pixel dashboard had a measured 374-pixel document width in both themes; the corrected targeted fixture measured 305 pixels (with the vertical scrollbar) and no out-of-bounds elements. Controls and their order are preserved.
- Removed the repository badge's inherited left indent below 360 pixels. Actual 320-pixel captures showed status words splitting across many lines; the badges now use the available row width and retain readable whole words. Colors, labels and filtering are unchanged.
- Increased the existing portal header spacing below 360 pixels. At 320 pixels the wrapped fixed navigation covered part of Adviser record-page and Student/Adviser dashboard headings. Targeted checks pass in both themes: record-page heading y=202/nav bottom y=188; Adviser dashboard y=208/188; Student dashboard y=196/192. A new acceptance assertion verifies narrow-screen portal headings stay below fixed navigation.
- Added Escape closure, Tab/Shift+Tab containment, and return focus to the existing IERB note dialog. Its save workflow is unchanged.
- Corrected the private browser fixture to supply the same role/name/email session fields as real login; stopped asynchronous requests from the previous role before rotating its fixture session. These are test-only corrections.
- Replaced cropped beyond-viewport evidence with actual viewport pixel strips, stitched over their entire content range. The fixed Student navbar's full bottom offset is excluded from subsequent strips. Navigation overlays are collapsed, and actual public Montserrat/Font Awesome resources are cached for isolated final captures. Dropdown/modal companions retain the actual viewport.
- Exposed the three required audit sources through narrow `.gitignore` exceptions. Added explicit missing-dependency errors, portable PHP selection, baseline source selection, clean-checkout verification, and the optional public-asset cache helper. Follow-up browser modes now create their companion screenshot directory on a clean checkout.

Only four production files changed during remediation: `assets/css/batch1075.css`, `assets/css/prism-workspace.css`, `assets/js/ierbprog.js`, and `assets/js/prism-workspace.js`. No schema, authentication, logout, cookie, session, workflow service, legal-governance, retention or deletion behavior was changed.

## Final UI acceptance matrix

| Item | Final acceptance | Final evidence and preserved behavior |
|---|---|---|
| UI-01 | PASS | All 12 role/route combinations at six sizes in both themes; expanded details and first/last-row Actions at desktop/tablet/mobile; authorized actions preserved and unobscured. |
| UI-02 | PASS | Complete light/dark login card at all six sizes; campus image, labels, password visibility, notices and legal controls preserved; focused heading contrast passes. |
| UI-03 | PASS within approved scope | Admin, Adviser and Student profile dropdowns, theme, bell, shared tour and unavailable-persistence explanation tested by actual keyboard input at three sizes in both themes. No persistent credential or cookie expiry change. 24-hour persistence remains BLOCKED. |
| UI-04 | PASS | Scoped Adviser queue, synchronized status/counts, review actions, equal/stacked calendar and notification panels; real private-fixture deadlines and personal unread counts tested. |
| UI-05 | PASS | Back destinations, Account/security access, Help header/theme and search controls retained; complete role/route captures. |
| UI-06 | PASS | Policy cards/history and keyboard tabs; unsaved Cancel/Continue/Discard at desktop/tablet/mobile in both themes; saved draft/title and publication pointers preserved; independent native governance suite passes. |
| UI-07 | PASS | Full heading, View All, preview summary, four status controls and all five cards captured at every size/theme; filename/version/uploader/status context retained; accessible complete filenames verified. |
| UI-08 | PASS | Full Student progress page at every size/theme, including official stage/ring/PI, six configured stages, requirement cards, history heading/records and pagination. |
| UI-09 | PASS | Complete funnel heading, helper, count cards and selectable stage controls at every size/theme; existing student-record and Stage 3+4 definitions preserved. |

## Screenshots and keyboard evidence

[Complete final gallery](tests/v10-batch1075-results/remediation/index.html) contains **374 unique final screenshots**, with original-resolution image links and filename/width/theme filtering. [Capture manifest](tests/v10-batch1075-results/remediation/evidence-index.json) records dimensions, SHA-256 hashes, component bounds, capture method, collapsed navigation and actual action evidence. All screenshot PNGs decoded successfully, their dimensions match the recorded full component bounds, all navigation checks passed, and all required role/route/size/theme files are present.

The main screenshot matrix is 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 and 320×700, each in light and dark mode. The expanded-card/Actions/topbar matrix is 1366×768, 768×1024 and 390×844, each in both themes. These are browser CSS viewport dimensions; a full-page/component artifact is taller than its viewport when needed. Closed navigation and separately captured open dropdowns avoid obscuring the underlying component evidence. No layout styling was changed for captures. Final captures use the application's actual cached public typography/icons; all other external browser requests remain blocked.

| Confirmed blocker | Before correction | Final capture |
|---|---|---|
| UI-01 mobile Actions hidden by sidebar | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI01-actions-1001-documents.php-390-light.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/UI01-actions-1001-documents.php-390-light.png) |
| UI-03 mobile profile clipped/off-screen | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI03-1001-390-light-profile.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/UI03-1001-390-light-profile.png) |
| UI-07 heading absent from capture | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI07-390-light.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/UI07-390-light.png) |
| UI-03 dashboard toolbar overflow at 320 pixels | [Before](tests/v10-batch1075-results/remediation/before-remediation/diagnostic-dashboard320-false.png) | [After](tests/v10-batch1075-results/remediation/interaction/diagnostic-dashboard320-false.png) |
| UI-07 status words split at 320 pixels | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI07-320-before-wrap-light.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/UI07-320-light.png) |
| UI-01 Student tablet card constrained by old cell width | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI01-Student-768-before-cell-width.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/UI01-expanded-1003-student.php-768-light.png) |
| UI-01 Adviser heading covered at 320 pixels | [Before](tests/v10-batch1075-results/remediation/before-remediation/UI01-Adviser-320-before-heading-spacing.png) | [After](tests/v10-batch1075-results/remediation/final-acceptance/1002-admin_students.php-320-light.png) |

Original Batch 10.5 screenshots are retained under `tests/v10-batch1075-results/before/`. The preserved pre-remediation interaction evidence contains 18 failed assertions; the focused corrected run passed 958 assertions. Partial/interrupted development captures and failure files are not final acceptance evidence; the final gallery uses only the successful `final-acceptance/browser-results.json` inventory.

The additional confirmed 320-pixel toolbar defect is recorded in `before-remediation/dashboard320-diagnostics.json`, the two `before-remediation/diagnostic-dashboard320-*.png` captures, and `before-remediation/full-browser-before320-fix.json` (2,705 assertions, only two overflow failures). Corrected targeted measurements and viewport captures are under `interaction/dashboard320-diagnostics.json` and `interaction/diagnostic-dashboard320-*.png`; the fresh final 320-pixel full-page captures replace the defective evidence. Private fixture data differs between native-only and complete HTTP workflow phases, so layout comparisons should not be read as changes to real record states/counts.

The 320-pixel repository badge wrapping defect and correction are shown in `before-remediation/UI07-320-before-wrap-light.png` and the final `UI07-320-light.png` (with dark counterparts); the targeted corrected full-component capture is `interaction/diagnostic-UI07-320-false.png`.

Actual Chromium CDP keyboard input exercised ArrowDown, Home, End, Tab, Shift+Tab, Enter and Escape. First and last-row Actions were checked for viewport positioning and unobscured hit testing. Modal focus, background exclusion, Escape cancellation and return to the Actions trigger passed. Native dialogs may send Tab to browser chrome; background application controls remain inert. Custom dialogs wrap first/last controls.

The final action inventory contains **36 role/route/action executions** covering these rendered labels: Add comment, Add note, Admin Override, Approve, Archive Adviser, Archive Student, Archive student, Delete, Deny, Download, Edit Adviser, Edit Student, Edit entry, Generate AI Summary, Open document, Preview, Return to Student for Revision, Review Document, Send follow-up email, Upload Revision, Version history, View. Disabled safeguards, Adviser exclusions and Student exclusions were checked. View/Download links received actual Enter activation with navigation intercepted to retain the fixture page, then their authenticated endpoint returned HTTP 200 with file headers recorded; this does not claim a browser PDF viewer/download-manager UI test. Upload Revision navigated to the existing form and was cancelled without upload. Review, approve/deny/comment/override/delete/archive/return and edit dialogs were cancelled without destructive confirmation. The final private database comparison proves document workflow/version fields and official student stage/status/requirements did not change through these cancelled Actions. No real mail/provider or non-test records were used.

Detailed labels, results, role IDs and endpoint headers: [Final browser assertions](tests/v10-batch1075-results/remediation/final-acceptance/browser-results.json) and [interaction evidence](tests/v10-batch1075-results/remediation/final-acceptance/interaction-results.json).

## Baseline harness findings and regression packaging

The runner directly invokes all 27 named audit files. The three missing files were existing CLI-only source tests hidden by the broad `/tests/*` rule, rather than generated output. `security-audit.php` uses namespaced PDO/security doubles without application bootstrap or a real database. Idle/logout tests extract actual source into isolated namespaces and mock configuration/session/cookie functions. All fixture identities/strings are synthetic; these files contain no credentials, database dumps or environment secrets and are safe to include as test sources.

The idle test lacked mocks for already-existing legal acceptance and session-generation helpers. It now models an already-accepted user while dedicated legal tests cover governance. Its frozen-clock expectations remain intact: 0/1799/1800 seconds permit access; 1801/7200 seconds expire before any refresh/query, with exact HTML redirect/API response assertions.

The logout test lacked the already-existing HTTPS helper and expected only the session-cookie deletion. It now models HTTP/HTTPS and asserts deletion of the public generation marker as well as the session cookie, including the anonymous/no-session-cookie case and exact scope/Secure/HttpOnly/SameSite attributes. Production behavior was unchanged.

| Comparison | Before correction | Corrected harness |
|---|---|---|
| Batch 10.5 idle | Baseline failure confirmed by independent audit | PASS, 25 assertions |
| Batch 10.75 idle | Original harness failed | PASS, 25 assertions |
| Batch 10.5 logout | Baseline failure confirmed by independent audit | PASS, 28 assertions / four cases |
| Batch 10.75 logout | Original harness failed | PASS, 28 assertions / four cases |
| Batch 10.75 complete CLI regression | Historical 25/27 | PASS, 27/27 in clean candidate-source checkout |

[Baseline/current comparison](tests/v10-batch1075-results/remediation/baseline-harness-results.json) exports only `config.php`, `login_process.php` and `logout.php` from the exact Batch 10.5 commit using `git show`; it does not switch branches or alter runtime files. The baseline comparison covers the two disputed harnesses, not a claimed rerun of the entire 27-suite historical baseline.

[Clean-checkout results](tests/v10-batch1075-results/remediation/clean-checkout-results.json) were produced from Git-listed source plus the explicitly proposed new audit/cache/packaging sources, with installed production dependencies matching `composer.lock`. No installed `config.local.php`, ignored helper, backup or runtime database was copied. Every snapshot source hash matches its final workspace source. The new files are intentionally uncommitted under the user's restriction; they must accompany the later user-authorized source commit. Checking out the existing unchanged HEAD alone still omits those proposed files.

## Final validation

| Suite | Final result | Evidence |
|---|---|---|
| Clean candidate-source CLI regression | 27/27 PASS; 128/128 production PHP/JS syntax checks | `clean-checkout-cli.log`, `clean-checkout-results.json` |
| Complete native/HTTP/UI acceptance | PASS; 2727 browser assertions; zero failures/exceptions; 243 cumulative native/HTTP/governance assertions | `final-acceptance-browser.log`, `final-acceptance/browser-results.json` |
| Fresh focused calendar/sort/search/empty/error/recovery | PASS; 132 browser assertions, zero failures/exceptions | `final-focus-browser.log`, `final-focus/browser-results.json` |
| Fresh contrast, initials and compact-card fit | PASS; 57 browser assertions, zero failures/exceptions | `final-contrast-browser.log`, `final-contrast/browser-results.json` |
| Persistent-deletion baseline protection | PASS; 203 native persistent evidence/live drift/API/retention assertions (205 cumulative), plus 12 real HTTP proof/session/origin/tamper/stale/diagnostics assertions (14 cumulative) | `deletion-native.log`, `deletion-http.log`, `deletion-results.json` |
| Corrected baseline/current idle/logout | All four executions PASS | `baseline-harness-results.json` |
| Protected production source and release inventory | 19 protected files unchanged from implementation HEAD; production hashes/exclusions verified; locked vendor parity | `release-integrity.json`, deployment manifest |

Counts are assertion executions with overlapping coverage, not a count of unique tests. Browser total for the three final phases is **2916**. The separate targeted 958-assertion pass is additional overlapping evidence. Final `git diff --check` returns 0; both changed CJS tests, all changed/new PHP tests and all three Python source helpers pass syntax checks.

Environment: PHP 8.2.12, Node 25.0.0, Python 3.11.4 with Pillow, MariaDB 10.4.32, installed headless Chromium. Every native DB fixture verifies its owned private datadir and frozen v10 manifest; every HTTP root is disposable and excludes installed private configuration. No production database connection or application schema migration occurred. Native deletion and HTTP deletion suites ran against separate fresh databases because their original fixtures share account IDs; their production assertions were not weakened.

Reproduction (Windows/XAMPP; use a non-production workspace):

```powershell
python tests/v10-batch1075-cache-assets.py
node tests/v10-batch1075-regression.cjs
python tests/v10-batch1075-packaging.py --native105
C:/xampp/php/php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --browser1075 --acceptance1075 C:/xampp/mysql/bin
C:/xampp/php/php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --browser1075 --uionly1075 --focus1075 C:/xampp/mysql/bin
C:/xampp/php/php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --browser1075 --uionly1075 --contrast1075 C:/xampp/mysql/bin
python tools/build-batch1075-manifest.py
```

The cache fetches only the already-used public CDN fonts/icons and requires network access once. Acceptance captures require Python/Pillow and an installed Chrome/Edge; native suites require local MariaDB binaries and required PHP extensions. `PRISM_PHP_BINARY` and `PRISM_PYTHON_BINARY` permit explicit executable selection. Locked Composer dependencies must be installed before testing. Final supplemental runs here used disposable source snapshots so historical evidence and the original Batch 10.5 result JSON were preserved.

## Release artifact integrity and Hostinger payload

[Explicit production manifest](PRISM_BATCH10_75_DEPLOY_MANIFEST.json) lists SHA-256 and size for **25 production overlay files** relative to Batch 10.5, **174 full runtime files**, and **73 locked dependency files**. `smalot/pdfparser v2.12.5` and `symfony/polyfill-mbstring v1.43.0` exactly match `composer.lock`; licenses are retained. Batch 10.75 CSS, legal-management JS, test scripts and their production references are present as Git-listed sources. New test sources are explicitly exposed for the authorized future commit.

Deploy only the listed production overlay to a verified matching baseline, plus the complete locked `vendor/` when missing or different. For a complete source installation use the separate full runtime inventory. PHP, JS and CSS must move together for deployment/rollback. Preserve hosting private configuration, database, uploads, reports/runtime state and existing approved cron/maintenance tools. Exclude all tests, evidence, fixtures, reports/manifests, backup directories, schema/migration/build tools, `.git`, `.env`, `config.local.php` and storage runtime data. Include the tracked `.htaccess` protections in a full installation, including `storage/.htaccess`; verify Hostinger honors them. The manifest records PHP/extension/server prerequisites, the exact Composer production build command, external UI dependencies and asset-cache invalidation requirements. The existing persistent-deletion proof must remain valid for the actual hosting context and otherwise fail closed. No deployment or hosting operation was performed.

Exact overlay files:

- `admin_legal_policies.php`
- `assets/css/admin-legal.css`
- `assets/css/batch1075.css`
- `assets/css/prism-workspace.css`
- `assets/css/style.css`
- `assets/js/academic-fields.js`
- `assets/js/admin-management.js`
- `assets/js/adviser-dashboard.js`
- `assets/js/dashboard-deadlines.js`
- `assets/js/documents.js`
- `assets/js/ierbprog.js`
- `assets/js/legal-management.js`
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

## Changed files and review state

Tracked remediation diff against implementation HEAD:

```text
.gitignore                         |   5 +
 assets/css/batch1075.css           |   7 ++
 assets/css/prism-workspace.css     |   6 +-
 assets/js/ierbprog.js              |  11 ++
 assets/js/prism-workspace.js       |  12 ++
 tests/v10-batch10-http.php         |  27 ++++-
 tests/v10-batch1075-regression.cjs |   7 +-
 tests/v10-batch1075-ui.cjs         | 227 ++++++++++++++++++++++++++++++++++---
 8 files changed, 280 insertions(+), 22 deletions(-)
```

New proposed test/build sources, outside that tracked diff stat:

- `tests/session-idle-audit.php` — existing ignored source exposed and narrowly corrected.
- `tests/logout-audit.php` — existing ignored source exposed and narrowly corrected.
- `tests/security-audit.php` — existing ignored source exposed unchanged.
- `tests/v10-batch1075-packaging.py` — candidate clean-checkout, baseline and private deletion-suite verification.
- `tests/v10-batch1075-cache-assets.py` — optional public typography/icon cache for faithful isolated evidence.
- `tools/build-batch1075-manifest.py` — reproducible production inventory generator.

New release documents are this report and `PRISM_BATCH10_75_DEPLOY_MANIFEST.json`. Generated screenshots, logs, cache, gallery and evidence drivers remain ignored and excluded from deployment. All task edits remain uncommitted; branch and HEAD remain unchanged. Existing unrelated work was not discarded or edited.

## Remaining limitations

The requested acceptance blockers are closed. The 24-hour persistence feature remains blocked under the frozen schema and unavailable; no lifecycle or post-24-hour tests are claimed. Existing preview/student-record count definitions and configured six-stage labels intentionally remain as documented in the master specification/implementation report. Real screen-reader use, other browser engines, real mail/AI providers, production CDN reachability and Hostinger environment/deployment checks were not performed. Confirmation dialogs were exercised and cancelled; destructive confirmations were limited to their pre-existing isolated native/HTTP tests. Those limits do not justify production behavior changes or deployment during this remediation.
