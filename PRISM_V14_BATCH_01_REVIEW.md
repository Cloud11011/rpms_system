# PRISM V14 Batch 1 review

Date: 2026-10-27 (Asia/Manila). Repository: `Cloud11011/rpms_system`.

## Result and activation boundary

The four-stage foundation uses immutable semantic identifiers, an explicit event transition contract, separate correction cases and decision history, optimistic revisions, durable request idempotency and transactional audit writes. Existing records retain their legacy values and current functionality. This batch exposes authenticated read-only contract metadata at `ierb_api.php?action=v14_contract`.

V14 HTTP activation is unavailable. Foundation enrollment and correction opening require explicit CLI opt-in (`PRISM_IERB_V14_FOUNDATION=1`), schema 12, and a live eligible RPMS Administrator. These entry points are intended for integration verification during Batch 1. Do not enroll operational records before Batch 2 connects authoritative document evidence and the V14 user interface. The document event hook rejects transitions until that integration is implemented.

| Identifier | Stage | Progress |
| --- | --- | --- |
| `initial_submission` | Initial Submission | 0% |
| `resubmission_amendment` | Resubmission / Amendment | Interrupted stage percentage |
| `approval_data_gathering` | Approval of Data Gathering | 50% |
| `ierb_completed` | IERB Completed | 100% |

Normal progression is Initial Submission → Approval of Data Gathering → IERB Completed. Initial Submission and Approval of Data Gathering can each open a correction case. Resolution returns to the stored interrupted context. Completed is terminal. A correction contributes no additional progress milestone.

The only completion event in the contract is `final_report_submitted`, for Form 33-0B. Its declared requirements are authorization, idempotency, audit evidence and atomicity with successful formal submission. It requires no additional RPMS acceptance, separate confirmation or manual completion. The existing Adviser approval → automatic formal RPMS submission behavior remains intact. The live event integration is explicitly deferred to Batch 2.

## Git preparation and release identity

- Fetched origin and verified source branch `feature/prism-v13-group-stage-advancement-v1` and remote at `fa53d324cd2e2ec154c8ce58f73a69c38b4596f1`.
- Target branch was absent after fetch; created `feature/prism-v14-major-revisions` from the verified source.
- Original checkout had no tracked modifications and contained unrelated untracked work. Preserved it and used the initially clean `.batch01-worktree` worktree within the authorized workspace.
- No reset, discard, main merge/rebase, force push or deployment.
- Commit message: `feat(prism-v14): establish revised IERB stage architecture`.
- Pushed branch: `origin/feature/prism-v14-major-revisions`.
- Commit SHA: the Git commit containing this report; resolve with `git log -1 --format=%H -- PRISM_V14_BATCH_01_REVIEW.md`. The exact local and remote SHA receipt is generated after commit at [push-verification.json](PRISM_V14_BATCH_01_EVIDENCE/push-verification.json). Keeping this generated receipt outside its own commit avoids a circular commit hash.

## Changed files

| Files | Purpose |
| --- | --- |
| `includes/ierb_stages.php` | Immutable identifiers, labels, normal path, allowed events, correction resume rules and meaningful progress. |
| `includes/ierb_v14_workflow.php` | Gated enrollment/correction services, live authorization, authoritative affected-version checks, Student/workflow locks, schema mutex, request replay, revisions, events and atomic audits. |
| `includes/schema_v12.php`, `includes/ierb_schema_v12.json` | Additive DDL, pinned extension definitions, exact preserved v11 verification, restart-safe migration and guarded rollback. |
| `maintenance/migrate-ierb-v14.php` | Explicit operator CLI runner; dry run by default; no application configuration bootstrap. |
| `config.php`, `includes/stage_configuration.php` | Recognize the separately authorized schema 12 extension and retain legacy guidance support. |
| `ierb_api.php` | Authenticated read-only V14 transition contract. |
| `includes/ierb_progress.php`, `includes/ierb_group_advancement.php` | Prevent legacy decision paths from modifying an explicitly enrolled V14 record. |
| `includes/account_lifecycle_schema.php`, `includes/account_retention.php` | Compose exact schema verification with the extension, bind verification evidence to its contract, and preserve enrolled workflow histories before purge file planning. |
| `maintenance/ierb-group-tests/run.cjs` | Run the existing suite against either v11 or v12 and verify contract access/HTTP mutation gating for all three roles. |
| `maintenance/ierb-v14-tests/{README.md,cases.php,run.cjs,generate-contract.php}` | Disposable foundation checks, independent-connection races, reproduction instructions and guarded contract generation helper. |
| `PRISM_V14_BATCH_01_EVIDENCE/{git-preparation.json,php-syntax.json,foundation-tests.json,legacy-v11-initial.json,legacy-v12.json}` | Preparation and acceptance evidence only. |
| `PRISM_V14_BATCH_01_REVIEW.md` | This review. |

## Database migration and reversal

Schema **12** is the database extension version; V14 is the application workflow version. The prerequisite is the exact reviewed schema v11. Ordinary HTTP requests apply no extension DDL and enroll no records.

New InnoDB tables:

- `ierb_workflows`: unique Student association, semantic stage, revision, preserved legacy-stage snapshot, enrollment evidence, responsible Admin and timestamps.
- `ierb_correction_cases`: independent repeatable cases, reason, affected version snapshots, interrupted review context, explicit Amendment Form policy, opening/resolution authorship and timestamps, retained resolution evidence. A generated unique key permits only one open case per workflow.
- `ierb_workflow_events`: append-only application history, unique workflow/revision and workflow/request keys, request digest, original response, before/after stages, case reference, actor, reason, evidence and timestamp.

Foreign keys use RESTRICT to preserve referenced workflow history and authorship. No existing table is altered; only `schema_meta.schema_version` moves from 11 to 12. Enrollment, correction opening and schema maintenance share the `prism_migrate` advisory mutex. Decisions also lock the Student before the workflow and revalidate live account/legal eligibility. An audit failure rolls back the case, revision and event together.

Operator runner: `php maintenance/migrate-ierb-v14.php [dry-run|apply|rollback]`. It requires all of:

- `PRISM_ALLOW_SCHEMA_V12_MIGRATION=1`;
- `PRISM_SCHEMA_V12_EXPECT_DB` exactly matching the selected database;
- Explicit `PRISM_MIGRATION_DB_DSN`, `PRISM_MIGRATION_DB_USER` and optional `PRISM_MIGRATION_DB_PASSWORD` environment values;
- Foreign-key enforcement, no open transaction, canonical metadata and the schema maintenance mutex.

Dry run performs verification without DDL. Apply can resume canonical partial DDL while the base version remains 11. A published schema 12 with a missing table is rejected as drift. Rollback drops only the new tables in reverse dependency order and restores the exact v11 inventory, **only when all new tables are empty**. Retained V14 records block rollback. A safe rollback after activation therefore means disabling the new workflow while retaining its data; destructive down-migration is refused.

Only the datadir-verified synthetic MariaDB fixture was migrated. No production database was read or migrated.

## Legacy compatibility assessment

Inspected the stage helpers/configuration, progress and group endpoints, Student and document APIs, dashboard category counts, report filters/snapshots, history reads, exports and JavaScript stage lists. They depend on legacy keys and cannot safely be relabeled by stage number. They continue using `STAGE_SEQUENCE`, configured legacy labels and existing progress percentages while V14 is gated. V14 computes its own progress from its predefined main path.

Every historical stage, label, document category, version, review decision and history row remains unchanged. The migration creates no legacy-to-V14 mappings. Explicit future enrollment requires a selected semantic review context and documentary evidence, retaining the original legacy value as a snapshot. New timelines remain separate from `ierb_history`.

Existing purge deployment verification must be reissued after adopting the changed verification contract. Archive remains available. An enrolled V14 Student is retained through Archive; its workflow history blocks permanent deletion before files are staged for removal. Referenced Admin authorship is also protected by foreign keys.

## Acceptance results

| Check | Result | Evidence |
| --- | --- | --- |
| PHP syntax | 130/130 files pass, including all tracked PHP and new PHP files | `php-syntax.json` |
| JavaScript syntax | Both modified/new runners pass `node --check` | Execution review |
| V14 foundation | 99 assertions pass | `foundation-tests.json` |
| Existing full v11 suite | 304 assertions pass | `legacy-v11-initial.json` |
| Existing full suite on schema 12, including added contract/gating checks | 310 assertions pass | `legacy-v12.json` |
| Working diff whitespace | `git diff --check` passes | Execution review |
| Staged whitespace | `git diff --cached --check` passes before commit | Execution review |

Foundation coverage includes every allowed/invalid stage-event pair, rejection of implicit legacy mapping, both correction resume contexts, unchanged correction progress, explicit enrollment, Admin/Adviser/Student authorization, live inactive-Admin rejection, affected document ownership and current-version checks, optional/mandatory per-case Amendment metadata, repeated correction identities/timestamps, nested/stale requests, request digest collisions, original-result replay after subsequent decisions, and atomic audit-failure rollback.

Migration coverage includes authorization/database binding, transaction/FK prerequisites, no-write dry runs, exact legacy preservation, apply replay, schema drift, a missing table after publication, empty rollback, interrupted apply/rollback recovery, retained-data refusal and concurrent schema mutex use. Independent PHP processes verify that simultaneous correction requests produce one decision and one stale conflict, then replay the committed result without duplicate history.

Repeated-case persistence tests seed resolved facts inside the disposable fixture to model the future document evidence adapter. The production resolution hook remains gated pending Batch 2. The test teardown removes only fixture data and restores the exact v11 schema.

Existing regression coverage includes individual/group authorization, complete membership and concurrent updates, transaction fault injection, histories/notices, stage guidance, Student upload, automatic Adviser submission, return/revision chains, local/AI summaries, AI report fallback, document pagination and bulk deletion, and responsive light/dark Chromium checks at 390, 768, 1024, 1440 and 1920 pixels.

All acceptance suites passed. Negative-path cases exercise expected authorization, validation, stale-state and persistence failures. Runtime databases, copied apps, sessions, provider stubs, screenshots, dependencies and unrelated original-checkout files are excluded from the commit.

## Limitations and Batch 2 boundary

- V14 activation, document-driven advancement/resolution, operational V14 displays, and legacy enrollment rollout remain gated. Existing dashboards, reports and exports retain their legacy interpretation until the new workflow is connected.
- Stage-specific mandatory requirements, multiple-upload type assignment, authoritative conditional Amendment verification, Form 33 acceptance and atomic Form 33-0B formal-submission completion are reserved for Batch 2.
- Schema 12 requires a verified v11 checkpoint. Its pinned definitions were tested with MariaDB 10.4.32 and PHP 8.2.12; other database server metadata formats require review before migration.
- The foundation creates one V14 workflow per Student, consistent with current Student-owned workflow storage. Multiple research projects per Student require a separate data model decision.
- No notification delivery, deployment, production migration or Admin stage builder was introduced.

Stop after the verified branch push. Batch 2 requires the user's explicit next instruction.
