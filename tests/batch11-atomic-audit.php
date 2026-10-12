<?php
/** Approved mandatory audit policy: test real existing routes on the owned disposable database. */
if (!isset($pdo, $datadir, $httpRoot) || realpath($pdo->query('SELECT @@datadir')->fetchColumn()) !== realpath($datadir)) exit(1);
function b11_atomic_snapshot(): array {
    $snapshot=[];
    foreach ($GLOBALS['pdo']->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) throw new RuntimeException('Invalid fixture table.');
        $rows=$GLOBALS['pdo']->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_map(fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($rows,SORT_STRING);
        $snapshot[$table]=$rows;
    }
    ksort($snapshot);return $snapshot;
}
function b11_edit_payload(): array {
    $s=b10_rows('SELECT * FROM students WHERE id=1003')[0];
    return ['id'=>1003,'studentId'=>$s['student_id'],'name'=>$s['full_name'],'email'=>$s['email'],
        'research'=>$s['research_title']??'','group'=>$s['research_group']??'','groupId'=>$s['research_group']??'',
        'adviserId'=>$s['adviser_id'],'stage'=>$s['stage'],'status'=>$s['status']==='Pending'?'Delayed':'Pending',
        'requirements'=>$s['requirements']??'','protocolCode'=>$s['protocol_code']??'',
        'isPrincipalInvestigator'=>!empty($s['is_principal_investigator']),
        'submissionDate'=>$s['last_submission_date']??'','reason'=>'Approved atomic audit edit fixture'];
}
$atomicStart=count($GLOBALS['b11Results']);
// The strict helper never begins, commits or silently drops ownership failures.
$ctx=['entity_type'=>'student','entity_id'=>1003,'student_id'=>1003,'override'=>true,'reason'=>'Fixture guard'];
try {audit_override_atomic($pdo,$admin,'admin_override_student_progress',$ctx);b11_check(true,false,'strict audit refuses no transaction');}
catch(LogicException $e){b11_check(false,$pdo->inTransaction(),'strict audit refuses no transaction');}
$pdo->beginTransaction();
try {
    foreach ([[$adviser,$ctx],[$admin,$ctx+['unused'=>true]]] as $index=>$case) {
        if ($index===1) $case[1]['override']=false;
        try {audit_override_atomic($pdo,$case[0],'fixture_guard',$case[1]);b11_check(true,false,'strict audit refuses invalid context');}
        catch(LogicException $e){b11_check(true,$pdo->inTransaction(),'strict audit refuses invalid context without owning transaction');}
    }
    try {audit_override_atomic($pdo,$admin,'fixture_guard',['entity_type'=>'student','entity_id'=>999999,'override'=>true]);b11_check(true,false,'strict audit refuses missing Student');}
    catch(RuntimeException $e){b11_check(true,$pdo->inTransaction(),'strict audit refuses missing Student without committing');}
} finally {$pdo->rollBack();}

$review=b10_new(1003,'Atomic audit review fixture');
$forceAtomic=b10_new(1003,'Atomic audit force fixture');
$deletePrev=b10_new(1003,'Atomic audit deletion fixture');
$deleteId=b10_new(1003,'Atomic audit deletion fixture','Stage 1',$deletePrev);
b11_check(200,b11_call('documents_api.php?action=submit_to_rpms&id='.$deleteId,['reason'=>'Existing submission to test reasoned locked deletion'])['status'],'atomic deletion fixture submitted through existing force route');
$storage=$httpRoot.'/storage/documents';if(!is_dir($storage))mkdir($storage,0700,true);
$deleteFile=$storage.'/'.b10_doc($deleteId)['stored_name'];copy(__DIR__.'/fixtures/batch6-valid.pdf',$deleteFile);$deleteHash=hash_file('sha256',$deleteFile);
$progress=b10_rows('SELECT stage,status FROM students WHERE id=1003')[0];
$cases=[
    ['Document review','documents_api.php?action=override_review&id='.$review,['status'=>'Approved','reason'=>'Approved mandatory atomic review audit'],'admin_override_document',$review],
    ['Force submission','documents_api.php?action=submit_to_rpms&id='.$forceAtomic,['reason'=>'Approved mandatory atomic submission audit'],'admin_override_submit_to_rpms',$forceAtomic],
    ['Explicit IERB','ierb_api.php?action=override',['id'=>1003,'status'=>$progress['status']==='Pending'?'Delayed':'Pending','reason'=>'Approved mandatory atomic IERB audit'],'admin_override_student_progress','1003'],
    ['IERB edit','ierb_api.php?action=save',b11_edit_payload(),'admin_override_student_progress','1003'],
    ['Student edit','students_api.php?action=save',b11_edit_payload(),'admin_override_student_progress','1003'],
    ['Locked deletion','documents_api.php?action=delete&id='.$deleteId,['reason'=>'Approved mandatory atomic deletion audit'],'document_deleted',$deleteId],
];
$pdo->exec("CREATE TRIGGER batch11_fail_override_audit BEFORE INSERT ON activity_logs FOR EACH ROW BEGIN IF NEW.is_override=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic mandatory override audit failure'; END IF; END");
try {
    foreach($cases as [$label,$endpoint,$payload]) {
        $before=b11_atomic_snapshot();$response=b11_call($endpoint,$payload);
        b11_check(500,$response['status'],$label.' mandatory audit INSERT failure returns 500');
        b11_check(false,$response['json']['ok']??null,$label.' audit failure is reported');
        b11_check($before,b11_atomic_snapshot(),$label.' audit failure rolls back every table including identity/history/delivery');
        b11_check($deleteHash,is_file($deleteFile)?hash_file('sha256',$deleteFile):null,$label.' audit failure preserves submitted file bytes');
    }
} finally {$pdo->exec('DROP TRIGGER batch11_fail_override_audit');}

// Retry after restoring the audit INSERT: one persisted override event, original recipient rules.
foreach($cases as [$label,$endpoint,$payload,$action,$entity]) {
    if(str_contains($endpoint,'action=save'))$payload=b11_edit_payload();
    if(str_contains($endpoint,'ierb_api.php?action=override')){
        $live=b10_rows('SELECT status FROM students WHERE id=1003')[0];$payload['status']=$live['status']==='Pending'?'Delayed':'Pending';
    }
    $auditBefore=b11_count('activity_logs','WHERE action=? AND entity_id=? AND is_override=1',[$action,$entity]);
    $historyBefore=b11_count('ierb_history');$notificationsBefore=b11_count('notifications');
    $response=b11_call($endpoint,$payload);b11_check(200,$response['status'],$label.' retry succeeds after audit recovery');
    b11_check(true,$response['json']['ok']??null,$label.' retry reports success');
    b11_check($auditBefore+1,b11_count('activity_logs','WHERE action=? AND entity_id=? AND is_override=1',[$action,$entity]),$label.' retry persists exactly one override audit');
    $event=b10_rows('SELECT * FROM activity_logs WHERE action=? AND entity_id=? AND is_override=1 ORDER BY id DESC LIMIT 1',[$action,$entity])[0];
    b11_check($payload['reason'],$event['reason'],$label.' audit preserves reason');
    b11_check($admin['full_name'],$event['actor_name'],$label.' audit preserves Admin identity');
    b11_check('admin',$event['actor_role'],$label.' audit records Admin role');
    b11_check($label==='Locked deletion'?0:(str_contains($endpoint,'action=save')?1:2),b11_count('notifications')-$notificationsBefore,$label.' retry preserves original recipient count');
    if(in_array($label,['Force submission','Explicit IERB','IERB edit','Student edit'],true))b11_check($historyBefore+1,b11_count('ierb_history'),$label.' retry appends exactly one progress history');
}
clearstatcache(true,$deleteFile);
b11_check(false,is_file($deleteFile),'locked deletion cleans up file only after successful audit and commit');
b11_check(0,b11_count('documents','WHERE id=?',[$deleteId]),'audited locked deletion removes target row');
b11_check(1,(int)b10_doc($deletePrev)['is_current'],'audited locked deletion restores previous current version');

// A later write failure must roll back the audit too (strict audit is not an independent commit).
$lateId=b10_new(1003,'Atomic late deletion failure');
b11_check(200,b11_call('documents_api.php?action=submit_to_rpms&id='.$lateId,['reason'=>'Fixture late-write deletion setup'])['status'],'late deletion fixture locked');
$lateFile=$storage.'/'.b10_doc($lateId)['stored_name'];copy(__DIR__.'/fixtures/batch6-valid.pdf',$lateFile);$lateHash=hash_file('sha256',$lateFile);
$pdo->exec("CREATE TRIGGER batch11_fail_after_audit BEFORE DELETE ON documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic write failure after mandatory audit'");
try {
    $before=b11_atomic_snapshot();b11_check(500,b11_call('documents_api.php?action=delete&id='.$lateId,['reason'=>'Fixture late deletion failure'])['status'],'write after mandatory audit fails');
    b11_check($before,b11_atomic_snapshot(),'later write failure rolls back audit and all database rows');
    b11_check($lateHash,hash_file('sha256',$lateFile),'later write failure preserves submitted file');
} finally {$pdo->exec('DROP TRIGGER batch11_fail_after_audit');}
@mkdir(__DIR__.'/batch11-results',0700,true);
file_put_contents(__DIR__.'/batch11-results/atomic-audit-results.json',json_encode(['approval'=>'Approve atomic audit logging for existing overrides','results'=>array_slice($GLOBALS['b11Results'],$atomicStart),'checks'=>count($GLOBALS['b11Results'])-$atomicStart],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
