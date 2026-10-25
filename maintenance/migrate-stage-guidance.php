<?php
/** Operator invocation only. No application bootstrap, seeds, credentials or automatic migration. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/schema_v11.php';
try {
    $dsn=getenv('PRISM_MIGRATION_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn,'mysql:') || getenv('PRISM_ALLOW_SCHEMA_V11_MIGRATION')!=='1'
        || !getenv('PRISM_SCHEMA_V11_EXPECT_DB')) throw new RuntimeException('Explicit v11 opt-in, DSN and exact database name are required.');
    $pdo=new PDO($dsn,getenv('PRISM_MIGRATION_DB_USER')?:'',getenv('PRISM_MIGRATION_DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    migrate_schema_v11($pdo);
    echo "Schema v11 verified. Existing stage keys, names and histories are preserved.\n";
} catch (Throwable $e) {
    // No credential/DSN values in diagnostics.
    fwrite(STDERR,'Stage guidance migration stopped: '.$e->getMessage().PHP_EOL);
    exit(1);
}
