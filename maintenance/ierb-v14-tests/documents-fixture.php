<?php
/** Every operation is bound to bootstrap's actual disposable MariaDB datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v12.php';
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
$actor = $pdo->query('SELECT * FROM users WHERE id='.(int)($in['actor'] ?? 1))->fetch();
$sid = (int)($in['studentId'] ?? 4);
try {
    $result = match ($in['action']) {
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
            $pdo->beginTransaction();
            $workflowIds=$pdo->query('SELECT id FROM ierb_workflows WHERE student_id=4')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($workflowIds as $id) {
                $pdo->prepare('DELETE FROM ierb_workflow_events WHERE workflow_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM ierb_correction_cases WHERE workflow_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM ierb_workflows WHERE id=?')->execute([$id]);
            }
            $pdo->exec("DELETE FROM documents WHERE original_name LIKE 'Synthetic Batch2 %' ORDER BY version_no DESC");
            $pdo->commit(); return ['ok'=>true];
        })(),
        default => throw new RuntimeException('Unknown synthetic operation.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()]);
}
