<?php
namespace PrismMigrationCliTest;
/** CLI-only operator-guard tests. Replace exactly the reviewed config include; never bootstrap the app. */
if (\PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function getenv(string $name): string|false
{
    return match ($name) {
        'PRISM_ALLOW_SCHEMA_V6_MIGRATION' => $GLOBALS['cliCase']['allow'],
        'PRISM_MIGRATION_EXPECT_DB' => $GLOBALS['cliCase']['expected'],
        default => throw new \RuntimeException('Unexpected environment read in CLI fixture.'),
    };
}
function migration_cli_bootstrap(): void
{
    echo "[fixture bootstrap]\n";
    define(__NAMESPACE__ . '\DB_NAME', $GLOBALS['cliCase']['configured']);
    define(__NAMESPACE__ . '\DB_USER', 'SYNTHETIC_PRIVATE_USER');
    define(__NAMESPACE__ . '\DB_PASS', 'SYNTHETIC_PRIVATE_PASSWORD');
}
function db(): void
{
    echo "[fixture db]\n"; // No connection, bootstrap, SQL, or application credentials.
    if ($GLOBALS['cliCase']['dbFails'] ?? false) {
        fwrite(STDERR, "Fixture migration failed.\n");
        exit(3);
    }
}
if (($argv[1] ?? '') === '--case') {
    $GLOBALS['cliCase'] = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $case = $GLOBALS['cliCase'];
    define(__NAMESPACE__ . '\PHP_SAPI', $case['sapi']);
    $source = file_get_contents(dirname(__DIR__) . '/tools/migrate-academic.php');
    $source = str_replace("require dirname(__DIR__) . '/config.php';", 'migration_cli_bootstrap();', $source, $replaced);
    if ($replaced !== 1 || preg_match('/\b(?:require|include)(?:_once)?\s*[\s(]/', $source)) {
        throw new \RuntimeException('Unexpected migration CLI include; refusing application bootstrap.');
    }
    $source = preg_replace('/^<\?php\s*/', '', $source, 1, $opening);
    if ($opening !== 1) throw new \RuntimeException('Missing CLI opening tag.');
    $argv = array_merge(['migrate-academic.php'], $case['args']);
    $argc = count($argv);
    eval('namespace ' . __NAMESPACE__ . '; ' . $source);
    exit;
}
$base = ['allow'=>'1', 'expected'=>'prism_academic_test', 'configured'=>'prism_academic_test',
    'args'=>['--apply'], 'sapi'=>'cli', 'code'=>0, 'bootstrap'=>true, 'db'=>true];
$cases = [
    ['name'=>'Missing expected database', 'expected'=>false, 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Empty expected database', 'expected'=>'', 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Whitespace-only expected database', 'expected'=>" \t ", 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Wrong expected database', 'expected'=>'prism', 'code'=>1, 'db'=>false],
    ['name'=>'Case mismatch is rejected', 'expected'=>'PRISM_ACADEMIC_TEST', 'code'=>1, 'db'=>false],
    ['name'=>'Leading whitespace is not normalized', 'expected'=>' prism_academic_test', 'code'=>1, 'db'=>false],
    ['name'=>'Trailing whitespace is not normalized', 'expected'=>'prism_academic_test ', 'code'=>1, 'db'=>false],
    ['name'=>'Matching expected database'],
    ['name'=>'Matching second target', 'configured'=>'prism', 'expected'=>'prism'],
    ['name'=>'Missing v6 opt-in', 'allow'=>false, 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Non-exact v6 opt-in', 'allow'=>'true', 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Missing apply argument', 'args'=>[], 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Wrong apply argument', 'args'=>['--Apply'], 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Extra argument', 'args'=>['--apply','extra'], 'code'=>1, 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Web invocation', 'sapi'=>'fpm-fcgi', 'bootstrap'=>false, 'db'=>false],
    ['name'=>'Database failure cannot report completion', 'dbFails'=>true, 'code'=>3],
];
$checks = 0;
function cli_expect(bool $ok, string $label): void
{
    ++$GLOBALS['checks'];
    if (!$ok) throw new \RuntimeException($label);
}
foreach ($cases as $overrides) {
    $case = array_replace($base, $overrides);
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case, JSON_THROW_ON_ERROR)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new \RuntimeException('Cannot launch CLI fixture.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $combined = $out . $err;
    cli_expect($code === $case['code'], $case['name'] . ': exit code');
    cli_expect(str_contains($out, '[fixture bootstrap]') === $case['bootstrap'], $case['name'] . ': bootstrap boundary');
    cli_expect(str_contains($out, '[fixture db]') === $case['db'], $case['name'] . ': db/DDL boundary');
    foreach (['SYNTHETIC_PRIVATE_USER','SYNTHETIC_PRIVATE_PASSWORD','DB_USER','DB_PASS'] as $secret) {
        cli_expect(!str_contains($combined, $secret), $case['name'] . ': credentials absent');
    }
    $target = 'PRISM schema v6 migration target: ' . $case['configured'];
    $completed = 'PRISM schema v6 migration completed for: ' . $case['configured'];
    if ($case['db']) {
        cli_expect(str_contains($out,$target) && strpos($out,$target) < strpos($out,'[fixture db]'),
            $case['name'] . ': exact target printed before db');
        if ($case['code'] === 0) {
            cli_expect(str_contains($out,$completed) && strpos($out,$completed) > strpos($out,'[fixture db]'),
                $case['name'] . ': target-specific completion after db');
            cli_expect($err === '', $case['name'] . ': clean success');
        } else {
            cli_expect(!str_contains($out,'migration completed'), $case['name'] . ': no false completion');
        }
    } else {
        cli_expect(!str_contains($out,'migration target:') && !str_contains($out,'migration completed'),
            $case['name'] . ': rejected invocation has no success/target announcement');
        if ($case['bootstrap']) {
            cli_expect(str_contains($err,'configured DB_NAME=' . json_encode($case['configured']))
                && str_contains($err,'PRISM_MIGRATION_EXPECT_DB=' . json_encode($case['expected'])),
                $case['name'] . ': mismatch identifies configured and expected names');
        } elseif ($case['sapi'] === 'cli') {
            cli_expect(str_contains($err,'PRISM_MIGRATION_EXPECT_DB'), $case['name'] . ': usage names the required guard');
        }
    }
    echo 'PASS: ' . $case['name'] . "\n";
}
echo 'PASS: ' . count($cases) . ' migration CLI cases, ' . $checks
    . " assertions. No application bootstrap, private config, or database connection.\n";
