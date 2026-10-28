# PRISM V14 — PDF requirements traceability (provisional draft)

Prepared: 2026-10-28 (Asia/Manila), at the end of Batch 6. Source: **Major Revision of PRISM** (three pages; columns: Sir Deuna, Sir Lee, Doc Josan, Maam Kamile, Our Revisions).

**This is an audit handoff, not a compliance certificate.** Batch 7 must independently inspect the source, run the tests and verify each row in the UI. A row is not proven merely because a batch report says it passed.

## Status vocabulary

| Status | Meaning in this draft |
| --- | --- |
| Implemented | Code exists and a suite that exercises it **was executed in Batch 6** and passed (see the Batch 6 review). Still preliminary. |
| Partially Implemented | Part of the requirement is met, or it is met only after a pending migration/activation. |
| Not Implemented | No implementation found. |
| Unverified | Code exists, but Batch 6 did not run a test that exercises it. |
| Requires Institutional Clarification | The PDF wording leaves scope open; an institutional decision is needed. |

**Deployment context.** Hostinger runs **schema 11**. Schemas 12–15 and V14 workflow enrollment are **not applied**. Where a row depends on them, it is marked Partially Implemented. The code ships, but the feature is not operational until the reviewed migration and activation.

## User-confirmed interpretations (authoritative where the PDF is ambiguous)

1. Stage 2 (Resubmission/Amendment) is **conditional**: RPMS opens a correction case, and resolution returns to the interrupted review stage.
2. RPMS decides for **each correction case** whether the Amendment Form is required.
3. Admins cannot create or remove stages. There is no stage builder.
4. The 13 Initial Submission requirements apply **once per verified research project**, not to every group member.
5. Normal stage advancement requires an authorized RPMS action.
6. Formal submission of the Final Report (Form 33-0B) **automatically** completes IERB.
7. The track-sheet report uses only Initial Submission through 3rd Resubmission.
8. That report limit does **not** impose a three-correction limit on the workflow.

## Traceability matrix

Columns: **#**, **Page**, **Source**, **Requirement** (faithful paraphrase), **Interpretation**, **Batch**, **Source files**, **UI / API**, **Evidence**, **Preliminary status**, **Batch 7 action**.

### Sir Deuna (page 1 column 1, continues on pages 2–3)

| # | Pg | Requirement | Interpretation | Batch | Source files | UI / API | Evidence | Status | Batch 7 action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| D1 | 1 | Allow multiple file submissions instead of a single specific document | Each queued file has its own document-type selector; cumulative browsing | 2, 3 | `includes/document_upload.php`, `assets/js/document-upload.js`, `documents_api.php` | Student My Documents; staff upload modal; `documents_api.php?action=upload` | Batch 2/3 upload suites; re-run in Batch 6 regression | Implemented | Verify in UI on a schema-11 restore. Multi-file works on legacy records; V14 buckets need schema 12+. |
| D2 | 1 | Documents needed for each stage are all the documents to fill in for that stage | Stage-specific mandatory requirement lists (13 initial, Form 33, Form 33-0B; conditional Amendment) | 2 | `includes/ierb_v14_requirements.php`, `assets/js/ierb-requirements.js` | Requirement checklist; `documents_api.php?action=requirements` | Batch 2 document suite (schema 12+) | Partially Implemented | Operational only after schema 12+ migration and explicit enrollment. Legacy schema-11 records keep the old model. |
| D3 | 2 | Show which documents have been submitted when hovering on the progress bar | Hover/focus popover plus click/tap panel; version-bound states | 5 | `includes/ierb_monitor.php`, `assets/js/ierb-monitor.js` | Dashboard and IERB Progress monitor | Batch 5 browser suite | Implemented (monitor), Unverified for legacy-only data | Verify with legacy rows on a schema-11 restore and with enrolled projects. |
| D4 | 2 | Cancel/Submit: more visible; enable only when required information/process is complete | Gated Save/Submit, loading state, duplicate-request prevention, Cancel always available | **6** | `assets/js/prism-forms.js`, `assets/css/prism-forms.css`, `assets/js/prism-ui.js`, page forms | Account, Student profile, Student Records, IERB entry/note, profile completion, deletion dialogs | `maintenance/ierb-ui-tests/integration.cjs` (Batch 6) | Implemented for the gated forms listed in the Batch 6 review; Partially elsewhere | Inventory the remaining forms in the Batch 6 review that keep the "inline error on click" pattern; decide whether to gate them too. |
| D5 | 2 | Latest data/submission on top; add date/time history of submission | Default newest verified formal submission; paginated history in Asia/Manila | 5 | `includes/ierb_monitor.php`, `ierb_api.php` | Monitor; history dialog; Student directory | Batch 5 suite | Implemented (monitor); Unverified for other lists | Confirm default ordering on Documents, Reports and Notifications lists. |
| D6 | 3 | Account UI/UX | Clear editable vs read-only fields; labels, placeholders, password visibility, gated Save, feedback near form | **6** | `account.php`, `role_portal.php`, `assets/js/account.js`, `assets/css/prism-forms.css` | `account.php`, Student portal Profile | Batch 6 suite | Implemented | Run a usability walkthrough with each role. "Account UI/UX" is open-ended, so record any institutional expectations. |
| D7 | 3 | Differences for Student and Adviser in terms of colors | Student keeps PRISM magenta; Adviser uses PRISM indigo; text and icon role chip | **6** | `assets/css/prism-forms.css`, `role_portal.php`, `includes/prism-navigation.php` | Student portal; Adviser pages | Batch 6 contrast and role checks (`role-colors.json`) | Implemented | Get institutional sign-off on the chosen palette. |

### Sir Lee (page 1 column 2, continues on page 2)

| # | Pg | Requirement | Interpretation | Batch | Source files | UI / API | Evidence | Status | Batch 7 action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 1 | Form input UIs: add placeholders to text inputs for visibility | Context-appropriate placeholders. Labels stay visible. No placeholders on selects | **6** | Forms listed in the Batch 6 review | All data-entry forms | Batch 6 placeholder checks | Implemented | Spot-check every form in both themes. |
| L2 | 1 | Data versioning for all data that needs it, especially AI summarization | Document version chains; append-only summary versions; report snapshots | Existing, 4 | `includes/document_summary_history.php`, `includes/schema_v14.php`, `documents_api.php` | Summary version selector; document versions | Batch 4 suite | Partially Implemented | Summary history requires schema 14. Define which "other records" need versioning (Requires Institutional Clarification). |
| L3 | 2 | Unique group names based on the research title instead of generated group IDs (also Doc Josan) | Title-derived immutable public identifier with collision suffix | 3 | `includes/research_projects.php`, `maintenance/reconcile-research-project.php` | Canonical project identity | Batch 3 project suite | Partially Implemented | Requires schema 13 plus operator reconciliation. Legacy cohort-number groups remain on schema 11. |
| L4 | 2 | Admin can add a new school year (scalability) (Doc Josan column) | Additive academic-year catalog with audited Admin creation | 5 | `includes/academic_years.php`, `academic_years_api.php`, `includes/schema_v15.php` | Academic year manager | Batch 5 suite | Partially Implemented | Adding years requires schema 15; existing years remain selectable on schema 11. |

### Doc Josan (page 1 column 3, continues on page 2)

| # | Pg | Requirement | Interpretation | Batch | Source files | UI / API | Evidence | Status | Batch 7 action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| J1 | 1 | Include report generation with the research title | Track sheet shows the canonical research title | 4 | `includes/ierb_track_report.php`, `includes/report_pdf.php`, `reports_api.php` | Reports | Batch 4 PDF/render suite | Partially Implemented | Project track sheets require schema 13/14. Check legacy report titles on schema 11. |
| J2 | 1 | For each submission, multiple submission of documents | Same as D1 | 2, 3 | see D1 | see D1 | see D1 | Implemented | As D1. |
| J3 | 2 | Unique group names from research title | Same as L3 | 3 | see L3 | | | Partially Implemented | As L3. |
| J4 | 2 | Admin can add a new school year | Same as L4 | 5 | see L4 | | | Partially Implemented | As L4. |

### Maam Kamile (page 1 column 4, continues on page 2)

| # | Pg | Requirement | Interpretation | Batch | Source files | UI / API | Evidence | Status | Batch 7 action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| K1 | 1 | Fix structure of AI reports / summarization results | Sectioned summaries; source-constrained progress narrative | 4 | `includes/document_summary_ai.php`, `includes/ierb_track_actions.php`, `assets/js/documents.js` | Summary modal; AI progress reports | Batch 4 suite (mock provider) | Implemented (structure) | Review real outputs with RPMS. |
| K2 | 1 | New stage process: Stage 1 Initial Submission | Fixed semantic stage `initial_submission` | 1, 2 | `includes/ierb_stages.php`, `includes/ierb_v14_workflow.php` | Checklist; progress | Batch 1 foundation suite | Partially Implemented | Activation and migration are pending. |
| K3 | 1 | 13 Initial Submission documents (Certification of Ethics … Study Protocol) | Once per verified project (interpretation 4) | 2, 3 | `includes/ierb_v14_requirements.php` | Checklist | Batch 2/3 suites | Partially Implemented | Verify labels exactly match the PDF wording; for example, the PDF spells "Diagramatic Workflow" and the code uses "Diagrammatic Workflow". |
| K4 | 1–2 | Stage 2 Resubmission/Amendment: all files available in stage, plus an Amendment Form; revision/resubmission | Conditional correction case; per-case Amendment decision (interpretations 1–2) | 1, 2 | `includes/ierb_v14_workflow.php`, `includes/ierb_v14_requirements.php` | RPMS correction CLI/services | Batch 1/2 suites | Partially Implemented | Correction opening and resolution are CLI-gated and have no general HTTP activation. Confirm this operating model with RPMS. |
| K5 | 2 | Stage 3 Approval of Data Gathering: Form 33 Certificate of Approval | RPMS acceptance with institutional approval reference | 2 | `includes/ierb_v14_requirements.php`, `assets/js/ierb-requirements.js` | Form 33 acceptance dialog | Batch 2 suite | Partially Implemented | Activation is pending. |
| K6 | 2 | Stage 4 IERB Completed: Form 33-0B Final Report Form | Automatic completion on authorized formal submission (interpretation 6) | 2, 3 | `includes/document_workflow.php`, `includes/research_project_advancement.php` | Adviser approval → formal submission | Batch 2/3 suites | Partially Implemented | Activation is pending. |

### Our Revisions (page 1 column 5, continues on pages 2–3)

| # | Pg | Requirement | Interpretation | Batch | Source files | UI / API | Evidence | Status | Batch 7 action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R1 | 1 | IERB Progress in the dashboard should include row selection | Read-only multi-record selection and comparison (bounded at 100) | 5 | `includes/ierb_monitor_view.php`, `assets/js/ierb-monitor.js` | Dashboard / IERB Progress | Batch 5 suite | Implemented | Confirm that read-only comparison meets the panel intent. |
| R2 | 1 | Oldest/Newest/Alphabetical sorting for all records that need it | Monitor, Documents, Student portal and Adviser queue have newest/oldest/A–Z | 5 (+ existing) | `includes/ierb_monitor.php`, `assets/js/documents.js`, `includes/record_filters.php` | Several lists | Batch 5 suite (monitor only) | Partially Implemented | Inventory Reports, Notifications and Student/Adviser Records lists. "All records that need it" Requires Institutional Clarification. |
| R3 | 2 | Verify AI summary accuracy; fix document summarization UI; downloadable PDF | Source-only prompts, de-identification, version PDFs | 4 | `includes/document_summary*.php`, `includes/report_pdf.php` | Summary modal; PDF download | Batch 4 suite (mock provider) | Partially Implemented | **Live-model accuracy is not certified.** Human review is required. Generation requires schema 14. |
| R4 | 2 | Numeric only for IDs | Student/School ID: digits only for new or changed values; leading zeros kept; historical IDs retained; Employee IDs unchanged | **6** | `includes/account_onboarding.php` (`onboarding_student_id`), `students_api.php`, `ierb_api.php`, `assets/js/prism-forms.js`, `complete_profile.php`, `admin_people.php`, `ierbprog.php` | Profile completion; Student Records; IERB entry | Batch 6 suite | Implemented | Confirm with RPMS that Employee IDs are out of scope and decide how to treat existing alphanumeric Student IDs (see Batch 6 limitations). |
| R5 | 2 | Required fields should have red asterisks | Attribute-driven markers on enabled, editable required controls | **6** | `assets/js/prism-forms.js`, `assets/css/prism-forms.css` | All pages using `prism_frontend_styles()` | Batch 6 suite plus the inventory in the Batch 6 review | Implemented | Walk every form; resolve the frontend/backend discrepancies listed in the Batch 6 review. |
| R6 | 2 | Delete confirmation | Every document deletion path requires an explicit dialog | **6** (bulk existed) | `assets/js/prism-ui.js` (`confirmDocumentDelete`), `documents.php`, `assets/js/documents.js`, `assets/js/adviser-dashboard.js` | Documents; Adviser dashboard | Batch 6 suite | Implemented | Check non-document deletions (accounts, reminders, notifications) for consistent confirmation. |
| R7 | 3 | Deletion popup shows filename and who uploaded it | Authoritative server record: filename, recorded uploader and role, type, version, record; bulk per-record list | **6** | `documents_api.php` (`delete_preview`), `includes/document_management.php` | Same as R6 | Batch 6 suite | Implemented | — |

## Cross-page continuity notes

- Page 1 → 2: Maam Kamile's Stage 2 continues ("All files are available in Stage, and an Amendment Form"), followed by Stages 3 and 4. Sir Deuna's, Doc Josan's and Our Revisions' rows on page 2 are new items, not continuations.
- Page 2 → 3: Sir Deuna's color differentiation and Our Revisions' deletion popup are the only page-3 items. The remaining rows on page 3 are empty.

## Items explicitly not fully satisfied (as of Batch 6)

1. Every V14 workflow item (D2, K2–K6) and the canonical groups, academic-year additions and summary history (L2–L4, J1, R3) depend on unapplied schemas 12–15 and on gated enrollment.
2. R2 sorting and D5 ordering are not inventoried for every list.
3. R3 live AI accuracy is not certified.
4. D4 gating does not cover every form. See the Batch 6 review for which forms use disabled gating and which use the "prevented on click with an inline error" pattern.
