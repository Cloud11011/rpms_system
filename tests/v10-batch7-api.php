<?php
/** Real notification API and auth/origin/generation guards, in-memory fixtures; no application bootstrap. */
if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/notification-v10-fixture.php';
require_once __DIR__.'/../includes/legal_policy.php';
function db(): PDO { return $GLOBALS['pdo']; }
function current_user(): ?array { return $GLOBALS['user']; }
function onboarding_complete(PDO $pdo,array $user): bool { return empty($GLOBALS['case']['pending']); }
function json_body(): array { return $GLOBALS['case']['data']??['id'=>1,'confirmed'=>true]; }
function json_out(array $body,int $status=200): never { http_response_code($status); echo json_encode($body); exit; }
function log_api_error(...$args): void { $GLOBALS['errors'][]=$args; }
function function_source(string $source,string $name): string {
    $source=str_replace("\r\n","\n",$source); $a=strpos($source,'function '.$name.'('); $b=strpos($source,"\n}\n",$a);
    if ($a===false || $b===false) throw new RuntimeException('Missing guard.'); return substr($source,$a,$b+2-$a);
}
if (($argv[1]??'')==='--case') {
    $case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR); $errors=[];
    $pdo=new NotificationV10FixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    notification_fixture_sqlite($pdo);
    $pdo->exec('CREATE TABLE legal_policies(id TEXT PRIMARY KEY,current_version_id INTEGER);
        CREATE TABLE legal_policy_versions(id INTEGER PRIMARY KEY,policy_id TEXT,content TEXT,content_sha256 TEXT,state TEXT,published_at TEXT);
        CREATE TABLE legal_policy_approvals(version_id INTEGER,decision TEXT);
        CREATE TABLE user_policy_acceptances(user_id INTEGER,policy_version_id INTEGER)');
    foreach (['privacy','terms'] as $index=>$policy) {
        $version=$index+1;$content='Fixture published '.$policy;
        $pdo->prepare('INSERT INTO legal_policies VALUES (?,?)')->execute([$policy,$version]);
        $pdo->prepare("INSERT INTO legal_policy_versions VALUES (?,?,?,?,'published','2026-01-01')")->execute([$version,$policy,$content,hash('sha256',$content)]);
        $pdo->prepare("INSERT INTO legal_policy_approvals VALUES (?,'approved')")->execute([$version]);
        foreach ([1,2,3,4,5] as $uid) if (empty($case['legalMissing'])) $pdo->prepare('INSERT INTO user_policy_acceptances VALUES (?,?)')->execute([$uid,$version]);
    }
    foreach ([[1,'admin'],[2,'admin'],[3,'student'],[4,'student'],[5,'adviser']] as [$id,$role]) $pdo->prepare('INSERT INTO users(id,email,full_name,role,status) VALUES (?,?,?,?,?)')->execute([$id,'actor-'.$id.'@example.invalid','Actor '.$id,$role,'Active']);
    $pdo->exec("INSERT INTO notification_campaigns(id,sender_user_id,kind,subject,message,status) VALUES (1,1,'staff','PRIVATE SUBJECT','PRIVATE BODY','Sent')");
    foreach ([[1,3],[2,4]] as [$id,$uid]) $pdo->prepare("INSERT INTO notifications(id,campaign_id,recipient_user_id,recipient_type,recipient_email,subject,message,type,status,archived_at) VALUES (?,1,?,'student',?,'PRIVATE SUBJECT','PRIVATE BODY','Status Update','Sent',?)")->execute([$id,$uid,'actor-'.$uid.'@example.invalid',!empty($case['archived'])?'2026-01-01':null]);
    $user=!empty($case['anonymous'])?null:$pdo->query('SELECT * FROM users WHERE id='.(int)($case['actor']??3))->fetch();
    if (!empty($case['campaignArchived'])) $pdo->exec("UPDATE notification_campaigns SET archived_at='2026-01-01'");
    $_SESSION=['user_id'=>$user['id']??null,'login_generation'=>str_repeat('a',32)];
    $_SERVER=['SCRIPT_NAME'=>'notifications_api.php','REQUEST_METHOD'=>$case['method']??'POST','HTTP_HOST'=>'prism.test',
        'HTTP_ORIGIN'=>$case['origin']??'http://prism.test'];
    if (empty($case['missingMarker'])) $_SERVER['HTTP_X_PRISM_GENERATION']=!empty($case['staleMarker'])?str_repeat('b',32):str_repeat('a',32);
    $_GET=['action'=>$case['action']??'list']+($case['query']??[]);
    require_once __DIR__.'/../security.php';
    $config=file_get_contents(__DIR__.'/../config.php');
    eval(function_source($config,'legal_acceptance_required').function_source($config,'api_require_login').function_source($config,'require_post_same_origin'));
    require_once __DIR__.'/../includes/notification_delivery.php';
    $source=file_get_contents(__DIR__.'/../notifications_api.php');
    foreach (["require __DIR__ . '/config.php';","require_once __DIR__ . '/workflow.php';","require_once __DIR__ . '/includes/notification_delivery.php';","require_once __DIR__ . '/includes/research_groups.php';"] as $include) $source=str_replace($include,'',$source);
    if (preg_match('/^\s*(require|include)(?:_once)?\s/m',$source)) throw new RuntimeException('Unexpected bootstrap in API fixture.');
    ob_start(); register_shutdown_function(function() {
        $raw=ob_get_clean(); echo json_encode(['status'=>http_response_code()?:200,'body'=>json_decode($raw,true),'raw'=>$raw,
            'rows'=>$GLOBALS['pdo']->query('SELECT * FROM notifications')->fetchAll(),'audit'=>$GLOBALS['pdo']->query('SELECT * FROM activity_logs')->fetchAll(),'errors'=>$GLOBALS['errors']]);
    });
    eval(preg_replace('/^<\?php\s*/','',$source)); exit;
}
$checks=0;
function api_check(bool $ok,string $message): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
function api_case(array $case): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
    api_check($exit===0 && $err==='','Fixture exits cleanly '.$err.$out); return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$actions=['mark_read','mark_all_read','archive','restore','delete','campaign_archive','campaign_restore','campaign_delete','campaign_cancel','send'];
foreach ($actions as $action) foreach ([['method'=>'GET','expected'=>405],['staleMarker'=>true,'expected'=>409],['missingMarker'=>true,'expected'=>409],['origin'=>'http://evil.invalid','expected'=>403],['legalMissing'=>true,'expected'=>403],['anonymous'=>true,'expected'=>401]] as $guard) {
    $r=api_case($guard+['action'=>$action,'actor'=>str_starts_with($action,'campaign_')||$action==='send'?1:3]);
    api_check($r['status']===$guard['expected'],'Every mutation preserves POST/origin/session/legal/login gate');
    api_check(!$r['audit'] && $r['rows'][0]['read_at']===null && $r['rows'][0]['archived_at']===null && $r['rows'][0]['deleted_at']===null,'Rejected requests have no side effects');
}
foreach (['list','mark_read','archive','restore','delete','sent_history'] as $action) {
    $r=api_case(['action'=>$action,'pending'=>true]); api_check($r['status']===403,'Pending restriction remains');
}
foreach ([['actor'=>4,'action'=>'archive'],['actor'=>5,'action'=>'mark_read'],['actor'=>2,'action'=>'delete'],['actor'=>2,'action'=>'campaign_archive']] as $attack) {
    $r=api_case($attack); api_check($r['status']===404 && !$r['audit'],'Object-level recipient/sender authority enforced');
}
foreach ([3,4] as $id) {
    $r=api_case(['actor'=>$id,'action'=>'list','method'=>'GET']);
    api_check(count($r['body']['notifications'])===1 && (int)$r['body']['notifications'][0]['recipient_user_id']===$id,'Personal list never exposes another user');
    api_check($r['body']['unread_count']===1 && !isset($r['body']['notifications'][0]['delivery_info']),'Exact unread count and no delivery diagnostics');
}
$r=api_case(['action'=>'delete','archived'=>true]); api_check($r['status']===200 && $r['rows'][0]['message']==='' && $r['rows'][1]['message']==='PRIVATE BODY','API tombstones only owner copy');
api_check(!str_contains(json_encode($r['audit']),'PRIVATE'),'API deletion audit excludes subject/body');
$r=api_case(['action'=>'delete','data'=>['id'=>1,'confirmed'=>'true'],'archived'=>true]); api_check($r['status']===422,'String confirmation cannot replace explicit boolean');
$r=api_case(['action'=>'list','query'=>['sort'=>'DROP TABLE users']]); api_check($r['status']===422,'Invalid sort cannot enter SQL');
echo "PASS: $checks actual notification API ownership, POST, same-origin, strict generation, legal, Pending and data-minimization checks.\n";
