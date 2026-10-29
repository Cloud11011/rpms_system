<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
/** Every operation is bound to bootstrap's actual disposable MariaDB datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v12.php';
require_once $root.'/includes/schema_v13.php';
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
$actor = $pdo->query('SELECT * FROM users WHERE id='.(int)($in['actor'] ?? 1))->fetch();
$sid = (int)($in['studentId'] ?? 4);
try {
    $result = match ($in['action']) {
        'project' => (function () use ($pdo,$actor,$sid) {
            putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION=1'); putenv('PRISM_SCHEMA_V13_EXPECT_DB=prism_group_synthetic_v11');
            migrate_schema_v13($pdo,'apply');
            $ids=getenv('PRISM_TEST_PROJECT_GROUP')==='1' ? [$sid,6] : [$sid];
            $mapping=[];
            foreach ($ids as $id) {
                $pdo->prepare('UPDATE students SET research_group=? WHERE id=?')->execute([count($ids)>1 ? 'AMT-BSIT-Y4-2627-G97' : null,$id]);
                $q=$pdo->prepare('SELECT * FROM students WHERE id=?'); $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
                $mapping[]=['studentId'=>$id,'snapshotSha256'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
            }
            return research_project_reconcile($pdo,$actor,'PRISM: RPMS Workload Assistant',$sid,2,$mapping,'Explicit canonical mapping for Batch2 regression and Batch3 group verification');
        })(),
        'advance' => (function () use ($pdo,$actor,$sid) {
            $_SESSION=['user_id'=>1,'login_generation'=>'batch2-project-session'];
            $p=research_project_for_student($pdo,$sid);
            $preview=ierb_group_preview($pdo,$actor,hash('sha256','project:'.$p['id']));
            if (!$preview['eligible']) return $preview;
            return ierb_group_advance($pdo,$actor,['confirmationToken'=>$preview['confirmationToken'],'reason'=>'RPMS reviewed all 13 accepted Initial requirements.','confirmed'=>true]);
        })(),
        'catalog' => ierb_v14_requirements(),
        'enroll' => ierb_v14_enroll($pdo,$actor,$sid,IerbStage::INITIAL,'Synthetic project submission holder reviewed',
            'Explicit synthetic project submission holder; no group enrollment or completion fanout','batch2-enrollment-request-0001'),
        'open' => ierb_v14_open_correction($pdo,$actor,$sid,$in['revision'],'Synthetic RPMS correction case',[$in['documentId']],$in['amendment'],$in['key']),
        'resolve' => ierb_v14_resolve_correction($pdo,$actor,$sid,$in['revision'],'Synthetic current correction evidence verified',$in['key']),
        'accept' => ierb_v14_accept_requirement($pdo,$actor,$sid,$in['requirementKey'],$in['documentId'],$in['revision'],
            'Synthetic RPMS requirement acceptance',$in['key'],$in['reference'] ?? ''),
        'review' => (function () use ($pdo,$actor,$in) {
            $GLOBALS['provider_failure'] = !empty($in['providerFailure']);
            $r = document_workflow_change($pdo,$actor,$in['documentId'],'review','Approved','Synthetic Batch2 Adviser approval');
            document_workflow_deliver($pdo,$r['notificationIds']);
            return $r + ['providerAttempts'=>count($GLOBALS['provider_calls'] ?? [])];
        })(),
        'hold' => (function () use ($pdo,$sid) {
            $pdo->beginTransaction(); $q=$pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE'); $q->execute([$sid]);
            echo "locked\n"; flush(); usleep(900000); $pdo->commit(); return ['released'=>true];
        })(),
        'cleanup' => (function () use ($pdo) {
            $was13=research_projects_available($pdo);
            $pdo->beginTransaction();
            if ($was13) foreach ($pdo->query('SELECT legacy_identity_json FROM research_project_members')->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $old=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                $pdo->prepare('UPDATE students SET research_group=?,research_title=?,adviser_id=? WHERE id=?')->execute([$old['research_group'],$old['research_title'],$old['adviser_id'],$old['id']]);
            }
            if ($was13) foreach (array_reverse(array_keys(schema_v13_tables())) as $name) $pdo->exec('DELETE FROM `'.$name.'`');
            $workflowIds=$pdo->query('SELECT id FROM ierb_workflows WHERE student_id=4')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($workflowIds as $id) {
                $pdo->prepare('DELETE FROM ierb_workflow_events WHERE workflow_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM ierb_correction_cases WHERE workflow_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM ierb_workflows WHERE id=?')->execute([$id]);
            }
            $pdo->exec("DELETE FROM documents WHERE original_name LIKE 'Synthetic Batch2 %' ORDER BY version_no DESC");
            $pdo->commit();
            if ($was13) { putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION=1'); putenv('PRISM_SCHEMA_V13_EXPECT_DB=prism_group_synthetic_v11'); migrate_schema_v13($pdo,'rollback'); }
            return ['ok'=>true];
        })(),
        default => throw new RuntimeException('Unknown synthetic operation.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()]);
}
