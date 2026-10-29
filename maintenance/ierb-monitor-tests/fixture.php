<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
/** This helper refuses every database outside the verified disposable datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v15.php';
require_once $root.'/includes/ierb_monitor.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
foreach ([13,14,15] as $v) { putenv('PRISM_ALLOW_SCHEMA_V'.$v.'_MIGRATION=1'); putenv('PRISM_SCHEMA_V'.$v.'_EXPECT_DB=prism_group_synthetic_v11'); }
putenv('PRISM_IERB_V14_FOUNDATION=1');
try {
    $result=match ($in['action']) {
        'install'=>(function()use($pdo,$root){
            $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
            if ($version==='12') { migrate_schema_v13($pdo,'apply'); migrate_schema_v14($pdo,'apply'); }
            if (!is_file($root.'/includes/academic_schema_v15.json')) {
                // One-time contract capture is permitted only in this guarded empty fixture.
                $contract=[];
                foreach (schema_v15_tables() as $name=>$ddl) { $pdo->exec($ddl); $contract[$name]=schema_v15_definition($pdo,$name); }
                foreach (array_keys(schema_v15_tables()) as $name) $pdo->exec('DROP TABLE `'.$name.'`');
                file_put_contents($root.'/includes/academic_schema_v15.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
            }
            $before=lifecycle_schema_inventory($pdo); $records=$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll();
            migrate_schema_v15($pdo);
            if ($before!==lifecycle_schema_inventory($pdo) || $records!==$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll()) throw new RuntimeException('Dry run changed retained data.');
            migrate_schema_v15($pdo,'apply'); lifecycle_compare_schema($pdo);
            if ($records!==$pdo->query('SELECT * FROM students ORDER BY id')->fetchAll()) throw new RuntimeException('Migration changed assignments.');
            return ['dryRunUnchanged'=>true,'studentsPreserved'=>true,'catalog'=>academic_years($pdo)];
        })(),
        'matrix'=>(function()use($pdo){
            $checks=[]; $refuses=static function(callable $f,string $label)use(&$checks){try{$f();}catch(Throwable $e){$checks[]=$label;return;}throw new RuntimeException('Unexpected success: '.$label);};
            putenv('PRISM_ALLOW_SCHEMA_V15_MIGRATION'); $refuses(fn()=>migrate_schema_v15($pdo),'Operator opt-in required'); putenv('PRISM_ALLOW_SCHEMA_V15_MIGRATION=1');
            putenv('PRISM_SCHEMA_V15_EXPECT_DB=other'); $refuses(fn()=>migrate_schema_v15($pdo),'Exact database binding required'); putenv('PRISM_SCHEMA_V15_EXPECT_DB=prism_group_synthetic_v11');
            $pdo->beginTransaction(); $refuses(fn()=>migrate_schema_v15($pdo),'Open transaction refused'); $pdo->rollBack();
            $pdo->exec('SET foreign_key_checks=0'); $refuses(fn()=>migrate_schema_v15($pdo),'Disabled foreign keys refused'); $pdo->exec('SET foreign_key_checks=1');
            migrate_schema_v15($pdo,'apply'); $checks[]='Apply replay preserves all existing years';
            $refuses(fn()=>migrate_schema_v15($pdo,'rollback'),'Retained academic-year rollback refused');
            $pdo->exec('ALTER TABLE academic_years ADD drift INT NULL'); $refuses(fn()=>migrate_schema_v15($pdo,'apply'),'Schema drift refused'); $pdo->exec('ALTER TABLE academic_years DROP drift');
            $pdo->exec('RENAME TABLE academic_years TO fixture_retained_years'); $refuses(fn()=>migrate_schema_v15($pdo,'apply'),'Published missing table refused'); $pdo->exec('RENAME TABLE fixture_retained_years TO academic_years');
            $refuses(fn()=>migrate_schema_v14($pdo,'rollback'),'Older migration cannot bypass schema 15 retention');
            $refuses(fn()=>$pdo->exec("INSERT INTO academic_years(year_label,historical,added_by_user_id) VALUES ('2030-2032',0,1)"),'Database rejects invalid consecutive-year creation');
            $pdo->exec('DELETE FROM academic_years'); migrate_schema_v15($pdo,'rollback'); schema_v14_verify($pdo);
            $checks[]='Empty fixture rollback preserves every schema 11-14 contract';
            $pdo->exec(schema_v15_tables(schema_extension_collation($pdo))['academic_years']); migrate_schema_v15($pdo); migrate_schema_v15($pdo,'apply');
            $checks[]='Interrupted unpublished additive DDL resumes without changing assignments';
            $pdo->exec('DELETE FROM academic_years'); $pdo->exec("UPDATE schema_meta SET v='14' WHERE k='schema_version'"); migrate_schema_v15($pdo,'rollback');
            migrate_schema_v15($pdo,'apply'); $checks[]='Interrupted empty rollback and reapply retain historical-year discovery';
            lifecycle_compare_schema($pdo); return $checks;
        })(),
        'add'=>academic_year_add($pdo,$GLOBALS['actor'],$in['year']),
        'catalog'=>academic_catalog(),
        'cleanup'=>(function()use($pdo){
            // Deliberate fixture-only teardown, never an operational rollback path.
            $pdo->exec('DELETE FROM academic_years'); migrate_schema_v15($pdo,'rollback');
            if (!empty($GLOBALS['in']['only15'])) return ['ok'=>true];
            $pdo->exec('DELETE FROM ierb_report_snapshots'); $pdo->exec('DELETE FROM document_summary_versions'); migrate_schema_v14($pdo,'rollback');
            return ['ok'=>true];
        })(),
        default=>throw new RuntimeException('Unknown fixture action'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR); exit(1); }
