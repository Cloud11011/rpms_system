<?php
/** Actual lifecycle/dispatch SQL with disposable fixtures only. Also invoked by the private native runner. */
if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/../includes/notification_delivery.php';
require_once __DIR__.'/../includes/account_identity.php';
if (!isset($pdo)) {
    require_once __DIR__.'/notification-v10-fixture.php';
    $pdo=new NotificationV10FixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    notification_fixture_sqlite($pdo);
}
$checks=0; $emails=[]; $errors=[];
function current_user(): ?array { return $GLOBALS['actor']??null; }
function log_api_error(...$args): void { $GLOBALS['errors'][]=$args; }
function send_notification_email(string $to,string $subject,string $body,bool $sensitive=false,bool $content=true): array {
    b7_check(!$sensitive && !$content,'All producer paths explicitly request safe fallback');
    b7_check(!$GLOBALS['pdo']->inTransaction(),'Provider call occurs after commit');
    $GLOBALS['emails'][]=compact('to','subject','body');
    return ['ok'=>true,'channel'=>$GLOBALS['transport']??'gmail_api'];
}
function b7_check(bool $ok,string $message): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
function b7_refuse(callable $call,int $status): void {
    try { $call(); } catch (NotificationLifecycleError $e) { b7_check($e->status===$status,'Correct ownership/lifecycle refusal'); return; }
    throw new RuntimeException('Expected lifecycle refusal');
}
function b7_row(string $table,int $id): array { return notification_rows($GLOBALS['pdo'],'SELECT * FROM '.$table.' WHERE id=?',[$id])[0]; }
function b7_send(string $kind='staff',?string $schedule=null): array {
    $p=$GLOBALS['pdo']; $p->beginTransaction();
    $campaign=notification_create_campaign($p,$GLOBALS['actor'],$kind,'PRIVATE SUBJECT','PRIVATE BODY',$schedule??date('Y-m-d H:i:s'));
    $ids=[];
    foreach ([10,11] as $id) {
        $s=b7_row('students',$id);
        $copy=create_notification($p,'student',$id,$s['email'],$s['full_name'],'PRIVATE SUBJECT','PRIVATE BODY','Status Update','Admin',null,$schedule??date('Y-m-d H:i:s'),null,$kind,$campaign);
        $ids[]=$copy['id'];
    }
    $p->commit();
    if ($schedule===null) foreach ($ids as $id) notification_dispatch($p,$id);
    return [$campaign,$ids];
}
$fixturePasswordHash=password_hash('Batch7 fixture password',PASSWORD_DEFAULT);
foreach ([[1,'admin','ADMIN1'],[2,'admin','ADMIN2'],[3,'student','S1'],[4,'student','S2'],[5,'adviser','ADV1']] as [$id,$role,$name]) {
    $pdo->prepare('INSERT INTO users(id,username,ref_id,email,full_name,role,status,password_hash) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$name,$name,'b7-'.$id.'@example.invalid',$name,$role,'Active',$fixturePasswordHash]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')->execute([$fixturePasswordHash,$id]);
}
foreach ([[10,3,'S1'],[11,4,'S2']] as [$id,$uid,$name]) $pdo->prepare('INSERT INTO students(id,student_id,user_id,full_name,email,profile_completed_at) VALUES (?,?,?,?,?,?)')->execute([$id,$name,$uid,$name,'b7-'.$uid.'@example.invalid','2026-01-01']);
$pdo->exec("INSERT INTO advisers(id,employee_id,user_id,full_name,email,status,profile_completed_at) VALUES (20,'ADV1',5,'ADV1','b7-5@example.invalid','Active','2026-01-01')");
$actor=b7_row('users',1); $a=b7_row('users',3); $b=b7_row('users',4); $other=b7_row('users',2); $adviser=b7_row('users',5);
[$campaign,$ids]=b7_send(); [$idA,$idB]=$ids;
b7_check(count($emails)===2,'Exactly one attempt per independent recipient');
$history=notification_list($pdo,$actor,[],'sent_history');
b7_check($history['total']===1 && (int)$history['notifications'][0]['recipient_count']===2,'One campaign per multi-recipient send');
b7_check((int)b7_row('notifications',$idA)['recipient_user_id']===3,'Authoritative recipient binding');
b7_check(notification_list($pdo,$a,[])['unread_count']===1,'Exact personal unread count');
notification_transition($pdo,$a,'mark_read',$idA); $read=b7_row('notifications',$idA)['read_at'];
notification_transition($pdo,$a,'mark_read',$idA);
b7_check(b7_row('notifications',$idA)['read_at']===$read && b7_row('notifications',$idB)['read_at']===null,'Independent/idempotent reading');
foreach ([$b,$adviser,$other] as $attacker) b7_refuse(fn()=>notification_transition($pdo,$attacker,'archive',$idA),404);
b7_refuse(fn()=>notification_transition($pdo,$other,'campaign_archive',$campaign),404);
b7_refuse(fn()=>notification_transition($pdo,$a,'delete',$idA,true),409);
notification_transition($pdo,$a,'archive',$idA);
b7_check(notification_list($pdo,$a,[])['total']===0 && notification_list($pdo,$a,['status'=>'Archived'])['total']===1,'Archive excluded from inbox/bell/count');
notification_transition($pdo,$a,'archive',$idA);
notification_transition($pdo,$a,'restore',$idA);
b7_check(b7_row('notifications',$idA)['read_at']===$read,'Restore preserves read/content identity');
notification_transition($pdo,$a,'archive',$idA); b7_refuse(fn()=>notification_transition($pdo,$a,'delete',$idA),422);
notification_transition($pdo,$a,'delete',$idA,true); notification_transition($pdo,$a,'delete',$idA,true);
$deleted=b7_row('notifications',$idA);
b7_check($deleted['deleted_at']!==null && $deleted['subject']===null && $deleted['message']==='' && $deleted['delivery_info']===null,'Recipient content erased');
b7_check($deleted['recipient_email']===null && $deleted['recipient_name']===null && $deleted['created_by']===null,'Deleted copy minimizes display identity metadata');
foreach (['restore','archive','mark_read'] as $action) b7_refuse(fn()=>notification_transition($pdo,$a,$action,$idA),404);
b7_check(b7_row('notifications',$idB)['message']==='PRIVATE BODY' && b7_row('notification_campaigns',$campaign)['message']==='PRIVATE BODY','Other recipient/sender survive recipient deletion');
b7_check(notification_list($pdo,$a,['status'=>'Archived'])['total']===0,'Deleted body is absent from all recipient API views');
notification_transition($pdo,$actor,'campaign_archive',$campaign); notification_transition($pdo,$actor,'campaign_restore',$campaign);
notification_transition($pdo,$actor,'campaign_archive',$campaign); b7_refuse(fn()=>notification_transition($pdo,$actor,'campaign_delete',$campaign),422);
notification_transition($pdo,$actor,'campaign_delete',$campaign,true); notification_transition($pdo,$actor,'campaign_delete',$campaign,true);
b7_check(b7_row('notification_campaigns',$campaign)['message']===null && notification_list($pdo,$actor,[],'sent_history')['total']===0,'Sender content erased/inaccessible');
b7_check(b7_row('notifications',$idB)['message']==='PRIVATE BODY','Sender deletion preserves recipient body');
[$scheduled,$scheduledIds]=b7_send('staff',date('Y-m-d H:i:s',time()+3600));
foreach (['campaign_archive','campaign_delete'] as $action) b7_refuse(fn()=>notification_transition($pdo,$actor,$action,$scheduled,true),409);
notification_transition($pdo,$actor,'campaign_cancel',$scheduled); notification_transition($pdo,$actor,'campaign_cancel',$scheduled);
foreach ($scheduledIds as $id) b7_check(notification_dispatch($pdo,$id)['status']==='Skipped','Cancelled schedule never dispatches');
notification_transition($pdo,$actor,'campaign_archive',$scheduled); notification_transition($pdo,$actor,'campaign_delete',$scheduled,true);
[$sending,$sendingIds]=b7_send('workflow',date('Y-m-d H:i:s',time()+3600));
$pdo->prepare("UPDATE notification_campaigns SET status='Sending' WHERE id=?")->execute([$sending]);
foreach (['campaign_archive','campaign_delete','campaign_cancel'] as $action) b7_refuse(fn()=>notification_transition($pdo,$actor,$action,$sending,true),409);
$pdo->prepare("UPDATE notification_campaigns SET status='Failed' WHERE id=?")->execute([$sending]);
$pdo->prepare("UPDATE notifications SET status='Failed' WHERE campaign_id=?")->execute([$sending]);
notification_transition($pdo,$actor,'campaign_archive',$sending); notification_transition($pdo,$actor,'campaign_delete',$sending,true);
// A legacy row stays independent, readable and mutable only with established account/profile ownership.
$pdo->prepare("INSERT INTO notifications(recipient_type,recipient_id,recipient_email,recipient_name,subject,message,type,status) VALUES ('student',10,?,'S1','Legacy subject','Legacy body','Reminder','Sent')")->execute([$a['email']]);
$legacy=(int)$pdo->lastInsertId();
b7_check(in_array($legacy,array_column(notification_list($pdo,$a,[])['notifications'],'id')) && notification_list($pdo,$actor,[],'legacy_history')['total']===1,'Legacy visibility retained without false grouping');
notification_transition($pdo,$a,'mark_read',$legacy); b7_check(b7_row('notifications',$legacy)['campaign_id']===null,'Legacy read does not manufacture campaign');
$pdo->prepare("INSERT INTO notifications(recipient_type,recipient_id,recipient_email,message,type,status) VALUES ('student',999,?,'Ambiguous legacy','Reminder','Sent')")->execute([$a['email']]);
b7_refuse(fn()=>notification_transition($pdo,$a,'archive',(int)$pdo->lastInsertId()),404);
// Filtering and body-free audit.
$transport='log'; [$failed,$failedIds]=b7_send('system'); $transport='gmail_api';
b7_check(b7_row('notification_campaigns',$failed)['status']==='Failed','Metadata fallback is never falsely labelled externally Sent');
b7_check(notification_list($pdo,$b,['kind'=>'system','sort'=>'Oldest'])['total']===1,'Kind/sort filter works with independent copies');
$audit=$pdo->query('SELECT * FROM activity_logs')->fetchAll();
b7_check(!str_contains(json_encode($audit),'PRIVATE') && !str_contains(json_encode($audit),'delivery_info'),'Lifecycle audit excludes subject/body/transport content');
b7_check(count(array_filter($audit,fn($r)=>$r['action']==='notification_delete'))===1,'Repeated deletion has one audit event');
// Full sender purge coordination primitive; actual account workflow integration is exercised natively.
[$purge,$purgeIds]=b7_send('workflow'); $locks=[]; $pdo->beginTransaction(); notification_purge_sender($pdo,1,$locks); $pdo->commit();
foreach (array_reverse($locks) as $name) notification_unlock($pdo,$name);
b7_check(b7_row('notification_campaigns',$purge)['message']===null && b7_row('notifications',$purgeIds[1])['message']==='PRIVATE BODY','Sender purge erases sender content and preserves other copies');
b7_check(!$errors,'No payload/exception diagnostics leaked');
// Personal lifecycle authority is the same for staff recipient accounts.
foreach ([['admin',2,$other],['adviser',20,$adviser]] as [$role,$profileId,$recipient]) {
    $copy=create_notification($pdo,$role,$profileId,$recipient['email'],$recipient['full_name'],'Staff inbox subject','Staff inbox body','Reminder','Admin',null,null,null,'system');
    $copyId=$copy['id'];
    b7_check((int)b7_row('notifications',$copyId)['recipient_user_id']===(int)$recipient['id'],'Staff personal copy binds to its own login');
    notification_transition($pdo,$recipient,'mark_read',$copyId);
    notification_transition($pdo,$recipient,'archive',$copyId);
    notification_transition($pdo,$recipient,'restore',$copyId);
    b7_check(b7_row('notifications',$copyId)['message']==='Staff inbox body','Staff restore preserves personal content');
    notification_transition($pdo,$recipient,'archive',$copyId);
    notification_transition($pdo,$recipient,'delete',$copyId,true);
    b7_check(b7_row('notifications',$copyId)['message']==='' && b7_row('notifications',$copyId)['deleted_at']!==null,'Staff personal deletion erases only its own copy');
}
echo "PASS: $checks actual lifecycle, binding, sender/recipient independence, legacy, filter, schedule, idempotency and privacy checks.\n";
