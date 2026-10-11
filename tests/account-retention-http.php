<?php
/** Actual login/session/origin/API behavior in a disposable application copy. */
$httpRoot=$root.'/retention-http';mkdir($httpRoot);mkdir($httpRoot.'/includes');mkdir($httpRoot.'/sessions');
foreach (['config.php','security.php','auth_rate_limit.php','workflow.php','account_lifecycle_api.php','login_process.php','profile_api.php',
    'send_followup.php','account_invitation_api.php','account_setup.php','complete_profile.php','index.php','calendar_deadlines_api.php','reports_api.php','students_api.php','advisers_api.php','documents_api.php','ai_helpers.php','forgot_password_process.php','forgot_password.php','reset_password.php','update_password.php','notifications_api.php','data_exports_api.php','ierb_api.php'] as $file) copy(__DIR__.'/../'.$file,$httpRoot.'/'.$file);
foreach (glob(__DIR__.'/../includes/*') as $file) if (is_file($file)&&in_array(pathinfo($file,PATHINFO_EXTENSION),['php','json'],true)) copy($file,$httpRoot.'/includes/'.basename($file));
// Preserve this legacy schema fixture; B6 HTTP separately tests the full v10 gates.
$fixtureConfig=file_get_contents($httpRoot.'/config.php');
file_put_contents($httpRoot.'/config.php',str_replace('const SCHEMA_VERSION = 10;', 'const SCHEMA_VERSION = 9;', $fixtureConfig));
$fixtureLegal=file_get_contents($httpRoot.'/includes/legal_policy.php');
$fixtureLegal=preg_replace('/function legal_outstanding\([^\n]*\n\{[\s\S]*?\n\}/','function legal_outstanding(...$args): array { return []; }',$fixtureLegal,1,$legalReplacements);
if ($legalReplacements!==1) throw new RuntimeException('Exact legacy legal fixture boundary required.');
file_put_contents($httpRoot.'/includes/legal_policy.php',$fixtureLegal);
copy(__DIR__.'/../tools/schema-v9-contract.json',$httpRoot.'/includes/account_lifecycle_schema.json');
$sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$httpPort=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1);fclose($sock);
$httpBase='http://127.0.0.1:'.$httpPort;
function retention_http_config(?array $verification=null): void {
    global $httpRoot,$httpBase,$port,$password;
    $values=['DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>'hardening_test_lifecycle','DB_USER'=>'root','DB_PASS'=>$password,
        'APP_ENV'=>'development','APP_BASE_URL'=>$httpBase,'ALLOWED_EMAIL_DOMAINS'=>['example.invalid'],
        'GMAIL_CLIENT_ID'=>'','GMAIL_CLIENT_SECRET'=>'','GMAIL_REFRESH_TOKEN'=>'','OPENROUTER_API_KEY'=>'',
        'PRISM_HARD_DELETE_SCHEMA_VERIFIED'=>true,'PRISM_HARD_DELETE_VERIFICATION'=>$verification??PRISM_HARD_DELETE_VERIFICATION];
    $text="<?php\n";foreach($values as $key=>$value)$text.='define('.var_export($key,true).','.var_export($value,true).");\n";
    file_put_contents($httpRoot.'/config.local.php',$text);
}
function retention_http_request(string $path,mixed $data,string &$cookie,array $options=[]): array {
    global $httpBase;
    $headers=['Accept: application/json','Content-Type: '.(!empty($options['form'])?'application/x-www-form-urlencoded':'application/json')];
    if ($cookie!=='')$headers[]='Cookie: '.$cookie;
    if (empty($options['noOrigin']))$headers[]='Origin: '.($options['origin']??$httpBase);
    $content=!empty($options['form'])?http_build_query($data):(is_string($data)?$data:json_encode($data));
    $context=stream_context_create(['http'=>['method'=>$options['method']??'POST','header'=>implode("\r\n",$headers),'content'=>$content,
        'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $body=file_get_contents($httpBase.'/'.$path,false,$context);$responseHeaders=$http_response_header??[];
    preg_match('~HTTP/\S+ (\d+)~',$responseHeaders[0]??'',$m);$status=(int)($m[1]??0);
    $cookies=[];
    foreach (explode('; ',$cookie) as $part) if (str_contains($part,'=')) {[$name,$value]=explode('=',$part,2);$cookies[$name]=$value;}
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: (PHPSESSID|prism_generation)=([^;]*)/i',$header,$m))$cookies[$m[1]]=$m[2];
    $cookie=implode('; ',array_map(fn($name,$value)=>$name.'='.$value,array_keys($cookies),$cookies));
    return ['status'=>$status,'data'=>json_decode($body,true),'body'=>$body,'headers'=>$responseHeaders];
}
function retention_http_login(int $id): string {
    $cookie='';$r=retention_http_request('login_process.php',['email'=>retention_test_actor($id)['email'],'password'=>$GLOBALS['fixturePassword']],$cookie,['form'=>true]);
    reset_migration_expect(302,$r['status'],'Actual login redirect');reset_migration_expect(true,str_starts_with($cookie,'PHPSESSID='),'Actual session issued');return $cookie;
}
retention_http_config();
$httpProcess=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S','127.0.0.1:'.$httpPort,'-t',$httpRoot],
    [0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$httpPipes,$httpRoot,null,['bypass_shell'=>true,'create_new_console'=>false]);
if (!is_resource($httpProcess))throw new RuntimeException('Cannot start isolated HTTP server');fclose($httpPipes[0]);
$httpStart=$GLOBALS['checks'];
try {
    $deadline=microtime(true)+8;do {$probe=@fsockopen('127.0.0.1',$httpPort,$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(10000);}while(microtime(true)<$deadline);
    retention_test_reset();$cookie=retention_http_login(1);$anonymous='';
    reset_migration_expect(401,retention_http_request('account_lifecycle_api.php',retention_test_input(),$anonymous)['status'],'Anonymous denied');
    foreach (['currentPassword'=>'wrong','confirmation'=>'wrong','confirmed'=>false] as $field=>$value) {
        $input=retention_test_input();$input[$field]=$value;
        reset_migration_expect(422,retention_http_request('account_lifecycle_api.php',$input,$cookie)['status'],'Actual single validation');
    }
    reset_migration_expect(403,retention_http_request('account_lifecycle_api.php',retention_test_input(),$cookie,['origin'=>'https://evil.invalid'])['status'],'Cross-origin mutation denied');
    reset_migration_expect(403,retention_http_request('account_lifecycle_api.php',retention_test_input(),$cookie,['noOrigin'=>true])['status'],'Missing origin evidence denied');
    reset_migration_expect(400,retention_http_request('account_lifecycle_api.php','{',$cookie)['status'],'Malformed JSON denied');
    $pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');$studentCookie=retention_http_login(100);
    reset_migration_expect(403,retention_http_request('account_lifecycle_api.php',retention_test_input(),$studentCookie)['status'],'Actual non-Admin denied');
    $restored=retention_http_request('account_lifecycle_api.php',['accountType'=>'student','action'=>'archive','targetId'=>100],$cookie);
    reset_migration_expect(200,$restored['status'],'HTTP archive');
    reset_migration_expect(401,retention_http_request('profile_api.php?action=me',[],$studentCookie,['method'=>'GET'])['status'],'Archive invalidates existing session');
    reset_migration_expect(409,retention_http_request('account_lifecycle_api.php',retention_test_input(),$cookie)['status'],'HTTP day0 refused');
    $override=retention_test_input('student','grace_period_override');$override['reason']='Synthetic exceptional cleanup';
    reset_migration_expect(200,retention_http_request('account_lifecycle_api.php',$override,$cookie)['status'],'HTTP grace override');
    foreach (['student','adviser'] as $type) foreach ([7,8] as $days) {
        retention_test_reset();$table=retention_table($type);$id=$type==='student'?100:200;
        $pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL $days DAY) WHERE id=$id");
        $input=retention_test_input($type,'grace_period_override');$input['reason']='Synthetic exceptional cleanup';
        $rejected=retention_http_request('account_lifecycle_api.php',$input,$cookie);
        reset_migration_expect(409,$rejected['status'],'Direct HTTP elapsed override rejected: '.$type.' day'.$days);
        reset_migration_expect(true,str_contains($rejected['data']['message'],'normal permanent deletion'),'HTTP elapsed override directs accurate audit method');
        reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM account_purge_jobs')->fetchColumn(),'HTTP elapsed override creates no job');
        reset_migration_expect(200,retention_http_request('account_lifecycle_api.php',retention_test_input($type),$cookie)['status'],'HTTP normal deletion at day'.$days);
    }
    foreach (['account_lifecycle_api.php','advisers_api.php?action=delete'] as $path) foreach ([0,1] as $assigned) {
        retention_test_reset();$pdo->exec('UPDATE advisers SET status="Active",archived_at=NULL WHERE id=200');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
        if ($assigned) $pdo->exec('UPDATE students SET adviser_id=200 WHERE id=100');
        $archive=['accountType'=>'adviser','targetId'=>200,'id'=>200,'action'=>'archive'];
        foreach ([[],['expectedAssignedStudents'=>null],['expectedAssignedStudents'=>(string)$assigned],['expectedAssignedStudents'=>-1],['expectedAssignedStudents'=>true],['expectedAssignedStudents'=>[]],['expectedAssignedStudents'=>1-$assigned]] as $bad) {
            reset_migration_expect(isset($bad['expectedAssignedStudents']) && is_int($bad['expectedAssignedStudents']) && $bad['expectedAssignedStudents']>=0?409:422,
                retention_http_request($path,$archive+$bad,$cookie)['status'],'Direct HTTP Adviser archive requires integer/exact impact: '.$path);
            reset_migration_expect(null,$pdo->query('SELECT archived_at FROM advisers')->fetchColumn(),'Rejected HTTP archive preserves Adviser');
            reset_migration_expect($assigned?200:null,$assigned?(int)$pdo->query('SELECT adviser_id FROM students')->fetchColumn():$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Rejected HTTP archive preserves assignment');
            reset_migration_expect('Active',$pdo->query('SELECT status FROM users WHERE id=200')->fetchColumn(),'Rejected HTTP archive preserves login');
        }
        $archived=retention_http_request($path,$archive+['expectedAssignedStudents'=>$assigned],$cookie);
        reset_migration_expect(200,$archived['status'],'Exact direct HTTP Adviser impact succeeds: '.$path);
        reset_migration_expect($assigned,$archived['data']['unassignedStudents'],'HTTP exact archive reports preview impact');
        reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'HTTP archive detaches confirmed assignments');
    }
    // Valid journals in the HTTP application's own private storage exercise recovery without JavaScript.
    foreach (['committed','rollback'] as $recoveryKind) {
        retention_test_reset();$name='http-recover-'.$recoveryKind.'.txt';$bytes='Synthetic '.$recoveryKind.' recovery bytes';
        file_put_contents(STORAGE_DIR.'/documents/'.$name,$bytes);
        $pdo->prepare('INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES (?,100,?,?,0)')->execute([$name,$name,$name]);
        $pdo->beginTransaction();$record=retention_locked_record($pdo,'student',100);
        $login=lifecycle_resolve_login($pdo,$record,'student',true);$mutexes=[];$plan=retention_purge_plan($pdo,$record,'student',$login,$mutexes);
        $prepared=purge_files_prepare($pdo,'student',100,[['kind'=>'documents','name'=>$name]]);purge_files_stage($prepared);
        if ($recoveryKind==='committed') {
            retention_purge_mutate($pdo,retention_test_actor(),$record,'student',$login,$plan,'manual',$prepared['job'],$prepared['hash'],null);$pdo->commit();
        } else $pdo->rollBack();
        foreach ([$httpRoot.'/storage',$httpRoot.'/storage/documents',$httpRoot.'/storage/.purge'] as $privateDir) if (!is_dir($privateDir)) mkdir($privateDir,0700);
        $journal=$httpRoot.'/storage/.purge/'.$prepared['job'];
        if (!rename(purge_journal_root().DIRECTORY_SEPARATOR.$prepared['job'],$journal)) throw new RuntimeException('Cannot place synthetic HTTP recovery journal');
        $recovery=['action'=>'recover','jobId'=>$prepared['job'],'currentPassword'=>$fixturePassword,'confirmed'=>true,'confirmation'=>'RECOVER '.$prepared['job']];
        $missing=$recovery;unset($missing['confirmation']);
        foreach ([$missing,array_replace($recovery,['confirmation'=>'RECOVER wrong']),array_replace($recovery,['confirmation'=>$recovery['confirmation'].' ']),
            array_replace($recovery,['currentPassword'=>'wrong']),array_replace($recovery,['confirmed'=>false]),array_replace($recovery,['jobId'=>'../crafted']),array_replace($recovery,['jobId'=>[]])] as $crafted) {
            reset_migration_expect(422,retention_http_request('account_lifecycle_api.php',$crafted,$cookie)['status'],'Crafted direct HTTP '.$recoveryKind.' recovery refused');
            reset_migration_expect($bytes,file_get_contents($journal.'/0.file'),'Crafted recovery preserves quarantined bytes');
            reset_migration_expect($recoveryKind==='committed'?0:1,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Crafted recovery preserves committed/rollback account state');
            reset_migration_expect($recoveryKind==='committed'?'files_pending':false,$pdo->query('SELECT status FROM account_purge_jobs')->fetchColumn(),'Crafted recovery preserves job state');
        }
        reset_migration_expect(200,retention_http_request('account_lifecycle_api.php',$recovery,$cookie)['status'],'Exact phrase permits actual HTTP '.$recoveryKind.' recovery');
        reset_migration_expect(false,is_dir($journal),'HTTP recovery removes finalized/restored journal');
        if ($recoveryKind==='committed') reset_migration_expect('complete',$pdo->query('SELECT status FROM account_purge_jobs')->fetchColumn(),'HTTP committed recovery completes job');
        else {
            reset_migration_expect($bytes,file_get_contents($httpRoot.'/storage/documents/'.$name),'HTTP rollback recovery restores exact bytes');
            unlink($httpRoot.'/storage/documents/'.$name);
        }
    }
    retention_test_reset();$selection=retention_http_request('account_lifecycle_api.php',['action'=>'selection','accountType'=>'student','filters'=>['lifecycle'=>'archived']],$cookie);
    reset_migration_expect(200,$selection['status'],'HTTP session-bound selection');
    $preview=retention_http_request('account_lifecycle_api.php',['action'=>'bulk_preview','selectionToken'=>$selection['data']['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>'permanent_delete'],$cookie);
    reset_migration_expect('PURGE 1 ACCOUNTS',$preview['data']['phrase'],'HTTP server phrase');
    $input=['action'=>'bulk_execute','previewToken'=>$preview['data']['previewToken'],'cursor'=>0,'currentPassword'=>$fixturePassword,'confirmation'=>$preview['data']['phrase'],'confirmed'=>true];
    $bulk=retention_http_request('account_lifecycle_api.php',$input,$cookie);reset_migration_expect(200,$bulk['status'],'HTTP bulk execute with real session checkpoints');
    reset_migration_expect(1,$bulk['data']['completed'],'HTTP committed count');
    reset_migration_expect($bulk['data'],retention_http_request('account_lifecycle_api.php',$input,$cookie)['data'],'HTTP retry idempotent');
    retention_test_reset();
    $oldEvidence=PRISM_HARD_DELETE_VERIFICATION;$oldEvidence['issued_at']=time()-86401;$oldEvidence['expires_at']=time()-1;retention_http_config($oldEvidence);
    reset_migration_expect(409,retention_http_request('account_lifecycle_api.php',retention_test_input(),$cookie)['status'],'Actual expired verification refused');retention_http_config();
    $state=retention_http_request('account_lifecycle_api.php?action=account_state&accountType=student&targetId=100',[],$cookie,['method'=>'GET']);
    reset_migration_expect(true,$state['data']['lifecycle']['manualEligible'],'Server state authoritative');
    // Real list page and sort output share canonical filter logic with selection.
    $list=retention_http_request('students_api.php?action=list&lifecycle=archived&sortBy=studentId&direction=DESC',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$list['status'],'Actual filtered list');reset_migration_expect(1,$list['data']['total'],'Actual list count');
    $advisers=retention_http_request('advisers_api.php?action=list&lifecycle=archived',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$advisers['status'],'Actual Adviser list');reset_migration_expect(true,$advisers['data']['advisers'][0]['lifecycle']['manualEligible'],'Adviser server eligibility in list');
    // Restored setup token is accepted by the actual reset-password processing route.
    $restore=retention_http_request('account_lifecycle_api.php',['accountType'=>'student','action'=>'restore','targetId'=>100],$cookie);
    reset_migration_expect(200,$restore['status'],'Actual restore');
    $passwordCookie='';$passwordResult=retention_http_request('update_password.php',['token'=>$restore['data']['setupToken'],'password'=>'New synthetic passphrase 2026','confirm_password'=>'New synthetic passphrase 2026'],$passwordCookie,['form'=>true]);
    reset_migration_expect(302,$passwordResult['status'],'Actual restore setup token accepted');
    reset_migration_expect(true,password_verify('New synthetic passphrase 2026',$pdo->query('SELECT password_hash FROM users WHERE id=100')->fetchColumn()),'Actual restored credential usable');
    reset_migration_expect(0,(int)$pdo->query('SELECT must_change_password FROM users WHERE id=100')->fetchColumn(),'Setup completes role policy');
    foreach (['READ COMMITTED','REPEATABLE READ'] as $isolation) {
        retention_test_reset();$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');
        $pdo->exec("SET GLOBAL tx_isolation='".str_replace(' ','-',$isolation)."'");
        $gate=$datadir.'/upload-archive-'.bin2hex(random_bytes(5));
        $archiver=retention_worker_start(['accountType'=>'student','targetId'=>100,'action'=>'archive'],1,['retentionGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
        $uploadFile=$root.'/synthetic-upload.pdf';copy(__DIR__.'/fixtures/batch6-valid.pdf',$uploadFile);
        $uploadFixture=['url'=>$httpBase.'/documents_api.php?action=upload','origin'=>$httpBase,'cookie'=>$cookie,'file'=>$uploadFile];
        $uploadProcess=proc_open([PHP_BINARY,__DIR__.'/account-retention-upload-worker.php',json_encode($uploadFixture)],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$uploadPipes,__DIR__,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($uploadPipes[0]);
        $uploadDeadline=microtime(true)+8;
        do {$stagedUploads=glob($httpRoot.'/storage/documents/*')?:[];if($stagedUploads)break;usleep(10000);}while(microtime(true)<$uploadDeadline);
        file_put_contents($gate,'release');
        reset_migration_expect(200,retention_worker_finish($archiver)['status'],'Actual Archive while multipart upload is running');
        reset_migration_expect(true,(bool)$stagedUploads,'Real upload reached private storage before final parent revalidation');
        $pdo->exec('UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 7 DAY) WHERE id=100');
        reset_migration_expect(true,lifecycle_execute($pdo,retention_test_actor(),retention_test_input())['ok'],'Purge racing captured upload');
        $uploadOut=stream_get_contents($uploadPipes[1]);$uploadErr=stream_get_contents($uploadPipes[2]);fclose($uploadPipes[1]);fclose($uploadPipes[2]);
        reset_migration_expect(0,proc_close($uploadProcess),'Upload worker exit '.$uploadErr);$uploaded=json_decode($uploadOut,true,512,JSON_THROW_ON_ERROR);
        reset_migration_expect(409,$uploaded['status'],'Actual upload final guard at '.$isolation);
        reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),'Upload cannot recreate purged workflow');
        reset_migration_expect([],glob($httpRoot.'/storage/documents/*')?:[],'Rejected in-flight upload file removed');
    }
    // Actual API storage (not the parent's separate fixture storage), and current CSV after purge.
    retention_test_reset();
    file_put_contents($httpRoot.'/storage/documents/http-owned.txt','Synthetic exclusively owned HTTP file');
    $pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current,ai_summary) VALUES ('http-owned',100,'Synthetic','http-owned.txt',0,'Synthetic summary')");
    reset_migration_expect(200,retention_http_request('account_lifecycle_api.php',retention_test_input(),$cookie)['status'],'Actual HTTP file purge completes');
    reset_migration_expect(false,file_exists($httpRoot.'/storage/documents/http-owned.txt'),'API finalizes file in actual app storage');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),'API removes document/summary');
    $pdo->exec('UPDATE account_purge_jobs SET status="files_pending"');
    $pending=retention_http_request('account_lifecycle_api.php?action=recovery_jobs',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$pending['status'],'Recovery list includes unfinished DB jobs');
    reset_migration_expect(false,$pending['data']['jobs'][0]['manifestAvailable'],'Missing journal explicitly surfaced instead of hiding unfinished purge');
    $pdo->exec('UPDATE account_purge_jobs SET status="complete"');
    foreach(['data_exports_api.php?action=students','data_exports_api.php?action=documents','ierb_api.php?action=export_csv'] as $path) {
        $csv=retention_http_request($path,[],$cookie,['method'=>'GET']);
        reset_migration_expect(200,$csv['status'],'Actual current export after Student purge');
        reset_migration_expect(false,str_contains($csv['body'],'S100')||str_contains($csv['body'],'Student One'),'Purged Student absent from current export');
    }
    reset_migration_expect(200,retention_http_request('account_lifecycle_api.php',retention_test_input('adviser'),$cookie)['status'],'Actual Adviser HTTP purge');
    $csv=retention_http_request('data_exports_api.php?action=advisers',[],$cookie,['method'=>'GET']);
    reset_migration_expect(200,$csv['status'],'Actual Adviser export');
    reset_migration_expect(false,str_contains($csv['body'],'E200')||str_contains($csv['body'],'Adviser One'),'Purged Adviser absent from current export/workload');
    reset_migration_expect(404,retention_http_request('students_api.php?action=delete',['id'=>999999],$cookie)['status'],'Legacy Student archive endpoint missing target still 404');
    reset_migration_expect(404,retention_http_request('ierb_api.php?action=delete',['id'=>999999],$cookie)['status'],'IERB archive endpoint missing target still 404');
    require __DIR__.'/account-onboarding-http.php';
} finally { proc_terminate($httpProcess);proc_close($httpProcess); }
echo 'PASS: actual isolated HTTP checks '.($GLOBALS['checks']-$httpStart).'; cumulative '.$GLOBALS['checks'].".\n";
