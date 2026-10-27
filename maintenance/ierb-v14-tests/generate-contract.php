<?php
/** Rebuild only in the datadir-verified disposable group fixture, before running V14 tests. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require $root.'/includes/schema_v12.php';
schema_v11_verify($pdo);
foreach (array_keys(schema_v12_tables()) as $name) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$name]);
    if ((int)$q->fetchColumn()) throw new RuntimeException('Contract generation requires an empty extension.');
}
try {
    foreach (schema_v12_tables() as $sql) $pdo->exec($sql);
    $contract = [];
    foreach (array_keys(schema_v12_tables()) as $name) $contract[$name] = schema_v12_definition($pdo, $name);
    file_put_contents($root.'/includes/ierb_schema_v12.json', json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo "Disposable canonical schema 12 definitions captured.\n";
} finally {
    foreach (array_reverse(array_keys(schema_v12_tables())) as $name) $pdo->exec('DROP TABLE IF EXISTS `'.$name.'`');
}
