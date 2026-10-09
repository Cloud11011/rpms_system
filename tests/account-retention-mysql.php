<?php
/** Runs exclusively inside account-lifecycle-runner's verified disposable MariaDB. */
if (PHP_SAPI!=='cli' || !isset($pdo,$datadir,$root) || !function_exists('reset_migration_expect')) exit(1);
$pdo->exec('CREATE DATABASE hardening_test_lifecycle'); $pdo->exec('USE hardening_test_lifecycle');
$GLOBALS['allowV7']=$GLOBALS['allowV8']=true;
PrismResetMigrationSQL\migrate($pdo);
reset_migration_expect('8',(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Start from v8');
$pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email,status) VALUES (99,'LEGACY','Legacy Adviser','legacy@example.invalid','Inactive')");
$GLOBALS['allowV9']=true;
PrismResetMigrationSQL\migrate($pdo);
require_once __DIR__.'/../includes/account_lifecycle.php';
$metadata=lifecycle_schema_inventory($pdo);
reset_migration_expect('9',$metadata['schema_version'],'v8→v9 migration');
reset_migration_expect(true,(bool)$pdo->query('SELECT archived_at FROM advisers WHERE id=99')->fetchColumn(),'Legacy inactive Adviser gets conservative new date');
$before=lifecycle_schema_inventory($pdo); PrismResetMigrationSQL\migrate_schema_v9($pdo);
reset_migration_expect($before,lifecycle_schema_inventory($pdo),'v9 retry-safe schema');
reset_migration_expect(12,count($metadata['foreign_keys']),'Invitation ownership and inviter FKs; existing FKs preserved');
reset_migration_expect(17,count($metadata['tables']),'Recovery journal and shared invitation table added');
if ($argv[1]==='--retention-manifest') {
    file_put_contents(__DIR__.'/../includes/account_lifecycle_schema.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    echo 'PASS: additive v9 manifest from isolated MariaDB; '.$GLOBALS['checks']." assertions.\n";
    return;
}
require __DIR__.'/account-retention-migration.php';
lifecycle_compare_schema($pdo);
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',true);
define('PRISM_HARD_DELETE_VERIFICATION',lifecycle_privileged_verification($pdo));
define('STORAGE_DIR',$root.DIRECTORY_SEPARATOR.'storage');
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
function stage_label(string $stage): string { return $stage; }
mkdir(STORAGE_DIR); mkdir(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'); mkdir(STORAGE_DIR.DIRECTORY_SEPARATOR.'reports');
$pdo->exec("SET time_zone='+08:00'");
$fixturePassword='Synthetic retention passphrase'; $fixtureHash=password_hash($fixturePassword,PASSWORD_DEFAULT);
function db(): PDO { return $GLOBALS['pdo']; }
function log_api_error(...$args): void { if(($args[0]??'')==='account_invitation') { $GLOBALS['invitationErrors']=($GLOBALS['invitationErrors']??0)+1; return; } throw new RuntimeException('Unexpected test API error'); }
require_once __DIR__.'/../workflow.php';
function retention_test_reset(): void {
    $pdo=db();
    foreach (['calendar_deadline_recipients','calendar_deadline_groups','calendar_deadlines','documents','ierb_history','password_resets',
        'notifications','reports','ai_outputs','activity_logs','account_purge_jobs','students','advisers','users'] as $table) $pdo->exec('DELETE FROM '.$table);
    $q=$pdo->prepare('INSERT INTO users (id,username,password_hash,role,full_name,email,ref_id,status) VALUES (?,?,?,?,?,?,?,?)');
    foreach ([[1,'A1','admin','Admin One','admin1@example.invalid','A1','Active'],[2,'A2','admin','Admin Two','admin2@example.invalid','A2','Active'],
        [100,'S100','student','Student One','student@example.invalid','S100','Inactive'],[200,'E200','adviser','Adviser One','adviser@example.invalid','E200','Inactive']] as $r) {
        array_splice($r,2,0,[$GLOBALS['fixtureHash']]); $q->execute($r);
    }
    $pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email,status,user_id,archived_at) VALUES (200,'E200','Adviser One','adviser@example.invalid','Inactive',200,DATE_SUB(NOW(),INTERVAL 7 DAY))");
    $pdo->exec("INSERT INTO students (id,student_id,full_name,email,user_id,archived_at) VALUES (100,'S100','Student One','student@example.invalid',100,DATE_SUB(NOW(),INTERVAL 7 DAY))");
    $pdo->exec('UPDATE students SET profile_completed_at=created_at');
    $pdo->exec('UPDATE advisers SET profile_completed_at=created_at');
    $_SESSION=[];
}
function retention_test_actor(int $id=1): array { return db()->query('SELECT * FROM users WHERE id='.$id)->fetch(); }
function retention_test_input(string $type='student',string $action='permanent_delete'): array {
    return ['accountType'=>$type,'action'=>$action,'targetId'=>$type==='student'?100:200,'currentPassword'=>$GLOBALS['fixturePassword'],
        'confirmation'=>$type==='student'?'S100':'E200','confirmed'=>true];
}
function retention_test_failure(array $data,string $class=AccountLifecycleConflict::class,int $actor=1): void {
    $before=db()->query('SELECT * FROM users ORDER BY id')->fetchAll(); $error=null;
    try { lifecycle_execute(db(),retention_test_actor($actor),$data); } catch(Throwable $e) { $error=$e; }
    reset_migration_expect($class,$error?get_class($error):null,'Correct refusal: '.($error?->getMessage()??'unexpected success'));
    reset_migration_expect($before,db()->query('SELECT * FROM users ORDER BY id')->fetchAll(),'Failure preserves login');
    reset_migration_expect(false,db()->inTransaction(),'Refusal closes transaction');
}
retention_test_reset();
echo 'PASS: migration and schema assertions '.$GLOBALS['checks'].".\n";

foreach (['student','adviser'] as $type) {
    $table=retention_table($type);$id=$type==='student'?100:200;
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=NULL WHERE id=$id");
    if ($type==='adviser') $pdo->exec('UPDATE advisers SET status="Active" WHERE id=200');
    retention_test_failure(retention_test_input($type));
    foreach ([0,6] as $days) {
        retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL $days DAY) WHERE id=$id");
        retention_test_failure(retention_test_input($type));
    }
    retention_test_reset(); $result=lifecycle_execute($pdo,retention_test_actor(),retention_test_input($type));
    reset_migration_expect(true,$result['ok'],'Day7 '.$type.' purge');
    reset_migration_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE id=$id")->fetchColumn(),'Profile removed');
    reset_migration_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE id=$id")->fetchColumn(),'Login removed');
    reset_migration_expect('complete',$pdo->query('SELECT status FROM account_purge_jobs')->fetchColumn(),'Files finalized before success');
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=NOW() WHERE id=$id");
    $input=retention_test_input($type,'grace_period_override');
    retention_test_failure($input,AccountLifecycleValidation::class);
    $input['reason']='Exceptional synthetic account cleanup';$result=lifecycle_execute($pdo,retention_test_actor(),$input);
    reset_migration_expect(true,$result['ok'],'Single grace override succeeds');
    reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='{$type}_grace_period_override_purge'")->fetchColumn(),'Override separately audited');
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL 6 DAY) WHERE id=$id");
    reset_migration_expect(true,lifecycle_execute($pdo,retention_test_actor(),$input)['ok'],'Day6 '.$type.' override succeeds');
    foreach ([7,8,30] as $days) {
        retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL $days DAY) WHERE id=$id");
        retention_test_failure($input);
        reset_migration_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='{$type}_grace_period_override_purge'")->fetchColumn(),'Elapsed override never audited or converted');
        reset_migration_expect(true,lifecycle_execute($pdo,retention_test_actor(),retention_test_input($type))['ok'],'Day'.$days.' normal '.$type.' deletion still succeeds');
    }
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=NOW(),retention_hold=1 WHERE id=$id");retention_test_failure($input);
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=NULL WHERE id=$id");retention_test_failure($input);
    foreach (['permanent_delete','grace_period_override','retention_cleanup'] as $method) {
        retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL 7 MONTH),retention_hold=1 WHERE id=$id");
        $input=retention_test_input($type,$method);$input['reason']='Synthetic exception';retention_test_failure($input);
    }
    retention_test_reset();
    lifecycle_execute($pdo,retention_test_actor(),['accountType'=>$type,'targetId'=>$id,'action'=>'hold','reason'=>'Synthetic institutional review']);
    reset_migration_expect(1,(int)$pdo->query("SELECT retention_hold FROM $table WHERE id=$id")->fetchColumn(),'Structured Hold');
    lifecycle_execute($pdo,retention_test_actor(),['accountType'=>$type,'targetId'=>$id,'action'=>'remove_hold']);
    reset_migration_expect(0,(int)$pdo->query("SELECT retention_hold FROM $table WHERE id=$id")->fetchColumn(),'Hold removed');
    $result=lifecycle_execute($pdo,retention_test_actor(),retention_test_input($type));reset_migration_expect(true,$result['ok'],'Hold removal restores eligibility');
    retention_test_reset();$beforeHash=$pdo->query("SELECT password_hash FROM users WHERE id=$id")->fetchColumn();
    $result=lifecycle_execute($pdo,retention_test_actor(),['accountType'=>$type,'targetId'=>$id,'action'=>'restore']);
    reset_migration_expect(null,$pdo->query("SELECT archived_at FROM $table WHERE id=$id")->fetchColumn(),'Restore clears archive');
    reset_migration_expect('Active',$pdo->query("SELECT status FROM users WHERE id=$id")->fetchColumn(),'Restore activates login');
    reset_migration_expect(false,$beforeHash===$pdo->query("SELECT password_hash FROM users WHERE id=$id")->fetchColumn(),'Old session fingerprint invalidated');
    reset_migration_expect('sha256:'.hash('sha256',$result['setupToken']),$pdo->query("SELECT token FROM password_resets WHERE user_id=$id")->fetchColumn(),'Valid hashed restore setup token');
    lifecycle_execute($pdo,retention_test_actor(),['accountType'=>$type,'targetId'=>$id,'action'=>'archive','expectedAssignedStudents'=>0]);
    reset_migration_expect(true,(bool)$pdo->query("SELECT archived_at>=DATE_SUB(NOW(),INTERVAL 2 SECOND) FROM $table WHERE id=$id")->fetchColumn(),'Rearchive gets new date');
    retention_test_failure(retention_test_input($type));
    retention_test_reset();$pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL 6 MONTH) WHERE id=$id");
    $result=lifecycle_execute($pdo,retention_test_actor(),retention_test_input($type,'retention_cleanup'));
    reset_migration_expect(true,$result['ok'],'Six calendar months cleanup '.$type);
    foreach ([['currentPassword','wrong'],['confirmation','wrong'],['confirmed',false]] as [$key,$value]) {
        retention_test_reset();$input=retention_test_input($type);$input[$key]=$value;retention_test_failure($input,AccountLifecycleValidation::class);
    }
    retention_test_reset();retention_test_failure(retention_test_input($type),AccountLifecycleForbidden::class,100);
}
reset_migration_expect('2027-02-28 12:00:00',$pdo->query("SELECT DATE_ADD('2026-08-31 12:00:00',INTERVAL 6 MONTH)")->fetchColumn(),'Calendar month-end clamp');
retention_test_reset();$pdo->exec('UPDATE students SET archived_at=DATE_SUB(DATE_ADD(NOW(),INTERVAL 2 DAY),INTERVAL 6 MONTH) WHERE id=100');
reset_migration_expect(true,retention_state(retention_scope_rows($pdo,retention_test_actor(),'student',[],[100])[0])['approachingRetention'],'Three-day warning on evaluation');

// Concrete unresolved states, including current vs superseded/submitted versions.
foreach (['Submitted','Denied','Resubmission Requested','Approved'] as $status) {
    retention_test_reset();$q=$pdo->prepare('INSERT INTO documents (id,student_id,original_name,stored_name,review_status) VALUES ("open",100,"Open","open.txt",?)');$q->execute([$status]);
    retention_test_failure(retention_test_input());
    reset_migration_expect(true,(bool)$pdo->query('SELECT purge_postponed_reason FROM students WHERE id=100')->fetchColumn(),'Postponement stored');
    $pdo->exec('UPDATE students SET archived_at=NOW() WHERE id=100');
    $input=retention_test_input('student','grace_period_override');$input['reason']='Synthetic exception';retention_test_failure($input);
}
foreach (['student','adviser'] as $type) {
    retention_test_reset();$q=$pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,message,status) VALUES (?,?,"In progress","Sending")');$q->execute([$type,$type==='student'?100:200]);
    retention_test_failure(retention_test_input($type));
    $pdo->exec('UPDATE '.retention_table($type).' SET archived_at=NOW()');
    $input=retention_test_input($type,'grace_period_override');$input['reason']='Synthetic exception';retention_test_failure($input);
}
retention_test_reset();$pdo->exec("INSERT INTO calendar_deadlines (creator_user_id,title,deadline_date,target_scope) VALUES (200,'Upcoming',DATE_ADD(CURDATE(),INTERVAL 1 DAY),'groups')");
retention_test_failure(retention_test_input('adviser'));
$pdo->exec('UPDATE advisers SET archived_at=NOW()');$input=retention_test_input('adviser','grace_period_override');$input['reason']='Synthetic exception';retention_test_failure($input);

// Full Student data minimization, all versions and files; aggregate/ambiguous artifacts preserved.
retention_test_reset();
foreach (['current.txt','old.txt'] as $name) file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.$name,'Synthetic '.$name);
file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'exclusive.pdf','Synthetic exclusive PDF');
file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'aggregate.pdf','Synthetic aggregate PDF');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current,rpms_submitted_at,review_status,ai_summary,supersedes_id)
    VALUES ('old',100,'Old','old.txt',0,NOW(),'Approved','Private old AI',NULL),('current',100,'Current','current.txt',1,NOW(),'Approved','Private AI','old')");
$pdo->exec("INSERT INTO ierb_history (student_id,note,actor) VALUES (100,'Substantive completed history','Adviser One')");
$pdo->exec("INSERT INTO notifications (recipient_type,recipient_id,message) VALUES ('student',100,'Student-specific notice'),('admin',1,'Institutional notice')");
$pdo->exec("INSERT INTO calendar_deadlines (id,creator_user_id,title,deadline_date,target_scope) VALUES (1,1,'Official',CURDATE(),'all'); INSERT INTO calendar_deadline_recipients VALUES (1,100)");
$pdo->exec("INSERT INTO reports (id,title,type,filename,owner_student_id) VALUES ('exclusive','Student','Student Report','exclusive.pdf',100),('aggregate','All','Progress','aggregate.pdf',NULL),('legacy','Legacy','Student Report','unknown.pdf',NULL)");
$pdo->exec("INSERT INTO ai_outputs (id,type,source_ref,output,owner_student_id,owner_document_id) VALUES ('student-ai','student','opaque','Student AI',100,NULL),('doc-ai','document','opaque','Document AI',NULL,'old'),('aggregate-ai','report','unknown','Aggregate AI',NULL,NULL)");
$pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES (100,'setup',DATE_ADD(NOW(),INTERVAL 1 DAY)),(100,'reset',DATE_ADD(NOW(),INTERVAL 1 DAY))");
$pdo->exec("INSERT INTO activity_logs (user_email,action,student_id,details) VALUES ('student@example.invalid','workflow',100,'Private research title')");
$result=lifecycle_execute($pdo,retention_test_actor(),retention_test_input());
reset_migration_expect(true,$result['ok'],'Student with completed history purged');
foreach (['students','documents','ierb_history','calendar_deadline_recipients'] as $table) reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'Owned rows deleted: '.$table);
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=100')->fetchColumn(),'Setup/reset removed');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Student notifications deleted; institutional notice preserved');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM ai_outputs')->fetchColumn(),'Exclusive AI removed; aggregate preserved');
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn(),'Exclusive report deleted; aggregate/ambiguous preserved');
foreach (['documents/current.txt','documents/old.txt','reports/exclusive.pdf'] as $file) reset_migration_expect(false,is_file(STORAGE_DIR.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$file)),'Owned physical file removed');
reset_migration_expect(true,is_file(STORAGE_DIR.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'aggregate.pdf'),'Aggregate historical PDF unchanged');
$audit=$pdo->query('SELECT * FROM activity_logs')->fetchAll();reset_migration_expect(1,count($audit),'Only minimal Student deletion audit remains');
reset_migration_expect('S100',$audit[0]['before_value'],'Former Student ID retained');
reset_migration_expect('manual',$audit[0]['after_value'],'Method retained');
reset_migration_expect(false,str_contains(json_encode($audit),'student@example.invalid'),'No target email in deletion audit');
reset_migration_expect(false,str_contains(json_encode($audit),'Private research title'),'No target research in deletion audit');

// Adviser archive and purge detach identities without destroying Student workflow evidence.
retention_test_reset();$pdo->exec('UPDATE advisers SET status="Active",archived_at=NULL WHERE id=200');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');$pdo->exec('UPDATE students SET adviser_id=200 WHERE id=100');
$archive=['accountType'=>'adviser','targetId'=>200,'action'=>'archive'];
retention_test_failure($archive,AccountLifecycleValidation::class);
foreach ([null,'1',1.0,-1,true,[],"1x"] as $badCount) {
    retention_test_failure($archive+['expectedAssignedStudents'=>$badCount],AccountLifecycleValidation::class);
    reset_migration_expect(200,(int)$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Malformed archive count preserves assignment');
    reset_migration_expect(null,$pdo->query('SELECT archived_at FROM advisers')->fetchColumn(),'Malformed archive count preserves active profile');
}
try { retention_change($pdo,retention_test_actor(),['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>0]);throw new RuntimeException('Stale impact accepted'); }
catch(AccountLifecycleConflict $e) { reset_migration_expect(200,(int)$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Stale impact rolls back assignment'); }
$r=retention_change($pdo,retention_test_actor(),['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>1]);
reset_migration_expect(1,$r['unassignedStudents'],'Archive impact reported');reset_migration_expect(null,$pdo->query('SELECT adviser_id FROM students')->fetchColumn(),'Adviser archive unassigns archived Student too');
$pdo->exec('UPDATE advisers SET archived_at=DATE_SUB(NOW(),INTERVAL 7 DAY) WHERE id=200');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,reviewed_by,uploaded_by,rpms_submitted_by,review_status,rpms_submitted_at) VALUES ('reviewed',100,'History','not-present.txt','Adviser One','Adviser One','Adviser One','Approved',NOW())");
$pdo->exec("INSERT INTO ierb_history (student_id,note,actor) VALUES (100,'Historical review','Adviser One')");
$pdo->exec("INSERT INTO notifications (recipient_type,recipient_id,recipient_email,recipient_name,message,created_by,status) VALUES ('student',100,'student@example.invalid','Student One','Workflow evidence','Adviser One','Sent'),('adviser',200,'adviser@example.invalid','Adviser One','Old own notice','Adviser One','Scheduled')");
$pdo->exec("INSERT INTO calendar_deadlines (creator_user_id,title,deadline_date,target_scope,status) VALUES (200,'Past',DATE_SUB(CURDATE(),INTERVAL 1 DAY),'groups','Active')");
$pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES (200,'adviser-reset',DATE_ADD(NOW(),INTERVAL 1 DAY))");
$result=lifecycle_execute($pdo,retention_test_actor(),retention_test_input('adviser'));
reset_migration_expect(true,$result['ok'],'Adviser historical activity no longer permanent blocker');
reset_migration_expect('Adviser One',$pdo->query('SELECT reviewed_by FROM documents')->fetchColumn(),'Historical reviewer text remains');
reset_migration_expect('Adviser One',$pdo->query('SELECT actor FROM ierb_history')->fetchColumn(),'Historical actor remains');
$notice=$pdo->query('SELECT * FROM notifications WHERE recipient_email="adviser@example.invalid"')->fetch();
reset_migration_expect(null,$notice['recipient_id'],'Historical recipient detached');reset_migration_expect('historical_adviser',$notice['recipient_type'],'Historical recipient cannot attach to reused email/name');
reset_migration_expect('Cancelled',$notice['status'],'Own queued email cancelled');
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Workflow notifications preserved');
reset_migration_expect(null,$pdo->query('SELECT creator_user_id FROM calendar_deadlines')->fetchColumn(),'Past deadline author detached');
reset_migration_expect('Adviser One',$pdo->query('SELECT creator_name FROM calendar_deadlines')->fetchColumn(),'Past deadline display author preserved');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=200')->fetchColumn(),'Adviser credentials removed');
retention_test_reset();$pdo->exec('UPDATE advisers SET status="Active",archived_at=NULL WHERE id=200');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');
retention_test_failure($archive,AccountLifecycleValidation::class);
retention_test_failure($archive+['expectedAssignedStudents'=>1]);
reset_migration_expect(0,retention_change($pdo,retention_test_actor(),$archive+['expectedAssignedStudents'=>0])['unassignedStudents'],'Zero-impact Adviser archive requires exact integer zero');

// Safety cases that must not guess ownership or touch unrelated files.
foreach (["INSERT INTO documents (id,student_name,original_name,stored_name) VALUES ('legacy','Student One','Legacy','shared.txt')",
    "INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('bad',100,'Bad','../outside.txt',0)",
    "INSERT INTO users (username,password_hash,role,full_name,email,ref_id,status) VALUES ('OTHER','hash','student','Other','other@example.invalid','S100','Inactive')"] as $sql) {
    retention_test_reset();$pdo->exec($sql);retention_test_failure(retention_test_input());
}

echo 'PASS: single-account, ownership, retention and file checks; cumulative assertions '.$GLOBALS['checks'].".\n";

// Actual transactional audit rejection AFTER quarantine must restore files before releasing locks.
class RetentionAuditDeniedPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if (str_starts_with($query,'INSERT INTO activity_logs')) throw new RuntimeException('Synthetic durable audit failure');
        return parent::prepare($query,$options);
    }
}
$faultDsn='mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle;charset=utf8mb4';
$auditDenied=new RetentionAuditDeniedPDO($faultDsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$auditDenied->exec("SET time_zone='+08:00'");
retention_test_reset();file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'audit.txt','Audit rollback content');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('audit',100,'Audit','audit.txt',0)");
try { lifecycle_execute($auditDenied,retention_test_actor(),retention_test_input());throw new RuntimeException('Audit failure accepted'); }
catch(RuntimeException $e) { reset_migration_expect('Synthetic durable audit failure',$e->getMessage(),'Mandatory audit refusal'); }
reset_migration_expect('Audit rollback content',file_get_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'audit.txt'),'Audit rollback restored physical file');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Audit failure preserves DB');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM account_purge_jobs')->fetchColumn(),'No committed job after audit failure');
reset_migration_expect([],purge_journal_candidates($pdo),'Recovered rollback removes journal');

class RetentionFinalizeFaultPDO extends PDO {
    public ?string $faultPath=null;
    public function commit(): bool {
        $ok=parent::commit();
        $dirs=glob(purge_journal_root().DIRECTORY_SEPARATOR.'*',GLOB_ONLYDIR);
        foreach($dirs as $dir) if (is_file($dir.DIRECTORY_SEPARATOR.'0.file')) {
            $this->faultPath=$dir.DIRECTORY_SEPARATOR.'0.file';
            rename($this->faultPath,$this->faultPath.'.held');mkdir($this->faultPath);break;
        }
        return $ok;
    }
}
retention_test_reset();file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'finalize.txt','Finalization content');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('finalize',100,'Finalize','finalize.txt',0)");
$fault=new RetentionFinalizeFaultPDO($faultDsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$fault->exec("SET time_zone='+08:00'");
$result=lifecycle_execute($fault,retention_test_actor(),retention_test_input());
reset_migration_expect(false,$result['ok'],'Filesystem failure never reports success');reset_migration_expect(true,$result['committed'],'Actual DB commit reported separately');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'DB deletion really committed');
reset_migration_expect('files_pending',$pdo->query('SELECT status FROM account_purge_jobs')->fetchColumn(),'Durable recovery pending');
reset_migration_expect(true,is_file($fault->faultPath.'.held'),'Recoverable bytes remain private');
rmdir($fault->faultPath);rename($fault->faultPath.'.held',$fault->faultPath);
$recovery=['jobId'=>$result['jobId'],'currentPassword'=>$fixturePassword,'confirmed'=>true,'confirmation'=>'RECOVER '.$result['jobId']];
function retention_test_recovery_refusals(array $recovery,string $staged,string $bytes): void {
    $missing=$recovery;unset($missing['confirmation']);
    $cases=[$missing,array_replace($recovery,['confirmation'=>'wrong']),array_replace($recovery,['confirmation'=>$recovery['confirmation'].' ']),
        array_replace($recovery,['confirmation'=>strtolower($recovery['confirmation'])]),array_replace($recovery,['currentPassword'=>'wrong']),
        array_replace($recovery,['confirmed'=>false])];
    foreach ([null,[],42,'../outside',str_repeat('A',32),str_repeat('a',32)."\n"] as $job) $cases[]=array_replace($recovery,['jobId'=>$job]);
    foreach ($cases as $input) {
        $before=db()->query('SELECT * FROM account_purge_jobs ORDER BY id')->fetchAll();
        try { retention_recover(db(),retention_test_actor(),$input);throw new RuntimeException('Invalid recovery accepted'); }
        catch(AccountLifecycleValidation $e) { reset_migration_expect(true,true,'Recovery rejects missing/wrong phrase, password, acknowledgement or malformed job ID'); }
        reset_migration_expect($before,db()->query('SELECT * FROM account_purge_jobs ORDER BY id')->fetchAll(),'Invalid recovery preserves job state');
        reset_migration_expect($bytes,file_get_contents($staged),'Invalid recovery preserves quarantined bytes');
        reset_migration_expect(false,db()->inTransaction(),'Invalid recovery closes transaction');
    }
}
retention_test_recovery_refusals($recovery,$fault->faultPath,'Finalization content');
$recovered=retention_recover($pdo,retention_test_actor(),$recovery);
reset_migration_expect(true,$recovered['ok'],'Admin-triggered committed recovery');reset_migration_expect('complete',$pdo->query('SELECT status FROM account_purge_jobs')->fetchColumn(),'Recovery marked complete');
reset_migration_expect([],purge_journal_candidates($pdo),'Finalized journal removed');

// Crash before commit: durable disk journal, DB rollback, explicit restore recovery.
retention_test_reset();file_put_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'crash.txt','Crash recovery bytes');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('crash',100,'Crash','crash.txt',0)");
$pdo->beginTransaction();$r=retention_locked_record($pdo,'student',100);$prepared=purge_files_prepare($pdo,'student',100,[['kind'=>'documents','name'=>'crash.txt']]);purge_files_stage($prepared);$pdo->rollBack();
retention_test_failure(retention_test_input());
$recovery=['jobId'=>$prepared['job'],'currentPassword'=>$fixturePassword,'confirmed'=>true,'confirmation'=>'RECOVER '.$prepared['job']];
retention_test_recovery_refusals($recovery,purge_journal_root().DIRECTORY_SEPARATOR.$prepared['job'].DIRECTORY_SEPARATOR.'0.file','Crash recovery bytes');
$recovered=retention_recover($pdo,retention_test_actor(),$recovery);
reset_migration_expect(true,$recovered['ok'],'Admin restores uncommitted quarantine');
reset_migration_expect('Crash recovery bytes',file_get_contents(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'crash.txt'),'Rollback recovery exact bytes');

// Bulk: scope reconstruction, stale confirmation, mixed eligibility and bounded chunks/retry.
function retention_bulk_fixture(): void {
    retention_test_reset();
    $q=db()->prepare('INSERT INTO students (id,student_id,full_name,email,academic_year,archived_at) VALUES (?,?,?,?,?,DATE_SUB(NOW(),INTERVAL 7 MONTH))');
    for($i=300;$i<328;$i++) $q->execute([$i,'S'.$i,'Student '.$i,'s'.$i.'@example.invalid','2026-2027']);
    db()->exec('UPDATE students SET retention_hold=1 WHERE id=301');
    db()->exec("INSERT INTO documents (id,student_id,original_name,stored_name) VALUES ('unresolved-bulk',302,'Open','open-bulk.txt')");
    db()->exec('UPDATE students SET archived_at=NOW() WHERE id=303');
}
retention_bulk_fixture();$actor=retention_test_actor();
$selection=retention_selection($pdo,$actor,['accountType'=>'student','filters'=>['lifecycle'=>'archived']]);
reset_migration_expect(29,$selection['total'],'All matching server population');
$base=['selectionToken'=>$selection['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>'permanent_delete'];
$preview=retention_bulk_preview($pdo,$actor,$base);reset_migration_expect(26,$preview['eligible'],'Mixed population eligibility');reset_migration_expect('PURGE 26 ACCOUNTS',$preview['phrase'],'Server phrase');
foreach ([['ids'=>[100,999],'selectionMode'=>'individual'],['ids'=>[100,100],'selectionMode'=>'individual'],['bulkAction'=>'grace_period_override']] as $bad) {
    try { retention_bulk_preview($pdo,$actor,array_replace($base,$bad));throw new RuntimeException('Invalid bulk accepted'); }
    catch(AccountLifecycleForbidden|AccountLifecycleValidation $e) { reset_migration_expect(true,true,'Invalid/injected/duplicate/bulk override rejected'); }
}
$execute=['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$fixturePassword,'confirmation'=>$preview['phrase'],'confirmed'=>true];
foreach (['currentPassword'=>'wrong','confirmation'=>'PURGE 29 ACCOUNTS','confirmed'=>false] as $key=>$value) {
    try { retention_bulk_execute($pdo,$actor,array_replace($execute,[$key=>$value]));throw new RuntimeException('Invalid bulk confirmation accepted'); }
    catch(AccountLifecycleValidation $e) { reset_migration_expect(29,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Invalid bulk confirmation changes nothing'); }
}
$pdo->exec('UPDATE students SET retention_hold=1 WHERE id=304');
try { retention_bulk_execute($pdo,$actor,$execute);throw new RuntimeException('Stale count accepted'); }
catch(AccountLifecycleConflict $e) { reset_migration_expect(29,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Stale preview refuses before first purge'); }
$pdo->exec('UPDATE students SET retention_hold=0 WHERE id=304');
$preview=retention_bulk_preview($pdo,$actor,$base);$execute['previewToken']=$preview['previewToken'];
$first=retention_bulk_execute($pdo,$actor,$execute);reset_migration_expect(25,$first['nextCursor'],'Bounded first chunk');reset_migration_expect(false,$first['done'],'Explicit continuation');
$retry=retention_bulk_execute($pdo,$actor,$execute);reset_migration_expect($first,$retry,'Lost-response retry returns identical committed outcomes');
$second=retention_bulk_execute($pdo,$actor,array_replace($execute,['cursor'=>$first['nextCursor']]));
reset_migration_expect(true,$second['done'],'Bulk finishes second chunk');reset_migration_expect(26,$second['completed'],'Actual completed outcomes');reset_migration_expect(3,$second['skipped'],'Independent Hold/workflow/grace skipped');
reset_migration_expect(3,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Only blocked accounts remain');
reset_migration_expect(26,(int)$pdo->query('SELECT COUNT(*) FROM account_purge_jobs WHERE status="complete"')->fetchColumn(),'Every completed outcome has durable evidence');
retention_test_reset();$selection=retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['q'=>'S100']]);
$preview=retention_bulk_preview($pdo,retention_test_actor(),['selectionToken'=>$selection['selectionToken'],'selectionMode'=>'individual','ids'=>[100],'bulkAction'=>'hold']);
$execute=['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$fixturePassword,'confirmation'=>$preview['phrase'],'confirmed'=>true,'reason'=>'Bulk review'];
reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Bulk Hold');
foreach (['remove_hold','restore','archive'] as $action) {
    $selection=retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>[]]);
    $preview=retention_bulk_preview($pdo,retention_test_actor(),['selectionToken'=>$selection['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>$action]);
    $execute=['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$fixturePassword,'confirmation'=>$preview['phrase'],'confirmed'=>true];
    reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Bulk '.$action);
}
// Selection limit is explicit, discovery uses a COUNT and never an unbounded purge request.
try { retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['role'=>'admin']]);throw new RuntimeException('Injected scope accepted'); }
catch(AccountLifecycleValidation $e) { reset_migration_expect(true,true,'Unrecognized role scope rejected'); }
echo 'PASS: file failure/recovery and bulk checks; cumulative assertions '.$GLOBALS['checks'].".\n";
require __DIR__.'/account-retention-extended.php';
require __DIR__.'/account-retention-concurrency.php';
require __DIR__.'/account-retention-verification.php';
require __DIR__.'/account-retention-http.php';
require __DIR__.'/account-onboarding-migration.php';
require __DIR__.'/account-onboarding-mysql.php';
require __DIR__.'/account-onboarding-concurrency.php';
