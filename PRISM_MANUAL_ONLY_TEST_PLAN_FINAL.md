# PRISM Manual-Only Acceptance Test Plan

- **Code branch:** `prism-v2-final-audit`
- **Code baseline commit:** `ef6dc8d7fc02f4ac82927989c08cfcc8d7fcc232`
- **Schema version:** 6
- **MariaDB reference:** 10.4.32
- **Revision date:** 02 October 2026
- **QA package commit:** Pending docs-only commit

## Scope rule

This is the human/operator/external-environment acceptance complement to the technical regression. Repeatable technical cases remain mandatory and are tracked in `PRISM_TECHNICAL_TEST_TRACEABILITY_MATRIX.md`; they are not replaced by this trimmed plan.

## Evidence and independence model

- Astra/Codex executes technical verification on the exact release candidate and records commands, counts, runtime versions, disposable DB evidence, defects and fixes.
- Claude and Grok independently challenge the GitHub baseline and the adequacy of the tests, especially high-risk authorization, data-integrity, workflow, race/session and migration behavior.
- Humans execute only the MAN cases that require external delivery, real deployment/operator action or subjective judgment.
- All 164 original test IDs must have a named owner and concrete evidence or an explicit gap/Blocked disposition before claiming complete verification.

## Prior manual results

AUTH-001 through AUTH-017 and STU-001 through STU-004 were manually completed and reported Passed during the current testing cycle. Final-SHA automation must still re-cover their repeatable behavior.

## Manual-only cases

### MAN-001 - Real Password-Reset Email Delivery and Link

**Why manual-only:** Code/tests can validate token logic and mail construction, but only the controlled mailbox proves real delivery and the delivered link in the actual mail route.

**Preconditions:** Controlled test inbox; mail enabled; valid canonical application URL; disposable known account.

**Steps:**
1. Request a reset for a known test account and separately for an unknown valid-format email.
2. Inspect only the controlled known inbox and confirm the real reset message arrives.
3. Open the delivered link and confirm it targets the intended application origin.
4. Request another reset within the cooldown period and confirm no duplicate real email is sent when cooldown applies.
5. Complete the reset and sign in with the new password.

**Expected result:** Generic UI behavior is preserved; only the known account receives the real email; the delivered link targets the correct origin and completes the reset. No token/secret is copied into evidence.

**Status:** PASSED

**Evidence / Remarks:** Carried forward from the completed AUTH-012 to AUTH-014 manual run; the user confirmed controlled test inbox receipt and successful reset behavior.

**Original coverage linked:** AUTH-012, AUTH-013, AUTH-014 external-delivery portion.

### MAN-002 - Real Account-Setup Email Delivery

**Why manual-only:** Provisioning persistence can be automated, but real mailbox receipt and practical setup instructions require external observation.

**Preconditions:** Controlled student/adviser test inbox; real mail transport; disposable new account; production disclosure policy enabled.

**Steps:**
1. Create one disposable account through a supported administrative path.
2. Observe UI/API delivery state without recording plaintext credentials.
3. Confirm the real setup message arrives in the controlled inbox.
4. Follow the setup instructions through first login/password-change.

**Expected result:** Account persists; when delivery is reported as sent, the controlled inbox receives the setup message; no plaintext temporary password is exposed under production policy; setup instructions are usable.

**Status:** Not Tested

**Original coverage linked:** STU-001 delivery component, STU-014, SEC-011.

### MAN-003 - Immediate Notification Email Delivery

**Why manual-only:** Audience logic is automatable; real external inbox delivery is not.

**Preconditions:** Controlled student/adviser inboxes; known assignments; mail transport enabled.

**Steps:**
1. Send one immediate admin notification to a representative student audience.
2. Send one immediate adviser notification within the adviser scope.
3. Check intended controlled inboxes and one deliberately excluded inbox.
4. Compare observed receipt with PRISM delivery/history state.

**Expected result:** Intended recipients receive the real email; excluded recipients do not; displayed delivery state matches the observed outcome.

**Status:** Not Tested

**Original coverage linked:** NOTIF-001 to NOTIF-004 external-delivery portion.

### MAN-004 - Scheduled Notification Delivery in Intended Deployment

**Why manual-only:** Disposable worker logic is automatable, but the actual deployed scheduler/cron timing and real one-time delivery require the target environment.

**Preconditions:** Intended deployment; scheduler/cron or approved worker execution; controlled inbox; known timezone.

**Steps:**
1. Schedule a unique notification several minutes in the future.
2. Confirm no external email before the due time.
3. Allow the deployed scheduler/worker to run after the due time.
4. Confirm one real delivery.
5. Allow another cycle and confirm no duplicate delivery.

**Expected result:** Scheduled state remains before due time; delivery occurs after due time exactly once; final stored state agrees with the observed delivery.

**Status:** Not Tested

**Original coverage linked:** NOTIF-008 and NOTIF-010 deployment/external portion.

### MAN-005 - IERB Follow-Up Email Delivery

**Why manual-only:** Endpoint/scoping can be automated; real external delivery still requires inbox observation.

**Preconditions:** Existing in-scope student; assigned adviser/admin access; controlled student inbox; mail enabled.

**Steps:**
1. Send a standard IERB follow-up from the normal UI.
2. Inspect the controlled student inbox.
3. Verify the message corresponds to the intended student/action.
4. Confirm an unrelated controlled student does not receive it.

**Expected result:** The intended student receives the follow-up once, the message is understandable, and PRISM delivery state agrees with observation.

**Status:** Not Tested

**Original coverage linked:** IERB-012 external-delivery portion.

### MAN-006 - Live AI Report Quality and PDF Human Review

**Why manual-only:** Automation can verify plumbing/scope/PDF generation, but a human must judge fidelity, usefulness and hallucination risk of live AI output.

**Preconditions:** Approved live AI configuration; representative non-sensitive data; admin/adviser scoped datasets.

**Steps:**
1. Generate one summarized live-AI report.
2. Generate one full live-AI report.
3. Compare both PDFs with known fixture records.
4. Review readability, section order, names/stages/statuses, scope, and AI disclosure.
5. Record any fabricated, unsupported, misleading or materially omitted statement.

**Expected result:** Reports remain in scope, faithfully reflect source data, are readable, disclose AI assistance as applicable, and contain no material invented facts. If live AI is unavailable, mark Blocked.

**Status:** Not Tested

**Original coverage linked:** REP-005 and REP-006 live-AI/human-quality portion.

### MAN-007 - Responsive Visual Acceptance Across Key Role Pages

**Why manual-only:** Automated UI gates catch many regressions, but final readability, hierarchy and visual usability remain human acceptance judgments.

**Preconditions:** Representative populated data; Chrome/Edge; about 375/768/1280 px; light/dark themes where supported.

**Steps:**
1. Inspect login, admin dashboard, Student Management, IERB Progress, Notification Center, adviser workspace, student portal, Account and report/history pages.
2. Open representative modals/tables/cards with long realistic labels/messages/filenames.
3. Switch themes where supported.
4. Record overlap, clipping, unreadable wrapping, off-screen controls or unusable scrolling.

**Expected result:** Meaningful content remains readable and controls usable; no important overlap/clipping; long values wrap acceptably; key actions remain understandable.

**Status:** Not Tested

**Original coverage linked:** IERB-010, NOTIF-013, REP-012, LOG-004, ACA-028 visual portion, SEC-014 visual portion.

### MAN-008 - Keyboard, Focus, and Accessibility Acceptance

**Why manual-only:** Static/a11y tooling can inspect code, but a keyboard-only human pass is needed for practical focus order, visibility and modal/navigation comprehension.

**Preconditions:** Desktop browser; no mouse during case; representative admin/adviser pages and edit modal.

**Steps:**
1. Navigate primary/nested navigation with Tab/Shift+Tab and Enter/Space.
2. Open/close representative modals using the keyboard, including Escape where implemented.
3. Verify focus visibility and sensible return focus after close/save.
4. Confirm collapsed/hidden content is not accidentally focusable.
5. Check labels/errors are understandable without relying only on color.

**Expected result:** Core workflows are operable without a mouse; focus is sensible/visible; modals do not strand focus; hidden content is not focusable; labels/errors are understandable.

**Status:** Not Tested

**Original coverage linked:** ACA-028 keyboard portion, LOG-004, SEC-014.

### MAN-009 - Human End-to-End Workflow Comprehension

**Why manual-only:** State transitions can be automated; a human must judge whether each role can understand current state, feedback and next action.

**Preconditions:** Disposable assigned student; valid test document; admin available; configured stage-advance mode recorded.

**Steps:**
1. Student uploads a valid document and identifies the next action from the UI.
2. Adviser requests revision with actionable remarks.
3. Student confirms feedback is clear and uploads a corrected version.
4. Adviser approves the current version.
5. Student confirms formal Submit to RPMS appears only when appropriate and submits.
6. Admin/RPMS confirms submitted state is clear and consistent across roles.

**Expected result:** At each handoff, a human can identify current state, responsible role, feedback and next action without contradictory labels or misleading controls.

**Status:** Not Tested

**Original coverage linked:** ADV-002 to ADV-005 and DOC-001/DOC-004/DOC-005/DOC-007 as UAT only.

### MAN-010 - Real Cross-Browser and Deployed-Environment Smoke

**Why manual-only:** Source/local automation cannot certify the exact lab/presentation machine, deployed origin, server policies, browser policies and network path.

**Preconditions:** Deployed PRISM URL; separate computer; Chrome and Edge; test role accounts.

**Steps:**
1. Open the deployed URL from the separate machine and verify HTTPS/certificate behavior as applicable.
2. Sign in as admin, adviser and student in separate sessions.
3. Open each role landing page and one core feature page.
4. Upload and download/preview a small valid PDF.
5. Open a password-reset email link generated by the deployed instance.
6. Check for obvious mixed-content/resource failures.

**Expected result:** Application is reachable from the external machine; redirects/assets use the deployed origin; sessions work; core pages and file actions work in real browsers; reset links resolve to deployed PRISM.

**Status:** Not Tested

**Original coverage linked:** Deployment acceptance complement to AUTH/DOC/UI technical coverage.

### MAN-011 - Operator-Controlled Schema v6 Migration Gate for Important Deployment DB

**Why manual-only:** Astra should prove migration behavior on disposable databases. An important staging/production-like DB must only be migrated by an authorized operator with an actually tested backup/restore path and explicit target verification.

**Preconditions:** Owner approval; current v6 technical migration suite green; intended DB name known; migration guards available; backup destination available.

**Steps:**
1. Export the intended database backup and record non-secret row-count/schema baseline.
2. Restore that backup into a separately named disposable database and verify representative tables/row counts. Do not proceed if restore proof fails.
3. Confirm the configured DB name and explicit expected-target guard before DDL.
4. Run the authorized v6 migration once against the intended important DB.
5. Verify schema_version=6, required AUTO_INCREMENT metadata, required foreign keys and preservation of legacy zero-ID rows where present.
6. Create one disposable student and confirm positive ID allocation.
7. Rerun the migration to confirm idempotence, then remove migration opt-in variables.

**Expected result:** Backup restore is proven before important-DB DDL. Only the explicitly intended DB is touched. Migration reaches v6 without data loss, new IDs allocate normally, constraints are present, and rerun is safe/idempotent. Any target mismatch or incompatibility stops before DDL.

**Status:** Partial - test DB verified

**Evidence / Remarks:** Controlled prism_academic_test migration and post-migration student creation were already verified. Important/deployment DB migration remains pending.

**Original coverage linked:** Manual operator gate for current schema v6. Disposable compatibility/failure/retry/lock/target-guard testing remains Astra-owned.

## Release / presentation gate

- [ ] Technical audit is tied to code baseline `ef6dc8d7fc02f4ac82927989c08cfcc8d7fcc232` or a later explicitly recorded final code SHA.
- [ ] All 164 original IDs have concrete technical/manual evidence or an explicit gap/Blocked disposition.
- [ ] Astra final regression is clean on the final code SHA and includes exact commands/counts/runtime/DB evidence.
- [ ] Claude/Grok high-risk findings have been reconciled.
- [ ] All applicable MAN cases are Passed or formally Blocked.
- [ ] MAN-010 passes on the actual/equivalent presentation machine/network.
- [ ] MAN-011 proves backup restore in a separately named disposable DB before important-DB migration.
