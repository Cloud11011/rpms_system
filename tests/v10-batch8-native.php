<?php
/** Native frozen-v10 Support, purge and legal regressions; only the verified B7 private server. */
if (PHP_SAPI!=='cli' || !isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
require_once __DIR__.'/../includes/account_onboarding.php';
require_once __DIR__.'/../includes/legal_policy.php';
function b8_native_accept(array $actor): void { legal_accept($GLOBALS['pdo'],$actor,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($GLOBALS['pdo']))]); }
$nativeStart=$GLOBALS['nativeChecks'];$mailStart=count($emails);
foreach ([31=>'admin',32=>'admin',33=>'student',34=>'adviser',35=>'admin'] as $uid=>$role) {
    $pdo->prepare('INSERT INTO users(id,username,ref_id,full_name,email,role,status,password_hash,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([$uid,'B8-'.$uid,'B8-'.$uid,'Batch8 '.$uid,'b8-'.$uid.'@example.invalid',$role,$uid===35?'Inactive':'Active',password_hash('Batch7 fixture password',PASSWORD_DEFAULT)]);
    if ($role!=='admin') {
        $table=$role==='student'?'students':'advisers';$field=$role==='student'?'student_id':'employee_id';
        $pdo->prepare("INSERT INTO $table(id,user_id,$field,full_name,email,profile_completed_at) VALUES (?,?,?,?,?,NULL)")->execute([$uid,$uid,'B8-'.$uid,'Batch8 '.$uid,'b8-'.$uid.'@example.invalid']);
    }
}
$requester=b7_row('users',33);$adviser=b7_row('users',34);$adminA=b7_row('users',31);$adminB=b7_row('users',32);$operator=b7_row('users',2);
$_SESSION=['support_send_token'=>'native-fixture'];
$request=['category'=>'Account/Access','subject'=>'Access request','message'=>'B8_PRIVATE_SENTINEL','request_token'=>support_token()];
$sent=support_submit($pdo,$requester,$request);$cid=$sent['campaign_id'];
$copies=notification_rows($pdo,'SELECT * FROM notifications WHERE campaign_id=? ORDER BY recipient_user_id',[$cid]);
reset_migration_expect([2,31,32],array_map('intval',array_column($copies,'recipient_user_id')),'Native authoritative active-Admin snapshot');
$pdo->exec("UPDATE users SET status='Active' WHERE id=35");notification_list($pdo,$operator,['kind'=>'support']);
reset_migration_expect(3,count(notification_rows($pdo,'SELECT id FROM notifications WHERE campaign_id=?',[$cid])),'Native no retroactive delivery');
$copyA=(int)$copies[1]['id'];$copyB=(int)$copies[2]['id'];
notification_transition($pdo,$adminA,'mark_read',$copyA);notification_transition($pdo,$adminA,'archive',$copyA);
notification_transition($pdo,$adminA,'restore',$copyA);notification_transition($pdo,$adminA,'archive',$copyA);notification_transition($pdo,$adminA,'delete',$copyA,true);
reset_migration_expect(null,b7_row('notifications',$copyB)['read_at'],'Native Admin copy independence');
$response=support_respond($pdo,$adminB,['request_id'=>$copyB,'subject'=>'Support Response','message'=>'B8_PRIVATE_RESPONSE','request_token'=>support_token()]);
$rid=(int)notification_rows($pdo,'SELECT id FROM notifications WHERE campaign_id=?',[$response['campaign_id']])[0]['id'];
reset_migration_expect(33,(int)b7_row('notifications',$rid)['recipient_user_id'],'Native response binds Pending user ID');
reset_migration_expect('Support Response',b7_row('notifications',$rid)['type'],'Native response subtype');
support_transition($pdo,$requester,'mark_read',['id'=>$rid]);support_transition($pdo,$requester,'archive',['id'=>$rid]);support_transition($pdo,$requester,'restore',['id'=>$rid]);
support_transition($pdo,$requester,'campaign_archive',['id'=>$cid]);support_transition($pdo,$requester,'campaign_restore',['id'=>$cid]);
reset_migration_expect($cid,(int)b7_row('notification_campaigns',$response['campaign_id'])['reply_to_campaign_id'],'Native correlation remains original campaign');
// Pending Adviser support shares the same independent notification path.
$second=support_submit($pdo,$adviser,array_replace($request,['request_token'=>support_token()]));
reset_migration_expect(4,count(notification_rows($pdo,'SELECT id FROM notifications WHERE campaign_id=?',[$second['campaign_id']])),'Pending Adviser and current later Admin snapshot');
// Zero recipients rolls back and keeps the form token reusable.
$active=notification_rows($pdo,"SELECT id FROM users WHERE role='admin' AND status='Active'");
$pdo->exec("UPDATE users SET status='Inactive' WHERE role='admin'");$before=(int)$pdo->query('SELECT COUNT(*) FROM notification_campaigns')->fetchColumn();
try { support_submit($pdo,$requester,array_replace($request,['request_token'=>support_token()]));throw new RuntimeException('Zero Admin must fail'); }
catch (NotificationLifecycleError $e) { reset_migration_expect(422,$e->status,'Native zero Admin refusal'); }
reset_migration_expect($before,(int)$pdo->query('SELECT COUNT(*) FROM notification_campaigns')->fetchColumn(),'Zero Admin creates no campaign');
foreach ($active as $row) $pdo->prepare("UPDATE users SET status='Active' WHERE id=?")->execute([$row['id']]);
// Required password/legal enforcement remains above Support; test real acceptance/governance helpers.
$current=legal_current($pdo);legal_accept($pdo,$requester,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot($current)]);
reset_migration_expect([],legal_outstanding($pdo,33),'Native current legal acceptance');
foreach ([$adminA,$adminB,$operator] as $actor) b8_native_accept($actor);
$draft=legal_manage($pdo,$adminA,['action'=>'create','policy'=>'privacy']);
legal_manage($pdo,$adminA,['action'=>'save','policy'=>'privacy','versionId'=>$draft,'title'=>'Privacy Policy','content'=>"# Fixture privacy\nSafe legal source",'summary'=>'Native presentation non-change regression']);
legal_manage($pdo,$adminA,['action'=>'submit','policy'=>'privacy','versionId'=>$draft]);
try { legal_manage($pdo,$adminA,['action'=>'approve','policy'=>'privacy','versionId'=>$draft,'password'=>'Batch7 fixture password','reviewAcknowledged'=>true]);throw new RuntimeException('Creator self-approval must fail'); }
catch (LegalPolicyError $e) { reset_migration_expect(403,$e->status,'Multi-Admin requires independent approval'); }
legal_manage($pdo,$adminB,['action'=>'approve','policy'=>'privacy','versionId'=>$draft,'password'=>'Batch7 fixture password','reviewAcknowledged'=>true]);
reset_migration_expect(['privacy'],array_keys(legal_outstanding($pdo,33)),'Partial policy reacceptance requires only revised privacy');
legal_accept($pdo,$requester,['privacy'=>true,'terms'=>false,'snapshot'=>legal_snapshot(legal_current($pdo))]);
reset_migration_expect([],legal_outstanding($pdo,33),'Partial reacceptance preserves existing terms evidence');
b8_native_accept($adminA);$draft=legal_manage($pdo,$adminA,['action'=>'create','policy'=>'terms']);
legal_manage($pdo,$adminA,['action'=>'save','policy'=>'terms','versionId'=>$draft,'title'=>'Terms of Service','content'=>"# Fixture terms\nSafe legal source",'summary'=>'Native sole-Admin regression']);
legal_manage($pdo,$adminA,['action'=>'submit','policy'=>'terms','versionId'=>$draft]);
$active=notification_rows($pdo,"SELECT id FROM users WHERE role='admin' AND status='Active'");$pdo->exec("UPDATE users SET status='Inactive' WHERE role='admin' AND id<>31");
legal_manage($pdo,$adminA,['action'=>'approve','policy'=>'terms','versionId'=>$draft,'password'=>'Batch7 fixture password','reviewAcknowledged'=>true,'soleAcknowledged'=>true]);
reset_migration_expect('sole_admin',$pdo->query('SELECT approval_mode FROM legal_policy_approvals WHERE version_id='.$draft)->fetchColumn(),'Sole-Admin publication semantics retained');
foreach ($active as $row) $pdo->prepare("UPDATE users SET status='Active' WHERE id=?")->execute([$row['id']]);
// Existing retention purge, with its grace override, erases only sender history and account-bound responses.
retention_change($pdo,$operator,['accountType'=>'student','targetId'=>33,'action'=>'archive','expectedAssignedStudents'=>0]);
$result=retention_purge($pdo,$operator,['accountType'=>'student','targetId'=>33,'action'=>'grace_period_override','currentPassword'=>'Batch7 fixture password','confirmation'=>'B8-33','confirmed'=>true,'reason'=>'Isolated native Support purge regression']);
reset_migration_expect(true,$result['ok'],'Native requester purge uses existing retention journal');
reset_migration_expect(null,b7_row('notification_campaigns',$cid)['sender_user_id'],'Support sender SET NULL after purge');
reset_migration_expect(null,b7_row('notification_campaigns',$cid)['message'],'Support sender campaign content erased');
reset_migration_expect(0,count(notification_rows($pdo,'SELECT id FROM notifications WHERE id=?',[$rid])),'Requester response copy follows frozen recipient CASCADE');
reset_migration_expect('B8_PRIVATE_SENTINEL',b7_row('notifications',$copyB)['message'],'Other Admin request copy survives requester purge');
try { support_response_target($pdo,$adminB,$copyB);throw new RuntimeException('Purged requester cannot receive response'); }
catch (NotificationLifecycleError $e) { reset_migration_expect(409,$e->status,'Purged sender has no response recipient'); }
$otherCopy=(int)$copies[0]['id'];
$result=lifecycle_execute($pdo,$adminB,['accountType'=>'admin','action'=>'permanent_delete','targetId'=>32,'currentPassword'=>'Batch7 fixture password','confirmation'=>'DELETE','confirmed'=>true]);
reset_migration_expect(true,$result['ok'],'Native Admin purge uses existing lifecycle');
reset_migration_expect(0,count(notification_rows($pdo,'SELECT id FROM notifications WHERE id=?',[$copyB])),'Purged Admin copy CASCADE');
reset_migration_expect('B8_PRIVATE_SENTINEL',b7_row('notifications',$otherCopy)['message'],'Unrelated Admin survives Admin purge');
reset_migration_expect(null,b7_row('notification_campaigns',$response['campaign_id'])['message'],'Purged responding Admin sender history erased');
reset_migration_expect($mailStart,count($emails),'Zero Support email transport calls');
reset_migration_expect(false,str_contains(json_encode($pdo->query('SELECT * FROM activity_logs')->fetchAll()),'B8_PRIVATE'),'Support bodies absent from native purge/audit logs');
lifecycle_compare_schema($pdo);
echo 'PASS: '.($GLOBALS['nativeChecks']-$nativeStart)." Batch 8 native Support/purge/legal assertions; frozen schema parity.\n";
