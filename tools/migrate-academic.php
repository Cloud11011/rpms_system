<?php
/** Explicit CLI schema migration. Back up and test a disposable copy before real use. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || $argv[1] !== '--apply' || getenv('PRISM_ALLOW_SCHEMA_V5_MIGRATION') !== '1') {
    fwrite(STDERR, "Migration not started. Require --apply and PRISM_ALLOW_SCHEMA_V5_MIGRATION=1 after backup and staging verification.\n");
    exit(1);
}
require dirname(__DIR__) . '/config.php';
// Use the existing initializer, migration lock and failure handling; no alternate credentials.
db();
fwrite(STDOUT, "PRISM schema v5 migration completed. Verify the application and repeat idempotence check before release.\n");
