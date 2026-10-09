<?php
/** Direct authenticated HTTP against the private application copy, no JS authorization assumptions. */
$onboardingHttpStart=$GLOBALS['checks'];
retention_test_reset();$cookie=retention_http_login(1);$anon='';
$invite=['accountType'=>'student','email'=>'http-invite@example.invalid'];
foreach([['noOrigin'=>true],['origin'=>'https://evil.invalid']] as $options) reset_migration_expect(403,retention_http_request('account_invitation_api.php?action=invite',$invite,$cookie,$options)['status'],'Invitation origin guard');
reset_migration_expect(401,retention_http_request('account_invitation_api.php?action=invite',$invite,$anon)['status'],'Anonymous invitation denied');
$result=retention_http_request('account_invitation_api.php?action=invite',$invite,$cookie);
reset_migration_expect(200,$result['status'],'Admin invites using email only');
$sid=(int)$result['data']['id'];$r=$pdo->query('SELECT * FROM students WHERE id='.$sid)->fetch();$uid=(int)$r['user_id'];
reset_migration_expect(null,$r['student_id'],'HTTP invitation does not invent ID');
reset_migration_expect(409,retention_http_request('account_invitation_api.php?action=invite',$invite,$cookie)['status'],'HTTP duplicate refuses recreation');
$token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE account_invitations SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE user_id=?')->execute([hash('sha256',$token),$uid]);
$setup=retention_http_request('account_setup.php',['token'=>$token,'password'=>$fixturePassword,'confirmPassword'=>$fixturePassword],$anon,['form'=>true]);
reset_migration_expect(200,$setup['status'],'Setup form HTTP succeeds');
reset_migration_expect(true,str_contains($setup['body'],'Password established'),'Setup establishes password');
$pendingCookie=retention_http_login($uid);
$page=retention_http_request('index.php',[],$pendingCookie,['method'=>'GET']);
reset_migration_expect(302,$page['status'],'Pending login redirects');
reset_migration_expect(200,retention_http_request('complete_profile.php',[],$pendingCookie,['method'=>'GET'])['status'],'Onboarding page accessible');
foreach(['documents_api.php?action=upload','documents_api.php?action=submit','documents_api.php?action=review','ierb_api.php?action=save','notifications_api.php?action=send','calendar_deadlines_api.php?action=create','students_api.php?action=save','account_invitation_api.php?action=invite'] as $path) {
    $blocked=retention_http_request($path,[],$pendingCookie);
    reset_migration_expect(403,$blocked['status'],'Pending Student direct operation denied: '.$path);
}
foreach(['email'=>'swap@example.invalid','targetId'=>100,'role'=>'admin','profile_completed_at'=>'now','studentId'=>['array']] as $key=>$value) {
    $fields=['name'=>'HTTP Student','studentId'=>'HTTP-NEW','academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027'];$fields[$key]=$value;
    reset_migration_expect(422,retention_http_request('account_invitation_api.php?action=complete',$fields,$pendingCookie)['status'],'HTTP completion rejects crafted field '.$key);
}
$fields=['name'=>'HTTP Student','studentId'=>'HTTP-NEW','academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027'];
reset_migration_expect(200,retention_http_request('account_invitation_api.php?action=complete',$fields,$pendingCookie)['status'],'HTTP Student completion');
reset_migration_expect(409,retention_http_request('account_invitation_api.php?action=complete',$fields,$pendingCookie)['status'],'HTTP completion is first-set only');
reset_migration_expect(200,retention_http_request('ierb_api.php?action=list',[],$pendingCookie,['method'=>'GET'])['status'],'Completed Student workflow opens');
// Adviser setup, direct pending authority denial and normal workspace after completion.
$result=retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'adviser','email'=>'http-adviser@example.invalid'],$cookie);
reset_migration_expect(200,$result['status'],'Admin email-only Adviser invitation');
$aid=(int)$result['data']['id'];$adviser=$pdo->query('SELECT * FROM advisers WHERE id='.$aid)->fetch();$auid=(int)$adviser['user_id'];
$token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE account_invitations SET token_hash=? WHERE user_id=?')->execute([hash('sha256',$token),$auid]);
retention_http_request('account_setup.php',['token'=>$token,'password'=>$fixturePassword,'confirmPassword'=>$fixturePassword],$anon,['form'=>true]);
$adviserCookie=retention_http_login($auid);
foreach(['account_invitation_api.php?action=invite','students_api.php?action=save','documents_api.php?action=review','send_followup.php','notifications_api.php?action=send','calendar_deadlines_api.php?action=create','ierb_api.php?action=save'] as $path) {
    reset_migration_expect(403,retention_http_request($path,[],$adviserCookie)['status'],'Pending Adviser direct operation denied: '.$path);
}
reset_migration_expect(200,retention_http_request('account_invitation_api.php?action=complete',['name'=>'HTTP Adviser','employeeId'=>'HTTP-EMP','department'=>'Accountancy / Management / Technology'],$adviserCookie)['status'],'HTTP Adviser completion');
reset_migration_expect(403,retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'adviser','email'=>'forbidden-role@example.invalid'],$adviserCookie)['status'],'Adviser cannot invite Adviser');
$own=retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'student','email'=>'own-http@example.invalid'],$adviserCookie);
reset_migration_expect(200,$own['status'],'Complete Adviser invites Student');
reset_migration_expect($aid,(int)$pdo->query('SELECT adviser_id FROM students WHERE id='.(int)$own['data']['id'])->fetchColumn(),'HTTP Adviser relationship derives server-side');
reset_migration_expect(422,retention_http_request('account_invitation_api.php?action=invite',['accountType'=>'student','email'=>'crafted-http@example.invalid','adviserId'=>200],$adviserCookie)['status'],'Forged Adviser relationship rejected');
reset_migration_expect(403,retention_http_request('account_invitation_api.php?action=resend',['accountType'=>'student','targetId'=>$sid],$adviserCookie)['status'],'Adviser cannot resend another assignment');
reset_migration_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE details LIKE '%token=%'")->fetchColumn(),'No token in application audit');
require __DIR__.'/account-onboarding-remediation-http.php';
echo 'PASS: direct onboarding HTTP assertions '.($GLOBALS['checks']-$onboardingHttpStart).".\n";
