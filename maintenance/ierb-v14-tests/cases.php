<?php
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v12.php';
require_once $root.'/includes/stage_configuration.php';

$checks = [];
function check(bool $value, string $label): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$label);
    $checks[] = $label;
}
function refuses(callable $action, string $class, string $label): void {
    try { $action(); } catch (Throwable $e) { check($e instanceof $class, $label.' ('.get_class($e).')'); return; }
    throw new RuntimeException('Unexpected success: '.$label);
}
function rows(string $sql, array $params = []): array { $q = db()->prepare($sql); $q->execute($params); return $q->fetchAll(); }
function sql(string $sql, array $params = []): void { $q = db()->prepare($sql); $q->execute($params); }
function retained(): string {
    $data=[];
    foreach (['students','documents','ierb_history','stage_labels'] as $name) $data[$name] = rows('SELECT * FROM '.$name.' ORDER BY 1');
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function flags(): void {
    putenv('PRISM_ALLOW_SCHEMA_V12_MIGRATION=1');
    putenv('PRISM_SCHEMA_V12_EXPECT_DB=prism_group_synthetic_v11');
    putenv('PRISM_IERB_V14_FOUNDATION=1');
}
$admin = rows('SELECT * FROM users WHERE id=1')[0];
$phase = $argv[1] ?? 'all';
try {
    if ($phase === 'migration') {
        $before = retained(); $inventory = lifecycle_schema_inventory($pdo);
        refuses(fn()=>migrate_schema_v12($pdo), RuntimeException::class, 'Migration requires explicit opt-in');
        flags(); putenv('PRISM_SCHEMA_V12_EXPECT_DB=wrong_database');
        refuses(fn()=>migrate_schema_v12($pdo), RuntimeException::class, 'Migration rejects a different database binding'); flags();
        $pdo->beginTransaction(); refuses(fn()=>migrate_schema_v12($pdo), RuntimeException::class, 'Migration rejects an open transaction'); $pdo->rollBack();
        $pdo->exec('SET foreign_key_checks=0');
        refuses(fn()=>migrate_schema_v12($pdo), RuntimeException::class, 'Migration requires enforced foreign keys'); $pdo->exec('SET foreign_key_checks=1');
        refuses(fn()=>migrate_schema_v12($pdo,'unknown'), RuntimeException::class, 'Migration rejects unknown modes');
        check(migrate_schema_v12($pdo)['mode']==='dry-run' && lifecycle_schema_inventory($pdo)===$inventory, 'Default dry run does not change schema or data');
        check(migrate_schema_v12($pdo,'apply')['to']==='12', 'Additive migration applies to the canonical v11 fixture');
        schema_v12_verify($pdo); lifecycle_compare_schema($pdo);
        check(stage_guidance_available($pdo), 'Legacy stage guidance remains available on schema 12');
        check(stage_configuration_list($pdo)['order']===STAGE_SEQUENCE, 'Existing stage configuration keeps every legacy identifier');
        check(retained()===$before, 'Migration preserves all legacy Students, documents, histories and labels byte for byte');
        check(!rows('SELECT * FROM ierb_workflows'), 'Migration never maps or enrolls existing stage numbers');
        $after = lifecycle_schema_inventory($pdo); migrate_schema_v12($pdo,'apply');
        check(lifecycle_schema_inventory($pdo)===$after && retained()===$before, 'Applying the migration twice is idempotent');
        sql('DROP TABLE ierb_workflow_events');
        refuses(fn()=>migrate_schema_v12($pdo,'apply'), RuntimeException::class, 'Published schema 12 with a missing table is refused rather than silently repaired');
        $pdo->exec(schema_v12_tables()['ierb_workflow_events']); // Explicit disposable test repair of an empty table.
        sql('ALTER TABLE ierb_workflows ADD unexpected_column INT NULL');
        refuses(fn()=>migrate_schema_v12($pdo,'apply'), RuntimeException::class, 'Migration refuses extension schema drift');
        refuses(fn()=>lifecycle_compare_schema($pdo), AccountLifecycleConflict::class, 'Permanent deletion schema verification fails closed on drift');
        sql('ALTER TABLE ierb_workflows DROP unexpected_column');
        check(migrate_schema_v12($pdo,'rollback')['to']==='11', 'Empty extension rollback restores schema 11');
        check(lifecycle_schema_inventory($pdo)===$inventory && retained()===$before, 'Rollback restores the exact inventory and every retained record');
        // Simulate interruption after the first implicit-commit CREATE.
        $pdo->exec(schema_v12_tables()['ierb_workflows']);
        check(count(migrate_schema_v12($pdo)['existingExtensionTables'])===1, 'Dry run recognizes a canonical partially applied migration');
        migrate_schema_v12($pdo,'apply'); schema_v12_verify($pdo);
        check(retained()===$before, 'Restart after interrupted DDL safely finishes without changing legacy rows');
        migrate_schema_v12($pdo,'rollback');
        // A rollback interrupted after dropping the event table remains restartable.
        migrate_schema_v12($pdo,'apply'); sql("UPDATE schema_meta SET v='11' WHERE k='schema_version'"); sql('DROP TABLE ierb_workflow_events');
        migrate_schema_v12($pdo,'rollback');
        check(lifecycle_schema_inventory($pdo)===$inventory, 'Interrupted rollback can resume without a reset or data loss');
        migrate_schema_v12($pdo,'apply');
        putenv('PRISM_IERB_V14_FOUNDATION');
        refuses(fn()=>ierb_v14_enroll($pdo,$admin,4,IerbStage::INITIAL,'Reviewed evidence','Verified review context','v14-disabled-key-0001'),AccountLifecycleConflict::class,'V14 writes remain disabled by default after migration');
    } elseif ($phase === 'workflow') {
        flags();
        $before = retained();
        foreach (IerbStage::LABELS as $stage=>$label) check(is_string($stage) && $label!=='', 'Immutable V14 identity: '.$stage);
        check(IerbStage::transition(IerbStage::INITIAL,'initial_requirements_accepted')===IerbStage::GATHERING,'Normal initial advancement skips conditional Stage 2');
        check(IerbStage::transition(IerbStage::GATHERING,'final_report_submitted')===IerbStage::COMPLETED,'Only formal Final Report event reaches completed in the transition contract');
        foreach ([IerbStage::INITIAL,IerbStage::GATHERING] as $stage) {
            check(IerbStage::transition($stage,'open_correction')===IerbStage::CORRECTION,'Open correction interrupts '.$stage);
            check(IerbStage::transition(IerbStage::CORRECTION,'resolve_correction',$stage)===$stage,'Resolved correction resumes '.$stage);
            check(IerbStage::progress(IerbStage::CORRECTION,$stage)===IerbStage::progress($stage),'Correction creates no artificial progress from '.$stage);
        }
        foreach (array_keys(IerbStage::LABELS) as $stage) foreach (array_keys(IerbStage::TRANSITIONS) as $event) {
            if (isset(IerbStage::TRANSITIONS[$event][$stage])) continue;
            refuses(fn()=>IerbStage::transition($stage,$event),InvalidArgumentException::class,'Invalid transition rejected: '.$stage.' / '.$event);
        }
        foreach (array_merge(STAGE_SEQUENCE,['','initial','Stage 6']) as $invalid) refuses(fn()=>IerbStage::requireKey($invalid),InvalidArgumentException::class,'No implicit numeric/label conversion: '.$invalid);
        foreach ([null,IerbStage::CORRECTION,IerbStage::COMPLETED,'Stage 1'] as $invalid) refuses(fn()=>IerbStage::transition(IerbStage::CORRECTION,'resolve_correction',$invalid),InvalidArgumentException::class,'Invalid interrupted context rejected: '.($invalid??'null'));
        refuses(fn()=>IerbStage::transition(IerbStage::GATHERING,'advance'),InvalidArgumentException::class,'Manual completion is absent');
        check(IerbStage::progress(IerbStage::INITIAL)===0 && IerbStage::progress(IerbStage::GATHERING)===50 && IerbStage::progress(IerbStage::COMPLETED)===100,'Progress counts the two meaningful forward transitions');
        $contract=IerbStage::contract();
        check(!$contract['completionContract']['additionalRpmsAcceptance'] && !$contract['completionContract']['manualCompletion'] && !$contract['httpActivationAvailable'],'Batch 1 declares automatic completion and keeps HTTP activation gated');
        foreach ([2,4] as $uid) {
            $actor=rows('SELECT * FROM users WHERE id=?',[$uid])[0];
            refuses(fn()=>ierb_v14_enroll($pdo,$actor,4,IerbStage::INITIAL,'Verified context','Documentary context evidence','unauthorized-actor-0001'),AccountLifecycleForbidden::class,'Non-Admin cannot enroll: '.$actor['role']);
        }
        refuses(fn()=>ierb_v14_enroll($pdo,$admin,4,'Stage 1','Verified context','Documentary context evidence','invalid-enrollment-0001'),AccountLifecycleValidation::class,'Enrollment requires a semantic identifier');
        refuses(fn()=>ierb_v14_enroll($pdo,$admin,4,IerbStage::COMPLETED,'Verified context','Documentary context evidence','invalid-enrollment-0002'),AccountLifecycleValidation::class,'Enrollment cannot bypass formal completion');
        $r=ierb_v14_enroll($pdo,$admin,4,IerbStage::INITIAL,'Synthetic review context verified','Explicit synthetic Initial Submission review evidence','enrollment-request-0001');
        check($r['revision']===1 && $r['progress']===0,'Explicit enrollment records the selected review context');
        $again=ierb_v14_enroll($pdo,$admin,4,IerbStage::INITIAL,'Synthetic review context verified','Explicit synthetic Initial Submission review evidence','enrollment-request-0001');
        check($again['replayed'] && $again['workflowId']===$r['workflowId'],'Identical enrollment request replays its original result');
        refuses(fn()=>ierb_v14_enroll($pdo,$admin,4,IerbStage::GATHERING,'Synthetic review context verified','Explicit synthetic Initial Submission review evidence','enrollment-request-0001'),AccountLifecycleConflict::class,'Request key cannot be reused for different enrollment');
        // Only synthetic documents; their historical type/stage labels stay intact.
        foreach (['affected-v14-1','affected-v14-2'] as $id) sql("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current) VALUES (?,4,'Synthetic Student 4','Synthetic Student 4','student','V14 synthetic.pdf',?,'application/pdf',1,?,'Stage 1','Submitted',1,1)",[$id,$id.'.pdf',$id==='affected-v14-1'?'Study Protocol':'Informed Consent - English']);
        sql("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current) VALUES ('foreign-v14',5,'Synthetic Student 5','Synthetic Student 5','student','V14 synthetic.pdf','foreign.pdf','application/pdf',1,'Study Protocol','Stage 1','Submitted',1,1)");
        $before=retained();
        foreach ([2,4] as $uid) {
            $actor=rows('SELECT * FROM users WHERE id=?',[$uid])[0];
            refuses(fn()=>ierb_v14_open_correction($pdo,$actor,4,1,'Synthetic correction reason',['affected-v14-1'],false,'forbidden-correction-0001'),AccountLifecycleForbidden::class,'Only RPMS Admin opens a correction: '.$actor['role']);
        }
        sql("UPDATE users SET status='Inactive' WHERE id=1");
        refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-1'],false,'inactive-correction-0001'),AccountLifecycleForbidden::class,'Live inactive Admin rejected despite stale authenticated actor');
        sql("UPDATE users SET status='Active' WHERE id=1");
        foreach ([[],['missing-v14'],['foreign-v14'],['affected-v14-1','affected-v14-1']] as $bad) refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',$bad,false,'bad-document-selection-0001'),AccountLifecycleValidation::class,'Invalid affected document selection rejected: '.json_encode($bad));
        sql("UPDATE documents SET is_current=0 WHERE id='affected-v14-2'");
        refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-2'],false,'stale-document-request-0001'),AccountLifecycleValidation::class,'Superseded affected version rejected');
        sql("UPDATE documents SET is_current=1 WHERE id='affected-v14-2'");
        refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,0,'Synthetic correction reason',['affected-v14-1'],false,'stale-revision-request-0001'),AccountLifecycleConflict::class,'Stale workflow revision rejected');
        // Required audit must be atomic with the case, event and workflow revision.
        sql("CREATE TRIGGER v14_test_audit_failure BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
        try { refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-1'],false,'fault-correction-request-0001'),PDOException::class,'Audit failure aborts the complete correction transaction'); }
        finally { sql('DROP TRIGGER v14_test_audit_failure'); }
        check(!rows('SELECT * FROM ierb_correction_cases') && count(rows('SELECT * FROM ierb_workflow_events'))===1 && (int)rows('SELECT revision FROM ierb_workflows WHERE student_id=4')[0]['revision']===1,'Failed correction leaves no case, history event or revision change');
        $r=ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-1'],false,'correction-request-0001');
        $case=$r['correctionCase'];
        check($r['stage']===IerbStage::CORRECTION && $r['progress']===0 && $r['revision']===2,'Correction enters conditional Stage 2 without advancement');
        check($case['interrupted_stage']===IerbStage::INITIAL && (int)$case['opened_by_user_id']===1 && !!$case['opened_at'] && (int)$case['requires_amendment']===0,'Case records interrupted context, responsible Admin, timestamp and optional Amendment Form');
        check(json_decode($case['affected_documents_json'],true)[0]['id']==='affected-v14-1' && $case['reason']==='Synthetic correction reason','Case preserves exact affected version and reason');
        $replay=ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-1'],false,'correction-request-0001');
        check($replay['replayed'] && $replay['correctionCase']['id']===$case['id'],'Repeated correction request does not create another case or event');
        refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,1,'Synthetic correction reason',['affected-v14-1'],true,'correction-request-0001'),AccountLifecycleConflict::class,'Reused key cannot alter Amendment Form policy');
        refuses(fn()=>ierb_v14_open_correction($pdo,$admin,4,2,'Another correction reason',['affected-v14-1'],true,'correction-request-0002'),AccountLifecycleConflict::class,'Nested official correction cases are rejected');
        // Storage/transition contract for the future evidence adapter: emulate its atomic resolved facts.
        $pdo->beginTransaction();
        sql("UPDATE ierb_correction_cases SET resolved_by_user_id=1,resolved_at=NOW(6),resolution_evidence_json='{}' WHERE id=?",[$case['id']]);
        $resume=IerbStage::transition(IerbStage::CORRECTION,'resolve_correction',$case['interrupted_stage']);
        sql('UPDATE ierb_workflows SET stage_key=?,revision=revision+1 WHERE id=?',[$resume,$r['workflowId']]);
        $workflow=rows('SELECT * FROM ierb_workflows WHERE id=?',[$r['workflowId']])[0];
        ierb_v14_event($pdo,$admin,$workflow,'resolve_correction',IerbStage::CORRECTION,$resume,'synthetic-resolution-0001',hash('sha256','synthetic-resolution'),'Synthetic resolved evidence adapter fixture',(int)$case['id'],['fixtureOnly'=>true]);
        $pdo->commit();
        $again=ierb_v14_open_correction($pdo,$admin,4,3,'Second correction requires amendment',['affected-v14-2'],true,'correction-request-0003');
        check((int)$again['correctionCase']['id']!==(int)$case['id'] && (int)$again['correctionCase']['requires_amendment']===1,'Later correction has a distinct identity and independent mandatory Amendment Form policy');
        $cases=rows('SELECT * FROM ierb_correction_cases ORDER BY id');
        check(count($cases)===2 && !!$cases[0]['resolved_at'] && !$cases[1]['resolved_at'] && $cases[0]['opened_at']<=$cases[1]['opened_at'],'Repeat corrections retain separate open/resolution timestamps and evidence');
        check(retained()===$before,'All correction operations preserve legacy records, files metadata and document decisions');
        $pdo->beginTransaction();
        refuses(fn()=>ierb_v14_guard_legacy_write($pdo,4),AccountLifecycleConflict::class,'Enrolled workflow cannot be changed using a legacy individual/group writer');
        $pdo->rollBack();
        $pdo->beginTransaction();
        $mutexes=[]; $student=rows('SELECT * FROM students WHERE id=4 FOR UPDATE')[0];
        refuses(function() use ($pdo,$student,&$mutexes) { retention_purge_plan($pdo,$student,'student',null,$mutexes); },AccountLifecycleConflict::class,'Retained V14 history blocks purge planning before any file movement');
        $pdo->rollBack();
        $pdo->beginTransaction();
        refuses(fn()=>ierb_v14_document_event($pdo,$admin,4,'final_report_submitted','affected-v14-1'),AccountLifecycleConflict::class,'Unintegrated formal submission cannot complete a workflow in Batch 1');
        $pdo->rollBack();
        refuses(fn()=>ierb_v14_document_event($pdo,$admin,4,'final_report_submitted','affected-v14-1'),LogicException::class,'Future completion hook requires caller-owned submission transaction');
        refuses(fn()=>migrate_schema_v12($pdo,'rollback'),RuntimeException::class,'Rollback refuses to discard enrolled workflows, cases or audit events');
        schema_v12_verify($pdo); lifecycle_compare_schema($pdo);
        ierb_v14_enroll($pdo,$admin,5,IerbStage::GATHERING,'Synthetic approval evidence verified','Explicit Data Gathering review context evidence','enrollment-request-0005');
    } elseif ($phase === 'apply-for-regression') {
        flags(); echo json_encode(migrate_schema_v12($pdo,'apply')); exit;
    } elseif ($phase === 'rollback-empty') {
        flags(); echo json_encode(migrate_schema_v12($pdo,'rollback')); exit;
    } elseif ($phase === 'open') {
        flags();
        $key=$argv[2];
        try { $result=ierb_v14_open_correction($pdo,$admin,5,1,'Concurrent synthetic correction',['foreign-v14'],true,$key); echo json_encode(['status'=>'ok','result'=>$result]); }
        catch (Throwable $e) { echo json_encode(['status'=>'refused','type'=>get_class($e)]); }
        exit;
    } elseif ($phase === 'hold') {
        $pdo->beginTransaction(); sql('SELECT * FROM students WHERE id=5 FOR UPDATE');
        echo "locked\n"; flush(); usleep(900000); $pdo->commit(); echo 'released'; exit;
    } elseif ($phase === 'hold-migration') {
        $pdo->query("SELECT GET_LOCK('prism_migrate',5)"); echo "locked\n"; flush(); usleep(900000);
        $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); echo 'released'; exit;
    } elseif ($phase === 'migration-probe') {
        flags(); echo json_encode(migrate_schema_v12($pdo)); exit;
    } elseif ($phase === 'finish') {
        $workflow=rows('SELECT * FROM ierb_workflows WHERE student_id=5')[0];
        $cases=rows('SELECT * FROM ierb_correction_cases WHERE workflow_id=?',[$workflow['id']]);
        check(count($cases)===1 && (int)$workflow['revision']===2 && count(rows('SELECT * FROM ierb_workflow_events WHERE workflow_id=?',[$workflow['id']]))===2,'Concurrent requests persist exactly one correction, revision and decision event');
        check($cases[0]['interrupted_stage']===IerbStage::GATHERING && IerbStage::progress($workflow['stage_key'],$cases[0]['interrupted_stage'])===50,'Correction during data gathering preserves 50 percent progress and its resume context');
        flags();
        $replay=ierb_v14_enroll($pdo,$admin,5,IerbStage::GATHERING,'Synthetic approval evidence verified','Explicit Data Gathering review context evidence','enrollment-request-0005');
        check($replay['stage']===IerbStage::GATHERING && $replay['revision']===1 && $replay['replayed'],'Idempotency returns original decision result even after later workflow changes');
        // Explicit teardown of synthetic rows only, after rollback refusal has been tested.
        sql('DELETE FROM ierb_workflow_events'); sql('DELETE FROM ierb_correction_cases'); sql('DELETE FROM ierb_workflows');
        sql("DELETE FROM documents WHERE id IN ('affected-v14-1','affected-v14-2','foreign-v14')");
        migrate_schema_v12($pdo,'rollback'); schema_v11_verify($pdo);
        check(true,'Disposable teardown restores the exact v11 schema for existing regression suites');
    }
    echo json_encode(['status'=>'PASS','assertions'=>count($checks),'cases'=>$checks],JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString()); exit(1);
}
