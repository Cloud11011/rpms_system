<?php
/** Run through v10-batch7-native.php --only105 --batch105; fresh frozen-v10 fixture only. */
if(!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$start105=$GLOBALS['nativeChecks']; $results105=[];
function b105_expect(mixed $expected,mixed $actual,string $label): void {
    reset_migration_expect($expected,$actual,'B10.5 '.$label);
    $GLOBALS['results105'][]=$label;
}
function b105_call(array $evidence,array $options=[]): array {
    global $root,$datadir,$port,$password;
    $case=array_replace(['dsn'=>'mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4',
        'datadir'=>$datadir,'password'=>$password,'evidence'=>$evidence],$options);
    $file=$root.'/case-'.bin2hex(random_bytes(6)).'.json'; file_put_contents($file,json_encode($case,JSON_THROW_ON_ERROR));
    $process=proc_open([PHP_BINARY,__DIR__.'/v10-batch105-worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit=proc_close($process); unlink($file);
    if($exit!==0 || $err!=='') throw new RuntimeException('Private API worker failed (exit '.$exit.'): '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function b105_input(string $type='student'): array {
    return ['action'=>'permanent_delete','accountType'=>$type,'targetId'=>$type==='student'?903:904,
        'currentPassword'=>'Persistent fixture password','confirmation'=>$type==='student'?'S903':'A904','confirmed'=>true];
}
$hash105=password_hash('Persistent fixture password',PASSWORD_DEFAULT);
foreach([[901,'admin','ADMIN901'],[902,'admin','ADMIN902'],[903,'student','S903'],[904,'adviser','A904']] as [$id,$role,$identifier]) {
    $pdo->prepare('INSERT INTO users(id,username,password_hash,full_name,email,role,status,ref_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id,$identifier,$hash105,'Fixture '.$id,'fixture'.$id.'@example.invalid',$role,$role==='admin'?'Active':'Inactive',$identifier]);
}
$pdo->exec("INSERT INTO students(id,student_id,full_name,email,user_id,archived_at,profile_completed_at) VALUES (903,'S903','Fixture 903','fixture903@example.invalid',903,DATE_SUB(NOW(),INTERVAL 8 DAY),NOW())");
$pdo->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id,status,archived_at,profile_completed_at) VALUES (904,'A904','Fixture 904','fixture904@example.invalid',904,'Inactive',DATE_SUB(NOW(),INTERVAL 8 DAY),NOW())");
$global=PRISM_HARD_DELETE_VERIFICATION;
b105_expect(3,$global['evidence_version'],'persistent format version');
b105_expect(false,array_key_exists('expires_at',$global),'no expiry field');
$secret105=bin2hex(random_bytes(24));
$pdo->exec("CREATE USER 'persistent_scoped'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($secret105));
$pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON b7_fixture.* TO 'persistent_scoped'@'127.0.0.1'");
if(in_array('test',$pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN),true)) $pdo->exec('DROP DATABASE test');
$scoped105=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture','persistent_scoped',$secret105,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$scoped=lifecycle_shared_hosting_verification($scoped105,true);
$scopedOptions=['dbUser'=>'persistent_scoped','password'=>$secret105];
$metrics105=[];
foreach(['global'=>$global,'scoped'=>$scoped] as $mode=>$evidence) {
    $options=$mode==='global'?[]:$scopedOptions;
    foreach([0,1,7,30,180,365] as $days) {
        // Deterministic simulated age: unchanged current clock/environment, signed historical issue time.
        $historical=$evidence; $historical['issued_at']=1700000000-$days*86400; $historical=lifecycle_authenticate_evidence($historical);
        $r=b105_call($historical,$options);
        b105_expect(true,$r['data']['available'],$mode.' valid after simulated '.$days.' days');
        if($days===0) $metrics105[$mode]=['queries'=>$r['queries']-1,'milliseconds'=>$r['milliseconds']];
    }
    $bad=['empty'=>[],'unsupported version'=>array_replace($evidence,['evidence_version'=>99]),
        'legacy v2'=>array_replace($evidence,['evidence_version'=>2,'expires_at'=>time()+86400]),
        'signature'=>array_replace($evidence,['authenticator'=>str_repeat('0',64)]),
        'corrupt'=>array_replace($evidence,['manifest_sha256'=>str_repeat('0',64)]),
        'truncated'=>array_diff_key($evidence,['database'=>true]),
        'unauthenticated'=>array_diff_key($evidence,['authenticator'=>true])];
    $signedBad=[
        'manifest'=>array_replace($evidence,['manifest_sha256'=>str_repeat('0',64)]),
        'contract'=>array_replace($evidence,['verification_contract_sha256'=>str_repeat('0',64)]),
        'expiry field'=>array_replace($evidence,['expires_at'=>1]),
        'unknown field'=>array_replace($evidence,['unexpected'=>true]),
        'invalid issue type'=>array_replace($evidence,['issued_at'=>'1700000000']),
        'policy'=>array_replace($evidence,['validity_policy'=>'time']),
        'DDL assurance'=>array_replace($evidence,['schema_changes_excluded'=>false])];
    foreach(['db','host','port','server_id','version'] as $field) {
        $v=$evidence;$v['database'][$field]='wrong';$signedBad['database '.$field]=$v;
    }
    foreach($signedBad as $label=>$v) $bad['authenticated mismatch '.$label]=lifecycle_authenticate_evidence($v);
    foreach($bad as $label=>$v) {
        $r105=b105_call($v,$options);
        b105_expect(false,$r105['data']['available'],$mode.' rejects '.$label);
        $r=b105_call($v,array_replace($options,['mode'=>'delete','data'=>b105_input()+['verification'=>true,'available'=>true]]));
        b105_expect(409,$r['status'],$mode.' direct API rejects '.$label);
        b105_expect('Permanent deletion is unavailable because deployment verification is required. Contact the system operator.',$r['data']['message'],$mode.' sanitized refusal '.$label);
    }
    foreach([['missing'=>true],['enabled'=>false],['key'=>str_repeat('b',64)],['key'=>''],['fkChecks'=>0],['metadataError'=>true]] as $override) {
        b105_expect(false,b105_call($evidence,array_replace($options,$override))['data']['available'],$mode.' gate rejects '.key($override));
    }
}
echo "PASS: persistent evidence/authentication/time matrix; accounts preserved.\n";
b105_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students WHERE id=903')->fetchColumn(),'all invalid evidence preserved account');
// Negative schema mutations: only private fixtures, each restored before the next check.
$mutations=[
    ['missing column','ALTER TABLE students DROP COLUMN research_title','ALTER TABLE students ADD COLUMN research_title VARCHAR(255) NULL AFTER email'],
    ['column type','ALTER TABLE students MODIFY research_title VARCHAR(254) NULL','ALTER TABLE students MODIFY research_title VARCHAR(255) NULL'],
    ['required index','ALTER TABLE students DROP INDEX student_profile_completion','ALTER TABLE students ADD KEY student_profile_completion(profile_completed_at)'],
    ['missing FK','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1','ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE SET NULL ON UPDATE RESTRICT'],
    ['FK delete rule','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE RESTRICT ON UPDATE RESTRICT','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE SET NULL ON UPDATE RESTRICT'],
    ['FK update rule','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE SET NULL ON UPDATE CASCADE','ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;ALTER TABLE students ADD CONSTRAINT students_ibfk_1 FOREIGN KEY(adviser_id) REFERENCES advisers(id) ON DELETE SET NULL ON UPDATE RESTRICT'],
    ['trigger','CREATE TRIGGER persistent_unexpected BEFORE DELETE ON students FOR EACH ROW SET @fixture=1','DROP TRIGGER persistent_unexpected']];
foreach($mutations as [$label,$introduce,$restore]) {
    b105_expect(true,b105_call($global)['data']['available'],'pre-mutation availability '.$label);
    foreach(explode(';',$introduce) as $sql) $pdo->exec($sql);
    foreach([[$global,[]],[$scoped,$scopedOptions]] as [$evidence,$options]) {
        b105_expect(false,b105_call($evidence,$options)['data']['available'],'live drift '.$label);
        b105_expect(409,b105_call($evidence,array_replace($options,['mode'=>'delete','data'=>b105_input()]))['status'],'stale availability cannot delete '.$label);
    }
    foreach(explode(';',$restore) as $sql) $pdo->exec($sql);
    lifecycle_compare_schema($pdo);
}
foreach(['TRIGGER','SELECT'] as $privilege) {
    $pdo->exec("GRANT SELECT ON b7_fixture.users TO 'persistent_scoped'@'127.0.0.1'");
    $pdo->exec("REVOKE $privilege ON b7_fixture.* FROM 'persistent_scoped'@'127.0.0.1'");
    foreach([$global,$scoped] as $evidence) b105_expect(false,b105_call($evidence,$scopedOptions)['data']['available'],'lost '.$privilege.' visibility');
    $pdo->exec("GRANT $privilege ON b7_fixture.* TO 'persistent_scoped'@'127.0.0.1'");
    $pdo->exec("REVOKE SELECT ON b7_fixture.users FROM 'persistent_scoped'@'127.0.0.1'");
}
$pdo->exec('CREATE DATABASE persistent_external');
$pdo->exec("GRANT SELECT,TRIGGER ON persistent_external.* TO 'persistent_scoped'@'127.0.0.1'");
$pdo->exec('CREATE TABLE persistent_external.incoming(id INT PRIMARY KEY,student_id INT,FOREIGN KEY(student_id) REFERENCES b7_fixture.students(id) ON DELETE CASCADE) ENGINE=InnoDB');
foreach([[$global,[]],[$scoped,$scopedOptions]] as [$evidence,$options]) {
    b105_expect(false,b105_call($evidence,$options)['data']['available'],'visible external dependency');
    b105_expect(409,b105_call($evidence,array_replace($options,['mode'=>'delete','data'=>b105_input()]))['status'],'direct delete refuses external dependency');
}
$pdo->exec('DROP DATABASE persistent_external');
$pdo->exec("REVOKE SELECT,TRIGGER ON persistent_external.* FROM 'persistent_scoped'@'127.0.0.1'");
echo "PASS: live column/index/FK/trigger/dependency/visibility drift matrix.\n";
// Genuine read-only verifier CLI: signed evidence only on success; secrets never emitted.
foreach([['root',$password,'--verify'],['persistent_scoped',$secret105,'--verify-shared-hosting']] as [$user,$pass,$mode]) {
    $environment=array_merge(getenv(),['PRISM_SCHEMA_VERIFY_HOST'=>'127.0.0.1','PRISM_SCHEMA_VERIFY_PORT'=>(string)$port,
        'PRISM_SCHEMA_VERIFY_DATABASE'=>'b7_fixture','PRISM_SCHEMA_VERIFY_USER'=>$user,'PRISM_SCHEMA_VERIFY_PASSWORD'=>$pass,
        'PRISM_SCHEMA_VERIFY_KEY'=>PRISM_HARD_DELETE_VERIFICATION_KEY]);
    $args=[PHP_BINARY,__DIR__.'/../tools/verify-account-lifecycle-schema.php',$mode,'--schema-changes-excluded'];if($mode==='--verify-shared-hosting')$args[]='--external-dependencies-excluded';
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    b105_expect(0,proc_close($process),'real CLI '.$mode.' '.$err);
    b105_expect(true,str_contains($out,"'evidence_version' => 3") && str_contains($out,"'authenticator'"),'CLI persistent authenticated format');
    b105_expect(false,str_contains($out.$err,$pass) || str_contains($out.$err,PRISM_HARD_DELETE_VERIFICATION_KEY),'CLI no password or signing key');
}
// Availability is never an account authorization: retain all current actor/account rules.
foreach([['Hold',"UPDATE students SET retention_hold=1 WHERE id=903","UPDATE students SET retention_hold=0 WHERE id=903"],
    ['grace',"UPDATE students SET archived_at=NOW() WHERE id=903","UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=903"],
    ['active',"UPDATE students SET archived_at=NULL WHERE id=903","UPDATE students SET archived_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=903"]] as [$label,$sql,$restore]) {
    b105_expect(true,b105_call($global)['data']['available'],'deployment available before '.$label);
    $pdo->exec($sql);b105_expect(409,b105_call($global,['mode'=>'delete','data'=>b105_input()])['status'],'account safeguard '.$label);$pdo->exec($restore);
}
$input=b105_input();$input['action']='retention_cleanup';
b105_expect(409,b105_call($global,['mode'=>'delete','data'=>$input])['status'],'six-calendar-month retention unchanged');
b105_expect(true,b105_call($global)['data']['available'],'availability before actor deactivation');
$pdo->exec("UPDATE users SET status='Inactive' WHERE id=901");
b105_expect(403,b105_call($global,['mode'=>'delete','data'=>b105_input()])['status'],'inactive actor after availability refused');
$pdo->exec("UPDATE users SET status='Active' WHERE id=901");
$input=b105_input();$input['currentPassword']='wrong';
b105_expect(422,b105_call($global,['mode'=>'delete','data'=>$input])['status'],'current password required');
foreach(['student','adviser'] as $type) {
    b105_expect(200,b105_call($scoped,array_replace($scopedOptions,['mode'=>'delete','data'=>b105_input($type)]))['status'],'valid scoped actual '.$type.' purge');
}
$admin=['action'=>'permanent_delete','accountType'=>'admin','targetId'=>901,'currentPassword'=>'Persistent fixture password','confirmation'=>'DELETE','confirmed'=>true];
b105_expect(200,b105_call($global,['mode'=>'delete','data'=>$admin])['status'],'valid global Admin self-delete');
$result=['assertions'=>$GLOBALS['nativeChecks']-$start105,'metrics'=>$metrics105,'checks'=>$results105];
file_put_contents(__DIR__.'/v10-batch105-results.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
echo 'PASS: '.$result['assertions'].' persistent evidence, live drift, direct API and retention assertions. Metrics: '.json_encode($metrics105)."\n";
