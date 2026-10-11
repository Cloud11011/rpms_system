<?php
$extendedStart=$GLOBALS['checks'];
function retention_test_bulk(string $type,string $action): array {
    $selection=retention_selection(db(),retention_test_actor(),['accountType'=>$type,'filters'=>['lifecycle'=>$action==='archive'?'active':'archived']]);
    $preview=retention_bulk_preview(db(),retention_test_actor(),['selectionToken'=>$selection['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>$action]);
    return [$preview,['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$GLOBALS['fixturePassword'],'confirmation'=>$preview['phrase'],'confirmed'=>true]];
}
// Real report writer proves ownership at final persistence, including a one-member aggregate.
retention_test_reset();$pdo->exec('UPDATE students SET archived_at=NULL WHERE id=100');
$snapshot=$pdo->query('SELECT * FROM students WHERE id=100')->fetchAll();
foreach(['Student Report','Progress'] as $index=>$type) {
    report_persist_snapshot($pdo,$snapshot,[':id'=>'writer-'.$index,':title'=>'Synthetic report',':type'=>$type,':file'=>'writer-'.$index.'.pdf',':by'=>'Admin One',':uid'=>1]);
    reset_migration_expect($index===0?100:null,$pdo->query("SELECT owner_student_id FROM reports WHERE id='writer-$index'")->fetchColumn(),'Actual report provenance '.$type);
}
// Inconsistent AI ownership refuses instead of deleting another account's output.
retention_test_reset();$pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name,is_current) VALUES ('ai-own',100,'Synthetic','ai-own.txt',0)");
$pdo->exec("INSERT INTO ai_outputs (id,type,output,owner_student_id,owner_document_id) VALUES ('mixed-owner','document','Synthetic',999,'ai-own')");
retention_test_failure(retention_test_input());
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM ai_outputs')->fetchColumn(),'Mixed AI retained for review');
// Same eligible count with different individual membership/state must still reject.
retention_bulk_fixture();[$preview,$execute]=retention_test_bulk('student','permanent_delete');
$pdo->exec('UPDATE students SET retention_hold=CASE id WHEN 301 THEN 0 ELSE 1 END WHERE id IN (301,304)');
try {retention_bulk_execute($pdo,retention_test_actor(),$execute);throw new RuntimeException('Same-count stale preview accepted');}
catch(AccountLifecycleConflict $e) {reset_migration_expect(29,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Same-count membership change refuses before mutation');}
// Crash between DB COMMIT and session persistence recovers the durable audit marker.
retention_test_reset();[$preview,$execute]=retention_test_bulk('student','permanent_delete');$saved=$_SESSION;
$completed=retention_bulk_execute($pdo,retention_test_actor(),$execute);$_SESSION=$saved;
$retried=retention_bulk_execute($pdo,retention_test_actor(),$execute);
reset_migration_expect(1,$retried['completed'],'Lost checkpoint recovers committed account');
reset_migration_expect(true,$retried['done'],'Lost checkpoint completes');
reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM account_purge_jobs')->fetchColumn(),'Lost checkpoint does not repeat purge');
reset_migration_expect(true,str_contains($retried['results'][0]['reason'],'durable audit'),'Recovery reports actual committed evidence');
// A complete DB status alone cannot hide an unfinished/corrupt leftover disk journal.
$completeJob=$pdo->query('SELECT id FROM account_purge_jobs')->fetchColumn();$leftover=purge_journal_root().DIRECTORY_SEPARATOR.$completeJob;
mkdir($leftover);file_put_contents($leftover.DIRECTORY_SEPARATOR.'manifest.json','{}');$_SESSION=$saved;
$pending=retention_bulk_execute($pdo,retention_test_actor(),$execute);
reset_migration_expect(0,$pending['completed'],'Leftover journal is not reported completed after lost checkpoint');
reset_migration_expect(1,$pending['recoveryRequired'],'Leftover journal retains explicit recovery outcome');
unlink($leftover.DIRECTORY_SEPARATOR.'manifest.json');rmdir($leftover);
// One transaction failure does not poison the next account, and a fresh preview can retry it.
class RetentionOnceDeniedPDO extends PDO {
    private bool $denyOnce=true;
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if($this->denyOnce && str_starts_with($query,'DELETE FROM students')) {$this->denyOnce=false;throw new RuntimeException('Synthetic first-account failure');}
        return parent::prepare($query,$options);
    }
}
retention_test_reset();$pdo->exec("INSERT INTO students (id,student_id,full_name,email,archived_at) VALUES (101,'S101','Student Two','s101@example.invalid',DATE_SUB(NOW(),INTERVAL 7 MONTH))");
[$preview,$execute]=retention_test_bulk('student','permanent_delete');
$once=new RetentionOnceDeniedPDO($faultDsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$once->exec("SET time_zone='+08:00'");
$partial=retention_bulk_execute($once,retention_test_actor(),$execute);
reset_migration_expect(1,$partial['completed'],'Independent second account committed');reset_migration_expect(1,$partial['skipped'],'First failed transaction skipped accurately');
reset_migration_expect([100],array_map('intval',$pdo->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN)),'Only failed account remains');
[$preview,$execute]=retention_test_bulk('student','permanent_delete');reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Fresh confirmed preview retries actual failed account');
// Bulk Adviser archive impact, stale impact and both roles' remaining bulk actions.
retention_test_reset();$pdo->exec('UPDATE advisers SET status="Active",archived_at=NULL WHERE id=200');$pdo->exec('UPDATE users SET status="Active" WHERE id=200');$pdo->exec('UPDATE students SET adviser_id=200 WHERE id=100');
[$preview,$execute]=retention_test_bulk('adviser','archive');reset_migration_expect(1,$preview['unassignedStudents'],'Server bulk Adviser assignment impact');
$pdo->exec("INSERT INTO students (id,student_id,full_name,email,adviser_id) VALUES (101,'S101','Student Two','s101@example.invalid',200)");
try {retention_bulk_execute($pdo,retention_test_actor(),$execute);throw new RuntimeException('Stale impact accepted');}
catch(AccountLifecycleConflict $e) {reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE adviser_id=200')->fetchColumn(),'Stale assignment impact preserves both students');}
[$preview,$execute]=retention_test_bulk('adviser','archive');reset_migration_expect(2,$preview['unassignedStudents'],'Fresh assignment impact recomputed');
reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Bulk Adviser archive');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE adviser_id IS NOT NULL')->fetchColumn(),'Every assigned Student now Unassigned');
foreach(['hold','remove_hold','restore'] as $action) {[$preview,$execute]=retention_test_bulk('adviser',$action);reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Bulk Adviser '.$action);}
lifecycle_execute($pdo,retention_test_actor(),['accountType'=>'adviser','targetId'=>200,'action'=>'archive','expectedAssignedStudents'=>0]);$pdo->exec('UPDATE advisers SET archived_at=DATE_SUB(NOW(),INTERVAL 7 MONTH) WHERE id=200');
[$preview,$execute]=retention_test_bulk('adviser','retention_cleanup');reset_migration_expect(1,retention_bulk_execute($pdo,retention_test_actor(),$execute)['completed'],'Bulk Adviser cleanup');
reset_migration_expect(2,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Adviser cleanup preserves Student accounts');
// Bound and inspect a genuinely large filtered population without running thousands of purges.
retention_test_reset();$insert=$pdo->prepare('INSERT INTO students (id,student_id,full_name,email,archived_at) VALUES (?,?,?,?,DATE_SUB(NOW(),INTERVAL 7 MONTH))');
$pdo->beginTransaction();for($i=1000;$i<11000;$i++)$insert->execute([$i,'L'.$i,'Synthetic Large '.$i,'l'.$i.'@example.invalid']);$pdo->commit();
try {retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['lifecycle'=>'archived']]);throw new RuntimeException('Oversize selection accepted');}
catch(AccountLifecycleValidation $e) {reset_migration_expect(true,str_contains($e->getMessage(),'10,000'),'10,001 matching accounts bounded before fetch');}
$selection=retention_selection($pdo,retention_test_actor(),['accountType'=>'student','filters'=>['q'=>'Synthetic Large','lifecycle'=>'archived']]);reset_migration_expect(10000,$selection['total'],'Exactly 10,000 matching accounts resolved');
$preview=retention_bulk_preview($pdo,retention_test_actor(),['selectionToken'=>$selection['selectionToken'],'selectionMode'=>'all_matching','bulkAction'=>'retention_cleanup']);
reset_migration_expect(10000,$preview['eligible'],'Large server preview');reset_migration_expect('PURGE 10000 ACCOUNTS',$preview['phrase'],'Large server count confirmation');
$execute=['previewToken'=>$preview['previewToken'],'cursor'=>0,'currentPassword'=>$fixturePassword,'confirmation'=>$preview['phrase'],'confirmed'=>true];
$chunk=retention_bulk_execute($pdo,retention_test_actor(),$execute);reset_migration_expect(true,$chunk['nextCursor']>0 && $chunk['nextCursor']<=25,'Large work bounded to at most 25 targets');
reset_migration_expect(false,$chunk['done'],'Large population explicitly resumable');
reset_migration_expect(10001-$chunk['completed'],(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Large actual committed count');
// Student/Adviser retention never reaches Admin self/last-active protections.
retention_test_reset();$adminInput=['accountType'=>'admin','targetId'=>2,'action'=>'archive','currentPassword'=>$fixturePassword,'confirmation'=>'ARCHIVE','confirmed'=>true];
try {lifecycle_execute($pdo,retention_test_actor(),$adminInput);throw new RuntimeException('Other Admin archive accepted');}
catch(AccountLifecycleForbidden $e) {reset_migration_expect(true,true,'Other Admin remains protected');}
$adminInput['targetId']=1;$pdo->exec('UPDATE users SET status="Inactive" WHERE id=2');
try {lifecycle_execute($pdo,retention_test_actor(),$adminInput);throw new RuntimeException('Last Admin archive accepted');}
catch(AccountLifecycleConflict $e) {reset_migration_expect('Active',$pdo->query('SELECT status FROM users WHERE id=1')->fetchColumn(),'Last active Admin remains');}
try {retention_selection($pdo,retention_test_actor(),['accountType'=>'admin','filters'=>[]]);throw new RuntimeException('Admin bulk accepted');}
catch(AccountLifecycleValidation $e) {reset_migration_expect(true,true,'Admin bulk retention unavailable');}
echo 'PASS: report provenance, partial/crash retries, Adviser bulk, large population and Admin checks '.($GLOBALS['checks']-$extendedStart).'; cumulative '.$GLOBALS['checks'].".\n";
