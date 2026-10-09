<?php
/** Exercise the exact phpMyAdmin script only in the verified private instance. */
$pdo->exec('CREATE DATABASE retention_manual');$pdo->exec('USE retention_manual');
$GLOBALS['allowV9']=false;PrismResetMigrationSQL\migrate($pdo);$GLOBALS['allowV9']=true;
$pdo->exec("INSERT INTO students (student_id,full_name,email) VALUES ('MANUAL','Synthetic preserved record','manual@example.invalid')");
$manualBefore=$pdo->query('SELECT * FROM students')->fetchAll();
$manual=file_get_contents(__DIR__.'/../tools/schema-v9-retention-manual.sql');
function retention_manual_run(PDO $pdo,string $script): void {
    $script=preg_replace('/^--.*$/m','',$script);
    $script=str_replace(['DELIMITER $$','DELIMITER ;'],'',$script);
    foreach (explode('$$',$script) as $statement) if(trim($statement)!=='') $pdo->exec(trim($statement));
}
try {retention_manual_run($pdo,$manual);throw new RuntimeException('Disabled manual migration accepted');}
catch(PDOException $e) {reset_migration_expect('45000',$e->getCode(),'Manual migration explicit safeguards');}
reset_migration_expect('8',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Unconfirmed script leaves v8');
reset_migration_expect($manualBefore,$pdo->query('SELECT * FROM students')->fetchAll(),'Unconfirmed script preserves data');
$enabled=str_replace(["'REPLACE_WITH_EXACT_DATABASE_NAME'",'SET @PRISM_V9_BACKUP_AND_STAGING_VERIFIED = 0'],["'retention_manual'",'SET @PRISM_V9_BACKUP_AND_STAGING_VERIFIED = 1'],$manual);
retention_manual_run($pdo,$enabled); lifecycle_compare_schema($pdo);
reset_migration_expect('9',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Exact manual script advances v9');
$after=$pdo->query('SELECT * FROM students')->fetchAll();
foreach($manualBefore[0] as $key=>$value) reset_migration_expect($value,$after[0][$key],'Manual preserves Student '.$key);
$manualInventory=lifecycle_schema_inventory($pdo);retention_manual_run($pdo,$enabled);
reset_migration_expect($manualInventory,lifecycle_schema_inventory($pdo),'Manual v9 retry-safe exact schema');
$pdo->exec("UPDATE schema_meta SET v='8' WHERE k='schema_version'");
$pdo->exec('ALTER TABLE advisers MODIFY retention_hold VARCHAR(10) NULL');
try {retention_manual_run($pdo,$enabled);throw new RuntimeException('Incompatible manual shape accepted');}
catch(PDOException $e) {reset_migration_expect('45000',$e->getCode(),'Manual incompatible shape fails closed');}
reset_migration_expect('8',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Bad manual shape never advances version');
try {PrismResetMigrationSQL\migrate_schema_v9($pdo);throw new RuntimeException('Incompatible PHP shape accepted');}
catch(RuntimeException $e) {reset_migration_expect(true,str_contains($e->getMessage(),'Incompatible v9'),'PHP guarded migration rejects incompatible shape');}
$pdo->exec('USE hardening_test_lifecycle');$pdo->exec('DROP DATABASE retention_manual');
echo 'PASS: exact guarded PHP/manual migration checks; cumulative '.$GLOBALS['checks'].".\n";
