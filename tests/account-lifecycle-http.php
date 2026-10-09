<?php
/** Real request/auth/session guards in a temporary copy, never the installed application. */
if(PHP_SAPI!=='cli' || !isset($datadir,$pdo,$restrictedPassword)) exit(1);
$httpRoot=dirname($datadir).'/http'; mkdir($httpRoot); mkdir($httpRoot.'/includes'); mkdir($httpRoot.'/sessions');
foreach(['config.php','security.php','auth_rate_limit.php','workflow.php','account_lifecycle_api.php','login_process.php','profile_api.php','students_api.php','advisers_api.php','ierb_api.php','ai_helpers.php'] as $file)
    copy(__DIR__.'/../'.$file,$httpRoot.'/'.$file);
foreach(glob(__DIR__.'/../includes/*') as $file) if(is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['php','json'],true)) copy($file,$httpRoot.'/includes/'.basename($file));
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $httpPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
$httpBase='http://127.0.0.1:'.$httpPort;
function remediation_http_config(bool $verified=true,bool $deniedAudit=false,?array $verification=null,?array $connection=null): void {
    global $httpRoot,$httpBase,$port,$password,$restrictedPassword;
    $values=['DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>'hardening_test_lifecycle',
        'DB_USER'=>$deniedAudit?'lifecycle_restricted':'root','DB_PASS'=>$deniedAudit?$restrictedPassword:$password,
        'APP_ENV'=>'development','APP_BASE_URL'=>$httpBase,'ALLOWED_EMAIL_DOMAINS'=>['example.invalid'],
        'GMAIL_CLIENT_ID'=>'','GMAIL_CLIENT_SECRET'=>'','GMAIL_REFRESH_TOKEN'=>'','OPENROUTER_API_KEY'=>'',
        'PRISM_HARD_DELETE_SCHEMA_VERIFIED'=>$verified,'PRISM_HARD_DELETE_VERIFICATION'=>$verification??PRISM_HARD_DELETE_VERIFICATION];
    if($connection) $values=array_replace($values,$connection);
    $source="<?php\n"; foreach($values as $key=>$value) $source.='define('.var_export($key,true).','.var_export($value,true).");\n";
    file_put_contents($httpRoot.'/config.local.php',$source);
}
function remediation_http_request(string $path,mixed $data,string &$cookie,array $options=[]): array {
    global $httpBase;
    $headers=['Accept: application/json'];
    if($cookie!=='') $headers[]='Cookie: '.$cookie;
    if(empty($options['noOrigin'])) $headers[]='Origin: '.($options['origin']??$httpBase);
    $headers[]='Content-Type: '.(!empty($options['form'])?'application/x-www-form-urlencoded':'application/json');
    $content=!empty($options['form'])?http_build_query($data):(is_string($data)?$data:json_encode($data));
    $context=stream_context_create(['http'=>['method'=>$options['method']??'POST','header'=>implode("\r\n",$headers),
        'content'=>$content,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $body=file_get_contents($httpBase.'/'.$path,false,$context); $responseHeaders=$http_response_header??[];
    preg_match('~HTTP/\S+ (\d+)~',$responseHeaders[0]??'',$match); $status=(int)($match[1]??0);
    foreach($responseHeaders as $header) if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]*)/i',$header,$m)) $cookie=$m[1];
    return ['status'=>$status,'data'=>json_decode($body,true),'headers'=>$responseHeaders,'body'=>$body];
}
function remediation_http_login(int $id): string {
    $cookie='';
    $response=remediation_http_request('login_process.php',['email'=>lifecycle_actor($id)['email'],'password'=>$GLOBALS['fixturePassword']],$cookie,['form'=>true]);
    reset_migration_expect(302,$response['status'],'Actual login redirects');
    reset_migration_expect(true,str_starts_with($cookie,'PHPSESSID='),'Actual session cookie issued');
    $me=remediation_http_request('profile_api.php?action=me',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$me['status'],'Production current_user accepts authenticated session');
    return $cookie;
}
remediation_http_config();
$httpServer=proc_open([PHP_BINARY,'-d','display_errors=0','-d','disable_functions=mail,curl_exec','-d','session.save_path='.$httpRoot.'/sessions','-S','127.0.0.1:'.$httpPort,'-t',$httpRoot],
    [0=>['pipe','r'],1=>['file',$httpRoot.'/http.log','a'],2=>['file',$httpRoot.'/http.log','a']],$pipes,$httpRoot,null,['bypass_shell'=>true,'create_new_console'=>false]);
if(!is_resource($httpServer)) throw new RuntimeException('Cannot start isolated HTTP server'); fclose($pipes[0]);
try {
    $deadline=microtime(true)+10;
    do { $ready=@stream_socket_client('tcp://127.0.0.1:'.$httpPort,$errno,$error,0.1); if($ready) { fclose($ready); break; } usleep(100000); } while(microtime(true)<$deadline);
    reset_migration_expect(true,(bool)$ready,'Isolated HTTP server ready');
    lifecycle_reset_fixture(); $cookie='';
    reset_migration_expect(401,remediation_http_request('account_lifecycle_api.php',lifecycle_input(),$cookie)['status'],'Missing session denied by actual api_require_login');
    $cookie=remediation_http_login(1);
    $availability=remediation_http_request('account_lifecycle_api.php?action=availability',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$availability['status'],'Actual authenticated read-only availability endpoint');
    reset_migration_expect(true,$availability['data']['available'],'Actual global evidence availability');
    foreach([
        ['method'=>'GET'],['method'=>'PUT'],['origin'=>'https://attacker.invalid'],['noOrigin'=>true],
    ] as $options) reset_migration_expect(isset($options['method'])?405:403,remediation_http_request('account_lifecycle_api.php',lifecycle_input(),$cookie,$options)['status'],'Actual method/origin guard');
    foreach(['{','[]','null'] as $body) reset_migration_expect(400,remediation_http_request('account_lifecycle_api.php',$body,$cookie)['status'],'Actual malformed JSON 400');
    $missing=lifecycle_input(); $missing['targetId']=999;
    reset_migration_expect(404,remediation_http_request('account_lifecycle_api.php',$missing,$cookie)['status'],'Actual missing target 404');
    foreach(['currentPassword'=>'wrong','confirmation'=>'wrong','confirmed'=>false] as $field=>$value) { $input=lifecycle_input(); $input[$field]=$value; reset_migration_expect(422,remediation_http_request('account_lifecycle_api.php',$input,$cookie)['status'],'Actual validation 422'); }
    $other=lifecycle_input('admin'); $other['targetId']=2;
    reset_migration_expect(403,remediation_http_request('account_lifecycle_api.php',$other,$cookie)['status'],'Actual self-only Admin authorization');
    remediation_http_config(false);
    $availability=remediation_http_request('account_lifecycle_api.php?action=availability',[],$cookie,['method'=>'GET']);
    reset_migration_expect(false,$availability['data']['available'],'Actual unavailable gate is visible to Admin');
    reset_migration_expect(409,remediation_http_request('account_lifecycle_api.php',lifecycle_input(),$cookie)['status'],'Actual default-disabled gate 409'); remediation_http_config();
    // A real lock timeout is retryable, unlike denied persistence. Nothing is deleted.
    $pdo->exec('SET GLOBAL innodb_lock_wait_timeout=1'); $pdo->beginTransaction();
    $pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
    try {
        $timeout=remediation_http_request('account_lifecycle_api.php',lifecycle_input(),$cookie);
        reset_migration_expect(503,$timeout['status'],'Actual retryable row-lock timeout 503');
        reset_migration_expect(false,str_contains($timeout['body'],'SQLSTATE'),'Actual 503 sanitizes SQL diagnostics');
    } finally { $pdo->rollBack(); $pdo->exec('SET GLOBAL innodb_lock_wait_timeout=50'); }
    foreach([100,200] as $id) {
        lifecycle_reset_fixture(); $pdo->exec("UPDATE users SET status='Active' WHERE id=$id");
        $roleCookie=remediation_http_login($id);
        reset_migration_expect(403,remediation_http_request('account_lifecycle_api.php',lifecycle_input(),$roleCookie)['status'],'Actual non-Admin role denied');
    }
    // Creation establishes provenance only for records genuinely created in this transaction.
    foreach(['students_api.php','ierb_api.php','advisers_api.php'] as $endpoint) {
        remediation_http_config(); lifecycle_reset_fixture(); $cookie=remediation_http_login(1);
        $advisor=$endpoint==='advisers_api.php';
        $input=['id'=>0,'studentId'=>'NEW-S','employeeId'=>'NEW-E','name'=>'New HTTP Identity','email'=>'new@example.invalid',
            'stage'=>'Stage 1','status'=>$advisor?'Active':'On Track','department'=>'Accountancy / Management / Technology',
            'academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027','groupId'=>'__create__'];
        $response=remediation_http_request($endpoint.'?action=save',$input,$cookie);
        reset_migration_expect(200,$response['status'],'Actual transactional creation '.$endpoint);
        $newId=$response['data']['id'];
        reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='account_identity_created' AND entity_type='".($advisor?'adviser':'student')."' AND entity_id='$newId'")->fetchColumn(),'Actual new record receives immutable creation marker');
        if($endpoint==='students_api.php') {
            // Exact production endpoint use case, with no global metadata grants on the web/verifier user.
            $httpScopedPassword=bin2hex(random_bytes(24));
            $pdo->exec("CREATE USER 'lifecycle_http_scoped'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($httpScopedPassword));
            $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON hardening_test_lifecycle.* TO 'lifecycle_http_scoped'@'127.0.0.1'");
            if(in_array('test',$pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN),true)) $pdo->exec('DROP DATABASE test');
            $httpScoped=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','lifecycle_http_scoped',$httpScopedPassword,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $httpScoped->exec('SET TRANSACTION READ ONLY'); $httpScoped->beginTransaction();
            $httpEvidence=lifecycle_shared_hosting_verification($httpScoped,true); $httpScoped->rollBack();
            remediation_http_config(true,false,$httpEvidence,['DB_USER'=>'lifecycle_http_scoped','DB_PASS'=>$httpScopedPassword]);
            $availability=remediation_http_request('account_lifecycle_api.php?action=availability',[],$cookie,['method'=>'GET']);
            reset_migration_expect(true,$availability['data']['available'],'Actual HTTP shared-hosting availability succeeds');
            reset_migration_expect('schema_scoped_shared_hosting',$availability['data']['verificationMode'],'Actual HTTP availability records scoped mode');
            reset_migration_expect(200,remediation_http_request('students_api.php?action=delete',['id'=>$newId],$cookie)['status'],'New Student archived by actual Admin endpoint');
            $delete=lifecycle_input(); $delete['targetId']=$newId; $delete['confirmation']='NEW-S';
            $result=remediation_http_request('account_lifecycle_api.php',$delete,$cookie);
            reset_migration_expect(200,$result['status'],'Newly created untouched archived Student permanently deleted with scoped verification: '.$result['body']);
            reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id='.(int)$newId)->fetchColumn(),'Real HTTP-created Student removed');
            reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='student_permanently_deleted' AND entity_id=".$pdo->quote((string)$newId))->fetchColumn(),'Real HTTP deletion audit retained');
            remediation_http_config();
        }
    }
    // Actual identity writers, with real rollback when mandatory evidence cannot be inserted.
    foreach(['students_api.php','ierb_api.php','advisers_api.php'] as $endpoint) foreach([false,true] as $deny) {
        remediation_http_config(true,$deny); lifecycle_reset_fixture(); $cookie=remediation_http_login(1);
        $advisor=$endpoint==='advisers_api.php'; $id=$advisor?200:100; $table=$advisor?'advisers':'students';
        $input=['id'=>$id,'studentId'=>'S100','employeeId'=>'E200','name'=>'HTTP Renamed Identity','email'=>$advisor?'adviser@example.invalid':'student@example.invalid',
            'stage'=>'Stage 1','status'=>$advisor?'Inactive':'On Track','department'=>''];
        $before=remediation_snapshot(); $response=remediation_http_request($endpoint.'?action=save',$input,$cookie);
        reset_migration_expect($deny?500:200,$response['status'],'Actual identity edit '.$endpoint.' with audit '.($deny?'denied':'allowed'));
        if($deny) reset_migration_expect($before,remediation_snapshot(),'Actual endpoint provenance denial rolls back edit');
        else {
            reset_migration_expect('HTTP Renamed Identity',$pdo->query("SELECT full_name FROM $table WHERE id=$id")->fetchColumn(),'Actual identity edit committed');
            reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='account_identity_changed' AND entity_id='$id'")->fetchColumn(),'Actual endpoint immutable provenance persisted');
        }
    }
    foreach(['student'=>100,'adviser'=>200] as $role=>$id) foreach([false,true] as $deny) {
        remediation_http_config(true,$deny); lifecycle_reset_fixture(); $pdo->exec("UPDATE users SET status='Active' WHERE id=$id");
        $cookie=remediation_http_login($id); $before=remediation_snapshot();
        $response=remediation_http_request('profile_api.php?action=update_profile',['name'=>'HTTP Profile Rename'],$cookie);
        reset_migration_expect($deny?500:200,$response['status'],'Actual profile provenance '.$role);
        if($deny) reset_migration_expect($before,remediation_snapshot(),'Actual profile rollback on mandatory evidence failure');
        else reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='account_identity_changed' AND entity_type='$role' AND entity_id='$id'")->fetchColumn(),'Actual profile immutable provenance');
    }
    foreach([['student','permanent_delete'],['adviser','permanent_delete'],['admin','archive'],['admin','permanent_delete']] as [$type,$action]) {
        remediation_http_config(true,true); lifecycle_reset_fixture(); $cookie=remediation_http_login(1); $before=remediation_snapshot();
        $response=remediation_http_request('account_lifecycle_api.php',lifecycle_input($type,$action),$cookie);
        reset_migration_expect(500,$response['status'],'Actual lifecycle denied audit INSERT '.$type.'/'.$action);
        reset_migration_expect($before,remediation_snapshot(),'Actual lifecycle endpoint failure fully rolls back');
        reset_migration_expect(false,(bool)preg_match('/SQLSTATE|INSERT|lifecycle_restricted|activity_logs|password_hash|stack trace/i',$response['body']),'Actual 500 does not expose diagnostics');
    }
    foreach(['archive','permanent_delete'] as $action) {
        remediation_http_config($action!=='archive'); lifecycle_reset_fixture(); $cookie=remediation_http_login(1); $replay=$cookie;
        $response=remediation_http_request('account_lifecycle_api.php',lifecycle_input('admin',$action),$cookie);
        reset_migration_expect(200,$response['status'],'Actual Admin self lifecycle success');
        reset_migration_expect(true,$response['data']['logout'],'Actual endpoint requests logout');
        reset_migration_expect(true,(bool)preg_match('/Set-Cookie: PHPSESSID=deleted;.*(?:expires=|Max-Age=0)/i',implode("\n",$response['headers'])),'Actual session cookie expired');
        reset_migration_expect(401,remediation_http_request('profile_api.php?action=me',[],$replay,['method'=>'GET'])['status'],'Old session cannot be replayed');
        reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='Active'")->fetchColumn(),'Actual self lifecycle retains active Admin');
    }
} finally { proc_terminate($httpServer); proc_close($httpServer); }
remediation_http_config();
