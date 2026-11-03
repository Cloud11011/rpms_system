<?php
/** Disposable notification-worker scenarios on schema 15 or 16. Simulated provider only; never loads deployed configuration. */
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v16.php';
require_once $root.'/includes/admin_settings_schema.php';
require_once $root.'/includes/admin_settings_store.php';
require_once $root.'/includes/runtime_preflight.php';
require_once $root.'/includes/notification_worker.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$scenario=(string)($input['scenario']??'');
if (!in_array($scenario,['worker15','worker16'],true)) throw new RuntimeException('Unknown worker scenario.');
$database='prism_group_synthetic_v11';
foreach (['SCHEMA_V16','SETTINGS'] as $name) { putenv('PRISM_ALLOW_'.$name.'_MIGRATION=1'); putenv('PRISM_'.$name.'_EXPECT_DB='.$database); }
$sandbox=PRISM_TEST_REVIEW_DIR.'/sandbox';
$admin=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
$results=[]; $failed=0;
$check=function(string $name,bool $ok,mixed $detail=null)use(&$results,&$failed){ $results[]=['name'=>$name,'ok'=>$ok]+($detail===null?[]:['detail'=>$detail]); if(!$ok)$failed++; };
$fresh=fn(): PDO=>new PDO('mysql:host=127.0.0.1;port='.PRISM_TEST_DB_PORT.';dbname='.$database.';charset=utf8mb4','root','prism_synthetic_only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+08:00'"]);
$version=fn()=>(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
$row=fn(int $id)=>$pdo->query('SELECT * FROM notifications WHERE id='.$id)->fetch();
$meta=fn(int $id)=>json_decode((string)($row($id)['delivery_info']??''),true)?:[];
$campaignStatus=fn(int $id)=>(string)$pdo->query('SELECT c.status FROM notification_campaigns c JOIN notifications n ON n.campaign_id=c.id WHERE n.id='.$id)->fetchColumn();
$backdate=fn(int $id)=>$pdo->exec('UPDATE notifications SET scheduled_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id='.$id);
$sorted=function(array $list): array { sort($list); return array_values($list); };
$email=fn(int $user)=>(string)$pdo->query('SELECT email FROM users WHERE id='.$user)->fetchColumn();
$emails=fn(array $users)=>$sorted(array_map($email,$users));
// One worker pass; returns the summary and the simulated provider calls it made.
$run=function(int $fence=0)use($pdo): array { $GLOBALS['provider_calls']=[]; $s=notification_worker_run($pdo,100,60,$fence); return [$s,$GLOBALS['provider_calls']]; };
$refusal=function(callable $fn): ?string { try { $fn(); } catch (NotificationWorkerRefusal $e) { return $e->getMessage(); } return null; };

$recipient=function(int $user)use($pdo): array {
    $u=$pdo->query('SELECT id,role,email,full_name FROM users WHERE id='.$user)->fetch();
    $profile=match($u['role']){
        'student'=>(int)$pdo->query('SELECT id FROM students WHERE user_id='.$user)->fetchColumn(),
        'adviser'=>(int)$pdo->query('SELECT id FROM advisers WHERE user_id='.$user)->fetchColumn(),
        default=>$user,
    };
    return ['type'=>$u['role'],'id'=>$profile,'email'=>$u['email'],'name'=>$u['full_name']];
};
$queue=function(int $user,string $when='-1 minute',?int $campaign=null,string $kind='workflow')use($pdo,$recipient): int {
    $r=$recipient($user);
    $n=create_notification($pdo,$r['type'],$r['id'],$r['email'],$r['name'],'Synthetic worker notice','Synthetic worker body.','Status Update',
        'Synthetic worker test',null,date('Y-m-d H:i:s',strtotime($when)),null,$kind,$campaign);
    if (!isset($n['id'])) throw new RuntimeException('Synthetic notification was not queued: '.json_encode($n));
    return (int)$n['id'];
};
$campaign=function(array $users,string $kind='staff',?string $category=null)use($pdo,$admin,$queue): array {
    $pdo->beginTransaction();
    try {
        $cid=notification_create_campaign($pdo,$admin,$kind,'Synthetic campaign','Synthetic campaign body.',date('Y-m-d H:i:s',time()-60),$category);
        $ids=array_map(fn($u)=>$queue($u,'-1 minute',$cid,$kind),$users);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return [$cid,$ids];
};
/** Child PHP processes released together by a barrier file; each child's provider calls go to its own log. */
$children=function(array $specs,array $env=[])use($sandbox): array {
    $barrier=$sandbox.'/worker-barrier-'.bin2hex(random_bytes(4)); $procs=[];
    foreach ($specs as $n=>$args) {
        $log=$sandbox.'/worker-provider-'.bin2hex(random_bytes(4)).'.log';
        $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/notification-worker-child.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
            array_merge(getenv(),$env,['PRISM_TEST_BARRIER'=>$barrier,'PRISM_TEST_PROVIDER_LOG'=>$log]));
        $procs[$n]=[$p,$pipes,$log];
    }
    usleep(2500000);  // every child finishes bootstrap and waits on the barrier
    touch($barrier); $out=[];
    foreach ($procs as $n=>[$p,$pipes,$log]) {
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $calls=is_file($log)?array_values(array_filter(array_map(fn($l)=>explode("\t",$l)[1]??'',file($log,FILE_IGNORE_NEW_LINES)))):[];
        $out[$n]=['exit'=>proc_close($p),'result'=>json_decode($stdout,true),'stderr'=>trim($stderr),'calls'=>$calls];
        @unlink($log);
    }
    @unlink($barrier);
    return $out;
};
$verified=function(string $label,callable $fn)use($check){ try{$fn();$check($label,true);}catch(Throwable $e){$check($label,false,get_class($e).': '.$e->getMessage());} };

try {
    $GLOBALS['fixture_errors']=[];
    if ($scenario==='worker16') {
        migrate_schema_v16($pdo,'apply'); migrate_prism_admin_settings($pdo,'apply');
        $check('disposable fixture migrated to schema 16 with Settings',$version()==='16' && prism_settings_store_available($pdo));
    }
    $expected=$scenario==='worker16'?'16':'15';
    $check('scenario schema '.$expected,$version()===$expected);

    // A. Activation gate: only literal true plus a non-negative integer fence enables the cron worker.
    foreach ([[null,null],[false,5],['1',5],[1,5],['true',5]] as [$enabled,$fence]) {
        $check('activation disabled for '.var_export($enabled,true),notification_worker_activation($enabled,$fence)===['enabled'=>false]);
    }
    foreach ([null,-1,'5',5.0] as $fence) {
        $r=$refusal(fn()=>notification_worker_activation(true,$fence));
        $check('enabled worker refuses fence '.var_export($fence,true),$r!==null && str_contains($r,'RELEASE_AFTER_ID'),$r);
    }
    $check('activation with fence 0 and 42',notification_worker_activation(true,0)===['enabled'=>true,'releaseAfterId'=>0]
        && notification_worker_activation(true,42)===['enabled'=>true,'releaseAfterId'=>42]);
    $check('application default keeps the worker disabled',!str_contains(file_get_contents($root.'/config.php'),"define('PRISM_NOTIFICATION_WORKER_ENABLED', true)")
        && str_contains(file_get_contents($root.'/config.php'),"define('PRISM_NOTIFICATION_WORKER_ENABLED', false)"));

    // B. Compatibility: version allow-list plus the exact read-only contract.
    $check('compatibility accepts schema '.$expected,(function()use($fresh){ try { return notification_worker_compatibility($fresh()); } catch (Throwable $e) { return $e->getMessage(); } })()===$expected);
    $fresh()->exec('ALTER TABLE notifications ADD COLUMN synthetic_drift TINYINT NULL');
    try { $r=$refusal(fn()=>notification_worker_compatibility($fresh())); }
    finally { $fresh()->exec('ALTER TABLE notifications DROP COLUMN synthetic_drift'); }
    $check('compatibility refuses a drifted notifications table',$r!==null && str_contains($r,'contract'),$r);
    foreach (['14','17'] as $other) {
        $fresh()->exec("UPDATE schema_meta SET v='".$other."' WHERE k='schema_version'");
        try { $r=$refusal(fn()=>notification_worker_compatibility($fresh())); }
        finally { $fresh()->exec("UPDATE schema_meta SET v='".$expected."' WHERE k='schema_version'"); }
        $check('compatibility refuses schema '.$other,$r!==null && str_contains($r,'verified against'),$r);
    }
    $check('compatibility checks left the schema version unchanged',$version()===$expected);

    [$drain]=$run(0);
    $check('initial drain of fixture rows completed',$drain['errors']===0,$drain);

    // C. Backlog fence: pre-activation rows are untouched until the operator explicitly releases them.
    $b1=$queue(4);
    $b2=$queue(5); $pdo->exec("UPDATE notifications SET status='Failed',delivery_info='{\"attempts\":1,\"retryable\":true,\"message\":\"External notification delivery failed.\"}' WHERE id=".$b2);
    $b3=$queue(6); $pdo->exec("UPDATE notifications SET status='Sending',sending_started_at=DATE_SUB(NOW(),INTERVAL 1 HOUR),delivery_info='{\"attempts\":1,\"retryable\":false,\"message\":\"Delivery claimed.\"}' WHERE id=".$b3);
    $b4=$queue(7); $pdo->exec("UPDATE notifications SET status='Sent',sent_at=NOW() WHERE id=".$b4);
    $pdo->exec("UPDATE notification_campaigns c JOIN notifications n ON n.campaign_id=c.id SET c.status='Sending' WHERE n.id=".$b4);
    $fence=(int)$pdo->query('SELECT MAX(id) FROM notifications')->fetchColumn();
    $n1=$queue(2);
    $snapshot=fn()=>hash('sha256',json_encode([
        $pdo->query('SELECT id,status,delivery_info,scheduled_at,sending_started_at,sent_at,deleted_at FROM notifications WHERE id<='.$fence.' ORDER BY id')->fetchAll(PDO::FETCH_NUM),
        $pdo->query('SELECT c.id,c.status,c.sent_at FROM notification_campaigns c WHERE EXISTS (SELECT 1 FROM notifications n WHERE n.campaign_id=c.id AND n.id<='.$fence.') ORDER BY c.id')->fetchAll(PDO::FETCH_NUM)]));
    $before=$snapshot();
    [$s,$calls]=$run($fence);
    $check('fenced run delivers only the post-fence notification',$calls===[$email(2)] && $s['selected']===1 && $row($n1)['status']==='Sent',[$s,$calls]);
    $check('fenced run reports the held backlog',$s['heldBacklog']===3 && $s['releaseAfterId']===$fence,$s);
    $check('fenced run leaves every backlog row and campaign unchanged',$snapshot()===$before);
    [$s,$calls]=$run($fence);
    $check('repeated fenced run still holds the backlog',$calls===[] && $snapshot()===$before,[$s,$calls]);
    // The operator's read-only phpMyAdmin review, run through the client exactly as written.
    $bin=rtrim(str_replace('\\','/',getenv('PRISM_TEST_MARIADB_BIN')?:'C:/xampp/mysql/bin'),'/');
    $p=proc_open([is_file($bin.'/mariadb.exe')?$bin.'/mariadb.exe':$bin.'/mysql.exe','--protocol=TCP','--host=127.0.0.1','--port='.PRISM_TEST_DB_PORT,
        '--user=root','--password=prism_synthetic_only','--default-character-set=utf8mb4','--batch','--database='.$database],
        [0=>['file',$root.'/PRISM_V14_FRONTEND_RECONCILIATION/notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $review=str_replace("\r","",stream_get_contents($pipes[1])); $reviewErr=trim(stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); $reviewExit=proc_close($p);
    $check('queue review SQL runs cleanly',$reviewExit===0 && $reviewErr==='',$reviewErr);
    $check('queue review SQL counts the held backlog like the worker',preg_match('/^worker_eligible\teligible_total\t3\t/m',$review)===1
        && preg_match('/^fence_preview\t'.(int)$pdo->query('SELECT MAX(id) FROM notifications')->fetchColumn().'\t3\t0$/m',$review)===1,$review);
    $check('queue review SQL classifies the retryable failure and the stale claim',preg_match('/^failed\tretryable\t1\t1\t/m',$review)===1
        && preg_match('/^sending_claims\tstale_over_15_minutes\t1\t/m',$review)===1,$review);
    $check('queue review SQL is read-only and returns no addresses or content',$snapshot()===$before
        && !str_contains($review,'@example.test') && !str_contains($review,'Synthetic worker') && !str_contains($review,'Synthetic campaign'));
    [$s,$calls]=$run(0);
    $check('explicit release delivers due and retryable backlog once',$sorted($calls)===$emails([4,5]),$calls);
    $check('released backlog statuses',$row($b1)['status']==='Sent' && $row($b2)['status']==='Sent' && (int)$meta($b2)['attempts']===2,[$row($b1)['status'],$row($b2)['status'],$meta($b2)]);
    $check('stale backlog claim becomes Uncertain without a resend',$s['Uncertain']===1 && $row($b3)['status']==='Failed'
        && ($meta($b3)['uncertain']??false)===true && ($meta($b3)['retryable']??true)===false,$meta($b3));
    $check('released backlog campaign is completed',$campaignStatus($b4)==='Sent');

    // D. Normal scheduled delivery.
    $d1=$queue(7); $d2=$queue(2,'+1 hour');
    [$s,$calls]=$run(0);
    $check('due notification delivered; future one waits',$calls===[$email(7)] && $row($d2)['status']==='Scheduled',[$s,$calls]);
    $check('delivered row records Sent, sent_at and one attempt',$row($d1)['status']==='Sent' && $row($d1)['sent_at']!==null
        && (int)$meta($d1)['attempts']===1 && $meta($d1)['retryable']===false && $row($d1)['sending_started_at']===null,$meta($d1));
    [$s,$calls]=$run(0);
    $check('re-run sends nothing twice',$calls===[],$calls);
    $backdate($d2);
    [$s,$calls]=$run(0);
    $check('future notification delivered once due',$calls===[$email(2)] && $row($d2)['status']==='Sent',$calls);

    // E. Partial provider failure in one campaign.
    [$e,$ids]=$campaign([4,5,6]);
    $GLOBALS['provider_fail_for']=[$email(5)];
    [$s,$calls]=$run(0);
    $check('partial failure attempts every recipient once',$sorted($calls)===$emails([4,5,6]) && $s['Sent']===2 && $s['Failed']===1,[$s,$calls]);
    $check('failed recipient is retryable with backoff',$row($ids[1])['status']==='Failed' && $meta($ids[1])['retryable']===true
        && (int)$meta($ids[1])['attempts']===1 && strtotime($row($ids[1])['scheduled_at'])>time()+30,[$row($ids[1])['scheduled_at'],$meta($ids[1])]);
    $check('successful recipients stay Sent; campaign Failed',$row($ids[0])['status']==='Sent' && $row($ids[2])['status']==='Sent' && $campaignStatus($ids[0])==='Failed');
    [$s,$calls]=$run(0);
    $check('retry waits for its backoff',$calls===[],$calls);
    $GLOBALS['provider_fail_for']=[]; $backdate($ids[1]);
    [$s,$calls]=$run(0);
    $check('retry resends only the failed recipient',$calls===[$email(5)] && $row($ids[1])['status']==='Sent' && (int)$meta($ids[1])['attempts']===2,[$calls,$meta($ids[1])]);
    $check('campaign completes after the successful retry',$campaignStatus($ids[0])==='Sent');

    // F. Retry limit.
    $f1=$queue(6); $GLOBALS['provider_fail_for']=[$email(6)]; $attempted=0;
    foreach ([1,2,3] as $attempt) {
        [$s,$calls]=$run(0); $attempted+=count($calls);
        $check("attempt $attempt fails and records attempts=$attempt",(int)$meta($f1)['attempts']===$attempt && $meta($f1)['retryable']===($attempt<3),$meta($f1));
        if ($attempt<3) { [$s,$calls]=$run(0); $check("attempt $attempt backoff holds the retry",$calls===[]); }
        $backdate($f1);
    }
    [$s,$calls]=$run(0); $attempted+=count($calls);
    $check('no fourth attempt after the retry limit',$calls===[] && $attempted===3 && $row($f1)['status']==='Failed',$attempted);
    $GLOBALS['provider_fail_for']=[];

    // G. Interrupted delivery: the process dies after the provider accepted the message.
    $g1=$queue(5);
    $crash=$children([['worker']],['PRISM_TEST_PROVIDER_CRASH'=>'1'])[0];
    $check('crashing worker exits after one provider call',$crash['exit']===9 && $crash['calls']===[$email(5)],$crash);
    $check('interrupted row is left claimed',$row($g1)['status']==='Sending' && $row($g1)['sending_started_at']!==null);
    [$s,$calls]=$run(0);
    $check('recent claim is not resent and the crashed worker lock was released',$calls===[] && empty($s['busy']) && $row($g1)['status']==='Sending',[$s,$calls]);
    $pdo->exec('UPDATE notifications SET sending_started_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE) WHERE id='.$g1);
    [$s,$calls]=$run(0);
    $check('stale claim is marked Uncertain without resending',$calls===[] && $s['Uncertain']===1 && $row($g1)['status']==='Failed'
        && ($meta($g1)['uncertain']??false)===true && $meta($g1)['retryable']===false,[$s,$meta($g1)]);
    $backdate($g1);
    [$s,$calls]=$run(0);
    $check('uncertain delivery is never retried automatically',$calls===[],$calls);

    // H. Two concurrent workers, then two concurrent dispatchers on the same rows.
    $h=array_map(fn($u)=>$queue($u),[4,5,6,7]);
    $pair=$children([['worker'],['worker']],['PRISM_TEST_PROVIDER_DELAY_MS'=>'400']);
    $busy=array_values(array_filter($pair,fn($c)=>!empty($c['result']['busy'])));
    $all=array_merge($pair[0]['calls'],$pair[1]['calls']);
    $check('two concurrent workers: one runs, one reports busy',count($busy)===1 && $pair[0]['exit']===0 && $pair[1]['exit']===0,$pair);
    $check('concurrent workers deliver each notification exactly once',$sorted($all)===$emails([4,5,6,7])
        && count(array_filter($h,fn($id)=>$row($id)['status']==='Sent'))===4,$all);
    $h2=array_map(fn($u)=>$queue($u),[4,5,6,7]); $list=implode(',',$h2);
    $pair=$children([['dispatch',$list],['dispatch',$list]],['PRISM_TEST_PROVIDER_DELAY_MS'=>'300']);
    $all=array_merge($pair[0]['calls'],$pair[1]['calls']);
    $sent=count(array_filter(array_merge($pair[0]['result']??[],$pair[1]['result']??[]),fn($x)=>$x==='Sent'));
    $check('racing dispatchers deliver each notification exactly once',$sorted($all)===$emails([4,5,6,7]) && $sent===4
        && count(array_filter($h2,fn($id)=>$row($id)['status']==='Sent'))===4,['calls'=>$all,'results'=>[$pair[0]['result'],$pair[1]['result']]]);
    [$s,$calls]=$run(0);
    $check('worker after the races finds nothing to resend',$calls===[],$calls);

    // J. Inactive, archived, unbound, removed and support rows are never emailed.
    $j1=$queue(6); $pdo->exec('UPDATE students SET archived_at=NOW() WHERE user_id=6'); $pdo->exec("UPDATE users SET status='Inactive' WHERE id=6");
    $j2=$queue(3); $pdo->exec("UPDATE users SET status='Inactive' WHERE id=3");
    $pdo->prepare("INSERT INTO notifications(recipient_type,recipient_id,recipient_user_id,recipient_email,recipient_name,subject,message,type,status,scheduled_at,created_by)
        VALUES ('student',4,NULL,?,'Historical unbound','Synthetic','Synthetic historical body.','Status Update','Scheduled',DATE_SUB(NOW(),INTERVAL 1 MINUTE),'Synthetic')")
        ->execute([$email(4)]);
    $j3=(int)$pdo->lastInsertId();
    $j4=$queue(4); $pdo->exec('UPDATE notifications SET deleted_at=NOW() WHERE id='.$j4);
    $j5=$queue(5); $pdo->exec('UPDATE notifications SET archived_at=NOW() WHERE id='.$j5);
    [,$support]=$campaign([1],'support','Other'); $j6=$support[0];
    [$s,$calls]=$run(0);
    $check('ineligible recipients receive no email',$calls===[],[$s,$calls]);
    $check('archived Student and inactive Adviser copies are cancelled',$row($j1)['status']==='Cancelled' && $row($j2)['status']==='Cancelled');
    $check('unbound historical row needs operator review, not retry',$row($j3)['status']==='Failed' && $meta($j3)['retryable']===false);
    $check('deleted, archived and support rows are untouched',$row($j4)['status']==='Scheduled' && $row($j5)['status']==='Scheduled' && $row($j6)['status']==='Scheduled');
    $pdo->exec('UPDATE students SET archived_at=NULL WHERE user_id=6'); $pdo->exec("UPDATE users SET status='Active' WHERE id IN (3,6)");

    // K. Schema 16 deadline reminders through the worker, including two concurrent schedulers.
    if ($scenario==='worker16') {
        $pdo->prepare("INSERT INTO calendar_deadlines(creator_user_id,title,deadline_date,target_scope) VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 3 DAY),'groups')")
            ->execute([$admin['id'],'Synthetic worker deadline']);
        $k1=(int)$pdo->lastInsertId();
        foreach ([4,5] as $u) $pdo->prepare('INSERT INTO calendar_deadline_recipients(deadline_id,student_id) SELECT ?,id FROM students WHERE user_id=?')->execute([$k1,$u]);
        [$s,$calls]=$run(0);
        $check('worker queues and delivers due reminders',$s['remindersQueued']===2 && $sorted($calls)===$emails([4,5]),[$s,$calls]);
        [$s,$calls]=$run(0);
        $check('reminders are not queued or sent twice',$s['remindersQueued']===0 && $calls===[],[$s,$calls]);
        $pdo->prepare("INSERT INTO calendar_deadlines(creator_user_id,title,deadline_date,target_scope) VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 7 DAY),'groups')")
            ->execute([$admin['id'],'Synthetic concurrent deadline']);
        $k2=(int)$pdo->lastInsertId();
        foreach ([4,5,6,7] as $u) $pdo->prepare('INSERT INTO calendar_deadline_recipients(deadline_id,student_id) SELECT ?,id FROM students WHERE user_id=?')->execute([$k2,$u]);
        $pair=$children([['enqueue'],['enqueue']]);
        $receipts=(int)$pdo->query('SELECT COUNT(*) FROM prism_deadline_reminder_dispatches WHERE deadline_id='.$k2)->fetchColumn();
        $linked=(int)$pdo->query('SELECT COUNT(DISTINCT notification_id) FROM prism_deadline_reminder_dispatches WHERE deadline_id='.$k2.' AND notification_id IS NOT NULL')->fetchColumn();
        $check('concurrent schedulers queue each reminder exactly once',$pair[0]['exit']===0 && $pair[1]['exit']===0
            && ($pair[0]['result']['queued']??0)+($pair[1]['result']['queued']??0)===4 && $receipts===4 && $linked===4,$pair);
        [$s,$calls]=$run(0);
        $check('worker delivers the concurrently queued reminders once',$sorted($calls)===$emails([4,5,6,7]),$calls);
    }

    // L. The real cron entry point in a copied disposable app, with mail forced to a closed loopback port.
    // Never pass an empty sendmail_path: Windows PHP then pipes mail to an empty command and reports success.
    $app=$sandbox.'/worker-app';
    $git=proc_open(['git','-C',$root,'ls-files','-z'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $tracked=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($git);
    foreach (array_filter(explode("\0",$tracked)) as $file) {
        if (!str_ends_with($file,'.php') && !str_starts_with($file,'includes/')) continue;
        if (!is_dir(dirname($app.'/'.$file))) mkdir(dirname($app.'/'.$file),0777,true);
        copy($root.'/'.$file,$app.'/'.$file);
    }
    $prepend=$sandbox.'/worker-prepend.php';
    file_put_contents($prepend,"<?php\n// TEST ONLY: activation constants for the copied disposable app, then the loopback fixture runtime.\n"
        ."if (getenv('PRISM_TEST_WORKER_ENABLED')!==false) define('PRISM_NOTIFICATION_WORKER_ENABLED',getenv('PRISM_TEST_WORKER_ENABLED')==='true');\n"
        ."if (getenv('PRISM_TEST_WORKER_FENCE')!==false) define('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID',(int)getenv('PRISM_TEST_WORKER_FENCE'));\n"
        ."require ".var_export(str_replace('\\','/',dirname(__DIR__).'/ierb-group-tests/runtime.php'),true).";\n");
    $cli=function(array $env)use($app,$prepend): array {
        $base=array_diff_key(getenv(),['PRISM_TEST_WORKER_ENABLED'=>1,'PRISM_TEST_WORKER_FENCE'=>1]);
        $p=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$prepend,'-d','SMTP=127.0.0.1','-d','smtp_port=9',
            $app.'/maintenance/notification-dispatch.php','--limit=25','--seconds=30'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$app,array_merge($base,$env));
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return ['exit'=>proc_close($p),'out'=>trim($out),'err'=>trim($err)];
    };
    $l1=$queue(4);
    $cliFence=(int)$pdo->query('SELECT MAX(id) FROM notifications')->fetchColumn();
    $r=$cli([]);
    $check('CLI without activation exits 0 as disabled',$r['exit']===0 && str_contains($r['out'],'"disabled"') && $row($l1)['status']==='Scheduled',$r);
    $r=$cli(['PRISM_TEST_DB_PORT'=>'1']);
    $check('disabled CLI never contacts the database',$r['exit']===0 && str_contains($r['out'],'"disabled"'),$r);
    $r=$cli(['PRISM_TEST_WORKER_ENABLED'=>'false','PRISM_TEST_WORKER_FENCE'=>'0']);
    $check('explicit false stays disabled even with a fence',$r['exit']===0 && str_contains($r['out'],'"disabled"') && $row($l1)['status']==='Scheduled',$r);
    $r=$cli(['PRISM_TEST_WORKER_ENABLED'=>'true']);
    $check('enabled CLI without a fence is refused',$r['exit']===1 && str_contains($r['err'],'RELEASE_AFTER_ID') && $row($l1)['status']==='Scheduled',$r);
    $fresh()->exec('ALTER TABLE notifications ADD COLUMN synthetic_drift TINYINT NULL');
    try { $r=$cli(['PRISM_TEST_WORKER_ENABLED'=>'true','PRISM_TEST_WORKER_FENCE'=>(string)$cliFence]); }
    finally { $fresh()->exec('ALTER TABLE notifications DROP COLUMN synthetic_drift'); }
    $check('enabled CLI refuses a drifted contract',$r['exit']===1 && str_contains($r['err'],'contract'),$r);
    $fresh()->exec("UPDATE schema_meta SET v='14' WHERE k='schema_version'");
    try { $r=$cli(['PRISM_TEST_WORKER_ENABLED'=>'true','PRISM_TEST_WORKER_FENCE'=>(string)$cliFence]); }
    finally { $fresh()->exec("UPDATE schema_meta SET v='".$expected."' WHERE k='schema_version'"); }
    $check('enabled CLI refuses an unverified schema version',$r['exit']===1 && str_contains($r['err'],'verified against'),$r);
    $l2=$queue(7);
    $r=$cli(['PRISM_TEST_WORKER_ENABLED'=>'true','PRISM_TEST_WORKER_FENCE'=>(string)$cliFence]);
    $summary=json_decode($r['out'],true)?:[];
    $check('enabled CLI processes only post-fence rows',$r['exit']===0 && ($summary['selected']??null)===1 && ($summary['heldBacklog']??0)>=1
        && $row($l1)['status']==='Scheduled' && in_array($row($l2)['status'],['Sent','Logged'],true),[$r,$row($l2)['status']]);
    $mailLog=is_file($app.'/storage/mail.log')?file_get_contents($app.'/storage/mail.log'):'';
    $check('closed mail transport falls back to a metadata-only local record',$row($l2)['status']==='Logged'
        && str_contains($mailLog,'status=Logged') && !str_contains($mailLog,'example.test'),$mailLog);

    // M. The real config.php Gmail/mail() code under a simulated Gmail API and mail(): no real transport is loadable.
    $simulator=__DIR__.'/mail-provider-simulator.php'; $plan=$sandbox.'/provider-plan.json'; $ledger=$sandbox.'/provider-ledger.ndjson';
    $provider=function(array $script,array $providerPlan,array $env=[])use($simulator,$plan,$ledger,$app): array {
        file_put_contents($plan,json_encode($providerPlan)); @unlink($ledger);
        $base=array_diff_key(getenv(),array_flip(['PRISM_TEST_WORKER_ENABLED','PRISM_TEST_WORKER_FENCE','PRISM_TEST_MAIL_FALLBACK','PRISM_TEST_GMAIL']));
        $p=proc_open(array_merge([PHP_BINARY,'-d','display_errors=stderr','-d','disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_error,curl_errno,curl_close',
            '-d','auto_prepend_file='.$simulator],$script),[1=>['pipe','w'],2=>['pipe','w']],$pipes,$app,
            array_merge($base,['PRISM_TEST_PROVIDER_PLAN'=>$plan,'PRISM_TEST_PROVIDER_LEDGER'=>$ledger],$env));
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $entries=is_file($ledger)?array_map(fn($l)=>json_decode($l,true),file($ledger,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)):[];
        return ['exit'=>proc_close($p),'result'=>json_decode(trim($out),true),'out'=>trim($out),'err'=>trim($err),'ledger'=>$entries,
            'accepted'=>count(array_filter($entries,fn($e)=>$e['accepted'])),'gmail'=>count(array_filter($entries,fn($e)=>$e['provider']==='gmail')),
            'mail'=>count(array_filter($entries,fn($e)=>$e['provider']==='mail'))];
    };
    $harness=fn(array $request,array $providerPlan,array $env=[])=>$provider([__DIR__.'/mail-provider-harness.php',$app,json_encode($request)],$providerPlan,$env);
    $send=fn(string $scenario,array $env=[],array $extra=[],array $request=[])=>$harness(['action'=>'send','to'=>$email(4)]+$request,$extra+['gmail'=>$scenario,'mail'=>'accept'],$env);
    $fallback=['PRISM_TEST_MAIL_FALLBACK'=>'true'];
    $r=$provider([$app.'/maintenance/notification-dispatch.php'],[]);
    $check('cron entry under the provider simulator stays disabled by default',$r['exit']===0 && str_contains($r['out'],'"disabled"'),$r);
    $r=$send('success');
    $check('Gmail success is Sent through Gmail only',($r['result']['outcome']??'')==='sent' && ($r['result']['channel']??'')==='gmail_api' && $r['gmail']===1 && $r['mail']===0,$r);
    foreach (['reject'=>'rejected','rate_limited'=>'rejected','timeout_after_accept'=>'uncertain','server_error_after_accept'=>'uncertain'] as $gmailCase=>$outcome) {
        $r=$send($gmailCase,$fallback);
        $check("Gmail $gmailCase is $outcome with no mail() fallback even when fallback is enabled",($r['result']['ok']??true)===false
            && ($r['result']['outcome']??'')===$outcome && $r['gmail']===1 && $r['mail']===0,$r);
    }
    $r=$send('success',[],['token'=>'fail']);
    $check('Gmail unreachable before any send: not attempted, nothing sent when fallback is not configured',($r['result']['outcome']??'')==='not_attempted' && $r['gmail']===0 && $r['mail']===0,$r);
    $r=$send('success',$fallback,['token'=>'fail']);
    $check('explicitly configured mail() fallback is used only when no Gmail send was attempted',($r['result']['outcome']??'')==='sent'
        && ($r['result']['channel']??'')==='mail' && $r['gmail']===0 && $r['mail']===1 && $r['accepted']===1,$r);
    $r=$send('connect_fail',$fallback);
    $check('connection failure before Gmail received the request may use the configured fallback once',($r['result']['channel']??'')==='mail' && $r['accepted']===1,$r);
    $r=$send('success',$fallback,['token'=>'fail','mail'=>'refuse']);
    $check('refused fallback with Gmail configured is a retryable failure, never Logged',($r['result']['ok']??true)===false && ($r['result']['outcome']??'')==='rejected',$r);
    $r=$send('success',['PRISM_TEST_GMAIL'=>'off'],['mail'=>'refuse']);
    $check('no external transport configured: metadata-only Logged record',($r['result']['outcome']??'')==='logged' && ($r['result']['channel']??'')==='log' && $r['mail']===1,$r);
    $r=$send('success',['PRISM_TEST_GMAIL'=>'off']);
    $check('without Gmail, server mail() remains the primary transport',($r['result']['outcome']??'')==='sent' && ($r['result']['channel']??'')==='mail',$r);
    // Security-sensitive e-mail.
    $r=$harness(['action'=>'invite','user'=>4,'to'=>$email(4)],['gmail'=>'success']);
    $check('invitation e-mail delivered through Gmail',($r['result']['emailSent']??null)===true && $r['accepted']===1,$r);
    $r=$harness(['action'=>'invite','user'=>4,'to'=>$email(4)],['gmail'=>'timeout_after_accept','mail'=>'accept'],$fallback);
    $check('uncertain invitation is not duplicated and keeps the Pending/Resend path',($r['result']['emailSent']??null)===false
        && str_contains($r['result']['message']??'','Resend Invitation') && $r['gmail']===1 && $r['mail']===0,$r);
    $before=(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=4')->fetchColumn();
    $r=$harness(['action'=>'setup','user'=>4,'to'=>$email(4)],['gmail'=>'success']);
    $check('account setup e-mail delivered and reported as sent',($r['result']['ok']??null)===true && ($r['result']['response']['setupPending']??null)===false,$r);
    $r=$harness(['action'=>'setup','user'=>4,'to'=>$email(4)],['gmail'=>'server_error_after_accept','mail'=>'accept'],$fallback);
    $check('uncertain setup e-mail is reported pending, never duplicated through mail()',($r['result']['response']['setupPending']??null)===true
        && $r['gmail']===1 && $r['mail']===0 && (int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=4')->fetchColumn()===$before+2,$r);
    $r=$harness(['action'=>'reset','to'=>$email(4)],['gmail'=>'reject','mail'=>'accept'],$fallback);
    $check('rejected password reset e-mail falls back to nothing',($r['result']['ok']??true)===false && $r['gmail']===1 && $r['mail']===0,$r);
    $r=$harness(['action'=>'send','to'=>$email(4),'sensitive'=>true],['token'=>'fail','mail'=>'refuse'],$fallback);
    $check('sensitive e-mail is never written to the local log',($r['result']['ok']??true)===false && ($r['result']['channel']??'')==='none',$r);
    // Immediate notifications.
    $r=$harness(['action'=>'immediate','user'=>5],['gmail'=>'success']);
    $i1=(int)($r['result']['id']??0);
    $check('immediate notification Sent once with sent_at',($r['result']['status']??'')==='Sent' && $row($i1)['status']==='Sent' && $row($i1)['sent_at']!==null && $r['accepted']===1,$r);
    $r=$harness(['action'=>'immediate','user'=>6],['gmail'=>'timeout_after_accept','mail'=>'accept'],$fallback);
    $i2=(int)($r['result']['id']??0);
    $check('uncertain immediate notification is held, not retried and not marked sent',($r['result']['status']??'')==='Uncertain' && $row($i2)['status']==='Failed'
        && ($meta($i2)['uncertain']??false)===true && $meta($i2)['retryable']===false && $row($i2)['sent_at']===null && $r['mail']===0,[$r,$meta($i2)]);
    $r=$harness(['action'=>'immediate','user'=>7],['gmail'=>'reject']);
    $i3=(int)($r['result']['id']??0);
    $check('rejected immediate notification is retryable',$row($i3)['status']==='Failed' && $meta($i3)['retryable']===true && $row($i3)['sent_at']===null,$meta($i3));
    $r=$harness(['action'=>'immediate','user'=>4],['mail'=>'refuse'],['PRISM_TEST_GMAIL'=>'off']);
    $i4=(int)($r['result']['id']??0);
    $check('Logged immediate notification is not marked as sent',$row($i4)['status']==='Logged' && $row($i4)['sent_at']===null,$row($i4));
    // Scheduled notifications through the real cron entry point, one campaign, mixed provider outcomes.
    [$mc,$mids]=$campaign([4,5,6]);
    $fenceNow=$mids[0]-1; $workerEnv=$fallback+['PRISM_TEST_WORKER_ENABLED'=>'true','PRISM_TEST_WORKER_FENCE'=>(string)$fenceNow];
    $cliPlan=['byRecipient'=>[$email(4)=>'success',$email(5)=>'reject',$email(6)=>'timeout_after_accept'],'mail'=>'accept'];
    $backdate($i3);
    $r=$provider([$app.'/maintenance/notification-dispatch.php','--limit=25','--seconds=30'],$cliPlan,$workerEnv);
    $sum=json_decode($r['out'],true)?:[];
    $check('worker with Gmail: one Sent, one rejected, one uncertain; mail() never used',$r['exit']===1 && ($sum['Sent']??0)===1 && ($sum['Failed']??0)===1
        && ($sum['Uncertain']??0)===1 && $r['mail']===0 && $r['gmail']===3,[$r['out'],$r['ledger']]);
    $check('earlier rows below the cutoff and the rejected immediate row stay held',$row($i3)['status']==='Failed' && (int)$meta($i3)['attempts']===1);
    $check('mixed campaign statuses recorded',$row($mids[0])['status']==='Sent' && $meta($mids[1])['retryable']===true && ($meta($mids[2])['uncertain']??false)===true
        && $campaignStatus($mids[0])==='Failed');
    $backdate($mids[1]); $backdate($mids[2]);
    $r=$provider([$app.'/maintenance/notification-dispatch.php'],['gmail'=>'success'],$workerEnv);
    $check('retry resends only the rejected recipient; the uncertain one is never resent',$r['exit']===0 && $r['gmail']===1
        && ($r['ledger'][0]['to']??'')===$email(5) && $row($mids[1])['status']==='Sent' && (int)$meta($mids[1])['attempts']===2,[$r['out'],$r['ledger']]);
    $check('campaign with an unresolved uncertain recipient is not reported as Sent',$campaignStatus($mids[0])==='Failed');
    $r=$provider([$app.'/maintenance/notification-dispatch.php'],['gmail'=>'success'],$workerEnv);
    $check('no further provider calls once only uncertain rows remain',$r['gmail']===0 && $r['mail']===0,$r['ledger']);

    // Final state.
    $check('schema version unchanged at '.$expected,$version()===$expected);
    $verified('runtime preflight PASS after the scenario',function()use($fresh){ $p=prism_runtime_database_preflight($fresh()); if(!$p['ok']||$p['contract']!=='PASS') throw new RuntimeException(json_encode($p)); });
    $check('no unexpected application error logs',$GLOBALS['fixture_errors']===[],$GLOBALS['fixture_errors']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $check('scenario completed without unexpected exception',false,get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
}
echo json_encode(['ok'=>$failed===0,'scenario'=>$scenario,'server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'passed'=>count($results)-$failed,'failed'=>$failed,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===0?0:1);
