<?php
/** Real Support handlers/auth guards in isolated SQLite, with a counting mail transport. No config bootstrap. */
if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/notification-v10-fixture.php';
require_once __DIR__.'/../includes/notification_delivery.php';
require_once __DIR__.'/../includes/help_support.php';
require_once __DIR__.'/../includes/account_onboarding.php';
require_once __DIR__.'/../includes/legal_policy.php';
require_once __DIR__.'/../security.php';
function db(): PDO { return $GLOBALS['pdo']; }
function current_user(): ?array { return $GLOBALS['user']; }
function json_body(): array { return $GLOBALS['case']['data']??[]; }
function json_out(array $data,int $status=200): never { http_response_code($status); echo json_encode($data); exit; }
function log_api_error(...$args): void { $GLOBALS['errors'][]=$args; }
function send_notification_email(...$args): array { $GLOBALS['mailCalls']++; return ['ok'=>true,'channel'=>'gmail']; }
function b8_function(string $source,string $name): string {
    $a=strpos($source,'function '.$name.'('); $b=strpos($source,"\n}\n",$a);
    if ($a===false || $b===false) throw new RuntimeException('Guard not found.'); return substr($source,$a,$b+2-$a);
}
$config=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../config.php'));
eval(b8_function($config,'legal_acceptance_required').b8_function($config,'api_require_login').b8_function($config,'require_post_same_origin'));
function b8_seed(): PDO {
    $pdo=new NotificationV10FixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    notification_fixture_sqlite($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INTEGER DEFAULT 0');
    foreach ([1=>'admin',2=>'admin',3=>'student',4=>'student',5=>'adviser',6=>'admin'] as $id=>$role) {
        $pdo->prepare('INSERT INTO users(id,email,full_name,role,status,username,ref_id) VALUES (?,?,?,?,?,?,?)')->execute([$id,"u$id@example.invalid","User $id",$role,$id===6?'Inactive':'Active',"U$id","U$id"]);
        if ($role!=='admin') {
            $table=$role==='student'?'students':'advisers'; $field=$role==='student'?'student_id':'employee_id';
            $pdo->prepare("INSERT INTO $table(id,user_id,email,full_name,$field,profile_completed_at) VALUES (?,?,?,?,?,NULL)")->execute([$id,$id,"u$id@example.invalid","User $id","U$id"]);
        }
    }
    $pdo->exec("INSERT INTO notification_campaigns(id,sender_user_id,kind,support_category,reply_to_campaign_id,subject,message,status) VALUES
        (1,3,'support','Account/Access',NULL,'Request subject','PRIVATE SUPPORT SENTINEL','Sent'),
        (2,1,'support','Account/Access',1,'Response subject','PRIVATE RESPONSE SENTINEL','Sent'),
        (3,1,'staff',NULL,NULL,'Staff subject','STAFF BODY','Sent')");
    foreach ([[1,1,1,'Support Request'],[2,2,1,'Support Request'],[3,3,2,'Support Response'],[4,4,2,'Support Response'],[5,3,3,'Status Update']] as [$id,$uid,$cid,$type]) {
        $u=$pdo->query('SELECT * FROM users WHERE id='.$uid)->fetch();
        $pdo->prepare("INSERT INTO notifications(id,campaign_id,recipient_user_id,recipient_id,recipient_type,recipient_email,recipient_name,subject,message,type,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,'Sent','User 1')")->execute([$id,$cid,$uid,$uid,$u['role'],$u['email'],$u['full_name'],'Literal <img src=x>','PRIVATE SUPPORT SENTINEL',$type]);
    }
    $pdo->exec("CREATE TABLE legal_policies(id TEXT PRIMARY KEY,current_version_id INTEGER);
        CREATE TABLE legal_policy_versions(id INTEGER PRIMARY KEY,policy_id TEXT,content TEXT,content_sha256 TEXT,state TEXT,published_at TEXT);
        CREATE TABLE legal_policy_approvals(version_id INTEGER,decision TEXT);
        CREATE TABLE user_policy_acceptances(user_id INTEGER,policy_version_id INTEGER)");
    foreach (['privacy','terms'] as $i=>$key) {
        $id=$i+1;$body='Published '.$key;
        $pdo->prepare('INSERT INTO legal_policies VALUES (?,?)')->execute([$key,$id]);
        $pdo->prepare("INSERT INTO legal_policy_versions VALUES (?,?,?,?,'published','2026-01-01')")->execute([$id,$key,$body,hash('sha256',$body)]);
        $pdo->prepare("INSERT INTO legal_policy_approvals VALUES (?,'approved')")->execute([$id]);
        foreach (range(1,6) as $uid) $pdo->prepare('INSERT INTO user_policy_acceptances VALUES (?,?)')->execute([$uid,$id]);
    }
    return $pdo;
}
if (($argv[1]??'')==='--case') {
    $case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR); $pdo=b8_seed(); $errors=[]; $mailCalls=0;
    $actor=$case['actor']??3;$user=$pdo->query('SELECT * FROM users WHERE id='.(int)$actor)->fetch();
    if (!empty($case['anonymous'])) $user=null;
    if (!empty($case['complete'])) $pdo->exec("UPDATE students SET profile_completed_at='2026-01-01';UPDATE advisers SET profile_completed_at='2026-01-01'");
    if (!empty($case['archived'])) $pdo->exec("UPDATE students SET archived_at='2026-01-01';UPDATE advisers SET archived_at='2026-01-01'");
    if (!empty($case['inactive'])) { $pdo->exec('UPDATE users SET status="Inactive" WHERE id='.(int)$actor);$user['status']='Inactive'; }
    if (!empty($case['legalMissing'])) $pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id='.(int)$actor);
    if (!empty($case['zeroAdmin'])) $pdo->exec("UPDATE users SET status='Inactive' WHERE role='admin'");
    if (!empty($case['copyDeleted'])) $pdo->exec("UPDATE notifications SET deleted_at='2026-01-01' WHERE id=1");
    $_SESSION=['user_id'=>$user['id']??null,'login_generation'=>str_repeat('a',32),'support_send_token'=>'fixture-token','must_change_password'=>$case['password']??0];
    $file=$case['file']??'help_support_api.php';
    $_GET=['action'=>$case['action']??'responses']+($case['query']??[]);
    $_SERVER=['SCRIPT_NAME'=>$case['guard_script']??$file,'REQUEST_METHOD'=>$case['method']??'POST','HTTP_HOST'=>'prism.test','HTTP_ORIGIN'=>$case['origin']??'http://prism.test'];
    if (empty($case['missingMarker'])) $_SERVER['HTTP_X_PRISM_GENERATION']=str_repeat(!empty($case['staleMarker'])?'b':'a',32);
    ob_start();register_shutdown_function(function() {
        $raw=ob_get_clean();echo json_encode(['status'=>http_response_code()?:200,'body'=>json_decode($raw,true),'raw'=>$raw,
            'copies'=>db()->query('SELECT * FROM notifications')->fetchAll(),'campaigns'=>db()->query('SELECT * FROM notification_campaigns')->fetchAll(),
            'audit'=>db()->query('SELECT * FROM activity_logs')->fetchAll(),'mail'=>$GLOBALS['mailCalls'],'errors'=>$GLOBALS['errors']]);
    });
    $source=file_get_contents(__DIR__.'/../'.$file);
    foreach (["require __DIR__.'/config.php';","require __DIR__ . '/config.php';","require_once __DIR__.'/includes/help_support.php';",
        "require_once __DIR__ . '/workflow.php';","require_once __DIR__ . '/includes/notification_delivery.php';","require_once __DIR__ . '/includes/research_groups.php';"] as $include) $source=str_replace($include,'',$source);
    if (preg_match('/\b(?:require|include)(?:_once)?\s/',$source)) throw new RuntimeException('Unexpected bootstrap in Support fixture.');
    eval(preg_replace('/^<\?php\s*/','',$source));exit;
}
$checks=0;
function b8_check(bool $ok,string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
function b8_case(array $case): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    b8_check($exit===0 && $err==='','Fixture completes cleanly: '.$err.$raw);return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
}
$request=['category'=>'Account/Access','subject'=>'<img src=x>','message'=>"PRIVATE SUPPORT SENTINEL\nLiteral <script>text</script>",'request_token'=>'fixture-token'];
foreach ([3,5] as $actor) foreach ([false,true] as $complete) {
    $r=b8_case(['actor'=>$actor,'complete'=>$complete,'action'=>'submit','data'=>$request]);
    b8_check($r['status']===200 && count($r['campaigns'])===4 && count($r['copies'])===7,'Pending and complete Student/Adviser can submit');
    b8_check($r['campaigns'][3]['sender_user_id']===$actor && $r['campaigns'][3]['kind']==='support','Authenticated campaign sender');
    b8_check(array_column(array_slice($r['copies'],5),'recipient_user_id')===[1,2],'Every currently active Admin gets its own copy; inactive Admin excluded');
    b8_check($r['mail']===0 && !str_contains(json_encode($r['audit']),'SENTINEL') && !str_contains(json_encode($r['audit']),'<img'),'No email or audit content');
}
foreach ([['zeroAdmin'=>true],['archived'=>true],['inactive'=>true],['password'=>1],['legalMissing'=>true],['anonymous'=>true],['actor'=>1],['origin'=>'http://evil.invalid'],['missingMarker'=>true],['staleMarker'=>true],['method'=>'GET']] as $attack) {
    $r=b8_case($attack+['action'=>'submit','data'=>$request]);b8_check($r['status']>=400 && count($r['campaigns'])===3 && count($r['copies'])===5 && !$r['audit'] && !$r['mail'],'Rejected request has no side effects');
}
foreach ([['category'=>'arbitrary'],['subject'=>''],['subject'=>"a\0b"],['subject'=>str_repeat('s',256)],['message'=>str_repeat('m',601)],['message'=>"a\x01b"],['sender_user_id'=>4],['recipient_ids'=>[6]],['reply_to_campaign_id'=>1],['request_token'=>'used-token']] as $attack) {
    $r=b8_case(['action'=>'submit','data'=>array_replace($request,$attack)]);b8_check($r['status']>=400 && count($r['campaigns'])===3,'Forged fields, category, controls, lengths and replay rejected');
}
$response=['request_id'=>1,'subject'=>'Response','message'=>'PRIVATE RESPONSE SENTINEL','request_token'=>'fixture-token'];
$r=b8_case(['file'=>'notifications_api.php','actor'=>1,'action'=>'support_respond','data'=>$response]);
b8_check($r['status']===200 && $r['copies'][5]['recipient_user_id']===3 && $r['campaigns'][3]['reply_to_campaign_id']===1,'Admin response resolves original Pending requester');
b8_check($r['mail']===0 && $r['campaigns'][3]['support_category']==='Account/Access','Response in-app only and original category preserved');
foreach ([['actor'=>2],['actor'=>3],['copyDeleted'=>true],['archived'=>true],['origin'=>'http://evil.invalid'],['staleMarker'=>true],['missingMarker'=>true],['legalMissing'=>true]] as $attack) {
    $r=b8_case($attack+['file'=>'notifications_api.php','actor'=>1,'action'=>'support_respond','data'=>$response]);b8_check($r['status']>=400 && count($r['campaigns'])===3,'Response ownership, current requester and higher gates');
}
foreach ([['recipient_user_id'=>4],['reply_to_campaign_id'=>2],['sender_user_id'=>2],['category'=>'Other'],['request_id'=>3]] as $attack) {
    $r=b8_case(['file'=>'notifications_api.php','actor'=>1,'action'=>'support_respond','data'=>array_replace($response,$attack)]);b8_check($r['status']>=400,'Response recipient/correlation cannot be forged');
}
$r=b8_case(['action'=>'responses']);b8_check(array_column($r['body']['notifications'],'id')===[3] && $r['body']['unread_count']===1,'Pending reads only own support responses and support unread total');
$r=b8_case(['action'=>'requests']);b8_check(array_column($r['body']['notifications'],'id')===[1],'Requester sees only own original Support history');
foreach (['requests','responses'] as $action) foreach ([['missingMarker'=>true],['staleMarker'=>true],['legalMissing'=>true],['password'=>1]] as $attack) {
    $r=b8_case($attack+['action'=>$action]);b8_check($r['status']>=400 && $r['body']['ok']===false,'Support reads preserve generation/legal/password gates');
}
foreach (['documents_api.php','ierb_api.php','reports_api.php','notifications_api.php'] as $script) {
    $r=b8_case(['file'=>'notifications_api.php','guard_script'=>$script,'action'=>'list']);b8_check($r['status']===403,'Pending workspace guard remains blocked: '.$script);
}
foreach ([['action'=>'mark_read','data'=>['id'=>4]],['action'=>'archive','data'=>['id'=>1]],['action'=>'archive','data'=>['id'=>5]],['action'=>'campaign_archive','data'=>['id'=>2]]] as $attack) {
    $r=b8_case($attack);b8_check($r['status']===404 && !$r['audit'],'Cross-user/Admin/staff/sender Support actions denied');
}
// Exercise the actual helpers together for replay, snapshot, copies, lifecycle and mail-defense behavior.
$pdo=b8_seed();$mailCalls=0;$user=$pdo->query('SELECT * FROM users WHERE id=3')->fetch();$_SESSION=['support_send_token'=>'fixture-token'];
$sent=support_submit($pdo,$user,$request);$id=$sent['campaign_id'];
try { support_text("\xC3\x28",'Message',600,true);b8_check(false,'Invalid encoding must fail'); } catch (NotificationLifecycleError $e) { b8_check($e->status===422,'Invalid UTF-8 rejected'); }
try { support_submit($pdo,$user,$request);b8_check(false,'Replay must fail'); } catch (NotificationLifecycleError $e) { b8_check($e->status===409,'Same-browser duplicate POST rejected'); }
$pdo->exec("UPDATE users SET status='Active' WHERE id=6");notification_list($pdo,$user,[],'support_requests');
b8_check((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE campaign_id='.$id)->fetchColumn()===2,'Later activated Admin never gets historical copy');
$a=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();$b=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();
$copies=notification_rows($pdo,'SELECT id FROM notifications WHERE campaign_id=? ORDER BY id',[$id]);
$first=support_respond($pdo,$a,['request_id'=>(int)$copies[0]['id'],'subject'=>'First Admin reply','message'=>'FIRST PRIVATE RESPONSE','request_token'=>support_token()]);
notification_transition($pdo,$a,'mark_read',(int)$copies[0]['id']);notification_transition($pdo,$a,'archive',(int)$copies[0]['id']);notification_transition($pdo,$a,'restore',(int)$copies[0]['id']);notification_transition($pdo,$a,'archive',(int)$copies[0]['id']);notification_transition($pdo,$a,'delete',(int)$copies[0]['id'],true);
b8_check($pdo->query('SELECT read_at FROM notifications WHERE id='.(int)$copies[1]['id'])->fetchColumn()===null,'Independent Admin lifecycle');
support_transition($pdo,$user,'campaign_archive',['id'=>$id]);support_transition($pdo,$user,'campaign_delete',['id'=>$id,'confirmed'=>true]);
b8_check($pdo->query('SELECT message FROM notifications WHERE id='.(int)$copies[1]['id'])->fetchColumn()===$request['message'],'Student sender deletion preserves Admin copy');
$reply=support_respond($pdo,$b,['request_id'=>(int)$copies[1]['id'],'subject'=>'Reply','message'=>'PRIVATE RESPONSE SENTINEL','request_token'=>support_token()]);
$another=support_respond($pdo,$b,['request_id'=>(int)$copies[1]['id'],'subject'=>'Another independent reply','message'=>'SECOND PRIVATE RESPONSE','request_token'=>support_token()]);
b8_check(notification_list($pdo,$user,[],'support_responses')['total']===4,'Multiple Admin responses remain independent messages');
$responseId=(int)$pdo->query('SELECT id FROM notifications WHERE campaign_id='.(int)$reply['campaign_id'])->fetchColumn();
support_transition($pdo,$user,'archive',['id'=>$responseId]);support_transition($pdo,$user,'restore',['id'=>$responseId]);support_transition($pdo,$user,'mark_read',['id'=>$responseId]);
$pdo->exec("UPDATE notifications SET status='Scheduled',scheduled_at='2020-01-01' WHERE id=$responseId");notification_dispatch($pdo,$responseId);
$pdo->exec("UPDATE notifications SET status='Sending' WHERE id=$responseId");deliver_notification_email($pdo,$responseId,'no@example.invalid','PRIVATE SUBJECT','PRIVATE BODY');
b8_check($mailCalls===0,'Even accidental worker and low-level dispatch cannot email Support or write a fallback');
foreach (['ai_helpers.php','reports_api.php','data_exports_api.php','includes/csv_export.php','includes/document_summary.php'] as $file) {
    $source=file_get_contents(__DIR__.'/../'.$file);
    b8_check(!preg_match('/(?:FROM|JOIN)\s+(?:notification_campaigns|notifications)\b/i',$source),'Research/AI/export source never ingests notification bodies: '.$file);
    b8_check(!str_contains(json_encode(['source'=>$source,'research_rows'=>$pdo->query('SELECT * FROM students')->fetchAll()]),'PRIVATE SUPPORT SENTINEL'),'Sentinel absent from research source data');
}
echo "PASS: $checks Batch 8 Support checks\n";
