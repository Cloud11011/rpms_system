<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/runtime_preflight.php';
$options=getopt('',['database','integrations']);
$result=prism_runtime_preflight(dirname(__DIR__),array_key_exists('integrations',$options));
if (array_key_exists('database',$options)) {
    try {
        $dsn=getenv('PRISM_PREFLIGHT_DB_DSN');
        if (!$dsn || !str_starts_with($dsn,'mysql:')) throw new RuntimeException('Explicit connection required.');
        $pdo=new PDO($dsn,getenv('PRISM_PREFLIGHT_DB_USER')?:'',getenv('PRISM_PREFLIGHT_DB_PASSWORD')?:'',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $result['database']=prism_runtime_database_preflight($pdo);
        $result['ok']=$result['ok']&&$result['database']['ok'];
    } catch (Throwable $e) {
        $result['ok']=false;
        $result['database']=['ok'=>false,'message'=>'Read-only database preflight could not complete. Check explicitly supplied credentials and supported schema v10/v11.'];
    }
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['ok']?0:1);
