# 05 · Forms, sorting and Student ID inventory (F03, F04, F05)

**For:** RPMS reviewers deciding D3, D4 and D5, and the code reviewer. Every row was traced in the 7R working tree source; "Test" names the executed suite that covers it in this pass ([08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md)).

## A. Save / Submit / Cancel behaviour (panel row 4, decision D4)

Two patterns exist:

- **Pattern A (disabled until valid):** the action button is disabled until the required input is complete (`data-prism-gate`), with a visible status line linked by `aria-describedby` that says why.
- **Pattern B (validate on confirm):** the button stays enabled. Pressing it with missing input refuses the action, keeps the dialog open and shows an inline error (`role="alert"`).

Cancel is never disabled in either pattern and sends no request.

### A1. Pattern A — disabled until valid

| # | Form | Where | Gate | Test |
| --- | --- | --- | --- | --- |
| 1 | Admin/Adviser profile name | `account.php` | changed and valid | Batch 6 |
| 2 | Admin/Adviser password change | `account.php` | complete and matching | Batch 6 |
| 3 | Student profile | `role_portal.php` | changed and valid | Batch 6 |
| 4 | Student password change | `role_portal.php` | complete and matching | Batch 6 |
| 5 | Student / Adviser record modal (Student Records) | `admin_people.php` | valid (new) / valid and changed (edit) | Batch 6 |
| 6 | Invite Student / Adviser | `admin_people.php` | valid | Batch 6 |
| 7 | IERB entry add/edit | `ierbprog.php` | valid (new) / valid and changed (edit) | Batch 6 (incl. F02 diagnostics) |
| 8 | IERB note | `ierbprog.php` | valid | Batch 6 |
| 9 | Profile completion (onboarding) | `complete_profile.php` | valid | Batch 6 |
| 10 | Help & Support page | `help_support.php` | valid | Batch 6 |
| 11 | Student document upload | `role-portal.js` (`submissionButton.disabled = !ready`) | file and document type chosen | Batch 2/3 uploads |
| 12 | Summary regeneration | `documents.js` | disabled until the preview is confirmed | Batch 4 |
| 13 | Deletion confirmation for a protected record | `prism-ui.js` `confirmDocumentDelete` (`confirmDisabled`) | Confirm disabled; reason shown | Batch 6 |

### A2. Pattern B — validate on confirm, inline error

All but the last row use the shared `PrismUI.confirm` dialog.

| # | Dialog | Where | Required input | Test |
| --- | --- | --- | --- | --- |
| 1 | Adviser review: Deny / Request resubmission | `adviser-dashboard.js`, `documents.js` | remarks | Batch 1/2 |
| 2 | Add reviewer comment | `documents.js` | comment | Batch 2 |
| 3 | Return to Student for Revision (RPMS) | `documents.js` | reason | Batch 2; closure F01 |
| 4 | Accept RPMS requirement (Form 33 also needs the institutional reference) | `ierb-requirements.js` | reason (+ reference) | Batch 2; closure F01 (reference 422) |
| 5 | Request a correction case (Adviser) | `ierb-requirements.js` | reason, affected versions | 7R run 10 |
| 6 | Approve correction request | `ierb-requirements.js` | reason, Amendment decision | 7R run 10 |
| 7 | Reject correction request | `ierb-requirements.js` | reason | 7R run 10 |
| 8 | Open correction case | `ierb-requirements.js` | reason, versions, Amendment decision | 7R run 10 |
| 9 | Resolve correction case | `ierb-requirements.js` | reason | 7R run 10 |
| 10 | Advance research group | `ierbprog.js` | reason | Batch 3 |
| 11 | Delete a formally submitted document (Admin) | `prism-ui.js` | reason (≥ 5 characters) | Batch 6 |
| 12 | Submit to RPMS (Admin override) | `prism-ui.js` | reason | Batch 1/2 |
| 13 | Change IERB progress (advance / correct / status) | `prism-ui.js` | reason | Batch 1 |
| 14 | Admin Override of a document status | `prism-ui.js` | reason | Batch 1/2 |
| 15 | Permanent account deletion | `admin-management.js` | typed identifier, password, acknowledgement | closure cleanup rehearsal |

"Review document" with optional remarks is not gated, because nothing in it is required.

### A3. Defects found and fixed in this pass

- **B7C-02 (accessibility, Low):** in pattern B the reason field was not marked invalid and the error was not linked to it. Screen-reader users heard the alert but the field itself carried no error state. **Fixed** in `assets/js/prism-ui.js`: the field gets `aria-invalid="true"` and `aria-describedby` pointing to the error; both clear once the reason is long enough. A new Batch 6 assertion verifies it (PASS, Batch 6 r6 = 131 assertions; final chain run 01 = 429).
- **B7C-05 (visual, Low):** red asterisks on labels that name a separate control (account password fields, and any label in the shared form containers that does not wrap its control) wrapped onto their own line. The cause is a shared `display:grid` label rule in `prism-workspace.css`. **Fixed** with one rule that renders such labels as a single line ([evidence/f06-account-after-asterisk-fix](evidence/f06-account-after-asterisk-fix/)).

### A4. Remaining gap and recommendation

- Validation errors from a dialog's own checks (`validate()`, for example a missing Amendment decision) are announced by the alert, but focus is not moved to the control in error. Low; record under D4.
- **Recommendation (D4):** keep pattern A for data-entry forms and pattern B for reasoned decisions. Pattern B tells the user exactly what is missing and keeps focus on the field. A disabled confirm button in a decision dialog gives no reason unless extra text is added. Converting all 15 dialogs would be a large UI change with no accessibility gain. The panel wording ("only click … once needed information is done") is met literally by pattern A and in effect by pattern B; RPMS decides whether the effect is acceptable.

## B. Sorting coverage (panel rows 5 and 17, decision D3)

Server sorts are allowlisted: unknown values are refused (422) or fall back to the default. Every order ends with an `id` tie-breaker, so pagination is stable when timestamps are identical.

| # | List | Default order | Alternatives | Alphabetical? | Ties / empty values | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Student Records (Admin; Adviser's assigned) | **Newest updated** (client sends `updated DESC`) | Date added, name, Student ID, email, group, Adviser, stage, status, academic year, archived date; asc/desc | Yes | Tie `s.id ASC`; empty IDs/groups sort first ascending. **The API alone falls back to name A–Z** when no sort is sent (only API callers see this). | 7R run 10 (DB-verified order) |
| 2 | Research Adviser records | Name A–Z | Employee ID, email, archived date, department, status; asc/desc | Yes | Tie `a.id`. **No newest/oldest (date) option.** | Code |
| 3 | Archived accounts | As rows 1–2 with the archived filter | Archived date | Yes | as above | Code |
| 4 | IERB Progress / monitor | **Newest formal submission** | Research Title, name, Student ID, stage (high/low), status, academic year, group; asc/desc | Yes | Missing submission dates sort last | Batch 5 |
| 5 | Documents (Admin/Adviser) | **Newest uploaded** | Oldest, file name A–Z/Z–A, Student A–Z; Admin also stage, review status, recently reviewed | Yes | Tie `d.id` | Batch 5 |
| 6 | Student portal — My Documents | **Newest** | Oldest, name A–Z | Yes | — | Batch 6 |
| 7 | Adviser review queue | **Newest** | Oldest, filename A–Z, Student A–Z | Yes | The `<select>` is visually hidden (`prism-sr-only`) and reached through the queue's filter panel; it was not visually checked in this pass | Code |
| 8 | Reports | **Newest generated** | Oldest, title A–Z/Z–A, type | Yes | Tie `id` | 7R run 10 |
| 9 | Official deadlines | **Nearest upcoming**, then overdue (most recent first), then cancelled | Date asc/desc, title A–Z, recently created | Yes | Overdue flagged explicitly | 7R run 10 |
| 10 | Notifications | **Newest** | Oldest | **No** | Tie `n.id` | Code |
| 11 | Help & Support requests | **Newest** | Oldest | **No** | — | Code |
| 12 | Activity / audit logs | **Newest**, fixed | none | **No** | Tie `l.id DESC` | Code |
| 13 | Data export (CSV) | Name / newest upload, fixed per export | none | n/a (files) | — | Code |
| 14 | Academic years, legal policy versions | Catalog order | none | n/a | — | Code |

**Not verified at runtime in this pass:** pagination combined with every filter for lists 2, 7, 10–12 (the code paths are shared with lists that are tested).

**Recommendation (D3):** accept rows 1, 4–9 as satisfying "Oldest/Newest/Alphabetical". Decide for row 2 (add a date option?) and rows 10–12, where alphabetical order has little meaning. Panel row 17 stays PARTIAL until RPMS records that scope.

## C. Numeric Student IDs (panel row 19, decision D5)

| Path | Rule | Where | Test |
| --- | --- | --- | --- |
| Student Records — new | digits only, 1–100, leading zeros kept, duplicates refused | `students_api.php` → `onboarding_student_id()` | Batch 6 |
| Student Records — edit (Admin) | a changed ID must be digits only; an unchanged historical ID is accepted and never rewritten | same, with the stored value | Batch 6 |
| Student Records — edit (Adviser) | the stored ID is kept; Advisers cannot change it | `students_api.php` (`$adviserEdit`) | code |
| IERB entry — new / edit | same rule | `ierb_api.php` | Batch 6 |
| Invitation | creates a Pending record with **no** ID | `account_onboarding.php` | cleanup rehearsal |
| Profile completion | the Student must enter a digits-only ID | `account_onboarding.php` (`onboarding_finish`) | Batch 6 |
| Student / Admin profile pages | read-only | `role_portal.php`, `account.php` | Batch 6, F06 |
| Import | none exists (no CSV/bulk import of Students) | — | code search |

**Result:** every path that can create or change a Student ID enforces the rule. No historical value was changed in this pass.

**Production facts (owner-supplied export, read-only):** 17 non-numeric and 6 blank IDs out of 27 Students. Under the owner's decision every one of those records is test data, so D5 resolves when the authorized cleanup (D12) is executed and verified. Until then row 19 stays PARTIAL.
