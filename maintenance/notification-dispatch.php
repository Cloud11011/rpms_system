<?php
// hPanel cron: PHP CLI only. No HTTP token or public trigger.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/notification_worker.php';
try {
    $options=getopt('', ['limit:','seconds:']);
    foreach ($options as $value) if (!is_string($value) || !ctype_digit($value)) throw new InvalidArgumentException('Use positive integer limits.');
    // Deploying this file never starts delivery: activation and the reviewed backlog fence live in config.local.php only.
    $activation=notification_worker_activation(
        defined('PRISM_NOTIFICATION_WORKER_ENABLED')?constant('PRISM_NOTIFICATION_WORKER_ENABLED'):null,
        defined('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID')?constant('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID'):null);
    if (!$activation['enabled']) {
        echo json_encode(['status'=>'disabled','message'=>'Notification worker is not enabled; nothing was read or delivered.'],JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    // Connect directly: a maintenance invocation must never seed accounts or run migrations.
    $connect=fn()=>new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone = '+08:00'"]);
    notification_worker_compatibility($connect());
    $result=notification_worker_run($connect(),(int)($options['limit']??25),(int)($options['seconds']??50),$activation['releaseAfterId']);
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    exit(($result['errors'] || $result['Failed'] || $result['Uncertain'])?1:0);
} catch (NotificationWorkerRefusal $e) {
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR,"Notification worker failed. Check the verified database schema (v15 or v16), database access and runtime prerequisites. No credentials are logged.\n");
    exit(1);
}
