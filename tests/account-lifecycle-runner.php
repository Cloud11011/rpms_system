<?php
/**
 * CLI-only Windows/XAMPP integration test. Starts its own temporary MariaDB server.
 * Never reads application configuration or connects to the installed database service.
 * Run: C:\xampp\php\php.exe tests/account-lifecycle-runner.php --lifecycle
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array($argc,[2,4],true) || ($argc===4 && $argv[2]!=='--mariadb-bin') || !in_array($argv[1], ['--batch6', '--lifecycle-metadata', '--lifecycle', '--lifecycle-remediation', '--lifecycle-shared-hosting', '--retention', '--retention-core', '--retention-manifest', '--hostinger-fk-migration', '--hostinger-fk-retries', '--hostinger-fk-guards'], true) || PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "Requires Windows/XAMPP and --lifecycle or --lifecycle-metadata.\n");
    exit(1);
}
$checks = 0;
require_once __DIR__.'/../includes/schema_v10.php';
// Earlier suites deliberately exercise v8/v9; the application manifest is now v10.
if ($argv[1]!=='--batch6') define('PRISM_LIFECYCLE_MANIFEST_FILE',__DIR__.'/../tools/schema-v9-contract.json');
function reset_migration_expect(mixed $expected, mixed $actual, string $message): void
{
    ++$GLOBALS['checks'];
    if ($expected !== $actual) throw new RuntimeException($message);
}
function reset_migration_source(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    if ($from === false || $to === false) throw new RuntimeException('Cannot extract reviewed declarations.');
    $part = substr($source, $from, $to - $from);
    if (preg_match('/\b(?:require|include)(?:_once)?\s*\(?\s*[\'"$]/', $part)) {
        throw new RuntimeException('Unexpected include in extracted declarations.');
    }
    return $part;
}
$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/config.php'));
$declarations = reset_migration_source($source, 'const SCHEMA_VERSION = ', "\nfunction seed(");
$declarations=str_replace('__DIR__',var_export(dirname(__DIR__),true),$declarations);
$setup = reset_migration_source($source, 'function send_account_setup_email(', "\nfunction json_body(");
eval('namespace PrismResetMigrationSQL; use \PDO; use \RuntimeException; use \Throwable;'
    . 'const APP_BASE_URL = "https://prism.invalid";'
    . 'function getenv(string $key): string|false { if (!empty($GLOBALS["denyMigration"])) return false; if ($key === "PRISM_SCHEMA_V10_EXPECT_DB") return \getenv($key); return ($key === "PRISM_ALLOW_SCHEMA_V6_MIGRATION" || ($key === "PRISM_ALLOW_SCHEMA_V7_MIGRATION" && !empty($GLOBALS["allowV7"])) || ($key === "PRISM_ALLOW_SCHEMA_V8_MIGRATION" && !empty($GLOBALS["allowV8"])) || ($key === "PRISM_ALLOW_SCHEMA_V9_MIGRATION" && !empty($GLOBALS["allowV9"])) || ($key === "PRISM_ALLOW_SCHEMA_V10_MIGRATION" && !empty($GLOBALS["allowV10"]))) ? "1" : false; }'
    . 'function app_base_url_is_valid(): bool { return true; }'
    . 'function log_api_error(...$args): void { $GLOBALS["setupErrors"]++; }'
    . 'function send_notification_email(...$args): array { $GLOBALS["setupMailCalls"]++; return ["ok" => true, "channel" => "fixture"]; }'
    . $declarations . $setup);
$GLOBALS['setupErrors'] = 0;
$GLOBALS['setupMailCalls'] = 0;

$bin = dirname(PHP_BINARY, 2) . '/mysql/bin/';
// Optional portable binaries still start a fresh private datadir/port with the same ownership checks.
if($argc===4) {
    $resolvedBin=realpath($argv[3]);
    if($resolvedBin===false) throw new RuntimeException('Portable MariaDB binary directory not found.');
    $bin=rtrim($resolvedBin,'\\/').'/';
}
if (!is_file($bin . 'mysql_install_db.exe') || !is_file($bin . 'mysqld.exe')) {
    throw new RuntimeException('XAMPP MariaDB binaries not found.');
}
$root = sys_get_temp_dir() . '/prism-reset-migration-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create isolated server directory.');
$root = realpath($root);
$datadir = $root . '/data';
$server = null;
$pdo = null;
$failure = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot reserve isolated loopback port.');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    // A random credential and verified datadir prevent accidental use of another service.
    $password = bin2hex(random_bytes(24));
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $root . '/install.log', 'a'], 2 => ['file', $root . '/install.log', 'a']];
    $install = proc_open([$bin . 'mysql_install_db.exe', '--datadir=' . $datadir, '--port=' . $port,
        '--password=' . $password], $descriptors, $pipes, $root, null, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($install)) throw new RuntimeException('Cannot initialize temporary MariaDB.');
    fclose($pipes[0]);
    if (proc_close($install) !== 0) throw new RuntimeException('Temporary MariaDB initialization failed.');
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']];
    $server = proc_open([$bin . 'mysqld.exe', '--no-defaults', '--basedir=' . dirname($bin),
        '--datadir=' . $datadir, '--tmpdir=' . $root, '--bind-address=127.0.0.1', '--port=' . $port, '--skip-log-bin',
        '--innodb-buffer-pool-size=32M', '--pid-file=' . $root . '/server.pid'],
        $descriptors, $pipes, $root, null, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($server)) throw new RuntimeException('Cannot start temporary MariaDB.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 30;
    do {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_TIMEOUT => 1]);
            break;
        } catch (PDOException $error) {
            if (!proc_get_status($server)['running']) throw new RuntimeException('Temporary MariaDB stopped before readiness.');
            usleep(100000);
        }
    } while (microtime(true) < $deadline);
    if (!$pdo) throw new RuntimeException('Temporary MariaDB readiness timed out.');
    reset_migration_expect(realpath($datadir), realpath($pdo->query('SELECT @@datadir')->fetchColumn()),
        'Connection must belong to the temporary instance');
    echo 'Temporary MariaDB version: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

    if ($argv[1]==='--batch6') {
        require __DIR__.'/v10-batch6-mysql.php';
    } elseif(in_array($argv[1],['--hostinger-fk-migration','--hostinger-fk-retries','--hostinger-fk-guards'],true)) {
        require_once __DIR__.'/../includes/account_lifecycle.php';
        $GLOBALS['allowV7']=$GLOBALS['allowV8']=true;
        require __DIR__.'/account-hostinger-fk-migration.php';
    } else {
        require __DIR__.(str_starts_with($argv[1],'--retention')?'/account-retention-mysql.php':'/account-lifecycle-mysql.php');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($failure && is_file($root.'/server.log')) {
        $diagnostics=__DIR__.'/v10-batch6-results'; if (!is_dir($diagnostics)) mkdir($diagnostics);
        // Native error diagnostics only, never install output/credentials/fixture configuration.
        file_put_contents($diagnostics.'/native-failure-server.log',file_get_contents($root.'/server.log'));
    }
    if ($pdo) {
        try { $pdo->exec('SHUTDOWN'); } catch (Throwable $ignored) {}
        $pdo = null;
    }
    if (is_resource($server)) {
        $deadline = microtime(true) + 10;
        while (proc_get_status($server)['running'] && microtime(true) < $deadline) usleep(100000);
        if (proc_get_status($server)['running']) proc_terminate($server);
        proc_close($server);
    }
    // Delete only this newly created, resolved temporary directory; never follow links.
    $prefix = rtrim(realpath(sys_get_temp_dir()), '\\/') . DIRECTORY_SEPARATOR . 'prism-reset-migration-';
    if ($root && str_starts_with($root, $prefix)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $resolved = $entry->getRealPath();
            if ($entry->isLink() || !$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Unsafe temporary cleanup path; stopped.');
            }
            if ($entry->isDir()) rmdir($resolved); else unlink($resolved);
        }
        rmdir($root);
    }
}
if ($failure) {
    foreach (array_slice($failure->getTrace(),0,5) as $frame) fwrite(STDERR,($frame['function']??'').' at '.($frame['file']??'').':'.($frame['line']??0)."\n");
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . (isset($GLOBALS['hostingerFkContext'])?' [FK fixture '.$GLOBALS['hostingerFkContext'].']':'') . "\n");
    exit(1);
}
