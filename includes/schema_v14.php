<?php
/** Additive summary history and immutable report snapshots. Never applied by HTTP. */
require_once __DIR__.'/schema_v13.php';

/** Pinned DDL rendered for the legacy base collation; documents.id and reports.id are VARCHAR foreign key parents. */
function schema_v14_tables(string $collation=SCHEMA_PINNED_COLLATION): array
{
    $tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return array_map(static fn($ddl)=>schema_extension_ddl($ddl,$collation),[
        'document_summary_versions'=>"CREATE TABLE document_summary_versions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            document_id VARCHAR(40) NOT NULL,
            version_no INT NOT NULL,
            source_version_no INT NOT NULL,
            summary_content LONGTEXT NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            model_identifier VARCHAR(190) NULL,
            generated_by_user_id INT NULL,
            generated_at DATETIME(6) NULL,
            source_sha256 CHAR(64) NULL,
            partial_extraction TINYINT(1) NOT NULL DEFAULT 0,
            limitations TEXT NOT NULL,
            legacy_import TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY summary_document_version (document_id,version_no),
            CONSTRAINT summary_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT,
            CONSTRAINT summary_actor_fk FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT summary_source_check CHECK (source_type IN ('local','ai','unknown')),
            CONSTRAINT summary_version_check CHECK (version_no>0 AND source_version_no>0)
        )".$tail,
        'ierb_report_snapshots'=>"CREATE TABLE ierb_report_snapshots (
            report_id VARCHAR(40) NOT NULL PRIMARY KEY,
            snapshot_json LONGTEXT NOT NULL,
            snapshot_sha256 CHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT report_snapshot_fk FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
            CONSTRAINT report_snapshot_json CHECK (JSON_VALID(snapshot_json))
        )".$tail,
    ]);
}

function schema_v14_definition(PDO $pdo,string $name): string
{
    if (!isset(schema_v14_tables()[$name])) throw new LogicException('Unknown summary table.');
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1]);
}

function schema_v14_verify(PDO $pdo,bool $partial=false,array $extraTables=[]): void
{
    schema_v13_verify($pdo,false,array_merge(array_keys(schema_v14_tables()),$extraTables));
    $contract=json_decode(file_get_contents(__DIR__.'/summary_schema_v14.json'),true,512,JSON_THROW_ON_ERROR);
    $collation=schema_extension_collation($pdo);
    $version=$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_keys(schema_v14_tables()) as $name) {
        if (!in_array($name,$present,true)) {
            if (!$partial || in_array($version,['14','15'],true)) throw new RuntimeException('Incomplete summary schema.');
        } elseif (!schema_extension_matches($pdo,$name,schema_v14_definition($pdo,$name),$contract[$name],$collation)) throw new RuntimeException('Summary schema drift: '.$name.' (legacy base '.$collation.').');
    }
}

function migrate_schema_v14(PDO $pdo,string $mode='dry-run'): array
{
    if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SCHEMA_V14_MIGRATION')!=='1'
        || getenv('PRISM_SCHEMA_V14_EXPECT_DB')!==$pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Explicit CLI authorization and database binding required.');
    if (!in_array($mode,['dry-run','apply','rollback'],true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) throw new RuntimeException('Invalid migration preconditions.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new RuntimeException('Schema maintenance busy.');
    try {
        schema_v14_verify($pdo,true);
        $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($mode==='rollback') {
            foreach (array_keys(schema_v14_tables()) as $name) if (in_array($name,$present,true) && (int)$pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn()) throw new RuntimeException('Retained summary/report history blocks rollback.');
            $pdo->exec("UPDATE schema_meta SET v='13' WHERE k='schema_version'");
            foreach (array_reverse(array_keys(schema_v14_tables())) as $name) if (in_array($name,$present,true)) $pdo->exec('DROP TABLE `'.$name.'`');
            schema_v13_verify($pdo);
        } elseif ($mode==='apply') {
            foreach (schema_v14_tables(schema_extension_collation($pdo)) as $name=>$ddl) if (!in_array($name,$present,true)) $pdo->exec($ddl);
            schema_v14_verify($pdo);
            $pdo->exec("UPDATE schema_meta SET v='14' WHERE k='schema_version'");
        }
        return ['ok'=>true,'mode'=>$mode,'to'=>$mode==='rollback'?'13':'14','extensionCollation'=>schema_extension_collation($pdo),'existingRecordsChanged'=>0];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
