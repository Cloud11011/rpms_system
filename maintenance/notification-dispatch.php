<?php
// hPanel cron: PHP CLI only. No HTTP token or public trigger.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/notification_worker.php';
try {
    $options=getopt('', ['limit:','seconds:']);
    foreach ($options as $value) if (!is_string($value) || !ctype_digit($value)) throw new InvalidArgumentException('Use positive integer limits.');
    // Connect directly: a maintenance invocation must never seed accounts or run migrations.
    $pdo=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone = '+08:00'"]);
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()!=='10') {
        throw new RuntimeException('Worker requires an existing schema v10.');
    }
    $result=notification_worker_run($pdo,(int)($options['limit']??25),(int)($options['seconds']??50));
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    exit(($result['errors'] || $result['Failed'] || $result['Uncertain'])?1:0);
} catch (Throwable $e) {
    fwrite(STDERR,"Notification worker failed. Check schema v10, database access and runtime prerequisites. No credentials are logged.\n");
    exit(1);
}
