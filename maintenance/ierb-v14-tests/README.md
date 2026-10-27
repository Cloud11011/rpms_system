# V14 foundation verification

Use the disposable fixture in `maintenance/ierb-group-tests/README.md`. Its bootstrap verifies the actual MariaDB datadir before every helper; none of these commands loads `config.local.php` or a deployed database. The normal prerequisites are PHP 8.1+ with pdo_mysql/mbstring, MariaDB 10.4, Node, Playwright Core and Chrome. Install Composer dependencies locally for the existing document regression suite. Set `PRISM_TEST_PLAYWRIGHT` to an available Playwright Core module if the original audit's browser-tools directory is absent.

From a fresh checkout, initialize the isolated fixture, then run:

```powershell
& maintenance/ierb-group-tests/setup.ps1
node maintenance/ierb-group-tests/run.cjs
node maintenance/ierb-v14-tests/run.cjs
```

The foundation runner starts and finishes at the exact v11 schema. It applies schema 12, tests dry runs, restart after interrupted DDL, rollback, retained-data refusal, feature gating, explicit enrollment, role/lifecycle authorization, affected-version ownership, idempotency, repeated cases, atomic audit failure and independent-connection races. The original foundation cases seed a resolved case only inside the disposable test to exercise its storage contract. Batch 2's additional suite exercises actual evidence-driven correction resolution and completion.

To run the existing full HTTP/browser/document regression suite with the optional schema 12 extension installed and **no enrolled Students**:

```powershell
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php apply-for-regression
$env:PRISM_TEST_SCHEMA_VERSION='12'
node maintenance/ierb-group-tests/run.cjs
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php rollback-empty
Remove-Item Env:PRISM_TEST_SCHEMA_VERSION
```

Runtime databases, app copies, browser images, sessions and provider stubs stay in the ignored-by-staging `PRISM_V13_GROUP_ADVANCEMENT_REVIEW` directory; do not stage it. The foundation's JSON evidence goes to `PRISM_V14_BATCH_01_EVIDENCE`. Stop the synthetic server with `maintenance/ierb-group-tests/shutdown.php` after testing.

`generate-contract.php` is a maintainer-only helper for the empty disposable v11 fixture. It captures canonical `SHOW CREATE TABLE` output from the reviewed DDL. Do not regenerate the pinned contract merely to accept a deployment's schema drift. The current contract is validated on MariaDB 10.4.32; other server formats require explicit review.

## Batch 2 document integration

After the foundation suite restores schema 11, explicitly apply schema 12 to the same verified disposable fixture. No additional schema migration is needed:

```powershell
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php apply-for-regression
$env:PRISM_TEST_SCHEMA_VERSION='12'
$env:PRISM_TEST_V14_DOCUMENTS='1'
node maintenance/ierb-group-tests/run.cjs
& C:/xampp/php/php.exe maintenance/ierb-v14-tests/cases.php rollback-empty
Remove-Item Env:PRISM_TEST_SCHEMA_VERSION,Env:PRISM_TEST_V14_DOCUMENTS
```

The full run includes the existing backend, responsive browser and document regression suites before `documents.cjs`. To investigate only document integration, additionally set `PRISM_TEST_PHASE=documents`; remove it for acceptance. The runner copies new include/asset files into its isolated HTTP application before starting PHP. Composer dependencies, Chrome, Playwright Core and the synthetic MariaDB fixture are still prerequisites.

`documents-fixture.php` supports independent CLI workers and explicit test enrollment/correction administration. It uses the same datadir guard as every existing fixture helper. Only synthetic workflow holder 4 is enrolled; holder 5 remains outside V14. Cleanup removes that fixture's workflow/events/cases and documents named `Synthetic Batch2 ...`, without applying DDL or modifying any operational database.

Coverage includes all 13 requirements; missing, pending, denied and returned evidence; separate RPMS acceptance; Student and Admin mixed-category multipart requests and partial content/category failures; replay and concurrent acceptance; current version chains; Initial and Data Gathering correction resume; repeated mandatory/optional Amendments; case-bound supporting categories; institutional Form 33 acceptance through Chromium; role-scoped checklist parity; single/bulk evidence retention; four responsive light/dark checklist captures; Final Report rollback faults, concurrent approvals, retry, provider failure and automatic completion without partner fanout. Results and selected screenshots are written to `PRISM_V14_BATCH_02_EVIDENCE`.

Enrollment, case opening and resolution remain opted-in CLI services under `PRISM_IERB_V14_FOUNDATION=1`. There are no general V14 enrollment/activation or stage-builder HTTP routes. The new document acceptance endpoint operates only on explicitly enrolled holders, with current session generation, same-origin POST, live Admin eligibility, revision checks and authoritative document evidence.
