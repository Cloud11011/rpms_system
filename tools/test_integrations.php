<?php
/**
 * Checks the OpenRouter and Gmail settings without touching any student data.
 * Local machine only. Usage (browser):  http://localhost/rpms_system/tools/test_integrations.php?to=you@example.com
 * Usage (terminal):                     php tools/test_integrations.php you@example.com
 * Do NOT upload the tools/ folder to your live server.
 */
require __DIR__ . '/../config.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local machine only.');
}
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}
$to = $cli ? ($argv[1] ?? '') : (string)($_GET['to'] ?? '');

function line(string $s): void { echo $s . "\n"; }
function yn(bool $b): string { return $b ? 'OK     ' : 'MISSING'; }

line('=== Settings ===');
line(yn(extension_loaded('curl')) . ' PHP curl extension');
line(yn(OPENROUTER_API_KEY !== '') . ' OPENROUTER_API_KEY   (model: ' . OPENROUTER_MODEL . ')');
foreach (['GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET', 'GMAIL_REFRESH_TOKEN', 'GMAIL_SENDER_EMAIL'] as $k) {
    line(yn(constant($k) !== '') . ' ' . $k);
}

$logFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'api_errors.log';
$logBefore = is_file($logFile) ? filesize($logFile) : 0;

line("\n=== OpenRouter ===");
if (!openrouter_available()) {
    line('Skipped: no API key set. (PRISM falls back to its built-in local summarizer.)');
} else {
    $t = microtime(true);
    $out = openrouter_generate('You are a connectivity test. Reply with exactly: PRISM OK', 'ping');
    line($out !== null ? 'OK: got a reply in ' . round(microtime(true) - $t, 1) . 's -> ' . trim(substr($out, 0, 80))
                       : 'FAILED: see the error log below.');
}

line("\n=== Gmail ===");
if (!gmail_api_available()) {
    line('Skipped: one or more Gmail settings are missing.');
} elseif ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    line('Settings present. Add a recipient to send a real test, e.g. ?to=you@example.com');
} else {
    $ok = gmail_api_send($to, 'PRISM test email', "If you can read this, PRISM can send email through the Gmail API.\n\nSent " . date('c'));
    line($ok ? "OK: test email sent to $to (check the inbox and spam folder)." : 'FAILED: see the error log below.');
}

line("\n=== New entries in storage/api_errors.log ===");
clearstatcache();
$after = is_file($logFile) ? filesize($logFile) : 0;
if ($after > $logBefore) {
    $fh = fopen($logFile, 'rb'); fseek($fh, $logBefore); echo stream_get_contents($fh); fclose($fh);
} else {
    line('(none)');
}
