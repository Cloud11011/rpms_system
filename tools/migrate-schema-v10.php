<?php
/** Owner-authorized one-time operator command. Never executed by ordinary web requests. */
if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SCHEMA_V10_MIGRATION')!=='1' || !getenv('PRISM_SCHEMA_V10_EXPECT_DB')) {
    fwrite(STDERR,"Explicit CLI migration authorization and exact target database are required.\n"); exit(1);
}
require __DIR__.'/../config.php';
// Connection establishment runs the guarded migrate(). No seed/admin auto-creation through db().
if (DB_NAME!==getenv('PRISM_SCHEMA_V10_EXPECT_DB')) throw new RuntimeException('Configured database does not match the reviewed migration target.');
$pdo=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+08:00'");
migrate($pdo);
schema_v10_check($pdo,schema_v10_plan()['verify']);
schema_v10_check($pdo,schema_v10_plan()['invariants']);
echo "Schema v10 validated. No user acceptances were fabricated.\n";
