<?php
/** Batch 7R audit helper (outside the repository). Read-only: server facts, contract verification, fingerprints. */
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('R7_REPO');
require_once $root.'/includes/schema_v15.php';
require_once $root.'/includes/account_lifecycle.php';
$dsn = (string)getenv('PRISM_MIGRATION_DB_DSN');
if (!preg_match('/host=127\.0\.0\.1;port=(13527|13537|13547|13637|13647)\b/', $dsn)) { fwrite(STDERR, "Refusing non-disposable target\n"); exit(1); }
$pdo = new PDO($dsn, getenv('PRISM_MIGRATION_DB_USER'), getenv('PRISM_MIGRATION_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$action = $argv[1] ?? 'info';
$v = (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
if ($action === 'info') {
    $cols = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME IN ('documents','reports') AND COLUMN_NAME='id') OR (TABLE_NAME='students' AND COLUMN_NAME IN ('research_title','research_group','stage','academic_year'))) ORDER BY 1,2")->fetchAll();
    echo json_encode(['version'=>$pdo->query('SELECT VERSION()')->fetchColumn(), 'schema'=>$v,
        'collation_database'=>$pdo->query('SELECT @@collation_database')->fetchColumn(),
        'collation_server'=>$pdo->query('SELECT @@collation_server')->fetchColumn(),
        'anchor_collations'=>$cols,
        'table_collations'=>$pdo->query("SELECT TABLE_COLLATION, COUNT(*) n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_COLLATION ORDER BY 1")->fetchAll(),
        'check_constraints'=>$pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY 1,2")->fetchAll()], JSON_PRETTY_PRINT);
} elseif ($action === 'verify') {
    try {
        match ($v) { '11' => schema_v11_verify($pdo), '12' => schema_v12_verify($pdo), '13' => schema_v13_verify($pdo),
            '14' => schema_v14_verify($pdo), '15' => schema_v15_verify($pdo) };
        lifecycle_compare_schema($pdo);
        echo json_encode(['schema'=>$v, 'contract'=>'PASS']);
    } catch (Throwable $e) {
        $chain = []; for ($x = $e; $x; $x = $x->getPrevious()) $chain[] = get_class($x).': '.$x->getMessage();
        echo json_encode(['schema'=>$v, 'contract'=>'FAIL', 'errors'=>$chain]);
    }
} elseif ($action === 'fingerprint') {
    $out = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        $pk = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $rows = $pdo->query('SELECT * FROM `'.$t.'`'.($pk ? ' ORDER BY `'.implode('`,`', $pk).'`' : ''))->fetchAll();
        $out[$t] = ['rows'=>count($rows), 'sha256'=>hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    ksort($out); echo json_encode($out, JSON_PRETTY_PRINT);
} elseif ($action === 'diff11') {
    // Which manifest sections differ, and the exact differing rows (structure only, never data).
    $expected = json_decode(file_get_contents(schema_v11_manifest_file()), true);
    $actual = lifecycle_schema_inventory($pdo); $report = [];
    foreach (['tables','columns','indexes','foreign_keys','triggers'] as $k) {
        $e = array_map('json_encode', $expected[$k]); $a = array_map('json_encode', $actual[$k]);
        $report[$k] = ['missing'=>array_values(array_diff($e, $a)), 'unexpected'=>array_values(array_diff($a, $e)), 'orderEqual'=>$e === $a];
    }
    $failed = [];
    foreach (schema_v10_plan()['verify'] as $c) if ((int)$pdo->query($c['sql'])->fetchColumn() !== 1) $failed[] = $c['message'];
    $report['v10_verify_failures'] = $failed;
    echo json_encode($report, JSON_PRETTY_PRINT);
}
