# PRISM V14 — handoff to Claude Code

Checkpoint: 2026-11-03, Asia/Manila. **Implementation stopped at the user's explicit handoff instruction.** This report and root `CLAUDE.md` are the only files created during the handoff. No application source was changed during handoff, and no commit was created.

**Release verdict: INCOMPLETE / NOT READY FOR RELEASE.** Substantial V14 reconciliation has passed isolated runtime and browser checks, but Settings/schema-16 integration was interrupted. The latest complete checkout has not passed regression. In particular, a new verifier references a missing contract file. Do not deploy this working tree or interpret an earlier PASS as certification of its current contents.

## A. Executive summary

PRISM is a PHP 8.2 / MariaDB application with HTML, CSS, JavaScript and Composer dependencies. Reconcile the approved frontend ZIP with the verified V14 backend; the ZIP defines appearance and the V14 repository defines behavior. Do not replace the repository with the ZIP.

The active institutional workflow uses semantic keys, independently of retained numeric document/version storage columns:

| Stage | Key | Meaning |
| --- | --- | --- |
| 1 | `initial_submission` | Thirteen initial document requirements |
| 2 | `resubmission_amendment` | Conditional, RPMS-initiated official correction case |
| 3 | `approval_data_gathering` | RPMS acceptance of Form 33 with institutional approval reference |
| 4 | `ierb_completed` | IERB Completed after authorized Final Report formal submission |

Legacy presentation is being retired, while numeric storage buckets, migrations, audit evidence, version relationships and protection against old stage writers are retained. Stage 2 completion is derived from a resolved case with matching open/resolve ledger events, rather than the project's arrival at Stage 3 or 4. Existing V14 authorization, request idempotency, revision checks, atomic acceptance and Final Report transaction behavior remain the functional foundation.

**Immediate next unfinished work: Batch 6, Settings/database compatibility.** First read the exact incomplete-state findings in sections I–L. Batches 3–5 also need their remaining visual/report/export acceptance work; they are not formally signed off in full.

## B. Git and workspace state

Verified during this handoff:

| Item | Actual value |
| --- | --- |
| Workspace | `C:\PRISM_Workspaces\prism-v14-reconciliation` |
| Branch | `reconcile/prism-v14-frontend-20261102` |
| HEAD | `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16` |
| Authoritative backend branch | `feature/prism-v14-major-revisions` |
| Local backend branch SHA | Same as HEAD |
| Locally recorded `origin/feature/prism-v14-major-revisions` | Same as HEAD |
| Staged changes | None |
| New local commits beyond baseline | None; all reconciliation work is uncommitted |
| Worktrees listed by this repository | One, this workspace and reconciliation branch |
| Before handoff documents | 67 tracked modified files + 71 untracked files = 138 paths |
| Before handoff, by purpose | 81 application files, 18 maintenance/test files, 39 reports/evidence files |
| After creating these two documents | 67 tracked modified + 73 untracked = 140 paths |

Other PRISM folders used as read-only dependency/tool sources are not worktrees listed by this repository. Do not edit them. The remote branch was independently checked against GitHub earlier in the session and matched the baseline; **handoff checks read local refs and did not fetch or re-query GitHub**.

Frontend reference:

- ZIP: `C:\PRISM_Workspaces\prism-frontend-master\rpms_system_new.zip`.
- Size: 327,650,997 bytes.
- SHA-256, rechecked during handoff: `2c3133af45d4d320ce86e81856ccdbfdc273ef00b68834fdb30829f48f4b4a57`.
- ZIP's source Git HEAD, inspected earlier without importing its Git directory: `770bed25526aa599c89083c382a8b083e8d58d37`, a verified ancestor of the V14 baseline.
- Restricted extracted reference: ignored `tests/frontend-reconciliation/reference/`.
- No ZIP `.git`, vendor, private configuration, uploads, dumps or prior generated evidence was imported into the application.
- Initial inventory: 7,989 archive files; 7,626 excluded; 363 relevant reference files, 343 relevant V14 files. Normalized union: 212 identical, 114 modified, 37 ZIP-only, 17 V14-only. See `baseline.json` and `reconciliation-matrix.csv`.

Ignored `tests/` fixtures, screenshots and comparison candidates are important local evidence but will not travel in a normal Git commit. Preserve this workspace for Claude. Vendor is also ignored.

## C. Completed implementation and batch status

“Checkpoint PASS” below means the stated tests ran on that source checkpoint. It does not mean the current post-Settings source has passed.

| Batch | Status at handoff | Verified work and remaining limits |
| --- | --- | --- |
| 1 — baseline/safety | Baseline protection and inventory complete; regression had recorded failures | Branch/HEAD/ZIP preserved; inventory, dependency verification, baseline receipts. Original full regression did not finish green; see H. The early Batch 1 report is a historical checkpoint, not current status. |
| 2 — compatibility | Initial adapter batch complete at checkpoint | Actual-session adapter suite: 29 checks. Subsequent V14-only changes were additionally exercised in the 182-check current-workflow run. Final full contract regression remains. |
| 3 — dashboards/navigation | Integrated; conditional acceptance | ZIP Student/Adviser/Admin dashboards and shared navigation integrated. Original/reconciled paired dashboard run: 54 checks, 12 screenshot pairs. Current V14 browser matrix passed later. Final visual certification is outstanding; Settings navigation link is still unfinished. |
| 4 — documents | Substantial implementation and current-workflow checks complete; final acceptance outstanding | ZIP repository, checklist dialog and staff upload modal integrated with V14 services. Actual thirteen-file upload/version/review/formal-submission path passed; checklist contracts 18; staff records/documents matrix 86 checks. Uncertain upload/error recovery and complete historical-version/regression coverage still need final review. |
| 5 — monitoring/admin/reporting/export | Partial | V14-only IERB monitor, hover details, group operations and Student Records integrated. Current API/report/security surface suite: 33 checks within the 182 run. Latest ZIP Reports template/script/CSS adoption occurred afterward and has not been browser-tested. Export and remaining modules need final verification. |
| 6 — Settings/database compatibility | **In progress; exact interruption point** | ZIP Settings modules copied and adapted, schema composition started, worker nested-function defect repaired in source. Missing Settings DDL contract; no Settings end-to-end or coexistence regression. Only initial disposable schema-16/DDL extraction succeeded. |
| 7 — full regression/visual validation | Partial evidence; final batch not complete | Several genuine checkpoints passed, failures retained. No full regression of current checkout, schema 16, all UI states or complete final visual comparison. |
| 8 — final audit/readiness | Not completed | This is a handoff, not a release approval. Final inventory adjudication, security/legacy audit, migration readiness, rollback/release plan and release verdict remain. |

Do not redo the initial baseline extraction, replace the current branch, or discard completed adapters. Review and continue from the current changes.

## D. File-level changes

All paths below are relative to the workspace. `M` means tracked modified; `NEW` means untracked. “Verified checkpoint” always has the post-checkpoint limitations in H. This inventory includes every changed application file present before handoff; supporting maintenance files follow.

### Views and application endpoints

| File | Purpose; change; ZIP/V14 dependency | State / known issue |
| --- | --- | --- |
| `dashboard.php` (M) | Admin dashboard; ZIP cards/layout, semantic four-stage distribution, authoritative KPI helper | Rendered at checkpoints; final paired V14 comparison pending |
| `research_adviser.php` (M) | Adviser dashboard; ZIP layout/review cards, ordinary Request Revision terminology | Checkpoint rendered; official initiation omitted under approval |
| `role_portal.php` (M) | Student portal template; ZIP dashboard/documents/checklist placement; semantic stage label bootstrap | Current browser checkpoint passed |
| `ierbprog.php` (M) | Staff IERB page; ZIP progress cards, filters, group modal; removed old entry/action modals and Historical Student Stage Records | V14 browser checkpoint passed; no old Stage 1–5 active section |
| `admin_people.php` (M) | Shared Student/Adviser/archive roster; ZIP cards/dialog shell, retained approved V14 assignment fields; four-stage readonly choices | Student Records matrix passed; archive/Adviser full lifecycle interactions still need final testing |
| `documents.php` (M) | Staff document repository; ZIP template and shared upload modal | Staff browser checkpoint passed |
| `reports.php` (M) | Report history/tools; latest ZIP template, `PRISM_V14_REPORTS=true` hides retired Student-stage tool | Latest template adoption **untested**; existing report PDFs/history retained |
| `admin_settings.php` (NEW) | ZIP Academic Year/Communication Settings page; Admin login and shared navigation | Batch 6, untested |
| `admin_settings_api.php` (NEW) | ZIP Settings API; Admin-only list/save, origin/generation checks, template/reminder validation | Batch 6, untested; requires completed Settings store contract |
| `academic_years_api.php` (M) | Extends existing V14 Add Academic Year API with availability/current/removal operations | Batch 6, untested; use existing catalog, no assignment promotion |
| `advisers_api.php` (M) | Adviser roster API; adds scoped status counts for ZIP cards | Initial compatibility suite passed |
| `students_api.php` (M) | Student roster API; canonical stage/label/progress or setup pending; numeric historical stage is no longer presented as current progress | Current surface checks passed; save/assignment semantics retain V14 |
| `ierb_api.php` (M) | Progress API; read-only staff requirement projection; four-stage contract, filters/distribution; retired per-Student mutations and Adviser-request decision route | Current workflow/surface checkpoints passed; some unreachable old handler bodies and read/audit compatibility branches remain for review |
| `documents_api.php` (M) | Retains V14 document mutation services; maps active document labels through requirement semantics, including confirmations/notification text | Actual uploads/reviews/versions/Final completion passed; raw numeric stage remains an internal bucket |
| `reports_api.php` (M) | Canonical AI reports regardless of mutation activation flag; obsolete numeric Student report generation returns 410 | Current report/security API checks passed; old unreachable report-generation helpers retained |
| `workflow.php` (M) | Shared audit/notifications/attention; active attention projection uses canonical unfinished projects and actual correction state | Included in V14 runtime checkpoint; retained security helpers unchanged in purpose |
| `config.php` (M) | Bootstrap/migration recognition; now admits explicitly migrated schema 16, without HTTP DDL | **Post-checkpoint Batch 6 edit; full regression not run.** Frozen numeric stage definitions were not rewritten |

### Browser behavior

| File | Purpose; change; ZIP/V14 dependency | State / known issue |
| --- | --- | --- |
| `assets/js/adviser-dashboard.js` (M) | ZIP Adviser queue/cards/interactions connected to canonical monitoring and V14 review status | Earlier dashboard/browser checkpoints passed |
| `assets/js/role-portal.js` (M) | ZIP Student presentation with V14 upload readiness, current catalog, queue/version/idempotency protections; four semantic stages; Stage 2 only completes after resolved official case | Current workflow/browser checkpoints passed; final full interaction and visual matrix outstanding |
| `assets/js/ierbprog.js` (M) | ZIP IERB cards/filter/group modal rendering, canonical `monitor` API, hover requirements, semantic Stage 2 marker; excludes empty sort parameters | Current browser checkpoint passed; no per-Student legacy mutation controls |
| `assets/js/ierb-monitor.js` (M) | Shared ZIP monitor/popover/record detail, scoped requirement statuses and history | Hover passed mobile/desktop, both themes; old unreachable legacy rendering branches still warrant cleanup review |
| `assets/js/ierb-requirements.js` (M) | Merged ZIP checklist renderer; atomic V14 bulk acceptance, RPMS-only official case controls, cap three, Amendment, holder/revision authority; removed old Adviser-request decisions; corrected conditional checkmark | 18 synthetic browser contract checks; actual API path also tested. No production data used |
| `assets/js/documents.js` (M) | ZIP repository card/actions/selection UI; V14 review labels and semantic document filters | Staff browser checkpoint passed; complete summary/deletion/uncertain failure states still pending |
| `assets/js/document-upload-checklist.js` (NEW) | ZIP staff upload modal behavior, requirement/type selection and multi-file queue; fails closed for unenrolled profiles; blocks uncertain retries | Staff modal/checklist loaded in browser; actual bulk HTTP uploads tested separately |
| `assets/js/admin-management.js` (M) | ZIP roster/cards/dialogs; retained V14 assignment/archival controls and reasons; semantic filters and setup-pending state | Student Records matrix passed; do not reintroduce numeric legacy options |
| `assets/js/admin-retention.js` (M) | ZIP selection toolbar and `syncSelection` adapter; “direct_delete” display intent maps to existing `permanent_delete` eligibility preview | Fixed browser TypeError; last staff matrix passed. Full bulk lifecycle actions **not** retested. No server deletion eligibility weakened |
| `assets/js/prism-ui.js` (M) | Three-way merged ZIP shared UI with V14 review/metadata behavior; removed obsolete numeric progress decision dialog/public helpers | Included in checkpoint browser tests; generic old compatibility branches remain to review |
| `assets/js/reports.js` (M) | Merged ZIP report history/selection UI with retained V14 project report tools | Latest adoption **untested** |
| `assets/js/academic-fields.js` (M) | Candidate merge retains V14 onboarding/group controls and numeric Student ID rules; adds registration-year selection support | Batch 6 copy after last browser checkpoint; **untested** |
| `assets/js/academic-years.js` (M) | ZIP academic settings UI | Batch 6, untested |
| `assets/js/communication-settings.js` (NEW) | ZIP communication template/reminder settings UI | Batch 6, untested |

### Shared services, guards and schema files

| File | Purpose; change; ZIP/V14 dependency | State / known issue |
| --- | --- | --- |
| `includes/pagination.php` (M) | ZIP bounded page sizes/history options; retains V14 defaults and unique search bindings | Compatibility suite passed |
| `includes/prism-navigation.php` (M) | ZIP shared navigation markup and removed role-chip space | Rendered previously; **still links Account to `admin_legal_policies.php` / Legal Policies instead of Settings**. An attempted later replacement did not match the actual tuple; fix after handoff |
| `includes/ierb_monitor.php` (M) | Canonical V14-only scope, authoritative KPI/status counts, pagination and staff requirements endpoint | Checkpoint HTTP tests passed; legacy fallback in internal row projection is unreachable through active monitor scope |
| `includes/ierb_monitor_view.php` (M) | ZIP dashboard monitor markup and current V14 scope text | Browser checkpoint passed |
| `includes/record_filters.php` (M) | Semantic V14 stage SQL/options; no raw Student-stage fallback | Current filters tested |
| `includes/stage_configuration.php` (M) | Fixed V14 names/guidance with short retained storage keys; latest schema-16 availability added | Four-stage guidance/rename rejection tested on 15; latest 16 edit untested |
| `includes/ierb_group_advancement.php` (M) | Canonical-only options on current schema; stale non-project identity now rejected even in inspect/confirmation path | Canonical listing/advance tested; the additional stale-identity guard was added after initial 182-run app copy and needs explicit regression |
| `includes/ierb_v14_workflow.php` (M) | Adds ledger-backed official/resolved case counts, refreshes response counts after event persistence; latest schema16 recognition | Core business path tested on 15; 16 admission untested; RPMS gate/max-three locking retained |
| `includes/ierb_v14_requirements.php` (M) | Read summary exposes case counts; uploads reject unenrolled/no-V14 context; preserves current-stage, silent-overwrite, acceptance and Final guards | Actual V14 flow passed; latest 16 admission untested; intentionally retires old unenrolled upload path |
| `includes/document_management.php` (M) | Requirement/alias-derived semantic document stage filtering and display; numeric columns remain version keys | Current surface checks passed, including Final Report filter/confirmation |
| `includes/ierb_track_report.php` (M) | Removes legacy distribution from new institutional report output; retains snapshot/counting infrastructure | New PDF contents and SHA-256 snapshot checks passed; old stored reports/snapshots untouched |
| `includes/upload_modal.php` (NEW) | Exact ZIP staff upload dialog template | Staff matrix passed |
| `includes/academic_year_manager.php` (M) | ZIP Settings Academic Year panel | Batch 6, untested |
| `includes/academic_years.php` (M) | Reuses existing catalog; adds registration availability/current/soft-removal policy; preserves assignments/catalog rows | Batch 6, untested; removals availability bound to Settings marker/store |
| `includes/academic_catalog.php` (M) | Adds `registrationYears` to shared catalog | **Incomplete**: current-year bootstrap and server registration enforcement not finished; see I |
| `includes/admin_settings_store.php` (NEW) | JSON Settings persistence, live Admin/audit checks, template helpers; marker/exact schema check; migration/settings mutex order | Batch 6, untested; requires missing pinned JSON contract |
| `includes/admin_settings_schema.php` (NEW) | Three-table Settings DDL/migrator; strengthened to exact DDL verification and explicit marker; retention/audit rollback blocks | **Blocked:** references missing `includes/admin_settings_schema.json`; do not treat as finished |
| `includes/schema_optional_extensions.php` (NEW) | Compose only explicitly marked, independently verified onboarding/Settings extension tables | Batch 6, untested across coexistence orders |
| `includes/schema_v16.php` (NEW) | Optional `academic_year_settings` migration over schema 15, composed extensions, rollback safeguards | Initial synthetic apply succeeded without installed extensions; no full migration/security/coexistence rehearsal |
| `includes/academic_schema_v16.json` (NEW) | ZIP pinned DDL contract for `academic_year_settings` | Used in initial disposable apply; no replacement of prior contracts |
| `includes/schema_research_onboarding.php` (M) | Separates extension-table verifier and allows base15/16 composition; retains onboarding/protocol retention guards | **Post-checkpoint, untested** coexistence/rollback changes |
| `includes/schema_v12.php` (M) | Admits version16 in composed higher-schema verification only; keeps frozen base/extension comparisons | Post-checkpoint, untested |
| `includes/schema_v13.php` (M) | Version16 recognition in required project-table checks | Post-checkpoint, untested |
| `includes/schema_v14.php` (M) | Version16 recognition in required summary-table checks | Post-checkpoint, untested |
| `includes/schema_v15.php` (M) | Version16 composition; lower migrator refuses later schema/installed onboarding extension | Post-checkpoint, untested; do not weaken exact existing contracts |
| `includes/account_lifecycle_schema.php` (M) | Exact schema16/extension composition; verification hash includes new/onboarding contracts | Post-checkpoint, untested. Existing deployment verification becomes invalid; do not enable deletion by bypassing verification |
| `includes/account_retention.php` (M) | Keeps retained summary/workflow protections active on schema16 | Post-checkpoint, untested |
| `includes/document_summary_history.php` (M) | Enables existing summary history on schema16 | Post-checkpoint, untested |
| `includes/research_projects.php` (M) | Enables existing canonical project services on schema16 | Post-checkpoint, untested |
| `includes/notification_delivery.php` (M) | ZIP addition applies template after Student-safe projection | Batch 6, untested; preserve identity/privacy projection |
| `includes/notification_lifecycle.php` (M) | Recognizes deadline reminder event and Student-safe calendar projection | Batch 6, untested |
| `includes/notification_worker.php` (M) | ZIP reminder scheduler; moved `notification_enqueue_deadline_reminders` out of worker before first call | PHP lint of this file passed immediately after move; repeated worker, dedup, eligibility, cancellation and rollback behavior untested |

### Exact changed/new visual assets

These CSS changes adopt ZIP bytes, not a redesign. Runtime screenshots do not certify every selector/state. The Settings/report files in particular were copied after the last broader browser pass.

| File | Change/status |
| --- | --- |
| `assets/css/admin-management.css` (M) | ZIP management presentation; current Student Records checkpoint |
| `assets/css/adviser-records.css` (M) | ZIP Adviser roster presentation; final roster validation pending |
| `assets/css/dashboard-polish.css` (M) | ZIP dashboard cards; dashboard checkpoint |
| `assets/css/ierb-monitor.css` (M) | ZIP shared monitor; current hover/checklist checkpoint |
| `assets/css/ierbprog.css` (M) | ZIP IERB cards; current monitor checkpoint |
| `assets/css/prism-components.css` (M) | ZIP shared component presentation; final interaction-state audit pending |
| `assets/css/report-records.css` (M) | ZIP report cards; latest adoption untested |
| `assets/css/reports.css` (M) | ZIP report page; latest adoption untested |
| `assets/css/student-dashboard.css` (M) | ZIP Student dashboard; current browser checkpoint |
| `assets/css/student-records.css` (M) | ZIP Student cards/dialog layout; staff matrix passed |
| `assets/css/academic-settings.css` (NEW) | ZIP Settings styling; untested |
| `assets/css/adviser-overview.css` (NEW) | ZIP Adviser dashboard styling; dashboard checkpoint |
| `assets/css/adviser-records-clean.css` (NEW) | ZIP Adviser roster styling; final roster validation pending |
| `assets/css/archived-accounts.css` (NEW) | ZIP archive styling; archive interaction validation pending |
| `assets/css/document-upload-checklist.css` (NEW) | ZIP upload dialog; staff matrix passed |
| `assets/css/documents-layout.css` (NEW) | ZIP repository layout; staff matrix passed |
| `assets/css/ierb-monitor-cards.css` (NEW) | ZIP dashboard monitor cards; checkpoint rendered |
| `assets/css/ierb-requirements.css` (NEW) | ZIP requirements dialog; contract renderer/current portal checkpoint |

### Maintenance and regression changes (18 paths)

| File | Purpose and status |
| --- | --- |
| `maintenance/ierb-group-tests/run.cjs` (M) | Opt-in compatibility/dashboard/current-V14 hooks; copies new includes/assets/Settings root routes before staging; evidence defaults under fixture. Latest Settings-copy changes untested |
| `maintenance/ierb-project-tests/uploads.cjs` (M) | Waits for asynchronous authorized upload-catalog readiness before the original keyboard-focus assertion; rerun passed |
| `maintenance/ierb-ui-tests/integration.cjs` (M) | Initial role-label assertion adapted to ZIP account text; obsolete role-chip assertions still remain at approximately lines 395/400 |
| `maintenance/onboarding-tests/v14-simplification-smoke.cjs` (M) | Source assertions adapted for relocated KPI helper and retired HTTP operations; 65 checkpoint checks passed |
| `maintenance/frontend-reconciliation/analyze.py` (NEW) | Safe ZIP inventory/restricted extraction and normalized matrix; already executed; do not re-extract unnecessarily |
| `maintenance/frontend-reconciliation/check-syntax.py` (NEW) | PHP/JS parser gate only; last complete run 250 pass before subsequent changes |
| `maintenance/frontend-reconciliation/compatibility.cjs` (NEW) | Actual-session initial adapter tests; 29 total runner assertions at checkpoint |
| `maintenance/frontend-reconciliation/dashboard.cjs` (NEW) | Actual paired ZIP/reconciled dashboard rendering with shared fixtures; older comparison only |
| `maintenance/frontend-reconciliation/checklist-contract.cjs` (NEW) | Synthetic request-contract Chromium tests; last run 18 pass including Stage2 markers |
| `maintenance/frontend-reconciliation/v14-fixture.php` (NEW) | Datadir-bound synthetic 11→15 migration, explicit project enrollment/membership, controlled audit-fault trigger |
| `maintenance/frontend-reconciliation/run-v14.ps1` (NEW) | Fresh disposable schema15/current-V14 regression runner; existing datadir intentionally refused |
| `maintenance/frontend-reconciliation/v14-current.cjs` (NEW) | Independent current V14 business flow and browser matrix; does not rely on retired legacy HTTP mutations |
| `maintenance/frontend-reconciliation/current-surfaces.cjs` (NEW) | Canonical groups/filters/document/report/security/PDF snapshot checks; 33 passed within 182-run |
| `maintenance/frontend-reconciliation/current-pages.cjs` (NEW) | Student Records/Documents, Admin/Adviser × 390/1440 × light/dark; 86 passed in last browser run |
| `maintenance/frontend-reconciliation/run-browser.ps1` (NEW) | Resumes only a previously verified disposable workspace fixture; browser-only schema15 runner |
| `maintenance/frontend-reconciliation/settings-fixture.php` (NEW) | CLI/datadir-bound initial schema16 + raw Settings DDL extraction; `pin-contract` is not idempotent; no full Settings test suite yet |
| `maintenance/migrate-academic-year-settings.php` (NEW) | ZIP CLI wrapper for optional schema16; local development only, not production SQL |
| `maintenance/migrate-prism-settings.php` (NEW) | ZIP CLI wrapper for Settings extension; incomplete until contract/review/tests finish |

The 39 pre-handoff reports/evidence paths are enumerated in the inventory appendix below. No existing file was staged, committed, reset, cleaned or stashed.

## E. Frontend reconciliation and visual evidence

Integrated: ZIP dashboards/navigation shell, Adviser review queue/cards, Student progress/documents panels, IERB progress cards, hover popover, shared requirements dialog, staff upload dialog, Student Records cards and Add/Invite shell, archive/Adviser roster styles. Reports and Settings source have been adopted but their latest complete integration is not browser-verified.

Remaining appearance work includes Account and Legal pages. A handoff source comparison found these CSS files still differ from the ZIP: `assets/css/account-clean.css`, `assets/css/admin-legal-clean.css`, `assets/css/admin-legal.css`. ZIP-only `assets/css/ierb-entry-steps.css` is absent intentionally because its obsolete entry workflow was removed. Do not copy it back just to satisfy a file count. This CSS comparison is not proof that every other template/behavior is reconciled.

Evidence:

- `tests/frontend-reconciliation/batch03-final-runtime/paired-screenshots/`: 24 PNGs, 12 original/reconciled dashboard pairs across three roles, two themes and two widths.
- Corresponding geometry/API/error data: `tests/frontend-reconciliation/batch03-final-runtime/dashboard-comparison.json`.
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-final-pixel-comparison.json`: actual differences exist. For example Admin desktop dark differed by 0.0733% in that older comparison. Student differences were materially larger before later approved V14-only cleanup. These images are **not** final parity evidence.
- `tests/fv14-r8/current-v14-screenshots/`: 24 current UI screenshots (IERB/Student dashboard plus Admin/Adviser Student Records/Documents, themes and widths). These are current-render checks, **not paired ZIP parity certification**.
- Remote decorative font/icon CSS was replaced with identical empty CSS in paired tests; fonts/icons in production have not been independently certified by these captures. Browser clock/images were stabilized in the paired harness.

The sidebar backdrop failure was a test setup issue: the ZIP's expanded desktop navigation intentionally overlays content; current tests collapse it with `#prismSidebarToggle`. First-use tours are marked seen using the real per-user storage key. Do not change CSS or force pointer events to make a test pass.

Prepared files under ignored `tests/frontend-reconciliation/candidates/` are **review material, not deployable source**. Some have unresolved merge conflicts or outdated historic UI assumptions. `resolve-candidates.py` in the ignored comparison area is not safely idempotent; do not rerun or copy its whole output. Review each hunk against the V14 baseline and reference ZIP.

## F. Backend integration and retained legacy audit

### Current API and workflow behavior

- `ierb_api.php?action=monitor` scopes active views to canonical V14 projects; unenrolled raw-stage profiles do not become active project rows. Staff hover details use `monitor_requirements` with assigned-Adviser scope enforcement.
- Old per-Student `advance`, `correct`, `status`, `override`, `save`, `note`, `delete` routes return 410 after origin checks. There is no active arbitrary numeric stage editing dialog.
- Official Adviser request/decision routes are retired. Active case opening/resolution is Admin-only and retains existing V14 activation, origin, generation, idempotency, revision, locking, affected-version, Amendment and max-three safeguards.
- Ordinary Adviser document revisions create successor versions without consuming an official cycle or changing the official stage.
- `ierb_v14_case_usage` exposes total and resolved official cases. Resolved requires matching open/resolve ledger events and case actor/stage fields. Current Stage 3/4 alone does not complete Stage 2.
- Group Advancement lists canonical project identities; an additional inspect-time guard rejects stale legacy group identity tokens. Backend project-member transaction checks remain authoritative.
- Document stage display/filtering comes from the V14 requirement catalog and aliases. Storage/version keys remain numeric and must not be renamed globally.
- Unenrolled upload fallback is disabled; setup must be explicitly completed. Existing retained files remain readable through authorized version/download paths.
- Bulk acceptance remains one authoritative atomic request. Form33 requires institutional approval reference. Final Report review/formal submission/completion is transactional and idempotent.
- New institutional reports omit the obsolete legacy distribution. AI report scope is canonical project aggregates with local fallback. Existing report/snapshot history is retained.

### Why the following legacy-related code remains

| Retained area | Reason; continuation boundary |
| --- | --- |
| Numeric `students.stage`, `documents.stage`, version keys, `STAGE_SEQUENCE` and frozen configuration meanings | Required by retained document version identity, historical records and migrations. Active UI uses semantic V14 keys. Do not rewrite numeric Stage4 into new completion or delete columns/tables |
| `ierb_v14_assert_legacy_mutable` and related enrollment/project/onboarding guards | Security fences stop old writers from changing canonical/enrolled/pending-setup records. Retain even if all old HTTP controls are gone |
| Schema11–15 migrations and pinned contracts | Required for audited upgrade ordering, compatibility and strict integrity/deletion verification. No migration file was deleted |
| Old service functions in `includes/ierb_progress.php` and legacy branches of group services | Retained for reviewed historical/CLI/migration safeguards and old test evidence; active HTTP routes are retired/canonical-only. Verify remaining call sites before any further removal |
| Existing `ierb_history`, correction cases/events, project/member history, activity logs | Audit/source evidence and compatibility; no table/record history deletion was performed |
| Historical read/inspection fallback in `ierb_api.php`, monitor helpers, report helpers | Not an active legacy workflow control. Some branches are unreachable from canonical active scope; some retained direct history reads remain. Complete a call-site audit and decide whether to disable active read projection while retaining audit access/data |
| Historical document versions and summary/report snapshots | Required evidence and ownership/deletion protections; old reports may still legitimately contain their original historical text |
| Former Adviser-request service/ledger functions | Old request events and supersession/retention protections may still depend on these functions. Active browser controls and request-decision route are retired; do not erase the ledger or strip its guards |

This is an interim legacy audit, not a claim that every dead handler/helper has been removed. No table, production record, migration or compatibility safeguard was deleted to accomplish the active UI cleanup.

### Rejected ZIP backend regressions

Do not import ZIP versions that restore Adviser official initiation, remove the lifetime three-cycle cap, bypass current-stage upload eligibility, allow silent replacement, split bulk acceptance into separate writes, remove onboarding/protocol authority, or implicitly treat a numeric legacy row as an enrolled V14 checklist. Preserve V14's numeric Student ID validation (1–100 digits plus retained identity handling); the ZIP's narrower 4–5-digit proposal was not adopted as backend policy.

## G. Latest user decisions and approval boundaries

The latest handoff request explicitly says **handoff only; no implementation, source edits, commits, production SQL generation or execution**. Claude's development continuation belongs to the subsequent continuation session.

For continuation, the established project decisions are:

1. ZIP is the visual master; no redesign, spacing/color/typography/animation/interaction changes without explicit approval.
2. V14 is the functional/security authority; preserve all current capabilities and guards.
3. Only the four approved V14 stages are active and visible. Current Stage4 is IERB Completed. Removal of obsolete Stage1–5/historical filters/percentages/Student Stage Records UI is explicitly approved.
4. Only RPMS Administrators initiate official cases, maximum three over the project lifetime. Ordinary Adviser revisions remain separate and retain earlier versions.
5. Conditional Stage2 completes only after an actual authorized official case was resolved. Preserve document requirement names/statuses when hovering IERB Progress.
6. Production is schema15, as stated by the user, not independently queried. Reuse the existing Add Academic Year/catalog. Schema16 only if genuinely needed; evaluate minimal compatible integration first.
7. If a production migration proves necessary during later development, prepare reviewed, manually executable Hostinger phpMyAdmin SQL. No production migration/deployment is authorized. No production SQL was generated during handoff.
8. No push, PR, merge, deployment, production migration/data changes or source ZIP overwrite. Latest handoff also forbids committing without permission.

Three UI exceptions were explicitly approved and remain approved:

- Omit Advisers' official “Request a resubmission” control; keep ordinary Request Revision.
- Retain V14 research-assignment fields/actions in ZIP Student Add/Invite dialogs.
- Retain reviewed archival reason/warning for verified project members, including PI/submission-holder transition protection.

There is **no unanswered approval question currently blocking implementation**. The older approval in `PENDING_WORKFLOW_DISPLAY_APPROVAL.md` to retain historical labels/filters was superseded by the later V14-only cleanup instruction and the handoff specification. Do not follow that document's historical-retention proposal. `V14_ONLY_DIRECTION.md` records the replacement. Other visible design changes still require specific user approval; routine internal fixes and isolated tests are authorized when development resumes.

## H. Regression evidence and failures

### Environment

- PHP: `C:\xampp\php\php.exe`, 8.2.12; enable ZIP per process with `-d extension=zip` when required.
- Node 25.0.0; Python 3.11.4; Composer 2.10.3.
- MariaDB10.4.32 under `C:\xampp\mysql\bin` was used for early fixtures. Recent successful current-V14 fixtures used MariaDB12.3.2 at:
  `C:\Users\khirz\AppData\Local\Temp\claude\c--PRISM-Workspaces-prism-v13-release-clean\5edee734-cbba-4939-817e-6bcc925fb9ca\scratchpad\mdb1232\mariadb-12.3.2-winx64\bin`.
- Chrome: `C:\Program Files\Google\Chrome\Application\chrome.exe`.
- Playwright Core1.64.1: `C:\PRISM_Workspaces\prism-v13-release-clean\PRISM_V13_UI_REMEDIATION_AUDIT\browser-tools\node_modules\playwright-core`.
- Composer install failed under restricted networking. Existing local vendor was copied from `C:\PRISM_Workspaces\prism-v14-simplification-validation\vendor`, whose lock matched this repository. Lock SHA256: `285896fe571cd411b7f082448327f54a8f13315351e18732faf1fe10d2738ec7`. Installed PdfParser2.12.5/polyfill-mbstring1.43.0 matched the lock; platform requirements 12/12 passed. This was not ZIP vendor.
- All database tests used loopback, synthetic records and datadir-bound helpers. No production configuration or provider was used. Runtime disables real mail/cURL and supplies a synthetic provider.

### Actual successful checkpoints

| Checkpoint | Actual result | Evidence / command |
| --- | --- | --- |
| Initial parser check | 243 pass | `baseline-checks/syntax.json` |
| Initial source smoke | 65 pass | `baseline-checks/smoke.txt` |
| Initial schema11 HTTP/browser | 429 pass | `baseline-checks/regression-console.txt`, `01-schema11` receipt |
| V14 foundation | 99 pass | Same console, `02-foundation` receipt; do not use inherited result files as proof |
| Schema12 all-flags rerun | 843 pass | `tests/frontend-reconciliation/baseline-runtime/regression/03-schema12-all-r2/`, console resume |
| Baseline continuation | Reports139; monitor245; schema15 UI130; 7R120; closure96; extended7R120; extendedclosure96; schema11 rerun310 | `baseline-checks/regression-resume.txt`, corresponding `test-results-documents.json` receipts |
| Onboarding migration/guard checks | 8 explicit migration checks, race4, historical guards3; empty rollback receipts | Same resume console; these are pre-current-Settings results |
| Initial compatibility adapters | 29 pass | `batch-02-integration.json`, `batch-02-console.txt`; `PRISM_TEST_FRONTEND_COMPATIBILITY=1`, `node maintenance/ierb-group-tests/run.cjs` |
| Paired dashboards | 54 pass | `batch-03-integration.json`, `batch-03-final-console.txt`; fixture `batch03-final-runtime` |
| Latest full parser checkpoint | 250 pass, 0 fail | `python maintenance/frontend-reconciliation/check-syntax.py PRISM_V14_FRONTEND_RECONCILIATION/v14-cleanup-syntax.json` |
| Latest source smoke checkpoint | 65 pass | `node maintenance/onboarding-tests/v14-simplification-smoke.cjs`; `v14-cleanup-smoke.txt` |
| Latest checklist request contracts | 18 pass | `node maintenance/frontend-reconciliation/checklist-contract.cjs`; `checklist-contract.json` |
| Current V14 business + surface + browser run | **182 pass** | `v14-current-r8-console.txt`; fresh `run-v14.ps1 -ReviewName tests/fv14-r8 -DbPort 13993 -AppPort 14993` |
| Current API/report surface component | 33 pass, included in 182 | `tests/fv14-r8/current-surface-results.json` |
| Last successful broader browser-only run | **136 pass**, including 86 staff-page checks | `v14-pages-r8-retry-console.txt`; `tests/fv14-r8/test-results-documents.json`, `current-page-results.json`; `run-browser.ps1` on existing r8 fixture |
| Initial schema16/Settings DDL extraction | CLI returned `ok:true`; no assertion-count suite | `tests/settings-contract/prepare.json`, `settings-ddl.json`, `settings-contract-console.txt`; initial `pin-contract` action only |

**Do not sum these counts into one unique regression total.** Runs overlap. Resuming r8 overwrote its general `test-results-documents.json` with the later 136-check browser run; the original 182 result is preserved in the console. Several baseline fixture copies inherited earlier PASS JSON files with unrelated names/counts. Use the phase-specific receipt plus console, not a copied JSON merely found in a phase directory.

The 182-check run exercised actual sessions, thirteen individually typed files in one multipart request by a verified non-holder, ordinary revision without official-cycle usage, original download/version preservation, bulk acceptance, canonical group advance, Stage2 conditional before a case, three actual RPMS cases including Amendment, fourth-cycle rejection, Form33 reference gate, Final upload, synthetic audit-failure rollback, completion to both members, idempotent retry, canonical report options/PDF generation/download permissions, and hover/current UI checks. It did not validate the subsequent Settings edits.

### Failures, fixes and executions that are not a PASS

- Original schema12 all-flags attempt failed after 588 successful checks at a file-picker focus assertion during asynchronous catalog refresh. The readiness wait was corrected, original focus assertion retained, and rerun passed843.
- Original regression continuation ended at `10-schema11-ui` with `Change password enables when complete and matching` failure. It is not a green full baseline run.
- A focused older UI rerun progressed through profile/contrast checks, but still referenced removed `.prism-role-chip` in later responsive checks. Those assertions remain in `maintenance/ierb-ui-tests/integration.cjs`; reconcile them with the approved ZIP role text and current-only behavior, preserve the failure evidence, and retain meaningful coverage.
- First current-V14 fixture preparation lacked private storage directories; runner now creates them before explicit enrollment.
- Current r2 reached core business success then monitor rendering failed because empty `sortBy`/`direction` query parameters returned422. Client now drops empty values.
- One XAMPP run lost the server during initial DDL (`MySQL server has gone away`). Cause was not proven. A separate incomplete MariaDB12.1.2 directory also failed installation. Recent runs used the verified12.3.2 path above; do not claim the old environment problem was repaired.
- r5 hover asserted before async requirement details loaded; added wait for actual Form11 contents.
- r6/r7 desktop hover initially met first-use tour/backdrop overlays. Tour storage state and normal sidebar collapse fixed test setup; r7 browser rerun passed50. No CSS workaround was made.
- First expanded r8 staff-page run failed final error assertion with `retentionUI?.syncSelection is not a function` for Admin Student Records. Adopted/connected ZIP retention helper, then reran:136 pass, zero JS errors/failed local responses.
- Failed fixtures/logs remain; no failure was erased or converted to PASS. Unreachable retired workflow behavior makes portions of older suites obsolete; explicitly map and replace their expectations rather than silently deleting/skipping failures.

### Tests not run on the final source

After the 136 pass, Settings/schema16 and Reports source changes were made. **No complete syntax, smoke, business, browser, migration or security suite has run against that final source.** Individual new PHP files were linted while editing, which is not runtime verification. Missing Settings JSON, registration enforcement, navigation, worker tests, schema coexistence/drift/concurrency, actual Reports UI, remaining Account/Legal UI and final visual comparison are outstanding.

No production test, Hostinger deployment, production database inspection/migration, push, merge or PR occurred.

### Interrupted commands/processes

The pre-handoff Settings DDL extraction command completed with `ok:true`; its finally block stopped its disposable MariaDB. The previous r8/core/browser sessions also completed and logged database shutdown. There is no known command still running that needs continuation. Handoff only retrieved already-completed command output; it did not start another regression.

Retained stale lock files were found at:

- `tests/frontend-reconciliation/batch03-ui-runtime/sandbox/run.lock` (recorded PID16180)
- `tests/frontend-reconciliation/v14-current-runtime-r2/sandbox/run.lock` (PID15364)
- `tests/fv14-r5/sandbox/run.lock` (PID21332)
- `tests/fv14-r6/sandbox/run.lock` (PID14036)

They were not removed during handoff. Verify the recorded PID is absent before removing a specific disposable lock. Process visibility is restricted (CIM access was denied earlier); do not kill unrelated PHP/MySQL/Node processes or assume every local service belongs to this task.

## I. Database status and exact unfinished Settings state

Production schema15 is user-reported. No production access occurred. Existing V14 `academic_years` already provides Add Academic Year and must remain the shared catalog. No automatic Student year rollover/promotion is intended.

The ZIP proposes:

1. `academic_year_settings`: availability-for-registration and is-current policy tied to existing catalog rows, labelled schema16.
2. Separate `prism_settings`, `prism_deadline_reminder_dispatches`, `academic_year_removals` tables for communication JSON, dedup receipts and soft retirement.
3. Existing optional `research_onboarding_v1` extension on schema15 must coexist without losing assignment/protocol authority.

Persisting the ZIP's additional policies needs a reviewed storage solution; the **schema16 version bump is not yet established as the only necessary solution**. Claude must first assess minimal reuse of schema15/catalog and document why any additional migration is needed. Do not create a second academic-year catalog or weaken onboarding/lifecycle verification to make the ZIP schema pass.

Exact interruption state:

- `includes/admin_settings_schema.php` now expects **`includes/admin_settings_schema.json`, which does not exist**. Its intended strict comparison covers columns/types/indexes/checks/FKs/engine/collation, replacing ZIP's unsafe columns-only check. Do not weaken it to avoid the missing file.
- `tests/settings-contract/settings-ddl.json` contains raw SHOW CREATE strings for the three Settings tables, from a datadir-verified synthetic fixture. The file is PowerShell-redirection UTF-16. This is review input, **not an approved pinned contract automatically ready to copy**. Review against source DDL, normalize only permitted collation token and validate on supported engines/collations before pinning.
- `tests/settings-contract` used MariaDB12.3.2, port13994. `local-env.ps1` forces `utf8mb4_general_ci`, overriding the caller's earlier attempted unicode choice; returned DDL is correctly general_ci. The fixture reached schema16 and created the three raw tables **without** installing the `prism_settings_v1` marker. It is not a finished Settings migration fixture. Re-running `pin-contract` there fails because it performs CREATE on existing tables; use a fresh disposable fixture.
- `schema_v16_verify` composes existing exact contracts plus explicitly marked extensions. `schema_research_onboarding.php` was factored for independent extension verification. Both installation orders (onboarding→16/Settings and16/Settings→onboarding), partial/restart states, wrong marker/unknown tables, drift, rollback retention and migration-lock interactions remain untested.
- Schema recognition was extended to16 in bootstrap, project/workflow/summary/retention/guidance services. That is admission logic, not proof of safe runtime behavior on16. The initial unextended schema16 apply succeeded; current V14 flow on16 has not run.
- New lifecycle hash includes onboarding/Settings contracts; existing deployment verification should become invalid. Do not bypass authenticated verification or enable permanent deletion because an isolated schema happens to match.
- `prism_settings_store_available` requires marker1 and verifies exact table contracts. Positive results cache per PDO; false does not cache. Verify behavior when migrations/feature activation occur and protect against DDL racing a worker/write.
- Settings save acquires migration mutex then Settings mutex; confirm consistent release, failure handling, authorization and competing-writer behavior.
- `academic_catalog.php` currently only adds `registrationYears`. ZIP current-year bootstrap is not yet merged. `academic_validate` still has its old two-argument signature; intended new-registration-only availability enforcement in `account_onboarding.php` is not implemented. Preserve existing completed/legacy assignments when validating updates.
- `academic-fields.js` has registration-year support but its calling markup/bootstrap and server enforcement need end-to-end completion. Do not import the ZIP's Student ID restriction or drop V14 assignment fields.
- Navigation still points to Legal Policies; the Settings page exists but its canonical navigation link is unfinished.
- Reminder helper has been moved out of worker to fix undefined-first-call/redeclaration-on-repeat. Need repeated execution, event Student projection, eligible recipients, current group/all recipient population, cancellation, rescheduling, dedup, failure rollback and privacy tests. No actual provider delivery is authorized.
- New `reports.php`/`reports.js`/CSS adoption has not been exercised with the new Settings/navigation state.

No production-ready migration SQL or phpMyAdmin instructions have been generated. CLI PHP wrappers exist as unfinished development source. On later continuation, if migration is justified, prepare manual SQL and review/rehearse it separately; production execution requires separate approval. Retained configuration, reminders, removals, onboarding/protocol data and audit history must block unsafe rollback. Do not erase data to make rollback pass.

`SCHEMA_16_REVIEW.md` was written before implementation and still says “not applied”; read it as initial design notes, not the exact current state. This handoff is the authoritative checkpoint description.

## J. Prioritized continuation checklist

1. **Read this report and root `CLAUDE.md`; verify Git and preserve all 140 paths.** No commits or cleanup without permission. Do not recreate/reset the existing branch or re-import ZIP/backend wholesale.
2. **Resume Batch6 by reviewing the incomplete diff before editing.** Inspect `includes/admin_settings_schema.php`, missing pinned contract, `schema_v16.php`, `schema_optional_extensions.php`, `schema_research_onboarding.php`, `academic_years.php`, `academic_catalog.php`, `account_onboarding.php`, `notification_worker.php`, navigation and report UI. Decide/document whether schema16 is genuinely necessary while reusing schema15/catalog.
3. Complete the chosen contract/migration composition under existing local-development authorization; preserve old contracts. Add meaningful synthetic tests for drift, partial/restart, explicit CLI/exact DB/foreign-key/transaction gates, locks, both extension orders, supported collations and retained-history rollback refusal. Do not generate production SQL during this handoff turn.
4. Complete Academic Year/Communication UI-to-API wiring, registration policy enforcement, existing assignment preservation, current-year defaults, Admin authorization/audit and notification template validation. Keep mandatory `{message}` and sanitized Student projection. Finish Settings navigation.
5. Run the parser/source gates, then a **fresh** schema15 current-V14 regression. Clear browser-only flags first. Repeat with the chosen optional schema path, without introducing legacy workflow dependencies. Keep the original failures/evidence.
6. Finish remaining Batch5 Reports UI/export/Adviser/archive/deadline acceptance. Exercise retention selection/confirmation against V14 eligibility guards, including zero-eligible/protected groups; do not import ZIP backend deletion shortcuts.
7. Finish Account/Legal visual reconciliation and other matrix entries. Preserve V14 authentication/profile/legal approval logic. Review candidates hunk by hunk; source comparison alone is not browser acceptance.
8. Complete explicit legacy call-site/retention audit. Keep safeguards and historic data. Verify active interfaces do not emit obsolete stage choices, percentages or operations, including direct active API read/export projections and no-case/reopened-case Stage2 states.
9. Run relevant full regressions: auth/session/origin/generation, upload failure/retry/type/version handling, member/adviser boundaries, atomic acceptance, all official-case guards, Amendment, Final rollback/idempotency, reporting/exports/AI, Settings/notifications/migrations. Reconcile obsolete old tests explicitly while retaining meaningful coverage and evidence.
10. Re-render original ZIP/reconciled pages with matching canonical fixtures/themes/viewports/fonts/assets; compare geometry and screenshots, including dialogs/hover/responsive states. Document approved content differences and seek permission for any new unavoidable visible design change.
11. Complete Batch8: adjudicated file matrix/inventory, current regression report, visual comparison, optional manual migration readiness and recovery plan, risks and honest release verdict. Do not push, open PR, merge, deploy or migrate production.

## K. Pending decisions, risks and authorization

| Issue | Can Claude resolve under established development authorization? |
| --- | --- |
| Missing Settings contract / incomplete schema composition | Yes: inspect, develop, pin reviewed contracts and test on disposable databases. No production action |
| Whether schema16 is necessary | Assess and justify minimal compatible design; reuse schema15/catalog. Production migration still needs explicit approval |
| Settings registration/navigation/notification/report wiring | Yes, preserving ZIP appearance and verified V14 behavior |
| Latest source not regression-tested | Yes, run appropriate isolated tests after completing/fixing source; do not call the checkout PASS now |
| Historical display approval document conflicts with latest decision | Resolved by latest user instruction: current V14 only. No repeat approval needed |
| Old tests assert obsolete role chips/legacy controls | Update meaningful expectations against explicit approved changes, preserve failures; do not silently skip/remove coverage |
| Exact visual parity unfinished; Account/Legal still differ | Integrate exact ZIP design; any additional visible departure requires user approval |
| Retention direct-delete display contract | Client maps to existing permanent-delete eligibility; full runtime actions need validation. Do not weaken server guards |
| Legacy helpers/history/security compatibility remain | Audit references; disable truly obsolete active operations; retain necessary safeguards and history |
| Dependency/tool folders under Temp/other PRISM workspaces may disappear | Verify availability; use approved local sources/package installation if available, never ZIP vendor or production credentials |
| Restricted network/process visibility | Do not invent successful downloads/process cleanup; document limitations. Do not stop unrelated services |
| Production SQL, deploy, merge, push, PR, commit | Not authorized by handoff; ask at a concrete reviewable final step where applicable |

There is no currently pending user answer. The stop condition is the user's handoff-only instruction, not an environment or approval-review rejection.

## L. Safe continuation instructions and commands

These are instructions for Claude's later continuation; they were **not run as new implementation during handoff**.

Read-only verification:

```powershell
Set-Location -LiteralPath 'C:\PRISM_Workspaces\prism-v14-reconciliation'
git branch --show-current
git rev-parse HEAD
git status --short --untracked-files=all
git diff --cached --name-status
git worktree list --porcelain
git diff -- includes/admin_settings_schema.php includes/schema_v16.php includes/schema_research_onboarding.php
Get-Content -LiteralPath 'PRISM_V14_FRONTEND_RECONCILIATION/HANDOFF_TO_CLAUDE.md'
```

After completing/reviewing the interrupted source:

```powershell
python maintenance/frontend-reconciliation/check-syntax.py PRISM_V14_FRONTEND_RECONCILIATION/claude-syntax.json
node maintenance/onboarding-tests/v14-simplification-smoke.cjs
node maintenance/frontend-reconciliation/checklist-contract.cjs
git diff --check
```

The following fresh schema15 runner command previously passed182 checks on its earlier source checkpoint. Its code now includes the wider browser suite; expect a new actual result, not the historical count. Select a new review folder/unused ports. The helper refuses existing datadirs.

```powershell
$env:PRISM_TEST_MARIADB_BIN='C:/Users/khirz/AppData/Local/Temp/claude/c--PRISM-Workspaces-prism-v13-release-clean/5edee734-cbba-4939-817e-6bcc925fb9ca/scratchpad/mdb1232/mariadb-12.3.2-winx64/bin'
Remove-Item -LiteralPath Env:PRISM_TEST_V14_BROWSER_ONLY -ErrorAction SilentlyContinue
Remove-Item -LiteralPath Env:PRISM_RECONCILIATION_SOURCE -ErrorAction SilentlyContinue
& maintenance/frontend-reconciliation/run-v14.ps1 -ReviewName 'tests/claude-v14-fresh-01' -DbPort 13995 -AppPort 14995
```

`local-env.ps1` sets general_ci unconditionally. To test a different supported collation, choose/adjust the disposable setup consciously; simply setting an environment variable before this helper is insufficient. Base migration versions and datadir binding must still be verified. Do not repoint these helpers at an existing non-test database.

Browser-only reuse command that last passed136 on earlier source:

```powershell
& maintenance/frontend-reconciliation/run-browser.ps1 -ReviewName 'tests/fv14-r8' -DbPort 13993 -AppPort 14993
```

This reuses retained completed projects, excludes business workflow execution and overwrites some per-fixture browser result files; preserve earlier receipts first if necessary. Do not call it a fresh workflow run. It leaves `PRISM_TEST_V14_BROWSER_ONLY=1` in its process environment; remove that flag before a full run.

Earlier broad baseline command, **not green in full and not current-source certification**:

```powershell
& maintenance/onboarding-tests/run-regression.ps1 -MariaDbBin 'C:\xampp\mysql\bin' -ReviewName 'tests/claude-baseline-review-01' -DbPort 13996 -AppPort 14996
```

Review how old suites assume legacy endpoints before interpreting failures. Do not remove failing tests to obtain a PASS. Use explicit fixture-local evidence directories, fresh isolated ports/datadirs, synthetic users and disabled external providers. Test results must never overwrite historical tracked `PRISM_V14_BATCH_06_EVIDENCE` files; an earlier standalone run did so, but those tracked files were restored from HEAD and its newly generated output preserved under the ignored test fixture.

Preservation checks: no reset, clean, stash, rebase, merge, push, commit or ZIP writes. Do not copy private configuration. Use UTF-8-safe edits: Windows PowerShell's default pipeline encoding converted non-ASCII source text in earlier attempted transformations; literal Unicode in Python piped scripts can become `?`. Prefer an editor/patch tool or explicit UTF-8 files and verify exact bytes.

## Inventory appendix — pre-handoff report/evidence paths

These 39 paths existed before this handoff; the two new handoff documents are additional. Earlier narrative reports intentionally remain unmodified historical checkpoints. The exact current-state authority is this document plus the actual diff/evidence.

- `PRISM_V14_FRONTEND_RECONCILIATION/BATCH_01_BASELINE.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/BATCH_02_COMPATIBILITY.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/PENDING_UI_APPROVAL.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/PENDING_WORKFLOW_DISPLAY_APPROVAL.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/SCHEMA_16_REVIEW.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/V14_ONLY_DIRECTION.md`
- `PRISM_V14_FRONTEND_RECONCILIATION/baseline-checks/regression-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/baseline-checks/regression-resume.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/baseline-checks/smoke.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/baseline-checks/syntax.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/baseline.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-02-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-02-integration.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-02-smoke.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-02-syntax.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-final-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-final-pixel-comparison.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-integration.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-pixel-comparison.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-smoke.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-syntax.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/batch-03-ui-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/checklist-contract.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/reconciliation-matrix.csv`
- `PRISM_V14_FRONTEND_RECONCILIATION/settings-contract-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-browser-r7-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-cleanup-smoke.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-cleanup-syntax.json`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r2-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r3-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r4-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r5-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r6-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r7-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-current-r8-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-pages-r8-console.txt`
- `PRISM_V14_FRONTEND_RECONCILIATION/v14-pages-r8-retry-console.txt`

## Handoff preservation verification

Application/test/report content was fingerprinted before writing the two handoff documents and compared afterward. The verification result is recorded below after generation. No application source edits or new regression executions were made during handoff.

Verified after creating the documents:

- All **138 pre-handoff paths** retain identical SHA-256 content hashes; zero existing paths changed.
- Exactly two paths were added: `CLAUDE.md` and this handoff report.
- Final status: **67 tracked modified, 73 untracked, 0 staged; 140 changed/untracked paths total**.
- Original content-manifest SHA-256 (sorted path/SHA256 pairs, compact JSON): `78355462449dc9d6ef43e9820e3ff58a24b46d3e4b9d16062e332181965e5548`.
- Every pre-handoff changed/untracked path is named in this report. No local commit, reset, clean, stash, rebase, merge, push, deployment, production SQL generation/execution or ZIP write occurred during handoff.

**Handoff complete. Codex stops here.**
