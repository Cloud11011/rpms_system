<?php
/** Invitation-only exact domains and actual API/service; isolated SQL, never live config. */
if(PHP_SAPI!=='cli')exit(1);
function legal_acceptance_required(...$args): bool { return false; }
function source_function(string $source,string $name): string {
    $source=str_replace("\r\n","\n",$source);$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\n}\n",$start);
    if($start===false || $end===false)throw new RuntimeException('Missing reviewed helper.');return substr($source,$start,$end+2-$start);
}
if(($argv[1]??'')==='--case') {
    $case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    define('ALLOWED_EMAIL_DOMAINS',[' EXAMPLE.TEST ','example.invalid','example.test']);
    define('APP_BASE_URL','https://prism.invalid');
    class InvitationPDO extends PDO {
        public function prepare(string $sql,array $options=[]): PDOStatement|false {
            return parent::prepare(str_replace([' FOR UPDATE','DATE_ADD(NOW(),INTERVAL 1 HOUR)','NOW()'],['',"datetime('2026-10-10 12:00:00','+1 hour')","'2026-10-10 12:00:00'"],$sql),$options);
        }
    }
    $pdo=new InvitationPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE,password_hash TEXT,role TEXT,full_name TEXT,email TEXT UNIQUE,ref_id TEXT,status TEXT DEFAULT 'Active',must_change_password INTEGER DEFAULT 0)");
    foreach(['students'=>'student_id','advisers'=>'employee_id'] as $table=>$idField)$pdo->exec("CREATE TABLE $table(id INTEGER PRIMARY KEY AUTOINCREMENT,$idField TEXT UNIQUE,full_name TEXT,email TEXT UNIQUE,user_id INTEGER,adviser_id INTEGER,status TEXT DEFAULT 'Active',profile_completed_at TEXT,archived_at TEXT)");
    $pdo->exec('CREATE TABLE account_invitations(user_id INTEGER,invited_by_user_id INTEGER,token_hash TEXT,expires_at TEXT,last_sent_at TEXT,accepted_at TEXT)');
    $pdo->exec('CREATE TABLE activity_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_email TEXT,action TEXT,details TEXT,actor_name TEXT,actor_role TEXT,entity_type TEXT,entity_id TEXT,before_value TEXT,after_value TEXT,created_at TEXT)');
    $pdo->exec("INSERT INTO users(id,username,role,full_name,email,ref_id) VALUES(1,'ADMIN','admin','Admin','admin@example.test','ADMIN'),(2,'A2','adviser','Adviser','adviser@example.test','A2')");
    $pdo->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id,profile_completed_at) VALUES(2,'A2','Adviser','adviser@example.test',2,'2026-01-01')");
    if(!empty($case['duplicate'])) {
        $pdo->exec("INSERT INTO users(id,username,role,full_name,email) VALUES(3,'DUP','student','Existing','duplicate@example.test')");
        $pdo->exec("INSERT INTO students(id,student_id,email,user_id,profile_completed_at) VALUES(3,'DUP','duplicate@example.test',3,".($case['duplicate']==='pending'?'NULL':"'2026-01-01'").")");
    }
    $actor=$pdo->query('SELECT * FROM users WHERE id='.(($case['role']??'admin')==='adviser'?2:1))->fetch();
    if(($case['role']??'')==='anonymous')$actor=null;
    if(!empty($case['pendingAdviser']))$pdo->exec('UPDATE advisers SET profile_completed_at=NULL');
    $_SESSION=[];$_GET=['action'=>'invite'];
    $_SERVER=['REQUEST_METHOD'=>$case['method']??'POST','HTTP_HOST'=>'prism.test','HTTP_ORIGIN'=>$case['origin']??'http://prism.test','SCRIPT_NAME'=>'account_invitation_api.php'];
    function db(): PDO{return $GLOBALS['pdo'];}
    function current_user(): ?array{return $GLOBALS['actor'];}
    function json_body(bool $strict=false): array{return $GLOBALS['case']['data'];}
    function consume_auth_attempt(...$args): bool{return true;}
    function send_notification_email(...$args): array{return ['ok'=>false];}
    function log_api_error(...$args): void{$GLOBALS['fixtureErrors'][]=$args;}
    function json_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data);exit;}
    require __DIR__.'/../security.php';
    require __DIR__.'/../includes/account_onboarding.php';
    $config=file_get_contents(__DIR__.'/../config.php');
    eval(source_function($config,'api_require_login').source_function($config,'require_post_same_origin'));
    ob_start();register_shutdown_function(function(){
        $out=ob_get_clean();echo json_encode(['status'=>http_response_code()?:200,'response'=>json_decode($out,true),
            'students'=>$GLOBALS['pdo']->query('SELECT * FROM students')->fetchAll(),'advisers'=>$GLOBALS['pdo']->query('SELECT * FROM advisers')->fetchAll(),
            'invitations'=>$GLOBALS['pdo']->query('SELECT * FROM account_invitations')->fetchAll(),'transaction'=>$GLOBALS['pdo']->inTransaction(),'errors'=>$GLOBALS['fixtureErrors']??[]]);
    });
    $source=file_get_contents(__DIR__.'/../account_invitation_api.php');
    $source=str_replace(["require __DIR__.'/config.php';","require_once __DIR__.'/includes/account_lifecycle.php';"],'',$source);
    $source=str_replace('catch(Throwable $e) {','catch(Throwable $e) { $GLOBALS["fixtureErrors"][]=$e->getMessage();',$source);
    eval(preg_replace('/^<\?php\s*/','',$source));exit;
}
$checks=0;
function verify(bool $ok,string $label): void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label);}
function invite_case(array $case): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($p)===0 && $err==='','No endpoint warning: '.$err.$out);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    verify(!$r['transaction'],'Request closes transaction');return $r;
}
foreach(['admin','adviser'] as $role) {
    foreach(['new@example.test','new@EXAMPLE.TEST','  NEW@Example.Test  ','new@example.invalid'] as $email) {
        $r=invite_case(['role'=>$role,'data'=>['accountType'=>'student','email'=>$email]]);
        verify($r['status']===200 && count($r['invitations'])===1,'Authorized invitation creates one Pending identity: '.json_encode($r));
        $s=$r['students'][0];verify($s['email']===strtolower(trim($email)) && $s['student_id']===null && $s['profile_completed_at']===null,'Normalization and Pending lifecycle preserved');
        verify($s['adviser_id']===($role==='adviser'?2:null),'Assignment stays server-derived');
    }
    foreach(['new@fakeexample.test','new@example.test.attacker.com','new@dept.example.test','new@other.test','new@example.test.','bad-email','x@@example.test',[],['email'=>'new@example.test']] as $email) {
        $r=invite_case(['role'=>$role,'data'=>['accountType'=>'student','email'=>$email]]);
        verify($r['status']===422 && !$r['students'] && !$r['invitations'],'Direct HTTP-shaped bypass rejects malformed, lookalike and subdomain addresses');
    }
    foreach(['active','pending'] as $duplicate) {
        $r=invite_case(['role'=>$role,'duplicate'=>$duplicate,'data'=>['accountType'=>'student','email'=>'duplicate@example.test']]);
        verify($r['status']===409 && count($r['students'])===1 && !$r['invitations'],'Duplicates remain rejected without recreation');
    }
}
$r=invite_case(['role'=>'adviser','data'=>['accountType'=>'student','email'=>'new@example.test','adviserId'=>999]]);
verify($r['status']===422 && !$r['students'],'Forged Adviser assignment rejected');
$r=invite_case(['role'=>'admin','data'=>['accountType'=>'student','email'=>'new@example.test','adviserId'=>2]]);
verify($r['status']===200 && $r['students'][0]['adviser_id']===2,'Admin retains authorized assignment');
foreach([['role'=>'adviser','pendingAdviser'=>true],['role'=>'anonymous'],['method'=>'GET'],['origin'=>'https://evil.test']] as $guard) {
    $r=invite_case($guard+['data'=>['accountType'=>'student','email'=>'new@example.test']]);verify(in_array($r['status'],[401,403,405],true) && !$r['students'],'Existing origin/method/role/completion guards');
}
foreach(['admin'=>200,'adviser'=>403] as $role=>$status)verify(invite_case(['role'=>$role,'data'=>['accountType'=>'adviser','email'=>'new@example.test']])['status']===$status,'Adviser invitation permission unchanged');
echo "PASS: $checks Batch 3 invitation/API/domain/assignment assertions; volatile SQL only.\n";
