# V14 foundation verification

Use the disposable fixture in `maintenance/ierb-group-tests/README.md`. Its bootstrap verifies the actual MariaDB datadir before every helper; none of these commands loads `config.local.php` or a deployed database. The normal prerequisites are PHP 8.1+ with pdo_mysql/mbstring, MariaDB 10.4, Node, Playwright Core and Chrome. Install Composer dependencies locally for the existing document regression suite. Set `PRISM_TEST_PLAYWRIGHT` to an available Playwright Core module if the original audit's browser-tools directory is absent.

From a fresh checkout, initialize the isolated fixture, then run:

```powershell
& maintenance/ierb-group-tests/setup.ps1
node maintenance/ierb-group-tests/run.cjs
node maintenance/ierb-v14-tests/run.cjs
```

The foundation runner starts and finishes at the exact v11 schema. It applies schema 12, tests dry runs, restart after interrupted DDL, rollback, retained-data refusal, feature gating, explicit enrollment, role/lifecycle authorization, affected-version ownership, idempotency, repeated cases, atomic audit failure and independent-connection races. A resolved case is seeded only inside the disposable test to exercise the storage/resume contract; no production resolution or completion bypass is implemented. Document-driven resolution and completion belong to Batch 2.

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
