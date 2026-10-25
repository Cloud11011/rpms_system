<?php
/** Authorized additive stage-guidance contract. Frozen v10 artifacts remain intact. */
require_once __DIR__.'/schema_v10.php';

function schema_v11_manifest_file(): string { return __DIR__.'/account_lifecycle_schema_v11.json'; }

/** Compare the complete reviewed inventory, including constraints outside the manifest. */
function schema_v11_verify(PDO $pdo, string $version = '11'): void
{
    require_once __DIR__.'/account_lifecycle.php';
    $expected=json_decode(file_get_contents(schema_v11_manifest_file()),true,512,JSON_THROW_ON_ERROR);
    $expected['schema_version']=$version;
    $actual=lifecycle_schema_inventory($pdo);
    foreach ($expected as $key=>$value) {
        if (json_encode($actual[$key]??null,JSON_NUMERIC_CHECK)!==json_encode($value,JSON_NUMERIC_CHECK)) {
            throw new RuntimeException('Schema v11 contract validation failed: '.$key.'.');
        }
    }
    $checks=schema_v10_plan()['verify'];
    foreach ($checks as &$check) if ($check['message']==='Exact v10 columns') {
        $check=['sql'=>'SELECT COUNT(*)=241 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()', 'message'=>'Exact v11 columns'];
    }
    unset($check);
    schema_v10_check($pdo,$checks);
}

/** Explicit CLI only, exact target binding, advisory lock, additive and restartable. */
function migrate_schema_v11(PDO $pdo): void
{
    if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SCHEMA_V11_MIGRATION')!=='1'
        || getenv('PRISM_SCHEMA_V11_EXPECT_DB')!==$pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Schema v11 requires explicit CLI authorization and exact database binding.');
    }
    if ($pdo->inTransaction() || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new RuntimeException('Schema v11 requires foreign-key enforcement outside a transaction.');
    }
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',30)")->fetchColumn()!==1) throw new RuntimeException('The migration lock is unavailable.');
    try {
        $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
        if (!in_array($version,['10','11'],true)) throw new RuntimeException('A verified canonical v10 checkpoint is required.');
        $column=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_labels' AND COLUMN_NAME='guidance'")->fetchColumn();
        if ($version==='11') { schema_v11_verify($pdo); return; }
        if (!$column) {
            // Verify exact v10 before any DDL; never repair arbitrary drift.
            require_once __DIR__.'/account_lifecycle.php';
            $expected=json_decode(file_get_contents(__DIR__.'/account_lifecycle_schema.json'),true,512,JSON_THROW_ON_ERROR);
            $actual=lifecycle_schema_inventory($pdo);
            foreach ($expected as $key=>$value) if (json_encode($actual[$key]??null,JSON_NUMERIC_CHECK)!==json_encode($value,JSON_NUMERIC_CHECK)) {
                throw new RuntimeException('The canonical v10 prerequisite differs: '.$key.'.');
            }
            schema_v10_check($pdo,schema_v10_plan()['verify']);
            $pdo->exec('ALTER TABLE stage_labels ADD COLUMN guidance TEXT NULL AFTER label');
        }
        // A crash after implicit-commit ALTER may leave version=10. Accept only the exact additive shape.
        schema_v11_verify($pdo,'10');
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("UPDATE schema_meta SET v='11' WHERE k='schema_version' AND v='10'");
            $q->execute();
            if ($q->rowCount()!==1) throw new RuntimeException('Schema version changed during migration.');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        schema_v11_verify($pdo);
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
