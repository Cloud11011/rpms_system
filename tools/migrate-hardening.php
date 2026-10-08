<?php
/** Explicit CLI-only v8 migration. Never run before backup and isolated/staging verification. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$expectedDb = getenv('PRISM_MIGRATION_EXPECT_DB');
if ($argc !== 2 || $argv[1] !== '--apply' || getenv('PRISM_ALLOW_SCHEMA_V8_MIGRATION') !== '1'
    || $expectedDb === false || trim($expectedDb) === '') {
    fwrite(STDERR, "Require --apply, PRISM_ALLOW_SCHEMA_V8_MIGRATION=1 and PRISM_MIGRATION_EXPECT_DB after backup and disposable verification.\n");
    exit(1);
}
require dirname(__DIR__) . '/config.php';
if ($expectedDb !== DB_NAME) { fwrite(STDERR, "Configured database does not match the expected migration target.\n"); exit(1); }
$connection = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone = '+08:00'"]);
migrate($connection); // Migration only: never invoke account bootstrap/seed.
fwrite(STDOUT, "Schema v8 applied. Verify archive, deadlines and worker before release.\n");
