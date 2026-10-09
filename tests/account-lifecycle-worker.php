<?php
/** Private test-instance worker; never loads application configuration. */
if (PHP_SAPI!=='cli' || $argc!==2) exit(1);
$fixture=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
if (!str_contains($fixture['dsn'],'dbname=hardening_test_lifecycle') || !is_dir($fixture['datadir'])) exit(1);
$pdo=new PDO($fixture['dsn'],$fixture['dbUser']??'root',$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($fixture['datadir'])) exit(1);
define('STORAGE_DIR',dirname(realpath($fixture['datadir'])).DIRECTORY_SEPARATOR.'storage');
foreach ([STORAGE_DIR,STORAGE_DIR.DIRECTORY_SEPARATOR.'documents',STORAGE_DIR.DIRECTORY_SEPARATOR.'reports'] as $privateDir) {
    if (!is_dir($privateDir) && !mkdir($privateDir,0700) && !is_dir($privateDir)) exit(1);
}
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
        $retentionGate=!empty($this->fixture['retentionGate']) && in_array($this->queryString,['SELECT * FROM students WHERE id=? FOR UPDATE','SELECT * FROM advisers WHERE id=? FOR UPDATE'],true);
        $assignmentGate=!empty($this->fixture['assignmentGate']) && $this->queryString==='SELECT * FROM advisers WHERE id=? FOR UPDATE';
        if ($retentionGate || $assignmentGate) {
            $gate=$this->fixture[$assignmentGate?'assignmentGate':'retentionGate'];file_put_contents($gate.'.ready','locked');$deadline=microtime(true)+15;
            while(!file_exists($gate)&&microtime(true)<$deadline)usleep(10000);
            if(!file_exists($gate))throw new RuntimeException('Retention lock gate timed out');
        }
        return $rows;
    }
}
if(!empty($fixture['finalGate']) || !empty($fixture['retentionGate']) || !empty($fixture['assignmentGate'])) $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[LifecyclePausedStatement::class,[$fixture]]);
$pdo->exec("SET time_zone='+08:00'");
if(isset($fixture['foreignKeyChecks'])) $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS='.(int)$fixture['foreignKeyChecks']);
$actor=$pdo->query('SELECT * FROM users WHERE id='.(int)$fixture['actor'])->fetch();
require __DIR__.'/../includes/account_lifecycle.php';
require_once __DIR__.'/../includes/account_onboarding.php';
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',($fixture['verified']??true)===true);
if(empty($fixture['omitVerification'])) define('PRISM_HARD_DELETE_VERIFICATION',$fixture['verification']??[]);
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
if(str_starts_with($fixture['mode']??'', 'onboarding_')) {
    require_once __DIR__.'/../includes/account_onboarding.php';
    define('APP_BASE_URL','https://prism.invalid');
    function app_base_url_is_valid(): bool { return true; }
    function new_password_is_valid(string $value): bool { return strlen($value)>=12 && strlen($value)<=200; }
    function send_notification_email(...$args): array { return ['ok'=>true]; }
    try {
        $result=match($fixture['mode']) {
            'onboarding_invite'=>onboarding_invite($pdo,$actor,$fixture['data']),
            'onboarding_finish'=>onboarding_finish($pdo,$actor,$fixture['data']),
            'onboarding_resend'=>onboarding_resend($pdo,$actor,$fixture['data']),
            'onboarding_assign'=>onboarding_assign($pdo,$actor,$fixture['data']),
            'onboarding_accept'=>(function()use($pdo,$fixture){onboarding_accept($pdo,$fixture['data']);return ['ok'=>true];})()
        }; json_out($result);
    } catch(Throwable $e) {json_out(['ok'=>false,'message'=>lifecycle_error_status($e)<500?$e->getMessage():'Safe concurrent refusal'],lifecycle_error_status($e));}
}
if (($fixture['mode']??'')==='late_student_audit') {
    require_once __DIR__.'/../workflow.php';
    audit_log($actor,'ierb_note_added',['entity_type'=>'student','entity_id'=>100,'student_id'=>100,'details'=>'Synthetic late workflow data']);
    json_out(['ok'=>true]);
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
    if ($fixture['mode']==='student_save') {
        // Execute the real identity-sync and audit helpers without bootstrapping installed configuration.
        $configSource=file_get_contents(__DIR__.'/../config.php');
        $from=strpos($configSource,'function sync_student_login_identity(');$to=strpos($configSource,'function too_many_recent_failures(');
        if ($from===false || $to===false || $to<=$from) throw new RuntimeException('Cannot extract Student save helpers');
        eval(substr($configSource,$from,$to-$from));
    }
    $_GET['action']=match($fixture['mode']) {'adviser_archive'=>'delete','profile_password'=>'change_password',default=>'save'};
    $file=match($fixture['mode']) {'student_save'=>'students_api.php','profile_password'=>'profile_api.php',default=>'advisers_api.php'};
    $source=file_get_contents(__DIR__.'/../'.$file);
    $source=str_replace("require __DIR__ . '/config.php';",'',substr($source,5));
    eval(str_replace('__DIR__','dirname(__DIR__)',$source));
} else {
    if(($fixture['mode']??'')==='availability') { $_SERVER['REQUEST_METHOD']='GET'; $_GET['action']='availability'; }
    $source=file_get_contents(__DIR__.'/../account_lifecycle_api.php');
    $source=str_replace("require __DIR__.'/config.php';",'',substr($source,5));
    eval(str_replace('__DIR__','dirname(__DIR__)',$source));
}
