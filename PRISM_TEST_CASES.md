# PRISM System Test Case Document

Manual functional, academic, authorization, workflow, security and integration verification

| Document control | Value |
| --- | --- |
| Document ID / version | PRISM-TC-ACADEMIC / 1.0 |
| Prepared | 1 October 2026 |
| Target branch | prism-v2-academic-fields |
| Behavior baseline | b6323f5bce501f66faa9bdec15e512a48630ad16 — Add academic program and year fields |
| Inspected local HEAD | d4d19b0 — Update academic checkpoint documentation |
| Baseline relationship | b6323f5 is an ancestor of the inspected HEAD. The follow-up changes three review/checklist documents and two catalog maintenance comments; application behavior is unchanged. |
| Workspace | C:/xampp/htdocs/rpms_system |
| Execution state | Prepared for manual execution. No case has been executed or marked Passed in this document. |
| Coverage | 164 test cases in six categories; outcome fields intentionally blank. |

## Purpose and scope

This document specifies observable behavior implemented in the current local source. It is a manual test plan, not an execution report or release certification. Primary sources are the PHP endpoints/templates, shared helpers and JavaScript listed for each module. Existing review reports support context but do not replace source inspection.

The test plan adds no feature requirements, new roles or endpoints. Student deletion removes the student row and deactivates the email-linked login. Adviser login still routes to IERB Progress. Graduate grouping is technical, not an official institutional unit. The dashboard Open Documents action opens the repository without interpreting a student ID as a document ID.

## Test Environment

| Component | Required setup / execution record |
| --- | --- |
| Operating system | Windows with XAMPP; record actual Windows/XAMPP versions at execution. |
| Web server | Apache with the repository .htaccess rules enabled; record version and local origin/port. |
| Runtime | PHP from C:/xampp/php/php.exe; record php -v and enabled extensions. Use PDO MySQL and the application's document/PDF dependencies; ZipArchive is needed for DOCX extraction. |
| Database | MySQL/MariaDB with transactional tables; record server version, SQL mode, charset and timezone. Ordinary functional cases require schema v5. |
| Source | Local branch prism-v2-academic-fields, behavior baseline b6323f5, inspected documentation follow-up d4d19b0. |
| Browser | Current installed Chrome or Edge, DevTools Network/Console, separate browser profiles for role/concurrency cases. Record exact version. |
| Viewport and theme | 375, 768, 1024, 1280 and 1600 CSS pixels where specified; light/dark themes; keyboard and zoom checks. |
| Migration isolation | Migration tests must use a disposable database restored from a verified backup or a fresh disposable schema. Never point destructive fixtures at an important database. |
| External services | Controlled test inboxes/mail sandbox and operator-approved AI integration. Separate successful external delivery from Logged/local fallback behavior. |
| Test evidence | Record date, tester, build, sanitized screenshots/responses, and defect IDs during execution. Never record passwords, cookies, reset tokens, API keys or private configuration. |

## Shared Preconditions and Test Data

The following setup applies to every table unless a case overrides it. Operators prepare disposable fixtures and identify the target database before testing; no migration, service interruption, email delivery or application bootstrap was performed while preparing this document.

| Fixture | Definition |
| --- | --- |
| ADMIN | Active admin login without forced change except in dedicated cases. |
| ADV-A / ADV-B | Two active adviser logins, each linked to a distinct adviser row through email. Use separate browser profiles. |
| STUD-A1 / STUD-A2 / STUD-B | Active student logins linked by email to student rows; A1/A2 assigned to ADV-A and B assigned to ADV-B. Include an unassigned control record. |
| Identity data | Unique QA-prefixed IDs, descriptive research titles and controlled emails under a domain permitted by the deployment. Replace &lt;allowed-test-domain&gt; before execution; never use another person's inbox. |
| UG4 | academicUnitKey=amt; programKey=bsit; yearLevel=2nd Year; academicYear=2026-2027; course=BS in Information Technology. |
| UG5 | academicUnitKey=pmt; programKey=bs_clinical_pharmacy; yearLevel=5th Year; academicYear=2026-2027; course uses exact central-catalog label. |
| UG6 | academicUnitKey=dentistry; programKey=ddm; yearLevel=6th Year; academicYear=2026-2027; course=Doctor of Dental Medicine. |
| GRAD | programKey=mba_thesis; academicUnitKey=NULL; yearLevel=NULL; academicYear=2026-2027; exact graduate course label. Repeat all four graduate programs where specified. |
| Legacy records | Operator-seeded disposable rows containing free-text course labels and NULL, empty-string or whitespace academic values. Capture exact stored tuples before edits; do not create invalid legacy fixtures through the new-record API. |
| Document files | Small genuine PDF/TXT/DOCX, revision copies, long filenames, unsupported-extension and MIME-mismatch canaries, plus 20 MiB+1-byte file. Never use destructive/exfiltrating payloads. |
| Stages and statuses | Stage 1, Stage 2, Stage 3, Stage 4, Stage 5, Completed; statuses On Track, Pending, Delayed. Display labels are editable; stage keys remain stable. |
| Reason/threshold defaults | STAGE_ADVANCE_TRIGGER defaults to submission; test approval in a separately prepared instance. REQUIRE_OVERRIDE_REASON_ON_SAVE defaults true; OVERRIDE_MIN_REASON_LENGTH defaults 5 and needs alphanumeric content. Attention defaults: review 3 days, unsubmitted 3 days, inactivity 30 days. |
| Reset/throttle fixtures | Fresh test accounts/counter windows for isolated rate-limit tests. Do not mix recovery cooldown checks with already-throttled fixtures; no production counters should be cleared. |
| API replay | Use the existing endpoint/action and JSON or multipart shape observed in DevTools. Keep authenticated cookies private and supply matching Origin/Referer except when intentionally testing rejection. |

### Execution conventions

All steps inside one case are required for its final verdict. When a case names both academic APIs/forms, repeat it independently through students_api.php?action=save / Student Management and ierb_api.php?action=save / IERB Progress, using distinct fixtures. Adviser mutations apply only to the Student Management path where permitted; IERB save/delete remain admin-only.

For new records, always supply all required identity/form fields and a valid academic tuple unless the case intentionally tests one invalid value. Change one negative-test input at a time. Use current record IDs rather than student identifiers where an API expects a database ID, and real document IDs for document actions.

Browser validation can prevent invalid values reaching PHP. Use a sanitized DevTools request replay to exercise server validation without changing application code. For concurrency, start actual overlapping requests from independent sessions/connections; two sequential clicks do not prove locking correctness.

Date expectations use the application/database timezone. Read mail outcomes literally: Sent, Logged, Failed and Scheduled are distinct. Scheduled rows are currently visible in recipient lists before their due email time; a worker invocation is required for scheduled delivery.

Privileged failure injection, configuration variants and fixture seeding belong only in an approved isolated instance. No case requires opening private configuration in this report or publishing secret values. Missing environment prerequisites mean Blocked, not Passed.

Restore disposable fixtures or recreate the test database between destructive cases. Preserve sanitized evidence before cleanup. Do not infer that a case passed from a prior automated-suite count.

## Pass/Fail Criteria

| Verdict | Criteria |
| --- | --- |
| Passed | Every listed step and expected result was observed on the recorded build, with appropriate UI/API/data evidence and no unexpected access, corruption or regression. |
| Failed | Any expected result differs, an unauthorized action succeeds, stored data is unexpectedly changed/lost, unsafe content executes, or the action reports misleading success. Record a defect reference in Remarks. |
| Blocked | A prerequisite, permission, supported service, disposable database, mail/AI setup or safe concurrency arrangement is unavailable. Record the exact blocker without inventing an Actual Result. |
| Not Tested | Execution has not begun. All result/status/remarks cells remain empty in this prepared version; testers fill them during execution. |
| Release assessment | All in-scope mandatory cases must be executed and pass before claiming complete verification. Failed or blocked authorization, data-preservation, migration or core-workflow cases prevent that claim. Explicitly document any approved deferred environment/service scenario. |

## Case Category Index

| Category | ID ranges | Total cases |
| --- | --- | --- |
| Functional Test Cases | AUTH-001–AUTH-017; STU-001–STU-014; IERB-001–IERB-012; NOTIF-001–NOTIF-013; REP-001–REP-012; LOG-001–LOG-004 | 72 |
| Academic Feature Test Cases | ACA-001–ACA-028 | 28 |
| Role/Authorization Test Cases | ROL-001–ROL-012 | 12 |
| Workflow Test Cases | ADV-001–ADV-008; DOC-001–DOC-016 | 24 |
| Security Test Cases | SEC-001–SEC-014 | 14 |
| Migration/Integration Test Cases | MIG-001–MIG-014 | 14 |

## Functional Test Cases

### Authentication and account access

*Source traceability: login.php; login_process.php; loading.php; config.php::current_user/require_login/api_require_login; profile_api.php; forgot_password_process.php; reset_password.php; update_password.php; logout.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AUTH-001 | Valid administrator login | Admin | Active ADMIN; no forced change or throttle. | 1. Open login.php. 2. Enter ADMIN email and valid password. 3. Follow the loading redirect. | ADMIN credentials, kept outside this document. | Session starts and the final destination is dashboard.php; the stored account role determines access. |  |  |  |
| AUTH-002 | Valid adviser login | Adviser | Active ADV-A, linked adviser row; no forced change. | 1. Sign in through login.php as ADV-A. 2. Wait for loading.php. | ADV-A credentials. | Final destination is ierbprog.php. research_adviser.php exists but is not the implemented login landing page. |  |  |  |
| AUTH-003 | Valid student login | Student | Active STUD-A1 linked by email; no forced change. | 1. Sign in through login.php as STUD-A1. 2. Wait for redirect. | STUD-A1 credentials. | Student reaches student.php and sees their own portal. |  |  |  |
| AUTH-004 | Invalid or unknown credentials | All roles | No active login; fresh failure counters. | 1. Try an existing email with a wrong password. 2. Try an unknown valid-format email. | Two test emails; intentionally wrong passwords. | Both attempts show Invalid email or password; neither creates an authenticated session. |  |  |  |
| AUTH-005 | Required login fields and email identity | All roles | Logged out. | 1. Submit blank fields. 2. Submit a student/employee ID as the login identifier using the handler if browser validation blocks it. | Blank email/password; fixture ID instead of email. | Missing fields are rejected. An ID without a matching email cannot authenticate; login uses email. |  |  |  |
| AUTH-006 | Inactive account login | All roles | Operator has set a disposable user's status to Inactive. | 1. Attempt login with that user's correct credentials. | Inactive fixture account. | Login is rejected with an inactive-account message; no authenticated access is granted. |  |  |  |
| AUTH-007 | Forced password-change enforcement | All roles | Active user with must_change_password=1. | 1. Log in. 2. Request a protected page. 3. Request an ordinary protected API. 4. Open the change-password page. | Forced-change fixture; profile_api.php?action=me. | Page redirects to change_password_required.php; ordinary API returns 403/password_change_required. Profile me/change_password remain available. |  |  |  |
| AUTH-008 | Complete forced password change | All roles | Forced-change screen open; current password known. | 1. Enter current and a different valid new password. 2. Confirm and submit. 3. Open the role's landing page. | New test password 8–200 ASCII characters; matching confirmation. | Change succeeds, must_change_password clears and the current session can access its role pages. |  |  |  |
| AUTH-009 | Password-change validation | All roles | Authenticated; Account or forced-change form available. | 1. Try wrong current password. 2. Try a 7-character new password. 3. Try current password as new. 4. Try mismatched confirmation. | Separate attempts with otherwise valid input. | Each invalid attempt is rejected; password remains unchanged. UI confirmation validation and server policy are both exercised. |  |  |  |
| AUTH-010 | Signed-in password change and sessions | Admin / Adviser | Same account logged in to two separate browser profiles. | 1. Change password in Account in profile A. 2. Refresh protected content in A and B. 3. Try old and new passwords in a third profile. | Distinct valid replacement password. | A remains authenticated after session regeneration; B is rejected on its next authenticated request. Only the new password logs in. |  |  |  |
| AUTH-011 | Logout | All roles | Authenticated session. | 1. Use Logout. 2. Reload a protected page. 3. Request a protected API. | Existing session. | Returns to login.php; protected page requires login and API returns 401. Reloading cannot reuse the destroyed session. |  |  |  |
| AUTH-012 | Forgot password: known and unknown email | Anonymous | Mail sandbox and valid canonical base URL; no cooldown. | 1. Submit a known email. 2. Submit an unknown valid-format email. 3. Inspect only the controlled known inbox. | Known and unknown emails in test domain. | Both show the same generic confirmation. Known account receives a one-hour reset link; unknown account receives no account-specific response. |  |  |  |
| AUTH-013 | Reset-request cooldown | Anonymous | A known account just received a reset link. | 1. Request another reset within five minutes. 2. Compare reset rows and test mailbox. 3. Use the first link. | Same known email twice. | Generic confirmation persists; no second issuance during cooldown, and the recent valid link is not invalidated by the repeat request. |  |  |  |
| AUTH-014 | Successful password reset | Anonymous / Account owner | Valid unused reset link; owner also has an older signed-in session. | 1. Open link. 2. Set a valid matching password. 3. Sign in with it. 4. Refresh the old session. | Unused token, kept private; different valid password. | Reset succeeds, forced-change flag clears and all reset tokens for the user become used. Old sessions fail on their next authenticated request. |  |  |  |
| AUTH-015 | Invalid, expired and reused reset links | Anonymous | Separate invalid, expired and already-used tokens in disposable fixtures. | 1. Open each link. 2. Attempt submission through update_password.php where needed. | Random token; expired token; token consumed by AUTH-014. | No password changes. User is directed to request a new valid link; unusable tokens are not accepted. |  |  |  |
| AUTH-016 | Reset-password validation | Anonymous | Fresh valid reset token for each independent attempt. | 1. Try fewer than 8 characters. 2. Try mismatched confirmation. 3. Submit more than 200 characters through the handler. | 7-character, mismatched and 201-character values. | Invalid passwords are rejected without consuming the valid token or changing the password. |  |  |  |
| AUTH-017 | Login failure throttling | All roles | Disposable account; no recent failures; separate test window. | 1. Submit eight wrong passwords for one email. 2. Attempt a ninth login, including with the correct password. 3. Retest after the 15-minute window. | Same email for all attempts. | Further attempts are throttled within the window, including correct credentials; ordinary login resumes after qualifying failures expire. |  |  |  |

### Admin Student Management

*Source traceability: admin_students.php; admin_people.php; assets/js/admin-management.js; students_api.php::save/delete/adviser_options; config.php::sync_student_login_identity*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| STU-001 | Add student and linked account | Admin | Migrated DB; unique identifiers; active adviser exists. | 1. Open Student Management &gt; Add Student. 2. Complete identity and UG4 academic fields. 3. Save and reload. | QA-STU-NEW-01; controlled allowed-domain email; UG4; GRP-A; ADV-A. | One student and linked student login are created; academic tuple persists. Setup delivery outcome is reported rather than assumed. |  |  |  |
| STU-002 | Required, invalid and duplicate identity | Admin | Existing STUD-A1; valid UG4 fields available. | 1. Try missing name/ID and invalid email. 2. Try a disallowed domain if configured. 3. Try an existing student ID/email. | Blank fields; malformed email; duplicate STUD-A1 identity. | Invalid input is rejected. Duplicates report validation conflict and do not create another student/account. |  |  |  |
| STU-003 | Edit identity and synchronized login | Admin | Existing STUD-A1; unused replacement email and ID. | 1. Edit name, student ID and email without changing academics. 2. Save/reload. 3. Log in with replacement email. | New allowed-domain email, QA-STU-A1-EDIT. | Student and linked login identity stay synchronized; the record/documents remain accessible to the same owner using the new email. |  |  |  |
| STU-004 | Assign, reassign and unassign adviser | Admin | Active ADV-A and ADV-B; existing student. | 1. Assign ADV-A and save. 2. Reassign ADV-B and save. 3. Choose Unassigned and save; restore fixture afterward. | adviserId A, B, then null/empty selection. | Each assignment persists. Scoped adviser lists follow the current assignment; an unassigned student is not exposed as another adviser's student. |  |  |  |
| STU-005 | Valid stage/status change with reason | Admin | Existing student; default progress-reason protection enabled. | 1. Change Stage 1/Pending to Stage 2/On Track. 2. Supply meaningful reason. 3. Check record/history/audit. | Reason: Verified committee progress update. | New stage/status persist with before/after values and reason recorded; related progress history is available. |  |  |  |
| STU-006 | Progress reason cannot be bypassed | Admin | Existing student; valid identity and preserved academic tuple. | 1. Change stage/status. 2. Cancel/omit the reason. 3. Repeat API save with blank or punctuation-only reason. | Changed stage; reason empty or ..... . | No progress change commits; server rejects an invalid reason with 422/requiresReason. Other edits in the rejected save do not partially persist. |  |  |  |
| STU-007 | Non-progress edit without reason | Admin | Existing valid or legacy student. | 1. Change research title/group only. 2. Keep stage/status and academic controls unchanged. 3. Save. | Updated research title; no reason. | Unrelated edit succeeds without requiring a progress-change reason; stage/status and academic tuple remain intact. |  |  |  |
| STU-008 | Protocol Code and Principal Investigator | Admin | Existing student. | 1. Set protocol code and check Principal Investigator. 2. Save/reopen. 3. Uncheck PI and save. | Protocol QA-IERB-2026-001; PI true then false. | Protocol code and PI boolean persist and are displayed accurately; changing PI does not alter academic data. |  |  |  |
| STU-009 | Delete student and deactivate login | Admin | Disposable student with linked active login; deletion consequences accepted. | 1. Delete the student and confirm. 2. Reload list. 3. Attempt linked login and refresh an existing student session. | Disposable student database ID. | Student row is removed and matching student login becomes Inactive. No claim of a student soft-delete/recycle-bin feature; login access is denied. |  |  |  |
| STU-010 | Cancel destructive or unsaved action | Admin | Existing student and unchanged baseline snapshot. | 1. Open edit, alter values, then Cancel. 2. Open delete confirmation and cancel. 3. Reload. | Unsaved name change. | Neither cancelled action modifies or deletes the stored record. |  |  |  |
| STU-011 | Stale edit after deletion | Admin | Same disposable record open in two admin profiles. | 1. Delete in profile B. 2. Save the still-open edit in A with otherwise valid data. | Deleted numeric student ID. | Save returns 404/not found, not success and not the duplicate-email/ID message; no replacement row is created. |  |  |  |
| STU-012 | Repeated delete | Admin | Disposable record deleted by another admin. | 1. Submit delete again for the now-missing record. 2. Inspect response and list. | Deleted student ID. | Returns 404/not found and does not falsely report another successful deletion. |  |  |  |
| STU-013 | Search and empty/error states | Admin | Several distinct names/IDs and an isolated network-blocking option. | 1. Search a known token. 2. Search a nonexistent token. 3. Block students_api.php and reload; then unblock. | QA-STU; NO-MATCH-999. | Matching rows are shown; no-match and load failure are distinguishable. Recovery reloads actual records without inventing data. |  |  |  |
| STU-014 | Setup-delivery failure during creation | Admin | Disposable mail-failure/log-only environment; production disclosure policy enabled. | 1. Add a valid new student. 2. Inspect UI and JSON response. 3. Confirm student/login persistence. | Unique identity; UG4; unavailable real mail delivery. | Record/account still exist and setupPending/setupMessage describe undelivered setup. No temporaryPassword is returned under production policy. |  |  |  |

### IERB Progress Management

*Source traceability: ierbprog.php; assets/js/ierbprog.js; ierb_api.php::save/history/note/delete/override/stage_distribution; send_followup.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| IERB-001 | Add IERB entry | Admin | Migrated DB; unique student identity. | 1. Open IERB Progress &gt; Add IERB Entry. 2. Fill required identity, group, research and UG4 selections. 3. Save/reload. | QA-IERB-NEW-01; GRP-I; test research; UG4. | Entry and associated login are created; record, academic summary and stage distribution reflect the saved entry. |  |  |  |
| IERB-002 | Edit entry requirements | Admin | Existing IERB entry. | 1. Edit requirements and research title without progress change. 2. Save and reopen. | Requirements: Revised consent form; unchanged academics. | Requirements/title persist; unrelated academic, stage and status data are preserved. |  |  |  |
| IERB-003 | Stage/status save and mandatory reason | Admin | Existing entry; REQUIRE_OVERRIDE_REASON_ON_SAVE enabled. | 1. Change stage/status without reason via API. 2. Confirm no change. 3. Save through UI with meaningful reason. | Stage 2 to Stage 3; Delayed; reason: Revised compliance schedule approved. | Missing reason is rejected with 422; reasoned save succeeds with progress history and audit data. |  |  |  |
| IERB-004 | Clear completed requirements | Admin | Entry has nonempty requirements. | 1. Edit entry. 2. Clear requirements, retain other fields and save. 3. Reopen. | Empty requirements. | Pending requirements clear intentionally without clearing unrelated fields or academic data. |  |  |  |
| IERB-005 | Valid or absent submission date | Admin | Existing entry. | 1. Save today's date. 2. Save a valid past date. 3. Clear date and save. | Today; 2026-01-15 when not future; empty date. | Valid dates persist as YYYY-MM-DD; omitted/empty date on this full form saves NULL as implemented. No impossible date is synthesized. |  |  |  |
| IERB-006 | Invalid or future submission date | Admin | Existing entry and captured original date. | 1. Send save with an impossible date. 2. Repeat with tomorrow. 3. Reload record. | 2026-02-30; tomorrow in app timezone. | 422 validation response; date and other fields in the rejected operation remain unchanged. |  |  |  |
| IERB-007 | Add and validate progress note | Admin / Assigned adviser | Existing in-scope entry. | 1. Add a valid note. 2. View history. 3. Try blank note and 501 characters through API. | Note: Awaiting revised consent form; blank; 501 characters. | Valid note appears with actor, stage/status context and time, without changing progress. Empty/overlong notes are rejected. |  |  |  |
| IERB-008 | IERB history and audit trail | Admin / Assigned adviser | Known creation, progress-change and note events exist. | 1. Open entry history. 2. Open authorized Account activity view. 3. Compare events and actor data. | Entry ID with prior IERB actions. | History shows recorded progress/notes; audit shows applicable action, actor and reason/before/after fields. Adviser results remain scoped. |  |  |  |
| IERB-009 | Search, stage and status filters | Admin / Adviser | Multiple in-scope stages/statuses. | 1. Search a known name/group. 2. Combine stage/status filters. 3. Clear filters. 4. Search absent text. | Stage 2; Delayed; GRP-A; absent token. | Only matching authorized records display; clearing restores scoped list and no-match state is informative. |  |  |  |
| IERB-010 | Compact empty and populated chart | Admin / Adviser | One empty dataset and one known stage-count dataset available. | 1. Load empty view. 2. Load populated view at 375 and 1280 px in both themes. | Zero rows; known counts across Stage 1–5/Completed. | Empty chart is compact; populated chart preserves correct counts and proportional bars without page overflow or clipped meaningful labels. |  |  |  |
| IERB-011 | IERB delete and stale deletion | Admin | Disposable IERB entry with linked login. | 1. Delete and confirm. 2. Verify row removal/login inactivity. 3. Repeat API deletion of same ID. | Disposable IERB ID. | First delete removes entry and deactivates matching student login; repeat returns 404, not false success. |  |  |  |
| IERB-012 | Follow-up and admin override | Admin / Assigned adviser for follow-up | In-scope entry and controlled notification delivery; admin for override. | 1. Send standard follow-up. 2. As admin, override progress with meaningful reason. 3. Inspect notifications/history/audit. | studentDbId; reason: Corrected verified review progress. | Follow-up targets the stored student's email and reflects delivery outcome. Admin override stores reason/before/after; an unchanged override is rejected. |  |  |  |

### Notifications

*Source traceability: admin_notifications.php; notifications_api.php; assets/js/admin-notifications.js; role_portal.php; assets/js/role-portal.js; tools/process_scheduled_notifications.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| NOTIF-001 | Admin send now to students | Admin | Controlled recipient fixtures and delivery sandbox. | 1. Open Notification Center. 2. Select All Students and Reminder. 3. Enter message with scheduling off. 4. Send. | Message: QA deadline reminder; All Students. | Recipient rows cover the student audience. Immediate outcome records Sent, Logged or Failed accurately; Logged is not proof of email delivery. |  |  |  |
| NOTIF-002 | Admin adviser and combined audiences | Admin | Active and inactive adviser fixtures; students present. | 1. Preview All Advisers and send. 2. Repeat Students and Advisers using a distinct message. | Two unique QA messages. | Adviser audience includes active advisers; combined audience includes students and active advisers according to recipient resolution. |  |  |  |
| NOTIF-003 | Assigned-adviser notification scope | Adviser | ADV-A has students; ADV-B has separate students. | 1. Sign in as ADV-A. 2. Preview All Students. 3. Send a notification and inspect records. | ADV-A; message: Assigned-student update. | Only ADV-A's assigned students receive this audience; other advisers' students are excluded. |  |  |  |
| NOTIF-004 | Exact research-group targeting | Admin / Adviser | Groups GRP-A and GRP-A-EXTRA; both adviser assignments represented. | 1. Select Specific Research Group. 2. Enter GRP-A, preview and send. 3. Repeat as adviser. | Exact group GRP-A. | Only exact group matches are targeted; adviser targeting is further limited to assigned students. |  |  |  |
| NOTIF-005 | Empty or invalid audience | Admin / Adviser | Valid sender session. | 1. Select group audience with blank group. 2. Try nonexistent group. 3. Tamper with unsupported audience/type through API. | Blank/NO-SUCH-GROUP; invalid audience/type. | Invalid/no-recipient send is rejected, and no delivery rows or messages are created for that send. |  |  |  |
| NOTIF-006 | Message length and requiredness | Admin / Adviser | Valid nonempty recipient audience. | 1. Try blank message. 2. Send 600-character message. 3. Attempt 601 characters through API. | Blank; 600 and 601 visible characters. | Blank and over-limit messages are rejected; 600-character valid message is accepted. UI counter tracks the supported limit. |  |  |  |
| NOTIF-007 | Recipient-preview race | Admin / Adviser | Network throttling enabled; two distinguishable groups. | 1. Enter first group. 2. Quickly replace with second. 3. Let requests finish out of order. | GRP-A then GRP-B. | Preview reflects the latest input, not an older response; final send resolves recipients again server-side. |  |  |  |
| NOTIF-008 | Schedule future notification | Admin / Adviser | Worker paused; clocks/timezone known. | 1. Enable scheduling. 2. Set time several minutes ahead and send. 3. Inspect row and recipient's in-app list. | Future scheduleAt; unique QA message. | Rows are Scheduled with scheduled_at; no immediate email attempt. Existing list behavior exposes the stored notification before its due mail time. |  |  |  |
| NOTIF-009 | Invalid schedule | Admin / Adviser | Valid audience/message. | 1. Enable schedule with no time. 2. Try a past time. 3. Send malformed scheduleAt via API. | Empty, past and malformed timestamp. | Scheduling is rejected without creating notification rows for the invalid request. |  |  |  |
| NOTIF-010 | Scheduled CLI delivery and repeat run | Operator | Only disposable due notifications/test recipients; one future notification. | 1. Run the notification CLI worker before and after due time. 2. Run it again. 3. Inspect rows/mail sandbox. | tools/process_scheduled_notifications.php; due and future rows. | Only due Scheduled rows are claimed; final state reflects Sent/Logged/Failed. Completed rows are not resent by a repeat run; future rows remain Scheduled. |  |  |  |
| NOTIF-011 | History and details | Admin / Adviser | Notifications with multiple statuses and long messages. | 1. Open history. 2. Open a detail dialog. 3. Compare message, recipient, schedule/delivery/read timestamps. | Scheduled, Sent/Logged and Failed fixtures. | Details retain available metadata and full message. Admin sees global history; adviser sees self-addressed and assigned-student notifications. |  |  |  |
| NOTIF-012 | Student view and read actions | Student | STUD-A1 and STUD-B each have unread notifications. | 1. Open Notifications in the student portal. 2. Mark one read. 3. Mark all read. 4. Refresh both accounts. | Unread notification IDs for each student. | Student sees only own recipient email. Read timestamps update only own notifications; other student's read state remains unchanged. |  |  |  |
| NOTIF-013 | Empty, error and responsive form states | Admin / Adviser | Empty history, populated history and network-blocking option. | 1. Inspect empty view. 2. Block history API and reload. 3. Restore and inspect at 375/768/1280 px, both themes. | Long message/recipient names. | Empty and load-error states differ; message, schedule row and send control stay usable and meaningful content wraps. |  |  |  |

### Dashboard and reporting

*Source traceability: dashboard.php; reports.php; admin_ai.php; reports_api.php; ierb_api.php::export_csv/needs_attention; workflow.php::students_needing_attention; stage_labels_api.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| REP-001 | Dashboard metrics | Admin | Known student-row totals/stages/statuses and repeated group names. | 1. Open dashboard. 2. Compare headline counts and stage distribution with fixture records. | Known Pending, Delayed, Completed counts. | Metrics match implemented student-row calculations, not distinct research-group counts; stage distribution matches scoped source records. |  |  |  |
| REP-002 | Dashboard search/filter/sort | Admin | Records across courses and progress percentages. | 1. Search name/group/research text. 2. Select course. 3. Sort progress each direction. 4. Clear filters. | Known text; course label; ascending/descending sort. | Visible table rows match filters and selected sort. Headline metrics remain based on the loaded full list. |  |  |  |
| REP-003 | Needs Attention reasons | Admin / Adviser | Disposable dated fixtures meeting configured thresholds; Completed control record. | 1. Open Needs Attention. 2. Inspect delayed, awaiting-review, ready-but-unsubmitted and inactivity entries. | Defaults: review 3 days; unsubmitted 3 days; inactivity 30 days, unless configured otherwise. | Eligible non-Completed students appear with correct reasons/severity; adviser results include only assigned students. |  |  |  |
| REP-004 | Truthful Open Documents shortcut | Admin | Dashboard contains a student row with documents. | 1. Tab to its Open Documents action. 2. Activate with Enter. 3. Inspect destination/network. | Any displayed student row. | Navigates to documents.php with no student/document ID parameter; it does not call summarize or imply student ID is document ID. |  |  |  |
| REP-005 | AI summarized report | Admin / Adviser | At least one scoped student; approved test AI service available. | 1. Open AI Progress Reports. 2. Generate summarized report. 3. Open downloaded/history PDF. | mode=summary. | One PDF/report record is created from authorized scope. AI use is reported accurately and output is presented for human review. |  |  |  |
| REP-006 | AI full report | Admin / Adviser | Several scoped students with research/requirements. | 1. Generate Full Report. 2. Open PDF and compare included students. | mode=full. | PDF includes the full-report per-student detail supported by the implementation; another adviser's records do not enter adviser report data. |  |  |  |
| REP-007 | Local report fallback | Admin / Adviser | Scoped students; operator-provided test environment with AI unavailable/unconfigured. | 1. Generate summary and full reports. 2. Inspect response and PDFs. | mode=summary and full; aiUsed=false. | Local summarizer produces reports; UI explicitly states fallback. No claim that the external AI succeeded. |  |  |  |
| REP-008 | Report validation and generation state | Admin / Adviser | Empty scoped dataset, then populated dataset; DevTools available. | 1. Generate with no students. 2. Try invalid mode via API. 3. With records, click generation rapidly. | Empty dataset; mode=invalid; repeated UI clicks. | Empty/invalid requests return 422; no spurious report record. While a UI generation is pending, both generation buttons are disabled and recover afterward. |  |  |  |
| REP-009 | History, PDF keyboard access and download | Admin / Adviser | Generated AI reports owned by test users. | 1. Load history. 2. Tab/Enter on a report. 3. Download via download=1. 4. Test empty/error history separately. | Existing report ID. | AI history shows applicable report metadata; native links open PDF in a new tab. Download endpoint serves attachment; empty and error states are distinct. |  |  |  |
| REP-010 | CSV export scope and filters | Admin / Adviser | Known rows across stages/statuses and assignments. | 1. Use dashboard Export CSV. 2. Request ierb_api.php?action=export_csv with q/stage/status filters. 3. Compare rows. | q=GRP-A; stage=Stage 2; status=Delayed. | CSV follows server scope and supplied API filters. Dashboard's static CSV link does not automatically copy its client-side table filters. |  |  |  |
| REP-011 | Admin stage-label settings | Admin | Known original label; no other operator editing it. | 1. Edit one stage label in AI settings. 2. Save. 3. Reload relevant pages. 4. Restore original label. | Existing key Stage 2; label QA Review Stage. | Label saves and propagates on subsequent requests; stored stage key remains Stage 2. Saving row is disabled until response; invalid blank label is rejected. |  |  |  |
| REP-012 | Dashboard/report layout and load failures | Admin / Adviser | Long filenames, titles and labels; populated and empty fixtures. | 1. Inspect relevant permitted pages at 375/768/1024/1280/1600 px in both themes. 2. Block/unblock a report/list request. | Long document filenames; mixed status badges. | Content/controls remain accessible without page overflow; report cards are balanced. Errors are shown separately from empty data and recover after reload. |  |  |  |

### Account Activity Logs

*Source traceability: account.php; assets/js/account.js; audit_api.php::list*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| LOG-001 | Activity search and inclusive date range | Admin / Adviser | Known events across dates with matching reasons/names. | 1. Enter Search text. 2. Set From/To and Apply. 3. Check boundary-day entries. | Known actor/reason token; YYYY-MM-DD range. | Returned authorized events match search and inclusive start/end dates; filters retain their values. |  |  |  |
| LOG-002 | Overrides-only and full audit detail | Admin / Adviser | Normal and override events in permitted scope. | 1. Check Overrides Only and Apply. 2. Expand Audit details for an override. 3. Uncheck and reapply. | Override with before/after/reason. | Only flagged overrides display when selected. Available actor/entity/IDs/time/before/after/reason data remain accessible; normal entries return after clearing. |  |  |  |
| LOG-003 | Latest response and empty/error state | Admin / Adviser | Throttled network; filters with different outcomes. | 1. Apply filter A then B rapidly. 2. Test no-match query. 3. Block audit API and reload. | Two query tokens; nonexistent token. | Late response A does not replace B. No matching activity differs from Activity unavailable; pending state clears appropriately. |  |  |  |
| LOG-004 | Responsive and keyboard filter controls | Admin / Adviser | Account Activity Logs open. | 1. Inspect at 375/768/1280 px and both themes. 2. Navigate labels/controls and Audit details by keyboard. | Search, From, To, Overrides Only, Apply. | Labels sit above controls; checkbox is properly labeled; Search has the widest desktop allocation. Controls/disclosures remain usable without hiding audit content. |  |  |  |

## Academic Feature Test Cases

### Academic catalog, dependent controls and legacy preservation

*Source traceability: includes/academic_catalog.php::academic_catalog/academic_validate; assets/js/academic-fields.js; admin_people.php; ierbprog.php; students_api.php; ierb_api.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ACA-001 | Catalog coverage in both forms | Admin | New Student and new IERB dialogs available. | 1. Inspect all academic groups/programs in both forms. 2. Compare against the catalog inventory in this document. | Seven undergraduate units; 18 undergraduate and four graduate programs. | Both forms expose the same central catalog; no invented unit/program/year choices or duplicated independent catalog behavior. |  |  |  |
| ACA-002 | Academic Unit filters Program | Admin / Adviser in Student Management | Fresh form; no academic selections. | 1. Choose amt. 2. Inspect programs. 3. Choose dentistry and inspect again. | amt then dentistry. | Program options follow the chosen unit; BSIT is available under amt and Doctor of Dental Medicine under dentistry. |  |  |  |
| ACA-003 | Unit change clears incompatible selections | Admin | Form contains amt/bsit/2nd Year. | 1. Change unit to dentistry. 2. Inspect program and year. 3. Attempt save before choosing a new valid program/year. | UG4 changed to dentistry. | Incompatible program/year selections clear; required valid undergraduate selections must be completed before creation. |  |  |  |
| ACA-004 | Program determines allowed year levels | Admin | Unit pmt selected. | 1. Choose bs_pharmacy. 2. Inspect years. 3. Change to bs_clinical_pharmacy. | 4-year and 5-year pharmacy programs. | Year options derive from the selected program duration: 1st–4th for bs_pharmacy and 1st–5th for bs_clinical_pharmacy. |  |  |  |
| ACA-005 | Compatible year survives program change | Admin | pmt/bs_pharmacy/2nd Year selected. | 1. Change program to bs_clinical_pharmacy. 2. Inspect year. | 2nd Year in both programs. | Compatible 2nd Year remains selected rather than being unnecessarily erased. |  |  |  |
| ACA-006 | Incompatible year clears | Admin | pmt/bs_clinical_pharmacy/5th Year selected. | 1. Change program to bs_pharmacy. 2. Inspect year and try to save. | 5th Year moving from 5-year to 4-year program. | 5th Year is cleared/unavailable; valid year selection is required before saving the changed tuple. |  |  |  |
| ACA-007 | Academic Year is independent | Admin | Complete valid tuple with AY selected. | 1. Change unit/program/year to another valid tuple. 2. Inspect Academic Year. 3. Change only Academic Year. | 2026-2027 then 2027-2028. | Academic Year remains independent of dependent selects; changing it does not silently replace program or year level. |  |  |  |
| ACA-008 | Explicit Academic Year allowlist and no default | Admin | Fresh new-record form; operator has not extended catalog. | 1. Inspect Academic Year options and initial selection in both forms. | 2025-2026, 2026-2027, 2027-2028. | Exactly the implemented allowlist is offered, with no automatically selected active year or inferred rollover. |  |  |  |
| ACA-009 | Persist 4-year program boundaries | Admin | Two unused identities for each tested API. | 1. Create undergraduate records at 1st Year and 4th Year. 2. Read back via list and UI. | amt/bsit; 1st Year and 4th Year; AY 2026-2027. | Both valid boundary years persist through students_api.php and ierb_api.php with canonical program label. |  |  |  |
| ACA-010 | Persist 5-year program | Admin | New identity and full required record fields. | 1. Select pmt and Clinical Pharmacy five-year program. 2. Select 5th Year/AY and save. 3. Reload. | pmt/bs_clinical_pharmacy/5th Year/2026-2027. | Valid fifth-year record persists and displays correctly; no four-year truncation is imposed. |  |  |  |
| ACA-011 | Persist 6-year programs | Admin | Separate new identities for both programs. | 1. Create Dentistry at 6th Year. 2. Create Optometry at 6th Year. 3. Read back. | dentistry/ddm; optometry/doctor_optometry; 6th Year/2026-2027. | Both six-year catalog programs accept and retain 6th Year. |  |  |  |
| ACA-012 | Graduate program nullable values | Admin | New identities; Graduate programs grouping available. | 1. Repeat creation for each graduate program. 2. Select AY. 3. Inspect request and stored row. | mba_thesis, mba_non_thesis, mba_tqm, ms_psychology; AY 2026-2027. | Program and AY persist; academic_unit_key and year_level are NULL. Technical grouping is not stored as an invented academic unit/standing. |  |  |  |
| ACA-013 | Graduate program and year required | Admin | Graduate grouping selected. | 1. Try save without program. 2. Try selected program without Academic Year. 3. Send graduate tuple with fabricated unit/year via API. | Null program; null AY; invented graduate unit or 1st Year. | Incomplete or invented graduate tuple is rejected; no fabricated graduate duration/standing is accepted. |  |  |  |
| ACA-014 | Reject cross-unit and unknown keys | Admin / Adviser in scoped Student API | Otherwise valid payload; each request uses a separate test identity. | 1. Submit amt/ddm. 2. Submit unknown unit. 3. Submit unknown program through each academic save API. | amt + ddm; unit=unknown; program=unknown. | 422 academic validation; no partial student/login record is created or updated. |  |  |  |
| ACA-015 | Reject out-of-duration or unknown year | Admin | Otherwise valid undergraduate payload. | 1. Submit BSIT with 5th Year. 2. Submit Clinical Pharmacy with 6th Year. 3. Submit arbitrary standing. | bsit/5th Year; bs_clinical_pharmacy/6th Year; Freshman. | 422 response; year cannot exceed catalog duration or bypass the explicit year labels. |  |  |  |
| ACA-016 | Reject invalid Academic Years | Admin | Otherwise valid complete tuple. | 1. Submit malformed year. 2. Submit nonconsecutive year. 3. Submit well-formed year outside allowlist. | 2026/2027; 2026-2028; 2028-2029. | All three are rejected with 422; validation is not merely a date-format check. |  |  |  |
| ACA-017 | Reject non-string academic inputs | Admin | DevTools/API request replay available. | 1. Send arrays, objects, numbers and booleans for academic fields in separate attempts. | programKey=[]; yearLevel=2; academicYear=true. | Invalid types are rejected with 422 rather than being coerced into catalog values. |  |  |  |
| ACA-018 | Client duration cannot override catalog | Admin | Valid and invalid undergraduate tuples prepared. | 1. Send BSIT/5th Year plus duration=6 and programDuration=6. 2. Send valid BSIT/4th Year with false duration extras. | Client-supplied duration fields; canonical BSIT tuple. | First request remains invalid; second is governed by server catalog. Extra client duration values cannot expand allowed year levels. |  |  |  |
| ACA-019 | Canonical course label and arbitrary label rejection | Admin | Two independent new-record payloads with valid keys and otherwise required fields. | 1. Save valid tuple with its canonical course label. 2. Repeat with mismatching free-text course. | bsit; course=BS in Information Technology, then Invented Program. | Canonical tuple is stored; an explicitly changed arbitrary/mismatching course does not bypass academic validation. |  |  |  |
| ACA-020 | Long program label round-trip | Admin | New identity; both forms tested independently. | 1. Select Hotel, Restaurant and Culinary Operations program. 2. Save/reopen and read API/DB. 3. Inspect narrow viewport summary. | bsihm_hotel; 102-character canonical label; 4th Year. | Full label is saved without truncation and is readable through wrapping summary text in both UI paths. |  |  |  |
| ACA-021 | Edit valid academic record preselection | Admin / Assigned adviser in Student Management | Existing fully cataloged record. | 1. Open Edit. 2. Compare all four academic selections. 3. Cancel without editing. | Existing UG4 or UG5 tuple. | Stored compatible unit/program/year/AY are preselected correctly; opening/cancelling does not rewrite values. |  |  |  |
| ACA-022 | Legacy free-text course preservation | Admin / Assigned adviser in Student Management | Legacy row has course=Legacy Research Program; null academic keys/year fields. | 1. Open Edit. 2. Change research title only. 3. Save and compare stored tuple byte-for-byte. | Legacy free-text label and NULL keys. | Original course label remains visible and unchanged; unrelated edit neither relabels it nor forces catalog conversion. |  |  |  |
| ACA-023 | NULL and empty-string legacy preservation | Admin | Separate legacy rows with NULL, empty strings and whitespace-only academic values. | 1. Snapshot each tuple. 2. Edit unrelated fields through both APIs. 3. Read back exact values. | NULL, '', and whitespace fixtures; free-text course. | Each original value remains exact, including distinction between NULL and empty/whitespace; no silent normalization on unrelated edits. |  |  |  |
| ACA-024 | Unchanged edit omits academic fields | Admin / Assigned adviser in Student Management | Existing academic or legacy record; DevTools Network open. | 1. Edit only name/title. 2. Inspect outgoing save body. 3. Reload. | No academic control changes. | UI omits academic fields when untouched; server preserves stored tuple rather than clearing omitted values. |  |  |  |
| ACA-025 | Concurrent unrelated edit preserves newer academics | Admin | Same record open in A; B can save a valid academic change. | 1. In B save UG5 tuple. 2. In A change only research title and save untouched academic controls. 3. Read stored row. | A originally sees UG4; B saves UG5. | A's omitted academic fields preserve B's latest locked-row tuple; unrelated save does not restore stale academic selections. |  |  |  |
| ACA-026 | Intentional legacy conversion and invalid clearing | Admin | Legacy record and a separate complete cataloged record. | 1. Intentionally replace legacy academics with valid UG4 and save. 2. On complete record, explicitly clear required academic fields via API. | Complete UG4; explicit programKey=null. | Intentional valid conversion stores canonical tuple. Invalid explicit clearing is rejected; legacy preservation is not a client bypass flag. |  |  |  |
| ACA-027 | Keep existing academic values | Admin | Edit dialog for existing record; unrelated field also edited. | 1. Change academic selections. 2. Click Keep existing academic values. 3. Save unrelated edit. | Original legacy or valid tuple; new research title. | Original academic selections/values are restored, unrelated field edit remains, and stored original academics are preserved. |  |  |  |
| ACA-028 | Academic accessibility and read-only summary | Admin / Assigned adviser | Valid, legacy and long-label records; both forms available. | 1. Navigate academic selects by keyboard. 2. Test modal Tab, Escape and return focus. 3. Inspect read-only summaries at 375/768/1280 px, both themes. | UG4, long label, graduate NULLs and legacy fixtures. | Controls have labels and usable focus order; dialog closes/returns focus. Summaries accurately show known values, omit misleading empty values, and wrap safely. |  |  |  |

## Role/Authorization Test Cases

### Role boundaries, ownership and direct API access

*Source traceability: config.php::require_login/api_require_login; admin_people.php; students_api.php; ierb_api.php; advisers_api.php; documents_api.php; reports_api.php; notifications_api.php; audit_api.php; stage_labels_api.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ROL-001 | Unauthenticated protected access | Anonymous | Logged-out/incognito session. | 1. Request dashboard.php and documents.php. 2. Request students_api.php?action=list and documents_api.php?action=list. | No session cookie. | Pages redirect to login; JSON APIs return 401 without protected records. |  |  |  |
| ROL-002 | Cross-role page routing | Student / Adviser / Admin | Active role fixtures without forced changes. | 1. Student requests admin_students.php/account.php. 2. Adviser requests admin_advisers.php/dashboard.php. 3. Admin requests research_adviser.php. | Direct page URLs. | Unauthorized page content is not rendered; guards redirect to the user's implemented landing page. |  |  |  |
| ROL-003 | Student denied administrative mutations | Student | STUD-A1 authenticated; valid same-origin POST evidence. | 1. POST save/delete to students_api.php and advisers_api.php. 2. POST save/delete/note/override to ierb_api.php. | Existing or new valid payload IDs. | Each disallowed action returns 403; no record mutation occurs. Hidden UI controls are not the authorization boundary. |  |  |  |
| ROL-004 | Adviser scoped student list and management | Adviser | ADV-A and ADV-B assignments plus unassigned record. | 1. List students as ADV-A. 2. Edit own student. 3. Create student while claiming adviserId B. | ADV-A; claimed adviserId of ADV-B. | Only assigned records list; permitted own edit succeeds. Newly created record is assigned to the acting adviser, not the supplied other adviser. |  |  |  |
| ROL-005 | Adviser blocked from other student's mutation | Adviser | Known numeric ID of ADV-B's student; ADV-A session. | 1. Send Student save for B's student. 2. Request its IERB history/note/follow-up. | STUD-B ID; valid academic/nonacademic fields. | Cross-adviser operations are rejected; no B data is changed or disclosed through history. |  |  |  |
| ROL-006 | Adviser reassignment during edit | Adviser / Admin | ADV-A has student's Edit dialog open. | 1. Admin reassigns student to ADV-B. 2. ADV-A submits stale edit. 3. Reload scoped lists. | Reassigned student ID. | Server rechecks the locked row and returns 403; stale adviser edit cannot overwrite the new owner's record. |  |  |  |
| ROL-007 | Adviser IERB read/note versus save/delete | Adviser | Assigned student entry; valid origin evidence. | 1. View own IERB list/history and add note. 2. POST save, delete and override directly. | Assigned student ID. | Read and note are allowed; IERB save/delete/override return 403 even for assigned students. |  |  |  |
| ROL-008 | Protected protocol/PI and adviser directory | Adviser | Own student has known protocol/PI; linked adviser account. | 1. Forge protocolCode/isPrincipalInvestigator in own Student save. 2. Try adviser-directory save/delete. | Changed protected values; adviser ID. | Protected student fields remain unchanged by adviser save; adviser-directory mutations are admin-only and rejected. |  |  |  |
| ROL-009 | Student self-scope for IERB and documents | Student | STUD-A1 and STUD-B have entries/documents. | 1. List own IERB/docs. 2. Substitute B's ID in IERB history and document file/versions requests. | Own and foreign IDs. | Only student's email-linked record/documents list. Foreign record/document access is rejected rather than disclosed. |  |  |  |
| ROL-010 | Notification audience and mark-read ownership | Adviser / Student | Foreign unread notification exists; role fixtures active. | 1. Adviser requests All Advisers/combined send. 2. Student requests recipients_preview/send. 3. Mark a foreign notification read. | Foreign ID; disallowed audiences. | Disallowed send/preview returns 403. Foreign mark_read leaves that row unchanged even though implemented response may be ok:true. |  |  |  |
| ROL-011 | Reports: data scope, owner and deletion | Adviser / Student / Admin | Reports owned by ADV-A and ADV-B with distinguishable data. | 1. A lists/downloads own report. 2. A requests B's file and attempts deletion. 3. Student requests report API. 4. Admin opens both. | Report IDs; generated_by_user_id fixtures. | Adviser list is owner-scoped, foreign file 403, and all adviser deletes 403. Student denied; admin may access all reports. |  |  |  |
| ROL-012 | Audit scope and stage-label permissions | Adviser / Student / Admin | Audit events for both advisers; original stage label captured. | 1. A lists audit and filters for B's student. 2. Student requests audit. 3. A/student attempt stage-label save. 4. Admin saves/restores label. | Foreign studentId; existing stage key. | A cannot retrieve B's events; student audit access is 403. Stage-label mutation is admin-only; read access does not grant save authority. |  |  |  |

## Workflow Test Cases

### Research Adviser review workflow

*Source traceability: research_adviser.php; documents.php; documents_api.php::list/review/override_review; workflow.php::document_workflow_state/advance_stage_for_document; assets/js/prism-ui.js*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ADV-001 | Assigned student/document views | Adviser | ADV-A and ADV-B have distinguishable students and uploads. | 1. Open research_adviser.php directly as A. 2. Open Documents and IERB Progress. 3. Compare visible data. | Own, other-adviser and unassigned records. | Student/document views contain only assigned records; opening the adviser dashboard does not change the current login landing route. |  |  |  |
| ADV-002 | Review pending document | Adviser | Current unsubmitted document for assigned student. | 1. Open Preview/Download. 2. Set Under Review with remarks. 3. Save and reopen. | Assigned document ID; remarks: Initial review started. | Review status, reviewer, remarks and review time persist; workflow remains Pending Adviser Review. |  |  |  |
| ADV-003 | Approve current document | Adviser | Assigned current unsubmitted document; trigger mode recorded. | 1. Select Approved and save. 2. Inspect student workflow and history. | Current document; optional approval remarks. | Document becomes Ready for Formal RPMS Submission. Approval is not itself formal submission; stage behavior follows configured trigger mode. |  |  |  |
| ADV-004 | Request resubmission | Adviser | Assigned current unlocked document. | 1. Select Resubmission Requested. 2. Enter actionable remarks. 3. Save and inspect student view. | Remarks: Revise participant consent details. | Review persists and student sees Needs Revision with remarks; notification/audit record reflects the request. |  |  |  |
| ADV-005 | Deny document | Adviser | Assigned current unlocked document. | 1. Select Denied with explanation. 2. Save and inspect student view/history. | Remarks: Required ethics attachment is missing. | Document shows Needs Revision derived from Denied; explanation and reviewer are retained. |  |  |  |
| ADV-006 | Required review remarks | Adviser / Admin reviewer | Current unlocked document with known original status. | 1. Submit Denied without remarks. 2. Submit Resubmission Requested without remarks. 3. Try remarks over 5,000 characters via API. | Blank remarks; 5,001-character remarks. | Invalid review returns 422; original status/remarks are not partially overwritten. Approval does not share the same mandatory-denial-remarks rule. |  |  |  |
| ADV-007 | Cross-adviser review rejection | Adviser | ADV-A session; known current document belonging to ADV-B. | 1. Request file and versions. 2. Submit review or delete using B's document ID. | Foreign document ID. | Each operation is rejected with 403; neither file bytes nor a changed review state are exposed. |  |  |  |
| ADV-008 | Admin override and both advancement modes | Admin | Separate disposable fixtures with Stage 1 current document; operator can select each trigger mode safely. | 1. In approval mode, override to Approved with reason. 2. Compare normal approval. 3. Repeat on fresh fixtures in submission mode. | Reason: Verified committee approval; modes approval/submission. | In approval mode both approval paths advance to eligible next stage. In submission mode approval leaves stage unchanged until formal submission; reason/audit remain recorded. |  |  |  |

### Student document lifecycle and concurrency

*Source traceability: documents.php; role_portal.php; assets/js/role-portal.js; assets/js/prism-ui.js; documents_api.php::upload/review/submit_to_rpms/versions/delete; workflow.php*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DOC-001 | Upload valid document | Student | Linked student row; no locked current document for chosen stage/type. | 1. Open student document upload. 2. Choose valid PDF/TXT and type, enter notes. 3. Upload/reload. | Small valid PDF; type QA Ethics Form; notes QA upload. | File and version 1/current row are saved; workflow is Pending Adviser Review. Student stage/identity come from linked server row. |  |  |  |
| DOC-002 | Upload validation | Student | Linked student row; PHP limits allow request to reach app for size case. | 1. Try missing file. 2. Try .php extension. 3. Try extension/MIME mismatch. 4. Try file over 20 MiB. | Empty file field; harmless text .php; renamed incompatible file; 20 MiB+1 file. | Each invalid upload is rejected without a saved document. App emits 413 for oversize that reaches it; unsupported extension/MIME is rejected. |  |  |  |
| DOC-003 | Student upload identity and stage tampering | Student | Two different student rows and stages. | 1. Replay own upload while claiming another studentDbId/student name and stage. 2. Inspect resulting row. | STUD-A1 session; forged STUD-B identity/stage. | Upload remains associated with authenticated student's linked row/current stage; it cannot be assigned to another student by client fields. |  |  |  |
| DOC-004 | Approval exposes formal-submit action | Student / Assigned adviser | Current student upload pending review. | 1. Confirm student cannot formally submit yet. 2. Adviser approves. 3. Refresh student's workflow panel. | Same current document ID. | Student sees Ready for Formal RPMS Submission and the Submit to RPMS action after approval. |  |  |  |
| DOC-005 | Formal submission success | Student | Own approved current unsubmitted document. | 1. Click Submit to RPMS and confirm. 2. Reload. 3. Inspect history/audit and submission timestamp. | Approved document ID. | rpms_submitted_at/by are stored; state is Submitted to RPMS and document is locked. Stage advances only if configured submission rule is eligible. |  |  |  |
| DOC-006 | Reject premature or duplicate formal submission | Student | One pending document and one already submitted document. | 1. POST submit_to_rpms for pending document. 2. Repeat for submitted document. | Pending and locked IDs. | Both return 409; no duplicate submission transition or extra stage advance occurs. |  |  |  |
| DOC-007 | Revision and re-upload | Student / Assigned adviser | Current document requires resubmission; same stage/type still applicable. | 1. Read remarks. 2. Upload corrected file with same student/stage/type. 3. Inspect both rows. | Revised PDF with same documentType. | New version is current and pending review; prior version is retained as Superseded rather than overwritten. |  |  |  |
| DOC-008 | Version history and separate chains | Student / Adviser / Admin within scope | At least two versions for one type and another type/stage chain. | 1. Open version history. 2. Preview/download old version. 3. Compare separate chain. | Same student/stage/type versions and different type. | History returns that chain newest first; old files remain accessible within scope. Different stage/type uploads do not supersede unrelated chains. |  |  |  |
| DOC-009 | Stale/older version mutations | Student / Assigned adviser / Admin | Version 1 superseded by version 2. | 1. Try reviewing v1. 2. Try overriding v1. 3. Try formal submission of v1. | Superseded v1 ID. | Each attempted current-state transition is rejected with 409; latest version remains authoritative. |  |  |  |
| DOC-010 | Locked submitted-state rules | Student / Adviser / Admin | Current formally submitted document in a chosen student/stage/type chain. | 1. Attempt review status change/override. 2. Student attempts same-chain replacement upload. 3. Attempt same-status reviewer comment. | Locked document; same student/stage/type; valid remarks. | Status-changing review/override and same-chain student replacement are rejected. Same-status review remarks remain permitted as implemented; a new stage/type is a separate chain. |  |  |  |
| DOC-011 | Delete current version restores predecessor | Admin / Assigned adviser | Unlocked chain v1→v2; v2 current. | 1. Delete v2. 2. Inspect list/history and stored current flags. | v2 ID. | v2 is deleted; v1 becomes current again and chain remains usable. No promise of renumbering or gap-free historical version labels. |  |  |  |
| DOC-012 | Submitted document deletion controls | Admin / Adviser / Student | Disposable formally submitted document; consequences accepted. | 1. Adviser/student attempt deletion. 2. Admin tries without reason. 3. Admin deletes with valid reason. | Reason: Removed duplicate formally submitted file. | Student/adviser denied; admin missing reason rejected. Reasoned admin deletion succeeds and is audited as an override. |  |  |  |
| DOC-013 | Admin formal submission without prior approval | Admin | Current unlocked unapproved document. | 1. Attempt formal submit without reason. 2. Repeat with meaningful reason. 3. Inspect status/audit. | Reason: Verified approval outside electronic review. | Missing reason is rejected; valid admin action approves and formally submits with override reason and audit. Adviser cannot use this admin path. |  |  |  |
| DOC-014 | Concurrent uploads in one version chain | Student / Admin test operators | Disposable student; two actual browser sessions/connections; no submitted lock. | 1. Start two uploads for same student/stage/type nearly together. 2. Wait for responses. 3. Query chain/current flags. | Two distinct valid PDFs. | Successful uploads serialize and receive ordered versions with only one current row. Any rejected operation leaves no partial current version. |  |  |  |
| DOC-015 | Concurrent review/submission/delete | Admin / Adviser / Student test operators | Disposable current approved/unlocked documents; separate connections. | 1. Race review with formal submit. 2. On fresh fixture race deletion with review. 3. Repeat and inspect history/current rows. | Same document IDs; actual overlapping requests. | Committed result follows serialization; stale conflicting write is rejected rather than overwriting newer state. No duplicate advance or invalid locked-state transition; a later missing-ID read may return 404. |  |  |  |
| DOC-016 | Stage mismatch and Completed boundary | Admin / Adviser / Student within scope | Fixtures at Stage 2 with older Stage 1 document, and Stage 5 with current Stage 5 document. | 1. Apply configured trigger to older-stage document. 2. Apply it to Stage 5 document. 3. Repeat eligible transition. | Recorded approval/submission mode. | Older-stage document does not advance a different current student stage. Stage 5 advances to Completed once; no stage beyond Completed is invented. |  |  |  |

## Security Test Cases

### Security and hardened regression

*Source traceability: security.php; auth_rate_limit.php; config.php::require_post_same_origin/current_user; login_process.php; register_process.php; profile_api.php; documents_api.php; ierb_api.php; .htaccess; includes/.htaccess; storage/.htaccess; tests/.htaccess; includes/prism-navigation.php; assets/js/dashboard-sidebar.js; assets/css/dashboard-sidebar.css*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| SEC-001 | Same-origin AJAX and forms remain valid | All permitted roles | Browser uses the actual app origin including port; valid session/input. | 1. Login. 2. Save an authorized student/IERB edit. 3. Send a notification and change password with ordinary UI. | Matching Origin or Referer. | Legitimate browser requests succeed; protection does not require an unimplemented CSRF-token field or break current AJAX payloads. |  |  |  |
| SEC-002 | Cross-origin, absent-origin and method rejection | Authenticated tester / Anonymous login tester | Disposable records; replay tool can control HTTP headers. | 1. POST with foreign Origin. 2. Remove both Origin and Referer. 3. GET a protected mutation endpoint. 4. Try valid Referer with no Origin. | Foreign scheme/host/port; absent headers; students_api.php?action=save. | Foreign/absent origin evidence returns 403 without mutation; API mutation GET returns 405. Matching Referer fallback is accepted with otherwise valid authorized input. |  |  |  |
| SEC-003 | Direct includes/storage/test access denied | Anonymous / Authenticated tester | Apache uses repository access rules; no real secret paths requested. | 1. Request /includes/academic_catalog.php and /includes/prism-navigation.php. 2. Request tests fixture and known dummy stored file directly. | Only source-partial paths and test-owned dummy file. | Direct HTTP access is denied (403/404); no source, private document bytes or fixture execution. Authenticated document endpoint remains the intended file path. |  |  |  |
| SEC-004 | Unauthorized document IDs and path substitution | Student / Adviser | Own and foreign documents exist. | 1. Substitute foreign IDs in file/versions GET and same-origin summarize POST. 2. Try a traversal-looking value as id. 3. Inspect response bytes. | Foreign ID; ../test-dummy as an ID value. | Ownership guards reject foreign access; malformed/nonexistent ID returns not-found rather than filesystem contents. No unauthorized file or summary is returned. |  |  |  |
| SEC-005 | Uploaded content cannot execute scripts | Student / Operator | Only harmless canary content; disposable upload. | 1. Attempt .php/.html upload. 2. Upload permitted TXT containing literal markup if MIME validation allows. 3. Open via file endpoint and direct storage path. | Harmless script text setting a local marker only; no exfiltration. | Unsupported files are rejected. Accepted text/office content is forced to opaque download; nosniff and sandbox CSP are present. Storage access is denied and no script runs on PRISM origin. |  |  |  |
| SEC-006 | Dynamic text is escaped | Admin / Adviser test accounts | Test-only records permit literal text; browser console open. | 1. Save harmless markup-looking text in names, titles, notes, labels/messages/reasons within validation. 2. View lists, histories, dialogs and reports. | Literal &lt;img src=x onerror=alert(1)&gt;; quotes and ampersands. | Values render as text or safe PDF content, not executable DOM. No script runs; validated email fields continue to reject malformed email rather than accepting markup. |  |  |  |
| SEC-007 | Security headers and existing inline UI | All roles | Apache/PHP app available; standard browser. | 1. Inspect response headers on login and protected PHP pages. 2. Exercise inline-powered controls. 3. Embed page from a separate local origin. | X-Frame-Options, Referrer-Policy, CSP, nosniff. | SAMEORIGIN, strict-origin-when-cross-origin, frame-ancestors/base-uri self and nosniff are present. Cross-origin framing is blocked; current inline UI is not broken by script/style CSP restrictions. |  |  |  |
| SEC-008 | Safe database failure response | Operator | Disposable app instance isolated from other users; restore plan for DB service. | 1. Make its test DB unavailable. 2. Request protected API/page. 3. Inspect responses and restricted error log. 4. Restore service. | Temporary unavailable test database; display_errors enabled at server level. | Generic failure is returned (PDO failure 503) without credentials, SQL or stack trace. Error handling does not recursively invoke DB logging. |  |  |  |
| SEC-009 | Reset limits and canonical-URL misconfiguration | Operator / Anonymous | Separate disposable windows/instances; known and unknown test emails. | 1. Exceed five reset attempts/email and twenty/IP per 900 seconds in isolated runs. 2. Test invalid APP_BASE_URL via operator-prepared configuration. 3. Compare user-facing responses. | Known/unknown valid emails; no configuration values copied to report. | Valid-format requests remain generic; throttled/misconfigured runs do not issue mail. Operator diagnostics/logs identify configuration failure without account enumeration. |  |  |  |
| SEC-010 | Admin-registration code throttling | Anonymous / Operator | Registration test instance; code value remains private; fresh counter. | 1. Submit eight attempts from same test IP with changing identifiers/wrong codes. 2. Submit ninth attempt. 3. Check account table. | Test-only employee IDs/emails; wrong code values. | IP-bound throttling rejects further attempts within 900 seconds; changing email/ID does not bypass it. No account is created by rejected attempts. |  |  |  |
| SEC-011 | Production provisioning omits plaintext credentials | Admin / Operator | Production-policy test environment with mail failure; student/adviser/IERB creation fixtures. | 1. Create a new account through each supported administrative path. 2. Inspect JSON/UI/log evidence without recording secrets. | Valid unique identities; academic tuple where required. | Setup-pending state is explicit; temporaryPassword is absent. Development disclosure requires all explicit local-only conditions and is not enabled merely by delivery failure. |  |  |  |
| SEC-012 | Session deactivation and password-race protection | Operator / Account owner | Two signed-in profiles and controlled overlapping change/reset requests. | 1. Deactivate user while signed in and request protected content. 2. On another fixture complete reset while signed-in change is pending. 3. Re-enable only as cleanup. | Inactive status; distinct replacement passwords. | Inactive/credential-mismatched sessions fail next request. Stale signed-in update cannot overwrite newer reset hash; it fails authentication or returns conflict according to ordering. |  |  |  |
| SEC-013 | CSV formula neutralization | Admin / Adviser | Test records with spreadsheet-like research/name text; authorized export scope. | 1. Export CSV. 2. Inspect raw cells before opening in spreadsheet. 3. Open safely with formulas disabled. | Values beginning =, +, -, @, tab or carriage return. | Potential formula-leading values are prefixed safely by export logic; no formula is executed by opening test output. Scope/filter rules remain unchanged. |  |  |  |
| SEC-014 | Nested navigation and keyboard regression | Admin / Adviser | Permitted pages with nested sidebar; desktop and narrow viewports. | 1. Toggle nested navigation by keyboard. 2. Inspect aria-expanded/aria-current. 3. Collapse sidebar, tab through, use Escape, then reopen. | 375/768/1280 px; both themes. | Expanded/current attributes match state; hidden collapsed content is not accidentally focusable. Escape and keyboard navigation remain usable, with no missing controls or console exceptions. |  |  |  |

## Migration/Integration Test Cases

### Schema v5 and real integration gates

*Source traceability: tools/migrate-academic.php; config.php::migrate/migrate_schema/widen_student_course_if_needed/add_column_if_missing; includes/academic_catalog.php; RELEASE_CHECKLIST.md*

| Test Case ID | Module/Feature | User Role | Preconditions | Test Steps | Test Data | Expected Result | Actual Result | Status | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| MIG-001 | Verified backup and disposable restore prerequisite | Operator / DBA | Owner approval for isolated migration testing; no important database selected. | 1. Export a test baseline with schema/data. 2. Restore into a separately named disposable DB. 3. Verify counts/legacy course bytes and access isolation. | Restorable v4 snapshot; nonsecret count/checksum inventory. | Disposable restore matches baseline and is explicitly identified before migration. Backup is an operational prerequisite, not an automated rollback guarantee. |  |  |  |
| MIG-002 | v4 ordinary startup is blocked | Operator / Tester | Disposable v4 DB; migration opt-in absent; original schema captured. | 1. Request a DB-backed app page/API through Apache. 2. Inspect schema/version afterward. | schema_version=4. | Startup fails safely because v5 migration is pending; ordinary web initialization does not execute academic DDL or stamp v5. |  |  |  |
| MIG-003 | Migration entry point is not web-runnable | Anonymous / Operator | Disposable v4 DB; Apache running. | 1. Request tools/migrate-academic.php over HTTP, including an apply-looking query string. 2. Inspect schema. | HTTP URL only. | Web request is denied by Apache or PHP CLI guard (403/404); no migration occurs regardless of query parameters. |  |  |  |
| MIG-004 | CLI requires exact apply argument and opt-in | Operator | Disposable v4 DB; commands run only in approved test process. | 1. Run tool without --apply. 2. Run with --apply but no env flag. 3. Try flag other than 1 or extra arguments. | C:\xampp\php\php.exe tools/migrate-academic.php [invalid combinations]. | Tool exits 1 before application bootstrap and reports migration not started; database remains unmigrated. |  |  |  |
| MIG-005 | Authorized v4 migration | Operator / DBA | MIG-001 complete; v4 clone selected; schema/data inventory captured. | 1. In approved CLI process set PRISM_ALLOW_SCHEMA_V5_MIGRATION=1. 2. Run PHP tool with --apply. 3. Inspect schema_meta and output. 4. Clear process opt-in. | Disposable clone; exact --apply argument. | Successful migration reaches schema version 5 after DDL completes. No live/important database is touched. |  |  |  |
| MIG-006 | Course widening and attribute preservation | Operator / DBA | v4 clones with narrower CHAR/VARCHAR course and known attributes. | 1. Capture SHOW CREATE TABLE students. 2. Migrate. 3. Compare column type/default/nullability/charset/collation/comment. | Existing VARCHAR(100) or narrower CHAR; test default/comment. | Narrow course becomes VARCHAR(255); unrelated column attributes and stored values are preserved. |  |  |  |
| MIG-007 | Nullable academic columns added | Operator / DBA | Freshly migrated v4 clone. | 1. Inspect INFORMATION_SCHEMA.COLUMNS or SHOW CREATE TABLE. 2. Inspect legacy rows. | academic_unit_key, program_key, year_level, academic_year. | Columns exist as nullable VARCHAR(64), VARCHAR(80), VARCHAR(40), VARCHAR(9), default NULL; no fabricated academic backfill. |  |  |  |
| MIG-008 | Legacy byte-for-byte and row-count preservation | Operator / DBA | Pre-migration inventory includes NULL/empty/whitespace/non-ASCII/free-text course values. | 1. Compare pre/post student counts and IDs. 2. Compare exact course values and bytes. 3. Inspect new keys. | Legacy tuples and long/non-ASCII labels that fit old schema. | No student is lost or silently relabeled; NULL/empty/whitespace remain distinct and newly added academic values remain NULL. |  |  |  |
| MIG-009 | Safe rerun and normal v5 startup | Operator / Tester | Successful schema-v5 migration. | 1. Rerun authorized CLI tool. 2. Compare data/schema. 3. Remove opt-in from web/worker environment and open app. | Already-v5 disposable DB. | Rerun causes no duplicate columns/backfill/destructive change. Normal application initialization works at v5 without migration opt-in. |  |  |  |
| MIG-010 | Partial DDL failure and retry | Operator / DBA | Disposable clone/snapshot only; controlled DDL-permission failure after an early step. | 1. Cause a later migration step to fail. 2. Inspect persisted DDL/version. 3. Restore permission and retry. | Partially widened/added schema; version below 5. | Failure does not falsely stamp v5. Completed DDL may remain because ALTER TABLE commits implicitly; retry completes idempotently without data loss. |  |  |  |
| MIG-011 | Concurrent migration lock | Operator / DBA | Two approved CLI processes against one disposable pre-v5 DB. | 1. Start migration in both processes. 2. In separate run hold prism_migrate advisory lock past timeout. 3. Inspect schema/version. | GET_LOCK name prism_migrate; 30-second wait behavior. | Processes serialize/recheck version; no duplicate ALTER outcome. Lock timeout fails safely, with no false success/version stamp. |  |  |  |
| MIG-012 | Adequate-width, text and unsupported course types | Operator / DBA | Separate disposable schema variants. | 1. Migrate course VARCHAR(300), CHAR(255) and TEXT variants. 2. Try an unsupported type in separate clone. | Adequate-width character/text columns; unsupported integer type. | Adequate-width/text columns are not shrunk. Unsupported metadata stops for manual review rather than guessing/replacing data. |  |  |  |
| MIG-013 | Fresh schema and initial administrator | Operator / DBA | Empty disposable DB; approved private bootstrap credentials supplied by operator. | 1. Run authorized migration/initializer. 2. Inspect fresh schema and seeded administrator. 3. Sign in and complete forced change. | Unique bootstrap password meeting configured minimum; never written in report. | Fresh schema includes VARCHAR(255) course and academic columns; no public default password is assumed. Initial admin requires password change. |  |  |  |
| MIG-014 | Real persistence and end-to-end integration smoke | Admin / Adviser / Student / Operator | Migrated disposable DB; real independent DB connections and controlled mail/AI integrations. | 1. Execute UG4/UG5/UG6/graduate/legacy cases through both APIs. 2. Run scoped review/submission and concurrency cases. 3. Exercise setup/reset mail, scheduled worker and AI/PDF fallback. | Referenced ACA, DOC, AUTH, NOTIF and REP fixtures. | Values round-trip without truncation or authorization drift. All referenced integration outcomes are independently observed; fixture-suite success alone does not certify MySQL/mail/AI behavior. |  |  |  |

## Academic Catalog Reference

This inventory is transcribed from includes/academic_catalog.php. Keys are stable implementation values; unit labels are supplied catalog groupings, not a certification of official departmental names. Durations control undergraduate year choices only. Graduate duration/unit/year-level rules remain unspecified.

| Unit key | Catalog grouping label |
| --- | --- |
| amt | Accountancy / Management / Technology |
| elas | Education / Liberal Arts / Science |
| ihtm | International Hospitality Management / Tourism |
| dentistry | Dentistry |
| nursing | Nursing |
| pmt | Pharmacy / Medical Technology |
| optometry | Optometry |

| Program key | Exact course/program label | Unit key | Duration |
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
| mba_thesis | Master of Business Administration (Thesis Program) | NULL | Unspecified |
| mba_non_thesis | Master of Business Administration (Non-Thesis) | NULL | Unspecified |
| mba_tqm | Master of Business Administration (Total Quality Management) | NULL | Unspecified |
| ms_psychology | Master of Science in Psychology | NULL | Unspecified |

Academic Year allowlist: 2025-2026, 2026-2027, 2027-2028. Undergraduate year labels: 1st Year through the program's permitted final year. No default active year, graduate standing, extra future year or automatic rollover is asserted.

## Migration execution reference

The following is a future operator procedure for the already-approved disposable target only; it was not executed during document preparation. First satisfy MIG-001, confirm the isolated database connection without exposing credentials, and obtain any required operational authorization. ALTER TABLE can commit implicitly, so transaction rollback is not a backup.

```powershell
From C:/xampp/htdocs/rpms_system in a dedicated PowerShell process:
$env:PRISM_ALLOW_SCHEMA_V5_MIGRATION = '1'
& 'C:\xampp\php\php.exe' 'tools/migrate-academic.php' --apply
Remove-Item Env:PRISM_ALLOW_SCHEMA_V5_MIGRATION
Inspect the command exit code, schema version and data preservation before proceeding.
```

Use a dedicated test process and remove the flag after the run, including failures. Do not leave it in ordinary web/worker environments. A fresh database also invokes existing initial-administrator setup; supply any bootstrap secrets privately, never in test evidence.

## Test Summary Sheet

Enter outcome counts only after manual execution. For each module, Passed + Failed + Blocked + Not Tested must equal Total Cases. A case with several data variations has one ID and counts once; it passes only when all listed variations pass. No outcome counts have been prefilled.

| Module | Total Cases | Passed | Failed | Blocked | Not Tested |
| --- | --- | --- | --- | --- | --- |
| AUTH — Authentication and account access | 17 |  |  |  |  |
| STU — Admin Student Management | 14 |  |  |  |  |
| IERB — IERB Progress Management | 12 |  |  |  |  |
| NOTIF — Notifications | 13 |  |  |  |  |
| REP — Dashboard and reporting | 12 |  |  |  |  |
| LOG — Account Activity Logs | 4 |  |  |  |  |
| ACA — Academic catalog, dependent controls and legacy preservation | 28 |  |  |  |  |
| ROL — Role boundaries, ownership and direct API access | 12 |  |  |  |  |
| ADV — Research Adviser review workflow | 8 |  |  |  |  |
| DOC — Student document lifecycle and concurrency | 16 |  |  |  |  |
| SEC — Security and hardened regression | 14 |  |  |  |  |
| MIG — Schema v5 and real integration gates | 14 |  |  |  |  |
| TOTAL | 164 |  |  |  |  |

### Execution record

| Field | Entry |
| --- | --- |
| Test cycle / dates |  |
| Tester(s) |  |
| Recorded source commit |  |
| Environment/version record |  |
| Evidence location / defect register |  |
| Reviewer / review date |  |
| Release decision (after execution) |  |
