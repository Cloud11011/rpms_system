<?php
/** Runs only inside account-lifecycle-runner's verified private MariaDB. */
if(PHP_SAPI!=='cli' || !isset($datadir,$pdo) || !function_exists('lifecycle_reset_fixture')) exit(1);
$remediationStart=$GLOBALS['checks'];
echo "Running Item 8 remediation dependency, permission, archive, concurrency, metadata, and HTTP checks.\n";
require_once __DIR__.'/../includes/student_snapshot.php';
reset_migration_expect('REPEATABLE-READ',$pdo->query('SELECT @@tx_isolation')->fetchColumn(),'Actual local MariaDB deployed isolation');
function remediation_wait_ready(string $gate): void {
    $deadline=microtime(true)+10;
    while(!is_file($gate.'.ready') && microtime(true)<$deadline) usleep(10000);
    reset_migration_expect(true,is_file($gate.'.ready'),'Worker paused after capture/authentication');
}
function remediation_wait_student_lock(int $connectionId): void {
    $deadline=microtime(true)+10; $waiting=0;
    do {
        $waiting=(int)db()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID=$connectionId AND INFO LIKE '%FROM students%FOR UPDATE%'")->fetchColumn();
        if($waiting) break; usleep(50000);
    } while(microtime(true)<$deadline);
    reset_migration_expect(true,$waiting>0,'Actual MariaDB lock wait establishes concurrent overlap');
}
function remediation_snapshot(): array {
    $result=[];
    foreach(['users','students','advisers','ierb_history','password_resets','activity_logs'] as $table)
        $result[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    return $result;
}
function remediation_restore_reset_index(): void {
    $pdo=db(); $indexes=lifecycle_schema_inventory($pdo)['indexes'];
    $hasUserIndex=(bool)array_filter($indexes,fn($index)=>$index['TABLE_NAME']==='password_resets' && $index['INDEX_NAME']==='user_id');
    foreach($indexes as $index) if($index['TABLE_NAME']==='password_resets' && $index['COLUMN_NAME']==='user_id' && $index['INDEX_NAME']!=='user_id') {
        if(!$hasUserIndex) { $pdo->exec('ALTER TABLE password_resets ADD INDEX user_id(user_id)'); $hasUserIndex=true; }
        $present=$pdo->query('SHOW INDEX FROM password_resets WHERE Key_name='.$pdo->quote($index['INDEX_NAME']))->fetchAll();
        if($present) $pdo->exec('ALTER TABLE password_resets DROP INDEX `'.$index['INDEX_NAME'].'`');
    }
}

// R1: structured ownership is immutable; legacy ownership must be resolvable.
foreach([
    ['adviser',200,null,null,true],
    ['adviser',200,'stale@example.invalid','Previous Name',true],
    ['adviser',null,'adviser@example.invalid','Synthetic Adviser',true],
    ['adviser',null,'unresolvable@example.invalid','Unknown Legacy',true],
    ['adviser',201,'unrelated@example.invalid','Unrelated Adviser',false],
    ['adviser',null,'unrelated@example.invalid','Unrelated Adviser',false],
] as [$type,$id,$email,$name,$blocked]) {
    lifecycle_reset_fixture();
    $pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email,status) VALUES (201,'E201','Unrelated Adviser','unrelated@example.invalid','Active')");
    $pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,recipient_email,recipient_name,message) VALUES (?,?,?,?,?)')->execute([$type,$id,$email,$name,'Protected']);
    if($blocked) lifecycle_expect_failure(lifecycle_input('adviser'));
    else { lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input('adviser')); reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Unrelated notice preserved without blocking deletion'); }
}

// R2: immutable provenance, old/new nonsensitive values, and no retrospective legacy baseline.
foreach(['student'=>'students','adviser'=>'advisers'] as $type=>$table) {
    lifecycle_reset_fixture(); $id=$type==='student'?100:200;
    $pdo->beginTransaction(); $record=$pdo->query('SELECT * FROM '.$table.' WHERE id='.$id.' FOR UPDATE')->fetch();
    $before=lifecycle_identity_capture($pdo,$record,$type);
    $pdo->exec("UPDATE $table SET full_name='Renamed Identity' WHERE id=$id");
    $pdo->exec("UPDATE users SET full_name='Renamed Identity' WHERE id=$id"); $record['full_name']='Renamed Identity';
    lifecycle_identity_persist($pdo,lifecycle_actor(),$type,$before,lifecycle_identity_capture($pdo,$record,$type)); $pdo->commit();
    $evidence=$pdo->query("SELECT * FROM activity_logs WHERE action='account_identity_field_changed' AND entity_type='$type' AND entity_id='$id'")->fetchAll();
    reset_migration_expect(1,count($evidence),'Exactly one changed-field evidence');
    reset_migration_expect($before['full_name'],$evidence[0]['before_value'],'Old identity retained');
    reset_migration_expect('Renamed Identity',$evidence[0]['after_value'],'New identity retained');
    $pdo->exec("INSERT INTO documents (id,original_name,stored_name,student_name,reviewed_by) VALUES ('legacy','Fixture','synthetic',".$pdo->quote($type==='student'?$before['full_name']:'Another Student').','.$pdo->quote($type==='adviser'?$before['full_name']:'Another Adviser').')');
    lifecycle_expect_failure(lifecycle_input($type));
    lifecycle_reset_fixture(); $pdo->exec("DELETE FROM activity_logs WHERE action='account_identity_created' AND entity_type='$type'");
    lifecycle_expect_failure(lifecycle_input($type));
}

// Real privileges, not an injected PHP exception: every mandatory audit failure must roll back.
$restrictedPassword=bin2hex(random_bytes(24));
$pdo->exec("CREATE USER 'lifecycle_restricted'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($restrictedPassword));
$pdo->exec("GRANT SELECT,UPDATE,DELETE ON hardening_test_lifecycle.* TO 'lifecycle_restricted'@'127.0.0.1'");
foreach($metadata['tables'] as $table) if($table['TABLE_NAME']!=='activity_logs')
    $pdo->exec("GRANT INSERT ON hardening_test_lifecycle.`".$table['TABLE_NAME']."` TO 'lifecycle_restricted'@'127.0.0.1'");
$restricted=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','lifecycle_restricted',$restrictedPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try { lifecycle_privileged_verification($restricted); throw new RuntimeException('Restricted metadata capability accepted'); }
catch(AccountLifecycleConflict $error) { reset_migration_expect(true,true,'Restricted verifier refuses incomplete global visibility'); }
foreach([['student','permanent_delete'],['adviser','permanent_delete'],['admin','archive'],['admin','permanent_delete']] as [$type,$action]) {
    lifecycle_reset_fixture(); $state=remediation_snapshot(); $error=null;
    try { lifecycle_execute($restricted,lifecycle_actor(),lifecycle_input($type,$action)); } catch(Throwable $caught) { $error=$caught; }
    reset_migration_expect(true,$error instanceof PDOException,'Real audit INSERT permission failure');
    reset_migration_expect(500,lifecycle_error_status($error),'Permission persistence error maps to 500');
    reset_migration_expect($state,remediation_snapshot(),'Every affected row rolled back after audit denial');
    reset_migration_expect(false,$restricted->inTransaction(),'Denied audit ends transaction');
}
foreach(['student'=>'students','adviser'=>'advisers'] as $type=>$table) {
    lifecycle_reset_fixture(); $state=remediation_snapshot(); $id=$type==='student'?100:200;
    $restricted->beginTransaction();
    try {
        $record=$restricted->query('SELECT * FROM '.$table.' WHERE id='.$id.' FOR UPDATE')->fetch();
        $before=lifecycle_identity_capture($restricted,$record,$type);
        $restricted->exec("UPDATE $table SET full_name='Failed Rename' WHERE id=$id"); $record['full_name']='Failed Rename';
        lifecycle_identity_persist($restricted,lifecycle_actor(),$type,$before,lifecycle_identity_capture($restricted,$record,$type));
        throw new RuntimeException('Expected provenance permission failure');
    } catch(PDOException $error) { $restricted->rollBack(); }
    reset_migration_expect($state,remediation_snapshot(),'Mandatory identity evidence denial rolls back rename');
}

// R5: stale email, explicit UID, username/ref fallback and token invalidation.
foreach(['student'=>'students','adviser'=>'advisers'] as $type=>$table) foreach(['aligned','uid_stale','ref_fallback','username_fallback','ambiguous'] as $case) {
    lifecycle_reset_fixture(); $id=$type==='student'?100:200;
    $pdo->exec($type==='student'?"UPDATE students SET archived_at=NULL WHERE id=100":"UPDATE advisers SET status='Active' WHERE id=200");
    $pdo->exec("UPDATE users SET status='Active' WHERE id=$id");
    if($case==='uid_stale') { $pdo->exec("UPDATE $table SET user_id=$id WHERE id=$id"); $pdo->exec("UPDATE users SET email='stale@example.invalid' WHERE id=$id"); }
    if($case==='ref_fallback') $pdo->exec("UPDATE users SET username='OLD-ID',email='stale@example.invalid' WHERE id=$id");
    if($case==='username_fallback') $pdo->exec("UPDATE users SET ref_id='OLD-ID',email='stale@example.invalid' WHERE id=$id");
    if($case==='ambiguous') $pdo->exec("INSERT INTO users (id,username,password_hash,role,full_name,email,ref_id,status) VALUES (300,'EXTRA','synthetic','$type','Other Login','other@example.invalid',".$pdo->quote($type==='student'?'S100':'E200').",'Active')");
    // Existing reset/setup issuers intentionally share the same token schema.
    $pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES ($id,'reset-$type',DATE_ADD(NOW(),INTERVAL 1 DAY)),($id,'setup-$type',DATE_ADD(NOW(),INTERVAL 1 DAY))");
    $state=remediation_snapshot();
    if($type==='student') {
        $error=null; try { archive_student($pdo,lifecycle_actor(),100); } catch(Throwable $caught) { $error=$caught; }
        reset_migration_expect($case==='ambiguous',$error instanceof AccountLifecycleConflict,'Student ownership archive outcome');
    } else {
        $response=lifecycle_worker_finish(lifecycle_worker_start(['id'=>200],1,['mode'=>'adviser_archive']));
        reset_migration_expect($case==='ambiguous'?409:200,$response['status'],'Adviser ownership archive outcome');
    }
    if($case==='ambiguous') reset_migration_expect($state,remediation_snapshot(),'Ambiguous archive fully rolled back');
    else {
        reset_migration_expect('Inactive',$pdo->query("SELECT status FROM users WHERE id=$id")->fetchColumn(),'Confirmed login inactive');
        reset_migration_expect(2,(int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id=$id AND used=1")->fetchColumn(),'Reset and setup capabilities invalidated');
        reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE id=$id")->fetchColumn(),'Archived entity remains');
    }
}

// R3: capture-pause-persist races at both supported isolation levels, in both winner orders.
echo "PASS: R1/R2 dependencies and real permission failures; R5 ownership/token cases.\n";
foreach(['READ COMMITTED','REPEATABLE READ'] as $isolation) {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
    foreach(['notification_writer','report_writer'] as $mode) foreach(['archive','delete'] as $operation) {
        lifecycle_reset_fixture(); $pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100'); $pdo->exec("UPDATE users SET status='Active' WHERE id=100");
        $gate=$datadir.'/writer-'.bin2hex(random_bytes(5));
        $writer=lifecycle_worker_start([],1,['mode'=>$mode,'waitFile'=>$gate,'isolation'=>$isolation]); remediation_wait_ready($gate);
        archive_student($pdo,lifecycle_actor(),100);
        if($operation==='delete') lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input());
        file_put_contents($gate,'go'); $result=lifecycle_worker_finish($writer);
        reset_migration_expect($mode==='notification_writer'?200:409,$result['status'],'Stale writer outcome '.$isolation.'/'.$operation.'/'.$mode);
        $table=$mode==='notification_writer'?'notifications':'reports';
        reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'No late protected output after '.$operation);
    }
    foreach(['notification_writer','report_writer'] as $mode) {
        lifecycle_reset_fixture(); $pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100'); $pdo->exec("UPDATE users SET status='Active' WHERE id=100");
        $gate=$datadir.'/final-write-'.bin2hex(random_bytes(5));
        $writer=lifecycle_worker_start([],1,['mode'=>$mode,'isolation'=>$isolation,'finalGate'=>$gate]); remediation_wait_ready($gate);
        $archive=lifecycle_worker_start([],1,['mode'=>'student_archive','isolation'=>$isolation,'archiveAttempt'=>$gate.'archive']);
        remediation_wait_ready($gate.'archive');
        try { remediation_wait_student_lock((int)file_get_contents($gate.'archive.ready')); }
        catch(Throwable $error) {
            file_put_contents($gate,'go'); lifecycle_worker_finish($writer);
            $diagnostic=lifecycle_worker_finish($archive);
            throw new RuntimeException('Archive overlap failed: '.json_encode($diagnostic));
        }
        file_put_contents($gate,'go');
        reset_migration_expect(200,lifecycle_worker_finish($writer)['status'],'Writer holding Student lock persists first');
        reset_migration_expect(200,lifecycle_worker_finish($archive)['status'],'Concurrent archive completes after writer commit');
        lifecycle_expect_failure(lifecycle_input());
        reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM '.($mode==='notification_writer'?'notifications':'reports'))->fetchColumn(),'Winning writer evidence retained');
    }
    // Mixed last-Admin decisions use the same ordered Admin lock set.
    lifecycle_reset_fixture(); $gate=$datadir.'/mixed-'.bin2hex(random_bytes(5));
    $a=lifecycle_input('admin','archive'); $b=lifecycle_input('admin'); $b['targetId']=2;
    $wa=lifecycle_worker_start($a,1,['waitFile'=>$gate.'a','isolation'=>$isolation]);
    $wb=lifecycle_worker_start($b,2,['waitFile'=>$gate.'b','isolation'=>$isolation]);
    remediation_wait_ready($gate.'a'); remediation_wait_ready($gate.'b'); file_put_contents($gate.'a','go'); file_put_contents($gate.'b','go');
    $statuses=[lifecycle_worker_finish($wa)['status'],lifecycle_worker_finish($wb)['status']]; sort($statuses);
    reset_migration_expect([200,409],$statuses,'Mixed archive/delete concurrent outcomes');
    reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='Active'")->fetchColumn(),'Mixed operation retains one active Admin');
    // Actor is read before waiting; the locked current password must supersede that stale snapshot.
    lifecycle_reset_fixture(); $pdo->beginTransaction(); $pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
    $gate=$datadir.'/password-'.bin2hex(random_bytes(5)); $worker=lifecycle_worker_start(lifecycle_input(),1,['waitFile'=>$gate,'isolation'=>$isolation]); remediation_wait_ready($gate); file_put_contents($gate,'go');
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([password_hash('Changed synthetic passphrase',PASSWORD_DEFAULT)]); $pdo->commit();
    reset_migration_expect(422,lifecycle_worker_finish($worker)['status'],'Locked current password rejects stale credential');
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=100')->fetchColumn(),'Password race retains Student');
    foreach(['student'=>'students','adviser'=>'advisers'] as $type=>$table) {
        lifecycle_reset_fixture(); $id=$type==='student'?100:200; $pdo->beginTransaction();
        $pdo->query("SELECT id FROM $table WHERE id=$id FOR UPDATE")->fetch();
        $pdo->exec($type==='student'?"UPDATE students SET archived_at=NULL WHERE id=100":"UPDATE advisers SET status='Active' WHERE id=200");
        $pdo->exec("UPDATE users SET status='Active' WHERE id=$id");
        $worker=lifecycle_worker_start(lifecycle_input($type),1,['isolation'=>$isolation]); usleep(100000); $pdo->commit();
        reset_migration_expect(409,lifecycle_worker_finish($worker)['status'],'Reactivation current locked state prevents deletion');
        reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE id=$id")->fetchColumn(),'Reactivated entity preserved');
    }
}
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
// Lifecycle wins after another writer authenticated/captured its request: stale edits cannot recreate it.
foreach(['student'=>'student_save','adviser'=>'adviser_save','admin'=>'profile_password'] as $type=>$mode) {
    lifecycle_reset_fixture(); $gate=$datadir.'/delete-wins-'.bin2hex(random_bytes(5));
    $data=$type==='admin'?['currentPassword'=>$fixturePassword,'newPassword'=>'New isolated test passphrase']:
        ['id'=>$type==='student'?100:200,'studentId'=>'S100','employeeId'=>'E200','name'=>'Synthetic Identity','email'=>$type==='student'?'student@example.invalid':'adviser@example.invalid','status'=>$type==='student'?'On Track':'Active','department'=>''];
    $worker=lifecycle_worker_start($data,1,['mode'=>$mode,'waitFile'=>$gate]); remediation_wait_ready($gate);
    lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input($type)); file_put_contents($gate,'go');
    reset_migration_expect($type==='admin'?409:404,lifecycle_worker_finish($worker)['status'],'Deletion wins over stale password/identity edit');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM '.($type==='admin'?'users':($type==='student'?'students':'advisers')).' WHERE id='.($type==='admin'?1:($type==='student'?100:200)))->fetchColumn(),'No resurrection after deletion wins');
}

// R4: a flag is not approval without a matching complete operator verification.
foreach([['verified'=>false],['verification'=>[]],['verification'=>array_replace(PRISM_HARD_DELETE_VERIFICATION,['manifest_sha256'=>str_repeat('0',64)])],['verification'=>array_replace(PRISM_HARD_DELETE_VERIFICATION,['complete_visibility'=>false])],['verification'=>array_replace(PRISM_HARD_DELETE_VERIFICATION,['database'=>[]])]] as $gate) {
    lifecycle_reset_fixture(); reset_migration_expect(409,lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input(),1,$gate))['status'],'Unverified/mismatched gate refuses hard deletion');
}
lifecycle_reset_fixture(); reset_migration_expect(200,lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input('admin','archive'),1,['verified'=>false]))['status'],'Archive available without hard-delete approval');
foreach([
    ["UPDATE schema_meta SET v='7' WHERE k='schema_version'","UPDATE schema_meta SET v='8' WHERE k='schema_version'"],
    ['CREATE INDEX unexpected_idx ON students(full_name)','DROP INDEX unexpected_idx ON students'],
    ['CREATE TRIGGER unexpected_trigger BEFORE DELETE ON students FOR EACH ROW SET @unexpected_lifecycle=1','DROP TRIGGER unexpected_trigger'],
] as [$introduce,$restore]) { lifecycle_reset_fixture(); $pdo->exec($introduce); lifecycle_expect_failure(lifecycle_input()); $pdo->exec($restore); }
$pdo->exec('CREATE DATABASE hardening_test_external');
foreach(['RESTRICT','CASCADE','SET NULL'] as $rule) {
    lifecycle_reset_fixture();
    $pdo->exec("CREATE TABLE hardening_test_external.incoming (id INT PRIMARY KEY,user_id INT NULL, CONSTRAINT outside_fk FOREIGN KEY(user_id) REFERENCES hardening_test_lifecycle.users(id) ON DELETE $rule) ENGINE=InnoDB");
    $pdo->exec('INSERT INTO hardening_test_external.incoming VALUES (1,1)'); lifecycle_expect_failure(lifecycle_input('admin'));
    reset_migration_expect(1,(int)$pdo->query('SELECT user_id FROM hardening_test_external.incoming')->fetchColumn(),'External '.$rule.' evidence untouched');
    $pdo->exec('DROP TABLE hardening_test_external.incoming');
}
$pdo->exec('CREATE TABLE hardening_test_external.users LIKE hardening_test_lifecycle.users');
lifecycle_reset_fixture();
$pdo->exec('INSERT INTO hardening_test_external.users SELECT * FROM users');
$original=array_values(array_filter($metadata['foreign_keys'],fn($fk)=>$fk['TABLE_NAME']==='password_resets'))[0];
$constraint=$original['CONSTRAINT_NAME'];
$pdo->exec('ALTER TABLE password_resets DROP FOREIGN KEY `'.$constraint.'`');
$pdo->exec('ALTER TABLE password_resets ADD CONSTRAINT `'.$constraint.'` FOREIGN KEY(user_id) REFERENCES hardening_test_external.users(id) ON DELETE CASCADE');
remediation_restore_reset_index();
reset_migration_expect($metadata['indexes'],lifecycle_schema_inventory($pdo)['indexes'],'Same-name external FK fixture retains the exact reviewed indexes');
try { lifecycle_compare_schema($pdo); throw new RuntimeException('Cross-schema same-name FK accepted'); }
catch(AccountLifecycleConflict $error) { reset_migration_expect(true,str_contains($error->getMessage(),'foreign_keys'),'Schema-qualified FK identity itself causes the rejection'); }
lifecycle_expect_failure(lifecycle_input());
$pdo->exec('ALTER TABLE password_resets DROP FOREIGN KEY `'.$constraint.'`');
$pdo->exec('ALTER TABLE password_resets ADD CONSTRAINT `'.$constraint.'` FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE');
$pdo->exec('DROP DATABASE hardening_test_external');
// Restore the disposable fixture exactly; production metadata is never changed by verification.
remediation_restore_reset_index();
lifecycle_verify_schema($pdo);
foreach([['root',$password,0],['lifecycle_restricted',$restrictedPassword,1]] as [$verifyUser,$verifyPass,$exit]) {
    $environment=array_merge(getenv(),['PRISM_SCHEMA_VERIFY_HOST'=>'127.0.0.1','PRISM_SCHEMA_VERIFY_PORT'=>(string)$port,
        'PRISM_SCHEMA_VERIFY_DATABASE'=>'hardening_test_lifecycle','PRISM_SCHEMA_VERIFY_USER'=>$verifyUser,'PRISM_SCHEMA_VERIFY_PASSWORD'=>$verifyPass]);
    $process=proc_open([PHP_BINARY,__DIR__.'/../tools/verify-account-lifecycle-schema.php','--verify','--schema-changes-excluded'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $diagnostic=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    reset_migration_expect($exit,proc_close($process),'Actual operator CLI complete/incomplete visibility result');
    reset_migration_expect($exit===0,str_contains($output,"define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', true)"),'Only privileged verification emits enabling configuration');
    reset_migration_expect(false,str_contains($output.$diagnostic,$verifyPass),'Operator verifier never prints credentials');
}
class RemediationMetadataDeniedPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'INFORMATION_SCHEMA')) { $error=new PDOException('Synthetic metadata permission denial'); $error->errorInfo=['42000',1142,'Synthetic']; throw $error; }
        return parent::prepare($query,$options);
    }
}
$denied=new RemediationMetadataDeniedPDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
lifecycle_reset_fixture(); lifecycle_expect_failure(lifecycle_input(),PDOException::class,1,$denied);

// R6: exact exception mapping, including sanitized retryable database failures.
foreach([AccountLifecycleBadRequest::class=>400,AccountLifecycleForbidden::class=>403,AccountLifecycleNotFound::class=>404,AccountLifecycleConflict::class=>409,AccountLifecycleValidation::class=>422,RuntimeException::class=>500,AccountLifecycleUnavailable::class=>503] as $class=>$status)
    reset_migration_expect($status,lifecycle_error_status(new $class('Synthetic')),'HTTP error class contract');
foreach([1205,1213,2002,2006] as $code) { $error=new PDOException('Private synthetic diagnostic'); $error->errorInfo=['HY000',$code,'Private']; reset_migration_expect(503,lifecycle_error_status($error),'Retryable SQL failure'); }
foreach(['{','[]','null','"string"'] as $raw) { try { lifecycle_decode_body($raw); throw new RuntimeException('Malformed body accepted'); } catch(AccountLifecycleBadRequest $error) { reset_migration_expect(400,lifecycle_error_status($error),'Malformed JSON rejected'); } }
require __DIR__.'/account-lifecycle-http.php';
echo 'PASS: Item 8 remediation assertions '.($GLOBALS['checks']-$remediationStart)."; private MariaDB and isolated HTTP only.\n";
