<?php
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
require __DIR__.'/v10-batch10-bootstrap.php';
$b10Start=$GLOBALS['nativeChecks'];
function b10_expect(mixed $a,mixed $b,string $label): void { reset_migration_expect($a,$b,'Batch10 '.$label); }
function b10_rows(string $sql,array $args=[]): array { return lifecycle_rows(db(),$sql,$args); }
function b10_actor(int $id): array { return b10_rows('SELECT * FROM users WHERE id=?',[$id])[0]; }
function b10_doc(string $id): array { return document_with_return(db(),b10_rows('SELECT * FROM documents WHERE id=?',[$id])[0]); }
function b10_refuse(callable $fn,int $code): void {
    try { $fn(); } catch (DocumentWorkflowError $e) { b10_expect($code,$e->status,'safe refusal');b10_expect(false,db()->inTransaction(),'refusal closes transaction');return; }
    throw new RuntimeException('Expected workflow refusal.');
}
function b10_new(int $sid=1003,string $type='Research Proposal',string $stage='Stage 1',?string $prev=null): string {
    $id=bin2hex(random_bytes(12));$student=b10_rows('SELECT * FROM students WHERE id=?',[$sid])[0];
    $version=$prev?(int)b10_doc($prev)['version_no']+1:1;
    $q=db()->prepare("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current,supersedes_id) VALUES (?,?,?,'Fixture Student','student',?,?, 'application/pdf',100,?,?,'Submitted',?,1,?)");
    $q->execute([$id,$sid,$student['full_name'],'Fixture v'.$version.'.pdf',$id.'.pdf',$type,$stage,$version,$prev]);
    if($prev)db()->prepare('UPDATE documents SET is_current=0 WHERE id=?')->execute([$prev]);
    return $id;
}
function b10_race(array $cases): array {
    $gate=$GLOBALS['root'].'/b10-gate-'.bin2hex(random_bytes(5));$workers=[];
    foreach($cases as $case){
        $ready=$gate.'-'.count($workers);$process=proc_open([PHP_BINARY,__DIR__.'/v10-batch10-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        fwrite($pipes[0],json_encode($case+['dsn'=>$GLOBALS['dsn'],'password'=>$GLOBALS['password'],'datadir'=>$GLOBALS['datadir'],'ready'=>$ready,'gate'=>$gate]));fclose($pipes[0]);$workers[]=[$process,$pipes,$ready];
    }
    foreach($workers as [$process,$pipes,$ready]){ $deadline=microtime(true)+15;while(!is_file($ready)&&microtime(true)<$deadline)usleep(10000);b10_expect(true,is_file($ready),'native barrier readiness'); }
    file_put_contents($gate,'start');$results=[];
    foreach($workers as [$process,$pipes,$ready]){ $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);b10_expect(0,proc_close($process),'native worker exit '.$err);b10_expect('',$err,'native worker diagnostics');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);unlink($ready); }
    unlink($gate);return $results;
}
$dsn='mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4';
foreach ([1001=>'admin',1002=>'adviser',1003=>'student',1004=>'student',1005=>'adviser',1006=>'admin'] as $uid=>$role){
    $pdo->prepare('INSERT INTO users(id,username,ref_id,full_name,email,role,status,password_hash,must_change_password) VALUES (?,?,?,?,?,?,\'Active\',?,0)')
        ->execute([$uid,'B10-'.$uid,'B10-'.$uid,'Batch10 '.$uid,'b10-'.$uid.'@example.invalid',$role,password_hash('Batch10 fixture passphrase',PASSWORD_DEFAULT)]);
    legal_accept($pdo,b10_actor($uid),['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
}
foreach([1002,1005] as $uid)$pdo->prepare("INSERT INTO advisers(id,employee_id,user_id,full_name,email,status,profile_completed_at) VALUES (?,?,?,?,?,'Active',NOW())")->execute([$uid,'B10-'.$uid,$uid,'Batch10 '.$uid,'b10-'.$uid.'@example.invalid']);
foreach([1003,1004] as $uid)$pdo->prepare("INSERT INTO students(id,student_id,user_id,full_name,email,adviser_id,profile_completed_at,stage,status) VALUES (?,?,?,?,?,?,NOW(),'Stage 1','On Track')")->execute([$uid,'B10-'.$uid,$uid,'Batch10 '.$uid,'b10-'.$uid.'@example.invalid',$uid===1003?1002:1005]);
$adviser=b10_actor(1002);$admin=b10_actor(1001);$student=b10_actor(1003);
$v1=b10_new();$baseline=b10_doc($v1);
foreach ([$student,$admin,b10_actor(1005)] as $actor)b10_refuse(fn()=>document_workflow_change($pdo,$actor,$v1,'review','Approved'),403);
b10_refuse(fn()=>document_workflow_change($pdo,$student,$v1,'return','','Reason'),403);
b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$v1,'return','','Reason'),403);
b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v1,'return','','  '),422);
b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v1,'return','','Reason'),409);
// Inject real PDO failures at distinct persistence boundaries, without production hooks.
class B10FailurePDO extends PDO {
    public string $fail='';
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if ($this->fail!=='' && $this->fail!=='commit' && str_contains($query,$this->fail)) throw new RuntimeException('Synthetic fixture failure');
        return parent::prepare($query,$options);
    }
    public function commit(): bool { if($this->fail==='commit')throw new RuntimeException('Synthetic precommit failure');return parent::commit(); }
}
$fail=new B10FailurePDO($dsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['UPDATE documents SET rpms_submitted_at','INSERT INTO activity_logs','INSERT INTO ierb_history','INSERT INTO notification_campaigns','INSERT INTO notifications','commit'] as $point){
    $fail->fail=$point;$before=b10_rows('SELECT * FROM activity_logs');$copies=b10_rows('SELECT * FROM notifications');$campaigns=b10_rows('SELECT * FROM notification_campaigns');$profile=b10_rows('SELECT * FROM students WHERE id=1003');
    try{document_workflow_change($fail,$adviser,$v1,'review','Approved');throw new LogicException('Injection did not fail.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    b10_expect($baseline,b10_doc($v1),'approval rollback exact document '.$point);b10_expect($before,b10_rows('SELECT * FROM activity_logs'),'audit rollback '.$point);b10_expect($copies,b10_rows('SELECT * FROM notifications'),'notification rollback '.$point);b10_expect($campaigns,b10_rows('SELECT * FROM notification_campaigns'),'campaign rollback '.$point);b10_expect($profile,b10_rows('SELECT * FROM students WHERE id=1003'),'stage rollback '.$point);
}
$case=['actor'=>$adviser,'id'=>$v1,'operation'=>'review','status'=>'Approved'];$race=b10_race([$case,$case]);
b10_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'two approvals one authoritative transition');
$approved=b10_doc($v1);b10_expect(WF_SUBMITTED_RPMS,document_workflow_state($approved),'atomic approved/formally submitted');b10_expect('Approved',$approved['review_status'],'approval stored');b10_expect(true,!empty($approved['reviewed_at'])&&!empty($approved['rpms_submitted_at']),'both timestamps');
b10_expect(1,count(b10_rows("SELECT id FROM activity_logs WHERE entity_id=? AND action='document_approved'",[$v1])),'one approval audit');b10_expect(1,count(b10_rows("SELECT id FROM activity_logs WHERE entity_id=? AND action='document_submitted_to_rpms'",[$v1])),'one formal submission audit');
b10_expect(1,count(b10_rows('SELECT id FROM notification_campaigns')),'one approval campaign');b10_expect(3,count(b10_rows('SELECT id FROM notifications')),'one approval copy per Student/active Admin');
b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$v1,'review','Approved'),409);
foreach(['INSERT INTO activity_logs','INSERT INTO notification_campaigns','INSERT INTO notifications','commit'] as $point){
    $fail->fail=$point;$events=b10_rows('SELECT * FROM activity_logs');$copies=b10_rows('SELECT * FROM notifications');$campaigns=b10_rows('SELECT * FROM notification_campaigns');
    try{document_workflow_change($fail,$admin,$v1,'return','','Synthetic Return reason.');throw new LogicException('Injection did not fail.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    b10_expect($approved,b10_doc($v1),'Return rollback exact version '.$point);b10_expect($events,b10_rows('SELECT * FROM activity_logs'),'Return rollback audit '.$point);b10_expect($copies,b10_rows('SELECT * FROM notifications'),'Return rollback copies '.$point);b10_expect($campaigns,b10_rows('SELECT * FROM notification_campaigns'),'Return rollback campaign '.$point);
}
$case=['actor'=>$admin,'id'=>$v1,'operation'=>'return','reason'=>'Correct the methodology.'];$race=b10_race([$case,$case]);b10_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'two returns one authoritative transition');
$returned=b10_doc($v1);b10_expect(WF_RETURNED_REVISION,document_workflow_state($returned),'returned state');
foreach(['review_status','reviewed_by','reviewed_at','rpms_submitted_at','rpms_submitted_by','stored_name'] as $f)b10_expect($approved[$f],$returned[$f],'return preserves '.$f);
b10_expect('Correct the methodology.',$returned['rpms_return_reason'],'return reason');b10_expect($admin['full_name'],$returned['rpms_returned_by'],'return actor');
b10_expect(1,count(b10_rows("SELECT id FROM activity_logs WHERE entity_id=? AND action='document_returned_for_revision'",[$v1])),'one return audit');
b10_expect(2,count(b10_rows('SELECT id FROM notification_campaigns')),'one Return campaign');b10_expect(5,count(b10_rows('SELECT id FROM notifications')),'one Return copy per Student/assigned Adviser');
$retention=$pdo->query('SELECT '.retention_unresolved_sql('student','s').' AS open FROM students s WHERE id=1003')->fetchColumn();b10_expect(1,(int)$retention,'returned current revision prevents purge');
$v2=b10_new(1003,'Research Proposal','Stage 1',$v1);b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$v1,'review','Approved'),409);b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v1,'return','','Again'),409);
document_workflow_change($pdo,$adviser,$v2,'review','Approved');document_workflow_change($pdo,$admin,$v2,'return','','Clarify the sample.');
$v3=b10_new(1003,'Research Proposal','Stage 1',$v2);document_workflow_change($pdo,$adviser,$v3,'review','Approved');
b10_expect($approved['rpms_submitted_at'],b10_doc($v1)['rpms_submitted_at'],'original timestamp survives three cycles');b10_expect(2,count(b10_rows("SELECT id FROM activity_logs WHERE student_id=1003 AND action='document_resubmitted_to_rpms'")),'two distinct resubmission events');b10_expect('Stage 2',b10_rows('SELECT stage FROM students WHERE id=1003')[0]['stage'],'resubmissions do not advance unrelated current stage');
$race=b10_race([['actor'=>$admin,'id'=>$v3,'operation'=>'return','reason'=>'Cross-role race return.'],['actor'=>$adviser,'id'=>$v3,'operation'=>'review','status'=>'Approved']]);
b10_expect(true,$race[0]['ok'],'Return/adviser race Return succeeds');b10_expect(false,$race[1]['ok'],'Return/adviser race cannot reapprove submitted version');b10_expect(WF_RETURNED_REVISION,document_workflow_state(b10_doc($v3)),'cross-role race consistent state');
foreach(['Denied','Resubmission Requested'] as $outcome){$id=b10_new(1003,$outcome);b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$id,'review',$outcome,''),422);document_workflow_change($pdo,$adviser,$id,'review',$outcome,'Feedback required.');b10_expect(null,b10_doc($id)['rpms_submitted_at'],'corrective decision never submits');b10_expect($outcome,b10_doc($id)['review_status'],'decision remains distinct');b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$id,'review','Approved'),409);}
$id=b10_new(1003,'Auth test');
foreach(['Pending','Inactive'] as $s){$pdo->prepare('UPDATE users SET status=? WHERE id=1001')->execute([$s]);b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v3,'return','','Reason'),403);}$pdo->exec("UPDATE users SET status='Active' WHERE id=1001");
$pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=1001');b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v3,'return','','Reason'),403);legal_accept($pdo,$admin,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
$pdo->exec('UPDATE students SET adviser_id=1005 WHERE id=1003');b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$id,'review','Approved'),403);$pdo->exec('UPDATE students SET adviser_id=1002 WHERE id=1003');
$pdo->exec('UPDATE students SET archived_at=NOW() WHERE id=1003');b10_refuse(fn()=>document_workflow_change($pdo,$admin,$v3,'return','','Reason'),409);$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=1003');
$legacy=b10_new(1003,'Legacy approved');$pdo->prepare("UPDATE documents SET review_status='Approved',reviewed_at='2026-09-01 12:00:00',reviewed_by='Historical Adviser' WHERE id=?")->execute([$legacy]);
$historyCount=count(b10_rows('SELECT id FROM activity_logs'));document_workflow_change($pdo,$adviser,$legacy,'review','Approved');
b10_expect(WF_SUBMITTED_RPMS,document_workflow_state(b10_doc($legacy)),'eligible legacy approval gets prospective formal submission');b10_expect(true,count(b10_rows('SELECT id FROM activity_logs'))>$historyCount,'legacy action appends prospective evidence');
b10_expect('Historical Adviser',b10_doc($legacy)['reviewed_by'],'legacy confirmation preserves historical reviewer');b10_expect('2026-09-01 12:00:00',b10_doc($legacy)['reviewed_at'],'legacy confirmation preserves historical approval time');
// A duplicate legacy current marker must not make an older version authoritative.
$oldMarker=b10_new(1003,'Duplicate current');$newMarker=b10_new(1003,'Duplicate current','Stage 1',$oldMarker);$pdo->prepare('UPDATE documents SET is_current=1 WHERE id=?')->execute([$oldMarker]);
b10_refuse(fn()=>document_workflow_change($pdo,$adviser,$oldMarker,'review','Approved'),409);$pdo->prepare('UPDATE documents SET is_current=0 WHERE id=?')->execute([$oldMarker]);
$pdo->prepare('UPDATE users SET full_name=? WHERE id=1002')->execute([str_repeat('界',190)]);
$longNameDoc=b10_new(1003,'Full name boundary','Stage 2');document_workflow_change($pdo,b10_actor(1002),$longNameDoc,'review','Approved');
b10_expect(190,mb_strlen(b10_doc($longNameDoc)['rpms_submitted_by']),'maximum Unicode name bounded formal actor');
b10_expect(190,mb_strlen(b10_doc($longNameDoc)['reviewed_by']),'maximum Unicode reviewer preserved');
b10_expect('Stage 3',b10_rows('SELECT stage FROM students WHERE id=1003')[0]['stage'],'maximum name approval also advances atomically');
$pdo->prepare('UPDATE users SET full_name=? WHERE id=1002')->execute([$adviser['full_name']]);
lifecycle_compare_schema($pdo);
echo 'PASS: '.($GLOBALS['nativeChecks']-$b10Start)." Batch10 native atomicity, failure rollback, authorization, concurrency and three-cycle history assertions.\n";
require __DIR__.'/v10-batch10-http.php';
require __DIR__.'/v10-batch10-legal.php';
