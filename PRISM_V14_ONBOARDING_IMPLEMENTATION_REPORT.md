# PRISM V14 onboarding implementation review

Review date: **30 October 2026, Asia/Manila**. Repository: `Cloud11011/rpms_system`. Dedicated existing worktree: `C:\PRISM_Workspaces\prism-v14-onboarding-group-integration`. Branch: `feature/prism-v14-onboarding-group-integration`. Starting HEAD: `770bed25526aa599c89083c382a8b083e8d58d37`; the initial working tree was clean.

**Final verdict: PASS WITH CONDITIONS. Local implementation and isolated validation are complete.** This report describes the proposed local update. It does not authorize a migration, activation, commit or deployment.

## Implemented behavior

The existing Student Records Add Student form and Invite Student dialog offer **Create New Group** and **Join Existing Group**. No Research Group Records page, navigation item, enrollment page, reconciliation page or unrelated dashboard was added.

Creating a group requires a full research title and assigned active Adviser. Academic data is checked against the existing catalog. The valid full UTF-8 title is preserved (3–255 characters, matching the compatibility Student column); a separate transliterated identifier is allocated under the existing membership fence and unique identifier constraints. Duplicate titles require explicit confirmation and create distinct projects. They never imply identity or membership.

Joining uses an explicit canonical project ID selected by an authorized Administrator. The server compares catalog **program keys and academic year**, permits different year levels, rejects completed projects, and inherits the current authoritative title, Adviser and protocol code. The UI locks the inherited fields. A project with document evidence requires an explicit membership reason and confirmation, checked again at activation if evidence appeared while onboarding was pending. Neither membership creation nor an account edit copies, resets or replaces existing documents, acceptances, versions or workflow decisions.

Ordinary canonical Student edits cannot change the shared title, one member's Adviser, protocol code, PI designation, program or academic year. Existing Student Records actions provide audited group title, protocol, Adviser and PI changes. These use the existing eligible Admin checks, project revision checks, reason requirements, Student fence and migration mutex. PI changes synchronize the compatibility designation flags and preserve their canonical event history.

Research title is the primary visible group name in Student Records tables/cards, Adviser assignments, IERB monitoring and advancement choices, report choices, and new reports/exports. Official protocol code is secondary, with **Not yet assigned** for a missing code. Student Records displays, filters and sorts by canonical project stage and completion status while retaining legacy stored stages for historical compatibility; it does not classify completed members as legacy Stage 1. Project IDs and prior title aliases remain available for stable identity and retained history.

## Add Student, invitations and automatic setup

The existing Add Student account provisioning and password-setup requirements are retained. A staff-approved assignment is saved atomically with the new account. A temporary-password account receives no canonical membership, project or workflow until the required account prerequisites are complete.

Administrator Student invitations save the intended academic data and create/join decision inside the invitation's account transaction. Invitation tokens, hashes, expiration, password setup, institutional-email restrictions, resend cooling and profile completion remain intact. No project is created merely by inviting someone. Resend rotates the token on the existing account and preserves its assignment.

During profile completion the server preserves the staff-approved title; a Student cannot substitute a project ID, official title or code. Program/year conflicts put the assignment into **review**, without granting membership or choosing another group. Administrators can review, replace or cancel pending assignments using existing Student Records controls, with revision checks and retained audit evidence. Correcting an assignment does not falsely count its own pending title as another project. Ordinary Student Records and IERB profile edits cannot silently replace approved pending research information, and legacy stage writers cannot advance a pending assignment. Eligible complete, noncanonical Students, including those invited by Advisers, have an Admin review route in the same controls. Historical progress or legacy research associations require a reviewed institutional transition instead of automatic conversion.

Eligible profile completion and eligible Admin assignment saves finalize setup internally. Normal authorized workspace access also resumes recoverable pending work. The persisted approving Admin is revalidated for current role, active status, eligibility and legal acceptance at activation; an old approval never bypasses revoked authority. Student password, legal, profile and archive state, invitation acceptance, Adviser eligibility and account-recovery state are checked before membership.

The first activated assignment atomically creates **one Initial Submission workflow**, canonical project, immutable project ID, title-derived identifier, verified member row and history. Later members use a dedicated add-member path and inherit the current workflow revision/stage. They do not call `research_project_reconcile()` or create another workflow. Unique membership/account constraints, the existing Student fence and `prism_migrate` mutex prevent duplicate activation. A mandatory-audit failure rolls the whole project transaction back, retaining only a clearly pending assignment for retry. Pending, review and cancelled assignments do not grant shared-document access; pending Students are prevented from creating competing legacy submission evidence.

Advisers retain their existing invitations and review capabilities. An Adviser invitation or Student-entered research title alone never creates an official canonical project or verified membership. An Adviser-origin Student without RPMS research approval is shown a review message and cannot start a competing individual submission path; retained historical records remain available. There is no additional Administrator hierarchy.

## Protocol source of truth and schema proposal

Schema 15 lacks durable pending-assignment storage and a project-level unique protocol source. The proposed, separately reviewed **`research_onboarding_v1` extension** adds exactly two tables:

| Table | Purpose |
| --- | --- |
| `research_onboarding_assignments` | One staff-owned, revisioned pending/review/activated/cancelled assignment per Student; approval actor and optional activated project reference. Full prior/new assignment evidence and hashes are retained in transactional audits. |
| `research_project_protocols` | One authoritative nullable code per project, with a database unique constraint and case-insensitive ASCII comparison. |

The base schema version remains **15**. An explicit extension marker is published only after both definitions verify. Existing schema-15 verification remains strict and composes with the separately pinned extension contract. The additive migration never runs on HTTP requests or application startup. It requires CLI execution, `PRISM_ALLOW_RESEARCH_ONBOARDING_MIGRATION=1`, an exact `PRISM_RESEARCH_ONBOARDING_EXPECT_DB` binding, enabled foreign keys, the migration mutex and the reviewed schema-15 base. Dry run is read-only; apply is restart-safe; retained assignment/protocol data blocks rollback. Rollback exclusively locks the activation marker before current retention reads, so a concurrent account transaction cannot commit an assignment between an empty check and table removal. An independent-PDO race test verifies this. No production migration command was executed.

The migration refuses to infer existing canonical protocol codes from Student fields. If such codes exist, an independently reviewed import mapping is required before installation. No existing Student assignment or historical report is migrated or rewritten automatically.

Official codes are optional. Eligible RPMS Administrators alone assign or correct them through a reasoned, revision-checked group operation. Codes currently allow 1–100 ASCII letters/digits, spaces, dots, slashes and hyphens, normalized to uppercase. The authoritative table prevents reuse; legacy unrelated Student codes are checked as well. Uniqueness is conservatively retained even for completed projects. Codes update every member's existing `students.protocol_code` field in the same transaction for compatible searches, old integrations and exports; workflow validation checks for divergence. Prior codes remain in canonical project events. A correction changes neither the permanent project identity nor saved report/PDF bytes.

Student Records, IERB lists/monitoring and Adviser-scoped searches accept full/partial titles and codes, retaining existing name, ID and email searches and authorization scopes. Long title searches and literal wildcard escaping are retained. Adviser directory searches and group filters also use the authoritative research information. Existing report snapshots and prior audits remain immutable; synthetic PDF text and byte hashes are checked in the focused suite.

## Workflow, permissions and retention review

The four-stage business process is retained: all thirteen required initial documents, Adviser review and formal submission, RPMS acceptance and advancement, conditional correction with Amendment only when RPMS requires it, institutional Form 33 approval, and eligible Form 33-0B formal submission completing every verified member exactly once. The existing document/requirement/decision services remain authoritative; onboarding introduces no separate stage progression engine.

All members share one evidence holder, version history and workflow. Uploader and formal-submitter attribution remain individual. Cross-project file/checklist access stays denied. Students and Advisers cannot assign official codes or override RPMS stage decisions. The original V14 activation gate remains mandatory; it is not enabled by this code or by the extension migration.

| Role | Preserved authority and boundaries |
| --- | --- |
| Eligible RPMS Administrator | Create/join approvals, pending review, audited group title/code/Adviser/PI corrections and existing RPMS progress decisions. All eligible Admins use the same rules; no hierarchy was added. |
| Research Adviser | Assigned groups and Students only; existing invitations, document review, feedback and formal submissions. No official project identity, protocol assignment or RPMS stage override. |
| Student | Own verified project and permitted shared versions, with individual uploader/formal-submitter attribution. Incomplete invitations, arbitrary titles and forged IDs/codes confer no membership. |

Mutations retain authenticated role checks, CSRF/origin and session-generation checks, live account/legal eligibility, account-recovery fences, scoped record lookup, transactional audits and optimistic revisions. Database uniqueness is the final guard for Student membership and official codes. Sensitive assignment intent is returned only through the existing authorized account/record APIs.

Add IERB Entry routes new creation to existing Student Records once the extension is installed; its API refuses a new-record bypass. Existing historical records remain readable, with no automatic membership inferred from legacy labels/titles and no automatic numeric-to-semantic stage conversion.

No project deletion feature was added. Shared documents, versions, workflow events, institutional decisions and reports are retained. Ordinary canonical members can be archived individually with a reason, explicit confirmation and a current project revision. The archive is recorded as a dated canonical event; the account loses access while its verified membership and project history remain. Subsequent group progression still updates the retained history, skips delivery to inactive accounts, and checks every active member. Only an archive matching that official event can receive this treatment: an unreviewed archive still blocks progression. Restoring an account removes the archive exception and reinstates the original password/legal/account eligibility checks. Submission holders and PIs require a reviewed transition before archival; assigned canonical Advisers must first be reassigned group-wide. Noncanonical archive/restore controls remain. Retained assignment evidence also requires institutional review before permanent account deletion. This is an explicit retention dependency, not a destructive cleanup path.

## Validation evidence and commands

All results below belong to fresh, isolated runs of this update. Earlier passing totals from the original release are not counted as evidence. Actual environment: PHP **8.2.12**, MariaDB **12.3.2**, Node **25.0.0**, Chromium via the existing local Playwright Core installation. Fixture connections verify the synthetic database name, loopback port and dedicated datadir. The copied application disables real mail/cURL providers and has no Gmail credentials or deployed configuration.

| Check | Outcome | Assertions/files | Evidence |
| --- | --- | ---: | --- |
| Final focused onboarding, authorization, concurrency, shared-document workflow and browser acceptance | PASS | 266 assertions | [Results](PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE/test-results-documents.json) |
| Browser subset, already included in the focused total | PASS | 29 assertions | [Browser checks](PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE/onboarding/browser-results.json) |
| Full applicable regression, run after final focused acceptance | PASS | 2,771 assertions + 8 migration checks | [Consolidated receipts](PRISM_ONBOARDING_REGRESSION_RELEASE/regression-summary.json) |
| PHP and JavaScript syntax for every changed implementation/test source | PASS | 45 files | [Syntax results](PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE/syntax.json) |
| Existing public-runtime builder isolation and publication guards | PASS | 15 assertions | [Publication checks](PRISM_ONBOARDING_FINAL_EVIDENCE/publication-tests.json) |

The full counted regression total is **2,556 freshly rerun baseline assertions + 208 assertions with the extension installed + 4 independent rollback-race assertions + 3 historical-record guard assertions = 2,771**. Eight extension-migration precondition/structure checks also passed and are listed separately; they are not added again to that assertion total. The 29 browser assertions are included in the focused 266, not added a second time.

| Suite | Result | Assertions | Receipt |
| --- | --- | ---: | --- |
| 01-schema11 | PASS | 429 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/01-schema11/console.txt) |
| 02-foundation | PASS | 99 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/02-foundation/console.txt) |
| 03-schema12-all | PASS | 860 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/03-schema12-all/console.txt) |
| 04-reports | PASS | 144 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/04-reports/console.txt) |
| 05-monitor | PASS | 245 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/05-monitor/console.txt) |
| 06-schema15-ui | PASS | 130 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/06-schema15-ui/console.txt) |
| 07-schema15-7r | PASS | 114 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/07-schema15-7r/console.txt) |
| 08-schema15-closure | PASS | 94 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/08-schema15-closure/console.txt) |
| 09-schema11-rerun | PASS | 310 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/09-schema11-rerun/console.txt) |
| 10-schema11-ui | PASS | 131 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/10-schema11-ui/console.txt) |
| 12-extended-schema15-7r | PASS | 114 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/12-extended-schema15-7r/console.txt) |
| 13-extended-schema15-closure | PASS | 94 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/13-extended-schema15-closure/console.txt) |
| 11b-migration-approval-race | PASS | 4 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/11b-migration-approval-race/console.txt) |
| 13b-historical-guards | PASS | 3 | [Console](PRISM_ONBOARDING_REGRESSION_RELEASE/regression/13b-historical-guards/console.txt) |
| **Full counted total** | **PASS** | **2,771** | |

The empty extension rollback, schema cleanup and exact schema-11 return also succeeded. Both final fixture scripts stopped their own MariaDB process. Earlier manually started disposable fixture servers were stopped through their guarded shutdown command; their databases and evidence were retained.

The focused suite exercises real authenticated HTTP requests and independent PDO connections. It covers Add Student and both invitation modes, deferred activation, password/legal/Adviser gates, explicit duplicate-title handling, identical-title group selection, accented and long titles, program/year matching with different year levels, pending corrections/cancellation, token expiry/resend, three equally authorized Admins, revoked staff authority, mandatory-audit rollback/retry, duplicate memberships, protocol uniqueness/history, scoped search and unchanged historical PDF bytes. It runs all thirteen initial requirements, Adviser formal submissions and RPMS acceptances, both conditional-Amendment cases, Form 33, non-PI Final Report completion and duplicate refusal. A member joins while evidence exists, races a duplicate submission, and is later archived with retained history; remaining members complete the project without resetting evidence. Incomplete and unrelated accounts are denied shared evidence.

Browser checks use **1440px and 390px**, light and dark themes, and the existing forms. They exercise real Add/Invite saves, required title behavior, inherited read-only fields, keyboard focus, Cancel restoration and page overflow. The separate screenshot deliverable was waived at the user's request. Captures already produced by the automated harness are retained with the other local evidence.

Actual final commands from this worktree (PowerShell):

```powershell
$bin = 'C:/Users/khirz/AppData/Local/Temp/claude/c--PRISM-Workspaces-prism-v13-release-clean/5edee734-cbba-4939-817e-6bcc925fb9ca/scratchpad/mdb1232/mariadb-12.3.2-winx64/bin'
& maintenance/onboarding-tests/run-focused.ps1 -MariaDbBin $bin `
  -ReviewName PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE -DbPort 13937 -AppPort 14937
& maintenance/onboarding-tests/run-regression.ps1 -MariaDbBin $bin `
  -ReviewName PRISM_ONBOARDING_REGRESSION_RELEASE -DbPort 13947 -AppPort 14947
node maintenance/onboarding-tests/source-check.cjs verify PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE/source-sha256.json
git diff --check
git diff --cached --name-only
git rev-parse HEAD
```

The scripts refuse an existing fixture datadir, perform the original guarded foundation migrations only inside a new synthetic database, preserve all results, and stop their own database process. Applying the schema-15 fixture definition here does not repeat the production migration. Full regression suite/flag combinations are recorded in [regression.cjs](maintenance/onboarding-tests/regression.cjs); no original assertions were removed or skipped.

The publication test initially attempted to create its temporary fixture beside the worktree, outside the writable root. It was rerun successfully with its test `ROOT` redirected under `PRISM_ONBOARDING_FINAL_EVIDENCE/publication-fixture/source` using Python `runpy`; this changed only the test's temporary location:

```powershell
@'
import pathlib, runpy
ns = runpy.run_path('maintenance/release/build-public-runtime-tests.py')
fixture = pathlib.Path.cwd() / 'PRISM_ONBOARDING_FINAL_EVIDENCE/publication-fixture'
fixture.mkdir(exist_ok=True)
ns['main'].__globals__['ROOT'] = fixture / 'source'
ns['main']('PRISM_ONBOARDING_FINAL_EVIDENCE/publication-tests.json')
'@ | python -
```

No runtime package was exported or staged. The sorted runtime manifest contains 273 approved runtime paths, including the four new runtime module/schema/asset paths. Release-commit allowlist pins remain unchanged.

An earlier full-regression attempt recorded one IERB modal initialization failure (`PrismAcademicFields is not defined`) at 390px/dark after 853 assertions. The error, page state and logs are retained in [the failed attempt](PRISM_ONBOARDING_REGRESSION_VERIFIED/regression/03-schema12-all/ierb-entry-failure-390-dark.json). Its root cause was not established. Request-failure diagnostics were added without weakening assertions; a serial rerun passed all 860 checks and the rest of that 2,771-assertion regression. The final fresh run passed all 860 combined checks on its first attempt and completed the entire 2,771-assertion chain. Its receipt above records that later outcome separately. Earlier failed development attempts and evidence were preserved.

[Exact changed-file inventory](PRISM_V14_ONBOARDING_CHANGED_FILES.md) lists all **56** implementation, schema, test and review files. [SHA-256 source manifest](PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE/source-sha256.json) covers 313 runtime, schema and test files; final verification passed for all 313 files, confirming that the validated sources did not change. Generated fixture applications, database files and dependencies remain ignored; readable test receipts remain local and untracked.

## Activation dependencies and limits

1. Independently review the two-table extension, its pinned DDL, retention effects and any existing-code import needs before authorizing migration outside disposable fixtures.
2. Preserve the existing V14 gate. Production activation remains a separate, explicit owner decision; incomplete setup stays pending.
3. Create/complete eligible Adviser accounts and complete Student password, legal and profile prerequisites. A changed/revoked approving Admin or inconsistent assignment requires an eligible Admin to review the pending record.
4. Moving/removing existing verified membership, transferring an evidence holder, archiving a holder or PI, retiring an assigned canonical Adviser, merging historical evidence and exceptional additions to completed projects remain reviewed institutional transitions. The ordinary onboarding path does not perform these operations.
5. Protocol format support is deliberately bounded as described above. Review any official format requiring additional characters. Research titles longer than the existing 255-character Student compatibility field are rejected clearly, not truncated.
6. Retain the conservative institution-wide membership fence. Production-volume throughput, actual Hostinger runtime behavior and live email delivery are outside these isolated local tests.
7. Review the updated public-runtime manifest and re-pin the release commit allowlist only after explicit commit authorization. Existing release gates have not been bypassed, and no real runtime export, index staging, commit, push, merge, upload or deployment is part of this task.

No production database, `rpmsceu.online`, live `config.local.php`, production activation setting, Administrator account, historical cleanup or backup was modified. Synthetic test evidence and previous validation packages are retained.

## Final disposition

**PASS WITH CONDITIONS** for the local implementation. Focused acceptance, full applicable regression, extension-migration checks, publication guards, syntax checks and source verification all passed. The conditions are the independent schema-extension/import review, authorized V14 activation, eligible account prerequisites and exceptional institutional transitions described above. The earlier intermittent browser initialization failure remains disclosed; it did not recur in the final clean combined run. Actual hosting performance and live provider delivery remain untested.

The branch and HEAD are unchanged. The Git index is empty. All implementation and evidence remain local, unstaged, uncommitted, unpushed and undeployed. No merge, upload or live configuration change was made. Work stops at implementation, local testing and this report.
