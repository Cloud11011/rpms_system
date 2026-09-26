<?php
namespace PrismSecurityAudit;
use RuntimeException;
use PDOException;
use Throwable;

/** CLI-only, configuration-free tests of actual security helpers and origin guard. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function header(string $value): void { $GLOBALS['headers'][] = $value; }
function http_response_code(?int $value = null): int
{ if ($value !== null) $GLOBALS['status'] = $value; return $GLOBALS['status']; }
function error_log(string $value): bool { $GLOBALS['logs'][] = $value; return true; }
function ini_set(string $key, string $value): string|false { $GLOBALS['settings'][$key] = $value; return ''; }
function set_exception_handler(callable|string $handler): ?callable { $GLOBALS['handler'] = $handler; return null; }

// Fake connection class for the real db() initializer; no PDO driver or connection is used.
class PDO
{
    const ATTR_ERRMODE = 1, ERRMODE_EXCEPTION = 2, ATTR_DEFAULT_FETCH_MODE = 3, FETCH_ASSOC = 4, ATTR_EMULATE_PREPARES = 5;
    public static int $attempts = 0;
    public function __construct(...$args) {
        self::$attempts++;
        if (($GLOBALS['case']['dbFailure'] ?? '') === 'connect' && self::$attempts === 1) throw new PDOException('Fixture connection failure');
    }
    public function query(string $sql): object {
        return new class {
            public function fetchColumn(): int { return ($GLOBALS['case']['dbFailure'] ?? '') === 'seed' ? 0 : 1; }
        };
    }
}
function migrate(PDO $pdo): void {
    $GLOBALS['migrations']++;
    if ($GLOBALS['case']['dbFailure'] === 'migrate' && $GLOBALS['migrations'] === 1) throw new PDOException('Fixture migration failure');
}
function seed(PDO $pdo): void {
    $GLOBALS['seeds']++;
    if ($GLOBALS['case']['dbFailure'] === 'seed' && $GLOBALS['seeds'] === 1) throw new PDOException('Fixture seed failure');
}

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    define(__NAMESPACE__ . '\PHP_SAPI', $case['sapi'] ?? 'fpm-fcgi');
    define(__NAMESPACE__ . '\APP_ENV', $case['env'] ?? 'production');
    define(__NAMESPACE__ . '\APP_BASE_URL', $case['url'] ?? 'https://prism.example.test/app');
    define(__NAMESPACE__ . '\ALLOW_DEVELOPMENT_PASSWORD_RESPONSE', $case['allowPassword'] ?? false);
    $_SERVER = array_replace(['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'prism.example.test',
        'HTTPS' => 'on', 'HTTP_ORIGIN' => 'https://prism.example.test', 'REMOTE_ADDR' => '203.0.113.9',
        'SCRIPT_NAME' => '/index.php'], $case['server'] ?? []);
    foreach ($case['unset'] ?? [] as $key) unset($_SERVER[$key]);
    $headers = $logs = $settings = []; $status = 200; $result = null; $handler = null;
    $source = file_get_contents(__DIR__ . '/../security.php');
    eval('namespace ' . __NAMESPACE__ . '; use \Throwable; use \PDOException; ' . preg_replace('/^<\?php\s*/', '', $source));
    ob_start();
    register_shutdown_function(function () {
        $output = ob_get_clean();
        echo json_encode(['status' => $GLOBALS['status'], 'headers' => $GLOBALS['headers'],
            'logs' => $GLOBALS['logs'], 'settings' => $GLOBALS['settings'], 'handler' => $GLOBALS['handler'],
            'output' => $output, 'result' => $GLOBALS['result']]);
    });
    switch ($case['kind']) {
        case 'database':
            foreach (['DB_HOST' => 'fixture', 'DB_PORT' => 3306, 'DB_NAME' => 'fixture', 'DB_USER' => 'fixture', 'DB_PASS' => 'fixture'] as $key => $value) define(__NAMESPACE__ . '\\' . $key, $value);
            $migrations = $seeds = 0;
            $config = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../config.php'));
            $start = strpos($config, 'function db(): PDO');
            $end = strpos($config, "\n}\n", $start);
            if ($start === false || $end === false) throw new RuntimeException('Cannot isolate database initializer.');
            eval('namespace ' . __NAMESPACE__ . '; ' . substr($config, $start, $end + 2 - $start));
            $failed = false;
            try { db(); } catch (PDOException $e) { $failed = true; }
            $connection = db();
            $result = ['failed' => $failed, 'freshAttempts' => PDO::$attempts, 'cached' => db() === $connection];
            break;
        case 'origin':
            $config = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../config.php'));
            $start = strpos($config, 'function require_post_same_origin(): void');
            $end = strpos($config, "\n}\n", $start);
            if ($start === false || $end === false) throw new RuntimeException('Cannot isolate origin guard.');
            eval('namespace ' . __NAMESPACE__ . '; ' . substr($config, $start, $end + 2 - $start));
            require_post_same_origin();
            $result = 'allowed';
            break;
        case 'url':
            $result = ['valid' => app_base_url_is_valid(), 'issues' => application_configuration_issues()];
            break;
        case 'password':
            $result = account_setup_response_fields($case['delivery'] ?? ['ok' => true, 'channel' => 'log'], 'FIXTURE-ONLY-PASSWORD');
            break;
        case 'headers':
            install_application_security();
            break;
        case 'error':
            handle_unhandled_application_error(!empty($case['pdo'])
                ? new PDOException('FIXTURE-PRIVATE-DIAGNOSTIC sql password=do-not-display')
                : new RuntimeException('FIXTURE-PRIVATE-DIAGNOSTIC'));
        default: throw new RuntimeException('Unexpected fixture case.');
    }
    exit;
}
$cases = [
    ['name' => 'Connection failure does not poison cached DB handle', 'kind' => 'database', 'dbFailure' => 'connect'],
    ['name' => 'Migration failure does not cache uninitialized DB handle', 'kind' => 'database', 'dbFailure' => 'migrate'],
    ['name' => 'Seed failure does not cache uninitialized DB handle', 'kind' => 'database', 'dbFailure' => 'seed'],
    ['name' => 'Same-origin HTTPS POST', 'kind' => 'origin', 'expected' => 200],
    ['name' => 'Same-origin Referer fallback', 'kind' => 'origin', 'unset' => ['HTTP_ORIGIN'],
        'server' => ['HTTP_REFERER' => 'https://prism.example.test/account.php'], 'expected' => 200],
    ['name' => 'Default HTTPS port normalized', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'https://PRISM.EXAMPLE.TEST:443'], 'expected' => 200],
    ['name' => 'Local alternate port accepted', 'kind' => 'origin',
        'server' => ['HTTPS' => 'off', 'HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:8080'], 'expected' => 200],
    ['name' => 'IPv6 loopback origin accepted', 'kind' => 'origin',
        'server' => ['HTTPS' => 'off', 'HTTP_HOST' => '[::1]:8080', 'HTTP_ORIGIN' => 'http://[::1]:8080'], 'expected' => 200],
    ['name' => 'Existing HTTPS proxy convention accepted', 'kind' => 'origin',
        'server' => ['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], 'expected' => 200],
    ['name' => 'Missing Origin and Referer rejected', 'kind' => 'origin', 'unset' => ['HTTP_ORIGIN'], 'expected' => 403],
    ['name' => 'Cross-site Origin rejected', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'https://attacker.test'], 'expected' => 403],
    ['name' => 'Cross-site Referer rejected', 'kind' => 'origin', 'unset' => ['HTTP_ORIGIN'],
        'server' => ['HTTP_REFERER' => 'https://attacker.test/form'], 'expected' => 403],
    ['name' => 'Cross-scheme request rejected', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'http://prism.example.test'], 'expected' => 403],
    ['name' => 'Different port rejected', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'https://prism.example.test:8443'], 'expected' => 403],
    ['name' => 'Null Origin cannot fall back to trusted Referer', 'kind' => 'origin',
        'server' => ['HTTP_ORIGIN' => 'null', 'HTTP_REFERER' => 'https://prism.example.test/form'], 'expected' => 403],
    ['name' => 'Empty Origin rejected', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => ''], 'expected' => 403],
    ['name' => 'Userinfo Origin rejected', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'https://user@prism.example.test'], 'expected' => 403],
    ['name' => 'Invalid port rejected safely', 'kind' => 'origin', 'server' => ['HTTP_ORIGIN' => 'https://prism.example.test:999999'], 'expected' => 403],
    ['name' => 'GET action rejected', 'kind' => 'origin', 'server' => ['REQUEST_METHOD' => 'GET'], 'expected' => 405],
    ['name' => 'Production HTTPS base URL accepted', 'kind' => 'url', 'valid' => true],
    ['name' => 'Missing base URL detected', 'kind' => 'url', 'url' => '', 'valid' => false],
    ['name' => 'HTTP production URL rejected', 'kind' => 'url', 'url' => 'http://prism.example.test', 'valid' => false],
    ['name' => 'Development localhost URL accepted', 'kind' => 'url', 'env' => 'development', 'url' => 'http://localhost:8080/rpms_system', 'valid' => true],
    ['name' => 'Production localhost URL rejected', 'kind' => 'url', 'url' => 'https://localhost/rpms_system', 'valid' => false],
    ['name' => 'URL credentials rejected without disclosure', 'kind' => 'url', 'url' => 'https://secret:FIXTURE-PRIVATE-DIAGNOSTIC@prism.example.test', 'valid' => false],
    ['name' => 'URL query rejected', 'kind' => 'url', 'url' => 'https://prism.example.test/?secret=1', 'valid' => false],
    ['name' => 'URL fragment rejected', 'kind' => 'url', 'url' => 'https://prism.example.test/#part', 'valid' => false],
    ['name' => 'Non-HTTP URL rejected', 'kind' => 'url', 'url' => 'javascript:alert(1)', 'valid' => false],
    ['name' => 'Control characters in URL rejected', 'kind' => 'url', 'url' => "https://prism.example.test/\r\nx", 'valid' => false],
    ['name' => 'Production password fallback suppressed', 'kind' => 'password', 'disclose' => false],
    ['name' => 'Production flag cannot expose password', 'kind' => 'password', 'allowPassword' => true, 'disclose' => false],
    ['name' => 'Development requires explicit opt-in', 'kind' => 'password', 'env' => 'development',
        'server' => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], 'disclose' => false],
    ['name' => 'Opted-in loopback development fallback works', 'kind' => 'password', 'env' => 'development', 'allowPassword' => true,
        'server' => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], 'disclose' => true],
    ['name' => 'Remote peer cannot use development fallback', 'kind' => 'password', 'env' => 'development', 'allowPassword' => true,
        'server' => ['HTTP_HOST' => 'localhost'], 'disclose' => false],
    ['name' => 'Public host cannot use development fallback', 'kind' => 'password', 'env' => 'development', 'allowPassword' => true,
        'server' => ['REMOTE_ADDR' => '127.0.0.1'], 'disclose' => false],
    ['name' => 'Delivered setup never includes password', 'kind' => 'password', 'env' => 'development', 'allowPassword' => true,
        'server' => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], 'delivery' => ['ok' => true, 'channel' => 'mail'], 'disclose' => false],
    ['name' => 'Web bootstrap installs safe error/header policy', 'kind' => 'headers'],
    ['name' => 'CLI bootstrap emits no web headers', 'kind' => 'headers', 'sapi' => 'cli'],
    ['name' => 'PDO failure gives generic JSON', 'kind' => 'error', 'pdo' => true, 'server' => ['SCRIPT_NAME' => '/students_api.php'], 'expected' => 503, 'json' => true],
    ['name' => 'PDO failure gives generic HTML', 'kind' => 'error', 'pdo' => true, 'expected' => 503],
    ['name' => 'AJAX failure gives generic JSON', 'kind' => 'error', 'server' => ['HTTP_ACCEPT' => 'application/json'], 'expected' => 500, 'json' => true],
];
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start security fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $r = json_decode($output, true);
    $pass = $exit === 0 && $error === '' && is_array($r) && !str_contains($output, 'FIXTURE-PRIVATE-DIAGNOSTIC')
        && $r['status'] === ($case['expected'] ?? 200);
    if ($pass) {
        if ($case['kind'] === 'database') $pass = $r['result'] === ['failed' => true, 'freshAttempts' => 2, 'cached' => true];
        if ($case['kind'] === 'origin' && $r['status'] === 200) $pass = $r['result'] === 'allowed';
        if ($case['kind'] === 'origin' && $r['status'] !== 200) $pass = (json_decode($r['output'], true)['ok'] ?? true) === false;
        if ($case['kind'] === 'url') $pass = $r['result']['valid'] === $case['valid'] && (bool)$r['result']['issues'] === !$case['valid'];
        if ($case['kind'] === 'password') {
            $pass = isset($r['result']['temporaryPassword']) === $case['disclose'];
            if ($case['disclose']) $pass = $pass && $r['result']['temporaryPassword'] === 'FIXTURE-ONLY-PASSWORD';
            else $pass = $pass && !str_contains($output, 'FIXTURE-ONLY-PASSWORD');
            $pass = $pass && $r['result']['setupPending'] === empty($case['delivery']);
        }
        if ($case['kind'] === 'headers') {
            $pass = $r['handler'] === 'handle_unhandled_application_error';
            if (($case['sapi'] ?? '') === 'cli') $pass = $pass && !$r['headers'] && !$r['settings'];
            else {
                $pass = $pass && $r['settings']['display_errors'] === '0' && $r['settings']['display_startup_errors'] === '0'
                    && in_array('X-Frame-Options: SAMEORIGIN', $r['headers'], true)
                    && in_array('Referrer-Policy: strict-origin-when-cross-origin', $r['headers'], true)
                    && in_array("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'", $r['headers'], true);
            }
        }
        if ($case['kind'] === 'error') {
            $pass = count($r['logs']) === 1 && in_array('Cache-Control: no-store', $r['headers'], true);
            if (!empty($case['json'])) $pass = $pass && (json_decode($r['output'], true)['ok'] ?? true) === false;
            else $pass = $pass && str_contains($r['output'], '<h1>PRISM is temporarily unavailable</h1>');
        }
    }
    if (!$pass) { fwrite(STDERR, "FAIL: {$case['name']}\n$error$output\n"); exit(1); }
    echo "PASS: {$case['name']}\n";
}
echo count($cases) . " security boundary cases passed.\n";
