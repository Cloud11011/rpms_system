<?php
/** Disposable synthetic fixture only. Never includes deployed configuration. */
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v12.php';
require_once $root.'/includes/schema_v13.php';
require_once $root.'/includes/schema_v14.php';
require_once $root.'/includes/schema_v15.php';
require_once $root.'/includes/ierb_v14_requirements.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
$admin=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
try {
    $result=match($input['action']??'') {
        'prepare'=>(function()use($pdo,$admin){
            foreach([12,13,14,15] as $version){
                putenv('PRISM_ALLOW_SCHEMA_V'.$version.'_MIGRATION=1');
                putenv('PRISM_SCHEMA_V'.$version.'_EXPECT_DB=prism_group_synthetic_v11');
                ('migrate_schema_v'.$version)($pdo,'apply');
            }
            // New synthetic users only; explicit membership is established below.
            foreach([6,7] as $id){
                $pdo->prepare("INSERT INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at)
                    SELECT ?,?, ?,?,'student',?,password_hash,'Active','2026-01-01' FROM users WHERE id=4")
                    ->execute([$id,'S'.$id,'Synthetic Student '.$id,'student'.$id.'@example.test','S'.$id]);
                $pdo->prepare("INSERT INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,
                    course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at)
                    SELECT ?,?,?,?, ?,2,'Stage 1','Pending',NULL,course,academic_unit_key,program_key,year_level,academic_year,
                    '2026-01-01','2026-01-01' FROM students WHERE id=4")
                    ->execute([$id,$id,'S'.$id,'Synthetic Student '.$id,'student'.$id.'@example.test']);
                $pdo->prepare('INSERT INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4')->execute([$id]);
            }
            $pdo->exec('UPDATE students SET research_group=NULL WHERE id IN (4,5,6,7)');
            $projects=[];
            foreach([4=>[4,6],5=>[5]] as $holder=>$members){
                ierb_v14_enroll($pdo,$admin,$holder,IerbStage::INITIAL,'Synthetic explicit V14 enrollment',
                    'Disposable current-workflow test; no numeric stage inference','reconciliation-enroll-'.$holder);
                $mapping=[];
                foreach($members as $id){$q=$pdo->prepare('SELECT * FROM students WHERE id=?');$q->execute([$id]);
                    $mapping[]=['studentId'=>$id,'snapshotSha256'=>hash('sha256',json_encode($q->fetch(),JSON_THROW_ON_ERROR))];}
                $projects[$holder]=research_project_reconcile($pdo,$admin,'Synthetic V14 Study '.$holder,$holder,$holder===4?2:3,$mapping,
                    'Explicit reviewed synthetic membership for the current four-stage V14 workflow');
            }
            file_put_contents(PRISM_TEST_REVIEW_DIR.'/sandbox/v14-operations.flag','synthetic current V14 operations only');
            return ['projects'=>$projects,'schema'=>'15','catalog'=>ierb_v14_requirements()];
        })(),
        'catalog'=>ierb_v14_requirements(),
        // Schema 16 plus the Settings extension, through the same reviewed migrators an operator would run.
        'schema16'=>(function()use($pdo,$root){
            require_once $root.'/includes/schema_v16.php';
            require_once $root.'/includes/admin_settings_schema.php';
            foreach (['SCHEMA_V16','SETTINGS'] as $name) { putenv('PRISM_ALLOW_'.$name.'_MIGRATION=1'); putenv('PRISM_'.$name.'_EXPECT_DB=prism_group_synthetic_v11'); }
            migrate_schema_v16($pdo,'apply'); migrate_prism_admin_settings($pdo,'apply');
            schema_v16_verify($pdo); prism_admin_settings_schema_verify($pdo);
            return ['schema'=>(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()];
        })(),
        'audit-fault'=>(function()use($pdo,$input){
            if(!empty($input['enabled'])) $pdo->exec("CREATE TRIGGER synthetic_reconciliation_audit_fault BEFORE INSERT ON activity_logs FOR EACH ROW
                BEGIN IF NEW.action='ierb_v14_final_report_submitted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic rollback verification'; END IF; END");
            else $pdo->exec('DROP TRIGGER synthetic_reconciliation_audit_fault');
            return ['enabled'=>(bool)$input['enabled']];
        })(),
        default=>throw new RuntimeException('Unknown synthetic fixture action.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()]);exit(1);}
