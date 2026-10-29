<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/runtime_preflight.php';
require_once dirname(__DIR__).'/includes/migration_runner.php';
$options=getopt('',['database','integrations','expect-db:']);
$result=prism_runtime_preflight(dirname(__DIR__),array_key_exists('integrations',$options));
if (array_key_exists('database',$options)) {
    try {
        $dsn=getenv('PRISM_PREFLIGHT_DB_DSN');
        if (!$dsn || !str_starts_with($dsn,'mysql:')) throw new RuntimeException('Explicit connection required.');
        // Same TLS verification policy as the migration runners (PRISM_PREFLIGHT_DB_SSL_CA).
        $pdo=prism_migration_pdo($dsn,getenv('PRISM_PREFLIGHT_DB_USER')?:'',getenv('PRISM_PREFLIGHT_DB_PASSWORD')?:'',getenv('PRISM_PREFLIGHT_DB_SSL_CA')?:null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if (isset($options['expect-db']) && (string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$options['expect-db']) throw new PrismMigrationInputError('database_mismatch');
        $result['database']=prism_runtime_database_preflight($pdo);
        $result['ok']=$result['ok']&&$result['database']['ok'];
    } catch (Throwable $e) {
        $result['ok']=false;
        $result['database']=['ok'=>false,'message'=>'Read-only database preflight could not complete. Check explicitly supplied credentials and a supported schema (v10-v15).',
            'errorClass'=>match(true){$e instanceof PrismMigrationInputError&&$e->getMessage()==='database_mismatch'=>'database_mismatch',
                $e instanceof PrismMigrationConnectionError||$e instanceof PrismMigrationInputError=>prism_migration_diagnostic($e)['errorClass'],
                $e instanceof PDOException=>'database_error',default=>'precondition_refused'},
            'sqlstate'=>($p=$e instanceof PDOException?$e:$e->getPrevious()) instanceof PDOException?(string)($p->errorInfo[0]??$p->getCode()):null];
    }
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['ok']?0:1);
