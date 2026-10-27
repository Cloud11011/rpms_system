# Batch 3 verification and project administration

Use the disposable MariaDB fixture documented in `../ierb-group-tests/README.md` and the reviewed foundation suite in `../ierb-v14-tests/README.md`. Every fixture helper verifies the actual MariaDB datadir before reading or changing records. These tests never read application `config.local.php`, a deployed database, or real email/AI providers.

Run the full acceptance sequence from the repository root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File maintenance/ierb-group-tests/setup.ps1
node maintenance/ierb-group-tests/run.cjs
$env:PRISM_TEST_EVIDENCE_DIR=(Join-Path (Get-Location) 'PRISM_V14_BATCH_03_EVIDENCE')
node maintenance/ierb-v14-tests/run.cjs
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php apply-for-regression
$env:PRISM_TEST_SCHEMA_VERSION='12'
$env:PRISM_TEST_V14_DOCUMENTS='1'
$env:PRISM_TEST_PROJECT_GROUP='1'
node maintenance/ierb-group-tests/run.cjs
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php rollback-empty
& C:/xampp/php/php.exe maintenance/ierb-group-tests/shutdown.php
```

Set `PRISM_TEST_PLAYWRIGHT` to an available absolute Playwright Core module path. Other existing PHP/Chrome overrides remain supported. For a focused integration run, set `PRISM_TEST_PHASE=documents`; remove it for full acceptance. Omit `PRISM_TEST_PROJECT_GROUP` to test an explicitly mapped singleton holder. Both modes retain the unrelated Student 5 isolation checks. The upload browser tests use that Student's editable supporting-document chain and real multipart requests; locked accepted holder documents remain protected.

The document suite applies schema 13 only in the guarded synthetic fixture, then removes its explicit fixture project records and returns to schema 12. Group mode adds migration dry-run/restart/drift/rollback checks, explicit membership mapping, collisions and aliases, title/Adviser administration, shared status with private-file isolation, stale previews, independent-connection group races, transaction faults, member correction histories and automatic completion. Existing Batch 2 tests exercise both correction contexts, repeated Amendment policies, revision rules and Final Report retries. `uploads.cjs` covers repeated browsing, multiple selection, drops, deletion, missing types, duplicate selection, content failures, unknown outcomes, recovery, retained version history, revision locking, keyboard access, and 390/768/1440 light/dark layouts.

`generate-contract.php` is only for the verified disposable fixture with schema 12 and no project tables. It captures the reviewed DDL's exact MariaDB definitions. Do not regenerate contracts to accept unexplained deployment drift. `fixture.php` and `migration-cases.php` are synthetic helpers, never deployment or application endpoints.

## Explicit operator tools (not executed against production by this batch)

Schema 13 is additive to the exact schema 12 contract. `maintenance/migrate-research-projects.php` defaults to a read-only dry run, uses only an explicit `PRISM_MIGRATION_DB_DSN` plus credentials, and requires both `PRISM_ALLOW_SCHEMA_V13_MIGRATION=1` and `PRISM_SCHEMA_V13_EXPECT_DB` matching the selected database. Apply and rollback are explicit CLI arguments. Migration maps no students, stages, titles, or memberships. Any retained project row blocks rollback; disable activation and retain the extension after enrollment. Apply and empty rollback are restart safe and use the existing `prism_migrate` mutex.

`maintenance/reconcile-research-project.php` is a separate local operator tool using the selected application's configuration. It requires `PRISM_RESEARCH_PROJECT_ADMIN=1`, exact `PRISM_PROJECT_EXPECT_DB`, the existing `PRISM_IERB_V14_FOUNDATION=1` gate, schema 13, and a live eligible RPMS Administrator. It accepts JSON on stdin:

- `preview`: `actorUserId`, `studentIds`. Returns exact current row hashes, prior labels/titles and Adviser IDs without writing membership.
- `apply`: `actorUserId`, `title`, `holderId`, `adviserId`, reviewed `mapping` entries (`studentId`, `snapshotSha256`), and a reason. Every associated legacy member must be explicitly accounted for. Titles never identify members. Multiple existing enrolled holders, stale snapshots, inconsistent assignments or existing canonical membership fail closed. Mapping a completed holder also requires `historicalCompletionReference` and revalidates the original unique Final Report event and all accepted evidence.
- `rename`: `actorUserId`, `projectId`, `expectedRevision`, `title`, reason. Reserves a unique title slug while retaining all old aliases and the immutable primary key.
- `reassign-adviser`: `actorUserId`, `projectId`, `expectedRevision`, `adviserId`, reason. Updates the explicit project's entire assignment in one transaction with retained prior assignments.

Enrollment and reconciliation remain separate, explicit operations. The designated holder retains original document ownership and the only requirement ledger. Other members read their own scoped project checklist and progress; membership grants no access to another Student's files or version history. Ordinary record edits cannot bypass project membership, title, or assignment administration. Membership removal, moving existing evidence to another holder, and merging independently enrolled workflows require separate institutional reconciliation; no automated merge or destructive repair is provided.

The existing Admin Group Advancement screen lists canonical projects and reports every requirement blocker. Thirteen current RPMS acceptances establish eligibility. The existing preview, session-generation token, reason, confirmation, stale checks and all-or-none transaction then advance Initial Submission to Approval of Data Gathering. Final Report formal submission completes the canonical project and every member automatically in the same document transaction. Legacy numeric Student stages are retained; V14 summaries and public member histories use semantic stages.

The schema 13 Student fence deliberately retains the conservative PRIMARY range locking strategy used by existing group advancement. This provides a single membership concurrency boundary with legacy writers. Evaluate throughput before broad operational activation. No production activation, migration, deployment, new general HTTP enrollment route or stage builder is part of this batch.
