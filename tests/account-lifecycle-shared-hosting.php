<?php
/** Actual restricted grants and metadata on the runner's private MariaDB, never production. */
if(PHP_SAPI!=='cli' || !isset($datadir,$pdo) || !function_exists('lifecycle_reset_fixture')) exit(1);
$scopedStart=$GLOBALS['checks'];
$scopedPassword=bin2hex(random_bytes(24));
$pdo->exec("CREATE USER 'lifecycle_scoped'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($scopedPassword));
$pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON hardening_test_lifecycle.* TO 'lifecycle_scoped'@'127.0.0.1'");
$scoped=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_lifecycle','lifecycle_scoped',$scopedPassword,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try { lifecycle_privileged_verification($scoped); throw new RuntimeException('Scoped grants passed global verification'); }
catch(AccountLifecycleConflict $error) { reset_migration_expect(true,str_contains($error->getMessage(),'Complete server'),'Global mode never falls back'); }
try { lifecycle_shared_hosting_verification($scoped); throw new RuntimeException('Missing external assurance accepted'); }
catch(AccountLifecycleConflict $error) { reset_migration_expect(true,str_contains($error->getMessage(),'external'),'Explicit external exclusion required'); }
// XAMPP initializes a public test schema. It is not fully covered by direct scoped grants.
if(in_array('test',$scoped->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN),true)) {
    try { lifecycle_shared_hosting_verification($scoped,true); throw new RuntimeException('Partially visible schema accepted'); }
    catch(AccountLifecycleConflict $error) { reset_migration_expect(true,str_contains($error->getMessage(),'incomplete: test'),'Partially visible application schema fails closed'); }
    $pdo->exec('DROP DATABASE test'); // Only the runner-owned disposable instance.
}
// The actual verifier path is read-only and performs no bootstrap/migration writes.
lifecycle_reset_fixture();
$before=$pdo->query('SELECT * FROM activity_logs ORDER BY id')->fetchAll();
$scoped->exec('SET TRANSACTION READ ONLY'); $scoped->beginTransaction();
$scopedEvidence=lifecycle_shared_hosting_verification($scoped,true); $scoped->rollBack();
reset_migration_expect($before,$pdo->query('SELECT * FROM activity_logs ORDER BY id')->fetchAll(),'Verifier leaves audit rows unchanged');
reset_migration_expect(false,$scopedEvidence['complete_visibility'],'Scoped evidence never claims complete visibility');
reset_migration_expect(['hardening_test_lifecycle'],$scopedEvidence['visibility']['visible_schemas'],'Every visible application database enumerated');
$scopedWorker=['dbUser'=>'lifecycle_scoped','password'=>$scopedPassword,'verification'=>$scopedEvidence];
function scoped_response(array $data, array $extra=[]): array {
    return lifecycle_worker_finish(lifecycle_worker_start($data,1,array_replace($GLOBALS['scopedWorker'],$extra)));
}
function scoped_rejected(string $label, array $extra=[], ?array $data=null, int $status=409): void {
    $before=[]; foreach(['users','students','advisers','activity_logs','ierb_history'] as $table) $before[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    $response=scoped_response($data??lifecycle_input(),$extra);
    reset_migration_expect($status,$response['status'],$label.': '.($response['data']['message']??''));
    foreach($before as $table=>$rows) reset_migration_expect($rows,db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(),$label.' preserves '.$table);
}
// New record with creation provenance, recognized setup history, and the real Admin archive helper.
lifecycle_reset_fixture();
$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');
$pdo->exec("UPDATE users SET status='Active' WHERE id=100");
archive_student($pdo,lifecycle_actor(),100);
$response=scoped_response(lifecycle_input());
reset_migration_expect(200,$response['status'],'Archived unused Student succeeds with real scoped credentials');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=100')->fetchColumn(),'Student physically deleted');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=100')->fetchColumn(),'Owned Student login deleted');
reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='student_permanently_deleted'")->fetchColumn(),'Deletion audit retained');
lifecycle_reset_fixture(); reset_migration_expect(200,scoped_response(lifecycle_input('adviser'))['status'],'Unused archived Adviser supports scoped mode');

$badBindings=[];
foreach(['db','host','port','server_id','version'] as $field) {
    $bad=$scopedEvidence; $bad['database'][$field]='wrong-'.$field; $badBindings['wrong binding '.$field]=$bad;
}
$badEvidence=$badBindings+[
    'boolean alone'=>[],
    'wrong hash'=>array_replace($scopedEvidence,['manifest_sha256'=>str_repeat('0',64)]),
    'schema changes not excluded'=>array_replace($scopedEvidence,['schema_changes_excluded'=>false]),
    'external dependencies not excluded'=>array_replace($scopedEvidence,['external_dependencies_excluded'=>false]),
    'altered mode'=>array_replace($scopedEvidence,['verification_mode'=>'globally_privileged']),
    'unknown mode'=>array_replace($scopedEvidence,['verification_mode'=>'shared']),
    'false global visibility claim'=>array_replace($scopedEvidence,['complete_visibility'=>true]),
    'missing scoped visibility'=>array_replace($scopedEvidence,['visibility'=>[]]),
    'expired evidence'=>array_replace($scopedEvidence,['issued_at'=>time()-86401,'expires_at'=>time()-1]),
    'future evidence'=>array_replace($scopedEvidence,['issued_at'=>time()+3600,'expires_at'=>time()+90000]),
    'extended expiry'=>array_replace($scopedEvidence,['expires_at'=>time()+172800]),
    'obsolete evidence'=>array_replace($scopedEvidence,['evidence_version'=>1]),
];
foreach($badEvidence as $label=>$evidence) { lifecycle_reset_fixture(); scoped_rejected($label,['verification'=>$evidence]); }
lifecycle_reset_fixture(); scoped_rejected('missing enabling flag',['verified'=>false]);
lifecycle_reset_fixture(); scoped_rejected('missing verification object',['omitVerification'=>true]);
lifecycle_reset_fixture(); scoped_rejected('foreign-key checks disabled',['foreignKeyChecks'=>0]);
reset_migration_expect(true,scoped_response([],['mode'=>'availability'])['data']['available'],'Scoped availability accepts valid limited assurance');
reset_migration_expect(false,scoped_response([],['mode'=>'availability','verified'=>false])['data']['available'],'UI status refuses unavailable gate');
foreach([100,200] as $viewer) {
    reset_migration_expect(403,lifecycle_worker_finish(lifecycle_worker_start([], $viewer, array_replace($scopedWorker,['mode'=>'availability'])))['status'],'Availability is Admin-only');
}

$blocks=[
    'active Student'=>"UPDATE students SET archived_at=NULL WHERE id=100",
    'document'=>"INSERT INTO documents (id,student_id,original_name,stored_name) VALUES ('doc',100,'Protected','preserved.txt')",
    'document version'=>"INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('version',100,'Old','preserved.txt',0)",
    'notification'=>"INSERT INTO notifications (recipient_type,recipient_id,message) VALUES ('student',100,'Protected')",
    'deadline recipient'=>"INSERT INTO calendar_deadlines (id,title,deadline_date,target_scope) VALUES (1,'Protected','2027-01-01','all'); INSERT INTO calendar_deadline_recipients VALUES (1,100)",
    'substantive IERB'=>"UPDATE ierb_history SET note='Review decision' WHERE student_id=100",
    'identity edit'=>"INSERT INTO activity_logs (action,user_email) VALUES ('profile_updated','student@example.invalid')",
    'missing creation provenance'=>"DELETE FROM activity_logs WHERE action='account_identity_created' AND entity_type='student'",
    'workflow audit'=>"INSERT INTO activity_logs (action,student_id) VALUES ('document_approved',100)",
    'report ambiguity'=>"INSERT INTO reports (id,title,type,filename) VALUES ('report','Protected','Progress','preserved.pdf')",
    'AI ambiguity'=>"INSERT INTO ai_outputs (id,type,source_ref) VALUES ('ai','report','ambiguous')",
    'protocol'=>"UPDATE students SET protocol_code='P100' WHERE id=100",
    'PI'=>"UPDATE students SET is_principal_investigator=1 WHERE id=100",
    'progress'=>"UPDATE students SET stage='Stage 2' WHERE id=100",
    'status'=>"UPDATE students SET status='Pending' WHERE id=100",
    'requirements'=>"UPDATE students SET requirements='Protected' WHERE id=100",
    'submission date'=>"UPDATE students SET last_submission_date=NOW() WHERE id=100",
    'login ownership'=>"UPDATE users SET ref_id='OTHER' WHERE id=100",
    'legacy documents'=>"INSERT INTO documents (id,original_name,stored_name,student_name) VALUES ('legacy','Old','preserved.txt','Unknown')",
];
foreach($blocks as $label=>$sql) {
    lifecycle_reset_fixture(); foreach(explode('; ',$sql) as $statement) $pdo->exec($statement);
    scoped_rejected($label);
}
foreach(['currentPassword'=>'incorrect','confirmation'=>'wrong','confirmed'=>false,'testRecord'=>false] as $field=>$value) {
    lifecycle_reset_fixture(); $input=lifecycle_input(); $input[$field]=$value; scoped_rejected('invalid '.$field,[],$input,422);
}
lifecycle_reset_fixture(); $input=lifecycle_input('admin'); $input['targetId']=2; scoped_rejected('another Admin',[],$input,403);
lifecycle_reset_fixture(); $pdo->exec("UPDATE users SET status='Inactive' WHERE id=2"); scoped_rejected('last Admin',[],lifecycle_input('admin'));

// Each deviation is introduced and restored in the disposable database only.
foreach([
    ['schema version',"UPDATE schema_meta SET v='7' WHERE k='schema_version'","UPDATE schema_meta SET v='8' WHERE k='schema_version'"],
    ['column type','ALTER TABLE students MODIFY full_name VARCHAR(191) NOT NULL','ALTER TABLE students MODIFY full_name VARCHAR(190) NOT NULL'],
    ['column nullability','ALTER TABLE students MODIFY research_title VARCHAR(255) NOT NULL','ALTER TABLE students MODIFY research_title VARCHAR(255) NULL'],
    ['column default',"ALTER TABLE students ALTER COLUMN stage SET DEFAULT 'Stage 2'","ALTER TABLE students ALTER COLUMN stage SET DEFAULT 'Stage 1'"],
    ['index order','CREATE INDEX unexpected_order ON students(full_name,student_id)','DROP INDEX unexpected_order ON students'],
    ['FK rule','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1; ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE RESTRICT','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1; ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE SET NULL'],
    ['trigger','CREATE TRIGGER unexpected_scoped BEFORE DELETE ON students FOR EACH ROW SET @unexpected_scoped=1','DROP TRIGGER unexpected_scoped'],
    ['extra table','CREATE TABLE unexpected_scoped (id INT PRIMARY KEY) ENGINE=InnoDB','DROP TABLE unexpected_scoped'],
] as [$label,$introduce,$restore]) {
    lifecycle_reset_fixture();
    if($label==='column nullability') $pdo->exec("UPDATE students SET research_title='' WHERE research_title IS NULL");
    foreach(explode('; ',$introduce) as $statement) $pdo->exec($statement);
    scoped_rejected($label);
    foreach(explode('; ',$restore) as $statement) $pdo->exec($statement);
    if($label==='FK rule') {
        // MariaDB recreates the original implicit adviser_id index under the constraint name.
        $pdo->exec('ALTER TABLE students ADD INDEX adviser_id(adviser_id)');
        if($pdo->query("SHOW INDEX FROM students WHERE Key_name='students_ibfk_1'")->fetchAll()) $pdo->exec('ALTER TABLE students DROP INDEX students_ibfk_1');
    }
    lifecycle_compare_schema($pdo);
}
$pdo->exec('CREATE DATABASE hardening_test_scoped_external');
$pdo->exec("GRANT SELECT,TRIGGER ON hardening_test_scoped_external.* TO 'lifecycle_scoped'@'127.0.0.1'");
foreach(['RESTRICT','CASCADE','SET NULL'] as $rule) {
    lifecycle_reset_fixture();
    $pdo->exec("CREATE TABLE hardening_test_scoped_external.incoming (id INT PRIMARY KEY,student_id INT NULL, FOREIGN KEY(student_id) REFERENCES hardening_test_lifecycle.students(id) ON DELETE $rule) ENGINE=InnoDB");
    $pdo->exec('INSERT INTO hardening_test_scoped_external.incoming VALUES (1,100)');
    scoped_rejected('visible external '.$rule);
    reset_migration_expect(100,(int)$pdo->query('SELECT student_id FROM hardening_test_scoped_external.incoming')->fetchColumn(),'External dependency untouched');
    try { lifecycle_shared_hosting_verification($scoped,true); throw new RuntimeException('External reference accepted'); }
    catch(AccountLifecycleConflict $error) { reset_migration_expect(true,str_contains($error->getMessage(),'cross-schema'),'Verifier rejects external reference itself'); }
    $pdo->exec('DROP TABLE hardening_test_scoped_external.incoming');
}
$pdo->exec('DROP DATABASE hardening_test_scoped_external');
$pdo->exec("REVOKE SELECT,TRIGGER ON hardening_test_scoped_external.* FROM 'lifecycle_scoped'@'127.0.0.1'");
// Hidden RESTRICT is still enforced by InnoDB even though it is absent from scoped metadata.
$pdo->exec('CREATE DATABASE hardening_test_hidden');
$pdo->exec('CREATE TABLE hardening_test_hidden.incoming (id INT PRIMARY KEY,student_id INT, FOREIGN KEY(student_id) REFERENCES hardening_test_lifecycle.students(id) ON DELETE RESTRICT) ENGINE=InnoDB');
lifecycle_reset_fixture(); $pdo->exec('INSERT INTO hardening_test_hidden.incoming VALUES (1,100)');
scoped_rejected('hidden RESTRICT rolls back including audit');
$pdo->exec('DROP DATABASE hardening_test_hidden');
$pdo->exec("REVOKE TRIGGER ON hardening_test_lifecycle.* FROM 'lifecycle_scoped'@'127.0.0.1'");
lifecycle_reset_fixture(); scoped_rejected('missing trigger visibility');
$pdo->exec("GRANT TRIGGER ON hardening_test_lifecycle.* TO 'lifecycle_scoped'@'127.0.0.1'");
reset_migration_expect(['select'=>true,'trigger'=>true],lifecycle_schema_privileges(['GRANT SELECT, TRIGGER ON `host\\_prism`.* TO x'],'host_prism'),'Escaped underscore grants match Hostinger database names');
reset_migration_expect(['select'=>false,'trigger'=>false],lifecycle_schema_privileges(['GRANT SELECT, TRIGGER ON `host\\_prism`.* TO x'],'hostXprism'),'Escaped underscore is not a wildcard');
// Global evidence remains usable by a scoped web connection after privileged operator review.
lifecycle_reset_fixture(); reset_migration_expect(200,scoped_response(lifecycle_input(),['verification'=>PRISM_HARD_DELETE_VERIFICATION])['status'],'Global mode retained with separate verifier credentials');
foreach([
    [['--verify','--schema-changes-excluded'],1],
    [['--verify-shared-hosting','--schema-changes-excluded'],1],
    [['--verify-shared-hosting','--schema-changes-excluded','--external-dependencies-excluded'],0],
] as [$arguments,$expectedExit]) {
    $environment=array_merge(getenv(),['PRISM_SCHEMA_VERIFY_HOST'=>'127.0.0.1','PRISM_SCHEMA_VERIFY_PORT'=>(string)$port,
        'PRISM_SCHEMA_VERIFY_DATABASE'=>'hardening_test_lifecycle','PRISM_SCHEMA_VERIFY_USER'=>'lifecycle_scoped','PRISM_SCHEMA_VERIFY_PASSWORD'=>$scopedPassword]);
    $process=proc_open(array_merge([PHP_BINARY,__DIR__.'/../tools/verify-account-lifecycle-schema.php'],$arguments),
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $diagnostic=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    reset_migration_expect($expectedExit,proc_close($process),'Actual CLI explicit mode/acknowledgement contract');
    reset_migration_expect($expectedExit===0,str_contains($output,"define('PRISM_HARD_DELETE_VERIFICATION'"),'CLI only emits evidence on success');
    reset_migration_expect(false,str_contains($output.$diagnostic,$scopedPassword),'CLI does not disclose credentials');
}
echo 'PASS: shared-hosting assertions '.($GLOBALS['checks']-$scopedStart)."; actual schema-scoped MariaDB user.\n";
