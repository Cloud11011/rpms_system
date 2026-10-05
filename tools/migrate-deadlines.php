<?php
/** Explicit CLI-only v7 migration; back up and verify a disposable database first. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$expectedDb = getenv('PRISM_MIGRATION_EXPECT_DB');
if ($argc !== 2 || $argv[1] !== '--apply' || getenv('PRISM_ALLOW_SCHEMA_V7_MIGRATION') !== '1'
    || $expectedDb === false || trim($expectedDb) === '') {
    fwrite(STDERR, "Schema v7 migration not started. Require --apply, PRISM_ALLOW_SCHEMA_V7_MIGRATION=1 and PRISM_MIGRATION_EXPECT_DB after backup and staging verification.\n");
    exit(1);
}
require dirname(__DIR__) . '/config.php';
if ($expectedDb !== DB_NAME) {
    fwrite(STDERR, "Schema v7 migration not started: configured database does not match PRISM_MIGRATION_EXPECT_DB.\n");
    exit(1);
}
fwrite(STDOUT, 'PRISM schema v7 migration target: ' . DB_NAME . "\n");
db();
fwrite(STDOUT, "Schema v7 migration completed. Verify the application and repeat the idempotence check before release.\n");
