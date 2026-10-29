<?php
/** No-SSH operator wrapper for one reviewed CLI step (hPanel one-shot cron). Never reachable over HTTP.
 *   php maintenance/hostinger-cron-runner.php /home/<account>/prism-private/migration.json <step> <mode>
 * The JSON file lives OUTSIDE public_html and holds: dsn, user, password, database, receiptDir (optional: sslCa).
 * It is read only if it is a regular file given by its real absolute path (no link), owned by this account with chmod 600
 * (or 400), in a folder no other account can write to; otherwise the runner stops with unsafe_configuration before
 * reading it or connecting. It never changes the file's permissions (see prism_private_config()).
 * receiptDir must be a private directory (chmod 700, owned by this account, not a link) outside the application.
 * Steps: probe | preflight | v12 | v13 | v14 | v15 | deletion-verification. Modes: dry-run | apply | rollback (other steps ignore mode).
 * deletion-verification also needs deletionVerificationKey (the deployment's 64-hex key) and externalDependenciesExcluded: true;
 * its evidence goes only to a private file in receiptDir, and the receipt carries a host-free summary.
 * Only the reviewed runners are executed, one step per invocation. The receipt never contains credentials; every
 * receipt and evidence file is created exclusively with mode 0600. Every step, including probe, connects with the
 * same TLS verification policy (prism_migration_pdo) and is bound to the configured database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/migration_runner.php';
$fail = static function (string $class, string $message): never { fwrite(STDERR, json_encode(['ok' => false, 'errorClass' => $class, 'message' => $message]).PHP_EOL); exit(1); };
[$self, $configPath, $step, $mode] = array_pad($argv, 4, '');
$runners = ['v12' => 'migrate-ierb-v14.php', 'v13' => 'migrate-research-projects.php', 'v14' => 'migrate-summary-history.php', 'v15' => 'migrate-academic-years.php'];
if (!in_array($step, ['probe', 'preflight', 'deletion-verification', ...array_keys($runners)], true)) $fail('invalid_invocation', 'Choose probe, preflight, v12, v13, v14, v15 or deletion-verification.');
if (isset($runners[$step]) && !in_array($mode, ['dry-run', 'apply', 'rollback'], true)) $fail('invalid_invocation', 'Choose dry-run, apply or rollback.');
// Other steps ignore the mode, but it names the receipt file.
if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,19}\z/D', $mode)) $fail('invalid_invocation', 'The mode must be a short lowercase word, for example x.');
// The configuration holds the database password: its location, owner and permissions are verified before it is read.
if ($configPath === '' || (!file_exists($configPath) && !is_link($configPath))) $fail('invalid_invocation', 'The private configuration file was not found.');
try { [$raw, $configOwnerAndModeVerified] = prism_private_config($configPath); } catch (PrismMigrationInputError $e) { $fail('unsafe_configuration', $e->getMessage()); }
try { $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
catch (JsonException $e) { $fail('invalid_invocation', 'The private configuration file is not valid JSON.'); }
unset($raw);
if (!is_array($config)) $fail('invalid_invocation', 'The private configuration file must hold a JSON object.');
foreach (['dsn', 'user', 'password', 'database', 'receiptDir'] as $k) if (!isset($config[$k]) || !is_string($config[$k])) $fail('invalid_invocation', 'The private configuration is missing '.$k.'.');
if (isset($config['sslCa']) && !is_string($config['sslCa'])) $fail('invalid_invocation', 'sslCa must be the path of a CA certificate file.');
$ca = $config['sslCa'] ?? null;
// Receipts and evidence are written only to a private directory; refuse before running anything.
try { $receiptDir = prism_private_directory($config['receiptDir']); } catch (PrismMigrationInputError $e) { $fail('unsafe_configuration', $e->getMessage()); }
$stamp = date('Ymd-His').'-'.bin2hex(random_bytes(3));
$receipt = ['startedAt' => date(DATE_ATOM), 'step' => $step, 'mode' => $mode, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
    'extensions' => ['pdo_mysql' => extension_loaded('pdo_mysql'), 'mbstring' => extension_loaded('mbstring'), 'intl' => extension_loaded('intl'), 'posix' => extension_loaded('posix')],
    // false only on Windows development hosts, which have no POSIX owner or mode bits (only the path rules applied).
    'configurationFile' => ['ownerAndModeVerified' => $configOwnerAndModeVerified]];
if ($step === 'probe') {
    // Proves the cron PHP binary, extensions and a read-only connection without touching application data.
    $pdo = null;
    try {
        $pdo = prism_migration_pdo($config['dsn'], $config['user'], $config['password'], $ca);
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $receipt += ['connected' => true, 'tls' => ['caVerification' => is_string($ca) && $ca !== '', 'cipher' => prism_migration_tls_cipher($pdo)]];
        if ($database !== $config['database']) {
            $receipt += ['databaseMatches' => false, 'errorClass' => 'database_mismatch', 'exit' => 1];
        } else {
            $receipt += ['databaseMatches' => true, 'database' => $database, 'server' => $pdo->query('SELECT VERSION()')->fetchColumn(),
                'schemaVersion' => $pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(), 'exit' => 0];
        }
    } catch (Throwable $e) {
        $diagnostic = prism_migration_diagnostic($e); unset($diagnostic['ok']);
        $receipt += ['connected' => $pdo !== null] + $diagnostic + ['exit' => 1];
    }
} else {
    $env = ['PRISM_MIGRATION_DB_DSN' => $config['dsn'], 'PRISM_MIGRATION_DB_USER' => $config['user'], 'PRISM_MIGRATION_DB_PASSWORD' => $config['password'],
        'PRISM_PREFLIGHT_DB_DSN' => $config['dsn'], 'PRISM_PREFLIGHT_DB_USER' => $config['user'], 'PRISM_PREFLIGHT_DB_PASSWORD' => $config['password']];
    if (is_string($ca)) { $env['PRISM_MIGRATION_DB_SSL_CA'] = $ca; $env['PRISM_PREFLIGHT_DB_SSL_CA'] = $ca; }
    if (isset($runners[$step])) {
        $n = substr($step, 1);
        $env['PRISM_ALLOW_SCHEMA_V'.$n.'_MIGRATION'] = '1';
        $env['PRISM_SCHEMA_V'.$n.'_EXPECT_DB'] = $config['database'];
        $command = [PHP_BINARY, __DIR__.'/'.$runners[$step], $mode];
    } elseif ($step === 'deletion-verification') {
        if (!is_string($config['deletionVerificationKey'] ?? null) || ($config['externalDependenciesExcluded'] ?? null) !== true) {
            $fail('invalid_invocation', 'deletion-verification needs deletionVerificationKey and externalDependenciesExcluded: true.');
        }
        $env['PRISM_SCHEMA_VERIFY_KEY'] = $config['deletionVerificationKey'];
        $command = [PHP_BINARY, __DIR__.'/issue-deletion-verification.php', '--expect-db='.$config['database'], '--mode=shared-hosting',
            '--external-dependencies-excluded', '--out='.$receiptDir.DIRECTORY_SEPARATOR.'prism-deletion-verification-evidence-'.$stamp.'.json'];
    } else $command = [PHP_BINARY, __DIR__.'/runtime-preflight.php', '--database', '--expect-db='.$config['database']];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + getenv());
    if (!is_resource($process)) $fail('runner_unavailable', 'proc_open is unavailable for this PHP account; use the Remote MySQL route.');
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $receipt += ['exit' => proc_close($process), 'stdout' => json_decode($stdout, true) ?? $stdout, 'stderr' => json_decode($stderr, true) ?? $stderr];
}
$receipt['finishedAt'] = date(DATE_ATOM);
$json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
// Defence in depth: never persist the secret even if a runner echoed it.
$secrets = array_values(array_filter([$config['password'], $config['dsn'], $config['deletionVerificationKey'] ?? null], static fn($v) => is_string($v) && $v !== ''));
$json = str_replace($secrets, array_fill(0, count($secrets), '[redacted]'), (string)$json);
echo $json.PHP_EOL;
try { prism_private_write($receiptDir.DIRECTORY_SEPARATOR.'prism-'.$step.'-'.$mode.'-'.$stamp.'.json', $json.PHP_EOL); }
catch (Throwable $e) { $fail('receipt_not_written', 'The step ran, but its private receipt could not be written: '.$e->getMessage()); }
exit((int)$receipt['exit']);
