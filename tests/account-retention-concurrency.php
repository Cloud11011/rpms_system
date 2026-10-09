<?php
/** Real parallel processes against the parent's private DB; no installed configuration. */
function retention_worker_start(array $data,int $actor=1,array $extra=[]): array {
    global $port,$password,$datadir;
    $fixture=array_merge(['dsn'=>'mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle;charset=utf8mb4',
        'password'=>$password,'datadir'=>$datadir,'actor'=>$actor,'data'=>$data,'verification'=>PRISM_HARD_DELETE_VERIFICATION],$extra);
    $process=proc_open([PHP_BINARY,__DIR__.'/account-lifecycle-worker.php',json_encode($fixture)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated worker');fclose($pipes[0]);return [$process,$pipes];
}
function retention_worker_finish(array $worker): array {
    [$process,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    reset_migration_expect(0,$exit,'Worker exit: '.$err.' '.$out);reset_migration_expect('',$err,'No worker diagnostics');
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function retention_wait_gate(string $gate): void {
    $deadline=microtime(true)+8;
    while(!is_file($gate.'.ready')&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($gate.'.ready'))throw new RuntimeException('Worker gate timed out');
}
$raceStart=$GLOBALS['checks'];
foreach (['READ COMMITTED','REPEATABLE READ'] as $isolation) {
    foreach (['restore','archive','hold','remove_hold'] as $firstAction) {
        retention_test_reset();
        if($firstAction==='archive') {$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');}
        if($firstAction==='remove_hold')$pdo->exec('UPDATE students SET retention_hold=1 WHERE id=100');
        $gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
        $first=retention_worker_start(['accountType'=>'student','targetId'=>100,'action'=>$firstAction],1,['retentionGate'=>$gate,'isolation'=>$isolation]);
        retention_wait_gate($gate);
        $second=retention_worker_start(retention_test_input(),2,['isolation'=>$isolation]);
        usleep(50000);file_put_contents($gate,'release');
        $one=retention_worker_finish($first);$two=retention_worker_finish($second);
        reset_migration_expect(200,$one['status'],$firstAction.' wins target lock');
        reset_migration_expect($firstAction==='remove_hold'?200:409,$two['status'],$firstAction.' vs purge at '.$isolation);
        reset_migration_expect($firstAction==='remove_hold'?0:1,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Actual race outcome matches eligibility');
    }
    // Purge first: a waiting Restore/Hold cannot resurrect a deleted profile.
    foreach (['restore','hold','remove_hold','archive'] as $following) {
        retention_test_reset();$gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
        $first=retention_worker_start(retention_test_input(),1,['retentionGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
        $second=retention_worker_start(['accountType'=>'student','targetId'=>100,'action'=>$following],2,['isolation'=>$isolation]);
        usleep(50000);file_put_contents($gate,'release');
        reset_migration_expect(200,retention_worker_finish($first)['status'],'Purge wins lock');
        reset_migration_expect(404,retention_worker_finish($second)['status'],'Waiting '.$following.' cannot resurrect purged Student at '.$isolation);
        reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=100')->fetchColumn(),'Purged login absent');
    }
    foreach (['permanent_delete','retention_cleanup'] as $firstMethod) {
        retention_test_reset();$pdo->exec('UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 7 MONTH) WHERE id=100');
        $gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
        $first=retention_worker_start(retention_test_input('student',$firstMethod),1,['retentionGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
        $second=retention_worker_start(retention_test_input(),2,['isolation'=>$isolation]);
        $two=retention_worker_finish($second);file_put_contents($gate,'release');$one=retention_worker_finish($first);
        reset_migration_expect(409,$two['status'],'Second Admin/manual purge serialized against '.$firstMethod);
        reset_migration_expect(200,$one['status'],'Exactly one purge commits');
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM account_purge_jobs')->fetchColumn(),'No duplicate purge/audit job');
    }
    // A captured active recipient/report cannot persist after Archive→Purge.
    foreach (['notification_writer','report_writer'] as $mode) {
        retention_test_reset();$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');
        $gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
        $writer=retention_worker_start([],1,['mode'=>$mode,'waitFile'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
        archive_student($pdo,retention_test_actor(),100);$pdo->exec('UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 7 DAY) WHERE id=100');
        lifecycle_execute($pdo,retention_test_actor(),retention_test_input());file_put_contents($gate,'release');$result=retention_worker_finish($writer);
        reset_migration_expect($mode==='report_writer'?409:200,$result['status'],'Final actual persistence guard: '.$mode);
        reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM '.($mode==='report_writer'?'reports':'notifications'))->fetchColumn(),'No stale writer resurrects owned data');
    }
    // Actual Student save endpoint waits on the Adviser and rejects a purged assignment.
    retention_test_reset();$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id=100');
    $gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
    $first=retention_worker_start(retention_test_input('adviser'),1,['retentionGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
    $save=['id'=>100,'studentId'=>'S100','name'=>'Student One','email'=>'student@example.invalid','adviserId'=>200,'stage'=>'Stage 1','status'=>'On Track','research'=>'','group'=>''];
    $writer=retention_worker_start($save,2,['mode'=>'student_save','isolation'=>$isolation]);usleep(50000);file_put_contents($gate,'release');
    reset_migration_expect(200,retention_worker_finish($first)['status'],'Adviser purge commits');
    $writerResult=retention_worker_finish($writer);reset_migration_expect(true,in_array($writerResult['status'],[409,422],true),'Reassignment cannot attach to purged Adviser');
    reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Student stays Unassigned');
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Adviser race never deletes Student');
    // Fresh preview becomes stale while an actual Student save holds the Adviser lock.
    retention_test_reset();$pdo->exec('UPDATE advisers SET status="Active",archived_at=NULL WHERE id=200');
    $pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');$pdo->exec('UPDATE users SET status="Active" WHERE id IN (100,200)');
    $expected=(int)retention_scope_rows($pdo,retention_test_actor(),'adviser',[],[200])[0]['assigned_students'];
    reset_migration_expect(0,$expected,'Archive preview initially has zero assignments');
    $gate=$datadir.'/assignment-'.bin2hex(random_bytes(5));
    $writer=retention_worker_start($save,2,['mode'=>'student_save','assignmentGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
    $archive=retention_worker_start(['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>$expected],1,['isolation'=>$isolation]);
    usleep(50000);file_put_contents($gate,'release');
    reset_migration_expect(200,retention_worker_finish($writer)['status'],'Actual Student assignment commits before waiting archive');
    reset_migration_expect(409,retention_worker_finish($archive)['status'],'Concurrent assignment rejects stale Adviser confirmation at '.$isolation);
    reset_migration_expect(200,(int)$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Rejected stale archive preserves new assignment');
    reset_migration_expect(null,$pdo->query('SELECT archived_at FROM advisers')->fetchColumn(),'Rejected stale archive preserves active Adviser');
    reset_migration_expect('Active',$pdo->query('SELECT status FROM users WHERE id=200')->fetchColumn(),'Rejected stale archive preserves Adviser login');
    reset_migration_expect(1,retention_change($pdo,retention_test_actor(),['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>1])['unassignedStudents'],'Fresh concurrent impact count succeeds');
    // A late workflow audit waits for the purge lock, then skips the absent Student.
    retention_test_reset();$gate=$datadir.'/retention-'.bin2hex(random_bytes(5));
    $first=retention_worker_start(retention_test_input(),1,['retentionGate'=>$gate,'isolation'=>$isolation]);retention_wait_gate($gate);
    $writer=retention_worker_start([],2,['mode'=>'late_student_audit','isolation'=>$isolation]);usleep(50000);file_put_contents($gate,'release');
    reset_migration_expect(200,retention_worker_finish($first)['status'],'Student purge before late audit commits');
    reset_migration_expect(200,retention_worker_finish($writer)['status'],'Best-effort late audit safely skips');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE student_id=100')->fetchColumn(),'Late audit cannot recreate Student-owned data');
}
// Existing worker's real delivery mutex must block purge independently of a stale status.
retention_test_reset();$pdo->exec("INSERT INTO notifications (id,recipient_type,recipient_id,message,status) VALUES (77,'student',100,'Queued','Scheduled')");
$lease=new PDO($faultDsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lease->query("SELECT GET_LOCK('prism_notification_77',0)");retention_test_failure(retention_test_input());$lease->query("SELECT RELEASE_LOCK('prism_notification_77')");
reset_migration_expect(true,lifecycle_execute($pdo,retention_test_actor(),retention_test_input())['ok'],'Purge succeeds when real worker lease ends');
echo 'PASS: concurrency checks '.($GLOBALS['checks']-$raceStart).' at both supported isolation levels; cumulative '.$GLOBALS['checks'].".\n";
