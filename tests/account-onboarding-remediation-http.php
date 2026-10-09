<?php
/** Narrow final-v9 remediation against the existing verified private HTTP/DB copy. */
$remediationHttpStart=$GLOBALS['checks'];
function remediation_http_fields(string $role='student',string $id='FIXTURE-NEW'):array {
    return $role==='student'?['name'=>'Private Name Marker','studentId'=>$id,'academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027','research'=>'Private Research Marker']:
        ['name'=>'Private Name Marker','employeeId'=>$id,'department'=>'Accountancy / Management / Technology'];
}
function remediation_http_pending(string $role):array {
    $c=&$GLOBALS['remediationStaffCookie'];$result=retention_http_request('account_invitation_api.php?action=invite',['accountType'=>$role,'email'=>'remediation'.bin2hex(random_bytes(6)).'@example.invalid'],$c);
    reset_migration_expect(200,$result['status'],'HTTP remediation fixture invitation');
    $r=db()->query('SELECT * FROM '.retention_table($role).' WHERE id='.(int)$result['data']['id'])->fetch();
    $GLOBALS['remediationSetupToken']=bin2hex(random_bytes(32));
    db()->prepare('UPDATE account_invitations SET token_hash=? WHERE user_id=?')->execute([hash('sha256',$GLOBALS['remediationSetupToken']),$r['user_id']]);return $r;
}
retention_test_reset();$remediationStaffCookie=retention_http_login(2);
foreach(['student','adviser'] as $role) {
    retention_test_reset();$r=remediation_http_pending($role);$public='';
    retention_http_request('account_setup.php',['token'=>$remediationSetupToken,'password'=>$fixturePassword,'confirmPassword'=>$fixturePassword],$public,['form'=>true]);
    $c=retention_http_login((int)$r['user_id']);$table=retention_table($role);$before=$pdo->query("SELECT * FROM $table WHERE id=".(int)$r['id'])->fetch();
    $private=remediation_http_fields($role,'PRIVATE-IDENTIFIER');
    $page=retention_http_request('complete_profile.php',[],$c,['method'=>'GET']);
    reset_migration_expect(true,str_contains($page['body'],'method="post" action="complete_profile.php"'),'Explicit safe native POST fallback');
    reset_migration_expect(true,str_contains($page['body'],'type="submit" disabled'),'Submit disabled until JS ready');
    reset_migration_expect(true,str_contains($page['body'],'<noscript>'),'Clear no-JS message');
    reset_migration_expect(false,str_contains($page['body'],'change_password_required.php')||str_contains($page['body'],'Password security'),'No looping Pending password action');
    foreach([['complete_profile.php',$private,['form'=>true]],['complete_profile.php?'.http_build_query($private),[],['method'=>'GET']]] as [$path,$data,$options]) {
        $fallback=retention_http_request($path,$data,$c,$options);
        reset_migration_expect(200,$fallback['status'],'HTML fallback only renders completion page');
        reset_migration_expect(false,str_contains($fallback['body'],'Private Name Marker')||str_contains($fallback['body'],'PRIVATE-IDENTIFIER')||str_contains($fallback['body'],'Private Research Marker'),'Submitted PII not reflected in page or URLs');
        reset_migration_expect([],array_values(array_filter($fallback['headers'],fn($h)=>str_starts_with(strtolower($h),'location:'))),'No PII-bearing redirect');
        reset_migration_expect($before,$pdo->query("SELECT * FROM $table WHERE id=".(int)$r['id'])->fetch(),'HTML GET/POST cannot mutate profile');
    }
    reset_migration_expect(405,retention_http_request('account_invitation_api.php?action=complete',[],$c,['method'=>'GET'])['status'],'JSON completion refuses GET');
    reset_migration_expect(200,retention_http_request('account_invitation_api.php?action=complete',remediation_http_fields($role,'JS-POST-'.$role),$c)['status'],'Protected JSON completion still succeeds');
}
// Existing-record creation/editing, Pending assignment, and invitation assignment.
retention_test_reset();$cookie=retention_http_login(1);$inviteCookie=retention_http_login(2);
$pdo->prepare('INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id) VALUES (3,"A3",?,"admin","Admin Three","admin3@example.invalid","A3")')->execute([$fixtureHash]);
$assignCookie=retention_http_login(3);$pending=remediation_http_pending('student');
$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');
$save=remediation_http_fields()+['email'=>'save-new@example.invalid','adviserId'=>200];
$edit=array_replace($save,['id'=>100,'studentId'=>'S100','name'=>'Student One','email'=>'student@example.invalid']);
foreach(['pending','archived','inactive','incomplete','login_inactive','login_missing','login_role','identity_mismatch','missing'] as $state) {
    $pdo->exec('UPDATE advisers SET user_id=200,archived_at=NULL,status="Active",profile_completed_at=created_at WHERE id=200');
    $pdo->exec('UPDATE users SET status="Active",role="adviser",email="adviser@example.invalid",username="E200",ref_id="E200",full_name="Adviser One" WHERE id=200');
    $adviserId=$state==='missing'?999999:200;
    if($state==='pending')$adviserId=(int)remediation_http_pending('adviser')['id'];
    if($state==='archived')$pdo->exec('UPDATE advisers SET archived_at=NOW() WHERE id=200');
    if($state==='inactive')$pdo->exec('UPDATE advisers SET status="Inactive" WHERE id=200');
    if($state==='incomplete')$pdo->exec('UPDATE advisers SET profile_completed_at=NULL WHERE id=200');
    if($state==='login_inactive')$pdo->exec('UPDATE users SET status="Inactive" WHERE id=200');
    if($state==='login_missing')$pdo->exec('UPDATE advisers SET user_id=NULL WHERE id=200');
    if($state==='login_missing')$pdo->exec('UPDATE users SET email="missing-adviser@example.invalid",username="MISSING-E",ref_id="MISSING-E" WHERE id=200');
    if($state==='login_role')$pdo->exec('UPDATE users SET role="student" WHERE id=200');
    if($state==='identity_mismatch')$pdo->exec('UPDATE users SET email="mismatch-adviser@example.invalid" WHERE id=200');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    foreach([$save,$edit] as $input)reset_migration_expect(409,retention_http_request('students_api.php?action=save',array_replace($input,['adviserId'=>$adviserId]),$cookie)['status'],'Complete-record create/edit rejects '.$state.' Adviser');
    reset_migration_expect(409,retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'student','email'=>'invalid-assignment@example.invalid','adviserId'=>$adviserId],$inviteCookie)['status'],'Invitation rejects '.$state.' Adviser');
    reset_migration_expect(409,retention_http_request('account_invitation_api.php?action=assign',['targetId'=>(int)$pending['id'],'adviserId'=>$adviserId],$assignCookie)['status'],'Pending assignment rejects '.$state.' Adviser');
    reset_migration_expect($count,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Rejected assignment creates no Student');
    reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students WHERE id=100')->fetchColumn(),'Rejected edit leaves Unassigned');
    reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students WHERE id='.(int)$pending['id'])->fetchColumn(),'Rejected Pending assignment leaves Unassigned');
}
$pdo->exec('UPDATE advisers SET user_id=200,archived_at=NULL,status="Active",profile_completed_at=created_at WHERE id=200');
$pdo->exec('UPDATE users SET status="Active",role="adviser",email="adviser@example.invalid",username="E200",ref_id="E200" WHERE id=200');
foreach([[],true,'200junk',0,2147483648] as $badId)reset_migration_expect(422,retention_http_request('students_api.php?action=save',array_replace($edit,['adviserId'=>$badId]),$cookie)['status'],'Crafted Adviser ID cannot bypass validator');
foreach([200,null] as $adviserId) {
    $suffix=$adviserId===null?'unassigned':'assigned';
    $created=retention_http_request('students_api.php?action=save',array_replace($save,['studentId'=>'SAVE-'.$suffix,'email'=>'save-'.$suffix.'@example.invalid','adviserId'=>$adviserId]),$cookie);
    reset_migration_expect(200,$created['status'],'Complete-record create accepts '.$suffix);
    reset_migration_expect($adviserId,$pdo->query('SELECT adviser_id FROM students WHERE student_id="SAVE-'.$suffix.'"')->fetchColumn(),'Created assignment authoritative');
    reset_migration_expect(200,retention_http_request('students_api.php?action=save',array_replace($edit,['adviserId'=>$adviserId]),$cookie)['status'],'Complete-record edit accepts '.$suffix);
    reset_migration_expect($adviserId,$pdo->query('SELECT adviser_id FROM students WHERE id=100')->fetchColumn(),'Edited assignment authoritative');
    reset_migration_expect(200,retention_http_request('account_invitation_api.php?action=assign',['targetId'=>(int)$pending['id'],'adviserId'=>$adviserId],$assignCookie)['status'],'Pending assignment accepts '.$suffix);
    reset_migration_expect($adviserId,$pdo->query('SELECT adviser_id FROM students WHERE id='.(int)$pending['id'])->fetchColumn(),'Pending assignment authoritative');
    $invited=retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'student','email'=>'invite-'.$suffix.'@example.invalid','adviserId'=>$adviserId],$inviteCookie);
    reset_migration_expect(200,$invited['status'],'Invitation accepts '.$suffix);
    reset_migration_expect($adviserId,$pdo->query('SELECT adviser_id FROM students WHERE id='.(int)$invited['data']['id'])->fetchColumn(),'Invited assignment authoritative');
}
foreach(['student','adviser'] as $role) {
    retention_test_reset();$r=remediation_http_pending($role);$uid=(int)$r['user_id'];$invitationToken=$remediationSetupToken;
    $original=$pdo->query('SELECT * FROM account_invitations WHERE user_id='.$uid)->fetch();$responses=[];
    foreach([$r['email'],'admin1@example.invalid','unknown-reset@example.invalid'] as $email) {
        $public='';$request=retention_http_request('forgot_password_process.php',['email'=>$email],$public,['form'=>true]);
        reset_migration_expect(302,$request['status'],'Recovery keeps generic redirect');
        $responses[]=retention_http_request('forgot_password.php',[],$public,['method'=>'GET'])['body'];
    }
    foreach($responses as $body)reset_migration_expect(true,str_contains($body,'If that email is registered, a reset link was requested. If no email arrives, contact the RPMS office.'),'Unaccepted/complete/unknown recovery gives same generic confirmation');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id='.$uid)->fetchColumn(),'Unaccepted invitation creates no reset');
    reset_migration_expect($original,$pdo->query('SELECT * FROM account_invitations WHERE user_id='.$uid)->fetch(),'Forgot Password preserves invitation');
    $old=bin2hex(random_bytes(32));$pdo->prepare('INSERT INTO password_resets(user_id,token,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$uid,'sha256:'.hash('sha256',$old)]);
    $public='';$before=retention_test_actor($uid)['password_hash'];
    $page=retention_http_request('reset_password.php?token='.$old,[],$public,['method'=>'GET']);
    reset_migration_expect(true,str_contains($page['body'],'style="display:none"'),'Ordinary reset form unavailable before acceptance');
    $reset=retention_http_request('update_password.php',['token'=>$old,'password'=>'Synthetic ordinary recovery passphrase','confirm_password'=>'Synthetic ordinary recovery passphrase'],$public,['form'=>true]);
    reset_migration_expect(302,$reset['status'],'Old reset refused safely');
    reset_migration_expect($before,retention_test_actor($uid)['password_hash'],'Reset cannot establish first-time password');
    reset_migration_expect(null,$pdo->query('SELECT accepted_at FROM account_invitations WHERE user_id='.$uid)->fetchColumn(),'Reset cannot accept invitation');
    $setup=retention_http_request('account_setup.php',['token'=>$invitationToken,'password'=>$fixturePassword,'confirmPassword'=>$fixturePassword],$public,['form'=>true]);
    reset_migration_expect(true,str_contains($setup['body'],'Password established'),'Original invitation still accepts after Forgot Password');
    reset_migration_expect(1,(int)$pdo->query('SELECT used FROM password_resets WHERE user_id='.$uid)->fetchColumn(),'Acceptance invalidates old reset');
    $pdo->exec('UPDATE password_resets SET created_at=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE user_id='.$uid);
    retention_http_request('forgot_password_process.php',['email'=>$r['email']],$public,['form'=>true]);
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used=0 AND user_id='.$uid)->fetchColumn(),'Accepted Pending can request recovery');
    $recover=bin2hex(random_bytes(32));$pdo->prepare('UPDATE password_resets SET token=? WHERE used=0 AND user_id=?')->execute(['sha256:'.hash('sha256',$recover),$uid]);
    retention_http_request('update_password.php',['token'=>$recover,'password'=>$fixturePassword,'confirm_password'=>$fixturePassword],$public,['form'=>true]);
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used=0 AND user_id='.$uid)->fetchColumn(),'Accepted Pending consumes reset');
    $login=retention_http_login($uid);
    reset_migration_expect(200,retention_http_request('account_invitation_api.php?action=complete',remediation_http_fields($role,'RECOVERED-'.$role),$login)['status'],'Recovered Pending can complete');
    $pdo->exec('UPDATE password_resets SET created_at=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE user_id='.$uid);
    retention_http_request('forgot_password_process.php',['email'=>$r['email']],$public,['form'=>true]);
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used=0 AND user_id='.$uid)->fetchColumn(),'Completed account retains recovery');
}
echo 'PASS: narrow remediation HTTP assertions '.($GLOBALS['checks']-$remediationHttpStart).".\n";
