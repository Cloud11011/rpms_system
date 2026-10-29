# PRISM V14 7R: "Major Revision of PRISM" compliance matrix

**For:** RPMS reviewers. Every row of the three-page requirements document, with its status after 7R.

**Status meanings:**
- **PASS:** behaviour verified by an executed test in this batch.
- **PASS (pending activation):** verified in tests, but usable on Hostinger only after migration to schema 15 and V14 activation.
- **PARTIAL:** implemented with a stated gap, or an institutional decision is open.
- **NOT VERIFIED:** no executed evidence.

Code existing is never enough for PASS.

Run labels refer to [PRISM_V14_7R_SECURITY_AND_REGRESSION.md](PRISM_V14_7R_SECURITY_AND_REGRESSION.md) §2. "S11" means it works on the deployed schema 11 today.

| # | Source | Requirement | Status | Evidence / gap |
| --- | --- | --- | --- | --- |
| 1 | Reviewer A | Allow multiple file submissions instead of one specific document | **PASS** (S11) | Typed multi-file upload with a cumulative queue (Batch 2–6 suites in runs 01/03). 7R: any verified member uploads to the shared project record (run 10). |
| 2 | Reviewer A | Documents needed per stage are all the documents for that stage | **PASS (pending activation)** | 13 Initial requirements, Amendment Form, Form 33, Form 33-0B in the V14 checklist (runs 03, 06, 10) |
| 3 | Reviewer A | Show submitted documents when hovering on the progress bar | **PASS (pending activation for V14 rows)** | Batch 5 monitor hover (run 05); legacy rows S11 |
| 4 | Reviewer A | Cancel/Submit more visible; enable/disable until information is done | **PARTIAL** | Gated Save/Submit across forms (run 01). Reasoned dialogs, including the new correction dialogs, validate on confirm with an inline error: decision **D4**. |
| 5 | Reviewer A | Most updated / latest submission on top; date/time history | **PASS** (S11) | Monitor latest-first (05); documents newest (01). 7R: Student Records newest updated by default, Reports newest generated, Deadlines nearest upcoming (run 10). |
| 6 | Reviewer A | Account UI/UX | **PARTIAL** | Account and profile UX (run 01); scope acceptance is decision **D10** |
| 7 | Reviewer A | Different colors for Student and Adviser | **PASS** (S11) | Role accents and contrast checks, light and dark (run 01) |
| 8 | Reviewer B | Placeholders on input text forms | **PASS** (S11) | Placeholder presence and contrast (run 01) |
| 9 | Reviewer B | Data versioning, specifically AI summarization; other records that need it | **PASS (pending activation)** for summaries; **PARTIAL** for "other records" | Append-only summary versions and report snapshots (run 04); scope is decision **D8** |
| 10 | Reviewer C | Report generation with the title of the research | **PASS** (S11) | 7R adds Research Titles to the AI Full and Summarized reports (case table + title table) and canonical project titles; the track sheet already led with the title. PDF text verified by parsing the generated file (run 10). |
| 11 | Reviewer C | Multiple submission of documents per submission | **PASS** (S11) | As #1 |
| 12 | Reviewer C | Unique group names based on the research title | **PASS (pending activation)** | Title-based, collision-safe, stable identifiers with retained aliases; trailing-hyphen defect fixed (B7-F22, run 10). Legacy group labels remain until reconciliation. |
| 13 | Reviewer C | Admin can add a new school year | **PASS (pending migration to 15)** | Dynamic academic-year catalog (run 05; matrix on 12.3.2) |
| 14 | Reviewer D | Fix structure of AI reports / summarization results | **PASS** for structure; accuracy **NOT VERIFIED** | Factual tables, Research Titles, canonical projects counted once, sources, limitations and human-verification section (run 10). Live-model accuracy review is decision **D9**. |
| 15 | Reviewer D | New stage process: Stage 1 (13 requirements), Stage 2 Resubmission/Amendment (affected files + Amendment Form), Stage 3 Approval for Data Gathering (Form 33), Stage 4 IERB Completed (Form 33-0B) | **PASS (pending activation)** | Stage machine and requirements (runs 02/03/06). 7R adds Adviser request → RPMS approve/reject, RPMS-initiated cases with a per-case Amendment decision, and resolution (run 10). Operational only after migration and decision **D1**. |
| 16 | Our revisions | IERB Progress dashboard row selection | **PASS** | Row selection and comparison (run 05) |
| 17 | Our revisions | Oldest/Newest/Alphabetical sorting for records that need it | **PASS** for implemented lists; acceptance is decision **D3** | Documents, monitor, notifications, management lists (earlier batches); 7R adds Student Records, Reports and Deadlines (run 10) |
| 18 | Our revisions | Verify AI summary accuracy; fix summarization UI; downloadable PDF | Download and UI **PASS (pending migration to 14)**; accuracy **NOT VERIFIED** | Run 04; the PDF now keeps Filipino/Spanish diacritics (7R-D). No live-model accuracy sample yet (**D9**). |
| 19 | Our revisions | Numeric-only IDs | **PARTIAL** | New and changed IDs are numeric with leading zeros kept (run 01). Production has 17 historical non-numeric IDs and 6 blank IDs pending onboarding: decision **D5**. |
| 20 | Our revisions | Required fields have red asterisks | **PASS** (S11) | Asterisk visibility and contrast (run 01); new dialogs mark required choices |
| 21 | Our revisions | Delete confirmation | **PASS** (S11) | Run 01 |
| 22 | Our revisions | Deletion popup shows file name and uploader | **PASS** (S11) | Run 01 |

**Summary (22 rows):**
- **12 PASS** without migration: rows 1, 5, 7, 8, 10, 11, 14 (structure), 16, 17, 20, 21, 22. Row 17's scope still needs acceptance (**D3**).
- **7 PASS that are usable only after migration**: rows 2, 3, 9, 12, 13, 15, 18. Rows 2, 3, 12 and 15 also need V14 activation (**D1**).
- **3 PARTIAL**: rows 4 (**D4**), 6 (**D10**), 19 (**D5**). Row 9 is also partial for "other records" (**D8**).
- **NOT VERIFIED**: live AI accuracy, which is part of rows 14 and 18 (**D9**).
