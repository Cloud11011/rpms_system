# PRISM V14 7R: Group workflow review (7R-B and 7R-C)

**For:** RPMS reviewers and the developer who will review the code. It explains how Decisions A–D are implemented on the reviewed schema-15 design, without a new schema version.

## 1. Model: one project, one evidence record, many contributors

The reviewed schema has one authoritative workflow per canonical project (`research_projects.workflow_id`), anchored on one Student row (`submission_holder_id`). 7R keeps that anchor, because it is what guarantees a single workflow, single version chains and once-per-project requirement satisfaction. It **redefines what the anchor means**:

| Before 7R (Batch 3 design) | After 7R (Decision A) |
| --- | --- |
| "Submission holder" owned the evidence; other members could not upload project evidence, open its files, or trigger completion | The anchor is only the **project evidence record**. Every verified member uploads into it, revises it, opens its files and version history, and formally submits Adviser-approved versions. |
| A member's upload went to their own record and never counted | A member's upload joins the project's version chain on the evidence record; `uploaded_by` and the audit actor are the individual member |
| PI could be confused with the holder | PI is a separate, RPMS-recorded fact (`pi_designated` project event) with **no** permission effect |

"Verified member" means an explicit `research_project_members` row created by reviewed reconciliation. Names, titles, group labels and protocol codes never grant membership. A Student outside the project is refused (403) for files, version history, checklist and submission.

### Code paths (server-enforced)

| Behaviour | Where |
| --- | --- |
| Verified-member resolution for a live login | [includes/research_projects.php](includes/research_projects.php) `research_project_member_actor()` |
| Opt-in member admission in the shared eligibility gate (only reviewed paths pass `true`) | [includes/current_workflow_eligibility.php](includes/current_workflow_eligibility.php) `current_workflow_student(..., $projectMember)` |
| Upload redirection to the evidence record, re-verified under locks; uploader notice and audit | [documents_api.php](documents_api.php) `upload_document_file()` |
| Read access (file, versions, summaries) and list scope | `research_project_student_can_read()`; [includes/document_management.php](includes/document_management.php) `document_list_query(..., $projects)` |
| Member formal submission | [includes/document_workflow.php](includes/document_workflow.php) operation `member_submit` |
| Final Report by a member completes IERB for every member | [includes/ierb_v14_requirements.php](includes/ierb_v14_requirements.php) `ierb_v14_apply_document_event()` and `research_project_validate()` |
| Checklist exposes shared document references, research title, PI and upload policy | `ierb_v14_checklist()` |
| PI designation (Admin, reasoned, revision-checked) | `research_project_designate_pi()` (CLI `reconcile-research-project.php designate-pi`, HTTP `v14_project_pi`) |
| Track sheet PI line | [includes/ierb_track_report.php](includes/ierb_track_report.php) |

## 2. Formal submission routes and duplicate prevention

| Route | Who | When |
| --- | --- | --- |
| Adviser approval auto-forwards (unchanged) | Assigned Adviser | Approving a current version formally submits it in the same transaction |
| Member formal submission (new) | Any verified member, or the record owner | Only for a current version that is **Adviser-approved** (`reviewed_by` set, no RPMS override) and not yet submitted or returned. The client must state the exact `versionNo`. |
| Reasoned Admin override submission (unchanged) | RPMS Admin | With an override reason |

All routes run in `document_workflow_change()` under the same Student and version row locks. The submission stamp is a single-row `UPDATE … WHERE rpms_submitted_at IS NULL` that must affect exactly one row. A concurrent second route therefore receives 409, and exactly one formal-submission audit exists. This is tested with two members racing, and with a later member retry.

## 3. Adviser correction requests (Decision C)

Implemented on the **existing reviewed schema-12 event ledger** (`ierb_workflow_events`). No new table and no schema 16. [includes/ierb_v14_corrections.php](includes/ierb_v14_corrections.php):

| Step | Event | Official stage | Who |
| --- | --- | --- | --- |
| Request | `correction_requested`. Evidence: proposed current versions, Amendment suggestion, `status: pending_rpms_review` | **Unchanged** (revision advances so concurrent decisions refresh) | Assigned Adviser only |
| Reject | `correction_request_rejected`, naming the request, with a reason | Unchanged | RPMS Admin only |
| Approve | `open_correction`, naming the request (`requestDecision: approved`), with RPMS's own Amendment decision and affected versions | → Resubmission / Amendment (Stage 2) for the whole project | RPMS Admin only |
| RPMS-initiated case | `open_correction`; a pending request is recorded as `superseded_by_rpms_initiated_case` | → Stage 2 | RPMS Admin |
| Resolve | `resolve_correction` (unchanged rules: accepted successors in each original chain, plus the Amendment when required) | → interrupted stage | RPMS Admin |

**Pending** means: the latest `correction_requested` event for the workflow, with no later decision event naming it.

**Guarantees:**
- one pending request per workflow;
- no request while a case is open or at a stage that cannot open a case;
- the workflow revision is checked on every decision;
- request keys are idempotent and digest-bound;
- events are immutable;
- Advisers cannot decide (403), and Students cannot request (403).

**Notifications:**
- request → every verified member and every active RPMS Admin;
- decision → every member and the Adviser;
- stage changes → the existing project fan-out.

**Read model:** Students see "Pending RPMS Review" without staff reasons; staff see the reason, proposed versions and requester.

**Why the ledger rather than a new table.** A pending request is a decision-history fact, which is exactly what the reviewed append-only ledger records, with the same idempotency, revision and audit guarantees. The alternative (a dedicated `ierb_correction_requests` table, schema 16) would add a migration step and contract for no additional guarantee. It is recorded as optional proposal R2 in the spec; it is **not** implemented and was not introduced silently.

## 4. Enrollment, reconciliation and activation (B7-F01)

| Capability | CLI (operator) | HTTP (Admin) |
| --- | --- | --- |
| Enroll | `maintenance/v14-operations.php enroll` | `v14_enroll` |
| Reconciliation preview / apply | `reconcile-research-project.php preview/apply` | `v14_project_preview` / `v14_project_reconcile` |
| Rename (audited, aliases kept) / reassign Adviser / designate PI | `rename` / `reassign-adviser` / `designate-pi` | `v14_project_rename` / `v14_project_adviser` / `v14_project_pi` |
| Correction request / decide / open / resolve / queue | `correction-request/-decide/-open/-resolve/-queue` | `v14_correction_*` |

**The HTTP routes are inert until activated.** `ierb_v14_gate()` accepts HTTP only when `config.local.php` defines `PRISM_IERB_V14_OPERATIONS === true` (default: not defined; refused with 409). Every mutation requires:
- POST and same-origin;
- a session-generation match and the required role;
- a live account re-validated under locks;
- a request key and the expected revision.

Authoritative fields in the body are refused. Turning activation on is institutional decision D1.

**UI:** the shared requirements panel (Documents page and Student portal) shows the research title, the recorded PI, the shared-upload policy and pending-request status. When V14 operations are activated:
- the assigned Adviser gets **Request correction case**;
- RPMS gets **Approve request**, **Reject request**, **Open correction case** and **Resolve correction case**.

These use the shared accessible confirmation dialog (focus management, Escape, required reason, required Amendment choice). An enrollment and reconciliation **form** UI is not built; those remain CLI or API operations until D1 is decided.

## 5. Stage rules preserved (Decision D)

The following are unchanged and covered by the existing Batch 2–6 suites:
- the four stages;
- 13 Initial requirements accepted before advancement;
- RPMS-only ordinary advancement;
- a conditional, repeatable Stage 2 with a per-case Amendment decision;
- automatic completion on a valid Final Report formal submission (now also by a member);
- four report rows;
- no stage builder.

## 6. Superseded tests

Batch 3 asserted the old isolation rule ("membership never exposes another Student's private file"; "only the holder can formally submit the Final Report"). Those assertions were rewritten to the authoritative Decision A in [maintenance/ierb-project-tests/projects.cjs](maintenance/ierb-project-tests/projects.cjs), and an explicit **non-member** isolation check was added. The member-uploaded Final Report version is then the one whose formal submission completes the project.

## 7. Known limits

- Removing a member, moving evidence between projects, or merging independently enrolled workflows still requires separate institutional reconciliation. No automated merge exists.
- A member's documents uploaded **before** reconciliation stay on their own record. They do not count for the project unless re-uploaded to it, because they are never silently moved.
- End-to-end "member formally submits an Adviser-approved Final Report → completion" is exercised at code level; the HTTP test covers member submission of an ordinary requirement plus Adviser-route Final Report completion of a member-uploaded version. A staging walkthrough remains (runbook §7).
