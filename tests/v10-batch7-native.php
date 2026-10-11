<?php
/** Private MariaDB tests. Builds fresh fixtures from frozen v10 metadata; NO migration/config/installed DB. */
if (PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Windows') exit(1);
$checks=0;
function reset_migration_expect(mixed $expected,mixed $actual,string $message): void {
    $GLOBALS['nativeChecks']=($GLOBALS['nativeChecks']??0)+1;
    if ($expected!==$actual) throw new RuntimeException($message);
}
$bin = dirname(PHP_BINARY, 2) . '/mysql/bin/';
// Optional portable binaries still start a fresh private datadir/port with the same ownership checks.
if($argc>=4) {
    $resolvedBin=realpath($argv[$argc-1]);
    if($resolvedBin===false) throw new RuntimeException('Portable MariaDB binary directory not found.');
    $bin=rtrim($resolvedBin,'\\/').'/';
}
if (!is_file($bin . 'mysql_install_db.exe') || !is_file($bin . 'mysqld.exe')) {
    throw new RuntimeException('XAMPP MariaDB binaries not found.');
}
$root = sys_get_temp_dir() . '/prism-batch7-native-' . bin2hex(random_bytes(8));
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

    require __DIR__.'/v10-batch7-native-fixture.php';
    if (!in_array('--only9',$argv,true) && !in_array('--only10',$argv,true)) {
        require __DIR__.'/v10-batch7-lifecycle.php';
        require __DIR__.'/v10-batch7-concurrency.php';
        require __DIR__.'/v10-batch7-purge.php';
    }
    if (in_array('--batch8',$argv,true)) require __DIR__.'/v10-batch8-native.php';
    if (in_array('--batch9',$argv,true)) require __DIR__.'/v10-batch9-native.php';
    if (in_array('--batch10',$argv,true)) require __DIR__.'/v10-batch10-native.php';
    echo 'PASS: '.($GLOBALS['nativeChecks']??0)." native concurrency, FK and account-purge assertions.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($failure && is_file($root.'/server.log')) {
        $diagnostics=__DIR__.'/v10-batch7-results'; if (!is_dir($diagnostics)) mkdir($diagnostics);
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
    $prefix = rtrim(realpath(sys_get_temp_dir()), '\\/') . DIRECTORY_SEPARATOR . 'prism-batch7-native-';
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
