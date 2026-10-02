<?php
/** Explicit CLI schema migration. Back up and test a disposable copy before real use. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$expectedDb = getenv('PRISM_MIGRATION_EXPECT_DB');
if ($argc !== 2 || $argv[1] !== '--apply' || getenv('PRISM_ALLOW_SCHEMA_V6_MIGRATION') !== '1'
    || $expectedDb === false || trim($expectedDb) === '') {
    fwrite(STDERR, "Schema v6 migration not started. Require --apply, PRISM_ALLOW_SCHEMA_V6_MIGRATION=1 and a non-empty PRISM_MIGRATION_EXPECT_DB after backup and staging verification.\n");
    exit(1);
}
require dirname(__DIR__) . '/config.php';
if ($expectedDb !== DB_NAME) {
    fwrite(STDERR, 'Schema v6 migration not started: configured DB_NAME=' . json_encode(DB_NAME)
        . ' does not match PRISM_MIGRATION_EXPECT_DB=' . json_encode($expectedDb) . ".\n");
    exit(1);
}
fwrite(STDOUT, 'PRISM schema v6 migration target: ' . DB_NAME . "\n");
// Use the existing initializer, migration lock and failure handling; no alternate credentials.
db();
fwrite(STDOUT, 'PRISM schema v6 migration completed for: ' . DB_NAME
    . "\nVerify the application and repeat idempotence check before release.\n");
