# PRISM V14 simplification — whole-regression compatibility audit

Status: **OPEN / RELEASE BLOCKED**. This audit uses the development branch and the user's provided outputs; it does not read or alter live Hostinger data or the user's local test files.

## Verified results
- 53 source/syntax checks passed.
- 267 focused isolated schema-15 onboarding/API/Chromium assertions passed.
- Full regression: schema 11 PASS (429 assertions); foundation PASS (99 assertions); schema12-all FAIL. The regression runner prints only the final stdout line. The final `documents.cjs` cleanup message does not identify the error. Inspect the retained `regression/03-schema12-all/console.txt` and `test-failure.json` before declaring a precise runtime root cause.

## Regression evidence update — October 31, 2026

- The first `03-schema12-all` failure was confirmed from the saved artifact: a Final Report was uploaded during Initial Submission; the new V14 server policy correctly returned HTTP 409. The document suite was updated to test negative stage eligibility and positive Form 33/Final Report progression.
- The second coordinated run again passed 429 schema-11 and 99 foundation assertions and all 53 source smoke checks. The V14 document suite passed through conditional amendment, formal Form 33 acceptance, eligible Final Report upload and historical evidence safeguards before stopping in the six-viewport legacy queue browser matrix at `390 light: readiness and upload count cover the complete queue`.
- **Actual application compatibility defect:** the new duplicate-required-type client restriction was applied to a historical, unenrolled Student's queue of five `Other Supporting Document` files. It is now scoped to V14 checklists only; no current V14 required-document duplicate is allowed. A new V14 browser assertion checks duplicate rejection and recovery when the extra file is removed.
- **Preemptive later-suite alignment:** the 7R suite now requires explicit Adviser return before a second verified group member uploads a new protocol version, and expects the retired legacy IERB title-update route to reject changes. Research titles remain editable through Student Records with before/after audit checks.
- All modified JavaScript files passed syntax checks. **The new application and test changes have not yet passed a fresh database/browser regression.** Keep the release gate closed.

## Confirmed source/test contradictions — must reconcile in one coordinated pass
1. **Early Final Report:** `maintenance/ierb-v14-tests/documents.cjs` expects an early Final Report upload to succeed (line approximately 89) before the project reaches Data Gathering. `ierb_v14_stage_upload_types()` now correctly restricts Initial Submission to the 13 initial types. Revise the test to assert pre-stage upload rejection and create the Final Report only after the recorded Form 33 acceptance; retain negative checks for premature review and completion.
2. **Unrequested supporting uploads:** the same document suite expects `Other Supporting Document` to be uploaded with no request, then corrected. New policy allows only stage-required types, unless explicitly affected in a correction case. Preserve case-bound supporting-correction coverage using a deliberately staged historical/synthetic supporting document; separately assert that unrequested new supporting uploads are denied.
3. **Student dropdown after advancement:** `documents.cjs` selects `Curriculum Vitae` and `Payment Receipt` while the test Student is in Data Gathering; those types are correctly absent now. Replace with assertions that Stage 1 types are missing, Form 33/Final Report eligibility is enforced, the checklist is correct, and revision paths remain available as authorized.
4. **Legacy cumulative upload browser suite:** `maintenance/ierb-project-tests/uploads.cjs` selects unrestricted `Other Supporting Document` on Student 5 (the explicit non-V14 Student). Keep this as a compatibility suite only while fallback legacy operations remain supported; add separate new V14 project-member queue tests. Do not re-enable unrestricted categories in active V14 to satisfy this suite.
5. **Legacy IERB entry UI:** `maintenance/ierb-ui-tests/integration.cjs` invokes `#addIerbEntry` and asserts the entry modal on desktop/mobile. The V14-first page intentionally hides that button; replace active-mode expectations with the new Student Records entry route, retaining legacy-mode compatibility tests in explicit nonactivated fixtures.
6. **Legacy AI reports:** `maintenance/ierb-7r-tests/integration.cjs` and `maintenance/ierb-group-tests/regression.cjs` expect `reports_api.php?action=ai_report` to work. The active V14 implementation now blocks that route. Audit whether users still need the prior institutional AI Full report output (distinct from project track sheets) before retiring it. Migrate/report through the V14 project data source rather than silently deleting a legitimate reporting capability.
7. **Mixed legacy/V14 monitor metrics:** `maintenance/ierb-monitor-tests/integration.cjs` expects legacy Student rows and dashboard labels. New V14 production mode displays canonical project rows only. Distinguish the inactive legacy compatibility mode from active V14 mode in fixtures, and verify archived-history access and project counts separately.
8. **Legacy stage-management dialog:** `maintenance/ierb-group-tests/browser.cjs` expects the old Manage Stages behavior. In V14 mode the action is Edit V14 Stage Guidance, fixed stage labels, and distinct short storage keys. Test both modes deliberately, not with a single shared assertion.
9. **Document and deadline exports:** Existing schema11 report regressions may remain valid while V14 is disabled. Add active-V14 assertions for project title/stage, group membership, protocol identifiers and deadline-targeting semantics. Verify SQL against the actual schema-15 fixture, not syntax only.
10. **Bulk RPMS acceptance is new and largely untested:** add real authenticated HTTP tests for distinct selected versions, mixed valid/invalid atomic rollback, role denial, duplicate category rejection, stale revision, response replay/idempotency, Form 33 exclusion, and failure injected into audit/notification persistence. Confirm RPMS checklist behavior in Chromium.
11. **Stage dropdown/checklist UX:** Add screenshots at 390/768/1440 widths, both themes, missing/under review/accepted/returned/completed states, multiple upload, keyboard selection and per-file duplicate type rejection. Verify the server rejects forged out-of-stage uploads, not just hides dropdown options.
12. **Routine revisions in later stages:** Document-level Adviser Return for Revision and official RPMS correction-case progression are distinct. Confirm the intended policy for revisions of older-stage accepted documents after stage advancement: the strict current-stage gate rejects such uploads without a case. Avoid weakening locked institutional evidence without a defined correction transition.

## Harness issue
`maintenance/onboarding-tests/regression.cjs` previously printed only last stdout line. Since document suites run cleanup in a `finally` clause, failure messages were hidden. This change preserves both streams and prints the failure stack when the child process exits nonzero.

## Mandatory sequence
A. Examine original saved `03-schema12-all` console and assertion JSON (read-only; no rerun).
B. Reconcile all *active V14* tests as a single review and ensure deliberate legacy-mode compatibility where relevant; **never change a test merely to turn a failure into a pass**.
C. Add and run missing new-feature integration/Chromium tests on an isolated schema-15 fixture.
D. Execute complete regression matrix with a fresh review name after fixes; inspect first failure and all generated evidence.
E. Review source, databases, no-data-loss invariants, permissions and rollback plan before production promotion.

No deletion of historical project/document/audit records and no deployment is authorized by this audit.

## Third full regression — Stage-specific dropdown synchronization

- On the October 31 local run at 09:00:16, the 53 source smoke checks and the first 429/99 regression assertions passed. The V14 document fixture successfully executed all current-stage, RPMS Form 33, correction, supporting-history and bulk/retained-evidence stages before the Student browser assertion at `maintenance/ierb-v14-tests/documents.cjs:240` failed. The error label was `Data Gathering Student dropdown offers only the Final Report after Form 33 approval`.
- **Confirmed frontend race:** `role-portal.js` initially exposed the global `PrismUI.documentTypes` catalog while the authenticated V14 checklist loaded; after stage requirements were fetched it updated `v14UploadTypes` without re-rendering already queued file selectors. A quick file selection could therefore expose obsolete options even when the server correctly restricted uploads to Final Report.
- **Correction:** Initialize the catalog as unavailable; disable file selection and submission until the authorized checklist or legacy record is resolved; rebuild the queued selectors after refresh, clearing stale type choices without losing uploaded files. Keep legacy catalog available only after the record is confirmed as historical. Guard against an older concurrent refresh overwriting the latest record.
- **Test update:** Wait for the explicit upload-catalog-ready signal in the V14 Student and legacy cumulative queue browser suites before selecting files. The V14 test also prints the actual selectable types on failure. Static JS syntax verified; isolated MariaDB/Chromium execution still pending.
