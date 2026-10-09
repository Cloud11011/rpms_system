<?php
/** Runs only in the parent's verified disposable MariaDB instance. */
if (PHP_SAPI !== 'cli' || !isset($pdo, $datadir) || !function_exists('reset_migration_expect')) exit(1);
$pdo->exec('CREATE DATABASE hardening_test_lifecycle');
$pdo->exec('USE hardening_test_lifecycle');
$GLOBALS['allowV7'] = $GLOBALS['allowV8'] = true;
PrismResetMigrationSQL\migrate($pdo);
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
require_once __DIR__.'/../includes/account_lifecycle.php';
$metadata=lifecycle_schema_inventory($pdo);
$pdo->rollBack();
reset_migration_expect('8', $metadata['schema_version'], 'Actual disposable schema version');
foreach ($metadata['tables'] as $table) reset_migration_expect('InnoDB', $table['ENGINE'], 'Actual transactional engine: '.$table['TABLE_NAME']);
reset_migration_expect(0, count($metadata['triggers']), 'No unexpected triggers');
$expected = ['advisers.user_id'=>'users:SET NULL','calendar_deadline_groups.deadline_id'=>'calendar_deadlines:CASCADE','calendar_deadline_recipients.deadline_id'=>'calendar_deadlines:CASCADE','calendar_deadline_recipients.student_id'=>'students:RESTRICT','calendar_deadlines.creator_user_id'=>'users:SET NULL','documents.student_id'=>'students:SET NULL','ierb_history.student_id'=>'students:CASCADE','password_resets.user_id'=>'users:CASCADE','students.adviser_id'=>'advisers:SET NULL','students.user_id'=>'users:SET NULL'];
$actual=[]; foreach($metadata['foreign_keys'] as $fk) $actual[$fk['TABLE_NAME'].'.'.$fk['COLUMN_NAME']]=$fk['REFERENCED_TABLE_NAME'].':'.$fk['DELETE_RULE'];
ksort($actual); ksort($expected);
reset_migration_expect($expected,$actual,'Actual incoming/outgoing FK map has no unexpected references');
$dest=__DIR__.'/lifecycle-results'; if(!is_dir($dest)) mkdir($dest);
file_put_contents($dest.'/metadata.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'PASS: read-only metadata verified '.count($metadata['tables']).' tables, '.count($metadata['columns']).' columns, '.count($metadata['indexes']).' index components, '.count($actual)." foreign keys; zero triggers.\n";
if ($argv[1] === '--lifecycle-metadata') return;
require_once __DIR__.'/../includes/account_lifecycle.php';
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',true);
define('PRISM_HARD_DELETE_VERIFICATION',lifecycle_privileged_verification($pdo));
require __DIR__.'/../workflow.php';
function db(): PDO { return $GLOBALS['pdo']; }
function log_api_error(...$args): void { throw new RuntimeException('Unexpected test audit failure'); }
$fixturePassword='Synthetic lifecycle passphrase';
$fixtureHash=password_hash($fixturePassword,PASSWORD_DEFAULT);
function lifecycle_reset_fixture(): void {
    global $pdo,$metadata,$fixtureHash;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($metadata['tables'] as $table) if (!in_array($table['TABLE_NAME'],['schema_meta','stage_labels'],true)) $pdo->exec('TRUNCATE TABLE `'.$table['TABLE_NAME'].'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $q=$pdo->prepare('INSERT INTO users (id,username,password_hash,role,full_name,email,ref_id,status) VALUES (?,?,?,?,?,?,?,?)');
    foreach ([[1,'ADMIN1','admin','Synthetic Admin One','admin1@example.invalid','ADMIN1','Active'],[2,'ADMIN2','admin','Synthetic Admin Two','admin2@example.invalid','ADMIN2','Active'],[100,'S100','student','Synthetic Student','student@example.invalid','S100','Inactive'],[200,'E200','adviser','Synthetic Adviser','adviser@example.invalid','E200','Inactive']] as $r) {
        array_splice($r,2,0,[$fixtureHash]); $q->execute($r);
    }
    $pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email,status) VALUES (200,'E200','Synthetic Adviser','adviser@example.invalid','Inactive')");
    $pdo->exec("INSERT INTO activity_logs (action,entity_type,entity_id,after_value) VALUES ('adviser_deactivated','adviser','200','Inactive')");
    $pdo->exec("INSERT INTO students (id,student_id,full_name,email,stage,status,archived_at) VALUES (100,'S100','Synthetic Student','student@example.invalid','Stage 1','On Track',NOW())");
    $pdo->exec("INSERT INTO ierb_history (student_id,stage,status,note,requirements,actor,created_at) SELECT id,'Stage 1','On Track','Record created by RPMS.','','Synthetic Admin One',created_at FROM students WHERE id=100");
    $pdo->beginTransaction();
    foreach(['student'=>'students','adviser'=>'advisers'] as $type=>$table) {
        $record=$pdo->query('SELECT * FROM '.$table)->fetch();
        lifecycle_identity_persist($pdo,lifecycle_actor(),$type,null,lifecycle_identity_capture($pdo,$record,$type));
    }
    $pdo->commit();
}
function lifecycle_input(string $type='student',string $action='permanent_delete'): array {
    return ['accountType'=>$type,'action'=>$action,'targetId'=>['student'=>100,'adviser'=>200,'admin'=>1][$type],
        'currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>['student'=>'S100','adviser'=>'E200','admin'=>$action==='archive'?'ARCHIVE':'DELETE'][$type],
        'confirmed'=>true,'testRecord'=>true];
}
function lifecycle_actor(int $id=1): array { return db()->query('SELECT * FROM users WHERE id='.$id)->fetch(); }
function lifecycle_expect_failure(array $data,string $class=AccountLifecycleConflict::class,int $actor=1,?PDO $connection=null): void {
    $before=db()->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $failure=null;
    try { lifecycle_execute($connection??db(),lifecycle_actor($actor),$data); }
    catch(Throwable $e) { $failure=$e; }
    reset_migration_expect(true,$failure!==null && get_class($failure)===$class,'Correct lifecycle rejection: '.($failure?->getMessage()??'Unexpected success'));
    reset_migration_expect($before,db()->query('SELECT * FROM users ORDER BY id')->fetchAll(),'Failure preserves login records');
    reset_migration_expect(false,($connection??db())->inTransaction(),'Failure ends transaction');
}
function lifecycle_worker_start(array $data,int $actor=1,array $extra=[]): array {
    global $port,$password,$datadir;
    $fixture=array_merge(['dsn'=>'mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle;charset=utf8mb4','password'=>$password,'datadir'=>$datadir,'actor'=>$actor,'data'=>$data,'verification'=>PRISM_HARD_DELETE_VERIFICATION],$extra);
    $process=proc_open([PHP_BINARY,__DIR__.'/account-lifecycle-worker.php',json_encode($fixture)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start lifecycle worker'); fclose($pipes[0]);
    return [$process,$pipes];
}
function lifecycle_worker_finish(array $worker): array {
    [$process,$pipes]=$worker; $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    if($exit!==0 && is_file($GLOBALS['datadir'].'/worker-errors.log')) {
        $lines=file($GLOBALS['datadir'].'/worker-errors.log');
        $fatal=array_values(array_filter($lines,fn($line)=>str_contains($line,'Fatal error:')));
        $err.=implode('',array_slice($fatal,-1));
    }
    reset_migration_expect(0,$exit,'Worker exit '.$exit.': '.$err);reset_migration_expect('',$err,'Worker has no diagnostics');
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}

if($argv[1]==='--lifecycle-remediation') {
    require __DIR__.'/account-lifecycle-remediation.php';
    echo 'PASS: isolated remediation checks; total assertions '.$GLOBALS['checks'].".\n";
    return;
}
foreach (['student','adviser'] as $type) {
    lifecycle_reset_fixture(); $result=lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input($type));
    reset_migration_expect(true,$result['ok'],'Eligible archived '.$type.' deletion');
    $table=$type==='student'?'students':'advisers';$id=$type==='student'?100:200;
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE id='.$id)->fetchColumn(),'Record deleted');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id='.$id)->fetchColumn(),'Login deleted');
    reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='{$type}_permanently_deleted'")->fetchColumn(),'Durable delete evidence remains');
}
foreach (['student','adviser','admin'] as $type) foreach ([100,200] as $actor) {
    lifecycle_reset_fixture(); lifecycle_expect_failure(lifecycle_input($type),AccountLifecycleForbidden::class,$actor);
}
foreach (['student','adviser','admin'] as $type) foreach (['currentPassword'=>'wrong','confirmation'=>'wrong','confirmed'=>false] as $field=>$value) {
    lifecycle_reset_fixture(); $data=lifecycle_input($type);$data[$field]=$value;lifecycle_expect_failure($data,AccountLifecycleValidation::class);
}
foreach (['student','adviser'] as $type) {
    lifecycle_reset_fixture();$data=lifecycle_input($type);$data['testRecord']=false;lifecycle_expect_failure($data,AccountLifecycleValidation::class);
}
$studentBlocks=[
    'active'=>"UPDATE students SET archived_at=NULL WHERE id=100",
    'protocol'=>"UPDATE students SET protocol_code='PROTECTED' WHERE id=100",
    'progress'=>"UPDATE students SET stage='Stage 2' WHERE id=100",
    'substantive history'=>"UPDATE ierb_history SET note='Institutional review' WHERE student_id=100",
    'multiple history'=>"INSERT INTO ierb_history (student_id,note) VALUES (100,'Review')",
    'document versions'=>"INSERT INTO documents (id,student_id,student_name,original_name,stored_name,is_current) VALUES ('old',100,'Synthetic Student','Old.txt','../../outside.txt',0)",
    'legacy documents'=>"INSERT INTO documents (id,student_name,original_name,stored_name) VALUES ('legacy','Synthetic Student','Legacy.txt','shared.txt')",
    'unlinked renamed document'=>"INSERT INTO documents (id,student_name,original_name,stored_name) VALUES ('legacy','Old identity','Legacy.txt','shared.txt')",
    'deadline recipient'=>"INSERT INTO calendar_deadlines (id,creator_user_id,title,deadline_date,target_scope) VALUES (1,1,'Protected','2027-01-01','all'); INSERT INTO calendar_deadline_recipients VALUES (1,100)",
    'notifications'=>"INSERT INTO notifications (recipient_type,recipient_id,recipient_email,message) VALUES ('student',100,'student@example.invalid','Protected')",
    'legacy notifications'=>"INSERT INTO notifications (recipient_type,recipient_email,message) VALUES ('student','old-identity@example.invalid','Protected')",
    'workflow audit'=>"INSERT INTO activity_logs (action,student_id,details) VALUES ('document_approved',100,'Protected')",
    'legacy audit'=>"INSERT INTO activity_logs (action,details) VALUES ('unknown','S100 evidence')",
    'identity edit'=>"INSERT INTO activity_logs (action,user_email) VALUES ('profile_updated','student@example.invalid')",
    'prior ID ambiguity'=>"INSERT INTO activity_logs (action,details) VALUES ('student_saved','student_id=OLD100')",
    'reports'=>"INSERT INTO reports (id,title,type,filename) VALUES ('snapshot','Protected','Progress','preserved.pdf')",
    'AI legacy'=>"INSERT INTO ai_outputs (id,type,source_ref) VALUES ('legacy','report','uncertain')",
    'ambiguous login'=>"UPDATE users SET ref_id='OTHER' WHERE id=100",
    'additional login'=>"UPDATE users SET ref_id='S100' WHERE id=200",
    'shared login'=>"UPDATE advisers SET user_id=100 WHERE id=200",
];
foreach($studentBlocks as $name=>$sql) {
    lifecycle_reset_fixture(); foreach(explode('; ',$sql) as $statement)$pdo->exec($statement);
    $before=$pdo->query('SELECT * FROM students')->fetchAll(); lifecycle_expect_failure(lifecycle_input());
    reset_migration_expect($before,$pdo->query('SELECT * FROM students')->fetchAll(),'Protected Student retained: '.$name);
}
$adviserBlocks=[
    'archive evidence'=>"DELETE FROM activity_logs WHERE action='adviser_deactivated'",
    'active'=>"UPDATE advisers SET status='Active' WHERE id=200",
    'archived student'=>"UPDATE students SET adviser_id=200 WHERE id=100",
    'IERB attribution'=>"UPDATE ierb_history SET actor='Synthetic Adviser (Admin Override)' WHERE student_id=100",
    'document attribution'=>"INSERT INTO documents (id,original_name,stored_name,reviewed_by) VALUES ('review','Old.txt','preserved.txt','Synthetic Adviser')",
    'deadline author'=>"INSERT INTO calendar_deadlines (creator_user_id,title,deadline_date,target_scope) VALUES (200,'Protected','2027-01-01','all')",
    'notification attribution'=>"INSERT INTO notifications (recipient_type,message,created_by) VALUES ('student','Protected','Synthetic Adviser')",
    'identity ambiguity'=>"INSERT INTO activity_logs (action,entity_type,entity_id,before_value) VALUES ('adviser_saved','adviser','200','Active')",
    'report snapshot'=>"INSERT INTO reports (id,title,type,filename) VALUES ('snapshot','Protected','Progress','preserved.pdf')",
];
foreach($adviserBlocks as $name=>$sql) { lifecycle_reset_fixture();$pdo->exec($sql);lifecycle_expect_failure(lifecycle_input('adviser')); }

foreach(['archive','permanent_delete'] as $action) {
    lifecycle_reset_fixture();$data=lifecycle_input('admin',$action);$data['targetId']=2;lifecycle_expect_failure($data,AccountLifecycleForbidden::class);
    lifecycle_reset_fixture();$pdo->exec("UPDATE users SET status='Inactive' WHERE id=2");lifecycle_expect_failure(lifecycle_input('admin',$action));
    lifecycle_reset_fixture();$pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES (1,'synthetic-token','2027-01-01')");
    $worker=lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input('admin',$action)));
    reset_migration_expect(200,$worker['status'],'Self '.$action.' endpoint succeeds');
    reset_migration_expect(false,$worker['sessionActive'],'Self lifecycle destroys session');
    reset_migration_expect(null,$worker['sessionUser'],'Self lifecycle clears session identity');
    reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=1 AND used=0')->fetchColumn(),'Outstanding tokens unusable');
    reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='Active'")->fetchColumn(),'Another active Admin remains');
}
lifecycle_reset_fixture();$pdo->exec("INSERT INTO calendar_deadlines (creator_user_id,title,deadline_date,target_scope) VALUES (1,'Protected','2027-01-01','all')");lifecycle_expect_failure(lifecycle_input('admin'));
lifecycle_reset_fixture();$pdo->exec("INSERT INTO reports (id,title,type,filename,generated_by,generated_by_user_id) VALUES ('snapshot','Protected','Progress','preserved.pdf','Synthetic Admin One',1)");
$before=$pdo->query('SELECT * FROM reports')->fetchAll();lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input('admin'));reset_migration_expect($before,$pdo->query('SELECT * FROM reports')->fetchAll(),'Admin delete preserves report author ID and attribution');
foreach([100,200] as $actor) { lifecycle_reset_fixture();$worker=lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input(),$actor));reset_migration_expect(403,$worker['status'],'Endpoint role authorization'); }
lifecycle_reset_fixture();$worker=lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input(),1,['badOrigin'=>true]));reset_migration_expect(403,$worker['status'],'Cross-origin write denied');

// Archive exercises existing production helpers/endpoints and retains every collected row.
lifecycle_reset_fixture();$pdo->exec("UPDATE students SET archived_at=NULL,adviser_id=200 WHERE id=100");$pdo->exec("UPDATE users SET status='Active' WHERE id=100");
$history=$pdo->query('SELECT * FROM ierb_history')->fetchAll();archive_student($pdo,lifecycle_actor(),100);reset_migration_expect($history,$pdo->query('SELECT * FROM ierb_history')->fetchAll(),'Student archive preserves history');
reset_migration_expect('Inactive',$pdo->query('SELECT status FROM users WHERE id=100')->fetchColumn(),'Student archive deactivates login');
lifecycle_execute($pdo,lifecycle_actor(),lifecycle_input());reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=100')->fetchColumn(),'Student can be deleted only after the real archive operation');
lifecycle_reset_fixture();$pdo->exec("UPDATE advisers SET status='Active' WHERE id=200");$pdo->exec("UPDATE users SET status='Active' WHERE id=200");$pdo->exec('UPDATE students SET adviser_id=200 WHERE id=100');
$worker=lifecycle_worker_finish(lifecycle_worker_start(['id'=>200],1,['mode'=>'adviser_archive']));reset_migration_expect(200,$worker['status'],'Existing Adviser archive succeeds');
reset_migration_expect(200,(int)$pdo->query('SELECT adviser_id FROM students WHERE id=100')->fetchColumn(),'Adviser archive retains historical assignment');
reset_migration_expect('Inactive',$pdo->query('SELECT status FROM users WHERE id=200')->fetchColumn(),'Adviser archive deactivates login');

// A mandatory audit failure must roll back, without changing the reviewed schema.
class LifecycleAuditFailurePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_starts_with($query,'INSERT INTO activity_logs'))throw new RuntimeException('Injected audit persistence failure');
        return parent::prepare($query,$options);
    }
}
$failureConnection=new LifecycleAuditFailurePDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
lifecycle_reset_fixture();lifecycle_expect_failure(lifecycle_input(),RuntimeException::class,1,$failureConnection);
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=100')->fetchColumn(),'Audit failure retains Student');
class LifecycleDependencyFailurePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_starts_with($query,'DELETE FROM users'))throw new RuntimeException('Injected dependency cleanup failure');
        return parent::prepare($query,$options);
    }
}
$dependencyConnection=new LifecycleDependencyFailurePDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
lifecycle_reset_fixture();$pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES (100,'synthetic-token','2027-01-01')");
$before=[];foreach(['students','ierb_history','password_resets','activity_logs'] as $table)$before[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
lifecycle_expect_failure(lifecycle_input(),RuntimeException::class,1,$dependencyConnection);
foreach($before as $table=>$rows)reset_migration_expect($rows,$pdo->query('SELECT * FROM '.$table)->fetchAll(),'Dependency failure rolls back '.$table);

// Files are never opened or removed by hard deletion. Shared versions and traversal-like names block it.
lifecycle_reset_fixture();$outside=$datadir.'/outside-fixture.txt';file_put_contents($outside,'Preserved synthetic content');
$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('v1',100,'Fixture.txt','../../outside-fixture.txt',0),('v2',100,'Fixture.txt','../../outside-fixture.txt',1)");
lifecycle_expect_failure(lifecycle_input());reset_migration_expect('Preserved synthetic content',file_get_contents($outside),'Outside/shared file unchanged');
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),'Every shared document version remains');

// A protected-history writer holding the Student lock wins; deletion observes its committed history.
lifecycle_reset_fixture();$pdo->beginTransaction();$pdo->query('SELECT id FROM students WHERE id=100 FOR UPDATE')->fetchAll();
$waiting=lifecycle_worker_start(lifecycle_input());usleep(150000);
$pdo->exec("INSERT INTO ierb_history (student_id,note) VALUES (100,'Concurrent institutional review')");$pdo->commit();
$blocked=lifecycle_worker_finish($waiting);reset_migration_expect(409,$blocked['status'],'Concurrent substantive-history write prevents deletion');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=100')->fetchColumn(),'Concurrent history retains Student');

// Raw endpoint requests enforce password, confirmation, and self-only checks independently of buttons.
foreach(['archive','permanent_delete'] as $action) {
    foreach(['currentPassword'=>'wrong','confirmation'=>'wrong','confirmed'=>false,'targetId'=>2] as $field=>$value) {
        lifecycle_reset_fixture();$data=lifecycle_input('admin',$action);$data[$field]=$value;
        $response=lifecycle_worker_finish(lifecycle_worker_start($data));
        reset_migration_expect($field==='targetId'?403:422,$response['status'],'Raw Admin lifecycle request rejects '.$field);
        reset_migration_expect(2,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='Active'")->fetchColumn(),'Rejected endpoint leaves Admins active');
    }
    foreach([100,200] as $actor) { lifecycle_reset_fixture();$response=lifecycle_worker_finish(lifecycle_worker_start(lifecycle_input('admin',$action),$actor));reset_migration_expect(403,$response['status'],'Student/Adviser cannot archive or delete Admin'); }
}

// Concurrent Admin operations begin with both actors authenticated, then race on the shared locks.
foreach(['archive','permanent_delete'] as $action) {
    lifecycle_reset_fixture();$gate=$datadir.'/gate-'.$action;
    $a=lifecycle_input('admin',$action);$b=$a;$b['targetId']=2;
    $w1=lifecycle_worker_start($a,1,['waitFile'=>$gate.'1']);$w2=lifecycle_worker_start($b,2,['waitFile'=>$gate.'2']);
    $deadline=microtime(true)+10;while((!file_exists($gate.'1.ready')||!file_exists($gate.'2.ready'))&&microtime(true)<$deadline)usleep(10000);
    file_put_contents($gate.'1','go');file_put_contents($gate.'2','go');
    $r1=lifecycle_worker_finish($w1);$r2=lifecycle_worker_finish($w2);$statuses=[$r1['status'],$r2['status']];sort($statuses);
    reset_migration_expect([200,409],$statuses,'Concurrent '.$action.' protects last active Admin');
    reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='Active'")->fetchColumn(),'Concurrent operation retains exactly one active Admin');
}
lifecycle_reset_fixture();$pdo->exec('CREATE TABLE unexpected_reference (id INT PRIMARY KEY,user_id INT, FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB');
lifecycle_expect_failure(lifecycle_input());$pdo->exec('DROP TABLE unexpected_reference');
$pdo->exec('ALTER TABLE students ADD COLUMN unexpected_reference INT NULL');lifecycle_expect_failure(lifecycle_input());$pdo->exec('ALTER TABLE students DROP COLUMN unexpected_reference');
$pdo->exec('CREATE DATABASE hardening_test_external');
$pdo->exec('CREATE TABLE hardening_test_external.external_reference (id INT PRIMARY KEY,user_id INT, FOREIGN KEY(user_id) REFERENCES hardening_test_lifecycle.users(id)) ENGINE=InnoDB');
lifecycle_expect_failure(lifecycle_input());$pdo->exec('DROP DATABASE hardening_test_external');
lifecycle_verify_schema($pdo);
reset_migration_expect('8',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Lifecycle leaves schema version unchanged');
require __DIR__.'/account-lifecycle-remediation.php';
file_put_contents(__DIR__.'/lifecycle-results/integration-results.json',json_encode(['assertions'=>$GLOBALS['checks'],'passed'=>true,'database'=>'disposable MariaDB','schema_version'=>8],JSON_PRETTY_PRINT));
echo 'PASS: account lifecycle integration and concurrency checks completed; total assertions '.$GLOBALS['checks'].".\n";
