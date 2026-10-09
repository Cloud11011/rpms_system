<?php
/** Real parallel shared-service requests; verified private datadir, two isolation levels. */
$onboardingRaceStart=$GLOBALS['checks'];
function onboarding_test_pair(array $one,array $two,string $isolation): array {
    global $datadir;
    $gate=$datadir.'/onboarding-race-'.bin2hex(random_bytes(6));
    $a=retention_worker_start($one['data'],$one['actor'],['mode'=>$one['mode'],'waitFile'=>$gate,'isolation'=>$isolation]);
    $b=retention_worker_start($two['data'],$two['actor'],['mode'=>$two['mode'],'waitFile'=>$gate.'b','isolation'=>$isolation]);
    retention_wait_gate($gate);retention_wait_gate($gate.'b');file_put_contents($gate,'release');file_put_contents($gate.'b','release');
    return [retention_worker_finish($a),retention_worker_finish($b)];
}
foreach(['READ COMMITTED','REPEATABLE READ'] as $isolation) {
    foreach([[1,2],[1,200],[200,201]] as [$actorA,$actorB]) {
        retention_test_reset();$pdo->exec('UPDATE advisers SET archived_at=NULL,status="Active"');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
        $pdo->prepare('INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id) VALUES (201,?,?,"adviser","Second Adviser","second@example.invalid","E201")')->execute(['E201',$fixtureHash]);
        $pdo->exec('INSERT INTO advisers(id,employee_id,full_name,email,user_id,profile_completed_at) VALUES (201,"E201","Second Adviser","second@example.invalid",201,NOW())');
        $input=['accountType'=>'student','email'=>'same-race@example.invalid'];
        [$a,$b]=onboarding_test_pair(['mode'=>'onboarding_invite','actor'=>$actorA,'data'=>$input],['mode'=>'onboarding_invite','actor'=>$actorB,'data'=>$input],$isolation);
        reset_migration_expect(1,count(array_filter([$a,$b],fn($r)=>$r['status']===200)),'Exactly one concurrent email invitation succeeds');
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM users WHERE email="same-race@example.invalid"')->fetchColumn(),'One authoritative concurrent identity');
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'One live invitation');
    }
    foreach(['student','adviser'] as $role) {
        retention_test_reset();$r=onboarding_test_pending($role);$login=onboarding_test_accept($r);$uid=(int)$r['user_id'];
        $request=['mode'=>'onboarding_finish','actor'=>$uid,'data'=>onboarding_test_fields($role)];
        [$a,$b]=onboarding_test_pair($request,$request,$isolation);
        reset_migration_expect(1,count(array_filter([$a,$b],fn($r)=>$r['status']===200)),'One completion wins same-profile race');
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE action="account_profile_completed"')->fetchColumn(),'One durable completion audit');
        retention_test_reset();$first=onboarding_test_pending($role);onboarding_test_accept($first);$second=onboarding_test_pending($role);onboarding_test_accept($second);
        [$a,$b]=onboarding_test_pair(['mode'=>'onboarding_finish','actor'=>(int)$first['user_id'],'data'=>onboarding_test_fields($role,'SAME-ID')],['mode'=>'onboarding_finish','actor'=>(int)$second['user_id'],'data'=>onboarding_test_fields($role,'SAME-ID')],$isolation);
        reset_migration_expect(1,count(array_filter([$a,$b],fn($r)=>$r['status']===200)),'One concurrent institutional ID first-set');
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM '.retention_table($role).' WHERE profile_completed_at IS NOT NULL AND '.($role==='student'?'student_id':'employee_id').'="SAME-ID"')->fetchColumn(),'Database prevents duplicate completed IDs');
    }
    foreach(['archive','hold','purge','resend','adviser_archive','assign'] as $kind) {
        retention_test_reset();$r=onboarding_test_pending();$uid=(int)$r['user_id'];$id=(int)$r['id'];$token=$GLOBALS['invitationTestToken'];
        if($kind!=='resend')onboarding_test_accept($r);
        $finish=['mode'=>'onboarding_finish','actor'=>$uid,'data'=>onboarding_test_fields()];
        if($kind==='resend') {
            $pdo->exec('UPDATE account_invitations SET last_sent_at=DATE_SUB(NOW(),INTERVAL 61 SECOND)');
            $finish=['mode'=>'onboarding_accept','actor'=>$uid,'data'=>['token'=>$token,'password'=>$fixturePassword,'confirmPassword'=>$fixturePassword]];
            $other=['mode'=>'onboarding_resend','actor'=>1,'data'=>['accountType'=>'student','targetId'=>$id]];
        } elseif($kind==='assign') $other=['mode'=>'onboarding_assign','actor'=>1,'data'=>['targetId'=>$id,'adviserId'=>null]];
        elseif($kind==='adviser_archive') {
            $pdo->exec('UPDATE advisers SET archived_at=NULL,status="Active"');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');$pdo->exec('UPDATE students SET adviser_id=200 WHERE id='.$id);
            $other=['mode'=>'lifecycle','actor'=>1,'data'=>['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>1]];
        } else {
            $other=['mode'=>'lifecycle','actor'=>1,'data'=>['accountType'=>'student','targetId'=>$id,'action'=>'archive']];
            if(in_array($kind,['hold','purge'],true)) {
                retention_change($pdo,retention_test_actor(),$other['data']);
                $other['data']['action']=$kind==='hold'?'hold':'permanent_delete';
                if($kind==='purge'){$pdo->exec('UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 7 DAY) WHERE id='.$id);$other['data']+=['currentPassword'=>$fixturePassword,'confirmation'=>$r['email'],'confirmed'=>true];}
            }
        }
        [$a,$b]=onboarding_test_pair($finish,$other,$isolation);
        reset_migration_expect(true,in_array($a['status'],[200,403,409,422,503],true),'Completion race has a controlled result: '.$kind);
        reset_migration_expect(true,in_array($b['status'],[200,409,503],true),'Lifecycle race has a controlled result: '.$kind);
        if($kind==='adviser_archive')reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students WHERE id='.$id)->fetchColumn(),'Adviser archive cannot leave stale assignment');
        if($kind==='purge')reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'Purge race removes setup credentials');
        if($kind==='hold')reset_migration_expect(null,$pdo->query('SELECT profile_completed_at FROM students WHERE id='.$id)->fetchColumn(),'Archived Hold race cannot activate profile');
    }
    foreach(['onboarding_assign','onboarding_invite'] as $mode) {
        retention_test_reset();$pdo->exec('UPDATE advisers SET archived_at=NULL,status="Active" WHERE id=200');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
        $r=onboarding_test_pending();
        $data=$mode==='onboarding_assign'?['targetId'=>(int)$r['id'],'adviserId'=>200]:['accountType'=>'student','email'=>'assignment-race@example.invalid','adviserId'=>200];
        [$a,$b]=onboarding_test_pair(['mode'=>$mode,'actor'=>1,'data'=>$data],['mode'=>'lifecycle','actor'=>2,'data'=>['action'=>'archive','accountType'=>'adviser','targetId'=>200,'expectedAssignedStudents'=>0]],$isolation);
        reset_migration_expect(true,in_array($a['status'],[200,409,503],true),'Assignment/archive race has controlled assignment result');
        reset_migration_expect(true,in_array($b['status'],[200,409,503],true),'Assignment/archive race has controlled archive result');
        $archived=$pdo->query('SELECT archived_at FROM advisers WHERE id=200')->fetchColumn();
        if($archived!==null) {
            reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE adviser_id=200')->fetchColumn(),'Archive never leaves new Pending assignment attached');
            reset_migration_expect('Inactive',$pdo->query('SELECT status FROM users WHERE id=200')->fetchColumn(),'Archived assignment target login inactive');
        } else {
            reset_migration_expect('Active',$pdo->query('SELECT status FROM users WHERE id=200')->fetchColumn(),'Assignment winner retains usable Adviser');
            reset_migration_expect('Active',$pdo->query('SELECT status FROM advisers WHERE id=200')->fetchColumn(),'Assignment winner retains active Adviser profile');
        }
    }
}
echo 'PASS: onboarding concurrency assertions '.($GLOBALS['checks']-$onboardingRaceStart).'; cumulative '.$GLOBALS['checks'].".\n";
