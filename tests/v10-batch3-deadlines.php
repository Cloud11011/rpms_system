<?php
/** Reuse actual endpoint + volatile SQL fixture; never load configuration/live services. */
if(PHP_SAPI!=='cli')exit(1);
$checks=0;
function verify(bool $ok,string $label): void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label);}
function deadline_case(array $case): array {
    $p=proc_open([PHP_BINARY,__DIR__.'/calendar-deadlines-audit.php','--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($p)===0 && $err==='','Actual deadline endpoint executes cleanly: '.$err.$out);return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$g1='AMT-BSIT-Y2-2627-G01';$g2='AMT-BSIT-Y2-2627-G02';$g3='AMT-BSIT-Y2-2627-G03';
$base=['title'=>'Batch 3','date'=>'2026-10-10','target'=>'selected','groups'=>[$g1]];
foreach([['admin',[$g1]],['admin',[$g1,$g2,$g1]],['adviser',[$g1,$g2]]] as [$role,$groups]) {
    $r=deadline_case(['role'=>$role,'data'=>array_replace($base,['groups'=>$groups])]);verify($r['status']===200 && count($r['groups'])===count(array_unique($groups)),'Canonical authorized/deduplicated targets');
}
foreach([['admin',['stale']],['adviser',[$g3]],['adviser',[$g1,$g3]],['admin',[]],['admin', [['object']]]] as [$role,$groups]) {
    $r=deadline_case(['role'=>$role,'data'=>array_replace($base,['groups'=>$groups])]);verify(in_array($r['status'],[403,422],true) && !$r['deadlines'],'Stale, crafted, empty and unauthorized targets rejected atomically');
}
foreach(['admin','adviser'] as $role){$r=deadline_case(['role'=>$role,'data'=>array_replace($base,['target'=>'all','groups'=>[]])]);verify($r['status']===200 && ($role==='admin'?$r['deadlines'][0]['target_scope']==='all':count($r['groups'])===2),'Existing global/assigned-all policy retained');}
foreach([['method'=>'GET'],['origin'=>'https://evil.test'],['role'=>'student']] as $guard)verify(in_array(deadline_case($guard+['data'=>$base])['status'],[403,405],true),'Mutation/role/origin guards retained');
$sql=["INSERT INTO calendar_deadlines(id,creator_user_id,title,description,deadline_date,target_scope,status) VALUES(1,10,'Admin','','2026-10-10','groups','Active'),(2,11,'Own','','2026-10-10','groups','Active'),(3,99,'Other','','2026-10-10','groups','Active'),(4,11,'Cancelled','','2026-10-10','groups','Cancelled')",
    "INSERT INTO calendar_deadline_groups VALUES(1,'stale-historical'),(2,'$g1'),(3,'$g3'),(4,'$g1')"];
$r=deadline_case(['action'=>'list','method'=>'GET','sql'=>$sql,'query'=>['manage'=>'1','from'=>'2026-10-10']]);
verify($r['status']===200 && $r['groups'][0]['research_group']==='stale-historical','Management read preserves historical target; no editor/update endpoint exists');
foreach([['admin',1,200],['adviser',2,200],['adviser',1,403],['adviser',3,403],['student',2,403],['admin',4,200]] as [$role,$id,$status]) {
    $r=deadline_case(['action'=>'cancel','role'=>$role,'data'=>['id'=>$id],'sql'=>$sql]);verify($r['status']===$status,'Existing creator/Admin cancellation policy retained');
    verify(count($r['deadlines'])===4 && count($r['groups'])===4,'Cancellation never deletes history or group mappings');
    if($status===200)verify($r['deadlines'][$id-1]['status']==='Cancelled','Status reflects authoritative backend result');
    if($id===4)verify(!$r['notifications'],'Already cancelled never repeats notifications');
}
echo "PASS: $checks Batch 3 deadline/API/group/cancellation assertions; volatile SQL only.\n";
