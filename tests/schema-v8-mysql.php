<?php
/** Parent fixture: only reachable inside the verified disposable MariaDB lifecycle. */
if (PHP_SAPI !== 'cli' || !isset($pdo) || !function_exists('reset_migration_expect')) exit(1);
date_default_timezone_set('Asia/Manila');
function db(): PDO { return $GLOBALS['pdo']; }
function log_api_error(...$args): void { throw new RuntimeException('Unexpected test error: '.json_encode($args)); }
function fixture_function(string $file, string $name): string {
    $source = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../'.$file));
    $start = strpos($source,'function '.$name.'('); $end = strpos($source,"\n}\n",$start);
    return substr($source,$start,$end+2-$start);
}
require __DIR__.'/../security.php';
require __DIR__.'/../workflow.php';
require __DIR__.'/../includes/academic_catalog.php';
require __DIR__.'/../includes/calendar_deadlines.php';
$pdo->exec('CREATE DATABASE hardening_test_main'); $pdo->exec('USE hardening_test_main');
$GLOBALS['allowV7'] = true;
PrismResetMigrationSQL\migrate($pdo);
reset_migration_expect('7', $pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(), 'Starting version seven');
$pdo->exec("SET time_zone = '+08:00'");
$pdo->exec("INSERT INTO users (id,username,password_hash,role,full_name,email) VALUES
    (1,'admin','fixture','admin','Admin','admin@example.test'),(2,'advA','fixture','adviser','Adviser A','a@example.test'),
    (3,'advB','fixture','adviser','Adviser B','b@example.test'),(4,'student','fixture','student','Synthetic One','s1@example.test')");
$pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email) VALUES (1,'A','Adviser A','a@example.test'),(2,'B','Adviser B','b@example.test')");
$pdo->exec("INSERT INTO students (id,student_id,full_name,email,adviser_id,research_group,academic_unit_key,program_key,year_level,academic_year)
    VALUES (1,'S1','Synthetic One','s1@example.test',1,'AMT-BSIT-Y2-2627-G01','amt','bsit','2nd Year','2026-2027'),
    (2,'S2','Synthetic Two','s2@example.test',2,'AMT-BSIT-Y2-2627-G01','amt','bsit','2nd Year','2026-2027')");
$pdo->exec("INSERT INTO ierb_history (student_id,stage,status,note,actor) VALUES (1,'Stage 1','On Track','Retain history','Admin')");
$pdo->exec("INSERT INTO documents (id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,version_no,is_current)
    VALUES ('v1',1,'Synthetic One','Student','student','Fixture.txt','fixture.txt','text/plain',8,'Protocol','Stage 1',1,0),
    ('v2',1,'Synthetic One','Student','student','Fixture.txt','fixture2.txt','text/plain',8,'Protocol','Stage 1',2,1)");
$pdo->exec("UPDATE documents SET rpms_submitted_at=NOW() WHERE id='v2'");
$pdo->exec("INSERT INTO reports (id,title,type,filename,generated_by) VALUES ('keep','Retain report','Progress Report','fixture.pdf','Admin')");
$pdo->exec("INSERT INTO activity_logs (user_email,action,details) VALUES ('admin@example.test','fixture','Retain audit')");
$before=[];
foreach (['users','students','advisers','ierb_history','documents','reports','activity_logs','calendar_deadlines','notifications'] as $table) $before[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
$GLOBALS['denyMigration']=true;
try { PrismResetMigrationSQL\migrate($pdo); throw new LogicException('Web/unguarded migration must refuse'); }
catch (RuntimeException $e) { reset_migration_expect('7',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Guard preserves version'); }
$GLOBALS['denyMigration']=false; $GLOBALS['allowV8']=true;
PrismResetMigrationSQL\migrate($pdo);
reset_migration_expect('8',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Version eight after validation');
foreach ($before as $table=>$rows) {
    $after=$pdo->query('SELECT * FROM '.$table)->fetchAll();
    foreach ($after as &$row) { unset($row['archived_at'],$row['sending_started_at']); } unset($row);
    reset_migration_expect($rows,$after,'All collected rows preserved: '.$table);
}
$ddl=[];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $ddl[$table]=$pdo->query('SHOW CREATE TABLE '.$table)->fetchColumn(1);
PrismResetMigrationSQL\migrate($pdo);
foreach ($ddl as $table=>$sql) reset_migration_expect($sql,$pdo->query('SHOW CREATE TABLE '.$table)->fetchColumn(1),'Migration is idempotent: '.$table);

// Validate the actual operator SQL with isolated settings, guard and repeat behavior.
$manual = file_get_contents(__DIR__.'/../tools/schema-v8-manual.sql');
// Operator settings may already name a staging database. Never rewrite that local file.
// Only the test copy receives fixture settings; every migration statement remains intact.
$manual = preg_replace("/^SET @PRISM_EXPECT_DB = '[^']*';$/m", "SET @PRISM_EXPECT_DB = 'REPLACE_WITH_EXACT_DATABASE_NAME';", $manual, 1, $databaseSettings);
$manual = preg_replace('/^SET @PRISM_BACKUP_AND_STAGING_VERIFIED = [01];$/m', 'SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 0;', $manual, 1, $backupSettings);
reset_migration_expect(1, $databaseSettings, 'Exactly one operator database setting isolated');
reset_migration_expect(1, $backupSettings, 'Exactly one operator backup setting isolated');
foreach ([false,true,true] as $enabled) {
    $sql = $enabled ? str_replace(["'REPLACE_WITH_EXACT_DATABASE_NAME'",'SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 0'],["'hardening_test_main'",'SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 1'],$manual) : $manual;
    $sql = preg_replace('/^--.*$/m','',$sql);
    foreach (preg_split('/;\s*(?:\n|$)/',$sql) as $statement) if (trim($statement)!=='') {
        $result = $pdo->query($statement);
        if ($result->columnCount()) $result->fetchAll();
        $result->closeCursor();
    }
    foreach ($ddl as $table=>$definition) reset_migration_expect($definition,$pdo->query('SHOW CREATE TABLE '.$table)->fetchColumn(1),'Manual SQL guard/repeat preserves DDL: '.$table);
}

// Verify strict schema parity fails before stamping; repair only this disposable table.
$pdo->exec('CREATE DATABASE hardening_manual_test'); $pdo->exec('USE hardening_manual_test');
$GLOBALS['allowV8']=false;
PrismResetMigrationSQL\migrate($pdo);
$pdo->exec("INSERT INTO students (student_id,full_name,email) VALUES ('MANUAL1','Synthetic manual preservation','manual@example.test')");
$manualBefore=$pdo->query('SELECT * FROM students')->fetchAll();
foreach ([false,true,true] as $enabled) {
    $sql=$enabled ? str_replace(["'REPLACE_WITH_EXACT_DATABASE_NAME'",'SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 0'],["'hardening_manual_test'",'SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 1'],$manual) : $manual;
    foreach(preg_split('/;\s*(?:\n|$)/',preg_replace('/^--.*$/m','',$sql)) as $statement) if(trim($statement)!=='') {
        $result=$pdo->query($statement); if($result->columnCount())$result->fetchAll(); $result->closeCursor();
    }
    reset_migration_expect($enabled?1:0,(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='archived_at'")->fetchColumn(),'Manual additive migration defaults to refusal; enabled/repeat adds lifecycle');
    reset_migration_expect('7',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Manual SQL never advances version without separate validation');
    $after=$pdo->query('SELECT * FROM students')->fetchAll(); foreach($after as &$row)unset($row['archived_at']);unset($row);
    reset_migration_expect($manualBefore,$after,'Exact manual migration retains Student values');
}
$GLOBALS['allowV8']=true; PrismResetMigrationSQL\migrate($pdo);
reset_migration_expect('8',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Canonical validation accepts exact manual migration schema');
$pdo->exec('USE hardening_test_main');

// Verify strict schema parity fails before stamping; repair only this disposable table.
$pdo->exec("UPDATE schema_meta SET v='7' WHERE k='schema_version'");
$pdo->exec('ALTER TABLE calendar_deadline_groups MODIFY research_group VARCHAR(190) COLLATE utf8mb4_general_ci NOT NULL');
try { PrismResetMigrationSQL\migrate($pdo); throw new LogicException('Collation mismatch accepted'); }
catch (RuntimeException $e) { reset_migration_expect('7',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Failed schema validation never advances version'); }
$pdo->exec('ALTER TABLE calendar_deadline_groups MODIFY research_group VARCHAR(190) COLLATE utf8mb4_bin NOT NULL');
PrismResetMigrationSQL\migrate($pdo);
$pdo->exec("UPDATE schema_meta SET v='7' WHERE k='schema_version'");
$pdo->exec('ALTER TABLE calendar_deadline_recipients ADD INDEX fixture_student (student_id), DROP INDEX deadline_recipient_student');
try { PrismResetMigrationSQL\migrate($pdo); throw new LogicException('Audience index mismatch accepted'); }
catch (RuntimeException $e) { reset_migration_expect('7',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Missing audience index never advances version'); }
$pdo->exec('ALTER TABLE calendar_deadline_recipients ADD INDEX deadline_recipient_student (student_id,deadline_id), DROP INDEX fixture_student');
PrismResetMigrationSQL\migrate($pdo);

$connection=['database'=>'hardening_test_main','datadir'=>$datadir,'dsn'=>'mysql:host=127.0.0.1;port='.$port.';dbname=hardening_test_main;charset=utf8mb4','password'=>$password];
$admin=['id'=>1,'role'=>'admin','email'=>'admin@example.test','full_name'=>'Admin'];
$adviser=['id'=>2,'role'=>'adviser','email'=>'a@example.test','full_name'=>'Adviser A'];
function parallel_fixtures(array $cases): array {
    $running=[];
    foreach ($cases as $case) {
        $p=proc_open([PHP_BINARY,__DIR__.'/hardening-concurrency.php',json_encode($GLOBALS['connection']+$case)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]); $running[]=[$p,$pipes];
    }
    $results=[];
    foreach ($running as [$p,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        reset_migration_expect(0,$exit,'Concurrent fixture exits successfully: '.$err);
        reset_migration_expect('',$err,'Concurrent fixture emits no warnings');
        $results[]=$out;
    }
    return $results;
}
$data=['title'=>'Shared group deadline','description'=>'Synthetic only','date'=>'2026-10-20','target'=>'selected','groups'=>['AMT-BSIT-Y2-2627-G01']];
$cases=array_fill(0,12,['mode'=>'deadline','actor'=>$adviser,'data'=>$data]);
$results=parallel_fixtures($cases);
$ids=[];foreach($results as $raw){$r=json_decode($raw,true);reset_migration_expect(200,$r['status'],'Identical create/retry succeeds');$ids[]=$r['data']['id'];}
reset_migration_expect(1,count(array_unique($ids)),'Twelve simultaneous requests create one deadline');
$id=$ids[0];
reset_migration_expect([1],array_map('intval',$pdo->query('SELECT student_id FROM calendar_deadline_recipients WHERE deadline_id='.$id)->fetchAll(PDO::FETCH_COLUMN)),'Creator current assigned audience only');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Only one notification queued');
foreach ([['id'=>3,'role'=>'adviser','email'=>'b@example.test'],['id'=>99,'role'=>'student','email'=>'s2@example.test']] as $foreign) {
    [$scope,$params]=deadline_scope($pdo,$foreign);$q=$pdo->prepare('SELECT COUNT(*) '.$scope);$q->execute($params);
    reset_migration_expect(0,(int)$q->fetchColumn(),'Shared group does not disclose foreign adviser deadline');
}
[$scope,$params]=deadline_scope($pdo,['role'=>'student','email'=>'s1@example.test']);$q=$pdo->prepare('SELECT COUNT(*) '.$scope);$q->execute($params);reset_migration_expect(1,(int)$q->fetchColumn(),'Original assigned student sees deadline');
$pdo->exec('UPDATE students SET adviser_id=2 WHERE id=1');
[$scope,$params]=deadline_scope($pdo,['role'=>'student','email'=>'s1@example.test']);$q=$pdo->prepare('SELECT COUNT(*) '.$scope);$q->execute($params);reset_migration_expect(0,(int)$q->fetchColumn(),'Reassignment removes active creator scope');
parallel_fixtures([['mode'=>'deadline','action'=>'cancel','actor'=>$admin,'data'=>['id'=>$id]]]);
reset_migration_expect([1],array_map('intval',$pdo->query("SELECT recipient_id FROM notifications WHERE subject='Official deadline cancelled'")->fetchAll(PDO::FETCH_COLUMN)),'Admin cancellation reaches original audience despite reassignment');
parallel_fixtures([['mode'=>'deadline','action'=>'cancel','actor'=>$adviser,'data'=>['id'=>$id]]]);
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Repeated cancellation does not repeat notifications');
$pdo->exec('UPDATE students SET adviser_id=1 WHERE id=1');
parallel_fixtures([['mode'=>'deadline','actor'=>$adviser,'data'=>array_replace($data,['date'=>'2026-10-21'])]]);
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM calendar_deadlines')->fetchColumn(),'Same title different date remains allowed');
$pdo->exec("UPDATE calendar_deadlines SET created_at='2000-01-01 00:00:00'");
parallel_fixtures([['mode'=>'deadline','actor'=>$adviser,'data'=>array_replace($data,['date'=>'2026-10-21'])]]);
reset_migration_expect(3,(int)$pdo->query('SELECT COUNT(*) FROM calendar_deadlines')->fetchColumn(),'Legitimate later same deadline allowed');

$academic=['academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027'];
$cases=[];
for($i=0;$i<24;$i++) $cases[]=['mode'=>'group','actor'=>$i%2?$adviser:$admin,'adviser'=>$i%2?1:2,'academic'=>array_replace($academic,['yearLevel'=>$i<16?'2nd Year':'3rd Year']),'suffix'=>'GTEST'.$i];
$results=parallel_fixtures($cases);$groups=array_map(fn($r)=>json_decode($r,true)['group'],$results);
reset_migration_expect(24,count(array_unique($groups)),'Twenty-four allocations across advisers and cohorts stay unique');

$pdo->exec('CREATE TABLE delivery_fixture (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190))');
$pdo->exec("INSERT INTO notifications (recipient_type,recipient_id,recipient_email,recipient_name,subject,message,type,status,scheduled_at,sending_started_at)
    VALUES ('student',1,'stale@example.test','Synthetic','Stale','Synthetic','Reminder','Sending',NOW(),DATE_SUB(NOW(), INTERVAL 16 MINUTE)),
    ('student',1,'fresh@example.test','Synthetic','Fresh','Synthetic','Reminder','Sending',NOW(),NOW()),
    ('student',1,'sent@example.test','Synthetic','Sent','Synthetic','Reminder','Sent',NOW(),NULL),
    ('student',1,'legacy@example.test','Synthetic','Legacy claim','Synthetic','Reminder','Sending',NOW(),NULL)");
parallel_fixtures(array_fill(0,4,['mode'=>'worker','actor'=>$admin]));
reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM delivery_fixture WHERE email='stale@example.test'")->fetchColumn(),'Stale claim recovered exactly once across racing workers');
foreach(['fresh','sent','legacy'] as $kind) reset_migration_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM delivery_fixture WHERE email='$kind@example.test'")->fetchColumn(),'Fresh/Sent/undated claims never reclaimed: '.$kind);
$deliveryCount=(int)$pdo->query('SELECT COUNT(*) FROM delivery_fixture')->fetchColumn();parallel_fixtures([['mode'=>'worker','actor'=>$admin]]);
reset_migration_expect($deliveryCount,(int)$pdo->query('SELECT COUNT(*) FROM delivery_fixture')->fetchColumn(),'Successful notifications never resent');
parallel_fixtures([['mode'=>'broadcast','actor'=>$admin,'data'=>['audience'=>'All Students','message'=>'Synthetic cohort notice']]]);
reset_migration_expect($deliveryCount,(int)$pdo->query('SELECT COUNT(*) FROM delivery_fixture')->fetchColumn(),'Twenty-six recipient broadcast queues without provider calls');
$due=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE status='Scheduled'")->fetchColumn();
parallel_fixtures([['mode'=>'worker','actor'=>$admin]]);
reset_migration_expect(min(20,$due),(int)$pdo->query('SELECT COUNT(*) FROM delivery_fixture')->fetchColumn()-$deliveryCount,'Worker processes at most twenty recipients');

$pdo->exec('UPDATE students SET adviser_id=1 WHERE id=1');
$counts=[];foreach(['students','ierb_history','documents','reports','activity_logs'] as $table)$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
archive_student($pdo,$admin,1);
foreach($counts as $table=>$count) reset_migration_expect($count+($table==='activity_logs'?1:0),(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'Archiving retains records: '.$table);
reset_migration_expect('Inactive',$pdo->query('SELECT status FROM users WHERE id=4')->fetchColumn(),'Archived student login inactive');
reset_migration_expect(true,$pdo->query('SELECT archived_at FROM students WHERE id=1')->fetchColumn()!==null,'Archive timestamp recorded');
archive_student($pdo,$admin,1);
reset_migration_expect($counts['activity_logs']+1,(int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),'Repeat archive is idempotent without audit noise');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=1 AND archived_at IS NULL')->fetchColumn(),'Archived student excluded from active workflows');
echo 'PASS: '.$checks." isolated v8 migration, preservation, scope, lease, concurrency and bulk checks.\n";
