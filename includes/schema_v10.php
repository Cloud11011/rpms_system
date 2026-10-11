<?php
/** Final B6 migration: explicit CLI target, locked, additive, retry-safe, no bootstrap config. */
function schema_v10_plan(): array
{
    return json_decode(file_get_contents(__DIR__.'/schema_v10_plan.json'), true, 512, JSON_THROW_ON_ERROR);
}

function schema_v10_check(PDO $pdo, array $checks): void
{
    foreach ($checks as $check) {
        if ((int)$pdo->query($check['sql'])->fetchColumn() !== 1) {
            throw new RuntimeException('Schema v10 contract validation failed: '.$check['message'].'. Version was not advanced.');
        }
    }
}

function migrate_schema_v10(PDO $pdo): void
{
    if (PHP_SAPI !== 'cli' || getenv('PRISM_ALLOW_SCHEMA_V10_MIGRATION') !== '1'
        || getenv('PRISM_SCHEMA_V10_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Schema v10 requires explicit CLI authorization and exact database binding.');
    }
    if ($pdo->inTransaction() || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1
        || (int)$pdo->query("SELECT COALESCE(IS_USED_LOCK('prism_migrate')=CONNECTION_ID(),0)")->fetchColumn() !== 1) {
        throw new RuntimeException('Schema v10 requires foreign-key enforcement and the migration lock, outside a transaction.');
    }
    $version=$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    if (!in_array((string)$version, ['9','10'], true)) throw new RuntimeException('Canonical v9 prerequisite required.');
    $plan=schema_v10_plan();
    schema_v10_check($pdo,$plan['preflight']);
    foreach ($plan['ddl'] as $step) {
        if ($step['exists'] === null || (int)$pdo->query($step['exists'])->fetchColumn() === 0) $pdo->exec($step['sql']);
    }
    schema_v10_check($pdo,$plan['verify']);
    $pdo->beginTransaction();
    try {
        foreach ($plan['seed'] as $sql) $pdo->exec($sql);
        schema_v10_check($pdo,$plan['invariants']);
        // Durable version stamp occurs only after schema, source hashes and approval invariants.
        $pdo->exec("REPLACE INTO schema_meta(k,v) VALUES ('schema_version','10')");
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
