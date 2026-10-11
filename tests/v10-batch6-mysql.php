<?php
/** Runs only inside account-lifecycle-runner's verified private server. No application config. */
if (PHP_SAPI!=='cli' || !isset($pdo,$datadir,$root) || !function_exists('reset_migration_expect')) exit(1);
require_once __DIR__.'/../includes/account_lifecycle.php';
require_once __DIR__.'/../includes/legal_policy.php';
require_once __DIR__.'/../security.php';
function db(): PDO { return $GLOBALS['pdo']; }
function b6_expect(mixed $expected,mixed $actual,string $message): void { reset_migration_expect($expected,$actual,$message); }
function b6_refuse(callable $call, ?int $status=null): void {
    $error=null; try { $call(); } catch (Throwable $e) { $error=$e; }
    b6_expect(true,$error!==null,'Expected refusal');
    if ($status!==null) b6_expect($status,$error instanceof LegalPolicyError?$error->status:0,'Correct legal refusal status');
    b6_expect(false,db()->inTransaction(),'Refusal ends transaction');
}
function b6_actor(int $id): array { return db()->query('SELECT * FROM users WHERE id='.$id)->fetch(); }
function b6_accept_all(int $id): void { legal_accept(db(),b6_actor($id),['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current(db()))]); }
function b6_manage(int $id,array $data): int { return legal_manage(db(),b6_actor($id),$data); }
function b6_manual(PDO $pdo,string $sql): void {
    $sql=preg_replace('/^--.*$/m','',$sql); $sql=str_replace(['DELIMITER $$','DELIMITER ;'],'',$sql);
    foreach (explode('$$',$sql) as $statement) if (trim($statement)!=='') $pdo->exec(trim($statement));
}
function b6_snapshot(PDO $pdo): array {
    $result=[];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if ($table!=='schema_meta') $result[$table]=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll();
    return $result;
}
$GLOBALS['allowV7']=$GLOBALS['allowV8']=$GLOBALS['allowV9']=true;
$legacy=[];
foreach (['b6_php','b6_manual','b6_retry','b6_failure'] as $schema) {
    $pdo->exec('CREATE DATABASE `'.$schema.'`'); $pdo->exec('USE `'.$schema.'`');
    $GLOBALS['allowV10']=false; $GLOBALS['allowV9']=false; PrismResetMigrationSQL\migrate($pdo);
    // Portable MariaDB 12.1 changed generated FK names. Build the canonical v9 prerequisite
    // explicitly; this is test fixture normalization, not a v10 migration accommodation.
    $v9=json_decode(file_get_contents(__DIR__.'/../tools/schema-v9-contract.json'),true);
    foreach ($v9['foreign_keys'] as $fk) {
        if ($fk['TABLE_NAME']==='account_invitations') continue;
        $q=$pdo->prepare('SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');$q->execute([$fk['TABLE_NAME'],$fk['COLUMN_NAME']]);$old=$q->fetchColumn();
        if ($old!==$fk['CONSTRAINT_NAME']) $pdo->exec('ALTER TABLE `'.$fk['TABLE_NAME'].'` DROP FOREIGN KEY `'.$old.'`, ADD CONSTRAINT `'.$fk['CONSTRAINT_NAME'].'` FOREIGN KEY (`'.$fk['COLUMN_NAME'].'`) REFERENCES `'.$fk['REFERENCED_TABLE_NAME'].'` (`'.$fk['REFERENCED_COLUMN_NAME'].'`) ON DELETE '.$fk['DELETE_RULE'].' ON UPDATE '.$fk['UPDATE_RULE']);
    }
    $expectedIndexes=[]; foreach ($v9['indexes'] as $index) $expectedIndexes[$index['TABLE_NAME'].':'.$index['INDEX_NAME']][]=$index;
    $actualIndexes=[]; foreach (lifecycle_schema_inventory($pdo)['indexes'] as $index) $actualIndexes[$index['TABLE_NAME'].':'.$index['INDEX_NAME']][]=$index;
    foreach ($actualIndexes as $key=>$entries) {
        if (isset($expectedIndexes[$key]))continue;
        foreach ($expectedIndexes as $expectedEntries) {
            $shape=static function(array $rows):array {foreach($rows as &$row)unset($row['INDEX_NAME']);unset($row);return $rows;};
            if($shape($entries)!==$shape($expectedEntries))continue;
            $pdo->exec('ALTER TABLE `'.$entries[0]['TABLE_NAME'].'` RENAME INDEX `'.$entries[0]['INDEX_NAME'].'` TO `'.$expectedEntries[0]['INDEX_NAME'].'`');break;
        }
    }
    $GLOBALS['allowV9']=true; PrismResetMigrationSQL\migrate($pdo);
    b6_expect('9',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Canonical v9 prerequisite');
    $pdo->exec("INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id) VALUES (1,'OLD','fixture','student','Preserved User','old@example.invalid','OLD')");
    $pdo->exec("INSERT INTO students(id,student_id,full_name,email,user_id,profile_completed_at) VALUES (1,'OLD','Preserved User','old@example.invalid',1,'2026-01-01')");
    $pdo->exec("INSERT INTO documents(id,student_id,original_name,stored_name,mime,document_type,stage,review_status,reviewed_by,reviewed_at,rpms_submitted_at,rpms_submitted_by) VALUES ('legacy',1,'old.doc','old.doc','application/msword','Study Protocol','Stage 1','Approved','Preserved Adviser','2026-01-01','2026-01-02','Preserved Submitter')");
    $pdo->exec("INSERT INTO notifications(recipient_type,recipient_id,recipient_email,message,type,read_at) VALUES ('student',1,'old@example.invalid','Preserved old message','Follow-up','2026-01-03')");
    $legacy[$schema]=b6_snapshot($pdo);
}
putenv('PRISM_ALLOW_SCHEMA_V10_MIGRATION=1'); $GLOBALS['allowV10']=true;
$pdo->exec('USE b6_php'); putenv('PRISM_SCHEMA_V10_EXPECT_DB=b6_php');
// Guarded refusals preserve the v9 checkpoint and all data.
putenv('PRISM_SCHEMA_V10_EXPECT_DB=wrong'); b6_refuse(fn()=>PrismResetMigrationSQL\migrate($pdo));
b6_expect('9',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Exact DB guard');
putenv('PRISM_SCHEMA_V10_EXPECT_DB=b6_php');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0'); b6_refuse(fn()=>PrismResetMigrationSQL\migrate($pdo)); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('SET timestamp=1791676800');
PrismResetMigrationSQL\migrate($pdo);
schema_v10_check($pdo,schema_v10_plan()['verify']); schema_v10_check($pdo,schema_v10_plan()['invariants']);
$phpInventory=lifecycle_schema_inventory($pdo); $phpRows=b6_snapshot($pdo);
b6_expect('10',$phpInventory['schema_version'],'Final SCHEMA_VERSION=10');
b6_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances')->fetchColumn(),'No fabricated acceptance');
foreach ($legacy['b6_php'] as $table=>$rows) {
    $after=$phpRows[$table];
    foreach ($rows as $i=>$row) foreach ($row as $col=>$value) b6_expect($value,$after[$i][$col],'Preserved legacy '.$table.'.'.$col);
}
PrismResetMigrationSQL\migrate($pdo); b6_expect($phpInventory,lifecycle_schema_inventory($pdo),'PHP wrapper retry');
$pdo->query("SELECT GET_LOCK('prism_migrate',0)"); migrate_schema_v10($pdo); $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
b6_expect($phpRows,b6_snapshot($pdo),'Direct guarded PHP retry preserves rows');
$manual=file_get_contents(__DIR__.'/../tools/schema-v10-manual.sql');
$pdo->exec('USE b6_manual');
b6_refuse(fn()=>b6_manual($pdo,$manual)); b6_expect('9',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Manual refuses default settings');
$enabled=str_replace(["'REPLACE_WITH_EXACT_DATABASE_NAME'",'SET @PRISM_V10_BACKUP_AND_STAGING_VERIFIED = 0'],["'b6_manual'",'SET @PRISM_V10_BACKUP_AND_STAGING_VERIFIED = 1'],$manual);
$pdo->exec('SET timestamp=1791676800'); b6_manual($pdo,$enabled);
b6_expect($phpInventory,lifecycle_schema_inventory($pdo),'Exact native PHP/manual schema parity');
foreach (['legal_policies','legal_policy_versions','legal_policy_approvals','user_policy_acceptances'] as $table) b6_expect($phpRows[$table],b6_snapshot($pdo)[$table],'Exact seed parity '.$table);
$manualRows=b6_snapshot($pdo); b6_manual($pdo,$enabled); b6_expect($manualRows,b6_snapshot($pdo),'Manual retry preserves all rows');
// Malformed partial table must fail closed and remain at v9; repairing test fixture permits retry.
$pdo->exec('USE b6_retry'); putenv('PRISM_SCHEMA_V10_EXPECT_DB=b6_retry');
$pdo->exec('CREATE TABLE legal_policies(id VARCHAR(30) PRIMARY KEY) ENGINE=InnoDB');
b6_refuse(fn()=>PrismResetMigrationSQL\migrate($pdo)); b6_expect('9',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Malformed partial DDL not stamped');
$pdo->exec('DROP TABLE legal_policies'); PrismResetMigrationSQL\migrate($pdo); b6_expect($phpInventory,lifecycle_schema_inventory($pdo),'Malformed-state repaired retry');
// Failure after additive DDL/before seed leaves a retryable v9, never fabricated acceptances.
class Batch6FailPDO extends PDO {
    public bool $fail=true;
    public function exec(string $sql): int|false {
        if ($this->fail && str_starts_with($sql,'INSERT INTO legal_policy_versions')) throw new RuntimeException('Synthetic seed failure');
        return parent::exec($sql);
    }
}
$failed=new Batch6FailPDO('mysql:host=127.0.0.1;port='.$port.';dbname=b6_failure;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
putenv('PRISM_SCHEMA_V10_EXPECT_DB=b6_failure'); b6_refuse(fn()=>PrismResetMigrationSQL\migrate($failed));
b6_expect('9',$failed->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Injected failure never stamps');
b6_expect(0,(int)$failed->query('SELECT COUNT(*) FROM legal_policy_versions')->fetchColumn(),'Seed rollback complete');
$failed->fail=false; PrismResetMigrationSQL\migrate($failed); b6_expect($phpInventory,lifecycle_schema_inventory($failed),'Injected failure retry parity');
$failed=null;
$pdo->exec('USE b6_php'); putenv('PRISM_SCHEMA_V10_EXPECT_DB=b6_php'); $pdo->exec('SET timestamp=0'); $pdo->exec("SET time_zone='+08:00'");
$out=__DIR__.'/v10-batch6-results'; if (!is_dir($out)) mkdir($out);
file_put_contents($out.'/schema-inventory.json',json_encode($phpInventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
if (getenv('PRISM_BATCH6_WRITE_MANIFEST')==='1') file_put_contents(__DIR__.'/../includes/account_lifecycle_schema.json',json_encode($phpInventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'PASS: native PHP/manual parity, guarded retries, failure rollback, exact corrected seeds and legacy preservation; '.$GLOBALS['checks']." assertions.\n";

// Actual legal service, all roles, immutable history and re-acknowledgment.
$hash=password_hash('Batch6 fixture passphrase',PASSWORD_DEFAULT);
foreach ([2=>'admin',3=>'admin',4=>'admin',5=>'adviser',6=>'student'] as $id=>$role) $pdo->prepare('INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id,status) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,'B6-'.$id,$hash,$role,'Batch6 '.$id,'b6-'.$id.'@example.invalid','B6-'.$id,$id===4?'Inactive':'Active']);
$pdo->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([$hash]);
foreach ([1,2,3,4,5,6] as $id) {
    b6_expect(['privacy','terms'],array_keys(legal_outstanding($pdo,$id)),'All roles/migrated users require current policies');
    b6_refuse(fn()=>legal_accept($pdo,b6_actor($id),['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo)),'user_id'=>2]),422);
}
foreach ([[],['privacy'=>true],['terms'=>true]] as $data) b6_refuse(fn()=>legal_accept($pdo,b6_actor(6),$data+['snapshot'=>legal_snapshot(legal_current($pdo))]),422);
b6_refuse(fn()=>legal_accept($pdo,b6_actor(6),['privacy'=>true,'terms'=>true,'snapshot'=>'old']),409);
foreach ([1,2,3,5,6] as $id) b6_accept_all($id);
b6_accept_all(6); b6_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id=6')->fetchColumn(),'Acceptance idempotent and exact');
foreach ([5,6] as $id) b6_refuse(fn()=>b6_manage($id,['action'=>'create','policy'=>'privacy']),403);
$v=b6_manage(2,['action'=>'create','policy'=>'privacy']);
b6_refuse(fn()=>b6_manage(3,['action'=>'create','policy'=>'privacy']),409);
b6_refuse(fn()=>b6_manage(2,['action'=>'submit','policy'=>'privacy','versionId'=>$v]),422);
$attack="# Privacy fixture\n\n<script>alert(1)</script>\n<img src=x onerror=alert(1)>\n<a href=\"javascript:alert(1)\">x</a>\n<iframe src=x></iframe>\n<style>body{display:none}</style>";
b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$v,'title'=>'Privacy Policy','content'=>$attack,'summary'=>'Presentation fixture']);
$rendered=legal_render($attack);
foreach (['<script>','<img ','<a href=','<iframe','<style>'] as $tag) b6_expect(false,str_contains($rendered,$tag),'Source markup escaped '.$tag);
b6_manage(2,['action'=>'submit','policy'=>'privacy','versionId'=>$v]);
b6_refuse(fn()=>b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$v,'title'=>'Attack','content'=>'changed','summary'=>'changed']),409);
$approve=['action'=>'approve','policy'=>'privacy','versionId'=>$v,'password'=>'Batch6 fixture passphrase','reviewAcknowledged'=>true,'soleAcknowledged'=>true];
b6_refuse(fn()=>b6_manage(2,$approve),403);
b6_refuse(fn()=>b6_manage(3,array_replace($approve,['password'=>'wrong'])),422);
b6_refuse(fn()=>b6_manage(3,array_replace($approve,['reviewAcknowledged'=>false])),422);
$old=legal_current($pdo)['privacy']; $staleSnapshot=legal_snapshot(legal_current($pdo));
b6_manage(3,$approve);
b6_expect($v,(int)legal_current($pdo)['privacy']['id'],'Different Admin publishes atomically');
b6_expect(['privacy'],array_keys(legal_outstanding($pdo,6)),'Only changed policy outstanding');
b6_expect($old['content'],$pdo->query('SELECT content FROM legal_policy_versions WHERE id='.(int)$old['id'])->fetchColumn(),'Old published wording immutable');
b6_refuse(fn()=>legal_accept($pdo,b6_actor(6),['privacy'=>true,'terms'=>true,'snapshot'=>$staleSnapshot]),409);
b6_refuse(fn()=>b6_manage(3,$approve),403); // publisher must re-acknowledge before further governance
b6_accept_all(3); b6_refuse(fn()=>b6_manage(3,$approve),409);
b6_accept_all(2); b6_accept_all(6);
b6_refuse(fn()=>b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$v,'content'=>'tampered','title'=>'Tampered']),409);
// Rejection retains immutable candidate; current pointer/acceptance unchanged.
$reject=b6_manage(2,['action'=>'create','policy'=>'terms']);
b6_manage(2,['action'=>'save','policy'=>'terms','versionId'=>$reject,'title'=>'Terms of Service','content'=>"# Rejected terms\nText",'summary'=>'Review fixture']);
b6_manage(2,['action'=>'submit','policy'=>'terms','versionId'=>$reject]);
b6_refuse(fn()=>b6_manage(2,['action'=>'reject','policy'=>'terms','versionId'=>$reject,'reason'=>'self']),403);
b6_refuse(fn()=>b6_manage(3,['action'=>'reject','policy'=>'terms','versionId'=>$reject,'reason'=>'']),422);
$before=legal_current($pdo); b6_manage(3,['action'=>'reject','policy'=>'terms','versionId'=>$reject,'reason'=>'Needs revision']);
b6_expect($before,legal_current($pdo),'Rejected candidate never changes current');
b6_expect([],legal_outstanding($pdo,6),'Rejection does not invalidate acceptance');
b6_refuse(fn()=>b6_manage(2,['action'=>'save','policy'=>'terms','versionId'=>$reject,'title'=>'Mutate','content'=>'Changed']),409);
// Three Admins: only ONE independent approval is sufficient.
$pdo->exec("UPDATE users SET status='Active' WHERE id=4"); b6_accept_all(4);
$next=b6_manage(2,['action'=>'create','policy'=>'terms']);
b6_expect(3,(int)$pdo->query('SELECT version_number FROM legal_policy_versions WHERE id='.$next)->fetchColumn(),'Rejected version consumes number monotonically');
b6_manage(2,['action'=>'save','policy'=>'terms','versionId'=>$next,'title'=>'Terms of Service','content'=>"# Terms fixture\nCurrent terms",'summary'=>'Three Admin fixture']);
b6_manage(2,['action'=>'submit','policy'=>'terms','versionId'=>$next]);
$termsApprove=array_replace($approve,['policy'=>'terms','versionId'=>$next]);
b6_refuse(fn()=>b6_manage(2,$termsApprove),403); b6_manage(4,$termsApprove);
b6_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM legal_policy_approvals WHERE version_id='.$next)->fetchColumn(),'One-other Admin approval suffices among three');
foreach ([2,3,4,6] as $id) b6_accept_all($id);
// Admin-count changes after draft creation must be re-read at publication.
$pdo->exec("UPDATE users SET status='Inactive' WHERE id IN (3,4)");
$sole=b6_manage(2,['action'=>'create','policy'=>'terms']);
b6_manage(2,['action'=>'save','policy'=>'terms','versionId'=>$sole,'title'=>'Terms of Service','content'=>"# Sole fixture\nText",'summary'=>'Sole Admin fixture']); b6_manage(2,['action'=>'submit','policy'=>'terms','versionId'=>$sole]);
$soleApprove=array_replace($termsApprove,['versionId'=>$sole]);
$pdo->exec("UPDATE users SET status='Active' WHERE id=3"); b6_refuse(fn()=>b6_manage(2,$soleApprove),403);
$pdo->exec("UPDATE users SET status='Inactive' WHERE id=3");
b6_refuse(fn()=>b6_manage(2,array_replace($soleApprove,['soleAcknowledged'=>false])),422);
b6_manage(2,$soleApprove);
b6_expect('sole_admin',$pdo->query('SELECT approval_mode FROM legal_policy_approvals WHERE version_id='.$sole)->fetchColumn(),'Sole-admin durable marker');
b6_expect(['terms'],array_keys(legal_outstanding($pdo,6)),'Partial Terms re-agreement');
$pdo->exec("UPDATE users SET status='Active' WHERE id IN (3,4)"); foreach ([2,3,4,6] as $id) b6_accept_all($id);
$privacyCandidate=$v;
require __DIR__.'/v10-batch6-http.php';
require __DIR__.'/v10-batch6-concurrency.php';
// Account restore and creator/reviewer purge: no published text or institutional history loss.
$pdo->exec("UPDATE users SET status='Inactive' WHERE id=6"); b6_refuse(fn()=>b6_accept_all(6),403); $pdo->exec("UPDATE users SET status='Active' WHERE id=6"); b6_expect(['privacy'],array_keys(legal_outstanding($pdo,6)),'Restore requires policy published during absence'); b6_accept_all(6);
$preserved=legal_current($pdo);
$pdo->exec('DELETE FROM users WHERE id=4'); b6_expect($preserved['terms']['content'],legal_current($pdo)['terms']['content'],'Reviewer purge preserves institutional policy');
b6_expect(null,$pdo->query('SELECT reviewer_user_id FROM legal_policy_approvals WHERE version_id='.$next)->fetchColumn(),'Reviewer SET NULL');
$pdo->exec('DELETE FROM users WHERE id=6'); b6_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id=6')->fetchColumn(),'Acceptance CASCADE on subject purge');
// Notification campaign/recipient semantics and Staff Code constraints prepared without future UI.
$pdo->exec("INSERT INTO notification_campaigns(sender_user_id,kind,support_category,subject,message,status) VALUES (2,'support','Technical Issue','Support fixture','Support body','Sent')"); $campaign=(int)$pdo->lastInsertId();
foreach ([2,3] as $id) $pdo->prepare("INSERT INTO notifications(recipient_type,recipient_user_id,campaign_id,recipient_email,message,type) VALUES ('admin',?,?,?,'Independent copy','Support')")->execute([$id,$campaign,'b6-'.$id.'@example.invalid']);
$copies=$pdo->query('SELECT id FROM notifications WHERE campaign_id='.$campaign.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE notifications SET deleted_at=NOW(),subject=NULL,message='',delivery_info=NULL WHERE id=".(int)$copies[0]);
b6_expect('Independent copy',$pdo->query('SELECT message FROM notifications WHERE id='.(int)$copies[1])->fetchColumn(),'Independent recipient deletion');
$pdo->exec('UPDATE notification_campaigns SET deleted_at=NOW(),subject=NULL,message=NULL WHERE id='.$campaign);
b6_expect('Independent copy',$pdo->query('SELECT message FROM notifications WHERE id='.(int)$copies[1])->fetchColumn(),'Sender deletion preserves recipient content');
$pdo->exec("INSERT INTO staff_registration_code(slot,generation_id,verifier_hash,creator_user_id) VALUES (1,'fixture-generation','synthetic verifier',2)");
b6_refuse(fn()=>$pdo->exec("INSERT INTO staff_registration_code(slot,generation_id) VALUES (2,'second')"));
b6_expect(null,$pdo->query('SELECT acknowledged_at FROM staff_registration_code')->fetchColumn(),'Pending code has no validity clock');
$pdo->exec("UPDATE staff_registration_code SET state='acknowledged',acknowledged_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR)");
b6_expect(24,(int)$pdo->query('SELECT TIMESTAMPDIFF(HOUR,acknowledged_at,expires_at) FROM staff_registration_code')->fetchColumn(),'Code clock starts at acknowledgment');
$consume="UPDATE staff_registration_code SET state='consumed',consumed_at=NOW(),consumed_by_user_id=3,verifier_hash=NULL WHERE slot=1 AND state='acknowledged' AND expires_at>NOW()";
b6_expect(1,$pdo->exec($consume),'First transactional consume'); b6_expect(0,$pdo->exec($consume),'Second consume denied');
// Exercise actual purge authorization/schema verifier with all new references present.
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',true);
define('PRISM_HARD_DELETE_VERIFICATION',lifecycle_privileged_verification($pdo));
// Full v10 Student/Adviser lifecycle uses only this runner's private storage.
define('STORAGE_DIR',$root.DIRECTORY_SEPARATOR.'b6-purge-storage');
mkdir(STORAGE_DIR); mkdir(STORAGE_DIR.DIRECTORY_SEPARATOR.'documents'); mkdir(STORAGE_DIR.DIRECTORY_SEPARATOR.'reports');
foreach ([100=>'student',200=>'adviser'] as $uid=>$role) {
    $identifier='B6-PURGE-'.$uid; $email='purge-'.$uid.'@example.invalid';
    $pdo->prepare('INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id,status) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$uid,$identifier,$hash,$role,'Purge fixture '.$uid,$email,$identifier,'Active']);
    $table=$role==='student'?'students':'advisers'; $idColumn=$role==='student'?'student_id':'employee_id';
    $pdo->prepare("INSERT INTO $table(id,$idColumn,full_name,email,user_id,profile_completed_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$uid,$identifier,'Purge fixture '.$uid,$email,$uid]);
    b6_accept_all($uid);
    $pdo->prepare("INSERT INTO notifications(recipient_type,recipient_id,recipient_user_id,recipient_email,message,type,status) VALUES (?,?,?,?,'Purge copy','System','Sent')")
        ->execute([$role,$uid,$uid,$email]);
    $change=['accountType'=>$role,'targetId'=>$uid,'expectedAssignedStudents'=>0];
    lifecycle_execute($pdo,b6_actor(3),$change+['action'=>'archive']);
    b6_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id='.$uid)->fetchColumn(),'Archive preserves exact acceptance');
    lifecycle_execute($pdo,b6_actor(3),$change+['action'=>'restore']);
    b6_expect([],legal_outstanding($pdo,$uid),'Restore keeps unchanged exact-version evidence');
    b6_expect(1,(int)b6_actor($uid)['must_change_password'],'Restore retains required security gate');
    lifecycle_execute($pdo,b6_actor(3),$change+['action'=>'archive']);
    $purge=$change+['action'=>'permanent_delete','currentPassword'=>'Batch6 fixture passphrase','confirmation'=>$identifier,'confirmed'=>true];
    b6_refuse(fn()=>lifecycle_execute($pdo,b6_actor(3),$purge));
    $pdo->exec("UPDATE $table SET archived_at=DATE_SUB(NOW(),INTERVAL 7 MONTH) WHERE id=$uid");
    lifecycle_execute($pdo,b6_actor(3),$change+['action'=>'hold','reason'=>'V10 retention test']);
    b6_refuse(fn()=>lifecycle_execute($pdo,b6_actor(3),$purge));
    lifecycle_execute($pdo,b6_actor(3),$change+['action'=>'remove_hold']);
    $result=lifecycle_execute($pdo,b6_actor(3),$purge);
    b6_expect(true,$result['complete'],'V10 full recoverable purge completes');
    b6_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id='.$uid)->fetchColumn(),'Full account purge cascades acceptance');
    b6_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE recipient_user_id='.$uid)->fetchColumn(),'Full account purge cascades recipient');
    b6_expect($preserved['privacy']['content'],legal_current($pdo)['privacy']['content'],'Full account purge preserves published policy');
}
lifecycle_execute($pdo,b6_actor(2),['accountType'=>'admin','action'=>'permanent_delete','targetId'=>2,'currentPassword'=>'Batch6 fixture passphrase','confirmation'=>'DELETE','confirmed'=>true]);
b6_expect(null,$pdo->query('SELECT creator_user_id FROM staff_registration_code')->fetchColumn(),'Code creator SET NULL');
b6_expect(null,$pdo->query('SELECT creator_user_id FROM legal_policy_versions WHERE id='.$privacyCandidate)->fetchColumn(),'Policy creator SET NULL');
b6_expect(null,$pdo->query('SELECT sender_user_id FROM notification_campaigns WHERE id='.$campaign)->fetchColumn(),'Campaign sender SET NULL');
b6_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE campaign_id='.$campaign)->fetchColumn(),'Purged recipient CASCADE; other Admin copy retained');
schema_v10_check($pdo,schema_v10_plan()['verify']); schema_v10_check($pdo,schema_v10_plan()['invariants']);
file_put_contents($out.'/mysql-results.json',json_encode(['version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'assertions'=>$GLOBALS['checks'],'passed'=>true],JSON_PRETTY_PRINT)."\n");
echo 'PASS: legal governance, two/three/sole Admin approval, count changes, immutable rejection/history, XSS escaping, exact/partial acceptance and lifecycle-safe FKs; '.$GLOBALS['checks']." cumulative assertions.\n";
