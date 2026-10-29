<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
function project_migration_tests(PDO $pdo): array
{
    $cases=[];
    $check=static function (bool $ok,string $name) use (&$cases) { if (!$ok) throw new RuntimeException('FAIL: '.$name); $cases[]=$name; };
    $refuses=static function (callable $fn,string $name) use ($check) { try { $fn(); } catch (RuntimeException $e) { $check(true,$name); return; } throw new RuntimeException('Unexpected success: '.$name); };
    $fingerprint=static function () use ($pdo) { $out=[]; foreach (['students','documents','ierb_history','activity_logs','ierb_workflows','ierb_correction_cases','ierb_workflow_events'] as $t) $out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC); return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR)); };
    $before=$fingerprint(); $inventory=lifecycle_schema_inventory($pdo);
    putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION');
    $refuses(fn()=>migrate_schema_v13($pdo),'Project migration requires explicit operator opt-in');
    putenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION=1'); putenv('PRISM_SCHEMA_V13_EXPECT_DB=wrong_database');
    $refuses(fn()=>migrate_schema_v13($pdo),'Project migration rejects the wrong database binding');
    putenv('PRISM_SCHEMA_V13_EXPECT_DB=prism_group_synthetic_v11');
    $pdo->beginTransaction(); $refuses(fn()=>migrate_schema_v13($pdo),'Project migration refuses an open transaction'); $pdo->rollBack();
    $pdo->exec('SET foreign_key_checks=0'); $refuses(fn()=>migrate_schema_v13($pdo),'Project migration requires foreign-key enforcement'); $pdo->exec('SET foreign_key_checks=1');
    $check(migrate_schema_v13($pdo)['mode']==='dry-run' && lifecycle_schema_inventory($pdo)===$inventory && $fingerprint()===$before,'Default project dry run changes no schema or retained rows');
    $check(migrate_schema_v13($pdo,'apply')['to']==='13' && $fingerprint()===$before,'Additive project migration preserves existing V14 enrollment and all legacy records');
    schema_v13_verify($pdo); lifecycle_compare_schema($pdo);
    $check(!(int)$pdo->query('SELECT COUNT(*) FROM research_project_members')->fetchColumn(),'Project migration infers zero memberships from titles or labels');
    $after=lifecycle_schema_inventory($pdo); migrate_schema_v13($pdo,'apply');
    $check(lifecycle_schema_inventory($pdo)===$after,'Project migration replay is idempotent');
    $pdo->exec('ALTER TABLE research_projects ADD unexpected_column INT NULL');
    $refuses(fn()=>migrate_schema_v13($pdo,'apply'),'Project schema drift fails closed');
    $refuses(fn()=>lifecycle_compare_schema($pdo),'Permanent deletion rejects project schema drift');
    $pdo->exec('ALTER TABLE research_projects DROP unexpected_column');
    $pdo->exec('DROP TABLE research_project_member_history');
    $refuses(fn()=>migrate_schema_v13($pdo,'apply'),'Published project schema with a missing table is not silently recreated');
    $pdo->exec(schema_v13_tables(schema_extension_collation($pdo))['research_project_member_history']);
    migrate_schema_v13($pdo,'rollback');
    $check(lifecycle_schema_inventory($pdo)===$inventory && $fingerprint()===$before,'Empty project rollback restores the exact schema 12 contract and retained records');
    $pdo->exec(schema_v13_tables(schema_extension_collation($pdo))['research_projects']); migrate_schema_v13($pdo,'apply');
    $check($fingerprint()===$before,'Interrupted project apply resumes without data rewriting');
    $pdo->exec("UPDATE schema_meta SET v='12' WHERE k='schema_version'"); $pdo->exec('DROP TABLE research_project_member_history');
    migrate_schema_v13($pdo,'rollback');
    $check(lifecycle_schema_inventory($pdo)===$inventory,'Interrupted project rollback resumes safely');
    return ['status'=>'PASS','assertions'=>count($cases),'cases'=>$cases];
}
