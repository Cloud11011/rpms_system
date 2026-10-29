# 03 · Non-PI member Final Report formal submission over HTTP (F01)

**For:** the code reviewer and RPMS. This closes the 7R gap "end-to-end member Final Report formal submission over HTTP is unverified" ([7R readiness](../PRISM_V14_7R_FINAL_RELEASE_READINESS.md), "Not verified").

## Result: **VERIFIED (PASS)**

| | |
| --- | --- |
| Test | [`maintenance/ierb-7r-tests/closure.cjs`](../maintenance/ierb-7r-tests/closure.cjs) + [`closure-fixture.php`](../maintenance/ierb-7r-tests/closure-fixture.php), hooked by `PRISM_TEST_CLOSURE=1` |
| Command | `PRISM_TEST_SCHEMA_VERSION=15 PRISM_TEST_PHASE=documents PRISM_TEST_CLOSURE=1 node maintenance/ierb-group-tests/run.cjs` |
| Fixture | Disposable local MariaDB **12.3.2**, `utf8mb4_general_ci` (production's shape), migrated 11 → 15 by the reviewed runners; PHP 8.2 built-in server; real login sessions, origin and session-generation guards |
| Final run | [evidence/runs/cc1232/11-closure-final-report-schema15-r2](evidence/runs/cc1232/11-closure-final-report-schema15-r2/): **PASS, 81 module assertions (94 runner total)**, 2026-10-28 22:33 |
| Data | Synthetic records 60–63 only, created through the reviewed services (explicit enrollment, reconciliation from per-Student snapshot hashes, audited PI designation) and fully removed afterwards, including notices and audits |

## Scenario

- One canonical project: "Closure Final Report Project – São José Study" (identifier `closure-final-report-project-sao-jose-study`).
- **Holder 60**, **PI 61** and **member 62** (the submitter, neither PI nor holder).
- **Outsider 63** has their own record.
- Adviser 2 is assigned; Adviser 3 is unassigned.

Everything after setup happens over HTTP:

1. Three members upload the 13 Initial requirements in rotation. Each Adviser approval formally submits the version, and RPMS accepts all 13.
2. RPMS advances the group to Approval of Data Gathering.
3. The holder uploads Form 33; the Adviser approves; RPMS accepts it with the institutional reference.
4. RPMS opens a correction case on the Study Protocol and returns that exact version. The PI uploads its revision, the Adviser approves, RPMS accepts and resolves.
5. Member 62 uploads Final Report v1 and v2, and formally submits the eligible version.

**Why the eligible Final Report is a historical approval.** In the current code, an Adviser's approval formally submits the version in the same transaction. A member-initiated submission therefore applies only to a version approved earlier but never forwarded ("Ready for Formal RPMS Submission"). The test creates that state by setting the Adviser-approval fields on the version, as the 7R module does. Every other step uses the real HTTP operations.

## Brief items 1–10

| # | Required | Verified behaviour | Assertions (prefix in console log) |
| --- | --- | --- | --- |
| 1 | A verified non-PI member logs in | Member 62 signs in with a real session and reads the shared checklist through their own identity | F01.1 |
| 2 | The member may access the shared project evidence | The checklist resolves to the one evidence record (Student 60) and shows PI 61; member uploads land on that record | F01.2 |
| 3 | The member can formally submit an eligible, Adviser-approved Form 33-0B | `submit_to_rpms` with the exact `versionNo` returns 200; the version records "Synthetic Student 62 (Student formal submission)" | F01.3, F01.7 |
| 4 | Security and workflow checks | GET → 405; cross-origin → 403; missing or stale session generation → 409; missing version → 422; wrong version → 409; client-supplied authoritative fields → 422; the assigned Adviser has no member route (403) | F01.4 |
| 5 | The project is automatically IERB Completed | Response `stageAdvanced: true`, `newStage: ierb_completed`; workflow at `ierb_completed`; `research_projects.completed_at` set and `completion_document_id` = this Final Report | F01.5 |
| 6 | Every verified member gets the synchronized status | Members 60/61/62 at `ierb_completed` with the workflow's revision (20); one completion history row each; each is notified "… is now IERB Completed"; each reads 100% through their own identity | F01.6 |
| 7 | Audit, evidence and notification records | One `document_submitted_to_rpms` audit by `student62@example.test`. One immutable ledger event `final_report_submitted` (actor 62, Approval of Data Gathering → IERB Completed) whose evidence names the Final Report, the accepted Form 33 and all 13 current accepted requirements, including the corrected protocol. One member-progress audit per member. A submission confirmation to the evidence record naming the submitter, and a queue notice to RPMS Admins. | F01.7 |
| 8 | Duplicate submissions are safe | Two simultaneous submissions by member 62: exactly one commits ([200, 409]). Later duplicates by 60, 61 and 62: 409 each. Three concurrent duplicates by different members: [409, 409, 409]. Completion, ledger, history and audit unchanged after all of them. | F01.3/F01.8, F01.8 |
| 9 | Non-members cannot submit or affect the project | Outsider: checklist 403, submission 403, no project notice. Unassigned Adviser: 403. None of the refused attempts changed the project. | F01.9 |
| 10 | Missing prerequisites cannot trigger completion | Refused, each with no stamp, completion, ledger or member change: Adviser approval of the Final Report at Initial Submission (409); member submission without Adviser approval; at Initial Submission; at Data Gathering before Form 33 acceptance; during an open correction case; of an unapproved current version; of a superseded approved version | F01.10 |

**Additional observations:**
- After completion, the Adviser cannot re-approve the Final Report (409), and a new Final Report upload is refused (409).
- Concurrency note: the same-member double submission runs in one PHP session, so PHP's session lock serializes it before it reaches the database. True cross-session concurrency is shown by the three-member duplicate burst and by the 7R module's two-member race on an ordinary requirement. Both rely on the same single-row conditional `UPDATE … WHERE rpms_submitted_at IS NULL` under row locks.

## Defects

- **No product defect** was found in the Final Report path.
- **Test defect (first attempt):** [runs/cc1232/11-closure-final-report-schema15](evidence/runs/cc1232/11-closure-final-report-schema15/). The test uploaded the corrected protocol without RPMS first returning the affected version, and the upload was correctly refused as locked. The test now follows the reviewed Batch 2 flow (return, then upload a revision).
- **UI gap (C-1):** the Student portal offers no "Submit to RPMS" action, although the API path works. Recorded for acknowledgement in [02](02_RPMS_DECISION_REGISTER_D1_D12.md).

## Not covered

- A Hostinger staging walkthrough with the browser UI (staging acceptance item 8).
- Browsers other than Chromium. This test is HTTP-only.
