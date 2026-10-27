<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/schema_v14.php';
try {
    $dsn=getenv('PRISM_MIGRATION_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn,'mysql:')) throw new RuntimeException('Explicit MySQL DSN required.');
    $pdo=new PDO($dsn,getenv('PRISM_MIGRATION_DB_USER')?:'',getenv('PRISM_MIGRATION_DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    echo json_encode(migrate_schema_v14($pdo,$argv[1]??'dry-run'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Summary migration refused. Verify opt-in, database binding, contracts and retained history.'.PHP_EOL); exit(1); }
