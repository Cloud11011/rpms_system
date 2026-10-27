<?php
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v14.php';
require_once $root.'/includes/ierb_track_report.php';
require_once $root.'/includes/report_pdf.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION=1');putenv('PRISM_SCHEMA_V13_EXPECT_DB=prism_group_synthetic_v11');
putenv('PRISM_ALLOW_SCHEMA_V14_MIGRATION=1');putenv('PRISM_SCHEMA_V14_EXPECT_DB=prism_group_synthetic_v11');
putenv('PRISM_IERB_V14_FOUNDATION=1');
try {
    $result=match($in['action']) {
        'install'=>(function()use($pdo,$root){
            if (!summary_history_available($pdo)) migrate_schema_v13($pdo,'apply');
            if (!is_file($root.'/includes/summary_schema_v14.json')) {
                // Initial contract capture is permitted ONLY in this datadir-guarded empty fixture.
                $contract=[];
                foreach(schema_v14_tables() as $name=>$ddl){$pdo->exec($ddl);$contract[$name]=schema_v14_definition($pdo,$name);}
                foreach(array_reverse(array_keys(schema_v14_tables())) as $name)$pdo->exec('DROP TABLE `'.$name.'`');
                file_put_contents($root.'/includes/summary_schema_v14.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
            }
            $before=lifecycle_schema_inventory($pdo);migrate_schema_v14($pdo);
            if ($before!==lifecycle_schema_inventory($pdo))throw new RuntimeException('Dry run mutated schema');
            migrate_schema_v14($pdo,'apply');schema_v14_verify($pdo);lifecycle_compare_schema($pdo);
            return ['ok'=>true,'dryRunUnchanged'=>true];
        })(),
        'project'=>(function()use($pdo){
            $actor=$GLOBALS['actor'];
            ierb_v14_enroll($pdo,$actor,4,IerbStage::INITIAL,'Synthetic Batch4 reviewed enrollment','Synthetic documented explicit context','batch4-enrollment-request-0001');
            $pdo->exec("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G97' WHERE id IN (4,6)");
            $mapping=[];
            foreach([4,6] as $id){$q=$pdo->prepare('SELECT * FROM students WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$mapping[]=['studentId'=>$id,'snapshotSha256'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];}
            return research_project_reconcile($pdo,$actor,'Synthetic study of research monitoring and the organization of institutional ethics documentation across several academic programmes with a long research title for PDF wrapping verification',4,2,$mapping,'Synthetic Batch4 reviewed explicit membership');
        })(),
        'snapshot'=>(function()use($pdo,$in){$pdo->beginTransaction();research_project_fence($pdo);$s=track_project_snapshot($pdo,$GLOBALS['actor'],$in['projectId']);$pdo->commit();return $s;})(),
        'privacy'=>(function()use($pdo,$in){
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM documents WHERE id=?');$q->execute([$in['documentId']]);$doc=$q->fetch(PDO::FETCH_ASSOC);
            $pdo->exec("UPDATE students SET research_group='Synthetic stale legacy label' WHERE id=6");
            $terms=document_summary_identifiers($pdo,$doc,$GLOBALS['actor']);$pdo->rollBack();return $terms;
        })(),
        'completed-evidence'=>(function()use($pdo,$root){
            $id=(int)$pdo->query('SELECT id FROM research_projects LIMIT 1')->fetchColumn();
            $pdo->beginTransaction();research_project_fence($pdo);$s=track_project_snapshot($pdo,$GLOBALS['actor'],$id);$pdo->commit();
            if ($s['workflow']['stage_key']!==IerbStage::COMPLETED || !$s['project']['completed_at'] || $s['checklist']['workflow']['progress']!==100)throw new RuntimeException('Completion report mismatch');
            if (!$s['tracking']['overflowEvidence'])throw new RuntimeException('Historical overflow not retained');
            file_put_contents($root.'/PRISM_V14_BATCH_04_EVIDENCE/completed-project.pdf',make_pdf('CEU RPMS - IERB Track Sheet',track_report_blocks($s)));
            file_put_contents($root.'/PRISM_V14_BATCH_04_EVIDENCE/completed-project.json',json_encode($s,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            return ['completed'=>true,'progress'=>100,'trackingRows'=>count($s['tracking']['rows']),'officialCycles'=>$s['tracking']['officialResubmissionCount']];
        })(),
        'retained-rollback'=>(function()use($pdo){try{migrate_schema_v14($pdo,'rollback');}catch(RuntimeException $e){return ['refused'=>true];}throw new RuntimeException('Retained rollback accepted');})(),
        'migration-tests'=>(function()use($pdo){
            $checks=[];$refuses=static function(callable $f,string $name)use(&$checks){try{$f();}catch(Throwable $e){$checks[]=$name;return;}throw new RuntimeException('Unexpected success '.$name);};
            putenv('PRISM_ALLOW_SCHEMA_V14_MIGRATION');$refuses(fn()=>migrate_schema_v14($pdo),'Migration requires operator authorization');putenv('PRISM_ALLOW_SCHEMA_V14_MIGRATION=1');
            putenv('PRISM_SCHEMA_V14_EXPECT_DB=wrong');$refuses(fn()=>migrate_schema_v14($pdo),'Migration requires exact database binding');putenv('PRISM_SCHEMA_V14_EXPECT_DB=prism_group_synthetic_v11');
            $pdo->beginTransaction();$refuses(fn()=>migrate_schema_v14($pdo),'Migration rejects open transactions');$pdo->rollBack();
            $pdo->exec('SET foreign_key_checks=0');$refuses(fn()=>migrate_schema_v14($pdo),'Migration requires foreign keys');$pdo->exec('SET foreign_key_checks=1');
            migrate_schema_v14($pdo,'apply');$checks[]='Apply replay is idempotent';
            $pdo->exec('ALTER TABLE document_summary_versions ADD drift INT NULL');$refuses(fn()=>migrate_schema_v14($pdo,'apply'),'Schema drift is rejected');$pdo->exec('ALTER TABLE document_summary_versions DROP drift');
            $pdo->exec('DROP TABLE ierb_report_snapshots');$refuses(fn()=>migrate_schema_v14($pdo,'apply'),'Published missing table is not silently recreated');$pdo->exec(schema_v14_tables()['ierb_report_snapshots']);
            migrate_schema_v14($pdo,'rollback');migrate_schema_v14($pdo,'apply');$checks[]='Empty rollback and reapply preserve exact contract';
            return $checks;
        })(),
        'layout'=>(function()use($pdo,$in,$root){
            $pdo->beginTransaction();research_project_fence($pdo);$s=track_project_snapshot($pdo,$GLOBALS['actor'],$in['projectId']);$pdo->commit();
            // Layout stress data is synthetic, visibly labeled, and never persisted as project facts.
            $s['notes'][]='SYNTHETIC LAYOUT STRESS FIXTURE. '.str_repeat('Verification notes must wrap cleanly and continue across multiple pages. ',65);
            foreach($s['checklist']['requirements'] as &$r)$r['label'].=str_repeat(' synthetic long requirement label',7);unset($r);
            $path=$root.'/PRISM_V14_BATCH_04_EVIDENCE/layout-stress.pdf';file_put_contents($path,make_pdf('CEU RPMS - IERB Track Sheet',track_report_blocks($s)));return ['path'=>$path];
        })(),
        'cleanup'=>(function()use($pdo){
            $pdo->exec('DELETE FROM ierb_report_snapshots');$pdo->exec('DELETE FROM document_summary_versions');migrate_schema_v14($pdo,'rollback');return ['ok'=>true];
        })(),
        default=>throw new RuntimeException('Unknown fixture action'),
    };echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR);exit(1);}
