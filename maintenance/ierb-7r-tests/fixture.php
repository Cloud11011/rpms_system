<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP.
/** Batch 7R synthetic helpers. Every operation is bound to bootstrap's verified disposable MariaDB datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v15.php';
require_once $root.'/includes/ierb_v14_requirements.php';
require_once $root.'/includes/report_pdf.php';
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
$actor = $pdo->query('SELECT * FROM users WHERE id='.(int)($in['actor'] ?? 1))->fetch();
const R7_MEMBERS = [40, 41, 42], R7_OUTSIDER = 43, R7_TITLE = 'Batch 7R Shared Project – Peñaflor Study';
try {
    $result = match ($in['action']) {
        'setup' => (function () use ($pdo, $actor) {
            foreach ([...R7_MEMBERS, R7_OUTSIDER] as $id) {
                $pdo->prepare("INSERT INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) SELECT ?,?,?,?,'student',?,password_hash,'Active',created_at FROM users WHERE id=4")
                    ->execute([$id, '70000'.$id, 'Synthetic Student '.$id, 'student'.$id.'@example.test', '70000'.$id]);
                $pdo->prepare("INSERT INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,research_title,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at)
                    SELECT ?,?,?,?,?,2,'Stage 1','Pending',?,?,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at FROM students WHERE id=4")
                    ->execute([$id, $id, '70000'.$id, 'Synthetic Student '.$id, 'student'.$id.'@example.test', $id === R7_OUTSIDER ? 'AMT-BSIT-Y4-2627-G73' : 'AMT-BSIT-Y4-2627-G71', $id === R7_OUTSIDER ? 'Batch 7R Outsider Study' : 'Batch 7R Legacy Title']);
                $pdo->prepare('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4')->execute([$id]);
            }
            $enrolled = ierb_v14_enroll($pdo, $actor, 40, IerbStage::INITIAL, 'Synthetic Batch 7R evidence record reviewed', 'Explicit synthetic enrollment for Batch 7R group workflow tests', 'batch7r-enroll-request-0040');
            $mapping = [];
            foreach (R7_MEMBERS as $id) { $q = $pdo->prepare('SELECT * FROM students WHERE id=?'); $q->execute([$id]); $mapping[] = ['studentId' => $id, 'snapshotSha256' => hash('sha256', json_encode($q->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR))]; }
            $project = research_project_reconcile($pdo, $actor, R7_TITLE, 40, 2, $mapping, 'Synthetic RPMS reviewed the explicit Batch 7R membership mapping.');
            return ['enrolled' => $enrolled['ok'], 'project' => $project];
        })(),
        'state' => (function () use ($pdo) {
            $p = research_project_for_student($pdo, 40);
            $w = $pdo->query('SELECT * FROM ierb_workflows WHERE student_id=40')->fetch();
            return ['project' => $p, 'workflow' => $w, 'pi' => $p ? research_project_principal_investigator($pdo, (int)$p['id']) : null,
                'case' => $w ? $pdo->query('SELECT * FROM ierb_correction_cases WHERE workflow_id='.(int)$w['id'].' ORDER BY id DESC LIMIT 1')->fetch() : null];
        })(),
        'pi' => (function () use ($pdo, $actor, $in) {
            $p = research_project_for_student($pdo, 40);
            return research_project_designate_pi($pdo, $actor, (int)$p['id'], (int)$p['revision'], (int)$in['studentId'], 'Synthetic RPMS verified the Principal Investigator designation.');
        })(),
        // Synthetic legacy approval that was never forwarded (the "Ready for Formal RPMS Submission" state).
        'legacy-approve' => $pdo->prepare("UPDATE documents SET review_status='Approved',reviewed_by='Synthetic Adviser 2',reviewed_at=NOW(),admin_override=? WHERE id=? AND rpms_submitted_at IS NULL")
            ->execute([(int)!empty($in['override']), $in['documentId']]),
        'slug' => ['slug' => research_project_slug(str_repeat('Community health literacy among senior residents ', 8)), 'short' => research_project_slug('Ñuñoa – Study!')],
        'collation' => ['anchor' => schema_extension_collation($pdo), 'extension' => $pdo->query("SELECT TABLE_NAME,TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('ierb_workflows','ierb_correction_cases','ierb_workflow_events','research_projects','research_project_identifiers','research_project_members','research_project_events','research_project_member_history','document_summary_versions','ierb_report_snapshots','academic_years') ORDER BY 1")->fetchAll(PDO::FETCH_KEY_PAIR)],
        'pdf-text' => (function () use ($in, $root) {
            require_once $root.'/vendor/autoload.php';
            $file = REPORTS_DIR.'/'.basename((string)$in['filename']);
            return ['text' => (new Smalot\PdfParser\Parser())->parseFile($file)->getText()];
        })(),
        'cleanup' => (function () use ($pdo) {
            $ids = [...R7_MEMBERS, R7_OUTSIDER]; $in = implode(',', $ids);
            $pdo->beginTransaction();
            $projectIds = $pdo->query("SELECT DISTINCT project_id FROM research_project_members WHERE student_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($projectIds as $pid) {
                foreach (['research_project_member_history', 'research_project_events', 'research_project_members', 'research_project_identifiers'] as $t) $pdo->prepare("DELETE FROM `$t` WHERE project_id=?")->execute([$pid]);
                $pdo->prepare('DELETE FROM research_projects WHERE id=?')->execute([$pid]);
            }
            foreach ($pdo->query("SELECT id FROM ierb_workflows WHERE student_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $wid) {
                $pdo->prepare('DELETE FROM ierb_workflow_events WHERE workflow_id=?')->execute([$wid]);
                $pdo->prepare('DELETE FROM ierb_correction_cases WHERE workflow_id=?')->execute([$wid]);
                $pdo->prepare('DELETE FROM ierb_workflows WHERE id=?')->execute([$wid]);
            }
            $pdo->exec("DELETE FROM documents WHERE student_id IN ($in) ORDER BY version_no DESC");
            $pdo->exec("DELETE FROM ierb_history WHERE student_id IN ($in)");
            $pdo->exec("DELETE FROM user_policy_acceptances WHERE user_id IN ($in)");
            // Reserved IDs: stale identity audits from earlier suites that reused them would make login resolution conflict.
            $pdo->exec("DELETE FROM activity_logs WHERE entity_type='student' AND entity_id IN ('".implode("','", $ids)."')");
            $pdo->exec("DELETE FROM students WHERE id IN ($in)");
            $pdo->exec("DELETE FROM users WHERE id IN ($in)");
            $pdo->commit();
            return ['removed' => $ids];
        })(),
        default => throw new RuntimeException('Unknown Batch 7R synthetic operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'type' => get_class($e), 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
