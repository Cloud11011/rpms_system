<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
/** Maintainer helper only: bootstrap verifies the actual disposable MariaDB datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/schema_v13.php';
schema_v12_verify($pdo);
$present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (array_keys(schema_v13_tables()) as $name) if (in_array($name,$present,true)) throw new RuntimeException('Project extension must be absent.');
try {
    foreach (schema_v13_tables() as $sql) $pdo->exec($sql);
    $contract=[];
    foreach (array_keys(schema_v13_tables()) as $name) $contract[$name]=schema_v13_definition($pdo,$name);
    file_put_contents($root.'/includes/research_schema_v13.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo "Canonical project contract captured in the verified disposable fixture.\n";
} finally {
    foreach (array_reverse(array_keys(schema_v13_tables())) as $name) $pdo->exec('DROP TABLE IF EXISTS `'.$name.'`');
}
