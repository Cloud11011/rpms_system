<?php
if (PHP_SAPI!=='cli' || !isset($pdo,$datadir,$root,$port,$password)) exit(1);
function b6_worker(array $case): array {
    $path=$GLOBALS['root'].'/b6-worker-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path,json_encode($case+['dsn'=>'mysql:host=127.0.0.1;port='.$GLOBALS['port'].';dbname=b6_php;charset=utf8mb4','password'=>$GLOBALS['password'],'datadir'=>$GLOBALS['datadir']]));
    $process=proc_open([PHP_BINARY,__DIR__.'/v10-batch6-concurrency-worker.php',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start private worker.'); fclose($pipes[0]);return [$process,$pipes,$path];
}
function b6_finish(array $worker): array {
    [$process,$pipes,$path]=$worker; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);unlink($path);
    b6_expect(0,$exit,'Concurrent worker exit: '.$err); b6_expect('',$err,'No concurrent worker diagnostics'); return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function b6_wait_gate(string $gate): void {
    $deadline=microtime(true)+10; while (!file_exists($gate.'.ready') && microtime(true)<$deadline) usleep(10000);
    b6_expect(true,file_exists($gate.'.ready'),'Authoritative account range locked');
}
$pdo->exec("UPDATE users SET status='Inactive' WHERE id IN (3,4)");
$race=b6_manage(2,['action'=>'create','policy'=>'privacy']);
b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$race,'title'=>'Privacy Policy','content'=>"# Privacy concurrency\nSafe text",'summary'=>'Concurrent approval fixture']);
b6_manage(2,['action'=>'submit','policy'=>'privacy','versionId'=>$race]);
$publish=['action'=>'approve','policy'=>'privacy','versionId'=>$race,'password'=>'Batch6 fixture passphrase','reviewAcknowledged'=>true,'soleAcknowledged'=>true];
$gate=$root.'/publication-gate';
$publisher=b6_worker(['mode'=>'manage','actor'=>2,'data'=>$publish,'gate'=>$gate]); b6_wait_gate($gate);
$inserter=b6_worker(['mode'=>'insert_admin']);
usleep(150000); b6_expect(true,proc_get_status($inserter[0])['running'],'New Admin insert waits for publication range lock');
file_put_contents($gate,'continue'); b6_expect(true,b6_finish($publisher)['ok'],'Sole publication completes before blocked registration'); b6_expect(true,b6_finish($inserter)['ok'],'Registration proceeds after publication');
b6_expect('sole_admin',$pdo->query('SELECT approval_mode FROM legal_policy_approvals WHERE version_id='.$race)->fetchColumn(),'Race uses authoritative sole count');
$pdo->exec("UPDATE users SET status='Active' WHERE id IN (3,4)"); foreach ([2,3,4] as $id) b6_accept_all($id);
// Two simultaneous independent reviewers: only one transaction publishes, no double approval.
$draft=b6_manage(2,['action'=>'create','policy'=>'privacy']); b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$draft,'title'=>'Privacy Policy','content'=>"# Independent concurrency\nText",'summary'=>'Two reviewers']); b6_manage(2,['action'=>'submit','policy'=>'privacy','versionId'=>$draft]);
$data=array_replace($publish,['versionId'=>$draft]); $a=b6_worker(['mode'=>'manage','actor'=>3,'data'=>$data]); $b=b6_worker(['mode'=>'manage','actor'=>4,'data'=>$data]);
$results=[b6_finish($a),b6_finish($b)]; b6_expect(1,count(array_filter($results,fn($r)=>$r['ok'])),'Exactly one concurrent publication winner');
b6_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM legal_policy_approvals WHERE version_id='.$draft)->fetchColumn(),'Exactly one durable concurrent approval');
foreach ([2,3,4] as $id) b6_accept_all($id);
// Concurrent creation is serialized and additionally protected by generated unique marker.
$a=b6_worker(['mode'=>'manage','actor'=>2,'data'=>['action'=>'create','policy'=>'terms']]); $b=b6_worker(['mode'=>'manage','actor'=>3,'data'=>['action'=>'create','policy'=>'terms']]);
$results=[b6_finish($a),b6_finish($b)]; b6_expect(1,count(array_filter($results,fn($r)=>$r['ok'])),'One open candidate under concurrent creation');
// Prepared B9 representation supports an actual one-winner transaction without a future schema change.
$pdo->exec("INSERT INTO staff_registration_code(slot,generation_id,verifier_hash,state,creator_user_id,acknowledged_at,expires_at) VALUES (1,'fixture-concurrent-code','synthetic verifier','acknowledged',2,NOW(),DATE_ADD(NOW(),INTERVAL 24 HOUR))");
$a=b6_worker(['mode'=>'consume']); $b=b6_worker(['mode'=>'consume']); $results=[b6_finish($a),b6_finish($b)];
b6_expect(1,array_sum(array_column($results,'result')),'Concurrent Staff Code consume has one winner'); $pdo->exec('DELETE FROM staff_registration_code');
echo "PASS: native concurrent Admin insertion/count locking, two-reviewer publication, candidate creation and Staff Code consumption.\n";
