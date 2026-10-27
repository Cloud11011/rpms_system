<?php
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v13.php';
require_once __DIR__.'/migration-cases.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
putenv('PRISM_IERB_V14_FOUNDATION=1');
putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION=1');
putenv('PRISM_SCHEMA_V13_EXPECT_DB=prism_group_synthetic_v11');
$actor=$pdo->query('SELECT * FROM users WHERE id='.(int)($in['actor'] ?? 1))->fetch();
try {
    $result=match ($in['action']) {
        'snapshot'=>(function () use ($pdo) {
            $digests=[];
            foreach (['ierb_workflows','research_projects','research_project_members','research_project_events','research_project_member_history','ierb_workflow_events','documents','ierb_history','notifications','activity_logs','notification_campaigns'] as $table) {
                $rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
                $digests[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
            }
            return hash('sha256',json_encode($digests,JSON_THROW_ON_ERROR));
        })(),
        'migration-tests'=>project_migration_tests($pdo),
        'migrate'=>migrate_schema_v13($pdo,$in['mode'] ?? 'apply'),
        'mapping'=>(function () use ($pdo,$in) {
            $out=[];
            foreach ($in['ids'] as $id) { $q=$pdo->prepare('SELECT * FROM students WHERE id=?'); $q->execute([$id]); $r=$q->fetch(PDO::FETCH_ASSOC); $out[]=['studentId'=>(int)$id,'snapshotSha256'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR))]; }
            return $out;
        })(),
        'create'=>research_project_reconcile($pdo,$actor,$in['title'],$in['holderId'],$in['adviserId'],$in['mapping'],'Synthetic administrator reviewed explicit legacy membership mapping.',$in['historicalCompletionReference'] ?? ''),
        'rename'=>research_project_rename($pdo,$actor,$in['projectId'],$in['revision'],$in['title'],'Synthetic administrator approved shared research title update.'),
        'adviser'=>research_project_reassign_adviser($pdo,$actor,$in['projectId'],$in['revision'],$in['adviserId'],'Synthetic administrator verified the shared Adviser assignment.'),
        'advance'=>(function () use ($pdo,$actor,$in) {
            $_SESSION=['user_id'=>$actor['id'],'login_generation'=>'synthetic-project-generation'];
            $preview=ierb_group_preview($pdo,$actor,hash('sha256','project:'.$in['projectId']));
            if (!$preview['eligible']) return $preview;
            return ierb_group_advance($pdo,$actor,['confirmationToken'=>$preview['confirmationToken'],'reason'=>'Synthetic Administrator verified all project requirements.','confirmed'=>true]);
        })(),
        'cleanup'=>(function () use ($pdo) {
            $pdo->beginTransaction();
            foreach ($pdo->query('SELECT legacy_identity_json FROM research_project_members')->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $r=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                $pdo->prepare('UPDATE students SET research_group=?,research_title=?,adviser_id=? WHERE id=?')->execute([$r['research_group'],$r['research_title'],$r['adviser_id'],$r['id']]);
            }
            foreach (array_reverse(array_keys(schema_v13_tables())) as $name) $pdo->exec('DELETE FROM `'.$name.'`');
            $pdo->commit(); return ['ok'=>true];
        })(),
        default=>throw new RuntimeException('Unknown synthetic project action.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'type'=>get_class($e),'message'=>$e->getMessage()]); }
