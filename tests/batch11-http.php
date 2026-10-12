<?php
/** Existing override endpoint coverage on the verified disposable frozen-v10 MariaDB/HTTP server. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$b11Start=$GLOBALS['nativeChecks'];$b11Results=[];
function b11_check(mixed $expected,mixed $actual,string $label): void {
    $GLOBALS['b11Results'][]=['label'=>$label,'passed'=>$expected===$actual,'expected'=>$expected,'actual'=>$actual];
    b10_expect($expected,$actual,'Batch11 '.$label);
}
function b11_call(string $endpoint,array $data=[],string $jar='admin',?string $source=null,?string $generation=null): array {
    $curl=curl_init($GLOBALS['origin'].'/'.$endpoint);$cookie=$GLOBALS['httpRoot'].'/'.$jar.'.cookies';
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Origin: '.($source??$GLOBALS['origin']),'X-Prism-Generation: '.($generation??$GLOBALS['generations'][$jar]??'')],CURLOPT_POSTFIELDS=>json_encode($data)]);
    $raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException('Private override request failed.');$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    $body=substr($raw,$size);return ['status'=>$code,'body'=>$body,'json'=>json_decode($body,true),'headers'=>substr($raw,0,$size)];
}
function b11_count(string $table,string $where='',array $args=[]): int {return (int)b10_rows('SELECT COUNT(*) AS n FROM '.$table.' '.$where,$args)[0]['n'];}
function b11_snapshot(): array {return ['documents'=>b10_rows('SELECT * FROM documents ORDER BY id'),'students'=>b10_rows('SELECT id,stage,status,requirements FROM students ORDER BY id'),'history'=>b10_rows('SELECT * FROM ierb_history ORDER BY id'),'notifications'=>b10_rows('SELECT * FROM notifications ORDER BY id'),'audit'=>b10_rows('SELECT * FROM activity_logs ORDER BY id')];}
try {
 b10_auth(1001,'admin');b10_auth(1002,'adviser');b10_auth(1003,'student');b10_auth(1005,'other-adviser');
 if (!in_array('--before1075',$argv,true)) {
 $id=b10_new(1003,'Batch11 override fixture');$old=b10_doc($id);$url='documents_api.php?action=override_review&id='.$id;
 foreach (['student','adviser','other-adviser'] as $role) {
  $before=b11_snapshot();b11_check(403,b11_call($url,['status'=>'Under Review','reason'=>'Valid fixture reason'],$role)['status'],$role.' document override forbidden');b11_check($before,b11_snapshot(),$role.' forbidden override no mutations');
  b11_check(403,b11_call('ierb_api.php?action=override',['id'=>1003,'stage'=>'Completed','status'=>'On Track','reason'=>'Valid fixture reason'],$role)['status'],$role.' explicit IERB override forbidden');
  b11_check(403,b11_call('documents_api.php?action=submit_to_rpms&id='.$id,['reason'=>'Valid fixture reason'],$role)['status'],$role.' legacy formal/force submission forbidden');
 }
 foreach (['',' ','abcd',[],['fake'=>'reason'],123,true] as $reason){$before=b11_snapshot();b11_check(422,b11_call($url,['status'=>'Under Review','reason'=>$reason])['status'],'invalid document reason '.get_debug_type($reason));b11_check($before,b11_snapshot(),'invalid reason no data/history/delivery');}
 foreach (['Imaginary',[],123] as $status)b11_check(422,b11_call($url,['status'=>$status,'reason'=>'Fixture correction'])['status'],'invalid target status '.get_debug_type($status));
 foreach(['ierb_api.php?action=save','students_api.php?action=save'] as $endpoint)foreach([[],true,12345] as $reason){$before=b11_snapshot();$r=b11_call($endpoint,['id'=>1003,'reason'=>$reason]);b11_check(422,$r['status'],'malformed edit reason '.$endpoint.' '.get_debug_type($reason));b11_check('Invalid progress change reason.',$r['json']['message']??'','edit rejects the malformed reason itself');b11_check($before,b11_snapshot(),'malformed edit override has no effects');}
 b11_check(405,b10_http($url)['status'],'override GET denied');
 b11_check(403,b11_call($url,['status'=>'Under Review','reason'=>'Fixture correction'],'admin','https://foreign.invalid')['status'],'override cross origin denied');
 b11_check(409,b11_call($url,['status'=>'Under Review','reason'=>'Fixture correction'],'admin',null,str_repeat('0',32))['status'],'override stale generation denied');
 b11_check(422,b11_call($url,['status'=>'Submitted','reason'=>'Fixture correction'],'admin',null,'')['status'],'legacy missing generation retains same-state refusal');
 b11_check(404,b11_call('documents_api.php?action=override_review&id=missing',['status'=>'Under Review','reason'=>'Fixture correction'])['status'],'missing document denied');
 b11_check(404,b11_call('ierb_api.php?action=override',['id'=>0,'stage'=>'Stage 1','reason'=>'Fixture correction'])['status'],'missing Student denied');
 $before=b11_snapshot();b11_check(409,b11_call($url,['status'=>'Under Review','reason'=>'Fixture correction','expectedStatus'=>'Verified'])['status'],'stale review snapshot denied');b11_check($before,b11_snapshot(),'stale review preserves everything');
 $beforeStage=b10_rows('SELECT stage,status FROM students WHERE id=1003')[0];
 $result=b11_call($url,['status'=>'Under Review','reason'=>'Fixture correction with <script> text','expectedStatus'=>'Submitted']);b11_check(200,$result['status'],'existing Admin document override succeeds');
 $doc=b10_doc($id);foreach(['version_no','is_current','supersedes_id','stored_name','uploaded_by','uploaded_at','rpms_submitted_at'] as $field)b11_check($old[$field],$doc[$field],'override preserves '.$field);
 b11_check('Under Review',$doc['review_status'],'review status matches');b11_check(1,(int)$doc['admin_override'],'override flag');b11_check('Fixture correction with <script> text',$doc['override_reason'],'full reason persisted');b11_check($admin['full_name'],$doc['override_by'],'Admin authorship');b11_check(true,!empty($doc['override_at']),'override timestamp');
 $audit=b10_rows("SELECT * FROM activity_logs WHERE entity_id=? AND action='admin_override_document'",[$id]);b11_check(1,count($audit),'one override audit');b11_check('Submitted',$audit[0]['before_value'],'audit source state');b11_check('Under Review',$audit[0]['after_value'],'audit target state');b11_check(1,(int)$audit[0]['is_override'],'audit distinct override flag');b11_check($beforeStage,b10_rows('SELECT stage,status FROM students WHERE id=1003')[0],'nonapproval leaves official progress');
 $copies=b10_rows("SELECT * FROM notifications WHERE message LIKE '%Fixture correction with <script> text%'");
 b11_check(true,count($copies)>=2,'Student and Adviser notification copies');
 foreach(['student','adviser'] as $role){$r=b10_http('documents_api.php?action=list',null,$role);$records=array_values(array_filter($r['json']['documents'],fn($d)=>$d['id']===$id));b11_check(1,count($records),$role.' override visible in scoped list');b11_check(true,$records[0]['adminOverride'],$role.' override badge metadata');b11_check(false,$records[0]['actions']['override'],$role.' override action remains forbidden');}
 $before=b11_snapshot();b11_check(422,b11_call($url,['status'=>'Under Review','reason'=>'Fixture retry'])['status'],'same-state replay rejected');b11_check($before,b11_snapshot(),'replay no duplicate audit/history/notifications');
 // Trigger injection is confined to the owned disposable database and removed in finally.
 $pdo->exec("CREATE TRIGGER batch11_fail_document BEFORE UPDATE ON documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic override write failure'");
 try{$before=b11_snapshot();b11_check(500,b11_call($url,['status'=>'Verified','reason'=>'Failure injection'])['status'],'document update failure');b11_check($before,b11_snapshot(),'document failure full rollback no delivery');}finally{$pdo->exec('DROP TRIGGER batch11_fail_document');}
 b11_check(200,b11_call($url,['status'=>'Approved','reason'=>'Existing Admin approval override'])['status'],'existing Admin Approved override');b11_check(null,b10_doc($id)['rpms_submitted_at'],'override approval does not impersonate Adviser formal submission');
 b11_check(200,b11_call('documents_api.php?action=submit_to_rpms&id='.$id)['status'],'existing ready formal submission');b11_check(true,!empty(b10_doc($id)['rpms_submitted_at']),'formal submission locks file');
 b11_check(409,b11_call($url,['status'=>'Denied','reason'=>'Attempt locked override'])['status'],'submitted override blocked');
 $oldVersion=b10_new(1003,'Historical fixture');$newVersion=b10_new(1003,'Historical fixture','Stage 1',$oldVersion);
 b11_check(409,b11_call('documents_api.php?action=override_review&id='.$oldVersion,['status'=>'Verified','reason'=>'Attempt historical override'])['status'],'historical version override blocked');
 $force=b10_new(1003,'Existing force submission fixture');b11_check(422,b11_call('documents_api.php?action=submit_to_rpms&id='.$force,['reason'=>''])['status'],'force submit requires reason');
 b11_check(200,b11_call('documents_api.php?action=submit_to_rpms&id='.$force,['reason'=>'Existing force submission justification'])['status'],'existing force submission capability preserved');b11_check(1,b11_count('activity_logs',"WHERE entity_id=? AND action='admin_override_submit_to_rpms'",[$force]),'force submit distinct audit');
 $progress=b10_rows('SELECT stage,status FROM students WHERE id=1003')[0];$target=$progress['status']==='Delayed'?'Pending':'Delayed';
 foreach(['','abc',[],123] as $reason)b11_check(422,b11_call('ierb_api.php?action=override',['id'=>1003,'status'=>$target,'reason'=>$reason])['status'],'invalid IERB reason '.get_debug_type($reason));
 $before=b11_snapshot();b11_check(409,b11_call('ierb_api.php?action=override',['id'=>1003,'status'=>$target,'reason'=>'Fixture progress correction','expectedStage'=>'Completed','expectedStatus'=>$progress['status']])['status'],'stale official progress denied');b11_check($before,b11_snapshot(),'stale IERB preserves history and delivery');
 $pdo->exec("CREATE TRIGGER batch11_fail_history BEFORE INSERT ON ierb_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic history failure'");
 try{$before=b11_snapshot();b11_check(500,b11_call('ierb_api.php?action=override',['id'=>1003,'status'=>$target,'reason'=>'Fixture rollback'])['status'],'IERB history failure');b11_check($before,b11_snapshot(),'IERB history failure full rollback');}finally{$pdo->exec('DROP TRIGGER batch11_fail_history');}
 $history=b11_count('ierb_history','WHERE student_id=?',[1003]);b11_check(200,b11_call('ierb_api.php?action=override',['id'=>1003,'status'=>$target,'reason'=>'Fixture progress correction','expectedStage'=>$progress['stage'],'expectedStatus'=>$progress['status']])['status'],'existing Admin IERB override succeeds');b11_check($history+1,b11_count('ierb_history','WHERE student_id=?',[1003]),'IERB override appends one history');
 $before=b11_snapshot();b11_check(422,b11_call('ierb_api.php?action=override',['id'=>1003,'status'=>$target,'reason'=>'Fixture progress correction'])['status'],'IERB replay no-op denied');b11_check($before,b11_snapshot(),'IERB replay no duplicate delivery');
 foreach(['ierb_api.php?action=list','reports_api.php?action=list'] as $endpoint)foreach([5,10,25,0,100000,-1] as $size){$r=b10_http($endpoint.'&perPage='.$size);b11_check(200,$r['status'],'pagination HTTP '.$endpoint);b11_check(in_array($size,[5,10,25],true)?$size:10,$r['json']['limit'],'bounded rows '.$endpoint.' '.$size);}
 foreach(['Inactive','Pending'] as $status){$pdo->prepare('UPDATE users SET status=? WHERE id=1001')->execute([$status]);b11_check(401,b11_call($url,['status'=>'Denied','reason'=>'Inactive Admin'])['status'],'inactive/Pending Admin session denied');$pdo->exec("UPDATE users SET status='Active' WHERE id=1001");b10_auth(1001,'admin');}
 $pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=1001');b11_check(403,b11_call($url,['status'=>'Denied','reason'=>'Legal gate'])['status'],'override legal gate');legal_accept($pdo,$admin,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
 $pdo->exec('UPDATE users SET must_change_password=1 WHERE id=1001');b11_check(403,b11_call($url,['status'=>'Denied','reason'=>'Password gate'])['status'],'override password gate');$pdo->exec('UPDATE users SET must_change_password=0 WHERE id=1001');b10_auth(1001,'admin');
 file_put_contents($httpRoot.'/fixture-expire.php','<?php require __DIR__."/config.php";$_SESSION["last_activity_at"]=time()-1801;echo "expired private clock";');b10_http('fixture-expire.php');b11_check(401,b11_call($url,['status'=>'Denied','reason'=>'Expired session'])['status'],'override expired session');b10_auth(1001,'admin');
 require __DIR__.'/batch11-atomic-audit.php';
 require __DIR__.'/batch11-concurrency.php';
 $readyId=b10_new(1003,'Ready UI integration fixture');b11_check(200,b11_call('documents_api.php?action=override_review&id='.$readyId,['status'=>'Approved','reason'=>'Existing approved status for UI integration fixture'])['status'],'eligible approved document prepared through existing override');
 }
 // Safe fixture rows exercise retention presentation without performing purge in the browser.
 $pdo->exec("UPDATE students SET archived_at=NOW(),retention_hold=1,retention_hold_reason='Fixture hold: preserve deletion safeguards' WHERE id=1004");
 $pdo->exec("INSERT INTO calendar_deadlines(creator_user_id,title,description,deadline_date,target_scope,status) VALUES(1001,'Batch11 official deadline','Synthetic visual fixture',CURRENT_DATE(),'all','Active')");
 lifecycle_compare_schema($pdo);
 echo 'PASS: '.($GLOBALS['nativeChecks']-$b11Start)." Batch11 override/authorization/rollback/pagination assertions.\n";
} finally {
 @mkdir(__DIR__.'/batch11-results',0700,true);
 file_put_contents(__DIR__.'/batch11-results/http-results.json',json_encode(['checks'=>$GLOBALS['nativeChecks']-$b11Start,'results'=>$b11Results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
