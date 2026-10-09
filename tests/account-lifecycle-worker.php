<?php
/** Private test-instance worker; never loads application configuration. */
if (PHP_SAPI!=='cli' || $argc!==2) exit(1);
$fixture=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
if (!str_contains($fixture['dsn'],'dbname=hardening_test_lifecycle') || !is_dir($fixture['datadir'])) exit(1);
$pdo=new PDO($fixture['dsn'],$fixture['dbUser']??'root',$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($fixture['datadir'])) exit(1);
class LifecyclePausedStatement extends PDOStatement {
    protected function __construct(private array $fixture) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        $rows=parent::fetchAll($mode,...$args);
        if(!empty($this->fixture['finalGate']) && str_contains($this->queryString,'SELECT * FROM students WHERE id IN (')) {
            $gate=$this->fixture['finalGate']; file_put_contents($gate.'.ready','locked');
            $deadline=microtime(true)+15;
            while(!file_exists($gate) && microtime(true)<$deadline) usleep(10000);
            if(!file_exists($gate)) throw new RuntimeException('Final persistence gate timed out');
        }
        return $rows;
    }
}
if(!empty($fixture['finalGate'])) $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[LifecyclePausedStatement::class,[$fixture]]);
$pdo->exec("SET time_zone='+08:00'");
$actor=$pdo->query('SELECT * FROM users WHERE id='.(int)$fixture['actor'])->fetch();
require __DIR__.'/../includes/account_lifecycle.php';
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',($fixture['verified']??true)===true);
define('PRISM_HARD_DELETE_VERIFICATION',$fixture['verification']??[]);
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
if(isset($fixture['isolation'])) $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$fixture['isolation']);
ini_set('error_log',$fixture['datadir'].'/worker-errors.log');
if(in_array($fixture['mode']??'',['notification_writer','report_writer'],true)) {
    require_once __DIR__.'/../includes/notification_delivery.php';
    $captured=$pdo->query('SELECT * FROM students WHERE id=100 AND archived_at IS NULL')->fetch();
}
function db(): PDO { return $GLOBALS['pdo']; }
function api_require_login($roles): array {
    if (!in_array($GLOBALS['actor']['role']??'',(array)$roles,true)) json_out(['ok'=>false,'message'=>'Forbidden'],403);
    return $GLOBALS['actor'];
}
function require_post_same_origin(): void {
    if (!empty($GLOBALS['fixture']['badOrigin'])) json_out(['ok'=>false],403);
}
function json_body(): array { return $GLOBALS['fixture']['data']; }
function json_out(array $data,int $status=200): never {
    echo json_encode(['status'=>$status,'data'=>$data,'sessionActive'=>session_status()===PHP_SESSION_ACTIVE,'sessionUser'=>$_SESSION['user_id']??null]); exit;
}
function log_api_error(...$args): void { throw new RuntimeException('Unexpected test API error'); }
function is_allowed_email_domain(string $email): bool { return str_ends_with($email,'@example.invalid'); }
if (!empty($fixture['waitFile'])) {
    file_put_contents($fixture['waitFile'].'.ready','ready');
    $deadline=microtime(true)+10;
    while(!file_exists($fixture['waitFile']) && microtime(true)<$deadline) usleep(10000);
}
if(($fixture['mode']??'')==='student_archive') {
    require_once __DIR__.'/../workflow.php';
    if(!empty($fixture['archiveAttempt'])) file_put_contents($fixture['archiveAttempt'].'.ready',(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try { archive_student($pdo,$actor,100); json_out(['ok'=>true]); }
    catch(Throwable $error) { json_out(['ok'=>false,'testError'=>get_class($error).': '.$error->getMessage()],lifecycle_error_status($error)); }
}
if(in_array($fixture['mode']??'',['notification_writer','report_writer'],true)) {
    try {
        if(!$captured) throw new StudentSnapshotConflict('No current Student.');
        if($fixture['mode']==='notification_writer') {
            $result=create_notification($pdo,'student',100,$captured['email'],$captured['full_name'],
                'Isolated notice','Isolated message','Reminder','Synthetic Admin One',null,date('Y-m-d H:i:s',time()+3600));
        } else {
            report_persist_snapshot($pdo,[$captured],[':id'=>'race-report',':title'=>'Isolated report',':type'=>'Progress',':file'=>'synthetic.pdf',':by'=>'Synthetic Admin One',':uid'=>1]);
            $result=['ok'=>true];
        }
        json_out($result);
    } catch(Throwable $error) { json_out(['ok'=>false],$error instanceof StudentSnapshotConflict?409:lifecycle_error_status($error)); }
}
session_save_path($fixture['datadir']); session_id('lifecycle'.bin2hex(random_bytes(8))); session_start();
$_SESSION['user_id']=$actor['id'];
if (in_array($fixture['mode']??'',['adviser_archive','adviser_save','student_save','profile_password'],true)) {
    require_once __DIR__.'/../security.php';
    $_GET['action']=match($fixture['mode']) {'adviser_archive'=>'delete','profile_password'=>'change_password',default=>'save'};
    $file=match($fixture['mode']) {'student_save'=>'students_api.php','profile_password'=>'profile_api.php',default=>'advisers_api.php'};
    $source=file_get_contents(__DIR__.'/../'.$file);
    $source=str_replace("require __DIR__ . '/config.php';",'',substr($source,5));
    eval(str_replace('__DIR__','dirname(__DIR__)',$source));
} else {
    $source=file_get_contents(__DIR__.'/../account_lifecycle_api.php');
    $source=str_replace("require __DIR__.'/config.php';",'',substr($source,5));
    eval(str_replace('__DIR__','dirname(__DIR__)',$source));
}
