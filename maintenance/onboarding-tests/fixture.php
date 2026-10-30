<?php
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/account_onboarding.php';
require_once $root.'/includes/research_onboarding.php';
require_once $root.'/includes/schema_research_onboarding.php';
putenv('PRISM_IERB_V14_FOUNDATION=1');
if(!defined('ALLOWED_EMAIL_DOMAINS'))define('ALLOWED_EMAIL_DOMAINS',['example.test']);
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $result=match($in['action']) {
        'extension'=>(function()use($pdo,$root){
            if(research_onboarding_available($pdo)) { research_onboarding_schema_verify($pdo); return ['Previously installed onboarding extension matches its exact contract']; }
            if(!is_file($root.'/includes/research_onboarding_schema.json')) {
                $contract=[];
                foreach(research_onboarding_tables(schema_extension_collation($pdo)) as $name=>$ddl){$pdo->exec($ddl);$contract[$name]=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];}
                foreach(array_reverse(array_keys(research_onboarding_tables())) as $name)$pdo->exec('DROP TABLE `'.$name.'`');
                file_put_contents($root.'/includes/research_onboarding_schema.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
            }
            $checks=[];
            $refuse=function(callable $f,string $label)use(&$checks){try{$f();}catch(Throwable $e){$checks[]=$label;return;}throw new RuntimeException('Unexpected success: '.$label);};
            $refuse(fn()=>migrate_research_onboarding($pdo),'Migration requires explicit opt-in');
            putenv('PRISM_ALLOW_RESEARCH_ONBOARDING_MIGRATION=1');putenv('PRISM_RESEARCH_ONBOARDING_EXPECT_DB=wrong');
            $refuse(fn()=>migrate_research_onboarding($pdo),'Migration requires exact database binding');putenv('PRISM_RESEARCH_ONBOARDING_EXPECT_DB=prism_group_synthetic_v11');
            $pdo->beginTransaction();$refuse(fn()=>migrate_research_onboarding($pdo),'Migration refuses open transaction');$pdo->rollBack();
            $pdo->exec('SET foreign_key_checks=0');$refuse(fn()=>migrate_research_onboarding($pdo),'Migration refuses disabled foreign keys');$pdo->exec('SET foreign_key_checks=1');
            $before=lifecycle_schema_inventory($pdo);migrate_research_onboarding($pdo);
            if($before!==lifecycle_schema_inventory($pdo))throw new RuntimeException('Dry run changed schema');$checks[]='Dry run preserves the complete schema';
            migrate_research_onboarding($pdo,'apply');migrate_research_onboarding($pdo,'apply');$checks[]='Restarted apply is idempotent';
            migrate_research_onboarding($pdo,'rollback');schema_v15_verify($pdo);$checks[]='Empty extension rolls back to exact schema 15';
            migrate_research_onboarding($pdo,'apply');lifecycle_compare_schema($pdo);$checks[]='Extension composes with the exact schema 15 lifecycle contract';
            return $checks;
        })(),
        'ready'=>(function()use($pdo,$in){
            $q=$pdo->prepare('SELECT * FROM students WHERE id=?');$q->execute([$in['id']]);$s=$q->fetch();
            $pdo->beginTransaction();$u=lifecycle_resolve_login($pdo,$s,'student');
            $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')->execute([prism_password_hash('SyntheticPass123!'),$u['id']]);
            $pdo->prepare('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4')->execute([$u['id']]);
            $pdo->commit();return ['userId'=>(int)$u['id']];
        })(),
        'token'=>(function()use($pdo,$in){
            $token=bin2hex(random_bytes(32));$q=$pdo->prepare('SELECT user_id FROM students WHERE id=?');$q->execute([$in['id']]);$uid=$q->fetchColumn();
            $pdo->prepare('UPDATE account_invitations SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE user_id=?')->execute([hash('sha256',$token),$uid]);return ['token'=>$token];
        })(),
        'accept'=>(function()use($pdo,$in){onboarding_accept($pdo,['token'=>$in['token'],'password'=>'SyntheticPass123!','confirmPassword'=>'SyntheticPass123!']);return ['ok'=>true];})(),
        'activate'=>research_onboarding_activate($pdo,(int)$in['id']),
        'legacy-guard'=>(function()use($pdo,$in){$pdo->beginTransaction();research_project_fence($pdo);ierb_v14_guard_legacy_write($pdo,(int)$in['id']);$pdo->rollBack();return true;})(),
        'gated-activate'=>(function()use($pdo,$in){putenv('PRISM_IERB_V14_FOUNDATION=0');return research_onboarding_activate($pdo,(int)$in['id']);})(),
        'retained-rollback'=>(function()use($pdo){putenv('PRISM_ALLOW_RESEARCH_ONBOARDING_MIGRATION=1');putenv('PRISM_RESEARCH_ONBOARDING_EXPECT_DB=prism_group_synthetic_v11');return migrate_research_onboarding($pdo,'rollback');})(),
        'archive'=>(function()use($pdo,$in){archive_student($pdo,$GLOBALS['actor'],(int)$in['id']);return true;})(),
        'pdf-text'=>(function()use($in,$root){require_once $root.'/vendor/autoload.php';return (new Smalot\PdfParser\Parser())->parseFile(REPORTS_DIR.'/'.basename($in['filename']))->getText();})(),
        'invite'=>onboarding_invite($pdo,$GLOBALS['actor'],$in['data']),
        'hold-assignment'=>(function()use($pdo){
            if((int)$pdo->query('SELECT COUNT(*) FROM research_onboarding_assignments')->fetchColumn())throw new RuntimeException('An empty synthetic assignment table is required.');
            $pdo->beginTransaction();$pdo->query("SELECT v FROM schema_meta WHERE k='research_onboarding_v1' LOCK IN SHARE MODE")->fetchColumn();
            echo "locked\n";flush();usleep(2200000);
            $pdo->exec("INSERT INTO research_onboarding_assignments(student_id,approved_by_user_id,assignment_json) VALUES (4,1,'{}')");$pdo->commit();return true;
        })(),
        'cleanup-assignment-race'=>(function()use($pdo){$pdo->exec('DELETE FROM research_onboarding_assignments WHERE student_id=4');return true;})(),
        'historical-guards'=>(function()use($pdo){
            $cases=[];
            foreach(['historical-stage','legacy-association','changed-after-approval'] as $scenario) {
                $number='9900'.random_int(10000000,99999999);$email='historical-'.$number.'@example.test';
                $pdo->prepare("INSERT INTO users(username,password_hash,role,full_name,email,ref_id,status) SELECT ?,password_hash,'student','Synthetic Historical Guard',?,?,'Active' FROM users WHERE id=4")->execute([$number,$email,$number]);$uid=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO students(student_id,full_name,email,user_id,adviser_id,course,academic_unit_key,program_key,year_level,academic_year,stage,status,profile_completed_at) VALUES (?,'Synthetic Historical Guard',?,?,2,'BS in Information Technology','amt','bsit','4th Year','2026-2027','Stage 1','On Track',NOW())")->execute([$number,$email,$uid]);$sid=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4')->execute([$uid]);
                try {
                    if($scenario==='historical-stage')$pdo->prepare("UPDATE students SET stage='Stage 3' WHERE id=?")->execute([$sid]);
                    if($scenario==='legacy-association')$pdo->prepare("UPDATE students SET research_group='Explicit retained legacy association' WHERE id=?")->execute([$sid]);
                    $actor=$GLOBALS['actor'];$data=['group'=>'__create__','research'=>'Synthetic reviewed history guard '.$number,'adviserId'=>2,'academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'4th Year','academicYear'=>'2026-2027'];
                    $rejected=false;$pdo->beginTransaction();research_project_fence($pdo);
                    try{$intent=research_onboarding_intent($pdo,$actor,$data,academic_validate($data));research_onboarding_store($pdo,$actor,$sid,$intent);$pdo->commit();}
                    catch(AccountLifecycleConflict $e){$pdo->rollBack();$rejected=true;}
                    if($scenario==='changed-after-approval') {
                        $pdo->prepare("UPDATE students SET stage='Stage 3' WHERE id=?")->execute([$sid]);
                        try{research_onboarding_activate($pdo,$sid);}catch(AccountLifecycleConflict $e){$rejected=true;}
                    }
                    $q=$pdo->prepare('SELECT COUNT(*) FROM ierb_workflows WHERE student_id=?');$q->execute([$sid]);
                    if(!$rejected || $q->fetchColumn() || research_project_for_student($pdo,$sid))throw new RuntimeException('Historical guard failed: '.$scenario);
                    $cases[]=$scenario.' is refused without enrollment or canonical membership';
                } finally {
                    if($pdo->inTransaction())$pdo->rollBack();
                    // Only the three explicitly created synthetic identities in the guarded datadir are removed.
                    $pdo->prepare('DELETE FROM research_onboarding_assignments WHERE student_id=?')->execute([$sid]);
                    $pdo->prepare("DELETE FROM activity_logs WHERE entity_type='student' AND entity_id=?")->execute([(string)$sid]);
                    $pdo->prepare('DELETE FROM user_policy_acceptances WHERE user_id=?')->execute([$uid]);
                    $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$sid]);$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
                }
            }
            return ['status'=>'PASS','assertions'=>count($cases),'cases'=>$cases];
        })(),
        default=>throw new RuntimeException('Unknown onboarding fixture action.')
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
