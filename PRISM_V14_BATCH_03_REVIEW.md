# PRISM V14 — Batch 3 review

Implementation began 2026-10-27; final review 2026-10-28 (Asia/Manila). Repository: `Cloud11011/rpms_system`.

## Overall verdict: PASS

Batch 3 implements canonical research projects, explicit authoritative membership, project-level requirement completion, RPMS-confirmed normal group advancement, atomic automatic Final Report completion, and the two-step cumulative Student upload form. All final acceptance suites pass. Exploratory failures are retained separately in `development-failures.json`; they are not acceptance passes.

Production activation, production migration and deployment: **not performed**. General enrollment/reconciliation remains explicitly gated CLI administration. Batch 4 is not started.

## Release identity and Git preparation

- Required and verified starting SHA: `6e1ee3058f038a2783c00ebcbbd9e863dd03d260`.
- Fetched origin and verified the same starting SHA on `origin/feature/prism-v14-major-revisions`.
- Working branch: `feature/prism-v14-major-revisions`, in the initially clean `.batch03-worktree`.
- Existing original-checkout and Batch 2 untracked files were preserved. The inactive Batch 2 checkout was detached at the verified starting SHA before advancing the branch, preserving its contents and evidence.
- Read both prior review reports. No main merge/rebase, reset, force push or Hostinger deployment.
- Authorized commit message: `feat(prism-v14): integrate research groups and refine document uploads`.
- Ending SHA: the commit containing this report (`git log -1 --format=%H -- PRISM_V14_BATCH_03_REVIEW.md`). The exact ending local and remote SHAs and their equality are recorded after push in [push-verification.json](PRISM_V14_BATCH_03_EVIDENCE/push-verification.json). This post-push receipt remains outside its own commit to avoid a circular commit hash; the delivery message gives the exact SHA.
- The push is the ordinary, non-forced push to `origin feature/prism-v14-major-revisions`. Final remote verification is required before delivery.

## Canonical identity and membership

Schema 13 adds five InnoDB tables without altering the reviewed v11/v12 tables:

| Table | Responsibility |
| --- | --- |
| `research_projects` | Immutable project primary key, title-derived unique public identifier, assigned Adviser, designated Student submission holder, one authoritative existing V14 workflow, revision and exact completion document. |
| `research_project_identifiers` | Reserved identifiers and aliases; previous canonical identifiers cannot be reassigned to another project. |
| `research_project_members` | Explicit unique Student membership, retained legacy identity/academic snapshot and synchronized semantic progress/revision. |
| `research_project_events` | Append-only application history for membership, title/Adviser changes and references to authoritative workflow events. |
| `research_project_member_history` | Separate durable progress observations for each member, referencing the shared project event. |

`PRISM: RPMS Workload Assistant` produces `prism-rpms-workload-assistant`; a separate project with the same title receives a collision suffix. Renaming preserves the internal key, all old aliases, documents, protocol codes, academic fields and history. Explicit Admin Adviser reassignment updates the project and all member assignments atomically and retains prior assignments.

No title similarity grants membership or file access. A live eligible RPMS Administrator supplies an exact reviewed Student mapping and row hashes. The service rejects omitted associated legacy members, changed snapshots, conflicting Adviser assignments, existing canonical membership and independently enrolled partner workflows. It never silently merges workflows or duplicates acceptance evidence. The operator must reconcile ambiguous records explicitly. The initial mapping retains previous generated identifiers and complete Student identity/academic snapshots.

Existing enrolled workflows remain intact. A completed holder can be reconciled only with an explicit institutional historical-completion reference and revalidation of the unique original Final Report event, initial accepted evidence, Form 33 and the original current Final Report. No new submission/completion event is manufactured.

`maintenance/reconcile-research-project.php` supplies gated `preview`, `apply`, `rename` and `reassign-adviser` operations. See [operator and test instructions](maintenance/ierb-project-tests/README.md). Ordinary Student-record edits cannot bypass shared membership/title/Adviser administration.

## Project requirements, advancement and corrections

The designated holder retains document ownership and the single existing version-bound RPMS acceptance ledger. Partners read shared status through their own authorized Student endpoint. The 13 Initial Submission requirements are required once per project. Partners do not acquire another 13-upload obligation or another acceptance ledger. Raw holder document IDs are omitted from partner Student checklists; direct file downloads and version histories still require existing ownership/assignment permissions. Duplicate-title projects remain isolated.

Accepting all 13 current initial requirements establishes eligibility and **does not automatically advance**. The existing Admin Group Advancement route and interface retain preview, a reason, explicit confirmation, session/generation-bound expiring tokens, token consumption, stale-preview rejection, membership fencing and all-or-none transactions. Preview lists every missing, pending, rejected, returned, ambiguous, unaccepted or otherwise invalid requirement and unresolved correction. Fingerprints cover current members/accounts, project identity/revision, workflow/case and accepted evidence. Competing independently previewed confirmations commit once; the stale competitor is rejected.

Normal advancement changes Initial Submission to Approval of Data Gathering only. Manual completion is unavailable. Semantic member progress and individual histories update together; legacy numeric stages retain their historical meaning. RPMS rows prefer canonical stage/progress and disable the guarded legacy decision controls for enrolled projects. Student and RPMS stage journeys use the four semantic stages; conditional correction is not counted as a completed forward milestone. Authorized histories combine retained legacy observations with the member's public canonical timeline.

Correction cases remain holder-owned and bound to canonical identity through the project's immutable workflow association. The existing interrupted stage, reason, affected versions, explicit per-case Amendment decision, current-successor chain checks, case-specific acceptance, resolution evidence and repeatable history are preserved. One unresolved case is permitted. Opening/resolution synchronizes member state and histories; Initial corrections remain at 0%, Gathering corrections at 50%, and resolution returns to the interrupted review context. No ordinary progress writer can independently advance a canonical member.

## Atomic automatic Final Report completion

The existing assigned-Adviser approval → automatic formal RPMS submission process remains intact. The designated holder's Form 33-0B needs no additional RPMS acceptance or manual completion action. Its submission transaction verifies canonical identity, holder authority, every member's current eligibility/assignment, all accepted current initial evidence, institutional Form 33 acceptance and absence of an unresolved correction.

The same transaction writes the formal submission stamp/audit/history, authoritative completion event, one project completion reference/timestamp, every member's semantic progress and individual history/audit, and notification intents. Failure inserting the second member's history rolls back the entire submission and completion, including all notices. Each member and the assigned Adviser receive accurate project progress notices; existing RPMS formal-submission notifications remain intact. Delivery occurs after commit. Provider failure does not undo the durable result.

Durable request replay and the existing approved-document retry path prevent duplicate completion. Concurrent approvals create one workflow completion and one project completion, one member completion observation per member, no extra Final Report acceptance, and no copied document files or acceptance ledgers. An unrelated project sharing the original title remains uncompleted. A non-holder's separately owned Final Report cannot formally complete the project.

Evidence: [group advancement](PRISM_V14_BATCH_03_EVIDENCE/group-advancement.json), [group completion](PRISM_V14_BATCH_03_EVIDENCE/group-completion.json), and [project tests](PRISM_V14_BATCH_03_EVIDENCE/project-tests.json).

## Student upload form and version retention

| Before | After |
| --- | --- |
| Step 1 global document-type selector; Step 2 file upload; Step 3 notes. | Step 1 Upload your documents; Step 2 optional reviewer notes. |
| Readiness/submission/reset depended on the global selector. | Every queued file has its own required category; no obsolete selector dependency remains. |
| A later browse/drop replaced the previous `FileList`. | Maintained browser state appends repeated browsing, multi-selection and drops. |
| A failure cleared the selected files. | Confirmed successes leave the queue; failed files and assignments remain, with useful recovery feedback. |

Queue entries have random session identities. File metadata is used only as a transient duplicate-selection hint, never a persistent document identifier. Removing one entry retains every other file and type. Filenames/sizes/types and the complete upload count remain visible and accessible. No arbitrary queue-count limit was added. Existing server count/type-array and file validations remain authoritative.

The payload sends every queued `document[]` with its matching `documentTypes[]` in order. Missing types or invalid client file validation block readiness. A malformed/unknown result retains entries and requires explicit reconciliation against My Documents before retry; a partial response removes known successes so they are not accidentally resent. Full success or an explicit revision/removal action resets the appropriate queue. Revision mode accepts one file, locks the original category (including historical labels), and retains the original chain.

The backend versioning model is unchanged: `version_no`, `is_current`, `supersedes_id`, ownership, review history, files and audits remain intact. Repeated editable categories create successors; submitted/locked or accepted evidence remains protected. Real browser uploads verify the version chain, authorized historical listing and byte-for-byte download of the original file before and after later uploads. Return/revision/correction checks remain intact. The implementation does not delete saved documents to fix selection.

## Migration, rollback and compatibility

`maintenance/migrate-research-projects.php` is an explicit-DSN CLI runner, dry-run by default. It requires the schema-13 opt-in, exact selected database binding, FK enforcement, no open transaction and the existing schema mutex. The pinned additive contract composes exact v11/v12 verification with all project definitions. Migration itself infers zero members, enrolls zero Students and maps no legacy stages.

Dry runs, idempotent apply, interrupted apply/empty rollback, wrong binding, disabled FK checks, drift and missing tables were tested. Rollback refuses any retained project data. After use, disable activation and retain the extension instead of destroying history. Membership/workflow evidence also prevents permanent account/document deletion. Existing v11/v12 guidance, account security, legal acceptance, lifecycle, notifications and workflows retain their regression coverage.

Only the actual-datadir-verified disposable MariaDB fixture was migrated. It finished at exact schema 11 and was stopped; [teardown receipt](PRISM_V14_BATCH_03_EVIDENCE/teardown.json). No production data was read, migrated or deployed.

## Acceptance evidence

Final critical failures: **0**. [Acceptance summary](PRISM_V14_BATCH_03_EVIDENCE/acceptance-summary.json).

| Suite | Final result |
| --- | --- |
| PHP syntax | 140/140 files PASS. |
| Changed/new JavaScript syntax | 9/9 scripts PASS. |
| Existing full v11 regression | 310 assertions PASS. |
| Reviewed foundation/migration suite | 99 assertions PASS. |
| Full v12 legacy + Batch 2 + canonical project integration | 724 assertions PASS. |
| Explicit singleton compatibility/document/UI run | 330 assertions PASS. |
| Newly added project-specific coverage | 96 assertions PASS, including the 14 migration cases. |
| Newly added cumulative-upload coverage | 96 assertions PASS. |
| Diff and staged whitespace | `git diff --check` and `git diff --cached --check` pass before the authorized commit. |

Counts overlap where focused modules are included in the full suites; they are not summed as independent tests. Coverage includes cross-group authorization, exact membership mapping, duplicate titles, alias/title/Adviser history, missing/pending/rejected/current evidence, stale previews, independent-connection operations, audit/history fault rollback, both correction contexts, repeated optional/mandatory Amendments, version retention, locked evidence, Final Report retries and provider failure. Upload coverage includes every requested selection/removal/type/partial-recovery behavior, actual multipart alignment, staff compatibility, explicit revision and keyboard access.

Responsive Chromium captures cover the Student queue at 390/768/1440 in light/dark, the partner's completed checklist at 390/1440 in light/dark, RPMS completion display and partial-upload recovery. Existing responsive legacy checks retain their 390/768/1024/1440/1920 matrix.

- [Mobile upload, light](PRISM_V14_BATCH_03_EVIDENCE/uploads-390-light.png) · [mobile upload, dark](PRISM_V14_BATCH_03_EVIDENCE/uploads-390-dark.png).
- [Tablet upload](PRISM_V14_BATCH_03_EVIDENCE/uploads-768-light.png) · [desktop upload](PRISM_V14_BATCH_03_EVIDENCE/uploads-1440-light.png).
- [Partial recovery](PRISM_V14_BATCH_03_EVIDENCE/uploads-partial-recovery.png) · [completed member, mobile](PRISM_V14_BATCH_03_EVIDENCE/member-completed-390-light.png) · [RPMS completion](PRISM_V14_BATCH_03_EVIDENCE/admin-member-completed.png).

## Changed-file inventory

The exact list, including accepted evidence artifacts, is [changed-files.json](PRISM_V14_BATCH_03_EVIDENCE/changed-files.json). Working-source hashes are [source-snapshot.json](PRISM_V14_BATCH_03_EVIDENCE/source-snapshot.json). Existing Batch 1/2 reports, pinned schema-12 contract and evidence files are preserved.

Implementation, migration, test and reproduction files:

- `assets/css/prism-ui.css`
- `assets/js/document-upload.js`
- `assets/js/ierb-requirements.js`
- `assets/js/ierbprog.js`
- `assets/js/prism-ui.js`
- `assets/js/role-portal.js`
- `config.php`
- `documents_api.php`
- `ierb_api.php`
- `includes/account_lifecycle_schema.php`
- `includes/account_retention.php`
- `includes/current_workflow_eligibility.php`
- `includes/document_workflow.php`
- `includes/ierb_group_advancement.php`
- `includes/ierb_v14_requirements.php`
- `includes/ierb_v14_workflow.php`
- `includes/research_groups.php`
- `includes/research_project_advancement.php`
- `includes/research_projects.php`
- `includes/research_schema_v13.json`
- `includes/schema_v12.php`
- `includes/schema_v13.php`
- `includes/stage_configuration.php`
- `maintenance/ierb-project-tests/README.md`
- `maintenance/ierb-project-tests/fixture.php`
- `maintenance/ierb-project-tests/generate-contract.php`
- `maintenance/ierb-project-tests/migration-cases.php`
- `maintenance/ierb-project-tests/projects.cjs`
- `maintenance/ierb-project-tests/uploads.cjs`
- `maintenance/ierb-v14-tests/documents-fixture.php`
- `maintenance/ierb-v14-tests/documents.cjs`
- `maintenance/ierb-v14-tests/run.cjs`
- `maintenance/migrate-research-projects.php`
- `maintenance/reconcile-research-project.php`
- `role_portal.php`
- `students_api.php`

Plus this review and `PRISM_V14_BATCH_03_EVIDENCE/*.json` / selected responsive PNG captures. Runtime databases, copied applications, dependencies, sessions, provider stubs, raw logs, edit helpers and unrelated user work are excluded from staging.

## Known limitations and activation boundary

- Operational enrollment/mapping/title/Adviser administration remains a reviewed CLI operation; no general HTTP activation route or stage builder was added. Schema 13 requires explicit operator application after this review.
- Each Student has one canonical project association, matching the existing one-workflow-per-Student model. Moving a holder's evidence, removing historical membership, or merging independently enrolled workflows requires separate institutional reconciliation; no automatic destructive merge is provided.
- The conservative Student PRIMARY-range fence serializes schema-13 project decisions with existing legacy writers. Evaluate throughput before broad operational activation.
- Exact DDL contracts were verified on MariaDB 10.4.32 with PHP 8.2.12. Other server metadata formats require review; contracts must not be regenerated to conceal drift. Responsive automation uses Chromium, not an unclaimed cross-engine certification.
- Legacy aggregate dashboards, exports and numeric-stage filters retain their reviewed legacy contract. Canonical group previews, RPMS member rows, Student summaries/checklists and member timelines use the semantic project workflow; no numeric similarity enrollment/mapping is introduced.

Commit/push verification is the final release step. Stop after the verified ordinary branch push. No Batch 4 work or deployment is authorized by this delivery.
