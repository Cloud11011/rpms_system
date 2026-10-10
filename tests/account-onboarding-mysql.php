<?php
/** Actual shared service; included only after the runner verifies its private MariaDB datadir. */
require_once __DIR__.'/../includes/account_onboarding.php';
$onboardingStart=$GLOBALS['checks'];
if (!defined('APP_BASE_URL')) define('APP_BASE_URL','https://prism.invalid');
if (!defined('ALLOWED_EMAIL_DOMAINS')) define('ALLOWED_EMAIL_DOMAINS',['example.invalid']);
function app_base_url_is_valid(): bool { return true; }
function is_allowed_email_domain(string $email): bool { return str_ends_with($email,'@example.invalid'); }
function new_password_is_valid(string $password): bool { return strlen($password)>=12 && strlen($password)<=200; }
function send_notification_email(string $email,string $subject,string $body,bool $sensitive=false): array {
    if (!$sensitive) throw new RuntimeException('Invitation transport must be sensitive.');
    preg_match('/(?:token=|#)([a-f0-9]{64})/',$body,$match);
    $GLOBALS['invitationTestToken']=$match[1]??null;
    return ['ok'=>!($GLOBALS['invitationMailFail']??false)];
}
function onboarding_test_refuse(callable $action,string $class): void {
    $error=null;try{$action();}catch(Throwable $e){$error=$e;}
    reset_migration_expect($class,$error?get_class($error):null,'Expected onboarding refusal: '.($error?->getMessage()??'success'));
    reset_migration_expect(false,db()->inTransaction(),'Refusal leaves no open transaction');
}
function onboarding_test_pending(string $role='student',int $actor=1,?string $email=null): array {
    $result=onboarding_invite(db(),retention_test_actor($actor),['accountType'=>$role,'email'=>$email??('invite'.bin2hex(random_bytes(6)).'@example.invalid')]);
    $table=$role==='student'?'students':'advisers';
    return db()->query("SELECT * FROM $table WHERE id=".(int)$result['id'])->fetch();
}
function onboarding_test_accept(array $r): array {
    onboarding_accept(db(),['token'=>$GLOBALS['invitationTestToken'],'password'=>'Synthetic onboarding passphrase','confirmPassword'=>'Synthetic onboarding passphrase']);
    return retention_test_actor((int)$r['user_id']);
}
function onboarding_test_fields(string $role='student',string $id='NEW-001'): array {
    return $role==='student'?['name'=>'Invited Student','studentId'=>$id,'academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027']:
        ['name'=>'Invited Adviser','employeeId'=>$id,'department'=>academic_catalog()['units']['amt']['label']];
}
retention_test_reset();
$pdo->exec('UPDATE advisers SET archived_at=NULL,status="Active"');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
foreach([['student',1],['adviser',1],['student',200]] as [$role,$actor]) {
    $r=onboarding_test_pending($role,$actor);
    reset_migration_expect(null,$r['full_name'],'No placeholder name');
    reset_migration_expect(null,$r[$role==='student'?'student_id':'employee_id'],'No placeholder ID');
    reset_migration_expect(null,$r['profile_completed_at'],'Durable Pending state');
    if($role==='student')reset_migration_expect($actor===200?200:null,$r['adviser_id'],'Server-derived assignment');
    $login=retention_test_actor((int)$r['user_id']);
    reset_migration_expect(null,$login['username'],'Pending username structurally absent');
    reset_migration_expect(false,onboarding_complete($pdo,$login),'Pending has no operational authority');
    $token=$GLOBALS['invitationTestToken'];
    reset_migration_expect(hash('sha256',$token),$pdo->query('SELECT token_hash FROM account_invitations WHERE user_id='.(int)$r['user_id'])->fetchColumn(),'Token hashed at rest');
    $login=onboarding_test_accept($r);
    onboarding_test_refuse(fn()=>onboarding_accept($pdo,['token'=>$token,'password'=>'Synthetic onboarding passphrase','confirmPassword'=>'Synthetic onboarding passphrase']),AccountLifecycleValidation::class);
    $fields=onboarding_test_fields($role,'FIRST-'.(int)$r['id'].'-'.$role);
    foreach(['role'=>'admin','email'=>'substitute@example.invalid','adviserId'=>99,'group'=>'forged','stage'=>'Completed','status'=>'Active','profile_completed_at'=>'now','targetId'=>100,'retention_hold'=>1] as $key=>$value) {
        onboarding_test_refuse(fn()=>onboarding_finish($pdo,$login,$fields+[$key=>$value]),AccountLifecycleValidation::class);
        reset_migration_expect(null,$pdo->query('SELECT profile_completed_at FROM '.retention_table($role).' WHERE id='.(int)$r['id'])->fetchColumn(),'Forged field cannot complete identity');
    }
    $done=onboarding_finish($pdo,$login,$fields);
    reset_migration_expect(true,$done['ok'],'Shared role completion succeeds');
    reset_migration_expect(true,onboarding_complete($pdo,retention_test_actor((int)$r['user_id'])),'Completed authority opens');
    onboarding_test_refuse(fn()=>onboarding_finish($pdo,retention_test_actor((int)$r['user_id']),$fields),AccountLifecycleConflict::class);
}
foreach([['adviser',200],['student',100],['adviser',100],['student',0]] as [$role,$actor]) {
    $who=$actor?retention_test_actor($actor):['id'=>0,'role'=>'anonymous','email'=>''];
    onboarding_test_refuse(fn()=>onboarding_invite($pdo,$who,['accountType'=>$role,'email'=>'forbidden@example.invalid']),AccountLifecycleForbidden::class);
}
onboarding_test_refuse(fn()=>onboarding_invite($pdo,retention_test_actor(200),['accountType'=>'student','email'=>'forged@example.invalid','adviserId'=>99]),AccountLifecycleValidation::class);
onboarding_test_refuse(fn()=>onboarding_invite($pdo,retention_test_actor(1),['accountType'=>'admin','email'=>'role@example.invalid']),AccountLifecycleForbidden::class);
foreach(['admin1@example.invalid','student@example.invalid','adviser@example.invalid'] as $email) onboarding_test_refuse(fn()=>onboarding_invite($pdo,retention_test_actor(1),['accountType'=>'student','email'=>$email]),AccountLifecycleConflict::class);
foreach(['student','adviser'] as $role) {
    $r=onboarding_test_pending($role);
    onboarding_test_refuse(fn()=>onboarding_invite($pdo,retention_test_actor(1),['accountType'=>$role,'email'=>$r['email']]),AccountLifecycleConflict::class);
    $token=$GLOBALS['invitationTestToken'];
    $resend=['accountType'=>$role,'targetId'=>(int)$r['id']];
    onboarding_test_refuse(fn()=>onboarding_resend($pdo,retention_test_actor(1),$resend),AccountLifecycleConflict::class);
    $pdo->exec('UPDATE account_invitations SET last_sent_at=DATE_SUB(NOW(),INTERVAL 61 SECOND)');
    onboarding_resend($pdo,retention_test_actor(1),$resend);
    $new=$GLOBALS['invitationTestToken'];
    reset_migration_expect(false,$token===$new,'Resend creates replacement secret');
    onboarding_test_refuse(fn()=>onboarding_accept($pdo,['token'=>$token,'password'=>'Synthetic onboarding passphrase','confirmPassword'=>'Synthetic onboarding passphrase']),AccountLifecycleValidation::class);
    $pdo->exec('UPDATE account_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id='.(int)$r['user_id']);
    onboarding_test_refuse(fn()=>onboarding_test_accept($r),AccountLifecycleValidation::class);
    foreach(['garbage',str_repeat('0',64),substr($new,0,63).'z'] as $bad) onboarding_test_refuse(fn()=>onboarding_accept($pdo,['token'=>$bad,'password'=>'Synthetic onboarding passphrase','confirmPassword'=>'Synthetic onboarding passphrase']),AccountLifecycleValidation::class);
    $pdo->exec('UPDATE account_invitations SET expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE user_id='.(int)$r['user_id']);
    $login=onboarding_test_accept($r);
    onboarding_test_refuse(fn()=>onboarding_finish($pdo,$login,onboarding_test_fields($role,$role==='student'?'S100':'E200')),AccountLifecycleConflict::class);
    if($role==='student')foreach(['programKey'=>'forged','yearLevel'=>'6th Year','academicYear'=>'2099-2100','academicUnitKey'=>'nursing'] as $key=>$value) {
        onboarding_test_refuse(fn()=>onboarding_finish($pdo,$login,array_replace(onboarding_test_fields(),[$key=>$value])),AccountLifecycleValidation::class);
    }
}
// Orphan profile/email and users-only identities never get silently claimed.
$pdo->exec("INSERT INTO students(student_id,full_name,email) VALUES ('ORPHAN','Orphan','orphan@example.invalid')");
$pdo->exec("INSERT INTO users(username,password_hash,role,full_name,email) VALUES ('ONLY','hash','student','Users only','only@example.invalid')");
foreach(['orphan@example.invalid','only@example.invalid'] as $email) onboarding_test_refuse(fn()=>onboarding_test_pending('adviser',1,$email),AccountLifecycleConflict::class);
// Delivery failure retains one Pending identity; retrying creation does not duplicate it.
retention_test_reset();$GLOBALS['invitationMailFail']=true;
$r=onboarding_test_pending('student',1,'delivery@example.invalid');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'Delivery failure retains invitation');
onboarding_test_refuse(fn()=>onboarding_test_pending('student',1,$r['email']),AccountLifecycleConflict::class);
$GLOBALS['invitationMailFail']=false;
// Both role lifecycles preserve Pending and remove all credentials after purge.
foreach(['student','adviser'] as $role)foreach(['manual','override','cleanup'] as $method) {
    retention_test_reset();$r=onboarding_test_pending($role);$id=(int)$r['id'];$table=retention_table($role);
    $base=['accountType'=>$role,'targetId'=>$id];
    retention_change($pdo,retention_test_actor(),$base+['action'=>'archive','expectedAssignedStudents'=>0]);
    reset_migration_expect(null,$pdo->query('SELECT token_hash FROM account_invitations')->fetchColumn(),'Archive invalidates token');
    retention_change($pdo,retention_test_actor(),$base+['action'=>'hold']);
    onboarding_test_refuse(fn()=>retention_purge($pdo,retention_test_actor(),$base+['action'=>'grace_period_override','currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>$r['email'],'confirmed'=>true,'reason'=>'Synthetic early cleanup']),AccountLifecycleConflict::class);
    retention_change($pdo,retention_test_actor(),$base+['action'=>'remove_hold']);
    $restored=retention_change($pdo,retention_test_actor(),$base+['action'=>'restore']);
    reset_migration_expect(true,str_contains($restored['setupLink'],'account_setup.php'),'Never accepted Pending restore issues invitation setup');
    reset_migration_expect(null,$pdo->query("SELECT profile_completed_at FROM $table WHERE id=$id")->fetchColumn(),'Restore never completes profile');
    retention_change($pdo,retention_test_actor(),$base+['action'=>'archive','expectedAssignedStudents'=>0]);
    if($method!=='override')$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL ".($method==='manual'?'7 DAY':'6 MONTH').") WHERE id=$id");
    $input=$base+['action'=>$method==='manual'?'permanent_delete':($method==='override'?'grace_period_override':'retention_cleanup'),'currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>$r['email'],'confirmed'=>true,'reason'=>'Synthetic early cleanup'];
    onboarding_test_refuse(fn()=>retention_purge($pdo,retention_test_actor(),array_replace($input,['confirmation'=>''])),AccountLifecycleValidation::class);
    reset_migration_expect(true,retention_purge($pdo,retention_test_actor(),$input)['ok'],'Pending '.$role.' '.$method.' purge');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'Purge removes invitation credentials');
    $journal=$pdo->query('SELECT identifier FROM account_purge_jobs ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    reset_migration_expect($role.' account #'.$id,$journal,'Pending purge retains minimal internal reference');
}
retention_test_reset();$pdo->exec('UPDATE advisers SET archived_at=NULL,status="Active"');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
$r=onboarding_test_pending('student',200);$token=$GLOBALS['invitationTestToken'];
retention_change($pdo,retention_test_actor(),['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>1]);
$login=onboarding_test_accept($r);onboarding_finish($pdo,$login,onboarding_test_fields());
reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students WHERE id='.(int)$r['id'])->fetchColumn(),'Adviser archive unassigns Pending Student; completion preserves Unassigned');
retention_test_reset();
$r=onboarding_test_pending('adviser');
onboarding_test_refuse(fn()=>onboarding_invite($pdo,retention_test_actor((int)$r['user_id']),['accountType'=>'student','email'=>'pending-authority@example.invalid']),AccountLifecycleForbidden::class);
// Mixed complete/Pending bulk scope and partial success.
retention_test_reset();$r=onboarding_test_pending();
$scope=retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['profile'=>'pending']]);
reset_migration_expect([(int)$r['id']],$scope['ids'],'Pending filter selects only incomplete identity');
foreach(['archive','hold','remove_hold','restore'] as $action) {
    $s=retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['profile'=>'pending']]);
    $preview=retention_bulk_preview($pdo,retention_test_actor(),['selectionToken'=>$s['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>$action]);
    $result=retention_bulk_execute($pdo,retention_test_actor(),['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>$preview['phrase'],'confirmed'=>true]);
    reset_migration_expect(1,$result['completed'],'Pending bulk '.$action);
    reset_migration_expect($r['email'],$result['results'][0]['identifier'],'Bulk Pending result displays genuine email');
}
// Mixed Pending/Complete destructive batches keep independent eligible/held outcomes.
foreach(['student','adviser'] as $role)foreach(['permanent_delete','retention_cleanup'] as $action) {
    retention_test_reset();$r=onboarding_test_pending($role);$held=onboarding_test_pending($role);
    foreach([$r,$held] as $row)retention_change($pdo,retention_test_actor(),['accountType'=>$role,'targetId'=>(int)$row['id'],'action'=>'archive','expectedAssignedStudents'=>0]);
    $table=retention_table($role);$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL 6 MONTH)");
    retention_change($pdo,retention_test_actor(),['accountType'=>$role,'targetId'=>(int)$held['id'],'action'=>'hold']);
    $s=retention_selection($pdo,retention_test_actor(),['accountType'=>$role,'filters'=>['lifecycle'=>'archived']]);
    $preview=retention_bulk_preview($pdo,retention_test_actor(),['selectionToken'=>$s['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>$action]);
    reset_migration_expect(3,$preview['selected'],'Mixed bulk includes Complete and Pending');
    reset_migration_expect(2,$preview['eligible'],'Mixed bulk excludes held Pending account');
    $input=['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>$preview['phrase'],'confirmed'=>true];
    onboarding_test_refuse(fn()=>retention_bulk_execute($pdo,retention_test_actor(),array_replace($input,['confirmation'=>'PURGE 3 ACCOUNTS'])),AccountLifecycleValidation::class);
    $result=retention_bulk_execute($pdo,retention_test_actor(),$input);
    reset_migration_expect(2,$result['completed'],'Mixed bulk independently purges eligible Complete/Pending');
    reset_migration_expect(1,$result['skipped'],'Held Pending stays intact');
    $results=array_column($result['results'],null,'id');
    reset_migration_expect($r['email'],$results[(int)$r['id']]['identifier'],'Purged Pending result has genuine confirmation email');
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'Only held Pending invitation remains');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id='.(int)$r['user_id'])->fetchColumn(),'Pending purge removes login');
    reset_migration_expect(null,$pdo->query("SELECT profile_completed_at FROM $table WHERE id=".(int)$held['id'])->fetchColumn(),'Held Pending remains incomplete');
    onboarding_test_refuse(fn()=>retention_bulk_preview($pdo,retention_test_actor(),['bulkAction'=>'grace_period_override']),AccountLifecycleValidation::class);
}
// Final audience persistence removes incomplete recipients even with a crafted group/name.
retention_test_reset();$s=onboarding_test_pending();$a=onboarding_test_pending('adviser');
$pdo->beginTransaction();
$audience=notification_lock_recipients($pdo,[['type'=>'student','id'=>(int)$s['id'],'name'=>null,'email'=>$s['email']],['type'=>'adviser','id'=>(int)$a['id'],'name'=>null,'email'=>$a['email']]]);
reset_migration_expect([],$audience,'Pending accounts excluded from final operational audience');$pdo->rollBack();
foreach(['student','adviser'] as $role) {
    retention_test_reset();$r=onboarding_test_pending($role);$uid=(int)$r['user_id'];
    reset_migration_expect(true,onboarding_requires_invitation_setup($pdo,$uid),'Unaccepted invitation requires first-time setup');
    $pdo->prepare('INSERT INTO password_resets(user_id,token,expires_at) VALUES (?, ?, DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$uid,'sha256:'.hash('sha256','synthetic-old-reset')]);
    onboarding_test_accept($r);
    reset_migration_expect(false,onboarding_requires_invitation_setup($pdo,$uid),'Accepted Pending may use ordinary recovery');
    reset_migration_expect(1,(int)$pdo->query('SELECT used FROM password_resets WHERE user_id='.$uid)->fetchColumn(),'Acceptance invalidates old reset');
    reset_migration_expect(null,$pdo->query('SELECT profile_completed_at FROM '.retention_table($role).' WHERE id='.(int)$r['id'])->fetchColumn(),'Recovery eligibility never completes profile');
}
echo 'PASS: onboarding service/lifecycle assertions '.($GLOBALS['checks']-$onboardingStart).'; cumulative '.$GLOBALS['checks'].".\n";
