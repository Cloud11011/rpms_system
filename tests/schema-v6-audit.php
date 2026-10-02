<?php
/** Additional isolated v6 fixtures; inherits the strict existing migration fixture. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/academic-migration-audit.php';
$v6Start = $checks;
function v6_fixture(): MigrationFixturePDO { return new MigrationFixturePDO('5'); }
function v6_alters(MigrationFixturePDO $pdo): array
{
    return array_values(array_filter($pdo->events, fn($sql) => str_starts_with($sql, 'ALTER TABLE')));
}
$GLOBALS['migrationFlag'] = '1';
$run = 'PrismAcademicMigrationCLI\\migrate';
$pdo = v6_fixture();
$before = $pdo->rows;
$run($pdo);
migration_expect('6', $pdo->version, 'Already-correct v5 advances to v6');
migration_expect([], v6_alters($pdo), 'Already-correct keys/FKs perform no ALTER');
migration_expect($before, $pdo->rows, 'V5 to v6 does not rerun academic migration/backfill');
foreach (['students', 'ierb_history', 'notifications'] as $table) {
    foreach (['int(11)', 'bigint(20) unsigned', 'smallint(5) unsigned zerofill'] as $type) {
        $pdo = v6_fixture();
        $pdo->integerIds[$table] = array_replace($pdo->idMetadata($table), [
            'COLUMN_TYPE' => $type, 'EXTRA' => '', 'COLUMN_COMMENT' => "Keep owner's key comment",
        ]);
        $pdo->sqlMode = 'STRICT_TRANS_TABLES';
        $before = $pdo->integerIds[$table];
        $run($pdo);
        $before['EXTRA'] = 'auto_increment';
        migration_expect($before, $pdo->integerIds[$table], "$table preserves type/signedness/width/comment");
        migration_expect('STRICT_TRANS_TABLES', $pdo->sqlMode, "$table restores SQL mode");
        migration_expect(1, count(v6_alters($pdo)), "$table needs exactly one repair");
        $run($pdo);
        migration_expect(1, count(v6_alters($pdo)), "$table rerun does not ALTER");
    }
    foreach ([
        ['COLUMN_TYPE' => 'varchar(20)'], ['IS_NULLABLE' => 'YES'], ['COLUMN_KEY' => 'UNI'],
        ['EXTRA' => 'STORED GENERATED'], ['COLUMN_NAME' => 'wrong'],
    ] as $invalid) {
        $pdo = v6_fixture();
        $pdo->integerIds[$table] = array_replace($pdo->idMetadata($table), $invalid);
        migration_failure(fn() => $run($pdo), "Unsupported $table.id", "$table invalid key fails closed");
        migration_expect('5', $pdo->version, "$table invalid key never stamps v6");
        migration_expect([], v6_alters($pdo), "$table invalid key is not rewritten");
    }
    $pdo = v6_fixture();
    $pdo->idPrimaryCounts[$table] = 2;
    migration_failure(fn() => $run($pdo), "Unsupported $table.id", "$table composite key fails closed");
    $pdo = v6_fixture();
    $pdo->integerIds[$table] = [];
    migration_failure(fn() => $run($pdo), "Missing $table.id", "$table missing key fails closed");
    foreach (['alter', 'verify'] as $failure) {
        $pdo = v6_fixture();
        $pdo->integerIds[$table] = array_replace($pdo->idMetadata($table), ['EXTRA' => '']);
        if ($failure === 'alter') $pdo->failOnce = "ALTER TABLE `$table`";
        else $pdo->ignoreIdAlters[$table] = true;
        migration_failure(fn() => $run($pdo), $failure === 'alter' ? 'Injected migration failure' : 'repair could not be verified',
            "$table $failure failure propagates");
        migration_expect('5', $pdo->version, "$table failure preserves v5");
        migration_expect('', $pdo->sqlMode, "$table failure restores SQL mode");
        migration_expect(false, $pdo->locked, "$table failure releases lock");
        $pdo->ignoreIdAlters = [];
        $run($pdo);
        migration_expect('6', $pdo->version, "$table retry succeeds");
    }
}
foreach (PrismAcademicMigrationCLI\migration_required_foreign_keys() as [$table, $column, $parent, $delete]) {
    $key = "$table.$column";
    $pdo = v6_fixture();
    $pdo->fkStates[$key] = 'missing';
    $run($pdo);
    migration_expect('correct', $pdo->fkStates[$key], "$key missing relationship added");
    migration_expect(1, count(v6_alters($pdo)), "$key adds exactly one FK");
    $run($pdo);
    migration_expect(1, count(v6_alters($pdo)), "$key rerun does not duplicate FK");
    foreach ([
        ['DELETE_RULE' => 'RESTRICT'], ['REFERENCED_TABLE_NAME' => 'other_parent'],
        ['REFERENCED_TABLE_SCHEMA' => 'other_database'], ['REFERENCED_COLUMN_NAME' => 'other_key'],
        ['UPDATE_RULE' => 'CASCADE'], 'composite',
    ] as $conflict) {
        $pdo = v6_fixture();
        $pdo->fkStates[$key] = $conflict;
        migration_failure(fn() => $run($pdo), 'Conflicting foreign key', "$key conflict needs review");
        migration_expect('5', $pdo->version, "$key conflict never stamps v6");
        migration_expect([], v6_alters($pdo), "$key unexpected relationship is not replaced");
    }
    foreach (['missing', 'correct'] as $state) {
        $pdo = v6_fixture();
        $pdo->fkStates[$key] = $state;
        $pdo->orphans[$key] = true;
        migration_failure(fn() => $run($pdo), 'Orphan rows', "$key orphan is rejected even with an existing constraint");
        migration_expect('5', $pdo->version, "$key orphans block version stamp");
        migration_expect([], v6_alters($pdo), "$key orphan preflight adds no FK");
    }
    foreach (['alter', 'verify'] as $failure) {
        $pdo = v6_fixture();
        $pdo->fkStates[$key] = 'missing';
        if ($failure === 'alter') $pdo->failOnce = "ALTER TABLE `$table` ADD FOREIGN KEY (`$column`)";
        else $pdo->ignoreFkAlters[$key] = true;
        migration_failure(fn() => $run($pdo), $failure === 'alter' ? 'Injected migration failure' : 'could not be verified',
            "$key $failure failure blocks stamp");
        migration_expect('5', $pdo->version, "$key failure leaves v5");
        migration_expect(false, $pdo->locked, "$key failure releases lock");
        $pdo->ignoreFkAlters = [];
        $run($pdo);
        migration_expect('6', $pdo->version, "$key retry succeeds");
    }
    foreach ([
        ['DATA_TYPE' => 'bigint'], ['COLUMN_TYPE' => 'int(10) unsigned'], ['ENGINE' => 'MyISAM'],
        ['EXTRA' => 'VIRTUAL GENERATED'],
    ] as $invalid) {
        $pdo = v6_fixture();
        $pdo->fkStates[$key] = 'missing';
        $pdo->fkColumnOverrides[$key] = $invalid;
        migration_failure(fn() => $run($pdo), 'Incompatible columns/engine', "$key unsafe prerequisites fail closed");
        migration_expect('5', $pdo->version, "$key unsafe prerequisites block v6");
    }
    if ($delete === 'SET NULL') {
        $pdo = v6_fixture();
        $pdo->fkColumnOverrides[$key] = ['IS_NULLABLE' => 'NO'];
        migration_failure(fn() => $run($pdo), 'Incompatible columns/engine', "$key SET NULL requires nullable column");
    }
}
$pdo = v6_fixture();
$pdo->foreignKeyChecks = false;
migration_failure(fn() => $run($pdo), 'foreign_key_checks=1', 'Disabled FK enforcement is not accepted');
migration_expect([], v6_alters($pdo), 'Disabled FK enforcement performs zero ALTER');
foreach (['parentIndexValid', 'childIndexValid'] as $property) {
    $pdo = v6_fixture();
    $pdo->$property = false;
    migration_failure(fn() => $run($pdo), $property === 'parentIndexValid' ? 'parent primary key' : 'child index',
        'Incompatible indexes fail closed');
    migration_expect('5', $pdo->version, 'Incompatible indexes block v6 stamp');
}
$pdo = v6_fixture();
$pdo->integerIds['students'] = array_replace($pdo->idMetadata('students'), ['EXTRA' => '']);
$pdo->fkStates['students.user_id'] = 'missing';
$pdo->failOnce = 'REPLACE INTO schema_meta';
migration_failure(fn() => $run($pdo), 'Injected migration failure', 'Final version-write failure propagates');
migration_expect('5', $pdo->version, 'Final version-write failure retains v5');
$alters = v6_alters($pdo);
$run($pdo);
migration_expect('6', $pdo->version, 'Version-write retry succeeds');
migration_expect($alters, v6_alters($pdo), 'Version-write retry does not repeat completed DDL');
echo 'PASS: ' . ($checks - $v6Start) . " additional isolated v6 checks.\n";
