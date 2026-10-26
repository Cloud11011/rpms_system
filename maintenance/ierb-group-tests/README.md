Run these checks only against the disposable synthetic fixture. They never use config.local.php or a deployed database.

From the repository root on Windows:

```powershell
& maintenance/ierb-group-tests/setup.ps1
node maintenance/ierb-group-tests/run.cjs
```

Prerequisites: XAMPP PHP with pdo_mysql, mbstring and zip; MariaDB tools; Node; Playwright Core; Chrome. Override PRISM_TEST_PHP, PRISM_TEST_PLAYWRIGHT (absolute module path), and PRISM_TEST_CHROME as needed. setup.ps1 defaults to C:/xampp/mysql/bin and C:/xampp/php. It refuses to overwrite an existing fixture.

MariaDB listens only on 127.0.0.1:13527; the copied PHP app listens on 127.0.0.1:14527. The database name is prism_group_synthetic_v11 and every CLI helper verifies the isolated datadir before accessing data. The fixture password is synthetic and only secures this disposable loopback instance. The full v11 schema comes from the existing frozen manifest; test-only fault triggers are removed in finally blocks. Application migrations and configuration are unchanged.

Results, screenshots, server data, copied sources, sessions and mail metadata go exclusively to PRISM_V13_GROUP_ADVANCEMENT_REVIEW. Do not deploy or stage that directory. The PHP server and browser close after the run; stop MariaDB with shutdown.php when finished. A second run can reuse the fixture; initialize a fresh fixture in a new checkout for a clean starting state.

The test provider never contacts Gmail or OpenRouter. Regression tests use real document upload/review/version APIs and synthetic provider responses. CLI concurrency workers have independent PDO connections. The same primary-range lock used by confirmation is exercised against concurrent inserts and reassignments. Production scale and real provider delivery are outside this suite.
