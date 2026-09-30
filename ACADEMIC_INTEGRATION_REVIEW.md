# PRISM academic integration G-J - 1 October 2026

G1-G4, H, I and J are complete, together with the explicitly approved dashboard shortcut and documentation cleanup. Academic integration was committed as `b6323f5bce501f66faa9bdec15e512a48630ad16` (`Add academic program and year fields`). This is isolated verification, not production readiness or live migration certification.

## Source and preserved baseline

Workspace: `C:/xampp/htdocs/rpms_system`.
Branch: `prism-v2-academic-fields`.
Academic checkpoint: `b6323f5bce501f66faa9bdec15e512a48630ad16`.
UI checkpoint `0ef69bcea2de3783ac2bb6d5dba5fbc899f3afec` (`0ef69bc`) is an ancestor and was committed separately by the owner. The initial academic preflight found a clean tree on the correct branch with that checkpoint present. The assistant preserved the subsequent work and did not commit, push, switch branches, reset, restore, clean, merge or rebase.

Private configuration/secrets were not opened, printed or modified. No live MySQL connection, application bootstrap, email, scheduler, AI integration, migration or production deployment was executed. Syntax checks parse PHP without executing it; isolated fixtures extract only reviewed code or load pure helpers.

## Exact file inventory

Files modified by the academic integration:

| File | Academic/final-cleanup change |
| --- | --- |
| `config.php` | Schema-v5 migration section only: guarded DDL, course widening, four nullable fields. Authentication/session behavior unchanged. |
| `students_api.php` | Shared academic validation under the existing row lock/ownership guard, bound persistence and additive response fields. |
| `ierb_api.php` | Same validation and persistence; full locked row permits preservation of omitted academic values; admin-only save retained. |
| `admin_people.php` | Student-only academic controls, protected catalog require, safe JSON export and shared assets; adviser-directory form remains separate. |
| `ierbprog.php` | Equivalent academic controls, safe export and shared assets; existing entryCourse ID retained. |
| `assets/js/admin-management.js` | Shared helper integration, untouched-edit omission, modal keyboard handling and read-only academic summary. |
| `assets/js/ierbprog.js` | Equivalent academic form integration and summary; existing stage/status/reason behavior retained. |
| `dashboard.php` | Per-student misleading AI summary shortcut replaced by native Open Documents link, with no record-ID parameter. |
| `tests/crud-audit.php` | Reviewed catalog dependency and valid creation tuples; original 42 cases/assertions preserved. |
| `tests/ui-audit.cjs` | Exact dependency fixtures, additive academic tests, required creation setup and strengthened dashboard shortcut assertion. |
| `UI_INTEGRATION_REVIEW.md` | Committed UI checkpoint recorded; old status/statistics explicitly marked historical. |
| `RELEASE_CHECKLIST.md` | Correct checkpoint/branch, guarded academic migration and remaining deployment requirements. |
| `AUDIT_REVIEW.md` | UI checkpoint note corrected and current academic report linked. |

Files added by the academic integration:

- `includes/academic_catalog.php`: one pure authoritative catalog and validator; denies direct execution and inherits existing includes/.htaccess denial.
- `assets/js/academic-fields.js`: shared dependent selects and safe read-only summary renderer.
- `assets/css/academic-fields.css`: scoped responsive academic form/display styles.
- `tests/academic-audit.php`: pure catalog and legacy-validation assertions.
- `tests/academic-migration-audit.php`: isolated migration/PDO fixtures without bootstrap or MySQL.
- `tests/academic-api-audit.php`: actual endpoint-body persistence/authorization checks plus pure response serializers.
- `tools/migrate-academic.php`: explicit CLI migration entry point; not executed during this work.
- `ACADEMIC_INTEGRATION_REVIEW.md`: this report.

Security/authentication files, `workflow.php`, `loading.php`, `documents_api.php`, `profile_api.php`, and shared `prism-ui.js`/`prism-ui.css` remain unchanged. Existing static IDs in the three touched existing templates were preserved; browser checks found no duplicate IDs.

## Schema v5 and migration safety

| Column | Change |
| --- | --- |
| `students.course` | Fresh schemas use VARCHAR(255). Existing narrower CHAR/VARCHAR is widened to VARCHAR(255), preserving the server-reported nullability, default, charset, collation and comments. Existing adequate-width CHAR/VARCHAR and TEXT/MEDIUMTEXT/LONGTEXT are not shrunk. Unsupported types stop for review. |
| `students.academic_unit_key` | VARCHAR(64) NULL DEFAULT NULL |
| `students.program_key` | VARCHAR(80) NULL DEFAULT NULL |
| `students.year_level` | VARCHAR(40) NULL DEFAULT NULL |
| `students.academic_year` | VARCHAR(9) NULL DEFAULT NULL |

There is no academic backfill, relabeling, row deletion, department reuse or new role. Existing course labels and student rows remain untouched by academic migration. Columns are added only if absent. The advisory migration lock is retained and schema version is stamped only after all steps succeed. Isolated tests cover fresh/v4 migration, no-op v5, partial-DDL retry, failed widening/version write, lock timeout, concurrent winner, unsupported metadata and preservation of legacy values/column attributes.

**No database was migrated. A pending v4/fresh database now deliberately refuses ordinary application DB initialization before migration DDL.** An already-v5 database does not require an opt-in. Before any important-database migration: get owner authorization, make/verify a backup, restore a disposable copy, run and verify migration there, test application behavior and retry/idempotence, then schedule a controlled migration window. ALTER TABLE may implicitly commit; transaction rollback cannot protect DDL.

The operator-only entry point requires both `tools/migrate-academic.php --apply` and `PRISM_ALLOW_SCHEMA_V5_MIGRATION=1` in an approved CLI process. It uses the existing application initializer, including existing fresh-install seeding. Do not put the flag in routine web/worker environments. The assistant did not run the entry point.

## Catalog and stable keys

The single source is `includes/academic_catalog.php`, exported to the two forms with JSON_HEX_TAG, JSON_HEX_AMP, JSON_HEX_APOS and JSON_HEX_QUOT. Neither API nor JS maintains an independent program catalog. JS year options derive from exported duration; only the server catalog controls accepted duration/year combinations.

Academic unit keys are supplied catalog groupings, not claims of official institutional departments:

| Stable unit key | Catalog label |
| --- | --- |
| amt | Accountancy / Management / Technology |
| elas | Education / Liberal Arts / Science |
| ihtm | International Hospitality Management / Tourism |
| dentistry | Dentistry |
| nursing | Nursing |
| pmt | Pharmacy / Medical Technology |
| optometry | Optometry |

All 18 supplied undergraduate and four graduate programs are represented:

| Stable program key | Stored course/program label | Unit key | Duration (years) |
| --- | --- | --- | --- |
| bsa | BS in Accountancy | amt | 4 |
| bsit | BS in Information Technology | amt | 4 |
| bsba_im | BS in Business Administration Major in International Management | amt | 4 |
| ba_comm_a | BA in Communication and Media Curriculum A | elas | 4 |
| ba_comm_b | BA in Communication and Media with 21 units of Education Curriculum B | elas | 4 |
| bs_psychology | BS in Psychology | elas | 4 |
| bs_psychology_education | BS in Psychology (with 18 units of Education) | elas | 4 |
| bsned | Bachelor of Special Needs Education | elas | 4 |
| bsned_early_childhood | Bachelor of Special Needs Education Specialization in Early Childhood Education | elas | 4 |
| bsihm_cruise | BS in International Hospitality Management Specialization in Cruise and Integrated Resort Operations | ihtm | 4 |
| bsihm_hotel | BS in International Hospitality Management Specialization in Hotel, Restaurant and Culinary Operations | ihtm | 4 |
| bsittm | BS in International Tourism and Travel Management | ihtm | 4 |
| ddm | Doctor of Dental Medicine | dentistry | 6 |
| bsn | BS in Nursing | nursing | 4 |
| bsmt | BS in Medical Technology | pmt | 4 |
| bs_pharmacy | BS in Pharmacy (Four-Year Program) leading to BS in Clinical Pharmacy | pmt | 4 |
| bs_clinical_pharmacy | BS in Clinical Pharmacy (Five-Year Program) leading to Doctor of Pharmacy | pmt | 5 |
| doctor_optometry | Doctor of Optometry | optometry | 6 |
| mba_thesis | Master of Business Administration (Thesis Program) | NULL (graduate grouping) | Unspecified |
| mba_non_thesis | Master of Business Administration (Non-Thesis) | NULL (graduate grouping) | Unspecified |
| mba_tqm | Master of Business Administration (Total Quality Management) | NULL (graduate grouping) | Unspecified |
| ms_psychology | Master of Science in Psychology | NULL (graduate grouping) | Unspecified |

The explicit Academic Year allowlist is `2025-2026`, `2026-2027`, `2027-2028`. No rollover month, active year or default selection is inferred. Years must be formatted YYYY-YYYY, consecutive and allowlisted.

Graduate programs require a program and Academic Year. Their academic-unit and year-level values remain NULL until official mappings/standing rules are supplied; no graduate duration or standing is invented. The UI's graduate option is explicitly a technical catalog grouping and sends NULL for the unresolved unit/year.

## Validation, legacy behavior and API contracts

New undergraduate records require valid unit/program/year-level/Academic-Year values; a changed academic tuple must satisfy the same rules. The two APIs call the same `academic_validate()` helper. Unknown keys, cross-unit combinations, out-of-duration years, malformed/nonconsecutive/unallowlisted years, non-string scalar/array/object inputs and arbitrary course labels are rejected with 422 before academic writes. Client duration cannot override catalog duration. Canonical program labels, including the 102-character hospitality label, are bound without the old 100-character validation cap.

Legacy preservation is determined against the locked server row, never a client bypass flag. Omitted or unchanged academic values preserve the original tuple verbatim, including NULL, empty strings, whitespace and free-text labels. An unrelated edit does not erase course, keys, year level or Academic Year. Explicitly changed/cleared values require a valid resulting tuple. Unchanged UI edits omit academic fields so concurrent server academic changes are also preserved. A Keep existing academic values control restores the original academic selections without resetting other form fields.

Existing endpoint routes and non-academic parameter names/response fields remain. The additive public names are `academicUnitKey`, `programKey`, `yearLevel`, `academicYear`; existing `course` remains the human-readable label. **New-record callers now need the approved required academic tuple**; a legacy course-only creation payload no longer satisfies validation. The `course` and `entryCourse` element IDs remain, but are program selects whose helper sends the catalog label through the existing course API field. No new endpoint was introduced.

Student API assigned-adviser ownership is rechecked against the locked row before academic validation. Advisers keep their existing assigned-student create/edit scope and cannot edit reassigned students or protected protocol/PI fields. Student-role mutations remain forbidden. IERB save/provisioning remains admin-only; adviser note/read behavior, formal submission, stage/status reason/audit rules, deletion and document workflow remain unchanged.

## Core UI and read-only display

Student Management and IERB use the same helper for unit -> program -> year controls. Incompatible program/year selections clear; compatible year levels survive program changes; Academic Year stays independent. Valid edits preselect values; legacy labels remain visible and preservable. Full long program names are shown in wrapping explanatory text. Both entry dialogs support native keyboard controls, focus entry, Tab containment and Escape/return focus.

J adds safe semantic academic summaries to Student Records and IERB Monitoring for existing admin/adviser views. Values are inserted with textContent; no new mutation controls or authority were added. Empty legacy tuples add no misleading summary. Reports/PDFs and other dashboard/profile academic expansion are deferred.

The final dashboard cleanup replaces the per-row AI Summary shortcut with a native `documents.php` link labeled Open Documents. It carries no student/document ID and makes no summarize API request. Existing summary modal IDs/functions remain; no new API or authorization path was created.

## Batch gates and intentional test updates

| Batch | Verification result |
| --- | --- |
| G1 | 591 pure catalog/validation assertions; existing CRUD 42, auth guards 12, security 41; syntax/diff passed. |
| G2 | 155 isolated migration assertions; catalog 591 and relevant hardened checks passed; no live migration. |
| G3 | 515 academic Student API assertions; all 42 existing CRUD cases; syntax/diff passed. |
| G4 | 1,027 academic assertions across both APIs; catalog/migration/CRUD gates passed. |
| H | Entire browser gate 1,819/1,819; academic and relevant hardened suites passed. |
| I | Entire browser gate 1,927/1,927; academic/hardened checks passed. |
| J | Entire browser gate 2,159/2,159; API coverage grew to 1,343 including JSON serializers; relevant hardened checks passed. |
| Final cleanup | Entire browser gate 2,159/2,159; all PHP suites, reset UI, syntax, static IDs and diff gates passed. |

No existing assertion was weakened, skipped or removed. Original UI/security intent was retained:

1. CRUD fixtures add valid catalog fields only for new Student/IERB creation in the corresponding implementation batch. All existing stale-record, ownership, transaction, progress-reason, setup-pending and production-password assertions remain.
2. UI fixture catalog handling permits only `require_once __DIR__ . '/includes/academic_catalog.php';` in the exact page allowlist `admin_people.php` and `ierbprog.php`. Nested/unexpected includes remain rejected; real config/bootstrap is never loaded.
3. Approved H fixture correction: replace the catalog require with `academicSource.replace(/^<\?php\s*/, '')`, rather than adding PHP tags around an already-open PHP source. This fixes the duplicate-opening-tag parse error without changing product code.
4. Approved I fixture correction: provisioning and race creation select `entryAcademicUnit=amt`, `entryCourse=bsit`, `entryYearLevel=2nd Year`, `entryAcademicYear=2026-2027`, dispatching change in dependency order. The obsolete free-text course setup was removed. Requiredness and product validation remain intact; the original payload and safe setup-pending assertions remain with the additive academic fields.
5. Two newly added I test expressions used nested string quoting incorrectly. They now call `evaluateFunction()` with the same Edit entry selector/action. This was a test-driver syntax correction; no assertion or product behavior was changed.
6. The existing dashboard assertion now verifies a keyboard-activated native Open Documents link, exact ID-free href, absence of data-id, and no additional summarize API call. It retains and strengthens the original student-ID/document-ID separation assertion.
7. Additive academic UI coverage checks every requested width/theme, required fields, dependent controls, legacy and valid edits, literal hostile values, errors, graduate payloads, no duplicate IDs/overflow, keyboard focus and read-only adviser boundaries. Additional isolated serializer checks cover every catalog program and NULL/empty/hostile legacy values through JSON.

The temporary fixture failures were reported and their corrections were isolated to test setup/driver code. Full gates were rerun; final runs have no failures or skips. Product validation was never relaxed to make tests pass.

## Final regression results

| Command/check | Passing result |
| --- | --- |
| `node tests/ui-audit.cjs` | 2,159, zero failures |
| `node tests/reset-ui-audit.cjs` | 5 |
| PHP `tests/academic-audit.php` | 591 |
| PHP `tests/academic-migration-audit.php` | 155 |
| PHP `tests/academic-api-audit.php` | 1,343 |
| PHP `tests/backend-audit.php` with `-d extension=zip` | 86 |
| PHP `tests/auth-audit.php` | 12 |
| PHP `tests/auth-flows.php` | 31 |
| PHP `tests/crud-audit.php` | 42 |
| PHP `tests/document-workflow-audit.php` | 24 |
| PHP `tests/security-audit.php` | 41 |
| PHP `tests/rate-limit-audit.php` | 45 |
| PHP `tests/logout-audit.php` | 28 assertions across 4 cases |
| PHP syntax | 63 first-party PHP files; private configuration/dependencies excluded |
| Node syntax | 19 JS/CJS files |
| Static ID preservation | admin_people.php, ierbprog.php, dashboard.php |
| `git diff --check` | Passed |
| Staged diff | Empty |

Academic suites total 2,089 assertions; hardened PHP suites total 309 checks/assertions, plus five forced-password browser cases. Browser gate tests actual templates/assets with mocked APIs, at 375/768/1024/1280/1600px, both themes and permitted role views. No runtime exception or CSP violation was recorded. External CDN fonts/icons were blocked. Database fixtures demonstrate branch/parameter/locking behavior, not actual MySQL persistence or concurrency.

## Historical pre-commit git status

Before academic checkpoint `b6323f5`, 13 tracked files were modified and eight files were new, with nothing staged. The snapshot below records that pre-commit state; those changes were subsequently committed.

```text
 M AUDIT_REVIEW.md
 M RELEASE_CHECKLIST.md
 M UI_INTEGRATION_REVIEW.md
 M admin_people.php
 M assets/js/admin-management.js
 M assets/js/ierbprog.js
 M config.php
 M dashboard.php
 M ierb_api.php
 M ierbprog.php
 M students_api.php
 M tests/crud-audit.php
 M tests/ui-audit.cjs
?? ACADEMIC_INTEGRATION_REVIEW.md
?? assets/css/academic-fields.css
?? assets/js/academic-fields.js
?? includes/academic_catalog.php
?? tests/academic-api-audit.php
?? tests/academic-audit.php
?? tests/academic-migration-audit.php
?? tools/migrate-academic.php
```

## Historical pre-commit git diff --stat

This historical pre-commit summary covers tracked files only; the eight then-new files above were absent from these statistics but were included in academic checkpoint `b6323f5`.

```text
 AUDIT_REVIEW.md               |   6 +-
 RELEASE_CHECKLIST.md          |  12 ++-
 UI_INTEGRATION_REVIEW.md      |  24 ++---
 admin_people.php              |   9 +-
 assets/js/admin-management.js |  17 +++-
 assets/js/ierbprog.js         |  21 ++++-
 config.php                    | 111 +++++++++++++++++++++--
 dashboard.php                 |   3 +-
 ierb_api.php                  |  37 ++++++--
 ierbprog.php                  |  17 +++-
 students_api.php              |  41 ++++++---
 tests/crud-audit.php          |   9 ++
 tests/ui-audit.cjs            | 201 ++++++++++++++++++++++++++++++++++++++++--
 13 files changed, 452 insertions(+), 56 deletions(-)
```

## Remaining manual/integration and institutional decisions

- Back up/restore a disposable MySQL/MariaDB database and run schema-v5 migration there, checking supported server versions, table engines, course charset/collation/default/nullability, nullable columns, row counts and byte-for-byte legacy course values. Exercise fresh, v4, repeat and partial-DDL retry before any important-database migration.
- Persist/read back all academic tuples through both APIs against real SQL, including the 102-character label, NULL/empty legacy data, graduate nulls, explicit changes/clears and unrelated edits; verify no truncation or silent rewrites.
- Test simultaneous academic/unrelated edits, deleted records and adviser reassignment using multiple actual accounts/connections. Retest existing document locking/version/approval/override/formal-submission races and both stage-advance modes on MySQL.
- Verify authenticated admin/adviser/student boundaries, inactive/expired/forced-change sessions, real account provisioning/recovery, setup-pending handling and current/other-session invalidation.
- Verify Apache actually denies direct /includes/, tests and private storage/config/log/Git artifacts; confirm equivalent deployment-server rules and safe headers/proxy/origin behavior. Fixtures do not prove deployment configuration.
- Review real Chrome/Edge/mobile rendering, zoom, keyboard/screen-reader announcements, native select behavior, long legacy text and CDN fonts/icons. Validate current required academic selections with institution users.
- Exercise real setup/recovery mail, notification scheduling/CLI delivery, AI integration/local fallback and PDF ownership/downloads; these existing release gates remain unverified by fixtures.
- Confirm official academic-unit labels/mappings, graduate unit/standing/year-level rules and Academic Year maintenance. No institutional rollover or active year is inferred; unprovided graduate rules remain nullable.
- Academic integration is recorded in checkpoint `b6323f5`. Further commits, pushes or deployment require separate owner authorization. No live migration or production deployment is authorized by these fixture results.
