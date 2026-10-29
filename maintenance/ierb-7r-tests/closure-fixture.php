<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP.
/** Batch 7R compliance-closure helpers (F01). Every operation is bound to bootstrap's verified disposable MariaDB datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v15.php';
require_once $root.'/includes/ierb_v14_requirements.php';
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
$actor = $pdo->query('SELECT * FROM users WHERE id=1')->fetch();
const CC_HOLDER = 60, CC_PI = 61, CC_MEMBERS = [60, 61, 62], CC_OUTSIDER = 63, CC_TITLE = 'Closure Final Report Project – São José Study';
try {
    $result = match ($in['action']) {
        // Setup goes through the reviewed services only: explicit enrollment, reviewed reconciliation, audited PI designation.
        'setup' => (function () use ($pdo, $actor) {
            foreach ([...CC_MEMBERS, CC_OUTSIDER] as $id) {
                $pdo->prepare("INSERT INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) SELECT ?,?,?,?,'student',?,password_hash,'Active',created_at FROM users WHERE id=4")
                    ->execute([$id, '80000'.$id, 'Synthetic Student '.$id, 'student'.$id.'@example.test', '80000'.$id]);
                $pdo->prepare("INSERT INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,research_title,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at)
                    SELECT ?,?,?,?,?,2,'Stage 1','Pending',?,?,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at FROM students WHERE id=4")
                    ->execute([$id, $id, '80000'.$id, 'Synthetic Student '.$id, 'student'.$id.'@example.test', $id === CC_OUTSIDER ? 'AMT-BSIT-Y4-2627-G83' : 'AMT-BSIT-Y4-2627-G81', $id === CC_OUTSIDER ? 'Closure Outsider Study' : 'Closure Legacy Title']);
                $pdo->prepare('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4')->execute([$id]);
            }
            $enrolled = ierb_v14_enroll($pdo, $actor, CC_HOLDER, IerbStage::INITIAL, 'Synthetic closure evidence record reviewed', 'Explicit synthetic enrollment for the closure Final Report test', 'closure-enroll-request-0060');
            $mapping = [];
            foreach (CC_MEMBERS as $id) { $q = $pdo->prepare('SELECT * FROM students WHERE id=?'); $q->execute([$id]); $mapping[] = ['studentId' => $id, 'snapshotSha256' => hash('sha256', json_encode($q->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR))]; }
            $project = research_project_reconcile($pdo, $actor, CC_TITLE, CC_HOLDER, 2, $mapping, 'Synthetic RPMS reviewed the explicit closure membership mapping.');
            $p = research_project_for_student($pdo, CC_HOLDER);
            research_project_designate_pi($pdo, $actor, (int)$p['id'], (int)$p['revision'], CC_PI, 'Synthetic RPMS verified the closure Principal Investigator designation.');
            return ['enrolled' => $enrolled['ok'], 'project' => $project, 'pi' => research_project_principal_investigator($pdo, (int)$p['id'])['studentId'] ?? null];
        })(),
        'state' => (function () use ($pdo) {
            $p = research_project_for_student($pdo, CC_HOLDER);
            $w = $pdo->query('SELECT * FROM ierb_workflows WHERE student_id='.CC_HOLDER)->fetch();
            return ['project' => $p, 'workflow' => $w,
                'members' => $p ? $pdo->query('SELECT student_id,stage_key,workflow_revision FROM research_project_members WHERE project_id='.(int)$p['id'].' ORDER BY student_id')->fetchAll() : [],
                'case' => $w ? $pdo->query('SELECT * FROM ierb_correction_cases WHERE workflow_id='.(int)$w['id'].' ORDER BY id DESC LIMIT 1')->fetch() : null];
        })(),
        // Synthetic historical Adviser approval that was never forwarded ("Ready for Formal RPMS Submission"). Current Adviser
        // approvals forward in the same transaction, so this is the only state in which a Student-initiated submission applies.
        'legacy-approve' => $pdo->prepare("UPDATE documents SET review_status='Approved',reviewed_by='Synthetic Adviser 2',reviewed_at=NOW(),admin_override=0 WHERE id=? AND rpms_submitted_at IS NULL")
            ->execute([$in['documentId']]),
        'cleanup' => (function () use ($pdo) {
            $ids = [...CC_MEMBERS, CC_OUTSIDER]; $list = implode(',', $ids);
            $pdo->beginTransaction();
            $docIds = $pdo->query("SELECT id FROM documents WHERE student_id IN ($list)")->fetchAll(PDO::FETCH_COLUMN);
            $projectIds = $pdo->query("SELECT DISTINCT project_id FROM research_project_members WHERE student_id IN ($list)")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($projectIds as $pid) {
                foreach (['research_project_member_history', 'research_project_events', 'research_project_members', 'research_project_identifiers'] as $t) $pdo->prepare("DELETE FROM `$t` WHERE project_id=?")->execute([$pid]);
                $pdo->prepare('DELETE FROM research_projects WHERE id=?')->execute([$pid]);
            }
            foreach ($pdo->query("SELECT id FROM ierb_workflows WHERE student_id IN ($list)")->fetchAll(PDO::FETCH_COLUMN) as $wid) {
                $pdo->prepare('DELETE FROM ierb_workflow_events WHERE workflow_id=?')->execute([$wid]);
                $pdo->prepare('DELETE FROM ierb_correction_cases WHERE workflow_id=?')->execute([$wid]);
                $pdo->prepare('DELETE FROM ierb_workflows WHERE id=?')->execute([$wid]);
            }
            $pdo->exec("DELETE FROM documents WHERE student_id IN ($list) ORDER BY version_no DESC");
            $pdo->exec("DELETE FROM ierb_history WHERE student_id IN ($list)");
            // Unlike the 7R module, also remove this module's notices and audits so later suites see no residue.
            $pdo->exec("DELETE FROM notifications WHERE (recipient_type='student' AND recipient_id IN ($list)) OR recipient_user_id IN ($list) OR message LIKE '%Synthetic Closure%' OR message LIKE '%closure-final-report-project%'");
            if ($docIds) { $q = $pdo->prepare('DELETE FROM activity_logs WHERE entity_id IN ('.implode(',', array_fill(0, count($docIds), '?')).')'); $q->execute($docIds); }
            $pdo->exec("DELETE FROM activity_logs WHERE student_id IN ($list) OR user_email IN ('student60@example.test','student61@example.test','student62@example.test','student63@example.test')
                OR (entity_type='student' AND entity_id IN ('".implode("','", $ids)."'))");
            $pdo->exec("DELETE FROM user_policy_acceptances WHERE user_id IN ($list)");
            $pdo->exec("DELETE FROM students WHERE id IN ($list)");
            $pdo->exec("DELETE FROM users WHERE id IN ($list)");
            $pdo->commit();
            return ['removed' => $ids, 'documents' => count($docIds)];
        })(),
        default => throw new RuntimeException('Unknown closure synthetic operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'type' => get_class($e), 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
