# 01 · Final requirements matrix: "Major Revision of PRISM" (22 rows)

**For:** RPMS reviewers and the panel's compliance sign-off.

**Source.** The original three-page document, read for this pass (`Major Revision for PRISM (1).pdf`; columns Reviewer A, Reviewer B, Reviewer C, Reviewer D, Our Revisions). Row numbers follow the 7R matrix.

**Technical status** (what executed evidence shows):
- **PASS**
- **PASS PENDING ACTIVATION** (verified, but usable on Hostinger only after migration to schema 15 and, where stated, V14 activation D1)
- **PARTIAL**
- **FAIL**
- **NOT VERIFIED**

**Institutional acceptance** is recorded separately. Technical PASS is not acceptance.

**Schema column:** "S11" means demonstrable on the deployed schema 11 today; "15" means after migration.

**Run labels:** final-chain runs in [08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md) §3, fixture `cc1232` (MariaDB 12.3.2, utf8mb4_general_ci). For example, "01" is the full schema-11 suite including Batch 6, and "11" is the closure F01 test.

| # | Panel source | Requested behaviour (PDF) | Implemented behaviour | Test evidence | UI evidence | Technical status | Schema | Missing work / decision | Acceptance owner · status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Reviewer A | Multiple file submissions instead of one specific document | Typed multi-file queue; any verified member uploads to the shared project record | Runs 01, 03; 7R run 10; F01 (13 uploads by 3 members) | Student upload panel (Batch 2/6) | **PASS** | S11 | — | RPMS · pending sign-off |
| 2 | Reviewer A | Documents needed per stage are all that stage's documents | 13 Initial requirements; Amendment Form per case; Form 33; Form 33-0B | Runs 03, 06; F01 (13 + Form 33 + Final Report) | Requirements checklist | **PASS PENDING ACTIVATION** | 15 + D1 | D1 | RPMS · pending (D1) |
| 3 | Reviewer A | Show submitted documents when hovering on the progress bar | Monitor popover/panel per record, version-bound | Run 05 (Batch 5 browser) | Batch 5 screenshots | **PASS PENDING ACTIVATION** (V14 rows); legacy rows at S11 | 15 + D1 (V14) | D1 | RPMS · pending |
| 4 | Reviewer A | Cancel/Submit more visible; enable only when information is done | Pattern A on 13 forms; pattern B (inline error) on 15 reasoned dialogs. **Pattern B accessibility fixed this pass (B7C-02).** Cancel never gated. | Batch 6 (runs 01, 06, 08×6), 7R run 10, F01 | [05](05_FORMS_AND_SORTING_INVENTORY.md) §A; Batch 6 screenshots | **PARTIAL** | S11 | **D4**: accept pattern B for decision dialogs | RPMS · open (D4) |
| 5 | Reviewer A | Latest submission on top; date/time history of submission | Newest-first defaults (monitor, documents, Student Records, reports, deadlines nearest-upcoming); submission history with date/time | Runs 05, 10; 7R DB-verified order | Monitor and history dialogs | **PASS** | S11 | — | RPMS · pending sign-off |
| 6 | Reviewer A | Account UI/UX | Editable vs read-only fields, notes, gated Save, password visibility, field feedback, role styling. **Asterisk wrap fixed (B7C-05).** All three roles checked. | Batch 6 (Student, Adviser); F06 script (Admin): all checks PASS | [07](07_VERSIONING_AND_ACCOUNT_ACCEPTANCE.md) §B screenshots | **PARTIAL** (open-ended requirement) | S11 | **D10**: RPMS acceptance; 3 minor observations | RPMS · open (D10) |
| 7 | Reviewer A | Different colours for Student and Adviser | Magenta / indigo accents with text and icon chips; ≥ 4.5:1 contrast | Batch 6 `role-colors.json` (every run) | Dashboards, light/dark | **PASS** | S11 | Palette acceptance (D10) | RPMS · open (D10) |
| 8 | Reviewer B | Placeholders on input text forms | Placeholders on editable text fields; contrast 4.92:1 / 7.17:1 | Batch 6 | Batch 6 screenshots | **PASS** | S11 | — | RPMS · pending sign-off |
| 9 | Reviewer B | Data versioning for all data that needs it, especially AI summarization | Summary versions (14), document chains, report snapshots (14), V14 ledger, project history, legal versions; audit-only elsewhere | Run 04 (Batch 4); 7R | Summary version selector | **PARTIAL**: AI summaries PASS PENDING ACTIVATION (14); "other records" scope open | 14 | **D8** ([07](07_VERSIONING_AND_ACCOUNT_ACCEPTANCE.md) §A) | RPMS · open (D8) |
| 10 | Reviewer C | Report generation with the research title | Research Titles in AI Full/Summarized reports (text parsed from the PDF), canonical projects counted once; titled track sheets | 7R run 10; this pass runs 04, 10 | Report PDFs | **PASS** | S11 (AI reports); project track sheets at 13/14 | — | RPMS · pending sign-off |
| 11 | Reviewer C | Multiple submission of documents per submission | As row 1 | As row 1 | As row 1 | **PASS** | S11 | — | RPMS · pending sign-off |
| 12 | Reviewer C | Unique group names based on the research title | Title-derived, collision-safe identifiers with aliases; no trailing hyphen; library-independent | Runs 03, 10 | Checklist project facts | **PASS PENDING ACTIVATION** | 13+ and D1 | D1; legacy labels stay until reconciliation (or cleanup) | RPMS · pending (D1) |
| 13 | Reviewer C | Admin can add a new school year | Additive academic-year catalog, audited | Run 05; migration rehearsal 11→15 | Academic year manager | **PASS PENDING ACTIVATION** (migration) | 15 | Migration | RPMS · pending |
| 14 | Reviewer D | Fix structure of AI reports / summarization results | Sectioned summaries; factual report tables; sources, limitations and human-verification section | Run 04 (mock provider); 7R run 10 (PDF text) | Summary modal; report PDFs | **PASS PENDING ACTIVATION** for structure (reports at S11, summaries at 14). **Live accuracy NOT VERIFIED** (see row 18). | S11 / 14 | D9 for accuracy | RPMS · open (D9) |
| 15 | Reviewer D | New four-stage process (Initial 13 → Resubmission/Amendment → Form 33 → Form 33-0B completed) | Fixed stage machine; RPMS-only advancement; per-case Amendment; correction requests; **a non-PI member's formal Final Report submission completes IERB for every member (F01, over HTTP)** | Runs 02, 03, 06, 10; **run 11 (F01)** | Requirements panel; correction dialogs | **PASS PENDING ACTIVATION** | 15 + D1 | D1; staging items 3–8 | RPMS · pending (D1) |
| 16 | Our revisions | IERB Progress dashboard row selection | Read-only multi-row selection and comparison (up to 100) | Run 05 | Monitor screenshots (Batch 5) | **PASS** | Tested at 12–15; S11 behaviour not separately evidenced in this pass | — | RPMS · pending sign-off |
| 17 | Our revisions | Oldest/Newest/Alphabetical sorting for all records that need it | 14 lists inventoried; 9 with an alphabetical option; date-only for notifications and Help & Support; fixed order for audit logs | 7R run 10; runs 05, 06 | [05](05_FORMS_AND_SORTING_INVENTORY.md) §B | **PARTIAL** (scope not established) | S11 | **D3**: which lists "need" which orders; Adviser records lack a date order | RPMS · open (D3) |
| 18 | Our revisions | Verify AI summary accuracy; fix summarization UI; downloadable PDF | UI and PDF download (Unicode Windows-1252 text kept) | Run 04 (mock); 7R PDF round-trip | Summary modal, PDF | **NOT VERIFIED** for accuracy (no live-model sample); UI/PDF **PASS PENDING ACTIVATION** (14) | 14 | **D9**: protocol in [06](06_AI_ACCURACY_EVALUATION.md) | RPMS · open (D9) |
| 19 | Our revisions | Numeric-only IDs | Digits only for every new or changed Student ID; leading zeros kept; all write paths traced | Batch 6 | Student Records, IERB entry, profile completion | **PARTIAL**: rule PASS; 17 non-numeric + 6 blank historical IDs remain until cleanup | S11 | **D5** via D12 (all are test records) | RPMS + owner · owner-decided, pending cleanup |
| 20 | Our revisions | Required fields have red asterisks | Attribute-driven, `aria-hidden` markers, contrast ≥ 3:1; **same-line placement fixed for labels that do not wrap their control (B7C-05)** | Batch 6 (all runs) | [evidence/f06-account-after-asterisk-fix](evidence/f06-account-after-asterisk-fix/) | **PASS** | S11 | — | RPMS · pending sign-off |
| 21 | Our revisions | Delete confirmation | Every document deletion path uses a confirmation dialog; bulk with token and reason | Batch 6 | Delete dialog screenshots | **PASS** | S11 | — | RPMS · pending sign-off |
| 22 | Our revisions | Deletion popup shows file name and uploader | Server-authoritative preview: filename, recorded uploader and role, version, record | Batch 6 | Delete dialog 390–1920 px | **PASS** | S11 | — | RPMS · pending sign-off |

## Summary (22 rows, technical status)

| Status | Count | Rows |
| --- | --- | --- |
| **PASS** | **10** | 1, 5, 7, 8, 10, 11, 16, 20, 21, 22 |
| **PASS PENDING ACTIVATION** | **6** | 2, 3, 12, 13, 14, 15 (rows 2, 3, 12, 15 also need D1) |
| **PARTIAL** | **5** | 4 (D4), 6 (D10), 9 (D8), 17 (D3), 19 (D5 via D12) |
| **NOT VERIFIED** | **1** | 18 (live AI accuracy, D9; the accuracy aspect of row 14 is tracked here) |
| **FAIL** | **0** | — |

**Institutionally accepted: 0 of 22.** No RPMS sign-off has been recorded for any row.

**Compared with the 7R matrix:**
- Row 14 is now shown as PASS PENDING ACTIVATION, because summaries need schema 14 (7R counted it as PASS without migration).
- Row 17 is shown as PARTIAL rather than "PASS for implemented lists", because its scope is undecided.
- Row 18 is shown as NOT VERIFIED overall rather than split.
- No row was downgraded because of a defect. This pass's three product fixes (B7C-01, B7C-02, B7C-05) strengthen rows 4, 6, 10/12 (title integrity) and 20.

**Full compliance is not declared.** Five rows are PARTIAL, one is NOT VERIFIED, and six need migration or activation that has not happened.
