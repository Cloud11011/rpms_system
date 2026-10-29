<?php
/** Additive academic-year catalog. Explicit CLI migration only. */
require_once __DIR__.'/schema_v14.php';
require_once __DIR__.'/academic_years.php';

/** Pinned DDL rendered for the legacy base collation; year labels are compared with students.academic_year. */
function schema_v15_tables(string $collation=SCHEMA_PINNED_COLLATION): array
{
    return array_map(static fn($ddl)=>schema_extension_ddl($ddl,$collation),['academic_years'=>"CREATE TABLE academic_years (
        year_label VARCHAR(9) NOT NULL PRIMARY KEY,
        historical TINYINT(1) NOT NULL DEFAULT 0,
        added_by_user_id INT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        CONSTRAINT academic_year_actor_fk FOREIGN KEY (added_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT academic_year_format CHECK (historical=1 OR (year_label REGEXP '^[1-9][0-9]{3}-[1-9][0-9]{3}$' AND CAST(RIGHT(year_label,4) AS UNSIGNED)=CAST(LEFT(year_label,4) AS UNSIGNED)+1)),
        CONSTRAINT academic_year_origin CHECK (historical IN (0,1) AND (historical=1 OR added_by_user_id IS NOT NULL))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"]);
}

function schema_v15_definition(PDO $pdo,string $name): string
{
    if (!isset(schema_v15_tables()[$name])) throw new LogicException('Unknown academic-year table.');
    return $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
}

function schema_v15_verify(PDO $pdo,bool $partial=false): void
{
    schema_v14_verify($pdo,false,array_keys(schema_v15_tables()));
    $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    if (!in_array($version,['14','15'],true)) throw new RuntimeException('Reviewed schema 14 or 15 required.');
    $contract=json_decode(file_get_contents(__DIR__.'/academic_schema_v15.json'),true,512,JSON_THROW_ON_ERROR);
    $collation=schema_extension_collation($pdo);
    $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_keys(schema_v15_tables()) as $name) {
        if (!in_array($name,$present,true)) {
            if (!$partial || $version==='15') throw new RuntimeException('Incomplete academic-year schema.');
        } elseif (!schema_extension_matches($pdo,$name,schema_v15_definition($pdo,$name),$contract[$name],$collation)) throw new RuntimeException('Academic-year schema drift: '.$name.' (legacy base '.$collation.').');
    }
}

function migrate_schema_v15(PDO $pdo,string $mode='dry-run'): array
{
    if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SCHEMA_V15_MIGRATION')!=='1'
        || getenv('PRISM_SCHEMA_V15_EXPECT_DB')!==$pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Explicit CLI authorization and database binding required.');
    if (!in_array($mode,['dry-run','apply','rollback'],true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) throw new RuntimeException('Invalid migration preconditions.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new RuntimeException('Schema maintenance busy.');
    try {
        schema_v15_verify($pdo,true);
        $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $years=academic_years($pdo);
        if ($mode==='rollback') {
            if (in_array('academic_years',$present,true) && (int)$pdo->query('SELECT COUNT(*) FROM academic_years')->fetchColumn()) throw new RuntimeException('Retained academic years block rollback. Disable additions and retain the catalog.');
            $pdo->exec("UPDATE schema_meta SET v='14' WHERE k='schema_version'");
            if (in_array('academic_years',$present,true)) $pdo->exec('DROP TABLE academic_years');
            schema_v14_verify($pdo);
        } elseif ($mode==='apply') {
            foreach (schema_v15_tables(schema_extension_collation($pdo)) as $name=>$ddl) if (!in_array($name,$present,true)) $pdo->exec($ddl);
            schema_v15_verify($pdo);
            $pdo->beginTransaction();
            try {
                $insert=$pdo->prepare('INSERT IGNORE INTO academic_years(year_label,historical) VALUES (?,1)');
                foreach ($years as $year) $insert->execute([$year]);
                $pdo->exec("UPDATE schema_meta SET v='15' WHERE k='schema_version'");
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
        return ['ok'=>true,'mode'=>$mode,'to'=>$mode==='rollback'?'14':'15','historicalYears'=>$years,'existingAssignmentsChanged'=>0];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
