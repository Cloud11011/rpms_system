<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/schema_v15.php';
try {
    $dsn=getenv('PRISM_MIGRATION_DB_DSN'); $user=getenv('PRISM_MIGRATION_DB_USER');
    if (!$dsn || !$user) throw new RuntimeException('Explicit migration DSN and user required.');
    $pdo=new PDO($dsn,$user,getenv('PRISM_MIGRATION_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    echo json_encode(migrate_schema_v15($pdo,$argv[1]??'dry-run'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
