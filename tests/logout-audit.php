<?php
namespace PrismLogoutAudit;
/** Isolated actual logout handler; no configuration, database or real session. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function current_user(): ?array { return $GLOBALS['case']['anonymous'] ? null : ['email' => 'fixture@example.test']; }
function log_activity(...$args): void { $GLOBALS['logs'][] = $args; }
function ini_get(string $name): string { return $GLOBALS['case']['cookies'] ? '1' : '0'; }
function session_get_cookie_params(): array { return $GLOBALS['case']['params']; }
function session_name(): string { return 'PRISM_FIXTURE_SESSION'; }
function time(): int { return 1800000000; }
function setcookie(...$args): bool { $GLOBALS['cookieCalls'][] = $args; return true; }
function session_destroy(): bool {
    $GLOBALS['destroyCalls']++;
    $GLOBALS['clearedBeforeDestroy'] = $_SESSION === [];
    return true;
}
function header(string $header): void { $GLOBALS['headers'][] = $header; }
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $logs = $cookieCalls = $headers = []; $destroyCalls = 0; $clearedBeforeDestroy = false;
    $_SESSION = ['user_id' => 1, 'credential_fingerprint' => 'fixture-binding'];
    ob_start();
    register_shutdown_function(function () {
        $output = ob_get_clean();
        echo json_encode(['session' => $_SESSION, 'logs' => $GLOBALS['logs'], 'cookieCalls' => $GLOBALS['cookieCalls'],
            'headers' => $GLOBALS['headers'], 'destroyCalls' => $GLOBALS['destroyCalls'],
            'clearedBeforeDestroy' => $GLOBALS['clearedBeforeDestroy'], 'output' => $output]);
    });
    $source = file_get_contents(__DIR__ . '/../logout.php');
    $source = str_replace("require __DIR__ . '/config.php';", '', $source, $includes);
    if ($includes !== 1) throw new \RuntimeException('Unexpected config includes.');
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) throw new \RuntimeException('Unexpected include.');
    }
    eval('namespace ' . __NAMESPACE__ . '; ' . preg_replace('/^<\?php\s*/', '', $source));
    throw new \RuntimeException('Logout did not exit.');
}
$defaults = ['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'];
$withoutSameSite = $defaults; unset($withoutSameSite['samesite']);
$cases = [
    ['name' => 'Default Lax cookie', 'params' => $defaults, 'sameSite' => 'Lax'],
    ['name' => 'Custom HTTPS scope', 'params' => array_replace($defaults, ['path' => '/prism/', 'domain' => 'fixture.test', 'secure' => true, 'samesite' => 'Strict']), 'sameSite' => 'Strict'],
    ['name' => 'Missing SameSite defaults to Lax', 'params' => $withoutSameSite, 'sameSite' => 'Lax'],
    ['name' => 'Anonymous logout without cookies', 'params' => $defaults, 'sameSite' => 'Lax', 'cookies' => false, 'anonymous' => true],
];
$checks = 0;
function check(bool $condition, string $label): void {
    $GLOBALS['checks']++;
    if (!$condition) throw new \RuntimeException('FAIL: ' . $label);
}
foreach ($cases as $case) {
    $case += ['cookies' => true, 'anonymous' => false];
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new \RuntimeException('Cannot start fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    check($exit === 0 && $error === '', $case['name'] . ': clean execution');
    $r = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    check($r['output'] === '', 'no response body');
    check($r['session'] === [] && $r['clearedBeforeDestroy'], 'session cleared first');
    check($r['destroyCalls'] === 1, 'destroy once');
    check($r['headers'] === ['Location: login.php'], 'redirect preserved');
    check($r['logs'] === ($case['anonymous'] ? [] : [['fixture@example.test', 'logout', '']]), 'audit preserved');
    $expected = [];
    if ($case['cookies']) {
        $p = $case['params'];
        $expected[] = ['PRISM_FIXTURE_SESSION', '', ['expires' => 1799958000, 'path' => $p['path'],
            'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $case['sameSite']]];
    }
    check($r['cookieCalls'] === $expected, 'expired cookie attributes match');
}
echo "$checks logout assertions passed across " . count($cases) . " cases.\n";
