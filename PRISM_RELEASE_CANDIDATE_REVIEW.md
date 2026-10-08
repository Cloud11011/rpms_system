# PRISM release candidate review

Implementation completed in the approved phase order. No commit, push, merge, tag, deployment, application database migration, new table/column, or schema-version change was performed. Application checks pass; default local PHP ZIP configuration remains a runtime prerequisite to resolve before operational use of DOCX extraction.

The gates were completed before advancing:

| Phase | Gate result |
|---|---|
| Baseline | Restored summary extraction, endpoint, transport, HTTP protection and browser checks passed before Phase 1 |
| 1 | Archive fixture 85; workflow 43 cases; summary 297; report privacy/format 274; relevant academic/deadline/notification regressions passed |
| 2 | CSV 93; archive 85; Data Export UI 76, all passing |
| 3 | Requested-width typography/UI 1,936, all passing |
| 4 | Backend 87; login/summary boundary 99; archive 85; CSV 93; report 274; deadline 190; summary 297; focused UI 21; syntax/diff gate passed |
| 5 | Final CLI, private SQL/Apache, canonical browser and actual-font gates described below; all passed; the corrected legacy pagination/resources sweep passes 3,388 and the summary-boundary subset passes 21 |

1. **Branch and HEAD.** `prism-v2-final-v8`, `fc0398c23846faa3a398b796d24c0134b8e1effb`, latest commit `Restore secure admin document summarization`. HEAD is unchanged.

2. **Git status.** Everything from this pass is unstaged. The existing manual SQL modification and both reconciliation artifacts are preserved, unchanged and excluded from the review diff.

```text
 M .gitattributes
 M .gitignore
 M admin_ai.php
 M advisers_api.php
 M assets/css/academic-fields.css
 M assets/css/admin-management.css
 M assets/css/adviser-dashboard.css
 M assets/css/calendar.css
 M assets/css/ceu-footer.css
 M assets/css/dashboard-overview.css
 M assets/css/dashboard-sidebar.css
 M assets/css/dashboard.css
 M assets/css/documents.css
 M assets/css/ierbprog.css
 M assets/css/prism-ui.css
 M assets/css/prism-workspace.css
 M assets/css/reports.css
 M assets/css/research-resources.css
 M assets/css/role-portal.css
 M assets/css/role-topnav.css
 M assets/css/student-dashboard.css
 D assets/css/student.css
 M assets/css/style.css
 M assets/css/workspace-pages.css
 D assets/images/ceu_logo1.jpg
 M assets/js/documents.js
 M assets/js/reports.js
 D assets/js/student.js
 M calendar_deadlines_api.php
 M dashboard.php
 M documents_api.php
 M ierb_api.php
 M includes/calendar_deadlines.php
 M includes/pagination.php
 M includes/prism-navigation.php
 M includes/research_groups.php
 M loading.php
 M notifications_api.php
 M reports.php
 M reports_api.php
 D role_login_template.php
 M send_followup.php
 M students_api.php
 M tests/alignment-audit.php
 M tests/document-summary-audit.php
 M tests/document-summary-http-audit.py
 M tests/document-workflow-audit.php
 M tests/run-final-regression.py
 M tests/tonight-polish-audit.php
 M tests/ui-audit.cjs
 M tools/schema-v8-manual.sql
 M workflow.php
?? PRISM_RELEASE_CANDIDATE_REVIEW.md
?? PRISM_V8_BEFORE_RECONCILE.patch
?? PRISM_V8_BEFORE_RECONCILE_STATUS.txt
?? data_export.php
?? data_exports_api.php
?? includes/csv_export.php
?? tests/academic-migration-audit.php
?? tests/archive-operational-audit.php
?? tests/backend-audit.php
?? tests/calendar-deadlines-audit.php
?? tests/crud-audit.php
?? tests/csv-export-audit.php
?? tests/readiness-mysql.php
?? tests/report-format-audit.php
?? tests/schema-v8-mysql.php
?? tests/ui-polish-audit.cjs
```

3. **Files added to the proposed review.** New application files are the page, endpoint and CSV helper. Previously ignored local regression fixtures that needed task-related corrections are now visible for review; these are test files, not new runtime components.

- `PRISM_RELEASE_CANDIDATE_REVIEW.md`
- `data_export.php`
- `data_exports_api.php`
- `includes/csv_export.php`
- `tests/academic-migration-audit.php`
- `tests/archive-operational-audit.php`
- `tests/backend-audit.php`
- `tests/calendar-deadlines-audit.php`
- `tests/crud-audit.php`
- `tests/csv-export-audit.php`
- `tests/readiness-mysql.php`
- `tests/report-format-audit.php`
- `tests/schema-v8-mysql.php`
- `tests/ui-polish-audit.cjs`

4. **Tracked files modified by this pass.** The unrelated `tools/schema-v8-manual.sql` change is excluded here.

- `.gitattributes`
- `.gitignore`
- `admin_ai.php`
- `advisers_api.php`
- `assets/css/academic-fields.css`
- `assets/css/admin-management.css`
- `assets/css/adviser-dashboard.css`
- `assets/css/calendar.css`
- `assets/css/ceu-footer.css`
- `assets/css/dashboard-overview.css`
- `assets/css/dashboard-sidebar.css`
- `assets/css/dashboard.css`
- `assets/css/documents.css`
- `assets/css/ierbprog.css`
- `assets/css/prism-ui.css`
- `assets/css/prism-workspace.css`
- `assets/css/reports.css`
- `assets/css/research-resources.css`
- `assets/css/role-portal.css`
- `assets/css/role-topnav.css`
- `assets/css/student-dashboard.css`
- `assets/css/style.css`
- `assets/css/workspace-pages.css`
- `assets/js/documents.js`
- `assets/js/reports.js`
- `calendar_deadlines_api.php`
- `dashboard.php`
- `documents_api.php`
- `ierb_api.php`
- `includes/calendar_deadlines.php`
- `includes/pagination.php`
- `includes/prism-navigation.php`
- `includes/research_groups.php`
- `loading.php`
- `notifications_api.php`
- `reports.php`
- `reports_api.php`
- `send_followup.php`
- `students_api.php`
- `tests/alignment-audit.php`
- `tests/document-summary-audit.php`
- `tests/document-summary-http-audit.py`
- `tests/document-workflow-audit.php`
- `tests/run-final-regression.py`
- `tests/tonight-polish-audit.php`
- `tests/ui-audit.cjs`
- `workflow.php`

5. **Files deleted.**

- `assets/css/student.css`
- `assets/images/ceu_logo1.jpg`
- `assets/js/student.js`
- `role_login_template.php`

6. **Git diff --stat.** Tracked changes from this pass only; Git does not include untracked additions in this command. The supplied complete review diff also contains the added files.

```text
 .gitattributes                       |   2 -
 .gitignore                           |  11 ++
 admin_ai.php                         |   4 +-
 advisers_api.php                     |   2 +-
 assets/css/academic-fields.css       |   8 +-
 assets/css/admin-management.css      |   8 +-
 assets/css/adviser-dashboard.css     |  46 +++----
 assets/css/calendar.css              |  46 +++----
 assets/css/ceu-footer.css            |  12 +-
 assets/css/dashboard-overview.css    |   9 +-
 assets/css/dashboard-sidebar.css     |   8 +-
 assets/css/dashboard.css             | 138 ++++++++++-----------
 assets/css/documents.css             |   8 +-
 assets/css/ierbprog.css              |  60 ++++-----
 assets/css/prism-ui.css              | 233 ++++++++++++++++++-----------------
 assets/css/prism-workspace.css       |  87 +++++++------
 assets/css/reports.css               |   2 +-
 assets/css/research-resources.css    |  16 +--
 assets/css/role-portal.css           |   8 +-
 assets/css/role-topnav.css           |  22 ++--
 assets/css/student-dashboard.css     |  30 ++---
 assets/css/student.css               |  75 -----------
 assets/css/style.css                 |  81 +-----------
 assets/css/workspace-pages.css       |  76 +++++++-----
 assets/images/ceu_logo1.jpg          | Bin 3147718 -> 0 bytes
 assets/js/documents.js               |   2 +-
 assets/js/reports.js                 |   2 +-
 assets/js/student.js                 | 206 -------------------------------
 calendar_deadlines_api.php           |   4 +-
 dashboard.php                        |   8 +-
 documents_api.php                    |  17 +--
 ierb_api.php                         |  78 +++---------
 includes/calendar_deadlines.php      |   2 +-
 includes/pagination.php              |   7 ++
 includes/prism-navigation.php        |   2 +-
 includes/research_groups.php         |   4 +-
 loading.php                          |  67 +---------
 notifications_api.php                |   4 +-
 reports.php                          |   2 +-
 reports_api.php                      |  14 +--
 role_login_template.php              |  74 -----------
 send_followup.php                    |   2 +-
 students_api.php                     |   3 +
 tests/alignment-audit.php            |   1 +
 tests/document-summary-audit.php     |   3 +
 tests/document-summary-http-audit.py |   2 +-
 tests/document-workflow-audit.php    |   4 +
 tests/run-final-regression.py        |   2 +-
 tests/tonight-polish-audit.php       |   2 +-
 tests/ui-audit.cjs                   | 127 ++++++++++++++++++-
 workflow.php                         |   2 +-
 51 files changed, 643 insertions(+), 990 deletions(-)
```

7. **Archive scope corrections.** Added `prism_operational_student_scope()` while preserving the general historical Admin scope. Current reporting, IERB monitoring, dashboard counters, current CSV, operational upload/report selectors, follow-ups and automatic document-driven advancement require active Students. New deadline/broadcast group validation uses a narrowly opted-in active group scope. General group and notification helpers retain their previous historical semantics.

8. **Before/after evidence.** Isolated fixture: active A (Stage 1/Pending), active B (Completed/On Track), archived C (Stage 1/Pending). Former unscoped Admin datasets would count 3; current datasets count 2. Historical administration and comprehensive Student export still count 3. No real records were changed by fixtures.

9. **Dashboard evidence.** Executed actual initial PHP SQL: total 2, pending 1, completed/approved 1, delayed 0. API overview totals 2; stage counts are Stage 1 = 1, Stage 2 = 0, Stage 3/4 = 0, Completed = 1. The refreshed dashboard consumes the corrected current overview.

10. **IERB evidence.** Admin and Adviser current list, overview, distribution and CSV contain A/B only. Archived-only group filters are absent. Needs Attention excludes C. Adviser scope remains limited to assigned active Students; list pagination is unchanged.

11. **AI report evidence.** Summary/full modes each run through controlled provider success and local fallback. All four captured provider datasets have recordCount 2 and two current cases; C's requirements and case never appear. Generated factual/Student-ID tables exclude C. Labels say All Active Students.

12. **Historical retention evidence.** Admin Student Records returns A/B/C. Admin historical document list/download, IERB history and activity audit for C remain accessible. A new summary test proves Admin can generate a historical archived-Student document summary. Source business-data snapshots remain unchanged during reads/exports. Permitted Admin historical edits/overrides remain available; archive detection prevents automatic current advancement.

13. **Data Export architecture.** Admin page `data_export.php` sits in Reporting & Communication. `data_exports_api.php` authorizes Admin before selecting data. Explicit metadata queries stream UTF-8 BOM CSV with no persistent file. The shared CSV helper preserves the existing six formula guards. IERB delegates to the existing exporter and shares validated search/filter/order SQL with its list. Adviser/group export uses canonical assignment helpers and an aggregate active workload join, avoiding N+1 queries. These exports supplement infrastructure backups.

14. **CSV row/authorization/security evidence.** Student export: 19 columns, 3 records, C explicitly Archived; Adviser: 9 columns, 2 records including Inactive, active workload 2 and only current assigned groups; Document: 26 columns, archived history retained; IERB: existing 18 columns, 2 active records. Student/Adviser institution-wide requests return 403 for all four actions. Their legitimate Adviser IERB endpoint still succeeds. Tests cover timestamped filenames, headers, empty datasets, safe audit counts, no business-data mutation, 27-row pagination independence, unlinked/superseded documents, human catalog labels and preserved legacy values. Formula prefixes =,+,-,@,TAB,CR, Unicode, commas, quotes and multiline fields pass. No storage names/paths, credentials/tokens, raw content or full summary text are exported.

15. **Typography and effective sizes.** Shared tokens: micro 10px; meta 11.5px; profile-meta 10.5px; labels/table headings 12.5px; body/inputs/buttons/table cells/role navigation/submenus 13.5px; Admin main navigation 14.5px; badges 12.5px; subsection 17.5px; section 19.5px; title clamp(25.5px,2.25vw,31.5px); hero clamp(26px,2.4vw,33px); summary reading 14.5px. Root remains 16px. Typical controls were 12px and are now 13.5px; previous small table/profile text is standardized to the approved readable scale. Large statistic numbers and icon sizes retain their effective scale. This is a pixel adjustment, with no 1.5x/150% or container scaling.

16. **Layout adjustments.** Shared line heights follow the semantic scale. Export cards wrap into one column on small screens. Actual-font testing found a 5px calendar overflow at 320px; only dashboard calendar grid gap below 400px changed from 8px to 4px. Shell widths remain unchanged. Authentication typography remains separate; its former splash selectors alone were removed.

17. **Responsive results.** Typography checks cover 18 page/role combinations at 1920x1080,1366x768,1024x768,768x1024,390x844 in both themes: 180 combinations, 1,936 checks, no failures. Expanded resource transcripts, Student progress/upload/document/calendar/notification/profile panels, deadline forms and account/activity add 394 checks at these widths with real fonts. The actual-font three-role sweep also passes at 1440,375,320px in both themes (154 checks). No unintended page scrolling, clipped text/buttons, navigation overlap, summary-modal overflow or table-header layout regression was found. Intentional table scrolling remains. Main/extra browser suites cover expanded/collapsed sidebars, mobile menus, profile/notification dropdowns, modals, resources and footer.

18. **Dead files/code removed.** Student localStorage prototype JS/CSS, role login template, unused JPG, splash markup/timer/CSS/animation, two superseded in-memory IERB loader/filter functions, stale export-ignore entries, unsupported deadline update/delete mutation names, stale runtime summary-retirement comment and obsolete test expectations.

19. **Deletion safety.** Full-tree checks, including ignored files and case-insensitive image/path search, found no runtime loaders/imports/includes/links for the deleted files. Only obsolete tests and archive attributes referenced the template/prototype paths. Loading CSS was exclusive to the former splash page; authentication mobile rules are preserved. IERB helper names had no callers after the SQL exporter replaced their sole former use. Deadline update/delete have no implementations and now consistently return unsupported-action 400 with no writes/delivery; create/cancel retain authorization/CSRF checks. The summary implementation is retained.

20. **Compatibility retained.** `loading.php` now redirects to `index.php`, preserving old-link entry through canonical authentication/role routing. All three role login redirect files, Student/Admin wrappers, v6/v7/v8 migration CLIs, schema validation, notification worker, Gmail helpers, Hostinger/manual utilities, deployment documents and `assets/images/CEU FOOTER LOGO.png` remain.

21. **Uncertain code retained.** Historical Admin mutation routes, shared general Student/group scopes, notification helpers, historical generated reports/document data, existing schema/ai_outputs, unrelated deployment utilities and historical review documents remain. No speculative deletion or schema cleanup occurred. Persisted scheduled recipients are not rewritten; workers/delivery helpers are unchanged. Existing deadline recipient snapshots/cancellation behavior remain unchanged.

22. **Removal size.** Approximately 540 lines of proven obsolete runtime/compatibility code removed, plus the unused 3,147,718-byte JPG. Larger tracked diff deletion counts include typography replacements, so they do not represent additional cleanup.

23. **AI Document Summary.** 297 endpoint/auth/fallback/privacy/persistence/workflow assertions, 41 sensitive/aggregate transport assertions, 29 focused summary browser checks from the restored baseline (also covered in the full final UI suite). Canonical summary/parser source and Composer manifests remain unchanged. Write locking, de-identification, server prompt, provenance/Source not recorded, bounded transport, sensitive mode and deterministic fallback are preserved.

24. **Extraction.** 37 extraction/privacy checks pass across PDF, DOCX, TXT and RTF, with malformed/encrypted/scanned PDFs, invalid/unsafe Office containers, parser/CMap bounds and controlled error behavior exercised. DOCX fixtures use ZIP enabled explicitly; default local runtime lacks ZIP.

25. **AI progress reports.** 274 report privacy/format assertions plus 85 archive operational endpoint assertions validate success/fallback, current scope, factual tables, case counts and secure filenames/history. Controlled transports avoid live research/provider requests.

26. **Standard reports.** Institution-wide/stage reports exclude C even when C matches Stage 1; direct current Student Report for C returns safe 404 and creates no PDF. Existing format, pagination/history and authorization regressions pass.

27. **Aggregate AI privacy.** Report fixtures and the 41 transport checks preserve the aggregate privacy boundary. Archived C never enters newly generated current OpenRouter progress-report input. Historical Admin document summarization remains independently authorized and uses the unchanged restored sensitive transport.

28. **CSV totals.** 93 dedicated authorization/data/history/format/security assertions, plus active/archive export cases within the 85 operational checks and 76 focused Data Export browser checks. All pass.

29. **CLI totals.** Main runner: all 27 suites pass, totaling 5,092 reported check/case units (suites report a mixture of assertions and cases). Additional academic migration 327; v6 extension 392 (its script repeats the 327 base); v6/v7 CLI guards 145 assertions/16 cases each. Real private MariaDB: reset migration 90, v6 348, v7 159, v8 221, plus timezone/setup/reset/throttle/scheduling readiness. JavaScript VM suites: shared session/network/debounce 21; forced-password UI 6. No application bootstrap/live data is used for these integration tests.

30. **Browser totals.** Canonical full suite 6,917 checks; typography 1,936; expanded real-font panels 394; hardening 342; final filter/actionable/privacy 79; readiness 56; actual-font shell visual 154; focused Export 76. Every listed final suite passes. Legacy local UI audit result: full legacy run 6,783 checks with one stale retired-summary expectation; the corrected affected summary-boundary subset passes 21 checks. Its separate pagination/resources sweep passes 3,388 checks after replacing the obsolete 13px expectation with the approved semantic size. All 13,363 final browser check executions listed here pass (overlapping coverage). Counts overlap across suites and are execution counts, not distinct feature counts.

31. **Security/hardening evidence.** CLI security 46 cases, rate limiting 45 assertions, hardening 114 assertions, document transport 41, workflow 43 cases, notifications 290, deadlines 190, email MIME/Gmail/fallback 26; browser hardening 342; private v8 migration/lease/concurrency/bulk 221. Apache: 19 summary dependency GET/HEAD/public-control checks, config denial/public control 2, repository private-path denials 9. Auth/login/forced-change/idle/logout fixtures pass. No live Gmail or OpenRouter delivery was attempted.

32. **Syntax.** Final PHP/JS syntax verification: 129 PHP/JavaScript checks, zero failures; the last legacy-test edit also passes Node syntax checking. Sources are parsed without executing application configuration.

33. **Diff check.** `git diff --check` passes with no whitespace errors. Existing LF/CRLF conversion notices are not diff errors. No staging operation was performed.

34. **Composer/Hostinger prerequisites.** composer.json and lock remain exact baseline files. Installed locked smalot/pdfparser v2.12.5, symfony/polyfill-mbstring v1.43.0 and autoload are present; vendor remains ignored. Composer strict validation returns exit 1 for existing missing-license and deliberate-exact-pin warnings; the manifest and lock are valid; platform requirements pass. PHP 8.2.12, memory 512MB, request limit 120s; zlib/iconv/mbstring/XML/DOM/cURL available. Default local ZIP is disabled: runtime checker fails only ZIP, while `-d extension=zip` passes all 12 prerequisites. Hosting must enable/check ZIP in its web PHP configuration and either run `composer install --no-dev --prefer-dist --no-interaction` from the committed lockfile or upload the complete locked vendor tree. Do not assume automatic installation. Apache dependency/private-path protections pass locally; verify the actual hosting web runtime and live provider integration during deployment. No deployment is performed in this pass.

35. **Remaining issues/debt.** Local/web ZIP configuration is the known environment prerequisite. Hosting/live provider/mail behavior is unverified here. Existing Composer warnings and accumulated CSS specificity remain; no broad refactor was authorized. Older local test harnesses needed pure-include/source-marker/schema guard/token-width/summary-restoration and semantic pagination updates; these corrections do not change runtime architecture or schema. The isolated manual-SQL test overrides only operator settings in an in-memory copy so the preserved local staging configuration is never changed or used as a target. Exact protected SHA-256 values: manual SQL DF99E2A0C8F99E2FBE74EAC08BF6AF5D50FBA270BE738F4D63077E1902AFD403; reconciliation patch B139C3C891D9B0BD5BA51731691FAC5D81045364F15014BCA00B258D903AD126; reconciliation status 749E1542AE844A9C8CD887E3DBBDA4B6879617223BED1DC3FDDBB40F702BD399.

36. **Release-candidate assessment.** Application changes are suitable for the proposed final freeze following your review. Readiness is conditional on enabling/verifying ZIP in the operational PHP runtime and completing the normal hosting prerequisite checks. There is no schema change or unresolved demonstrated application defect in the tested paths. Stop here for review; nothing is committed, pushed, merged, tagged or deployed.

The review diff is `tests/release-candidate-results/prism-release-candidate.diff`, including new review files and excluding all three protected unrelated files. Detailed logs/JSON and requested-width screenshots are under `tests/final-regression-results`, `tests/release-candidate-results` and `tests/ui-restyle-results`.

Final archived-record matrix (isolated A/B/C evidence):

| Surface/action | Verified result |
|---|---|
| Admin Student Records | A/B/C, total 3 |
| Student Records CSV | A/B/C, C marked Archived |
| Existing historical documents | C visible/downloadable; Admin summary generation allowed |
| Document Records CSV | C metadata, older versions and unlinked history retained |
| Audit/IERB history | C retained and Admin-readable |
| Dashboard current metrics | A/B only, total 2 |
| IERB list/overview/distribution | A/B only, total 2 |
| IERB Progress CSV | A/B only; Adviser scope preserved |
| AI Progress Reports/provider input | Two active cases; no C in payload/factual tables/fallback |
| Standard Progress Reports | C excluded, including matching-stage report; direct C report 404 |
| Adviser current workload | Two active assignments, archived-only group excluded |
| New document target/upload | Active options; C rejected server-side; intervening archive rejects transaction |
| New formal RPMS submission | C rejected server-side 409, including Admin force-submit path |
| New follow-ups | C rejected 404 for Admin/Adviser, no delivery |
| Notification broadcasts | Audience A/B only; general historical helpers unchanged |
| New deadline recipients | Active target groups/recipient snapshot; existing frozen recipients unchanged |

