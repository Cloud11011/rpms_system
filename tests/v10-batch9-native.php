<?php
/** Execute production services on the verified disposable frozen-v10 server. Never migrate. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
require __DIR__.'/v10-batch9-helpers.php';
if (!defined('SCHEMA_VERSION')) define('SCHEMA_VERSION',10);
$b9Start=$GLOBALS['nativeChecks'];
foreach ([90=>'admin',91=>'admin',92=>'student',93=>'adviser',94=>'admin'] as $uid=>$role) {
    $pdo->prepare('INSERT INTO users(id,username,ref_id,full_name,email,role,status,password_hash,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([$uid,'B9-'.$uid,'B9-'.$uid,'Batch9 '.$uid,'b9-'.$uid.'@example.invalid',$role,$uid===94?'Inactive':'Active',password_hash('Batch9 fixture password',PASSWORD_DEFAULT)]);
}
$adminA=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=90')[0]; $adminB=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=91')[0];
foreach ([$adminA,$adminB] as $actor) legal_accept($pdo,$actor,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
$context=bin2hex(random_bytes(16)); $key=bin2hex(random_bytes(32));
function b9_expect(mixed $expected,mixed $actual,string $label): void { reset_migration_expect($expected,$actual,'Batch9 '.$label); }
function b9_refuse(callable $fn): void {
    try { $fn(); } catch (StaffRegistrationError $e) { b9_expect(false,$GLOBALS['pdo']->inTransaction(),'refusal closes transaction'); return; }
    throw new RuntimeException('Batch9 expected safe refusal.');
}
function b9_current(): array { return lifecycle_rows($GLOBALS['pdo'],'SELECT * FROM staff_registration_code WHERE slot=1')[0]??[]; }
function b9_generate(): array {
    return staff_manage($GLOBALS['pdo'],$GLOBALS['adminA'],['action'=>'generate','generation_id'=>b9_current()['generation_id']??''],$GLOBALS['context'],$GLOBALS['key']);
}
function b9_ack(array $generated, array $changes=[], ?array $actor=null): array {
    return staff_manage($GLOBALS['pdo'],$actor??$GLOBALS['adminA'],array_replace(['action'=>'acknowledge','generation_id'=>$generated['row']['generation_id'],'confirmed'=>'1','proof'=>$generated['proof']],$changes),$GLOBALS['context'],$GLOBALS['key']);
}
function b9_fields(string $code, string $id): array {
    return ['registration_code'=>$code,'employee_id'=>$id,'fullname'=>'Fixture Administrator','email'=>strtolower($id).'@example.invalid','password'=>'Batch9 fixture password','confirm_password'=>'Batch9 fixture password'];
}
function b9_race(array $cases): array {
    $gate=$GLOBALS['root'].'/b9-gate-'.bin2hex(random_bytes(5)); $workers=[];
    foreach ($cases as $case) {
        $ready=$gate.'-'.count($workers);
        $process=proc_open([PHP_BINARY,__DIR__.'/v10-batch9-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        if (!is_resource($process)) throw new RuntimeException('Private worker startup failed.');
        fwrite($pipes[0],json_encode($case+['dsn'=>'mysql:host=127.0.0.1;port='.$GLOBALS['port'].';dbname=b7_fixture;charset=utf8mb4','password'=>$GLOBALS['password'],
            'datadir'=>$GLOBALS['datadir'],'ready'=>$ready,'gate'=>$gate,'actor'=>$GLOBALS['adminA'],'context'=>$GLOBALS['context'],'key'=>$GLOBALS['key']])); fclose($pipes[0]);
        $workers[]=[$process,$pipes,$ready];
    }
    foreach ($workers as [$process,$pipes,$ready]) {
        $deadline=microtime(true)+15; while (!is_file($ready) && microtime(true)<$deadline) usleep(10000);
        b9_expect(true,is_file($ready),'native barrier readiness');
    }
    file_put_contents($gate,'start'); $results=[];
    foreach ($workers as [$process,$pipes,$ready]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        b9_expect(0,proc_close($process),'native worker exit '.$err); b9_expect('',$err,'native worker diagnostics');
        $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR); unlink($ready);
    }
    unlink($gate); return $results;
}
$pdo->exec('DELETE FROM staff_registration_code');
b9_expect(null,staff_overview($pdo,$adminA),'empty singleton overview does not generate');
foreach ([92,93,94] as $uid) b9_refuse(fn()=>staff_overview($pdo,lifecycle_rows($pdo,'SELECT * FROM users WHERE id=?',[$uid])[0]));
$pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=91'); b9_refuse(fn()=>staff_overview($pdo,$adminB));
legal_accept($pdo,$adminB,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
$g=b9_generate(); $secrets=[$g['code']];
b9_expect('pending',b9_current()['state'],'generation pending'); b9_expect(null,b9_current()['expires_at'],'pending no clock');
b9_expect(true,password_verify($g['code'],b9_current()['verifier_hash']),'secure verifier matches');
b9_expect(false,str_contains(json_encode(b9_current()),$g['code']),'database no plaintext');
b9_expect(false,str_contains(json_encode(staff_overview($pdo,$adminA)),$g['code']),'reload no plaintext');
b9_expect(false,str_contains(hex2bin(explode('.',$g['proof'])[0]),$g['code']),'proof excludes code');
b9_refuse(fn()=>staff_register($pdo,b9_fields($g['code'],'PENDING')));
foreach ([['confirmed'=>'0'],['proof'=>'forged'],['proof'=>null],['generation_id'=>str_repeat('a',32)]] as $changes) b9_refuse(fn()=>b9_ack($g,$changes));
b9_refuse(fn()=>b9_ack($g,[],$adminB));
b9_expect(false,staff_verify_proof($g['proof'],$g['row']['generation_id'],90,str_repeat('b',32),$key),'stale context proof');
$payload=bin2hex(json_encode([$g['row']['generation_id'],90,$context,time()-601,'expired-test-proof']));
b9_expect(false,staff_verify_proof($payload.'.'.hash_hmac('sha256',$payload,$key),$g['row']['generation_id'],90,$context,$key),'expired signed proof');
b9_expect(false,staff_verify_proof($g['proof'],$g['row']['generation_id'],90,$context,''),'missing signing key');
$lost=$g; $g=b9_generate(); $secrets[]=$g['code']; b9_refuse(fn()=>b9_ack($lost));
b9_refuse(fn()=>staff_register($pdo,b9_fields($lost['code'],'LOST'))); b9_ack($g);
b9_expect(24,(int)$pdo->query('SELECT TIMESTAMPDIFF(HOUR,acknowledged_at,expires_at) FROM staff_registration_code')->fetchColumn(),'acknowledgment clock exactly 24h');
b9_refuse(fn()=>b9_ack($g)); b9_refuse(fn()=>b9_generate());
foreach (['wrong','fixture-code','',str_repeat('a',200),['array'],"\xff"] as $bad) {
    $before=b9_current(); b9_refuse(fn()=>staff_register($pdo,array_replace(b9_fields($g['code'],'BAD'),['registration_code'=>$bad])));
    b9_expect($before,b9_current(),'invalid/static/encoding code preserves singleton');
}
$wrong=implode('-',array_fill(0,8,'00000')); $before=b9_current();
b9_refuse(fn()=>staff_register($pdo,b9_fields($wrong,'WRONG'))); b9_expect($before,b9_current(),'well-formed wrong code unchanged');
foreach ([['email'=>$adminA['email']],['email'=>'a@disallowed.invalid'],['password'=>'short'],['confirm_password'=>'different'],['fullname'=>''],['employee_id'=>'invalid id'],['employee_id'=>'B9-92']] as $changes) {
    $before=b9_current(); b9_refuse(fn()=>staff_register($pdo,array_replace(b9_fields($g['code'],'FAIL'),$changes))); b9_expect($before,b9_current(),'account failure preserves code');
}
$before=b9_current();b9_refuse(fn()=>staff_register($pdo,array_replace(b9_fields($g['code'],'SECRET-FIELD'),['fullname'=>$g['code']])));
b9_expect($before,b9_current(),'credential copied into identity field rejected without audit or consume');
$user=staff_register($pdo,b9_fields($g['code'],'SUCCESS')+['role'=>'student','status'=>'Inactive']);
b9_expect(['admin','Active',0],[$user['role'],$user['status'],(int)$user['must_change_password']],'new Admin state');
b9_expect(true,onboarding_complete($pdo,$user),'Admin profile complete');
b9_expect(2,count(legal_outstanding($pdo,(int)$user['id'])),'legal acceptance required');
try { legal_manage($pdo,$user,['action'=>'create','policy'=>'privacy']);throw new RuntimeException('Unaccepted Admin must not manage policies.'); }
catch (LegalPolicyError $e) { b9_expect(403,$e->status,'new unaccepted Admin legal governance denied'); }
b9_expect('consumed',b9_current()['state'],'consumed'); b9_expect(null,b9_current()['verifier_hash'],'consume erases verifier');
b9_expect((int)$user['id'],(int)b9_current()['consumed_by_user_id'],'consumption binds user');
b9_refuse(fn()=>staff_register($pdo,b9_fields($g['code'],'REUSE')));
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user['id']]); b9_expect(null,b9_current()['consumed_by_user_id'],'consumer FK SET NULL');
// Force mandatory audit failure after a native user insertion; neither user nor consumption survives.
$g=b9_generate();b9_ack($g);$before=b9_current();
$failing=new class('mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]) extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if (str_starts_with($query,'INSERT INTO activity_logs')) throw new RuntimeException('Isolated audit failure.');
        return parent::prepare($query,$options);
    }
};
$failing->exec("SET time_zone='+08:00'");b9_refuse(fn()=>staff_register($failing,b9_fields($g['code'],'ROLLBACK')));
b9_expect($before,b9_current(),'audit failure rolls back consumption');b9_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='ROLLBACK'")->fetchColumn(),'audit failure rolls back inserted user');
staff_manage($pdo,$adminA,['action'=>'invalidate','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1'],$context,$key);
$g=b9_generate();b9_ack($g);$pdo->exec("UPDATE staff_registration_code SET expires_at=DATE_ADD(NOW(),INTERVAL 1 SECOND)");
$slow=new class('mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]) extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if (str_starts_with($query,'INSERT INTO users')) usleep(2100000);
        return parent::prepare($query,$options);
    }
};
$slow->exec("SET time_zone='+08:00'");b9_refuse(fn()=>staff_register($slow,b9_fields($g['code'],'LATE-EXPIRY')));
b9_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='LATE-EXPIRY'")->fetchColumn(),'expiry while locked rolls back inserted user');
b9_expect('expired',staff_overview($pdo,$adminA)['state'],'late expiry normalizes safely');
$g=b9_generate();$secrets[]=$g['code'];b9_ack($g);$pdo->exec("UPDATE staff_registration_code SET expires_at=NOW()");
b9_refuse(fn()=>staff_register($pdo,b9_fields($g['code'],'EXPIRED')));b9_expect('expired',b9_current()['state'],'expiry normalization');b9_expect(null,b9_current()['verifier_hash'],'expiry erases verifier');
$g=b9_generate();$secrets[]=$g['code'];
b9_refuse(fn()=>staff_manage($pdo,$adminA,['action'=>'invalidate','generation_id'=>$g['row']['generation_id']],$context,$key));
staff_manage($pdo,$adminA,['action'=>'invalidate','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1'],$context,$key);
b9_expect(null,b9_current()['verifier_hash'],'invalidation erases verifier');b9_refuse(fn()=>staff_register($pdo,b9_fields($g['code'],'INVALIDATED')));
$g=b9_generate();$pdo->exec("UPDATE users SET status='Inactive' WHERE id=90");
b9_expect('invalidated',staff_overview($pdo,$adminB)['state'],'pending creator inactive');b9_refuse(fn()=>b9_ack($g));$pdo->exec("UPDATE users SET status='Active' WHERE id=90");
$g=b9_generate();
lifecycle_execute($pdo,$adminA,['accountType'=>'admin','action'=>'archive','targetId'=>90,'currentPassword'=>'Batch9 fixture password','confirmation'=>'ARCHIVE','confirmed'=>true]);
b9_expect('invalidated',b9_current()['state'],'existing archive atomically discards pending');
$pdo->exec("UPDATE users SET status='Active' WHERE id=90");b9_refuse(fn()=>b9_ack($g));
$g=b9_generate();$before=b9_current();$activeIds=array_column(lifecycle_rows($pdo,"SELECT id FROM users WHERE role='admin' AND status='Active'"),'id');
$pdo->exec("UPDATE users SET status='Inactive' WHERE role='admin' AND id<>90");
try { lifecycle_execute($pdo,$adminA,['accountType'=>'admin','action'=>'archive','targetId'=>90,'currentPassword'=>'Batch9 fixture password','confirmation'=>'ARCHIVE','confirmed'=>true]);throw new RuntimeException('Last Admin archive must fail.'); }
catch (AccountLifecycleConflict $e) { b9_expect($before,b9_current(),'last Admin safeguard leaves pending code untouched'); }
foreach ($activeIds as $id) $pdo->prepare("UPDATE users SET status='Active' WHERE id=?")->execute([$id]);
staff_manage($pdo,$adminA,['action'=>'invalidate','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1'],$context,$key);
$pdo->exec("INSERT INTO users(id,username,ref_id,full_name,email,role,status,password_hash,must_change_password) SELECT 96,'B9-96','B9-96','Pending Creator','b9-96@example.invalid','admin','Active',password_hash,0 FROM users WHERE id=90");
$pendingCreator=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=96')[0];legal_accept($pdo,$pendingCreator,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
$purgedGeneration=staff_manage($pdo,$pendingCreator,['action'=>'generate','generation_id'=>b9_current()['generation_id']],$context,$key);
lifecycle_execute($pdo,$pendingCreator,['accountType'=>'admin','action'=>'permanent_delete','targetId'=>96,'currentPassword'=>'Batch9 fixture password','confirmation'=>'DELETE','confirmed'=>true]);
b9_expect('invalidated',b9_current()['state'],'existing creator purge atomically invalidates pending');b9_expect(null,b9_current()['creator_user_id'],'pending creator purge SET NULL');
$g=b9_generate(); b9_ack($g);$pdo->exec("UPDATE users SET status='Inactive' WHERE id=90");
$global=staff_register($pdo,b9_fields($g['code'],'GLOBAL'));b9_expect('admin',$global['role'],'acknowledged global after creator inactive');$pdo->exec("UPDATE users SET status='Active' WHERE id=90");
// Production services race across independent native connections.
$g=b9_generate(); b9_ack($g);$before=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$race=b9_race([['operation'=>'register','data'=>b9_fields($g['code'],'RACE-A')],['operation'=>'register','data'=>b9_fields($g['code'],'RACE-B')]]);
b9_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'exactly one consumption winner');b9_expect($before+1,(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'exactly one new user');
$g=b9_generate();$race=b9_race(array_fill(0,2,['operation'=>'manage','data'=>['action'=>'generate','generation_id'=>$g['row']['generation_id']]]));
b9_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'replacement stale generation race');b9_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM staff_registration_code')->fetchColumn(),'singleton count');
$g=b9_generate();$race=b9_race([['operation'=>'manage','data'=>['action'=>'acknowledge','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1','proof'=>$g['proof']]],
    ['operation'=>'manage','data'=>['action'=>'invalidate','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1']]]);
b9_expect('invalidated',b9_current()['state'],'acknowledge invalidate race final invalidated');b9_expect(null,b9_current()['verifier_hash'],'race no verifier resurrection');
$g=b9_generate();b9_ack($g);$race=b9_race([['operation'=>'register','data'=>b9_fields($g['code'],'EXPIRY-RACE')],['operation'=>'expire']]);
b9_expect(in_array(b9_current()['state'],['consumed','expired','acknowledged'],true),true,'expiry race legal serialized outcome');
if (b9_current()['state']==='acknowledged') staff_overview($pdo,$adminA);
b9_expect(in_array(b9_current()['state'],['consumed','expired'],true),true,'expiry final no usable verifier');
$g=b9_generate();b9_ack($g);$race=b9_race(array_fill(0,2,['operation'=>'register','data'=>b9_fields($g['code'],'UNIQUE-RACE')]));
b9_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'account uniqueness race one winner');
$g=b9_generate();$race=b9_race([['operation'=>'manage','data'=>['action'=>'acknowledge','generation_id'=>$g['row']['generation_id'],'confirmed'=>'1','proof'=>$g['proof']]],['operation'=>'deactivate']]);
$pdo->exec("UPDATE users SET status='Active' WHERE id=90");
// Purge after acknowledgment retains the global generation through frozen SET NULL.
if (b9_current()['state']==='acknowledged') staff_manage($pdo,$adminB,['action'=>'invalidate','generation_id'=>b9_current()['generation_id'],'confirmed'=>'1'],$context,$key);
$g=staff_manage($pdo,$adminB,['action'=>'generate','generation_id'=>b9_current()['generation_id']],$context,$key);b9_ack($g,[],$adminB);
$pdo->exec('DELETE FROM users WHERE id=91');b9_expect(null,b9_current()['creator_user_id'],'creator FK SET NULL');
staff_register($pdo,b9_fields($g['code'],'PURGED-CREATOR'));b9_expect('consumed',b9_current()['state'],'global usable after creator purge');
foreach ($secrets as $secret) foreach (['staff_registration_code','activity_logs','notifications','notification_campaigns'] as $table) {
    b9_expect(false,str_contains(json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll()),$secret),'secret absent from persistent rows');
}
require __DIR__.'/v10-batch9-http.php';
lifecycle_compare_schema($pdo);
echo 'PASS: '.($GLOBALS['nativeChecks']-$b9Start)." Batch 9 native security, service, concurrency and HTTP assertions; frozen schema parity.\n";
