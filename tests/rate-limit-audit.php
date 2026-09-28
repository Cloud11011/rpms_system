<?php
/** CLI-only file-lock tests. Uses a private temporary directory, never application configuration. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$logs = [];
function log_api_error(...$args): void { $GLOBALS['logs'][] = $args; }
require __DIR__ . '/../auth_rate_limit.php';

if (($argv[1] ?? '') === '--attempt') {
    define('STORAGE_DIR', $argv[2]);
    $deadline = microtime(true) + 10;
    while (!is_file(STORAGE_DIR . '/start')) {
        if (microtime(true) > $deadline) exit(2);
        usleep(10000);
    }
    echo consume_auth_attempt('parallel', 'same-subject', 7, 900) ? '1' : '0';
    exit;
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prism-rate-audit-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create private test directory.');
define('STORAGE_DIR', $root);
$checks = 0;
function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
}
function counter_path(string $scope, string $subject): string
{ return STORAGE_DIR . '/auth_rate_limits/' . hash('sha256', strlen($scope) . ':' . $scope . $subject) . '.json'; }
$children = [];
try {
    check(consume_auth_attempt('reset', 'fixture@example.test', 2, 900), 'first attempt admitted');
    check(consume_auth_attempt('reset', 'fixture@example.test', 2, 900), 'second attempt admitted');
    check(!consume_auth_attempt('reset', 'fixture@example.test', 2, 900), 'quota exhausted');
    check(!$logs, 'normal quota rejection does not log an error');
    check(consume_auth_attempt('registration', 'fixture@example.test', 2, 900), 'scope isolation');
    check(consume_auth_attempt('reset', 'different@example.test', 2, 900), 'subject isolation');
    $path = counter_path('reset', 'fixture@example.test');
    $raw = file_get_contents($path);
    check(!str_contains($raw, 'fixture@example.test') && strlen($raw) <= 256, 'bounded counter contains no raw subject');
    check(str_contains(file_get_contents(STORAGE_DIR . '/auth_rate_limits/.htaccess'), 'Require all denied'), 'counter directory denies Apache access');
    file_put_contents($path, json_encode(['startedAt' => time() - 901, 'attempts' => 2]));
    check(consume_auth_attempt('reset', 'fixture@example.test', 2, 900), 'expired window admits new attempt');
    check(json_decode(file_get_contents($path), true)['attempts'] === 1, 'expired window counter resets');
    foreach (['', 'not json', str_repeat('x', 257), '{"startedAt":0,"attempts":-1}',
        json_encode(['startedAt' => time() + 3600, 'attempts' => 0])] as $corrupt) {
        file_put_contents($path, $corrupt);
        $before = count($logs);
        check(!consume_auth_attempt('reset', 'fixture@example.test', 2, 900), 'corrupt state fails closed');
        check(count($logs) === $before + 1 && $logs[$before] === [
            'auth_rate_limit', 'Authentication attempt limiting is unavailable; the request was blocked.',
        ], 'failure logs only fixed diagnostic');
    }
    check(!consume_auth_attempt('', 'subject', 2, 900), 'invalid scope fails closed');
    check(!consume_auth_attempt('reset', 'subject', 0, 900), 'invalid quota fails closed');
    // Simulate an unavailable counter path without platform-specific permission assumptions.
    $blocked = counter_path('blocked', 'subject');
    mkdir($blocked);
    check(!consume_auth_attempt('blocked', 'subject', 2, 900), 'unopenable counter fails closed');
    rmdir($blocked);
    for ($i = 0; $i < 20; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--attempt', STORAGE_DIR],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrent fixture.');
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    file_put_contents(STORAGE_DIR . '/start', 'go');
    $admitted = 0;
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        check($exit === 0 && $error === '' && in_array($output, ['0', '1'], true), 'concurrent attempt executes cleanly');
        $admitted += (int)$output;
    }
    $children = [];
    check($admitted === 7, 'twenty concurrent processes admit exactly seven attempts');
    check(json_decode(file_get_contents(counter_path('parallel', 'same-subject')), true)['attempts'] === 7, 'persisted quota matches admissions');
    echo "$checks rate-limit assertions passed; temporary files only.\n";
} finally {
    foreach ($children as [$process, $pipes]) {
        if (is_resource($process)) proc_terminate($process);
        foreach ([1, 2] as $index) if (is_resource($pipes[$index])) fclose($pipes[$index]);
        if (is_resource($process)) proc_close($process);
    }
    $resolved = realpath($root);
    if ($resolved === false || dirname($resolved) !== realpath(sys_get_temp_dir())
        || !str_starts_with(basename($resolved), 'prism-rate-audit-')) throw new RuntimeException('Unsafe cleanup root.');
    $remove = function (string $directory) use (&$remove, $resolved): void {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) continue;
            $path = $entry->getPathname(); $actual = realpath($path);
            if ($entry->isLink() || $actual === false || !str_starts_with($actual, $resolved . DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe fixture cleanup target.');
            if ($entry->isDir()) $remove($path); else unlink($path);
        }
        rmdir($directory);
    };
    $remove($resolved);
}
