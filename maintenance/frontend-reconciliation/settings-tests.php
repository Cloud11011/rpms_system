<?php
/** Disposable Settings/schema16 scenarios. Datadir-bound through bootstrap; never loads deployed configuration. */
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/current_workflow_eligibility.php';
require_once $root.'/includes/schema_v16.php';
require_once $root.'/includes/admin_settings_schema.php';
require_once $root.'/includes/admin_settings_store.php';
require_once $root.'/includes/academic_years.php';
require_once $root.'/includes/schema_research_onboarding.php';
require_once $root.'/includes/runtime_preflight.php';
require_once $root.'/includes/academic_catalog.php';
require_once $root.'/includes/notification_worker.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$database='prism_group_synthetic_v11';
foreach (['SCHEMA_V15','SCHEMA_V16'] as $name) { putenv('PRISM_ALLOW_'.$name.'_MIGRATION=1'); putenv('PRISM_'.$name.'_EXPECT_DB='.$database); }
foreach (['SETTINGS','RESEARCH_ONBOARDING'] as $name) { putenv('PRISM_ALLOW_'.$name.'_MIGRATION=1'); putenv('PRISM_'.$name.'_EXPECT_DB='.$database); }
$sqlDir=$root.'/PRISM_V14_FRONTEND_RECONCILIATION/schema-16';
$admin=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
$results=[]; $failed=0;
$check=function(string $name,bool $ok,mixed $detail=null)use(&$results,&$failed){ $results[]=['name'=>$name,'ok'=>$ok]+($detail===null?[]:['detail'=>$detail]); if(!$ok)$failed++; };
$throws=function(callable $fn,string $class)use($pdo): ?string { try{$fn();}catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); return $e instanceof $class?get_class($e).': '.$e->getMessage():'WRONG '.get_class($e).': '.$e->getMessage(); } return null; };
$version=fn()=>(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
$marker=fn(string $k)=>$pdo->query("SELECT v FROM schema_meta WHERE k='".$k."'")->fetchColumn();
$tables=fn()=>$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$assignments=fn()=>hash('sha256',json_encode($pdo->query('SELECT id,academic_year,program_key,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
$audits=fn(string $action)=>(int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action=".$pdo->quote($action))->fetchColumn();
$fresh=function()use($database): PDO {
    $c=new PDO('mysql:host=127.0.0.1;port='.PRISM_TEST_DB_PORT.';dbname='.$database.';charset=utf8mb4','root','prism_synthetic_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    return $c;
};
$verified=function(string $label,callable $fn)use($check){ try{$fn();$check($label,true);}catch(Throwable $e){$check($label,false,get_class($e).': '.$e->getMessage());} };
$sql=function(string $file)use($sqlDir,$database): array {
    $bin=rtrim(str_replace('\\','/',getenv('PRISM_TEST_MARIADB_BIN')?:'C:/xampp/mysql/bin'),'/');
    $client=is_file($bin.'/mariadb.exe')?$bin.'/mariadb.exe':$bin.'/mysql.exe';
    $p=proc_open([$client,'--protocol=TCP','--host=127.0.0.1','--port='.PRISM_TEST_DB_PORT,'--user=root','--password=prism_synthetic_only',
        '--default-character-set=utf8mb4','--batch','--database='.$database],[0=>['file',$sqlDir.'/'.$file,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['exit'=>proc_close($p),'out'=>$out,'err'=>trim($err)];
};
$verifyOverall=function(array $run): ?string { return preg_match('/^overall\t(\S+)/m',$run['out'],$m)?$m[1]:null; };

/** Schema15 behavior without any optional storage: catalog stays registerable, settings read defaults, writes refuse. */
$base15=function()use($pdo,$admin,$check,$throws,$version,$verified,$fresh){
    $check('starts at schema 15',$version()==='15');
    $verified('schema15 exact contract',fn()=>schema_v15_verify($pdo));
    $check('schema15: academic-year management unavailable',!academic_year_management_available($pdo));
    $check('schema15: registration years equal shared catalog',academic_year_registration_years($pdo)===academic_years($pdo));
    $check('schema15: settings store unavailable',!prism_settings_store_available($pdo));
    $check('schema15: reminder default',prism_settings_get($pdo,'communication.deadline_reminder_intervals',[7,3,1])===[7,3,1]);
    $check('schema15: template passthrough',prism_notification_apply_template($pdo,'Review Needed','x','A','Body')==='Body');
    $r=$throws(fn()=>prism_settings_save($pdo,$admin,'communication.deadline_reminder_intervals',[5]),AccountLifecycleConflict::class);
    $check('schema15: settings save refused 409',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $r=$throws(fn()=>academic_year_set_current($pdo,$admin,academic_years($pdo)[0]??'2025-2026'),AccountLifecycleConflict::class);
    $check('schema15: set-current refused 409',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $r=$throws(fn()=>migrate_prism_admin_settings($pdo,'apply'),RuntimeException::class);
    $check('settings migrator requires schema16',$r!==null && str_contains($r,'v16'),$r);
    // The preflight makes its session read-only, so it always gets its own connection.
    $verified('runtime preflight PASS at 15',function()use($fresh){ $p=prism_runtime_database_preflight($fresh()); if(!$p['ok']||$p['contract']!=='PASS') throw new RuntimeException(json_encode($p)); });
};

$applyV16=function()use($pdo,$check,$version,$assignments,$tables,$verified,$throws){
    $before=$assignments();
    $dry=migrate_schema_v16($pdo,'dry-run');
    $check('v16 dry-run writes nothing',$version()==='15' && !in_array('academic_year_settings',$tables(),true),$dry);
    $apply=migrate_schema_v16($pdo,'apply');
    $check('v16 apply reaches 16',$version()==='16' && $apply['studentAssignmentsChanged']===0,$apply);
    $check('v16 retains student academic assignments',$assignments()===$before);
    $valid=array_values(array_filter(academic_years($pdo),'academic_year_valid'));
    $check('v16 seeds a settings row per valid catalog year',(int)$pdo->query('SELECT COUNT(*) FROM academic_year_settings')->fetchColumn()===count($valid));
    $again=migrate_schema_v16($pdo,'apply');
    $check('v16 re-apply idempotent',$version()==='16' && $again['ok']===true);
    $verified('schema16 composed exact contract',fn()=>schema_v16_verify($pdo));
    $r=$throws(fn()=>migrate_schema_v15($pdo,'apply'),RuntimeException::class);
    $check('schema15 migrator refuses a later schema',$r!==null && str_contains($r,'later schema'),$r);
};

$applySettings=function()use($pdo,$check,$marker,$verified,$fresh){
    $dry=migrate_prism_admin_settings($pdo,'dry-run');
    $check('settings dry-run writes nothing',$marker('prism_settings_v1')===false && !$dry['migrationApplied']);
    $apply=migrate_prism_admin_settings($pdo,'apply');
    $check('settings apply installs marker',$marker('prism_settings_v1')==='1' && $apply['migrationApplied']);
    $again=migrate_prism_admin_settings($pdo,'apply');
    $check('settings re-apply idempotent',$again['ok']===true);
    $verified('settings exact contract',fn()=>prism_admin_settings_schema_verify($pdo));
    $check('settings store available (fresh connection)',prism_settings_store_available($fresh()));
};

$composed=function(string $label)use($verified,$fresh){
    $verified($label.': schema16 + extensions verify',fn()=>schema_v16_verify($fresh()));
    $verified($label.': lifecycle contract',fn()=>lifecycle_compare_schema($fresh()));
    $verified($label.': runtime preflight PASS at 16',function()use($fresh){ $p=prism_runtime_database_preflight($fresh()); if(!$p['ok']||$p['contract']!=='PASS') throw new RuntimeException(json_encode($p)); });
};

$drift=function()use($pdo,$check,$fresh){
    $pdo->exec("ALTER TABLE prism_settings COMMENT='drift'");
    try { prism_admin_settings_schema_verify($fresh()); $check('settings drift detected',false); }
    catch (RuntimeException $e) { $check('settings drift detected',str_contains($e->getMessage(),'drift'),$e->getMessage()); }
    try { prism_settings_store_available($fresh()); $check('drifted store fails closed',false); }
    catch (RuntimeException $e) { $check('drifted store fails closed',true); }
    $pdo->exec("ALTER TABLE prism_settings COMMENT=''");
    $check('settings drift reverted',(bool)prism_admin_settings_schema_verify($fresh()));
    $pdo->exec('CREATE TABLE prism_unreviewed_extra (id INT PRIMARY KEY) ENGINE=InnoDB');
    try { schema_v16_verify($fresh()); $check('unknown table rejected',false); }
    catch (RuntimeException $e) { $check('unknown table rejected',true,$e->getMessage()); }
    $pdo->exec('DROP TABLE prism_unreviewed_extra');
};

$operations=function()use($pdo,$admin,$check,$throws,$assignments,$audits){
    $before=$assignments();
    $student=$pdo->query("SELECT * FROM users WHERE role='student' ORDER BY id LIMIT 1")->fetch();
    $r=$throws(fn()=>prism_settings_save($pdo,$student,'communication.deadline_reminder_intervals',[5]),AccountLifecycleForbidden::class);
    $check('non-admin settings save forbidden',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $r=$throws(fn()=>academic_year_set_current($pdo,$student,'2030-2031'),AccountLifecycleForbidden::class);
    $check('non-admin academic-year change forbidden',$r!==null && !str_starts_with($r,'WRONG'),$r);
    prism_settings_save($pdo,$admin,'communication.deadline_reminder_intervals',[14,3]);
    $check('reminder intervals persisted',prism_settings_get($pdo,'communication.deadline_reminder_intervals',[])===[14,3]);
    $n=$audits('communication_setting_updated');
    prism_settings_save($pdo,$admin,'communication.deadline_reminder_intervals',[14,3]);
    $check('unchanged save writes no audit',$audits('communication_setting_updated')===$n);
    prism_settings_update_template($pdo,$admin,'adviser_review','Dear {recipient}, {message}',prism_notification_template_defaults());
    $check('template audited',$audits('communication_setting_updated')===$n+1);
    $check('template applied',prism_notification_apply_template($pdo,'Review Needed','x','Ana','Body')==='Dear Ana, Body');
    $check('untemplated type passthrough',prism_notification_apply_template($pdo,'Other','x','Ana','Body')==='Body');
    // Academic years: add, current, availability, removal, restore. Assignments must never move.
    academic_year_add($pdo,$admin,'2031-2032');
    academic_year_add($pdo,$admin,'2032-2033');
    $check('added year registerable',in_array('2031-2032',academic_year_registration_years($pdo),true));
    academic_year_set_current($pdo,$admin,'2031-2032');
    $current=array_values(array_filter(academic_year_settings_list($pdo),fn($y)=>$y['isCurrent']));
    $check('exactly one current year',count($current)===1 && $current[0]['academicYear']==='2031-2032');
    $check('current change audited',$audits('academic_year_current_changed')===1);
    $r=$throws(fn()=>academic_year_set_availability($pdo,$admin,'2031-2032',false),AccountLifecycleConflict::class);
    $check('current year cannot be made unavailable',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $r=$throws(fn()=>academic_year_remove($pdo,$admin,'2031-2032'),AccountLifecycleConflict::class);
    $check('current year cannot be removed',$r!==null && !str_starts_with($r,'WRONG'),$r);
    academic_year_set_availability($pdo,$admin,'2032-2033',false);
    $check('unavailable year leaves registration choices',!in_array('2032-2033',academic_year_registration_years($pdo),true));
    $check('unavailable year stays in shared catalog',in_array('2032-2033',academic_years($pdo),true));
    // New registrations use only registration years; edits of existing records keep the full catalog.
    $row=$pdo->query('SELECT course,academic_unit_key,program_key,year_level,academic_year FROM students WHERE program_key IS NOT NULL ORDER BY id LIMIT 1')->fetch();
    $input=['academicUnitKey'=>$row['academic_unit_key'],'programKey'=>$row['program_key'],'yearLevel'=>$row['year_level'],'academicYear'=>'2032-2033'];
    $r=$throws(fn()=>academic_validate($input,null,true),InvalidArgumentException::class);
    $check('new registration rejects unavailable year',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $check('existing-record edit accepts catalog year',academic_validate($input,$row)['academic_year']==='2032-2033');
    $check('unchanged legacy assignment passes through',academic_validate([],$row)['academic_year']===$row['academic_year']);
    $check('catalog exposes current year',academic_catalog()['currentAcademicYear']==='2031-2032');
    academic_year_remove($pdo,$admin,'2032-2033');
    $check('removal recorded and audited',(int)$pdo->query("SELECT COUNT(*) FROM academic_year_removals WHERE year_label='2032-2033'")->fetchColumn()===1 && $audits('academic_year_removed_from_registration')===1);
    $r=$throws(fn()=>academic_year_set_current($pdo,$admin,'2032-2033'),AccountLifecycleConflict::class);
    $check('removed year cannot become current',$r!==null && !str_starts_with($r,'WRONG'),$r);
    academic_year_add($pdo,$admin,'2032-2033');
    $check('re-adding restores registration',in_array('2032-2033',academic_year_registration_years($pdo),true) && $audits('academic_year_registration_restored')===1);
    $r=$throws(fn()=>academic_year_add($pdo,$admin,'2032-2033'),AccountLifecycleConflict::class);
    $check('duplicate available year rejected',$r!==null && !str_starts_with($r,'WRONG'),$r);
    $check('student assignments unchanged by settings',$assignments()===$before);
};

/** Uses the intervals saved by $operations ([14,3]). The synthetic provider in bootstrap records deliveries. */
$reminders=function()use($pdo,$admin,$check,$verified){
    $students=$pdo->query('SELECT id FROM students WHERE profile_completed_at IS NOT NULL AND archived_at IS NULL ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    $check('two eligible synthetic Students',count($students)===2);
    $pdo->prepare("INSERT INTO calendar_deadlines(creator_user_id,title,deadline_date,target_scope) VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 3 DAY),'groups')")
        ->execute([$admin['id'],'Synthetic Form 33 deadline']);
    $deadline=(int)$pdo->lastInsertId();
    foreach ($students as $sid) $pdo->prepare('INSERT INTO calendar_deadline_recipients(deadline_id,student_id) VALUES (?,?)')->execute([$deadline,$sid]);
    $pdo->prepare('UPDATE students SET archived_at=NOW() WHERE id=?')->execute([$students[1]]);
    $queued=notification_enqueue_deadline_reminders($pdo);
    $check('reminder queued for eligible Student only',$queued===1,$queued);
    $check('archived Student skipped',(int)$pdo->query('SELECT COUNT(*) FROM prism_deadline_reminder_dispatches WHERE student_id='.(int)$students[1])->fetchColumn()===0);
    $pdo->prepare('UPDATE students SET archived_at=NULL WHERE id=?')->execute([$students[1]]);
    $dispatch=$pdo->query('SELECT * FROM prism_deadline_reminder_dispatches WHERE deadline_id='.$deadline.' AND student_id='.(int)$students[0])->fetch();
    $check('dispatch receipt links notification',$dispatch && $dispatch['notification_id']!==null && (int)$dispatch['interval_days']===3);
    $notice=$pdo->query('SELECT n.*,c.kind FROM notifications n LEFT JOIN notification_campaigns c ON c.id=n.campaign_id WHERE n.id='.(int)($dispatch['notification_id']??0))->fetch();
    $check('reminder notification typed',$notice && $notice['type']==='Deadline Reminder' && $notice['recipient_type']==='student');
    $safe=$notice?notification_student_projection($notice):[];
    $check('Student projection is safe text',($safe['subject']??'')==='Official deadline reminder'
        && str_starts_with($safe['message']??'','Reminder: Synthetic Form 33 deadline') && !str_contains($safe['message']??'','{') && !str_contains($safe['message']??'','deadline_id'),$safe);
    prism_settings_update_template($pdo,$admin,'deadline_reminder','Hi {recipient}: {message}',prism_notification_template_defaults());
    $check('reminder template applies after projection',prism_notification_apply_template($pdo,'Deadline Reminder',$safe['subject']??'','Ana',$safe['message']??'')==='Hi Ana: '.($safe['message']??''));
    $check('rerun deduplicates',notification_enqueue_deadline_reminders($pdo)===1);  // only the restored Student is new
    $check('second rerun queues nothing',notification_enqueue_deadline_reminders($pdo)===0);
    $verified('worker runs twice in one process',function()use($pdo){
        $GLOBALS['provider_calls']=[];
        foreach ([1,2] as $_) { $s=notification_worker_run($pdo,25,20); if ($s['errors']!==0) throw new RuntimeException(json_encode($s)); }
    });
    $check('worker delivered queued reminders',count($GLOBALS['provider_calls']??[])>=2,$GLOBALS['provider_calls']??null);
    $pdo->exec("UPDATE calendar_deadlines SET status='Cancelled' WHERE id=".$deadline);
    $pdo->prepare("INSERT INTO calendar_deadlines(creator_user_id,title,deadline_date,target_scope,status) VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 3 DAY),'groups','Cancelled')")
        ->execute([$admin['id'],'Cancelled synthetic deadline']);
    $cancelled=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO calendar_deadline_recipients(deadline_id,student_id) VALUES (?,?)')->execute([$cancelled,$students[0]]);
    $check('cancelled deadlines queue nothing',notification_enqueue_deadline_reminders($pdo)===0);
    $pdo->exec("UPDATE calendar_deadlines SET status='Active',deadline_date=DATE_ADD(CURDATE(),INTERVAL 14 DAY) WHERE id=".$deadline);
    $check('rescheduled deadline queues new interval',notification_enqueue_deadline_reminders($pdo)===2);
    $check('reschedule keeps earlier receipts',(int)$pdo->query('SELECT COUNT(*) FROM prism_deadline_reminder_dispatches WHERE deadline_id='.$deadline)->fetchColumn()===4);
};

$rollbackBlocked=function()use($pdo,$check,$throws,$version){
    $r=$throws(fn()=>migrate_prism_admin_settings($pdo,'rollback'),RuntimeException::class);
    $check('settings rollback blocked by retained data',$r!==null && str_contains($r,'blocked'),$r);
    $r=$throws(fn()=>migrate_schema_v16($pdo,'rollback'),RuntimeException::class);
    $check('v16 rollback blocked while settings installed',$r!==null && str_contains($r,'Settings'),$r);
    $check('blocked rollbacks keep schema16',$version()==='16');
};

$onboarding=function(string $label)use($pdo,$check,$marker){
    $r=migrate_research_onboarding($pdo,'apply');
    $check($label.': onboarding extension installed',$marker('research_onboarding_v1')==='1',$r);
};

try {
    switch ($input['scenario']??'') {
        case 'manifest':
            echo json_encode(['ok'=>true,'version'=>$version(),'tables'=>$tables(),'collation'=>schema_extension_collation($pdo)],JSON_PRETTY_PRINT); exit(0);
        case 'settings-first':
            $base15(); $applyV16(); $applySettings(); $composed('settings only'); $drift(); $operations(); $reminders(); $rollbackBlocked();
            $onboarding('after settings'); $composed('settings then onboarding');
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql reports PASS on PHP-migrated schema',$run['exit']===0 && $verifyOverall($run)==='PASS',$run);
            break;
        case 'onboarding-first':
            $onboarding('on schema15');
            $verified('onboarding on 15 verifies',fn()=>research_onboarding_schema_verify($pdo));
            $applyV16(); $applySettings(); $composed('onboarding then settings'); $operations();
            break;
        case 'rollback':
            $before=$assignments();
            $applyV16();
            // Restart: a table left by an interrupted apply (no marker) is completed, verified and marked.
            $pdo->exec(prism_admin_settings_schema_tables(schema_extension_collation($pdo))['prism_settings']);
            $r=migrate_prism_admin_settings($pdo,'apply');
            $check('settings apply resumes partial state',$marker('prism_settings_v1')==='1' && $r['migrationApplied'],$r);
            // A competing holder of the shared maintenance lock blocks migrators and Settings writers alike.
            $holder=$fresh(); $holder->query("SELECT GET_LOCK('prism_migrate',1)");
            $r=$throws(fn()=>migrate_prism_admin_settings($pdo,'dry-run'),RuntimeException::class);
            $check('migrator refuses while lock held',$r!==null && str_contains($r,'busy'),$r);
            $r=$throws(fn()=>prism_settings_save($pdo,$admin,'communication.deadline_reminder_intervals',[2]),AccountLifecycleConflict::class);
            $check('settings save refuses while lock held',$r!==null && !str_starts_with($r,'WRONG'),$r);
            $r=$throws(fn()=>academic_year_add($pdo,$admin,'2040-2041'),AccountLifecycleConflict::class);
            $check('academic-year write refuses while lock held',$r!==null && !str_starts_with($r,'WRONG'),$r);
            $holder->query("SELECT RELEASE_LOCK('prism_migrate')"); $holder=null;
            $check('nothing written while locked',prism_settings_get($pdo,'communication.deadline_reminder_intervals',null)===null
                && !in_array('2040-2041',academic_years($pdo),true));
            $r=migrate_prism_admin_settings($pdo,'rollback');
            $check('empty settings rollback',$marker('prism_settings_v1')===false && !array_intersect(['prism_settings','prism_deadline_reminder_dispatches','academic_year_removals'],$tables()),$r);
            $pdo->exec("UPDATE academic_year_settings SET is_current=1 WHERE year_label=(SELECT year_label FROM (SELECT MIN(year_label) year_label FROM academic_year_settings) x)");
            $r=$throws(fn()=>migrate_schema_v16($pdo,'rollback'),RuntimeException::class);
            $check('custom year settings block v16 rollback',$r!==null && str_contains($r,'Custom'),$r);
            $pdo->exec('UPDATE academic_year_settings SET is_current=0');
            $r=migrate_schema_v16($pdo,'rollback');
            $check('default v16 rollback returns to 15',$version()==='15' && !in_array('academic_year_settings',$tables(),true),$r);
            $verified('schema15 exact after rollback',fn()=>schema_v15_verify($pdo));
            $check('rollback retained assignments',$assignments()===$before);
            break;
        case 'sql':
            $before=$assignments(); $years=academic_years($pdo);
            $base15();
            // A pre-existing partial table must stop the script before any DDL.
            $pdo->exec('CREATE TABLE academic_year_settings (year_label VARCHAR(9) PRIMARY KEY) ENGINE=InnoDB');
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL refuses partial prior state',$run['exit']!==0 && str_contains($run['err'],'PRISM ABORT') && $version()==='15'
                && count(array_intersect(['prism_settings','prism_deadline_reminder_dispatches','academic_year_removals'],$tables()))===0,$run['err']);
            $pdo->exec('DROP TABLE academic_year_settings');
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql reports NOT_MIGRATED at 15',$run['exit']===0 && $verifyOverall($run)==='NOT_MIGRATED',$run);
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL migration succeeds',$run['exit']===0 && $version()==='16' && $marker('prism_settings_v1')==='1',$run);
            $composed('SQL-migrated');
            $verified('SQL-migrated settings exact contract',fn()=>prism_admin_settings_schema_verify($fresh()));
            $check('SQL migration retains assignments',$assignments()===$before);
            $check('SQL migration retains catalog',array_values(array_intersect($years,academic_years($pdo)))===$years);
            $valid=array_values(array_filter(academic_years($pdo),'academic_year_valid'));
            $check('SQL seeds same settings rows as CLI',(int)$pdo->query('SELECT COUNT(*) FROM academic_year_settings WHERE available_for_registration=1 AND is_current=0')->fetchColumn()===count($valid));
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql reports PASS',$run['exit']===0 && $verifyOverall($run)==='PASS',$run);
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL re-run refused at 16',$run['exit']!==0 && str_contains($run['err'],'PRISM ABORT') && $version()==='16',$run['err']);
            $run=$sql('PRISM_SCHEMA_16_ROLLBACK.sql');
            $check('SQL rollback of unused install',$run['exit']===0 && $version()==='15' && $marker('prism_settings_v1')===false,$run);
            $verified('schema15 exact after SQL rollback',fn()=>schema_v15_verify($fresh()));
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL re-migration after rollback',$run['exit']===0 && $version()==='16',$run['err']);
            // A half-installed state (schema 15, no marker, only some tables): what an interrupted DDL run leaves behind.
            $newTables=['academic_year_settings','prism_settings','prism_deadline_reminder_dispatches','academic_year_removals'];
            $pdo->exec("DELETE FROM schema_meta WHERE k='prism_settings_v1'"); $pdo->exec("UPDATE schema_meta SET v='15' WHERE k='schema_version'");
            $pdo->exec('DROP TABLE academic_year_removals'); $pdo->exec('DROP TABLE prism_deadline_reminder_dispatches');
            $tableSnapshot=$tables();
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL refuses a half-installed state and changes nothing',$run['exit']!==0 && str_contains($run['err'],'PRISM ABORT') && $version()==='15' && $tables()===$tableSnapshot,$run['err']);
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql reports FAIL for a half-installed state',$run['exit']===0 && $verifyOverall($run)==='FAIL',$run);
            $run=$sql('PRISM_SCHEMA_16_ROLLBACK.sql');
            $check('SQL rollback cleans a half-installed state to exact schema 15',$run['exit']===0 && $version()==='15' && !array_intersect($newTables,$tables()) && $marker('prism_settings_v1')===false,$run);
            $verified('schema15 exact after half-install cleanup',fn()=>schema_v15_verify($fresh()));
            $check('half-install cleanup retains student assignments',$assignments()===$before);
            $run=$sql('PRISM_SCHEMA_15_TO_16.sql');
            $check('SQL migration succeeds after half-install cleanup',$run['exit']===0 && $version()==='16' && $marker('prism_settings_v1')==='1',$run['err']);
            $operations();
            $run=$sql('PRISM_SCHEMA_16_ROLLBACK.sql');
            $check('SQL rollback refused with retained settings',$run['exit']!==0 && str_contains($run['err'],'PRISM ABORT') && $version()==='16' && $marker('prism_settings_v1')==='1',$run['err']);
            $composed('after refused SQL rollback');
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql still PASS with data',$run['exit']===0 && $verifyOverall($run)==='PASS',$run);
            break;
        case 'runner':
            // Option A of the migration guide: the real hostinger-cron-runner.php, one reviewed step per invocation.
            $before=$assignments(); $secret='prism_synthetic_only';
            $base=rtrim(sys_get_temp_dir(),'\/').DIRECTORY_SEPARATOR.'prism-runner-'.bin2hex(random_bytes(4));
            $configDir=$base.DIRECTORY_SEPARATOR.'private-config'; $receiptDir=$base.DIRECTORY_SEPARATOR.'receipts';
            mkdir($configDir,0700,true); mkdir($receiptDir,0700,true); $configDir=realpath($configDir); $receiptDir=realpath($receiptDir);
            $writeConfig=function(string $name,string $db)use($configDir,$receiptDir,$database,$secret): string {
                $file=$configDir.DIRECTORY_SEPARATOR.$name; file_put_contents($file,json_encode(['dsn'=>'mysql:host=127.0.0.1;port='.PRISM_TEST_DB_PORT.';dbname='.$db.';charset=utf8mb4',
                    'user'=>'root','password'=>$secret,'database'=>$db,'receiptDir'=>$receiptDir])); return $file;
            };
            $config=$writeConfig('migration.json',$database); $wrong=$writeConfig('wrong.json','mysql');
            $runner=function(string $file,string $step,string $mode='x'){
                $p=proc_open([PHP_BINARY,dirname(__DIR__).'/hostinger-cron-runner.php',$file,$step,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                return ['exit'=>proc_close($p),'receipt'=>json_decode($out,true),'out'=>$out,'err'=>trim($err)];
            };
            // The step also lists PHP capabilities; this development CLI has no ext-zip (the fixture web server loads it), which is not a database fact.
            $dbPreflight=function(array $r,string $schema): bool {
                $out=$r['receipt']['stdout']??[]; $failed=array_column(array_filter($out['checks']??[],fn($c)=>$c['required']&&!$c['ok']),'capability');
                return ($out['database']['ok']??false)===true && ($out['database']['contract']??null)==='PASS' && ($out['database']['schema_version']??null)===$schema && array_diff($failed,['ext-zip'])===[];
            };
            $r=$runner($config,'probe');
            $check('runner probe connects read-only and reports schema 15',$r['exit']===0 && ($r['receipt']['schemaVersion']??null)==='15' && ($r['receipt']['databaseMatches']??false)===true,$r['out']);
            $r=$runner($wrong,'v16','apply');
            $check('runner refuses a mismatched database binding and changes nothing',$r['exit']!==0 && $version()==='15' && !in_array('academic_year_settings',$tables(),true),$r['out'].$r['err']);
            $r=$runner($config,'preflight');
            $check('runner preflight database contract PASS at schema 15',$dbPreflight($r,'15'),$r['out']);
            $r=$runner($config,'settings','apply');
            $check('runner settings step refuses before schema 16',$r['exit']!==0 && $marker('prism_settings_v1')===false && $version()==='15',$r['out']);
            $r=$runner($config,'v16','dry-run');
            $check('runner v16 dry-run writes nothing',$r['exit']===0 && ($r['receipt']['stdout']['ok']??false)===true && $version()==='15' && !in_array('academic_year_settings',$tables(),true),$r['out']);
            $r=$runner($config,'v16','apply');
            $check('runner v16 apply reaches 16 without touching Student assignments',$r['exit']===0 && $version()==='16' && ($r['receipt']['stdout']['studentAssignmentsChanged']??null)===0 && $assignments()===$before,$r['out']);
            $r=$runner($config,'settings','dry-run');
            $check('runner settings dry-run writes nothing',$r['exit']===0 && $marker('prism_settings_v1')===false,$r['out']);
            $r=$runner($config,'settings','apply');
            $check('runner settings apply installs the extension',$r['exit']===0 && $marker('prism_settings_v1')==='1' && ($r['receipt']['stdout']['migrationApplied']??false)===true,$r['out']);
            $r=$runner($config,'preflight');
            $check('runner preflight database contract PASS at schema 16',$dbPreflight($r,'16'),$r['out']);
            $composed('runner-migrated');
            $run=$sql('PRISM_SCHEMA_16_VERIFY.sql');
            $check('VERIFY.sql agrees with the runner result (PASS)',$run['exit']===0 && $verifyOverall($run)==='PASS',$run);
            $r=$runner($config,'v16','apply');
            $check('runner v16 apply is refused once at schema 16',$version()==='16' && ($r['exit']!==0 || ($r['receipt']['stdout']['ok']??true)!==false),$r['out'].$r['err']);
            // Recovery through the runner itself: unused install rolls back (settings first, then v16); re-apply; used install refuses.
            $r=$runner($config,'v16','rollback');
            $check('runner v16 rollback is refused while the Settings extension is installed',$r['exit']!==0 && $version()==='16',$r['out'].$r['err']);
            $r=$runner($config,'settings','rollback');
            $check('runner settings rollback removes the empty extension',$r['exit']===0 && $marker('prism_settings_v1')===false && !in_array('prism_settings',$tables(),true),$r['out'].$r['err']);
            $r=$runner($config,'v16','rollback');
            $check('runner v16 rollback returns to schema 15 without touching assignments',$r['exit']===0 && $version()==='15' && !in_array('academic_year_settings',$tables(),true) && $assignments()===$before,$r['out'].$r['err']);
            $r=$runner($config,'probe');
            $check('runner probe confirms schema 15 after rollback',$r['exit']===0 && ($r['receipt']['schemaVersion']??null)==='15',$r['out']);
            $r=$runner($config,'v16','apply'); $r2=$runner($config,'settings','apply');
            $check('runner re-applies v16 and Settings after a rollback',$r['exit']===0 && $r2['exit']===0 && $version()==='16' && $marker('prism_settings_v1')==='1',$r['out'].$r2['out']);
            prism_settings_save($pdo,$admin,'communication.deadline_reminder_intervals',[5,2]);
            $r=$runner($config,'settings','rollback');
            $check('runner settings rollback is refused once a setting is saved (fix forward, never delete data)',$r['exit']!==0 && $marker('prism_settings_v1')==='1' && count($pdo->query('SELECT 1 FROM prism_settings')->fetchAll())>=1,$r['out'].$r['err']);
            $r=$runner($config,'v16','rollback');
            $check('runner v16 rollback is refused while Settings data exists',$r['exit']!==0 && $version()==='16',$r['out'].$r['err']);
            $receipts=glob($receiptDir.DIRECTORY_SEPARATOR.'prism-*.json');
            $leak=array_filter($receipts,fn($f)=>str_contains(file_get_contents($f),$secret)||str_contains(file_get_contents($f),'dbname='));
            $check('every runner step wrote a private receipt with no password or connection string',count($receipts)>=9 && !$leak,['receipts'=>count($receipts),'leaking'=>array_values($leak)]);
            $check('runner left student assignments unchanged',$assignments()===$before);
            // PRISM_TEST_KEEP_RECEIPTS=<folder> keeps the (secret-free) receipts so a runbook can quote real expected values.
            if (($keep=getenv('PRISM_TEST_KEEP_RECEIPTS'))!==false && $keep!=='') { @mkdir($keep,0777,true); foreach (glob($receiptDir.DIRECTORY_SEPARATOR.'prism-*.json') as $f) copy($f,$keep.DIRECTORY_SEPARATOR.basename($f)); }
            foreach (array_merge(glob($receiptDir.DIRECTORY_SEPARATOR.'*'),glob($configDir.DIRECTORY_SEPARATOR.'*')) as $f) @unlink($f);
            @rmdir($receiptDir); @rmdir($configDir); @rmdir($base);
            break;
        default: throw new RuntimeException('Unknown Settings scenario.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $check('scenario completed without unexpected exception',false,get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
}
echo json_encode(['ok'=>$failed===0,'scenario'=>$input['scenario']??null,'server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'collation'=>schema_extension_collation($pdo),'passed'=>count($results)-$failed,'failed'=>$failed,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===0?0:1);
