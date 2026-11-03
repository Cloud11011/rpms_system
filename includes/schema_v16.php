<?php
/** Additive academic-year registration settings. Explicit CLI migration only. */
require_once __DIR__.'/schema_v15.php';
require_once __DIR__.'/schema_optional_extensions.php';

function schema_v16_tables(string $collation=SCHEMA_PINNED_COLLATION): array
{
    return array_map(static fn($ddl)=>schema_extension_ddl($ddl,$collation),[
        'academic_year_settings'=>"CREATE TABLE academic_year_settings (
            year_label VARCHAR(9) NOT NULL PRIMARY KEY,
            available_for_registration TINYINT(1) NOT NULL DEFAULT 1,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            CONSTRAINT academic_year_settings_year_fk FOREIGN KEY (year_label) REFERENCES academic_years(year_label) ON DELETE RESTRICT,
            CONSTRAINT academic_year_settings_availability CHECK (available_for_registration IN (0,1)),
            CONSTRAINT academic_year_settings_current CHECK (is_current IN (0,1) AND (is_current=0 OR available_for_registration=1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ]);
}

function schema_v16_definition(PDO $pdo,string $name): string
{
    if (!isset(schema_v16_tables()[$name])) throw new LogicException('Unknown academic-year settings table.');
    return $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
}

function schema_v16_verify(PDO $pdo,bool $partial=false,array $extraTables=[]): void
{
    schema_v15_verify($pdo,false,array_values(array_unique(array_merge(array_keys(schema_v16_tables()),$extraTables,schema_optional_extension_tables($pdo)))));
    $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    if (!in_array($version,['15','16'],true)) throw new RuntimeException('Reviewed schema 15 or 16 required.');
    $contract=json_decode(file_get_contents(__DIR__.'/academic_schema_v16.json'),true,512,JSON_THROW_ON_ERROR);
    $collation=schema_extension_collation($pdo);
    $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_keys(schema_v16_tables()) as $name) {
        if (!in_array($name,$present,true)) {
            if (!$partial || $version==='16') throw new RuntimeException('Incomplete academic-year settings schema.');
        } elseif (!schema_extension_matches($pdo,$name,schema_v16_definition($pdo,$name),$contract[$name],$collation)) {
            throw new RuntimeException('Academic-year settings schema drift: '.$name.' (legacy base '.$collation.').');
        }
    }
}

function migrate_schema_v16(PDO $pdo,string $mode='dry-run'): array
{
    if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SCHEMA_V16_MIGRATION')!=='1'
        || getenv('PRISM_SCHEMA_V16_EXPECT_DB')!==$pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Explicit CLI authorization and database binding required.');
    }
    if (!in_array($mode,['dry-run','apply','rollback'],true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new RuntimeException('Invalid migration preconditions.');
    }
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) {
        throw new RuntimeException('Schema maintenance busy.');
    }
    try {
        $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
        if (!in_array($version,['15','16'],true)) throw new RuntimeException('Academic-year settings require reviewed schema 15.');
        schema_v16_verify($pdo,true);
        $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($mode==='rollback') {
            if ($pdo->query("SELECT v FROM schema_meta WHERE k='prism_settings_v1'")->fetchColumn()!==false) throw new RuntimeException('Retain Settings data; remove the separately reviewed Settings extension before base rollback.');
            if (!in_array('academic_year_settings',$present,true)) {
                throw new RuntimeException('Schema v16 is not installed.');
            }
            $custom=(int)$pdo->query('SELECT COUNT(*) FROM academic_year_settings WHERE available_for_registration<>1 OR is_current<>0')->fetchColumn();
            if ($custom) throw new RuntimeException('Custom availability or current-year settings block rollback. Restore defaults before retrying.');
            $pdo->exec('DROP TABLE academic_year_settings');
            if ($version==='16') $pdo->exec("UPDATE schema_meta SET v='15' WHERE k='schema_version'");
            schema_v15_verify($pdo,false,schema_optional_extension_tables($pdo));
        } elseif ($mode==='apply') {
            foreach (schema_v16_tables(schema_extension_collation($pdo)) as $name=>$ddl) {
                if (!in_array($name,$present,true)) $pdo->exec($ddl);
            }
            schema_v16_verify($pdo,true);
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT IGNORE INTO academic_years(year_label,historical)
                    SELECT DISTINCT academic_year,1 FROM students
                    WHERE academic_year IS NOT NULL AND TRIM(academic_year)<>'' AND CHAR_LENGTH(academic_year)<=9");
                $pdo->exec("INSERT IGNORE INTO academic_year_settings(year_label)
                    SELECT year_label FROM academic_years
                    WHERE year_label REGEXP '^[1-9][0-9]{3}-[1-9][0-9]{3}$'
                    AND CAST(RIGHT(year_label,4) AS UNSIGNED)=CAST(LEFT(year_label,4) AS UNSIGNED)+1");
                $pdo->exec("UPDATE schema_meta SET v='16' WHERE k='schema_version'");
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            schema_v16_verify($pdo);
        }
        $settingsRows=$mode!=='rollback' && ($mode==='apply' || in_array('academic_year_settings',$present,true))
            ? (int)$pdo->query('SELECT COUNT(*) FROM academic_year_settings')->fetchColumn()
            : 0;
        return [
            'ok'=>true,'mode'=>$mode,'to'=>$mode==='rollback'?'15':'16',
            'settingsRows'=>$settingsRows,
            'studentAssignmentsChanged'=>0,
        ];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
    }
}
