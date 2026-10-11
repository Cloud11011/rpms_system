<?php
/** Connection must match the random-port private server and exact disposable datadir. */
if (PHP_SAPI!=='cli' || $argc!==2) exit(1);
$case=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$pdo=new PDO($case['dsn'],'root',$case['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($case['datadir'])) exit(1);
date_default_timezone_set('Asia/Manila'); $pdo->exec("SET time_zone='+08:00'");
require_once __DIR__.'/../includes/notification_delivery.php';
function send_notification_email(...$args): array {
    if (($args[3]??true)!==false || ($args[4]??true)!==false) throw new RuntimeException('Unsafe notification fallback.');
    file_put_contents($GLOBALS['case']['attempts'],"attempt\n",FILE_APPEND|LOCK_EX);
    if (isset($GLOBALS['case']['providerGate'])) {
        file_put_contents($GLOBALS['case']['providerGate'].'.ready','ready');
        $deadline=microtime(true)+15;
        while (!file_exists($GLOBALS['case']['providerGate']) && microtime(true)<$deadline) usleep(10000);
        if (!file_exists($GLOBALS['case']['providerGate'])) throw new RuntimeException('Private provider gate timed out.');
    }
    return ['ok'=>true,'channel'=>'fixture'];
}
function log_api_error(...$args): void { throw new RuntimeException('Unexpected private delivery error.'); }
if (isset($case['gate'])) {
    file_put_contents($case['ready'],'ready'); $deadline=microtime(true)+15;
    while (!file_exists($case['gate']) && microtime(true)<$deadline) usleep(10000);
    if (!file_exists($case['gate'])) exit(1);
}
try {
    if ($case['mode']==='dispatch') $result=notification_dispatch($pdo,$case['id']);
    else {
        $user=notification_rows($pdo,'SELECT * FROM users WHERE id=?',[$case['actor']])[0];
        $result=notification_transition($pdo,$user,$case['action'],$case['id'],true);
    }
    echo json_encode(['ok'=>true,'result'=>$result]);
} catch (NotificationLifecycleError $e) { echo json_encode(['ok'=>false,'status'=>$e->status]); }
