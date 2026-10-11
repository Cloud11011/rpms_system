<?php
/** Native concurrent connections with deterministic start/provider barriers. */
if (!isset($pdo,$root,$datadir,$port,$password)) exit(1);
function b7_worker(array $case): array {
    $path=$GLOBALS['root'].'/worker-'.bin2hex(random_bytes(5)).'.json';
    file_put_contents($path,json_encode($case+['dsn'=>'mysql:host=127.0.0.1;port='.$GLOBALS['port'].';dbname=b7_fixture;charset=utf8mb4',
        'password'=>$GLOBALS['password'],'datadir'=>$GLOBALS['datadir'],'attempts'=>$GLOBALS['root'].'/attempts.log']));
    $process=proc_open([PHP_BINARY,__DIR__.'/v10-batch7-worker.php',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Private worker startup failed.'); fclose($pipes[0]); return [$process,$pipes,$path];
}
function b7_finish(array $worker): array {
    [$process,$pipes,$path]=$worker; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process); unlink($path);
    reset_migration_expect(0,$exit,'Worker exit '.$err); reset_migration_expect('',$err,'No worker diagnostics');
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function b7_gate_wait(string $path): void {
    $deadline=microtime(true)+15; while (!file_exists($path) && microtime(true)<$deadline) usleep(10000);
    reset_migration_expect(true,file_exists($path),'Concurrent worker reached barrier');
}
function b7_race(array $a,array $b): array {
    $gate=$GLOBALS['root'].'/race-'.bin2hex(random_bytes(5));
    $wa=b7_worker($a+['gate'=>$gate,'ready'=>$gate.'.a']); $wb=b7_worker($b+['gate'=>$gate,'ready'=>$gate.'.b']);
    b7_gate_wait($gate.'.a'); b7_gate_wait($gate.'.b'); file_put_contents($gate,'go');
    return [b7_finish($wa),b7_finish($wb)];
}
$actor=b7_row('users',1);
foreach ([['archive','archive'],['archive','mark_read'],['restore','delete'],['delete','delete']] as [$aAction,$bAction]) {
    [$cid,$copies]=b7_send(); $id=$copies[0];
    if (in_array($aAction,['restore','delete'],true)) notification_transition($pdo,b7_row('users',3),'archive',$id);
    $results=b7_race(['mode'=>'transition','actor'=>3,'action'=>$aAction,'id'=>$id],['mode'=>'transition','actor'=>3,'action'=>$bAction,'id'=>$id]);
    // GET_LOCK is fail-fast; a losing simultaneous request may return 409 and can be safely retried.
    reset_migration_expect(true,count(array_filter($results,fn($r)=>$r['ok']))>=1,'At least one lifecycle request progresses');
    $row=b7_row('notifications',$id);
    if ($aAction==='archive' && $row['archived_at']===null) {
        notification_transition($pdo,b7_row('users',3),'archive',$id); // Retry a lock-conflict loser.
        $row=b7_row('notifications',$id);
    }
    if ($aAction==='archive') reset_migration_expect(true,$row['archived_at']!==null,'Concurrent archive persists');
    if ($aAction==='delete') reset_migration_expect('',$row['message'],'Repeated concurrent deletion erases content');
    if ($aAction==='restore') reset_migration_expect(true,($row['deleted_at']!==null && $row['message']==='') || ($row['deleted_at']===null && $row['archived_at']===null && $row['message']==='PRIVATE BODY'),'Restore/delete yields no resurrection');
    reset_migration_expect('PRIVATE BODY',b7_row('notifications',$copies[1])['message'],'Race never mutates other recipient');
}
foreach (['campaign_cancel','campaign_archive'] as $action) {
    [$cid,$copies]=b7_send('staff',date('Y-m-d H:i:s'));
    $before=is_file($root.'/attempts.log')?count(file($root.'/attempts.log')):0;
    $results=b7_race(['mode'=>'transition','actor'=>1,'action'=>$action,'id'=>$cid],['mode'=>'dispatch','id'=>$copies[0]]);
    $after=is_file($root.'/attempts.log')?count(file($root.'/attempts.log')):0;
    $campaign=b7_row('notification_campaigns',$cid);
    reset_migration_expect(true,$after-$before<=1,'Cancel/claim race cannot duplicate delivery');
    if ($campaign['status']==='Cancelled') reset_migration_expect(0,$after-$before,'Winning cancellation prevents mail');
    if ($campaign['archived_at']!==null) reset_migration_expect(true,in_array($campaign['status'],['Sent','Failed','Cancelled'],true),'Archive never wins over active scheduled/sending state');
    // Complete remaining copies before subsequent tests.
    foreach ($copies as $id) notification_dispatch($pdo,$id);
}
[$cid,$copies]=b7_send('staff',date('Y-m-d H:i:s')); $provider=$root.'/provider-gate';
$worker=b7_worker(['mode'=>'dispatch','id'=>$copies[0],'providerGate'=>$provider]); b7_gate_wait($provider.'.ready');
foreach (['campaign_archive','campaign_delete'] as $action) b7_refuse(fn()=>notification_transition($pdo,$actor,$action,$cid,true),409);
file_put_contents($provider,'continue'); $result=b7_finish($worker);
reset_migration_expect('Sent',$result['result']['status'],'Sending transition reaches stable copy state');
notification_dispatch($pdo,$copies[1]); notification_transition($pdo,$actor,'campaign_archive',$cid);
$results=b7_race(['mode'=>'transition','actor'=>1,'action'=>'campaign_delete','id'=>$cid],['mode'=>'transition','actor'=>1,'action'=>'campaign_delete','id'=>$cid]);
reset_migration_expect(null,b7_row('notification_campaigns',$cid)['message'],'Concurrent sender deletion erases sender body');
reset_migration_expect('PRIVATE BODY',b7_row('notifications',$copies[1])['message'],'Concurrent sender deletion preserves copies');
$audit=notification_rows($pdo,'SELECT id FROM activity_logs WHERE action=? AND entity_id=?',['notification_campaign_delete',(string)$cid]);
reset_migration_expect(1,count($audit),'One sender-delete audit event');
echo "PASS: two archives, archive/read, restore/delete, two deletes, cancel/worker, archive/claim, Sending/provider and two sender-delete native races.\n";
