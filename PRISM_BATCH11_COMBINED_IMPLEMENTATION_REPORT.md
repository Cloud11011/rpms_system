# PRISM Batch 11 — Combined implementation report

Date: 13 October 2026, Asia/Manila. Local implementation only. **No commit, push, merge, tag, deployment, migration, live configuration change or production database write was performed.**

## Release assessment

The independently authorized UI work and safe integration fixes are implemented. Existing override powers, account lifecycle safeguards, frozen v10 schema, prior versions/history, role scopes, official-stage rules and the 30-minute inactivity boundary are preserved. The 24-hour persistence control remains explicitly unavailable.

**Batch 11 has reference limitations; this is not an unconditional release acceptance or a claim that the whole system is audited.** The inaccessible external Quick Tour artifact could not be reviewed or integrated. The user explicitly approved mandatory atomic audit logging for existing overrides; that change is implemented and verified below. A separate Batch 12 audit/release review remains outside this task.

## Specification and baseline

- Read all 26 pages of `C:/Users/khirz/Downloads/PRISM_Batch11_Final_UI_Admin_Override_Sol_Master.pdf`: the B11-01–23 register, five Admin Override requirements, preservation/conflict notes, Sol execution prompt and all 17 appendix images. Embedded instructions were treated as specification material subordinate to the user's request and explicit restrictions.
- [Extracted complete text](tests/batch11-results/master-spec.txt); [reference appendix](tests/batch11-results/references/). JPEG and losslessly decoded RGB images were inspected, including all four prototype-code pages. No prototype script was executed. The demo timer and placeholder download URL were not copied.
- Branch: `prism-v10-b10-75-ui-update`. Exact baseline HEAD: `ec160354cba597ce350a19b425e3cbe5f004b674`, `Complete Batch 10.75 UI acceptance remediation`. The tracked tree was clean when work began. Existing untracked earlier-batch reports/schema contract and deletion-result evidence were preserved.
- The user identifies Batch 10.75 as accepted/deployed. Local Git history and the acceptance report verify the local release baseline; this task did not connect to Hostinger or independently attest the currently deployed remote bytes.
- `tests/batch11-baseline.cjs` exports accepted production sources using `git show` without checking out a branch or importing installed private configuration. Only disposable test-harness hooks are added to that export. [Baseline export identity](tests/batch11-results/baseline-source.json) and [baseline execution log](tests/batch11-results/baseline-browser.log).
- UI-01–09 are regression protections. New work supersedes only the expressly requested public/login composition, compact presentation, navigation/profile placement, calendar states and AI banner elements. Existing workflow services and protected deletion contracts are unchanged.

## Requirement evidence register

All browser evidence uses synthetic Student/Adviser/Admin identities and a verified private MariaDB datadir with an isolated PHP HTTP root. Final screenshots are real Chromium viewport captures; capture metadata and assertions are in the browser result JSON. Both themes and widths 1920, 1366, 1024, 768, 390 and 320 are exercised. Reference data/counts/names were never fabricated into the application.

| Requirement | Status | Implementation and preservation | Evidence |
|---|---|---|---|
| B11-01 Public landing/footer | PASS | Anonymous `index.php` renders a public hero, six stage summaries and login CTA through `includes/public_landing.php`. Authenticated role/password/legal/profile routing and invalidated/expired-session login redirects are retained. Static shared institutional footer is reused; no private metrics/identities are rendered. Existing authenticated footers remain single instances. | `index.php`, public CSS/JS; `index.php-WIDTH-THEME.png`; route/session suites; final browser public-stage/one-footer assertions |
| B11-02 Split login | PASS | Existing rounded card, fields, processing URL, email-domain hint, password visibility, error/success messages and legal/forgot-password links remain. Campus image/branding occupy the left panel; login remains on the right and stacks on phones. Existing theme follows `prismTheme`. | `login.php`, `batch11-public.css`; `login.php-WIDTH-THEME.png`; 52 auth-flow checks; final browser original-fields/theme/fit checks |
| B11-03 Hover sidebar | PASS | Delayed hover expansion only for a fine pointer on desktop. Hover collapse defers while sidebar focus is held. Click pin/toggle, keyboard/Escape, touch and mobile overlay behavior remain; hover state does not overwrite the user's stored choice. Existing reduced-motion rules apply. Hover delays never simulate backend results. | `dashboard-sidebar.js`; actual pointer enter/leave tests, menu Arrow/Home/End/Tab/Escape tests; mobile route captures |
| B11-04 Stage distribution | PASS | Smaller two-by-two cards retain large counts, all four categories, group-vs-student semantics and live stage filters. No metric query or grouping definition changed. | `batch11.css`; `stage-calendar-WIDTH-THEME.png`; dashboard native/backend regression; final dense-card assertion |
| B11-05 Quick actions/profile | PASS | Compact topbar keeps theme, real unread count, question-mark Tour, explicitly unavailable persistence and profile. Hover/focus tooltips have accessible names. Admin/Adviser menus retain Account, Activity, Help & Support, Logout; Tour/Guide leave only those profile menus. Guide is reachable from the Tour's introduction. | `prism-workspace.js`, `prism-guidance.js`, navigation partial; actual profile/Tour keyboard tests at every size/theme |
| B11-06 Admin calendar | PASS | Visible deadline/Today/selected legend; real deadline dates are colored, Today has a distinct accent and selection is outlined. Existing calendar detail actions, date keys, deadline API scopes and reminders remain. | `dashboard-deadlines.js`, `batch11.css`; stage/calendar captures; 190 deadline checks and browser calendar assertions |
| B11-07 Heading gap | PASS | Reduced topbar-to-overview spacing on Admin Dashboard only. Existing layout/header/menu positioning and phone wrapping remain. | `batch11.css`; `1001-dashboard.php-WIDTH-THEME.png`; viewport checks |
| B11-08 Animated AI banner | PASS with reference limitation | Gradient/orbs/sparkle decoration uses pointer-transparent layers and reduced-motion suppression. Existing Summary/Full formats use the real `reports_api.php?action=ai_report` POST. Busy blocks repeats, errors restore retry, Ready exposes only the actual returned report download. Cancellation sends no request. Missing MP4 means exact temporal matching to that recording is unverified. | `dashboard.php`, CSS; actual held pending request/repeat check, injected HTTP failure/retry, real fallback PDF with `%PDF-` header and HTTP 200; `ai-ready.png`; original report privacy/transport suites |
| B11-09 Dashboard IERB monitor | PASS | Compact monitor cards retain identity/group/course, stage, live progress, status and on-demand metadata. Existing Send Follow-up/Open Documents/Admin Override nodes and handlers remain; ellipsis Actions entries include icons and labels. | `dashboard.php`, shared `compactRecord`/`actionMenu`; `monitor-WIDTH-THEME.png`; accessible menu/role tests |
| B11-10 Repository spacing | PASS | Description/filter/list spacing and file-row density refined. The five-item preview, filter totals, workflow labels and file links are unchanged. | `batch11.css`; `repository-WIDTH-THEME.png`; document workflow/count regressions |
| B11-11 Resources/navigation | PASS | Smaller shared resource typography/spacing. Navigation-only IERB details becomes a direct existing portal link with `noopener noreferrer`; SDG and Research Agenda distinct resources remain. | `includes/research_resources.php`, CSS; `resources-WIDTH-THEME.png`; source/route review |
| B11-12 Admin record lists | PASS | Structured compact name/research/group/protocol/status columns; academic fields remain in on-demand details. Original action nodes, Student/Adviser identity, PI/protocol, assignment, invitation and lifecycle tools retain their handlers/scopes. | `admin-management.js`, `prism-ui.js`, CSS; Admin Student/Adviser captures; 1343 academic API and 169 archive/security checks |
| B11-13 Archived Accounts | PASS | Archived lists now use compact cards while keeping selection checkboxes, hold reasons, archive/retention dates, object metadata and restore/hold/purge actions. Permanent deletion remains unavailable when deployment verification is missing, and retained server safeguards govern all execution. | [Final phone archive capture](tests/batch11-results/archive/archive-320-false.png), focused final archive captures/assertions; shared row/menu code; 203 persistent-delete assertions, 12 persistent-delete HTTP assertions; 19 protected files unchanged |
| B11-14 Document Submissions | PASS | Compact filename/student/category/status rows and ellipsis menu retain uploads, filter modes, version history, ordinary review, Return/Revision, existing formal submission, preview/download, AI summary and Admin Override. Actions come from existing backend flags. | `documents.js`, shared CSS/JS; Admin/Adviser document captures; ordinary native/HTTP three-cycle workflow tests; explicit override HTTP tests |
| B11-15 Rows per page | PASS | IERB, AI History and Generated Reports have labeled 5/10/25 controls; the server whitelist bounds SQL LIMIT and retains role scope/totals. Page-size changes reset to page one. Shared pagination uses the server's returned limit, so range labels and page counts agree. Default remains 10; preview remains capped at 10. | `includes/pagination.php`, `recordPager`, IERB/AI/report scripts; bounded positive/invalid-size HTTP tests; browser controls for all three pages |
| B11-16 Notifications | PASS | Compose/history cards use clean two-panel layout, collapsing to one column on narrower screens. Recipients, message/schedule controls, validation, draft/history filters and existing sending/lifecycle handlers are preserved. | Notification captures; `batch11.css`; 213 notification API checks and real override recipient-copy checks |
| B11-17 Legal Policies | PASS | Published-policy/history shells, tabs and action placement refined with existing governance forms intact. Dirty Cancel still offers Continue Editing/Discard; actual browser tests preserve draft input and return without a publishing mutation. | Legal captures; `admin_legal_policies.php`, CSS; actual browser dirty-cancel test; 17 native governance checks; immutable published-policy comparison after browser |
| B11-18 External Tour code | BLOCKED — reference unavailable | The embedded Drive link was attempted through the web tool twice and was inaccessible. No external source was downloaded/executed/integrated. Existing local Tour and Navigation Guide remain functional via the topbar. | [Provided artifact link](https://drive.google.com/file/d/1OeGyUNwqagESHnL1ZBKFYQDH7a-CntRU/view?usp=sharing); source page 12 image; browser Tour/Guide tests |
| B11-19 Adviser menu/queue | PASS | Consolidated profile menu and compact assigned-student document cards, status pills and original View/Review actions. Assigned-object scopes remain server-owned. | `adviser-dashboard.js`, navigation and shared code; Adviser captures; menu, review-scope and negative override tests |
| B11-20 Adviser side panels/records | PASS | Side-by-side notifications/calendar desktop layout, shared calendar conventions, mobile stacking and structured Student cards. Read controls, feedback and identity/assignment fields remain. | Adviser Dashboard/Student/Calendar captures; scoped API tests; no new privileged controls in Adviser menus |
| B11-21 Adviser IERB/navbar/hero | PASS | Shared IERB overview/list/actions and bounded rows control. Resources leaves the navbar while an authorized dashboard resource link remains beside the hero. Existing Review Documents CTA is an actual permitted route; it was preserved instead of recreated. | Navigation/shared shell/IERB code; Adviser IERB captures and live menu/role checks; original hero `href=documents.php` |
| B11-22 Student calendar/progress | PASS | Shared calendar legend/states and denser official-stage/journey presentation; green completed/neutral pending requirements remain. Six real stages, percentage, PI/protocol, RPMS authority and structured history stay intact. | `role-calendar.js`, shared CSS; Student progress captures and six-stage assertions; Student negative override tests |
| B11-23 My Documents defect | PASS — historical fix verified | Exact accepted-source export passed Student-scoped usable-row-width, search, status filters, details, history/actions and all six-stage tests at 1366/768/390/320 in both themes. The screenshot's narrow-column failure is not reproducible on accepted 10.75. Its known root cause is the legacy first-cell maximum-width constraint already removed in acceptance remediation. Preserve that fix; add explicit min/max width and wrapping protection plus fresh full six-width verification. Upload/revision/server/file histories are exercised by the real disposable HTTP workflow tests. | [Baseline browser assertions](tests/batch11-results/before/browser-results.json), [baseline log](tests/batch11-results/baseline-browser.log), final Student captures; accepted remediation report; ordinary upload/Return/revision/resubmission tests |

## Override inventory, integration and approval gates

The mandatory source-backed inventory was written before override mutation code changed: [PRISM_BATCH11_OVERRIDE_INVENTORY.md](PRISM_BATCH11_OVERRIDE_INVENTORY.md). It maps entry points, endpoints/methods, role/object scopes, source/target states, prerequisites/reasons, transport/session gates, transactions, version/history, audit/notification paths and existing/missing tests.

| Operation | Final classification | Exact affected endpoint/source | Integration result |
|---|---|---|---|
| Document review-status override | FIXED INTEGRATION / VERIFIED EXISTING | POST `documents_api.php?action=override_review`; `PrismUI.overrideDocument`; `documents.js` | Existing seven allowed target statuses retained; new contextual current/proposed-state confirmation, version/student/type/feedback context, optional expected-status mismatch refusal and non-string reason/status refusal. Existing write locks and snapshot checks preserved. No unlock of formally submitted or superseded documents. |
| Ordinary formal / existing force submission | FIXED INTEGRATION for ordinary Ready submission; VERIFIED EXISTING force endpoint | POST `documents_api.php?action=submit_to_rpms`; `PrismUI.submitToRpms`; `documents.js` | Reconnected the omitted ordinary Admin Submit to RPMS menu action, only when the existing `actions.submitToRpms` eligibility flag is true. Its existing confirmation/helper and server transition are reused. Approved/no-reason and unapproved/reasoned endpoint paths are verified separately. No new force-submit UI entry or privilege. Concurrent force submissions have one winner; normal Adviser approval still follows its separate workflow service. |
| Explicit official IERB override | FIXED INTEGRATION / VERIFIED EXISTING | POST `ierb_api.php?action=override`; `PrismUI.overrideStudent` | Current Student stage/status are rechecked under the existing lock; stale client/lock snapshots refuse safely. Existing six-stage/status vocabulary, purge gate, history and recipients are preserved. Context shows current official progress and group/adviser fallback metadata. |
| Progress changes through edit forms | FIXED INPUT INTEGRATION / VERIFIED EXISTING | POST `ierb_api.php?action=save`, `students_api.php?action=save`; IERB/Admin record forms | Non-string reasons no longer become the five-character string `Array`. Existing reason requirements, scopes, audit payloads and official-stage semantics are preserved. Admin override audit insertion now shares the save transaction under the explicit approval. |
| Archive, grace override, restore, holds, permanent purge/recovery | VERIFIED EXISTING | Archived Accounts, `admin-management.js`, `admin-retention.js`, `account_lifecycle_api.php`, protected retention/purge services | Original controls/handlers preserved in compact menus. No new bulk grace override, hold bypass, grace-period change, deletion eligibility or proof/verification behavior. Actual purge assertions run only in owned disposable fixtures. |
| Submitted-document deletion with an override reason | VERIFIED EXISTING / APPROVED AUDIT FIX | POST `documents_api.php?action=delete`; existing delete UI | Existing Admin-only locked-file reason and lifecycle behavior preserved. Override audit is inserted before DELETE and shares version-restoration rollback; file cleanup follows successful commit. It is distinct from review override and account grace override. |
| Mandatory atomic legacy override audit | APPROVED / IMPLEMENTED | `workflow.php::audit_override_atomic`; existing document/IERB/Student override routes | User approved: **"Approve atomic audit logging for existing overrides"**. Six existing override paths now insert their override audit in the caller's transaction, before commit. Failed insertion returns an error and rolls back; successful commit precedes notifications/setup delivery/file cleanup. Ordinary global `audit_log` behavior remains unchanged. |

### B11-OV-01–05 acceptance

| Requirement | Status | Evidence / limits |
|---|---|---|
| OV-01 Inventory/feasibility | PASS | Pre-edit inventory above; distinct ordinary/override/retention operations; confirmed malformed reason and stale IERB integration defects; explicit audit-policy decision record. |
| OV-02 Entry points/scope | PASS | Original nodes/handlers moved into compact Actions. Backend flags, POST role/object authorization, current-version/locked guards and purge locks remain authoritative. Admin/Adviser/Student views and direct negative API paths tested separately. |
| OV-03 Confirmation/reason/security | PASS within preserved baseline policy | Real keyboard dialog validation/cancel/focus; no requests on cancel; duplicate action guards; malformed reason/type checks. Active/expired/password/legal, origin and stale-generation checks exercised. Legacy missing generation headers remain compatible by existing policy; see risks. |
| OV-04 Transaction/history/downstream | PASS within approved audit scope | All six mandatory override-audit INSERT failures roll back every database table and preserve submitted file bytes. Successful retries append exactly one override audit and preserve existing recipient counts; a later DELETE failure also rolls back its audit. Existing concurrent/replay and version/history checks pass. Notification delivery remains post-commit under its preserved failure policy. |
| OV-05 Tests/decisions | PASS for executed scope; approval recorded | Disposable production endpoint tests, mandatory audit-failure injection/recovery, concurrency workers, real ordinary workflow/immutable-file cycles, UI cancellation and role guards. The user's exact approval is recorded; external reference limits remain explicit. No destructive tests used installed data. |

### Approved mandatory audit behavior

On 13 October 2026 the user answered the specific policy question: **"Approve atomic audit logging for existing overrides"**. This supersedes the original pending gate. The separate strict helper requires an Admin override and an active caller-owned transaction, uses existing `activity_logs` fields and the live-Student ownership guard, and propagates insertion/ownership failures. It never begins or commits a separate transaction and does not rewrite prior audit history.

| Existing override path | Audit persistence and rollback boundary |
|---|---|
| Document review override | Override event follows existing review/conditional progress writes, before commit |
| Existing force submission | Override event follows submission/history/conditional progress writes, before commit; ordinary Ready submission keeps its prior audit policy |
| Explicit IERB override | Override event follows locked progress/history writes, before commit |
| IERB Admin edit with changed stage/status | Override event follows record/history/login-identity persistence, before commit and account setup delivery |
| Student Admin edit with changed stage/status | Existing progress audit is mandatory inside the save transaction; non-Admin audit behavior remains unchanged |
| Reasoned deletion of a submitted document | Override event is inserted while ownership exists, before DELETE; deletion/version restoration share the transaction; cleanup follows commit |

`tests/batch11-atomic-audit.php` injects a real `BEFORE INSERT` failure for override entries, exercises all six production routes, compares every frozen-schema table (including identity, history and notification data), and hashes the actual submitted fixture file. Successful retries require exactly one override event with the original reason/Admin identity and unchanged recipient counts. A failure after the audit INSERT also rolls back the audit and preserves the file. Helper tests reject missing transactions, non-Admin/non-override contexts and vanished Student ownership without taking transaction ownership. [Atomic audit evidence](tests/batch11-results/atomic-audit-results.json) is included in the combined HTTP count. Ordinary best-effort audits, auxiliary non-override events and post-commit notification failure handling retain their existing policy.

### Role-by-role negative checks

| Actor / condition | Explicit document override | Explicit IERB override | Legacy formal/force submission | Other preserved guard |
|---|---|---|---|---|
| Student | HTTP 403; no mutations | HTTP 403 | HTTP 403 | No approve/deny/delete/override controls; own document/history scope |
| Assigned Adviser | HTTP 403; no mutations | HTTP 403 | HTTP 403 | Ordinary assigned-student review remains permitted |
| Other Adviser | HTTP 403; no mutations | HTTP 403 | HTTP 403 | Cross-student ordinary review forbidden |
| Inactive/Pending Admin account | HTTP 401 | Shared auth gate retained | Shared auth gate retained | No active privileged session |
| Expired Admin | HTTP 401 | Shared auth gate retained | Shared auth gate retained | 1801-second inactivity test |
| Admin with password/legal requirement | HTTP 403 | Shared gate retained | Shared gate retained | Existing onboarding/security boundary unchanged |
| Admin GET / foreign Origin / stale generation | 405 / 403 / 409 | Shared transport retained | Shared transport retained | Existing form compatibility and CSRF/origin checks preserved |
| Admin stale/old/submitted document | 409 | Stale official snapshot 409 | Already submitted 409 | No old-version or locked review mutation |
| Admin absent/invalid reason or target | 422 | 422 | Force reason required | No audit/history/delivery changes on refusal |

Only columns with explicitly executed HTTP outcomes claim those exact statuses. Shared-gate statements rely on unchanged centralized guards plus their dedicated security tests; they do not claim every possible Cartesian product was executed.

## QA results and reproducibility

Final executed counts are recorded in [test summary](tests/batch11-results/test-summary.json). Assertions overlap; totals are executions, not unique scenarios.

| Suite | Evidence |
|---|---|
| Accepted-source baseline browser | 246 checks, zero failures, 40 screenshots; [before/browser-results.json](tests/batch11-results/before/browser-results.json) |
| Final browser / responsive / keyboard matrix | 2354 checks, zero failures/exceptions, 289 screenshots; [after/browser-results.json](tests/batch11-results/after/browser-results.json); [final browser log after approved audit integration](tests/batch11-results/atomic-final-browser.log); each of six widths in both themes across Admin, Adviser, Student and public routes |
| Override HTTP + native concurrency | 245 checks, zero failures, including the 82 atomic audit checks below; [http-results.json](tests/batch11-results/http-results.json); document and IERB success/reasons/types/roles/expiry/gates/stale/rollback/replay; different sessions + two server workers; ordinary and force submission separate |
| Approved atomic audit failure/recovery | 82 checks, zero failures; [atomic-audit-results.json](tests/batch11-results/atomic-audit-results.json); actual override INSERT failures for all six routes, every-table rollback, file hash preservation, one-event recovery, unchanged recipients, later DELETE failure and strict helper guards; included in HTTP count |
| Full CLI regressions and PHP/JS syntax | 27/27 suites and 130/130 syntax checks; [cli-final/cli-results.json](tests/batch11-results/cli-final/cli-results.json), [final regression log after approved audit integration](tests/batch11-results/atomic-final-regression.log); includes 51 document workflow cases and academic/filter/lifecycle/security regressions |
| Supplemental rendered text contrast | 37/37 route/theme/size checks; [contrast/browser-results.json](tests/batch11-results/contrast/browser-results.json); measured new solid-color text at 1920/768/320, both themes; gradients/images excluded |
| Final focused Archived Accounts geometry | 97 checks, zero failures, 12 screenshots; [archive/browser-results.json](tests/batch11-results/archive/browser-results.json), [final-archive.log](tests/batch11-results/final-archive.log); six widths/both themes; table-to-container bounds, expanded metadata, hold visibility and actual keyboard menu interaction after the final archive-only CSS override |
| Persistent-delete protection | 203 native assertions (205 cumulative fixture checks); 12 HTTP assertions (14 cumulative); [deletion-native-results.json](tests/batch11-results/deletion-native-results.json); original user result file restored byte-for-byte |
| Protected-source/diff checks | 19/19 protected files unchanged; `git diff --check` passed; [integrity.json](tests/batch11-results/integrity.json), [diff-stat.txt](tests/batch11-results/diff-stat.txt) |

Commands used:

```powershell
node tests/batch11-baseline.cjs
node tests/batch11-regression.cjs
C:\xampp\php\php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --batch11 --browser1075 --acceptance1075 C:\xampp\mysql\bin
C:\xampp\php\php.exe -d extension=zip tests/v10-batch7-native.php --only105 --batch105 C:\xampp\mysql\bin
C:\xampp\php\php.exe -d extension=zip tests/v10-batch7-native.php --only105 --http105 C:\xampp\mysql\bin
node tests/batch11-integrity.cjs
C:\xampp\php\php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --batch11 --browser1075 --acceptance1075 --contrast11 C:\xampp\mysql\bin
C:\xampp\php\php.exe -d extension=zip tests/v10-batch7-native.php --only10 --batch10 --batch11 --browser1075 --acceptance1075 --archive11 C:\xampp\mysql\bin
```

Native runs build a random loopback MariaDB instance and compare `@@datadir` to its owned temporary directory before mutation. HTTP tests copy production source, omit installed `config.local.php`, replace only the disposable `db()` connection, disable real providers and verify frozen schema parity. Browser requests outside loopback use hash-verified cached public fonts/icons or empty responses; no provider, mail or external tour script is contacted. Tests require PHP with PDO/mysql/cURL/ZIP, Node, local MariaDB and installed Chromium. The CDN cache is the accepted Batch 10.75 public-asset cache; its SHA-256 hashes are checked before use. Generated evidence/baseline snapshots are ignored artifacts, while required new test sources have narrow `.gitignore` exceptions.

Accessibility verification covers accessible names, actual keyboard menu navigation, focus return, Tour/Guide access, reason focus/validation, reduced motion and viewport/menu hit testing. Supplementary solid-color text contrast checks evaluate rendered foreground/ancestor colors; image/gradient backdrops are explicitly excluded. It is not a full WCAG certification, screen-reader usability study or all-browser/device certification. Real PDF endpoint/content-type/body checks are performed; browser download-manager/PDF-viewer UI behavior is not claimed. External provider outage logic is tested through existing mocked transport suites and a real report fallback; no live provider/mail delivery is claimed.

Intermediate harness failures were corrected: JSON-only IERB endpoints needed JSON requests; hidden Student panels could not be keyboard-tested before activating Documents; the legal Continue Editing selector had to match the existing control; hover tests needed desktop metrics before navigation. The routing/session regression exposed a real invalidated-session landing regression, corrected by preserving the prior-session login redirect. Visual review exposed a hidden download-link cascade issue, nested document pill-width leakage, stretched desktop stage cards and crowded 320-pixel stage columns. These were corrected with focused hidden/wrapping/size rules. Archived cards also inherited the legacy archive workspace's 1100-pixel minimum table width; the final compact-only archive override removes that minimum. Table-to-wrapper assertions and focused final archive captures supplement the complete matrix after that last archive-only change. After audit approval, the older CLI fixture needed to load the new real helper and simulate its INSERT, and the HTTP file-deletion assertion needed `clearstatcache` because another process deletes the file. These fixture issues were corrected before the final reruns. Intermediate failure evidence is retained separately and does not substitute for final passing results.

## Findings, risks and decision records

| Severity | Finding | Disposition |
|---|---|---|
| High (approved and fixed) | Legacy document/IERB override audits could fail after commit; Student-edit logging also swallowed failures inside its transaction. | User explicitly approved mandatory atomic audit logging. All six existing override routes now persist their override event inside the existing transaction. Actual audit INSERT failures, all-table/file rollback, recovery and late-write rollback are tested. No new role, privilege, transition or schema is introduced. |
| Medium (baseline compatibility) | `require_session_generation(false)` allows older clients without the header, while stale supplied markers are rejected. | Preserve protected `security.php` and existing form compatibility. Browser mutations send the real marker. Enforcing a mandatory marker specifically on legacy overrides is a separate hardening decision, not authorized implicitly here. |
| Medium (baseline policy) | Legacy notifications are post-commit/best effort; delivery failure does not reverse the workflow mutation. Document/IERB server reason checks retain the existing configured minimum, while UI caps reasons at 500; retention has its own server 5–500 rule. | Preserved. No notification-recipient/failure semantics or new universal reason-length policy introduced. |
| Verification limit | Legacy override/formal-submit eligibility honors the persisted current-version marker. Ordinary workflow tests cover a deliberately corrupted duplicate current marker, but this batch's legacy override tests cover actual superseded versions and do not certify arbitrary corrupted version-marker repair. | No data repair or reinterpretation of legacy version markers was performed. Include this abnormal-data case in Batch 12; preserve existing history and seek a decision before any repair policy. |
| Medium (fixed) | Array reasons could cast to `Array`, satisfying the minimum reason length in legacy overrides/edit routes. | Reject non-string reasons (and explicit document override status inputs) before writes. Negative input/refusal tests added. |
| Medium (fixed) | Explicit IERB override computed before/after from an unlocked starting record. | Recheck stage/status under the existing lock and reject stale client snapshots; concurrency tests verify one winner and no duplicate history/copies. |
| Low (fixed) | Inherited display rules exposed an idle report download link; inherited no-wrap constrained archived phone cards. | Explicit hidden-link handling, normal wrapping, compact-table clipping checks and fresh screenshots. |
| Reference limitation | Drive Quick Tour artifact inaccessible; MP4 not attached. | External source integration blocked. Local tour stays functional. Animation uses embedded visual prototype with real backend states. |
| Verification boundary | Hostinger deployed files were not inspected, and whole-system acceptance remains Batch 12. | Local accepted SHA and protected-source evidence are exact; no remote deployment claim is made. |

## Changed files and review boundaries

The exact tracked and new-file inventory, hashes and final tracked diff stat are recorded in [integrity.json](tests/batch11-results/integrity.json) and [diff-stat.txt](tests/batch11-results/diff-stat.txt). Untracked files are listed separately from the tracked diff, including pre-existing user reports. No Git staging/publication commands were used.

Production changes are presentation wiring in authenticated page heads, `index.php`/`login.php`, the shared navigation/footer/resources partials, shared UI/guidance/workspace/sidebar/calendar scripts, record/queue/report scripts, `includes/pagination.php`, and narrow input/stale-state fixes plus explicitly approved transactional override audit calls in `documents_api.php`, `ierb_api.php`, `students_api.php` and `workflow.php`. New production files are `assets/css/batch11.css`, `assets/css/batch11-public.css`, `assets/js/batch11-public.js`, and `includes/public_landing.php`.

Test/report sources are the override inventory, this report, `tests/batch11-browser.cjs`, `batch11-contrast.js`, `batch11-http.php`, `batch11-atomic-audit.php`, `batch11-concurrency.php`, `batch11-worker.php`, `batch11-baseline.cjs`, `batch11-regression.cjs`, `batch11-integrity.cjs`, `batch11-summarize.cjs`, narrow `.gitignore` exceptions, the optional Batch 11 hook in `tests/v10-batch10-http.php`, and the existing `tests/document-workflow-audit.php` fixture updated to load the actual new helper and test three additional audit-failure rollback cases. PDF extraction/one-time wiring scripts and generated references/screenshots remain local ignored evidence; they are not deployment dependencies.

`config.php`, `security.php`, login processing/logout, frozen schema metadata/build/migration artifacts, legal governance service, document workflow service, lifecycle/retention/bulk services and all 19 enumerated protected files remain unchanged. No runtime configuration, provider credential, account lifecycle or database schema file was rewritten.

## Read-only Batch 12 release checklist

1. Review the explicitly approved atomic audit delta and evidence. Any stricter session-marker or universal reason-length policy would need a separate decision before implementation.
2. Supply an accessible Quick Tour source artifact and the MP4 if exact reference integration is required; conduct source/security review before incorporating code.
3. Independently attest deployed Hostinger baseline and frozen schema/deletion verification, using authorized read-only procedures; reconcile any intervening changes.
4. Review final hashes, tracked/untracked production/test inventory, screenshot comparisons and all requirement statuses. Exclude fixtures, caches, exports, local configuration and generated evidence from deployment payloads.
5. Perform the separately scoped Batch 12 comprehensive acceptance audit, including any required real device/screen reader/browser/provider checks and operator deletion-verification review.
6. Obtain separate, explicit authorization before commit, push, merge, tag or deployment. Plan rollback to the accepted baseline, preserving runtime storage, audit history and lifecycle/deletion verification artifacts. No database migration is required by this UI batch.

## Final executed evidence and exact file inventory

- Final browser: **2354 checks, zero failures/exceptions, 289 real screenshots**.
- Override HTTP/concurrency: **245 checks, zero failures**.
- CLI regression: **27/27 suites; 130/130 syntax checks**.
- Contrast: **37 checks, zero failures; 672 measured solid-color text instances**.
- Protected files: **19/19 unchanged**. `git diff --check` exit 0.

### Final tracked diff stat

```text
.gitignore                        | 10 +++++++++
 account.php                       |  1 +
 admin_ai.php                      |  1 +
 admin_legal_policies.php          |  2 +-
 admin_notifications.php           |  1 +
 admin_people.php                  |  1 +
 assets/js/admin-ai.js             |  2 +-
 assets/js/admin-management.js     |  5 +++--
 assets/js/adviser-dashboard.js    |  2 +-
 assets/js/calendar.js             |  1 +
 assets/js/dashboard-deadlines.js  |  3 ++-
 assets/js/dashboard-sidebar.js    | 18 +++++++++++++++-
 assets/js/documents.js            |  4 +++-
 assets/js/ierbprog.js             |  4 ++--
 assets/js/prism-guidance.js       |  6 ++++--
 assets/js/prism-ui.js             | 44 +++++++++++++++++++++++++++++----------
 assets/js/prism-workspace.js      | 22 ++++++++++++++++++--
 assets/js/reports.js              |  2 +-
 assets/js/role-calendar.js        |  1 +
 calendar.php                      |  1 +
 dashboard.php                     | 30 +++++++++++++++++++-------
 data_export.php                   |  1 +
 documents.php                     |  1 +
 documents_api.php                 | 38 ++++++++++++++++++++++++++-------
 ierb_api.php                      | 32 +++++++++++++++++++++-------
 ierbprog.php                      |  1 +
 includes/ceu_footer.php           |  4 ++--
 includes/pagination.php           |  3 ++-
 includes/prism-navigation.php     |  6 +++---
 includes/research_resources.php   |  8 +------
 index.php                         |  5 ++++-
 login.php                         |  4 +++-
 reports.php                       |  1 +
 research_adviser.php              |  1 +
 role_portal.php                   |  1 +
 students_api.php                  | 10 +++++++--
 tests/document-workflow-audit.php | 20 +++++++++++++++---
 tests/v10-batch10-http.php        |  3 ++-
 workflow.php                      | 29 ++++++++++++++++++++++++++
 39 files changed, 258 insertions(+), 71 deletions(-)
```

### New untracked Batch 11 sources/reports (separate from tracked diff)

- `PRISM_BATCH11_COMBINED_IMPLEMENTATION_REPORT.md`
- `PRISM_BATCH11_OVERRIDE_INVENTORY.md`
- `assets/css/batch11-public.css`
- `assets/css/batch11.css`
- `assets/js/batch11-public.js`
- `includes/public_landing.php`
- `tests/batch11-atomic-audit.php`
- `tests/batch11-baseline.cjs`
- `tests/batch11-browser.cjs`
- `tests/batch11-concurrency.php`
- `tests/batch11-contrast.js`
- `tests/batch11-http.php`
- `tests/batch11-integrity.cjs`
- `tests/batch11-regression.cjs`
- `tests/batch11-summarize.cjs`
- `tests/batch11-worker.php`

### Pre-existing untracked files preserved

- `PRISM_V10_BATCH10_5_PERSISTENT_DELETE_VERIFICATION_REPORT.md`
- `PRISM_V10_BATCH10_FINAL_DOCUMENT_WORKFLOW_UI_REPORT.md`
- `PRISM_V10_BATCH2_READONLY_UX_REPORT.md`
- `PRISM_V10_BATCH3_FILTER_GUARDRAILS_REPORT.md`
- `PRISM_V10_BATCH4_5_ARCHIVE_WORKSPACE_REPORT.md`
- `PRISM_V10_BATCH4_DOCUMENT_UI_REPORT.md`
- `PRISM_V10_BATCH5A_UI_ADVISER_ROUTING_REPORT.md`
- `PRISM_V10_BATCH5B_SESSION_SECURITY_BULK_UPLOAD_PUBLIC_LEGAL_UI_REPORT.md`
- `PRISM_V10_BATCH6_SCHEMA_LEGAL_FOUNDATION_REPORT.md`
- `PRISM_V10_BATCH6_SCHEMA_PROPOSAL.md`
- `PRISM_V10_BATCH7_NOTIFICATION_LIFECYCLE_REPORT.md`
- `PRISM_V10_BATCH8_HELP_SUPPORT_UI_REPORT.md`
- `PRISM_V10_BATCH9_STAFF_REGISTRATION_CODE_REPORT.md`
- `PRISM_V10_SCHEMA_CONTRACT.md`
- `tests/v10-batch105-results.json`
