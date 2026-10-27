# Batch 4 verification and migration

All fixtures use the existing disposable MariaDB instance and verify its actual datadir before accessing records. They never read `config.local.php`, production data, or real provider credentials. Existing upload restrictions remain PDF/DOCX.

Start with `maintenance/ierb-group-tests/setup.ps1` in a fresh checkout. Install the existing Composer dependencies locally and set `PRISM_TEST_PLAYWRIGHT` to an available Playwright Core module. Run the full v11 regression suite, the V14 foundation suite, then the full schema-12 suite with both `PRISM_TEST_V14_DOCUMENTS=1` and `PRISM_TEST_PROJECT_GROUP=1`, as described in the Batch 3 test README. Set `PRISM_TEST_EVIDENCE_DIR` to `PRISM_V14_BATCH_04_EVIDENCE` so historical evidence files are preserved.

After those suites leave the fixture at schema 12, run:

```powershell
& C:/xampp/php/php.exe maintenance/ierb-report-tests/cycles.php
$env:PRISM_TEST_SCHEMA_VERSION='12'
$env:PRISM_TEST_PHASE='documents'
$env:PRISM_TEST_BATCH4='1'
node maintenance/ierb-group-tests/run.cjs
Remove-Item Env:PRISM_TEST_BATCH4
$env:PRISM_TEST_V14_DOCUMENTS='1'
$env:PRISM_TEST_PROJECT_GROUP='1'
$env:PRISM_TEST_REPORT_SCHEMA14='1'
node maintenance/ierb-group-tests/run.cjs
```

The first integration run tests summary versioning, historical import, Local/AI generation and provider-failure fallback, audited PDF exports, role and cross-document isolation, immutable project snapshots, provider de-identification, stale report rejection, responsive light/dark views, and real formal submission/return/correction cases through the historical fourth-cycle exception. It restores schema 12 after removing only synthetic records. The second run verifies the full document and canonical group workflow on schema 14, including automatic Form 33-0B completion and completed-project reporting with retained overflow evidence. Tests explicitly opt into schema migration only inside the disposable fixture.

Install a PDF renderer in a workspace-local tool directory (for example `python -m pip install --target .batch04-tools pymupdf`) and run `python maintenance/ierb-report-tests/render.py`. This renders every evidence PDF and validates text bounds, repeated headers/footers, and fixed tracking labels. Inspect the resulting actual page images; byte validity alone is insufficient. The dependencies, database, copied app, provider request logs and sessions are excluded from staging.

After verification, remove the empty schema-12 extension with `maintenance/ierb-v14-tests/cases.php rollback-empty`, then stop the disposable server with `maintenance/ierb-group-tests/shutdown.php`.

## Operator migration (not authorized for production in Batch 4)

The additive migration requires the exact reviewed schema 13. `maintenance/migrate-summary-history.php` defaults to a read-only dry run and reads only explicit environment credentials:

- `PRISM_MIGRATION_DB_DSN`, `PRISM_MIGRATION_DB_USER`, `PRISM_MIGRATION_DB_PASSWORD`;
- `PRISM_ALLOW_SCHEMA_V14_MIGRATION=1`;
- `PRISM_SCHEMA_V14_EXPECT_DB` exactly equal to the selected database.

Explicit CLI modes are `dry-run`, `apply`, and `rollback`. Foreign-key enforcement, no open transaction, the shared schema mutex, and exact pinned v11/v12/v13/v14 contracts are mandatory. Partial apply/empty rollback are restart safe. Published missing tables or drift fail closed. Retained summary versions or report snapshots prevent rollback. No existing rows are rewritten, no projects are enrolled, and HTTP requests never perform DDL. Do not regenerate a pinned contract to conceal drift.

New summary generation is unavailable before this reviewed migration so older summaries cannot be overwritten without history. Historical summaries remain readable and independently exportable. The first new generation imports an existing legacy summary as version 1, preserving its exact text and acknowledging unrecorded metadata. There is no backfill that invents generation times, models or investigator roles.

Project reports use the existing PDF renderer and report file/history/deletion paths. Summary PDFs are generated privately on demand from the selected immutable version, with a mandatory export audit. Source documents with retained summary versions are protected from deletion and account purge. Built-in PDF Latin fonts transliterate unsupported characters; snapshots retain original UTF-8 values.
