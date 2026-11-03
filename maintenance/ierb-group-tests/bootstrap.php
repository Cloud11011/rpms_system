<?php
if (PHP_SAPI!=='cli') exit;
date_default_timezone_set('Asia/Manila');
require __DIR__.'/environment.php';
$root=dirname(__DIR__,2);
$review=PRISM_TEST_REVIEW_DIR;
$GLOBALS['fixture_db']=new PDO('mysql:host=127.0.0.1;port='.PRISM_TEST_DB_PORT.';dbname=prism_group_synthetic_v11;charset=utf8mb4','root','prism_synthetic_only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo=$GLOBALS['fixture_db']; $pdo->exec("SET time_zone='+08:00'");
if (!str_contains(strtolower(str_replace('\\','/',$pdo->query('SELECT @@datadir')->fetchColumn())),strtolower(str_replace('\\','/',$review).'/sandbox/mysql/')))
    throw new RuntimeException('Only the isolated fixture database is authorized.');
define('STORAGE_DIR',$review.'/sandbox/app/storage');
define('DOCS_DIR',STORAGE_DIR.'/documents');
define('REPORTS_DIR',STORAGE_DIR.'/reports');
define('APP_BASE_URL','https://synthetic.example.test');
function db(): PDO { return $GLOBALS['fixture_db']; }
function current_user(): ?array { return $GLOBALS['actor']??null; }
function log_api_error(string $scope,string $message): void { $GLOBALS['fixture_errors'][]=$scope; }
function send_notification_email(string $email,string $subject,string $body,bool $a=false,bool $b=false): array {
    if (db()->inTransaction()) throw new RuntimeException('Provider called inside a transaction.');
    $GLOBALS['provider_calls'][]=$email;
    // Optional notification-worker controls: cross-process call log, slow provider, per-recipient failure, crash after acceptance.
    if (getenv('PRISM_TEST_PROVIDER_LOG')) file_put_contents(getenv('PRISM_TEST_PROVIDER_LOG'),getmypid()."\t".$email."\n",FILE_APPEND|LOCK_EX);
    if (!empty($GLOBALS['provider_delay_ms'])) usleep((int)$GLOBALS['provider_delay_ms']*1000);
    if (!empty($GLOBALS['provider_crash'])) exit(9);
    if (in_array($email,$GLOBALS['provider_fail_for']??[],true)) return ['ok'=>false,'outcome'=>'rejected','channel'=>'synthetic'];
    if (in_array($email,$GLOBALS['provider_uncertain_for']??[],true)) return ['ok'=>false,'outcome'=>'uncertain','channel'=>'synthetic'];
    if (!empty($GLOBALS['provider_failure'])) throw new RuntimeException('Synthetic Gmail failure after commit');
    return ['ok'=>true,'channel'=>'synthetic'];
}
$GLOBALS['actor']=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
require_once $root.'/workflow.php';
require_once $root.'/includes/account_lifecycle.php';
require_once $root.'/includes/legal_policy.php';
$source=file_get_contents($root.'/config.php');
$start=strpos($source,'const STAGE_SEQUENCE =');
$end=strpos($source,'function log_api_error(',$start);
eval(substr($source,$start,$end-$start));
require_once $root.'/includes/ierb_group_advancement.php';
