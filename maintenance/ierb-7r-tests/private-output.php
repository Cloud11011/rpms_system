<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // Test: never reachable over HTTP.
/** SEC-01 / SEC-02 regression without a database: private deletion-verification evidence, cron receipts and the
 * private cron configuration file (verified before it is read or used to connect).
 *   php maintenance/ierb-7r-tests/private-output.php
 * POSIX permission and ownership checks run only on POSIX hosts (the Hostinger target); on Windows they are listed
 * as skipped. Optional TLS probe checks run when PRISM_TEST_TLS_PORT, PRISM_TEST_TLS_CA and PRISM_TEST_TLS_WRONG_CA
 * name a disposable TLS-enabled server (root / prism_synthetic_only) with database prism_tls_probe.
 * Every file is created under the system temporary directory and removed at the end.
 */
$root = dirname(__DIR__, 2);
require $root.'/includes/migration_runner.php';
$checks = []; $skipped = [];
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks[] = $label; echo $label, PHP_EOL; };
$posix = DIRECTORY_SEPARATOR === '/';
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-private-output-'.bin2hex(random_bytes(4));
$refused = static function (callable $fn): ?string { try { $fn(); return null; } catch (PrismMigrationInputError $e) { return $e->getMessage(); } };
// Windows PHP cannot create links without privileges; Node can in Developer Mode.
$link = static function (string $target, string $path, bool $dir = false): bool {
    if (@symlink($target, $path)) return true;
    if (PHP_OS_FAMILY !== 'Windows') return false;
    $p = proc_open(['node', '-e', 'require("fs").symlinkSync(process.argv[1], process.argv[2], process.argv[3])', $target, $path, $dir ? 'junction' : 'file'], [], $pipes);
    $ok = is_resource($p) && proc_close($p) === 0; clearstatcache();
    return $ok && file_exists($path) || is_link($path);
};
$cli = static function (array $args, array $env = []) use ($root): array {
    $p = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + ['PATH' => getenv('PATH') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: '']);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($p), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true), 'errJson' => json_decode($err, true)];
};
$files = static fn(string $dir): array => array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
$mode = static function (string $path): int { clearstatcache(true, $path); return fileperms($path) & 0777; };
$key = str_repeat('ab', 32);
$rm = static function (string $path) use (&$rm): void {
    // Links (including Windows junctions, which PHP reports as neither file nor directory) are removed, never followed.
    if (is_link($path) || !is_dir($path)) { @unlink($path) || @rmdir($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $f) $rm($path.DIRECTORY_SEPARATOR.$f);
    @rmdir($path);
};
$status = 1;
try {
    mkdir($tmp, 0700); $private = $tmp.DIRECTORY_SEPARATOR.'private'; mkdir($private, 0700); chmod($private, 0700);
    $outside = $tmp.DIRECTORY_SEPARATOR.'outside'; mkdir($outside, 0700);

    // --- 1. The writer: exclusive, private, never overwrites, never follows a planted link.
    $mask = umask();
    $written = prism_private_write($private.'/a.json', '{"x":1}');
    $check(file_get_contents($written) === '{"x":1}' && basename($written) === 'a.json' && umask() === $mask, 'Writer creates the file with the exact content and restores the umask');
    $check($refused(fn() => prism_private_write($private.'/a.json', 'overwrite')) !== null && file_get_contents($written) === '{"x":1}', 'Writer never overwrites an existing file');
    foreach (['.hidden.json', 'bad name.json', 'semi;colon.json', ''] as $name) $check($refused(fn() => prism_private_target($private.'/'.$name)) !== null, 'Writer refuses the unsafe file name '.json_encode($name));
    $check($refused(fn() => prism_private_write($tmp.'/missing/a.json', 'x')) !== null, 'Writer refuses a missing directory');
    $check($refused(fn() => prism_private_write($root.'/maintenance/ierb-7r-tests/evidence-inside-app.json', 'x')) !== null && !file_exists($root.'/maintenance/ierb-7r-tests/evidence-inside-app.json'), 'Writer refuses a directory inside the deployed application');
    if ($link($outside.'/planted-target.json', $private.'/dangling.json')) {
        $check($refused(fn() => prism_private_write($private.'/dangling.json', 'secret')) !== null && !file_exists($outside.'/planted-target.json'), 'Writer refuses a dangling link planted at the target and creates nothing through it');
        file_put_contents($outside.'/victim.json', 'victim');
        $link($outside.'/victim.json', $private.'/to-victim.json');
        $check($refused(fn() => prism_private_write($private.'/to-victim.json', 'secret')) !== null && file_get_contents($outside.'/victim.json') === 'victim', 'Writer refuses a link to an existing file and leaves its target unchanged');
        $link($outside, $tmp.DIRECTORY_SEPARATOR.'linked-dir', true);
        $check($refused(fn() => prism_private_write($tmp.'/linked-dir/a.json', 'secret')) !== null && $files($outside) === ['victim.json'], 'Writer refuses a linked directory');
    } else $skipped[] = 'link checks (this account cannot create links)';
    if ($posix) {
        $check($mode($written) === 0600, 'POSIX: a new private file is 0600');
        umask(0); $loose = prism_private_write($private.'/loose-umask.json', 'x'); umask($mask);
        $check($mode($loose) === 0600, 'POSIX: the file is 0600 even under umask 000');
        foreach ([0755, 0750, 0711, 0701] as $bits) {
            chmod($private, $bits);
            $check($refused(fn() => prism_private_write($private.'/perm-'.decoct($bits).'.json', 'x')) !== null && !file_exists($private.'/perm-'.decoct($bits).'.json'), 'POSIX: a directory with mode '.decoct($bits).' is refused');
        }
        chmod($private, 0700);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $foreign = $tmp.'/foreign'; mkdir($foreign, 0700); chown($foreign, 65534);
            $check($refused(fn() => prism_private_write($foreign.'/a.json', 'x')) !== null, 'POSIX: a directory owned by another account is refused');
        } else $skipped[] = 'foreign-owner directory (needs root to create)';
    } else $skipped[] = 'POSIX mode and ownership checks (Windows host)';

    // --- 2. SEC-01: the issuer refuses unsafe or missing output before connecting, and never prints evidence.
    $issue = fn(array $args, array $env = []) => $cli(['maintenance/issue-deletion-verification.php', '--expect-db=prism_private_output_test', '--mode=shared-hosting', '--external-dependencies-excluded', ...$args], $env + ['PRISM_SCHEMA_VERIFY_KEY' => $key]);
    $r = $issue([]);
    $check($r['exit'] === 1 && $r['out'] === '' && str_contains((string)($r['errJson']['message'] ?? ''), '--out'), 'Issuer requires --out: evidence is never printed to stdout');
    $r = $issue(['--out=']);
    $check($r['exit'] === 1 && $r['out'] === '', 'Issuer refuses an empty --out');
    $r = $issue(['--out='.$root.'/evidence-inside-app.json']);
    $check($r['exit'] === 1 && $r['out'] === '' && !file_exists($root.'/evidence-inside-app.json'), 'Issuer refuses evidence inside the application directory');
    $r = $issue(['--out='.$written]);
    $check($r['exit'] === 1 && $r['out'] === '' && file_get_contents($written) === '{"x":1}', 'Issuer refuses an existing --out file and leaves it unchanged');
    if (is_link($private.'/dangling.json')) {
        $r = $issue(['--out='.$private.'/dangling.json']);
        $check($r['exit'] === 1 && $r['out'] === '' && !file_exists($outside.'/planted-target.json'), 'Issuer refuses a planted link at --out');
        $r = $issue(['--out='.$tmp.'/linked-dir/evidence.json']);
        $check($r['exit'] === 1 && $r['out'] === '' && $files($outside) === ['victim.json'], 'Issuer refuses a linked --out directory');
    }
    if ($posix) {
        chmod($private, 0755); $r = $issue(['--out='.$private.'/evidence.json']); chmod($private, 0700);
        $check($r['exit'] === 1 && !file_exists($private.'/evidence.json'), 'POSIX: issuer refuses a group/world-accessible --out directory');
    }
    $before = $files($private);
    $r = $issue(['--out='.$private.'/evidence.json']); // valid destination; no DSN, so issuance stops before any file is created
    $check($r['exit'] === 1 && $r['out'] === '' && ($r['errJson']['errorClass'] ?? '') === 'invalid_invocation' && $files($private) === $before, 'Issuer creates no evidence file when issuance does not complete');
    $check(!str_contains($r['out'].$r['err'], $key), 'Issuer never prints the key');

    // --- 3. SEC-02: private receipt storage and the probe's connection policy.
    // Operators keep the configuration at chmod 600 (section 5 tests the refusals).
    $config = static function (array $values) use ($tmp): string { $file = $tmp.DIRECTORY_SEPARATOR.'cron-'.bin2hex(random_bytes(3)).'.json'; file_put_contents($file, json_encode($values)); chmod($file, 0600); return $file; };
    $base = ['dsn' => 'mysql:host=127.0.0.1;port=1;dbname=prism_private_output_test', 'user' => 'probe_user', 'password' => 'probe-secret-123', 'database' => 'prism_private_output_test', 'receiptDir' => $private];
    $cron = fn(array $values, string $step = 'probe', string $m = 'x') => $cli(['maintenance/hostinger-cron-runner.php', $config($values), $step, $m]);
    $r = $cron(['receiptDir' => $root.'/maintenance'] + $base);
    $check($r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === 'unsafe_configuration', 'Cron runner refuses a receipt directory inside the application');
    $r = $cron(['receiptDir' => $tmp.'/missing'] + $base);
    $check($r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === 'unsafe_configuration', 'Cron runner refuses a missing receipt directory');
    if (file_exists($tmp.DIRECTORY_SEPARATOR.'linked-dir')) {
        $r = $cron(['receiptDir' => $tmp.DIRECTORY_SEPARATOR.'linked-dir'] + $base);
        $check($r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === 'unsafe_configuration' && $files($outside) === ['victim.json'], 'Cron runner refuses a linked receipt directory');
    }
    if ($posix) {
        chmod($private, 0755); $before = $files($private); $r = $cron($base); chmod($private, 0700);
        $check($r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === 'unsafe_configuration' && $files($private) === $before, 'POSIX: cron runner refuses a group/world-accessible receipt directory');
    }
    $before = $files($private);
    foreach (['../../escape', 'X', 'a b'] as $bad) {
        $r = $cron($base, 'probe', $bad);
        $check($r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === 'invalid_invocation' && $files($private) === $before, 'Cron runner refuses the unsafe mode '.json_encode($bad).' that would name the receipt');
    }
    $r = $cron(['sslCa' => $tmp.'/no-such-ca.pem'] + $base);
    $receipts = array_values(array_diff($files($private), $before));
    $text = $receipts ? file_get_contents($private.'/'.$receipts[0]) : '';
    $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'invalid_invocation' && ($r['json']['connected'] ?? null) === false, 'Probe applies the migration runner\'s TLS rule: an unreadable CA is refused before connecting');
    $check(count($receipts) === 1 && preg_match('/\Aprism-probe-x-\d{8}-\d{6}-[0-9a-f]{6}\.json\z/', $receipts[0]) && !str_contains($text, 'probe-secret-123') && !str_contains($text, 'probe_user@') && !str_contains($text, 'port=1'),
        'The probe receipt is stored privately with a unique name and carries no password or DSN');
    if ($posix) $check($mode($private.'/'.$receipts[0]) === 0600, 'POSIX: the receipt file is 0600');
    $r = $cron(['dsn' => 'sqlite::memory:'] + $base);
    $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'invalid_invocation', 'Probe refuses a non-MySQL DSN through the shared connection policy');
    $r = $cron($base);
    $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'connection_failed' && !str_contains($r['out'].$r['err'], 'probe-secret-123'), 'A failed probe reports only connection_failed (no credentials)');

    // --- 4. Optional: TLS verification against a disposable TLS server (same policy for probe, preflight and runners).
    if (getenv('PRISM_TEST_TLS_PORT') && getenv('PRISM_TEST_TLS_CA') && getenv('PRISM_TEST_TLS_WRONG_CA')) {
        $tls = ['dsn' => 'mysql:host=127.0.0.1;port='.getenv('PRISM_TEST_TLS_PORT').';dbname=prism_tls_probe', 'user' => 'root', 'password' => 'prism_synthetic_only', 'database' => 'prism_tls_probe', 'receiptDir' => $private];
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_CA')] + $tls);
        $check($r['exit'] === 0 && $r['json']['databaseMatches'] === true && $r['json']['tls']['caVerification'] === true && is_string($r['json']['tls']['cipher']) && $r['json']['schemaVersion'] === '11', 'TLS: the probe verifies the server certificate with the configured CA and records the negotiated cipher');
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_WRONG_CA')] + $tls);
        $check($r['exit'] === 1 && $r['json']['errorClass'] === 'connection_failed' && $r['json']['connected'] === false, 'TLS: the probe refuses a server certificate not signed by the configured CA');
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_WRONG_CA')] + $tls, 'preflight');
        $check($r['exit'] === 1 && ($r['json']['stdout']['database']['errorClass'] ?? '') === 'connection_failed', 'TLS: the preflight step applies the same certificate verification');
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_WRONG_CA')] + $tls, 'v12', 'dry-run');
        $check($r['exit'] === 1 && ($r['json']['stderr']['errorClass'] ?? '') === 'connection_failed', 'TLS: the migration runner refuses the same certificate (one policy for every step)');
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_CA'), 'database' => 'another_database'] + $tls);
        $check($r['exit'] === 1 && $r['json']['errorClass'] === 'database_mismatch' && !isset($r['json']['schemaVersion']), 'The probe refuses a connection to a database other than the configured one');
        $r = $cron(['sslCa' => getenv('PRISM_TEST_TLS_CA'), 'database' => 'another_database'] + $tls, 'preflight');
        $check($r['exit'] === 1 && ($r['json']['stdout']['database']['errorClass'] ?? '') === 'database_mismatch', 'The preflight step refuses a database other than the configured one');
        if (getenv('PRISM_TEST_PLAIN_PORT')) {
            $r = $cron(['dsn' => 'mysql:host=127.0.0.1;port='.getenv('PRISM_TEST_PLAIN_PORT').';dbname=prism_tls_probe', 'sslCa' => getenv('PRISM_TEST_TLS_CA')] + $tls);
            $check($r['exit'] === 1 && in_array($r['json']['errorClass'], ['connection_failed', 'tls_not_negotiated'], true), 'TLS: with a CA configured, a server without TLS is refused ('.$r['json']['errorClass'].')');
        } else $skipped[] = 'TLS downgrade refusal (PRISM_TEST_PLAIN_PORT not set)';
    } else $skipped[] = 'TLS server checks (PRISM_TEST_TLS_* not set)';

    // --- 5. SEC-02: the private cron configuration holds the database password. Every step verifies it before it is
    // read, parsed or used to connect, and the runner never changes its permissions.
    $cfg = $tmp.DIRECTORY_SEPARATOR.'cfg'; mkdir($cfg, 0700); chmod($cfg, 0700);
    $put = static function (string $file, string $contents, int $perm = 0600): string { file_put_contents($file, $contents); chmod($file, $perm); return $file; };
    // A listener that records connection attempts: a refused configuration must never reach a database.
    $trap = stream_socket_server('tcp://127.0.0.1:0');
    $trapDsn = 'mysql:host=127.0.0.1;port='.parse_url('tcp://'.stream_socket_get_name($trap, false), PHP_URL_PORT).';dbname=prism_private_output_test';
    $contacted = static function () use ($trap): bool { $c = @stream_socket_accept($trap, 0); if ($c) fclose($c); return (bool)$c; };
    // The listener never answers, so a connection to it fails after two seconds instead of hanging.
    $runCfg = fn(string $file, string $step = 'probe', string $m = 'x', array $php = []) => $cli(['-d', 'default_socket_timeout=2', '-d', 'mysqlnd.net_read_timeout=2', ...$php, 'maintenance/hostinger-cron-runner.php', $file, $step, $m]);
    $trapConfig = json_encode(['dsn' => $trapDsn] + $base);
    $ok = $put($cfg.DIRECTORY_SEPARATOR.'ok.json', $trapConfig);
    $r = $runCfg($ok);
    // Without pdo_mysql the runner cannot connect at all, so the listener control only applies with the driver.
    $driver = extension_loaded('pdo_mysql');
    if (!$driver) $skipped[] = 'connection-trap control (pdo_mysql not loaded, so no connection is possible)';
    $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'connection_failed' && $contacted() === $driver && ($r['json']['configurationFile']['ownerAndModeVerified'] ?? null) === $posix,
        'Configuration: a file that passes every check is read and the probe connects'.($driver ? ' (control: the listener sees the attempt)' : '').'; the receipt records ownerAndModeVerified='.json_encode($posix));
    $receiptsBefore = $files($private);
    $stopped = static function (array $r, string $class) use ($files, $private, &$receiptsBefore, $contacted): bool {
        return $r['exit'] === 1 && ($r['errJson']['errorClass'] ?? '') === $class && $r['out'] === '' && $files($private) === $receiptsBefore && !$contacted()
            && !str_contains($r['out'].$r['err'], 'probe-secret-123') && !str_contains($r['out'].$r['err'], 'probe_user');
    };
    $r = $runCfg('');
    $check($stopped($r, 'invalid_invocation'), 'Configuration: an empty path is refused');
    $r = $runCfg($cfg.DIRECTORY_SEPARATOR.'missing.json');
    $check($stopped($r, 'invalid_invocation'), 'Configuration: a missing file is refused, with no receipt and no connection attempt');
    $r = $runCfg($cfg);
    $check($stopped($r, 'unsafe_configuration'), 'Configuration: a directory is refused');
    $r = $runCfg($root.DIRECTORY_SEPARATOR.'composer.json');
    $check($stopped($r, 'unsafe_configuration'), 'Configuration: a file inside the deployed application is refused before it is parsed');
    mkdir($tmp.DIRECTORY_SEPARATOR.'public_html', 0700);
    $web = $put($tmp.DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'migration.json', $trapConfig);
    $r = $runCfg($web);
    $check($stopped($r, 'unsafe_configuration'), 'Configuration: a file inside a public_html web root outside the application is refused');
    foreach (['..'.DIRECTORY_SEPARATOR.'cfg', '.'] as $odd) {
        $r = $runCfg($cfg.DIRECTORY_SEPARATOR.$odd.DIRECTORY_SEPARATOR.'ok.json');
        $check($stopped($r, 'unsafe_configuration'), 'Configuration: a non-canonical path (with '.json_encode(strtok($odd, '/\\')).') is refused');
    }
    if ($link($ok, $cfg.DIRECTORY_SEPARATOR.'linked.json') && $link($cfg, $tmp.DIRECTORY_SEPARATOR.'cfg-link', true)) {
        $r = $runCfg($cfg.DIRECTORY_SEPARATOR.'linked.json');
        $check($stopped($r, 'unsafe_configuration'), 'Configuration: a symbolic link to a valid configuration file is refused');
        $r = $runCfg($tmp.DIRECTORY_SEPARATOR.'cfg-link'.DIRECTORY_SEPARATOR.'ok.json');
        $check($stopped($r, 'unsafe_configuration'), 'Configuration: a valid file reached through a linked folder is refused');
        $link($cfg.DIRECTORY_SEPARATOR.'gone.json', $cfg.DIRECTORY_SEPARATOR.'dangling.json');
        $r = $runCfg($cfg.DIRECTORY_SEPARATOR.'dangling.json');
        $check($stopped($r, 'unsafe_configuration'), 'Configuration: a dangling link is refused');
    } else $skipped[] = 'configuration link checks (this account cannot create links)';
    $r = $runCfg($put($cfg.DIRECTORY_SEPARATOR.'broken.json', '{"password":"probe-secret-123",'));
    $check($stopped($r, 'invalid_invocation') && str_contains($r['errJson']['message'] ?? '', 'not valid JSON'), 'Configuration: invalid JSON is refused without echoing its content');
    $r = $runCfg($put($cfg.DIRECTORY_SEPARATOR.'scalar.json', '"probe-secret-123"'));
    $check($stopped($r, 'invalid_invocation'), 'Configuration: a JSON value that is not an object is refused');
    $partial = ['dsn' => $trapDsn] + $base; unset($partial['database']);
    $r = $runCfg($put($cfg.DIRECTORY_SEPARATOR.'partial.json', json_encode($partial)));
    $check($stopped($r, 'invalid_invocation') && str_contains($r['errJson']['message'] ?? '', 'database'), 'Configuration: a missing field is named without echoing any value');
    foreach ([['preflight', 'x'], ['v12', 'dry-run'], ['v15', 'apply'], ['deletion-verification', 'x']] as [$s, $m]) {
        $r = $runCfg($web, $s, $m);
        $check($stopped($r, 'unsafe_configuration'), 'Configuration: the '.$s.' '.$m.' step refuses an unsafe configuration before running anything');
    }
    // The same location rule now covers private output: Hostinger's web server runs as the account and can serve a 0600 file.
    $webReceipts = $tmp.DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'receipts'; mkdir($webReceipts, 0700); chmod($webReceipts, 0700);
    $r = $runCfg($put($cfg.DIRECTORY_SEPARATOR.'web-receipts.json', json_encode(['dsn' => $trapDsn, 'receiptDir' => $webReceipts] + $base)));
    $check($stopped($r, 'unsafe_configuration') && $files($webReceipts) === [], 'Receipts: a receipt directory inside public_html is refused');
    $r = $issue(['--out='.$webReceipts.DIRECTORY_SEPARATOR.'evidence.json']);
    $check($r['exit'] === 1 && $r['out'] === '' && $files($webReceipts) === [], 'Issuer refuses evidence inside public_html');
    if ($posix) {
        foreach ([0644, 0640, 0604, 0660, 0606, 0620, 0602, 0610, 0601] as $bits) {
            $file = $put($cfg.'/mode-'.decoct($bits).'.json', $trapConfig, $bits);
            $r = $runCfg($file);
            $check($stopped($r, 'unsafe_configuration') && $mode($file) === $bits, 'POSIX: a configuration file with mode '.decoct($bits).' is refused before it is read, and its mode is left unchanged');
        }
        $r = $runCfg($put($cfg.'/loose-broken.json', '{"password":"probe-secret-123",', 0644));
        $check($stopped($r, 'unsafe_configuration'), 'POSIX: a group/world-readable file is refused before it is parsed (its invalid JSON is never reached)');
        foreach ([0600, 0400] as $bits) {
            $r = $runCfg($put($cfg.'/accepted-'.decoct($bits).'.json', json_encode($base), $bits));
            $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'connection_failed' && ($r['json']['configurationFile']['ownerAndModeVerified'] ?? null) === true,
                'POSIX: a configuration file with mode '.decoct($bits).' owned by this account is accepted');
        }
        $receiptsBefore = $files($private);
        foreach ([0770, 0730, 0720, 0703, 0777] as $bits) {
            chmod($cfg, $bits); $r = $runCfg($ok); chmod($cfg, 0700);
            $check($stopped($r, 'unsafe_configuration'), 'POSIX: a configuration folder with mode '.decoct($bits).' (writable by group or others) is refused');
        }
        chmod($cfg, 0755); $r = $runCfg($put($cfg.'/folder-755.json', json_encode($base))); chmod($cfg, 0700);
        $check($r['exit'] === 1 && ($r['json']['errorClass'] ?? '') === 'connection_failed', 'POSIX: a configuration folder with mode 755 (only the owner can write) is accepted');
        $receiptsBefore = $files($private);
        $r = $runCfg($ok, 'probe', 'x', ['-d', 'disable_functions=posix_geteuid']);
        $check($stopped($r, 'unsafe_configuration') && str_contains($r['errJson']['message'] ?? '', 'posix'), 'POSIX: without the posix extension the owner cannot be verified, so the runner fails closed');
        $r = $cli(['-d', 'disable_functions=posix_geteuid', 'maintenance/issue-deletion-verification.php', '--expect-db=prism_private_output_test', '--mode=shared-hosting', '--external-dependencies-excluded', '--out='.$private.'/posixless.json'], ['PRISM_SCHEMA_VERIFY_KEY' => $key]);
        $check($r['exit'] === 1 && $r['out'] === '' && str_contains($r['errJson']['message'] ?? '', 'posix') && !file_exists($private.'/posixless.json'), 'POSIX: without the posix extension the issuer refuses its output directory (fails closed)');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $foreignFile = $put($cfg.'/foreign.json', $trapConfig); chown($foreignFile, 65534);
            $r = $runCfg($foreignFile);
            $check($stopped($r, 'unsafe_configuration'), 'POSIX: a mode-600 configuration file owned by another account is refused (root could read it)');
            $foreignDir = $tmp.'/foreign-cfg'; mkdir($foreignDir, 0700); $inForeign = $put($foreignDir.'/ok.json', $trapConfig); chown($foreignDir, 65534);
            $r = $runCfg($inForeign);
            $check($stopped($r, 'unsafe_configuration'), 'POSIX: a configuration folder owned by another account is refused');
        } else $skipped[] = 'foreign-owner configuration file and folder (needs root to create)';
    } else $skipped[] = 'POSIX configuration mode, folder and ownership checks (Windows host: no POSIX owner or mode bits; receipts record ownerAndModeVerified=false)';
    fclose($trap);
    $status = 0;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
} finally {
    $rm($tmp);
}
echo json_encode(['status' => $status === 0 ? 'PASS' : 'FAIL', 'platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'assertions' => count($checks), 'skipped' => $skipped], JSON_PRETTY_PRINT), PHP_EOL;
exit($status);
