<?php
/** Parallel production endpoints, different sessions and HTTP workers, one owned private database. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
function b11_race(array $cases): array {
    $gate=$GLOBALS['root'].'/b11-gate-'.bin2hex(random_bytes(6));$workers=[];
    foreach($cases as $case){$ready=$gate.'-'.count($workers);$process=proc_open([PHP_BINARY,__DIR__.'/batch11-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        fwrite($pipes[0],json_encode($case+['dsn'=>$GLOBALS['dsn'],'password'=>$GLOBALS['password'],'datadir'=>$GLOBALS['datadir'],'ready'=>$ready,'gate'=>$gate]));fclose($pipes[0]);$workers[]=[$process,$pipes,$ready];}
    foreach($workers as [$process,$pipes,$ready]){$end=microtime(true)+15;while(!is_file($ready)&&microtime(true)<$end)usleep(10000);b11_check(true,is_file($ready),'concurrent worker ready');}
    file_put_contents($gate,'start');$results=[];
    foreach($workers as [$process,$pipes,$ready]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);b11_check(0,proc_close($process),'concurrent worker exit');b11_check('',$err,'concurrent worker diagnostics');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);unlink($ready);}unlink($gate);return $results;
}
$socket=stream_socket_server('tcp://127.0.0.1:0');$secondPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$secondOrigin='http://127.0.0.1:'.$secondPort;
$second=proc_open([PHP_BINARY,'-d','extension=zip','-d','session.save_path='.$httpRoot.'/sessions','-d','display_errors=0','-S','127.0.0.1:'.$secondPort,'-t',$httpRoot,$httpRoot.'/router.php'],[0=>['pipe','r'],1=>['file',$root.'/b11-http-2.log','a'],2=>['file',$root.'/b11-http-2.log','a']],$sp,null,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($sp[0]);
try{
 $deadline=microtime(true)+10;do{$sock=@fsockopen('127.0.0.1',$secondPort);if($sock){fclose($sock);break;}usleep(50000);}while(microtime(true)<$deadline);
 b10_auth(1006,'admin-race');
 $a=['origin'=>$origin,'cookie'=>$httpRoot.'/admin.cookies','generation'=>$generations['admin']];$b=['origin'=>$secondOrigin,'cookie'=>$httpRoot.'/admin-race.cookies','generation'=>$generations['admin-race']];
 $id=b10_new(1003,'Concurrent override fixture');$data=['status'=>'Under Review','expectedStatus'=>'Submitted','reason'=>'Concurrent fixture correction'];
 $race=b11_race([$a+['url'=>$origin.'/documents_api.php?action=override_review&id='.$id,'data'=>$data],$b+['url'=>$secondOrigin.'/documents_api.php?action=override_review&id='.$id,'data'=>$data]]);
 b11_check(1,count(array_filter($race,fn($r)=>$r['ok'])),'simultaneous same override one winner');b11_check('Under Review',b10_doc($id)['review_status'],'concurrent final review state');b11_check(1,b11_count('activity_logs',"WHERE entity_id=? AND action='admin_override_document'",[$id]),'concurrent document one audit');
 b11_check(2,b11_count('notifications',"WHERE message LIKE '%Concurrent fixture correction%'"),'concurrent one notification per Student/Adviser');
 $cur=b10_rows('SELECT stage,status FROM students WHERE id=1003')[0];$target=$cur['status']==='Pending'?'Delayed':'Pending';$history=b11_count('ierb_history','WHERE student_id=1003');
 $data=['id'=>1003,'status'=>$target,'expectedStage'=>$cur['stage'],'expectedStatus'=>$cur['status'],'reason'=>'Concurrent official progress correction'];
 $race=b11_race([$a+['url'=>$origin.'/ierb_api.php?action=override','data'=>$data],$b+['url'=>$secondOrigin.'/ierb_api.php?action=override','data'=>$data]]);
 b11_check(1,count(array_filter($race,fn($r)=>$r['ok'])),'simultaneous IERB override one winner');b11_check($history+1,b11_count('ierb_history','WHERE student_id=1003'),'concurrent IERB one history');b11_check(2,b11_count('notifications',"WHERE message LIKE '%Concurrent official progress correction%'"),'concurrent IERB one recipient copy each');
 $id=b10_new(1003,'Concurrent force submission fixture');$data=['reason'=>'Concurrent formal submission correction'];
 $race=b11_race([$a+['url'=>$origin.'/documents_api.php?action=submit_to_rpms&id='.$id,'data'=>$data],$b+['url'=>$secondOrigin.'/documents_api.php?action=submit_to_rpms&id='.$id,'data'=>$data]]);
 b11_check(1,count(array_filter($race,fn($r)=>$r['ok'])),'concurrent existing force submit one winner');b11_check(1,b11_count('activity_logs',"WHERE entity_id=? AND action='admin_override_submit_to_rpms'",[$id]),'concurrent force submit one audit');
 b11_check(true,!empty(b10_doc($id)['rpms_submitted_at']),'concurrent formal submission complete');
}finally{if(is_resource($second)){proc_terminate($second);proc_close($second);}}
