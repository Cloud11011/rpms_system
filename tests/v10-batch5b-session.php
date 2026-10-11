<?php
namespace PrismBatch5bSession;
if (PHP_SAPI !== 'cli') exit(1);
function header(string $value): void { if (str_starts_with($value, 'Location: ')) $GLOBALS['location'] = $value; }
function session_regenerate_id(bool $delete): bool { ++$GLOBALS['rotations']; return $delete; }
function session_destroy(): bool { $GLOBALS['destroyed'] = true; return true; }
function setcookie(...$args): bool { $GLOBALS['cookieExpired'] = true; return true; }
function consume_auth_attempt(...$args): bool { return true; }
function too_many_recent_failures(...$args): bool { return false; }
function log_activity(...$args): void {}
function db(): \PDO { return $GLOBALS['pdo']; }
function onboarding_complete(...$args): bool { return empty($GLOBALS['case']['pending']); }
function json_out(array $data, int $status=200): never { http_response_code($status); echo json_encode($data); exit; }
class IdentityPDO extends \PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): \PDOStatement|false { return new IdentityStatement(str_contains($query,'WHERE id')); }
}
class IdentityStatement extends \PDOStatement {
    public function __construct(private bool $current) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetch(int $mode=\PDO::FETCH_DEFAULT,int $cursorOrientation=\PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $GLOBALS[$this->current?'current':'candidate']; }
}
if (($argv[1]??'')==='--case') {
    $case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    require __DIR__.'/../security.php';
    $config=file_get_contents(__DIR__.'/../config.php');
    foreach (['current_user','require_post_same_origin'] as $name) {
        preg_match('/function '.$name.'\([^\n]*\n\{[\s\S]*?\n\}/',$config,$m);
        eval('namespace '.__NAMESPACE__.'; '.$m[0]);
    }
    $pdo=new IdentityPDO(); $rotations=0; $destroyed=false; $cookieExpired=false; $location='';
    $current=['id'=>1,'role'=>$case['role']??'adviser','email'=>'a@example.invalid','full_name'=>'A','status'=>$case['status']??'Active',
        'password_hash'=>password_hash('secret-A',PASSWORD_DEFAULT),'must_change_password'=>$case['passwordChange']??0,'username'=>'A','ref_id'=>'A'];
    $candidate=$current;
    if (!empty($case['different'])) { $candidate['id']=2; $candidate['email']='b@example.invalid'; $candidate['role']=$case['otherRole']??'admin'; }
    $_SESSION=['user_id'=>1,'account_type'=>$current['role'],'user_email'=>$current['email'],'credential_fingerprint'=>hash('sha256',$current['password_hash']),
        'last_activity_at'=>time(),'csrf_token'=>'fixture-csrf','login_generation'=>str_repeat('a',32)];
    if (!empty($case['anonymous'])) $_SESSION=[];
    if (!empty($case['expired'])) $_SESSION['last_activity_at']=time()-1801;
    if (!empty($case['invalidated'])) $_SESSION['credential_fingerprint']='invalid';
    $before=$_SESSION;
    $_SERVER=['REQUEST_METHOD'=>'POST','SCRIPT_NAME'=>$case['file'],'HTTP_HOST'=>'fixture.test','HTTP_ORIGIN'=>$case['origin']??'http://fixture.test'];
    $_POST=['email'=>$candidate['email'],'password'=>!empty($case['badPassword'])?'bad':'secret-A'];
    if (!empty($case['malformed'])) $_POST=['email'=>['malformed'],'password'=>['malformed']];
    if (!empty($case['missingOrigin'])) unset($_SERVER['HTTP_ORIGIN']);
    if (isset($case['generation'])) $_SERVER['HTTP_X_PRISM_GENERATION']=$case['generation'];
    ob_start();
    register_shutdown_function(function(){ $out=ob_get_clean(); echo json_encode(['location'=>$GLOBALS['location'],'id'=>$_SESSION['user_id']??null,
        'preserved'=>array_diff_assoc($GLOBALS['before'],$_SESSION)===[],'rotations'=>$GLOBALS['rotations'],'destroyed'=>$GLOBALS['destroyed'],
        'cookieExpired'=>$GLOBALS['cookieExpired'],'status'=>http_response_code()?:200,'body'=>json_decode($out,true),'error'=>$_SESSION['error']??null]); });
    if ($case['file']==='guard') { require_post_same_origin(); exit; }
    $source=file_get_contents(__DIR__.'/../'.$case['file']);
    $source=str_replace("require __DIR__ . '/config.php';",'', $source);
    // login.php rendering is checked by the browser harness; only run its real redirect preamble.
    if ($case['file']==='login.php') $source=substr($source,0,strpos($source,'$loginError'));
    eval('namespace '.__NAMESPACE__.'; '.preg_replace('/^<\?php\s*/','',$source)); exit;
}
$cases=[];
foreach (['student','adviser','admin'] as $role) {
    $cases[]=['file'=>'login.php','role'=>$role,'location'=>'index.php','id'=>1];
    foreach (['student','adviser','admin'] as $otherRole) $cases[]=['file'=>'login_process.php','role'=>$role,'otherRole'=>$otherRole,'different'=>true,'statusCode'=>409,'id'=>1,'preserve'=>true];
    $cases[]=['file'=>'login_process.php','role'=>$role,'badPassword'=>true,'location'=>'index.php','id'=>1,'preserve'=>true];
    $cases[]=['file'=>'login_process.php','role'=>$role,'malformed'=>true,'statusCode'=>409,'id'=>1,'preserve'=>true];
    $cases[]=['file'=>'login_process.php','role'=>$role,'location'=>'index.php','id'=>1,'preserve'=>true];
    $cases[]=['file'=>'index.php','role'=>$role,'location'=>$role==='student'?'student.php':($role==='adviser'?'research_adviser.php':'dashboard.php'),'id'=>1];
}
foreach (['passwordChange'=>1,'pending'=>true] as $gate=>$value) $cases[]=['file'=>'index.php',$gate=>$value,'location'=>$gate==='pending'?'complete_profile.php':'change_password_required.php','id'=>1];
foreach (['expired'=>true,'invalidated'=>true,'status'=>'Inactive'] as $gate=>$value) $cases[]=['file'=>'index.php',$gate=>$value,'location'=>$gate==='expired'?'login.php?expired=1':'login.php','id'=>null];
$cases[]=['file'=>'login_process.php','anonymous'=>true,'location'=>'index.php','id'=>1,'rotate'=>1];
$cases[]=['file'=>'logout.php','location'=>'login.php','id'=>null,'destroy'=>true];
foreach (['http://evil.invalid','http://fixture.test:81'] as $origin) $cases[]=['file'=>'login_process.php','origin'=>$origin,'statusCode'=>403,'id'=>1,'preserve'=>true];
$cases[]=['file'=>'login_process.php','missingOrigin'=>true,'statusCode'=>403,'id'=>1,'preserve'=>true];
$cases[]=['file'=>'guard','generation'=>str_repeat('b',32),'statusCode'=>409,'id'=>1,'preserve'=>true];
$cases[]=['file'=>'guard','generation'=>str_repeat('a',32),'id'=>1,'preserve'=>true];
if (in_array('--baseline',$argv,true)) $cases=[['file'=>'login_process.php','different'=>true,'id'=>2,'rotate'=>1,'location'=>'index.php']];
foreach ($cases as $case) {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$r=json_decode($out,true);
    if ($exit || $err || ($r['id']??null)!==$case['id'] || ($r['rotations']??-1)!==($case['rotate']??0)
        || ($r['location']??null)!==(isset($case['location'])?'Location: '.$case['location']:'') || ($r['status']??0)!==($case['statusCode']??200)
        || (!empty($case['preserve'])&&!$r['preserved']) || (!empty($case['destroy'])&&(!$r['destroyed']||!$r['cookieExpired'])))
        throw new \RuntimeException(json_encode($case).' '.$err.$out);
}
echo 'PASS: '.count($cases).(in_array('--baseline',$argv,true)?' baseline silent-account-switch reproduction':' session, routing, identity preservation, rotation/logout and stale-generation cases')."; isolated PDO/session doubles, no application bootstrap.\n";
