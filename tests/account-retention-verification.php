<?php
/** Actual schema grants, runtime revalidation and both explicit verifier modes. */
$verificationStart=$GLOBALS['checks'];$scopedSecret=bin2hex(random_bytes(24));
$pdo->exec("CREATE USER 'retention_scoped'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($scopedSecret));
$pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE,TRIGGER ON hardening_test_lifecycle.* TO 'retention_scoped'@'127.0.0.1'");
$scoped=new PDO($faultDsn,'retention_scoped',$scopedSecret,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(in_array('test',$scoped->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN),true))$pdo->exec('DROP DATABASE test');
try { lifecycle_privileged_verification($scoped);throw new RuntimeException('Scoped user passed global verifier'); }
catch(AccountLifecycleConflict $e) { reset_migration_expect(true,str_contains($e->getMessage(),'Complete server'),'No global-to-scoped fallback'); }
try { lifecycle_shared_hosting_verification($scoped);throw new RuntimeException('Scoped external assurance omitted'); }
catch(AccountLifecycleConflict $e) { reset_migration_expect(true,str_contains($e->getMessage(),'external'),'Explicit external assurance required'); }
$evidence=lifecycle_shared_hosting_verification($scoped,true);
reset_migration_expect(false,$evidence['complete_visibility'],'Scoped mode never claims global visibility');
reset_migration_expect('schema_scoped_shared_hosting',$evidence['verification_mode'],'Explicit scoped mode');
reset_migration_expect(86400,$evidence['expires_at']-$evidence['issued_at'],'24-hour lifetime preserved');
$scopedFixture=['dbUser'=>'retention_scoped','password'=>$scopedSecret,'verification'=>$evidence];
foreach (['student','adviser'] as $type) {
    retention_test_reset();$r=retention_worker_finish(retention_worker_start(retention_test_input($type),1,$scopedFixture));
    reset_migration_expect(200,$r['status'],'Controlled '.$type.' purge works with genuine schema-only grants');
}
$bad=[];
foreach (['db','host','port','server_id','version'] as $key) {$v=$evidence;$v['database'][$key]='wrong';$bad['binding '.$key]=$v;}
$bad+=[
    'expired'=>array_replace($evidence,['issued_at'=>time()-86401,'expires_at'=>time()-1]),
    'future'=>array_replace($evidence,['issued_at'=>time()+3600,'expires_at'=>time()+90000]),
    'extended'=>array_replace($evidence,['expires_at'=>time()+172800]),
    'hash'=>array_replace($evidence,['manifest_sha256'=>str_repeat('0',64)]),
    'global claim'=>array_replace($evidence,['complete_visibility'=>true]),
    'no external exclusion'=>array_replace($evidence,['external_dependencies_excluded'=>false]),
    'old evidence'=>array_replace($evidence,['evidence_version'=>1]),
    'wrong mode'=>array_replace($evidence,['verification_mode'=>'unknown']),
    'DDL not excluded'=>array_replace($evidence,['schema_changes_excluded'=>false]),
    'visibility'=>array_replace($evidence,['visibility'=>[]])];
foreach ($bad as $label=>$v) {
    retention_test_reset();$r=retention_worker_finish(retention_worker_start(retention_test_input(),1,array_replace($scopedFixture,['verification'=>$v])));
    reset_migration_expect(409,$r['status'],'Verification refused: '.$label);
    reset_migration_expect(1,(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),'Invalid evidence preserves profile');
}
retention_test_reset();$pdo->exec('ALTER TABLE students ADD COLUMN retention_unreviewed INT NULL');
$r=retention_worker_finish(retention_worker_start(retention_test_input(),1,$scopedFixture));reset_migration_expect(409,$r['status'],'Runtime schema deviation refused');$pdo->exec('ALTER TABLE students DROP COLUMN retention_unreviewed');
// A visible external dependency is rejected before mutation; no cascade is ever exercised.
$pdo->exec('CREATE DATABASE retention_external');$pdo->exec('CREATE TABLE retention_external.reference_row (id INT PRIMARY KEY,user_id INT,FOREIGN KEY (user_id) REFERENCES hardening_test_lifecycle.users(id) ON DELETE CASCADE) ENGINE=InnoDB');
$r=retention_worker_finish(retention_worker_start(retention_test_input()));reset_migration_expect(409,$r['status'],'Global reviewed manifest rejects external CASCADE reference');$pdo->exec('DROP DATABASE retention_external');
$pdo->exec("REVOKE TRIGGER ON hardening_test_lifecycle.* FROM 'retention_scoped'@'127.0.0.1'");
$r=retention_worker_finish(retention_worker_start(retention_test_input(),1,$scopedFixture));reset_migration_expect(409,$r['status'],'Runtime scope/grant revalidation');
$pdo->exec("GRANT TRIGGER ON hardening_test_lifecycle.* TO 'retention_scoped'@'127.0.0.1'");
// Both read-only operator CLI entry points, including real mode-labelled evidence output.
// This earlier-batch suite exercises v9; keep the actual v10 verifier unchanged.
$v9VerifierRoot=$root.'/v9-verifier';mkdir($v9VerifierRoot);mkdir($v9VerifierRoot.'/tools');mkdir($v9VerifierRoot.'/includes');
copy(__DIR__.'/../tools/verify-account-lifecycle-schema.php',$v9VerifierRoot.'/tools/verify-account-lifecycle-schema.php');
copy(__DIR__.'/../includes/account_lifecycle_schema.php',$v9VerifierRoot.'/includes/account_lifecycle_schema.php');
copy(__DIR__.'/../includes/account_identity.php',$v9VerifierRoot.'/includes/account_identity.php');
copy(__DIR__.'/../tools/schema-v9-contract.json',$v9VerifierRoot.'/includes/account_lifecycle_schema.json');
foreach ([['root',$password,'--verify'],['retention_scoped',$scopedSecret,'--verify-shared-hosting']] as [$name,$secret,$mode]) {
    $environment=array_merge(getenv(),['PRISM_SCHEMA_VERIFY_HOST'=>'127.0.0.1','PRISM_SCHEMA_VERIFY_PORT'=>(string)$port,
        'PRISM_SCHEMA_VERIFY_DATABASE'=>'hardening_test_lifecycle','PRISM_SCHEMA_VERIFY_USER'=>$name,'PRISM_SCHEMA_VERIFY_PASSWORD'=>$secret]);
    $args=[PHP_BINARY,$v9VerifierRoot.'/tools/verify-account-lifecycle-schema.php',$mode,'--schema-changes-excluded'];if($mode==='--verify-shared-hosting')$args[]='--external-dependencies-excluded';
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    reset_migration_expect(0,proc_close($process),'Actual verifier CLI '.$mode.': '.$err);
    reset_migration_expect(true,str_contains($out,"define('PRISM_HARD_DELETE_VERIFICATION'"),'CLI emits bound evidence');
    reset_migration_expect(false,str_contains($out.$err,$secret),'CLI never prints credentials');
}
echo 'PASS: real global/scoped verification checks '.($GLOBALS['checks']-$verificationStart).'; cumulative '.$GLOBALS['checks'].".\n";
