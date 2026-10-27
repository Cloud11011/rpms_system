# PRISM V14 Batch 2 review

Date: 2026-10-27 (Asia/Manila). Repository: `Cloud11011/rpms_system`.

## Release identity and boundaries

- Branch: `feature/prism-v14-major-revisions`.
- Exact starting commit and fetched origin starting HEAD: `ae0a80018372775df3e3781208521f19820726e9`.
- Ending commit: the commit containing this report, using the authorized message `feat(prism-v14): implement IERB document requirements and resubmission`. Resolve its exact SHA with `git log -1 --format=%H -- PRISM_V14_BATCH_02_REVIEW.md`.
- The exact ending local SHA, remote SHA and equality receipt are recorded after commit/push in [push-verification.json](PRISM_V14_BATCH_02_EVIDENCE/push-verification.json). This generated sidecar is outside its own commit to avoid a circular commit hash. The final delivery message also gives the verified SHA.
- Origin was fetched before implementation. The approved branch was already checked out in `.batch01-worktree`, with no tracked modifications. Its two unrelated untracked paths and all unrelated original-checkout work were preserved. See [git-preparation.json](PRISM_V14_BATCH_02_EVIDENCE/git-preparation.json).
- No main merge/rebase, new development branch, force push, Hostinger deployment, operational enrollment or live database access/migration.

General V14 HTTP activation remains unavailable. Enrollment, correction opening and correction resolution still require the reviewed explicit CLI gate, `PRISM_IERB_V14_FOUNDATION=1`, schema 12 and a live eligible RPMS Administrator. No stage builder or enrollment/activation HTTP route was added. Authenticated document checklist reads and RPMS acceptance operate only on explicitly enrolled submission holders. The existing read-only contract now accurately reports that document integration is implemented while HTTP activation is unavailable.

## Requirement structures and project boundary

`includes/ierb_v14_requirements.php` defines stable requirement keys, approved labels, document-category aliases and semantic stage assignments. New uploads show the approved canonical names; previously supported catalog names remain valid API input and historical stored names are never rewritten.

The 13 Initial Submission requirements are Certification of Ethics; Curriculum Vitae; Data Collection Questionnaire; Diagrammatic Workflow; Form 11 Review Checklist; Form 12 Registration and Application Form; Form 13 Study Protocol Assessment Form; Form 14 Informed Consent Assessment Form; Informed Consent in English; Informed Consent in Local Language; Letter to IERB Chair; Payment Receipt; and Study Protocol.

Evidence is explicitly associated with a workflow and its Student document owner, designated as the research project's submission holder. `submissionScope.kind=project_submission_holder` describes that boundary. No research-group string is used to infer document ownership, enrollment, acceptance or completion. Partners are not each automatically assigned 13 uploads. The service refuses evidence owned by another Student. Canonical project/group sharing and member synchronization remain Batch 3 work.

The existing schema 12 `ierb_workflow_events` table is the durable acceptance ledger; no new table or mutable acceptance checkbox is needed. Each `requirement_accepted` event stores:

- Requirement key, exact document ID, owner Student ID, original category/storage stage, version number and approved review state.
- Formal-submission timestamp and mandatory formal-submission audit ID.
- Correction-case ID when applicable and an institutional approval reference for Form 33.
- The foundation's responsible user, reason, revision, timestamp, request key/digest and complete original response.

`requirement_reconfirmed` records revalidation after an Initial Submission correction has resumed; it references the prior acceptance without inventing another acceptance decision. Only then may the normal Initial Submission transition occur if all 13 current requirements remain accepted.

An affected supporting or historical category receives a case-bound `affected:<original-version-id>` evidence key. Its corrected successor must be accepted to resolve that case. It does not become another permanent mandatory category. Original category names, predecessors and reviewer decisions remain retained.

## Schema and migration constraints

**No Batch 2 schema changes or migration were introduced.** The reviewed schema 12 DDL, pinned JSON contract, explicit migration runner, precise verification and deterministic empty-only rollback constraints are unchanged. The existing event ledger's JSON evidence accommodates the new version-bound decisions.

Normal HTTP requests perform no DDL. Migration never maps historical stage numbers or enrolls records. The synthetic suites verify v11/v12 inventories, retained-data refusal and rollback behavior. Every fixture connection verifies the actual disposable MariaDB datadir before executing any helper. Tests restore the empty extension to schema 11 and stop that synthetic server after verification; no live database is touched.

## Stage behavior

| Stage | Authoritative behavior |
| --- | --- |
| Initial Submission | All 13 requirements must have current, owned, approved, formally submitted, RPMS-accepted evidence. The final acceptance advances to Approval of Data Gathering atomically. Missing, pending, rejected, returned, superseded, ambiguous-current or unaudited records cannot count. |
| Resubmission / Amendment | RPMS opens an explicit repeatable case with the interrupted stage, reason, affected versions, Amendment policy and history. The case contributes no progress milestone. Resolution verifies corrected accepted successors and any required Amendment, then resumes the interrupted stage. |
| Approval of Data Gathering | Form 33 - Certificate of Approval requires its current approved formally submitted version plus separate RPMS acceptance with an explicit institutional approval reference. A review decision or detected date does not establish institutional approval. |
| IERB Completed | Form 33-0B - Final Report Form completes the eligible workflow during successful authorized formal submission. No additional RPMS acceptance, manual completion confirmation or stage advancement is required. |

The fixed four-stage contract remains intact. The Student view uses the separate V14 summary for enrolled holders, retains the legacy fields in storage/API rows, and labels the correction stage Conditional rather than a completed milestone. Student, assigned Adviser and RPMS checklists use the same backend-derived states: Missing; Uploaded / Pending Review; Returned for Revision; Formally Submitted to RPMS; Accepted. The Final Report remains a formal-submission requirement and is never given an RPMS-acceptance event.

## Corrections and Amendment

The foundation still prevents simultaneous unresolved cases and serializes decisions using the Student/workflow locks, optimistic revisions, request replay and mandatory audit writes. Opening preserves the original affected version snapshots. Returns use the existing RPMS Return for Revision action; Students upload new versions in the original document chain. Resolution requires a distinct accepted current successor, a higher version number, and a predecessor chain reaching each affected version.

RPMS explicitly chooses the Amendment policy for every case. When required, the Amendment must have been formally submitted after the current case opened and accepted for that case. An accepted Amendment from an earlier case cannot satisfy a new one. When optional, its absence never blocks resolution. An affected Amendment must have the required policy. Final Report review revisions precede its formal submission; official correction cases must be resolved before that submission can complete IERB.

Corrections interrupted at Initial Submission return to Initial Submission at 0%; those interrupted at Approval of Data Gathering return there at 50%. Reconfirmation of already accepted initial evidence is a distinct normal-path verification after resolution. Repeated histories, administrator attribution, reasons, timestamps and resolution evidence remain stored.

## Final Report transaction and completion evidence

`document_workflow_change()` retains live authorization, assignment/current-version validation and Adviser review. Approval invokes the existing `document_formal_submission()` automatically. That function records formal submission and its audit/history, then invokes `ierb_v14_document_event(..., 'final_report_submitted', documentId)` inside the same transaction. Existing reasoned RPMS Admin Override submission retains its existing authorization safeguards and also obeys all earlier-stage evidence checks.

The adapter verifies all 13 current accepted initial requirements, Form 33 institutional acceptance, the exact approved/formally submitted Final Report and its audit, the Gathering stage and absence of an unresolved case. It writes completion and the mandatory audit before commit. There is no frontend completion callback or extra completion endpoint.

The event key is derived from the document identity. Student locking and durable workflow request/revision constraints prevent duplicate completion. An identical authorized review retry returns a no-change result after verifying the live actor/current version. Document state, formal-submission evidence, V14 event/revision, history and queued notices share the transaction. Notification delivery occurs after commit; provider failure cannot undo or duplicate completion. V14 document versions are protected from single/bulk deletion, and completed submission evidence cannot be invalidated by a later return or replacement upload.

See [final-report-completion.json](PRISM_V14_BATCH_02_EVIDENCE/final-report-completion.json) for the captured synthetic workflow, exact document/version, completion event and its initial/Form 33/submission audit references, concurrent results and zero additional Final Report acceptance events. Tests explicitly verify that the unenrolled partner is not declared completed.

## Multiple-file upload and checklist UI

The existing upload implementation processes files independently. Every selected file now has its own document-type selector and validated `documentTypes[]` entry. The backend validates exact list/file alignment and each category, while retaining scalar `documentType` compatibility. PHP transport validation, PDF/DOCX restrictions, MIME/content/size checks, uploader identity, permissions, current-version locking, version numbering, session-bound duplicate receipts and existing notifications remain in force.

Mixed batches retain successful files and report individual category/content failures. The receipt fingerprint covers the per-file mappings. Uploads alone never create acceptance or completion evidence. Enrolled holders use explicit category storage buckets; revisions preserve their original type/stage chain. Those buckets do not map a legacy Student into V14.

Students see the checklist in My Documents. Staff can open it from document rows or the submission-holder picker, including when no document rows exist. Only RPMS receives acceptance controls. Form 33 acceptance collects the institutional approval reference and reason. No manually toggled acceptance checkboxes exist. Per-file assignments survive Student readiness rerenders; new dialogs use the existing confirmation, request and busy-state services.

## Security and compatibility

- Acceptance requires same-origin POST, current session generation, a live active eligible Admin, current Student eligibility, revision matching, version/owner/category evidence, formal-submission audit and atomic audit/notification persistence.
- Student/Adviser acceptance attempts, another Student's checklist, wrong categories/owners, fabricated authoritative fields, stale revisions and changed request digests are rejected.
- Checklist responses expose current requirement state and evidence references without internal case reasons, administrator attribution or unrestricted source-document access.
- Existing Student and assigned Adviser permissions remain enforced on upload, revision and review. No security, legal-acceptance, lifecycle, migration or retention safeguard was removed.
- Legacy records are not enrolled or relabeled. Legacy document workflow, current lists, group/individual advancement, histories, summaries, reports, notifications and schema compatibility retain their regression coverage. The V14 guard continues to prevent legacy stage writers from modifying enrolled holders.
- Legacy aggregate dashboards, report/export interpretation and group-wide status synchronization remain on their existing model pending the separate Batch 3 integration; V14's checklist/Student summary is explicit. This batch does not claim organization-wide rollout or group-wide completion.

## Changed-file inventory

| Files | Purpose |
| --- | --- |
| `includes/ierb_v14_requirements.php` | Requirement definitions, current evidence, acceptance/reconfirmation ledger, document transitions, correction resolution, retention guard and shared checklist. |
| `includes/ierb_v14_workflow.php` | Connect the document hook; preserve explicit CLI gate and reject contradictory correction selections. |
| `includes/ierb_stages.php` | Accurate document-integration contract metadata; unchanged four-stage structure and HTTP activation boundary. |
| `includes/document_workflow.php` | Atomic Final Report completion, safe review retry and terminal evidence protection. |
| `includes/document_catalog.php` | Approved labels/forms, historical catalog input compatibility. |
| `includes/document_management.php` | Protect retained V14 documents in bulk preview and deletion. |
| `documents_api.php` | Authorized checklist/acceptance actions, per-file category validation, enrolled upload buckets and single-delete protection. |
| `ierb_api.php` | Separate read-only V14 summary on existing scoped rows. |
| `assets/js/document-upload.js`, `assets/js/documents.js`, `assets/js/role-portal.js` | Per-file category controls, staff checklist access and Student V14 display. |
| `assets/js/ierb-requirements.js`, `assets/css/prism-ui.css` | Shared checklist, evidence display, reasoned acceptance and responsive presentation. |
| `documents.php`, `role_portal.php` | Checklist surfaces and script wiring. |
| `maintenance/ierb-group-tests/run.cjs` | Copy additive assets, run focused document phase or full regression with Batch 2. |
| `maintenance/ierb-v14-tests/documents-fixture.php`, `maintenance/ierb-v14-tests/documents.cjs`, `maintenance/ierb-v14-tests/README.md` | Guarded fixture workers, HTTP/browser/transaction tests and reproduction instructions. |
| `PRISM_V14_BATCH_02_EVIDENCE/*` | Preparation, syntax, foundation/legacy/document acceptance results, captured synthetic completion and selected responsive screenshots. Runtime databases/apps/sessions/dependencies are excluded. |
| `PRISM_V14_BATCH_02_REVIEW.md` | This report. |

## Acceptance results

All final acceptance checks below passed. Final acceptance failures: **0**. Results are summarized in [acceptance-summary.json](PRISM_V14_BATCH_02_EVIDENCE/acceptance-summary.json).

| Check | Result / evidence |
| --- | --- |
| PHP syntax | 132/132 files PASS; `php-syntax.json` |
| JavaScript syntax | 6/6 changed/new scripts PASS; `javascript-syntax.json` |
| Reviewed foundation and migration suite | 99 assertions PASS; `foundation-tests.json` |
| Full legacy v11 suite | 310 assertions PASS; `legacy-v11.json` |
| Full v12 legacy plus Batch 2 integration | 528 assertions PASS; `legacy-v12-and-documents.json` |
| Final focused document/UI refresh | 232 assertions PASS, including 219 Batch 2 document assertions; `document-tests.json` and `focused-documents.json` |
| Diff and staged whitespace | `git diff --check` and `git diff --cached --check` PASS before commit |

Coverage includes all 13 initial categories, pending/missing/denied/returned documents, automatic Adviser submission, authoritative acceptance and audit-failure rollback, mixed Student/Admin uploads with invalid content/category partial failures, duplicate requests, wrong ownership, replacement chains, optional/mandatory/repeated Amendments, both correction resume contexts, supporting categories, Form 33 browser acceptance, Final Report early-condition refusal, submission/completion fault rollback, concurrent acceptance/approval, retry, provider failure, role-scoped reads/writes, no partner fanout and no unauthorized activation. Responsive legacy Chromium checks run at 390, 768, 1024, 1440 and 1920 pixels in light/dark; checklist captures run at 390/1440 in both themes.

Three exploratory browser runs failed on test harness navigation/selection/onboarding timing. The exact errors and assertion counts are retained in `development-failures.json`; the selectors, tab URL and tour acknowledgements were corrected. These runs are not reported as passing. Final source review also restored an unintended UTF-8 conversion in the Student script. The final acceptance runs have zero failures. The isolated schema rollback/server shutdown receipt is `teardown.json`.

## Batch 3 dependencies and stop condition

Canonical project/group identities, explicit evidence sharing across owners, group membership synchronization and group-wide completion are deferred. Do not infer them from Student document ownership or legacy stage numbers. General rollout and legacy aggregate/report integration remain outside this batch.

Activation/deployment status: **no operational activation, live migration or deployment**. Commit/push status is verified by the generated ending-SHA receipt linked above. Stop after the authorized commit, push and remote-SHA verification. Batch 3 requires the user's separate review, minor testing and explicit authorization.
