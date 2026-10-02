<?php
/**
 * CLI-only Windows/XAMPP integration test. Starts its own temporary MariaDB server.
 * Never reads application configuration or connects to the installed database service.
 * Run: C:\xampp\php\php.exe tests/password-reset-migration-mysql.php --isolated-server
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !in_array($argv[1], ['--isolated-server', '--isolated-v6'], true) || PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "Requires Windows/XAMPP and --isolated-server or --isolated-v6.\n");
    exit(1);
}
$checks = 0;
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
$setup = reset_migration_source($source, 'function send_account_setup_email(', "\nfunction json_body(");
eval('namespace PrismResetMigrationSQL; use \PDO; use \RuntimeException; use \Throwable;'
    . 'const APP_BASE_URL = "https://prism.invalid";'
    . 'function getenv(string $key): string|false { return $key === "PRISM_ALLOW_SCHEMA_V6_MIGRATION" ? "1" : false; }'
    . 'function app_base_url_is_valid(): bool { return true; }'
    . 'function log_api_error(...$args): void { $GLOBALS["setupErrors"]++; }'
    . 'function send_notification_email(...$args): array { $GLOBALS["setupMailCalls"]++; return ["ok" => true, "channel" => "fixture"]; }'
    . $declarations . $setup);
$GLOBALS['setupErrors'] = 0;
$GLOBALS['setupMailCalls'] = 0;

$bin = dirname(PHP_BINARY, 2) . '/mysql/bin/';
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
        '--datadir=' . $datadir, '--bind-address=127.0.0.1', '--port=' . $port, '--skip-log-bin',
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

    if ($argv[1] === '--isolated-v6') {
        require __DIR__ . '/schema-v6-mysql.php'; // Exact test dependency; shared private-server lifecycle.
    } else {
    foreach (['fresh', 'legacy_empty', 'legacy_rows', 'correct_rows', 'unsigned_rows', 'zero_mode_rows'] as $case) {
        $pdo->exec('CREATE DATABASE `reset_test_' . $case . '`');
        $pdo->exec('USE `reset_test_' . $case . '`');
        $mode = $case === 'zero_mode_rows' ? 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_BACKSLASH_ESCAPES' : 'STRICT_TRANS_TABLES';
        $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        $mode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $before = [];
        $references = [];
        $constraints = [];
        if ($case !== 'fresh') {
            $pdo->exec("CREATE TABLE schema_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(40) NOT NULL) ENGINE=InnoDB");
            $pdo->exec("INSERT INTO schema_meta VALUES ('schema_version', '4')");
            $pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO users (id) VALUES (1), (2), (3), (4)');
            $type = $case === 'unsigned_rows' ? 'BIGINT UNSIGNED' : 'INT';
            $auto = $case === 'correct_rows' ? ' AUTO_INCREMENT' : '';
            $pdo->exec("CREATE TABLE password_resets (
                id $type NOT NULL$auto PRIMARY KEY COMMENT 'Legacy key''s comment',
                user_id INT NOT NULL, token VARCHAR(128) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL, used TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            if ($case !== 'legacy_empty') {
                $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
                $pdo->exec("INSERT INTO password_resets (id,user_id,token,expires_at,used,created_at) VALUES
                    (0,1,'synthetic-zero','2027-01-01 00:00:00',0,'2026-01-01 00:00:00'),
                    (42,2,'synthetic-used','2025-01-01 00:00:00',1,'2024-01-01 00:00:00')");
                $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
                $pdo->exec("CREATE TABLE reset_references (reset_id $type PRIMARY KEY,
                    CONSTRAINT referenced_reset FOREIGN KEY (reset_id) REFERENCES password_resets(id)) ENGINE=InnoDB");
                $pdo->exec('INSERT INTO reset_references VALUES (0), (42)');
                $references = $pdo->query('SELECT * FROM reset_references ORDER BY reset_id')->fetchAll();
            }
            $before = $pdo->query('SELECT * FROM password_resets ORDER BY id')->fetchAll();
            $constraints = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME")->fetchAll();
        }

        PrismResetMigrationSQL\migrate($pdo);
        $column = $pdo->query("SELECT COLUMN_NAME, COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'id'")->fetch();
        reset_migration_expect(['COLUMN_NAME' => 'id', 'COLUMN_KEY' => 'PRI', 'EXTRA' => 'auto_increment'], $column,
            $case . ': INFORMATION_SCHEMA confirms the repaired key');
        reset_migration_expect('6', $pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(), $case . ': v6 stamp');
        reset_migration_expect($mode, $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(), $case . ': session mode preserved');
        reset_migration_expect($before, $pdo->query('SELECT * FROM password_resets ORDER BY id')->fetchAll(), $case . ': every reset value preserved');
        if ($case !== 'fresh') {
            $afterConstraints = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('password_resets','reset_references')
                ORDER BY TABLE_NAME, CONSTRAINT_NAME")->fetchAll();
            reset_migration_expect($constraints, $afterConstraints, $case . ': incoming/outgoing foreign keys preserved');
        } else {
            $pdo->exec("INSERT INTO users (username,password_hash,role,full_name,email) VALUES
                ('fixture1','not-a-login-hash','student','Fixture One','one@example.invalid'),
                ('fixture2','not-a-login-hash','adviser','Fixture Two','two@example.invalid'),
                ('fixture3','not-a-login-hash','student','Fixture Three','three@example.invalid'),
                ('fixture4','not-a-login-hash','adviser','Fixture Four','four@example.invalid')");
        }
        if ($references) {
            reset_migration_expect($references, $pdo->query('SELECT * FROM reset_references ORDER BY reset_id')->fetchAll(),
                $case . ': referenced zero and nonzero IDs unchanged');
        }
        $ddl = $pdo->query('SHOW CREATE TABLE password_resets')->fetch(PDO::FETCH_NUM)[1];
        PrismResetMigrationSQL\migrate($pdo);
        reset_migration_expect($ddl, $pdo->query('SHOW CREATE TABLE password_resets')->fetch(PDO::FETCH_NUM)[1],
            $case . ': second migration leaves table unchanged');
        reset_migration_expect($before, $pdo->query('SELECT * FROM password_resets ORDER BY id')->fetchAll(),
            $case . ': rerun preserves reset rows');

        // Exercise the actual setup issuer; only delivery/configuration checks are stubbed.
        foreach ([3, 4, 3] as $userId) {
            $result = PrismResetMigrationSQL\send_account_setup_email($pdo, $userId, 'fixture@example.invalid', 'Fixture');
            reset_migration_expect(true, $result['ok'], $case . ': repeated setup token insert succeeds');
        }
        $newIds = $pdo->query('SELECT id FROM password_resets WHERE user_id IN (3,4) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        reset_migration_expect(3, count(array_unique($newIds)), $case . ': three distinct generated keys');
        reset_migration_expect(true, min(array_map('intval', $newIds)) > ($before ? 42 : 0), $case . ': generated IDs exceed existing maximum');
        reset_migration_expect(1, (int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=3 AND used=0')->fetchColumn(),
            $case . ': normal setup supersession still applies');
        reset_migration_expect($before, $pdo->query('SELECT * FROM password_resets WHERE user_id IN (1,2) ORDER BY id')->fetchAll(),
            $case . ': setup for other accounts leaves legacy tokens untouched');
        echo 'PASS: ' . $case . " metadata, preservation, rerun and repeated setup issuance.\n";
    }
    reset_migration_expect(0, $GLOBALS['setupErrors'], 'No setup issuance errors');
    reset_migration_expect(18, $GLOBALS['setupMailCalls'], 'All setup inserts reached mocked delivery');
    echo 'PASS: ' . $checks . " real MariaDB reset-migration checks. No application database/configuration or real email used.\n";
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
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
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
