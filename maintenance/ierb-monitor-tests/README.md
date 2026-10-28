# Batch 5 verification and academic-year migration

Use only disposable synthetic data. `maintenance/ierb-group-tests/setup.ps1` creates the loopback MariaDB fixture at port 13527 and refuses to overwrite an existing datadir. Every fixture helper verifies the actual server datadir before reading or writing records. The copied PHP app uses synthetic sessions and provider responses; it never loads production configuration or sends real email.

Prerequisites are the existing XAMPP PHP/MariaDB tools, Node, Chrome, Composer dependencies in `vendor`, and Playwright Core. Set `PRISM_TEST_PLAYWRIGHT` to its absolute module path. Set `PRISM_TEST_EVIDENCE_DIR` to a new output directory so prior evidence remains intact.

From the repository root:

```powershell
& maintenance/ierb-group-tests/setup.ps1
$env:PRISM_TEST_EVIDENCE_DIR=(Join-Path (Get-Location) 'PRISM_V14_BATCH_05_EVIDENCE')
node maintenance/ierb-group-tests/run.cjs
node maintenance/ierb-v14-tests/run.cjs
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php apply-for-regression
$env:PRISM_TEST_SCHEMA_VERSION='12'
$env:PRISM_TEST_V14_DOCUMENTS='1'
$env:PRISM_TEST_PROJECT_GROUP='1'
$env:PRISM_TEST_REPORT_SCHEMA14='1'
$env:PRISM_TEST_MONITOR_SCHEMA15='1'
node maintenance/ierb-group-tests/run.cjs
Remove-Item Env:PRISM_TEST_V14_DOCUMENTS,Env:PRISM_TEST_PROJECT_GROUP,Env:PRISM_TEST_REPORT_SCHEMA14,Env:PRISM_TEST_MONITOR_SCHEMA15
$env:PRISM_TEST_PHASE='documents'
$env:PRISM_TEST_BATCH4='1'
node maintenance/ierb-group-tests/run.cjs
Remove-Item Env:PRISM_TEST_BATCH4
$env:PRISM_TEST_BATCH5='1'
node maintenance/ierb-group-tests/run.cjs
Remove-Item Env:PRISM_TEST_BATCH5
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php rollback-empty
& C:/xampp/php/php.exe maintenance/ierb-group-tests/shutdown.php
```

The full schema-12 runner covers prior group, document, security, browser and report dependencies. Its document phase explicitly migrates the fixture to 14 and then 15 and verifies real automatic Final Report completion in the new monitor. It removes only synthetic extension data and returns to 12. Batch 4 verifies immutable AI summaries, PDFs, snapshots, real repeatable corrections, and the unchanged four fixed report rows. Batch 5 checks sorting, pagination, missing/tied dates, deduplication, authoritative checklist states, read-only comparison, current authorization, academic-year audit rollback and independent-connection duplicate races. Chromium exercises keyboard focus, hover, touch, cross-page/filter selection, modal focus behavior and overflow at 390, 768, 1024, 1440 and 1920 pixels in light/dark themes. Previously passed exploratory reruns may set `PRISM_TEST_SKIP_BATCH5_MATRIX=1`; final acceptance does not skip it.

Fixture cleanup deliberately deletes synthetic catalog rows before exercising empty rollback. This is test teardown, not an operational data-removal procedure. Runtime data, copied apps, dependency directories, credentials, sessions and provider logs must not be staged.

## Explicit operator migration

Production migration is **not authorized or performed by this batch**. The additive schema 15 migration requires exact reviewed schema 14 and does not alter existing Student/project assignments or enroll legacy workflows. Ordinary HTTP requests apply no extension DDL.

The CLI runner `php maintenance/migrate-academic-years.php [dry-run|apply|rollback]` defaults to read-only dry run and requires:

- `PRISM_MIGRATION_DB_DSN`, `PRISM_MIGRATION_DB_USER`, and optional `PRISM_MIGRATION_DB_PASSWORD`;
- `PRISM_ALLOW_SCHEMA_V15_MIGRATION=1`;
- `PRISM_SCHEMA_V15_EXPECT_DB` equal to the selected database name;
- enforced foreign keys, no open transaction, the shared `prism_migrate` mutex, and exact pinned schema 11–15 contracts.

The catalog preserves the three previously offered compatibility years and every distinct retained Student academic-year value, including archived records. Historical values are retained verbatim and marked historical. New entries require a responsible eligible Admin and consecutive `YYYY-YYYY` validation at both service and database layers. The unique primary key is the durable duplicate guard. Creation and individual assignment changes have mandatory transactional audits.

Missing published tables or unexplained drift fail closed. DDL before publication and empty rollback are restart safe. Once the catalog contains historical or newly created years, rollback is refused; disable additions while retaining the catalog. There is no deletion, promotion, bulk reassignment, historical replacement, stage builder, or automatic current-year choice.

Before schema 15 is applied, selectors retain the compatibility seed plus existing database years. Additions explicitly report the migration prerequisite. The seed file is migration/compatibility data, not a backend/client validation allowlist. After migration, all academic controls use the catalog plus retained references through `academic_catalog()`.

## Monitoring evidence rules

Canonical projects use their designated holder and the immutable project/workflow association. A valid document `rpms_submitted_at` must have a matching formal-submission audit with the correct document and Student identities and `Submitted to RPMS` result. Current or superseded versions can supply historical formal dates. Documents from another member do not become project submissions merely through group membership.

Legacy rows use the same verified document stamp; if that stamp is absent/invalid, the verified formal audit's own timestamp is the disclosed fallback. Manual `last_submission_date`, uploads, Student modification times, summary generation, and academic-year changes never establish formal submission. Unknown dates remain visible, last in either date direction, ordered by stable Student record ID. Other sort columns are allowlisted and have deterministic ties. Filtering/counting/sorting precede server-side pagination.

Official progress comes from the existing semantic workflow (including the correction's interrupted context), or the separate historical legacy model. Requirement acceptance is version-bound evidence. A formally submitted Final Report fulfills its requirement and automatically completes the eligible project; it does not acquire a fictional RPMS acceptance. Initial requirements, applicable later forms and per-case Amendment decisions remain separate from official stage progress.

The monitor is shared by Dashboard and IERB Progress. The existing Student action directory and guarded advancement tools remain available. Selection uses `project:<id>` or `student:<id>`, stays in memory across pages/filters, clears on navigation/reload, and is bounded at 100 comparisons. Every comparison re-resolves its keys in the current authorized scope and discloses unavailable keys. Adviser monitoring stays assigned-only; comparison/history and catalog management are Admin-only. No private document bytes, summary text, reviewer reasons or document-download links are exposed by this component.

History is paginated over retained formal, return, correction, advancement and completion events. It imposes no resubmission count limit and does not change the four fixed rows in Batch 4 PDFs. All displayed times are stored database values labeled Asia/Manila; unknown historical values are not reconstructed.
