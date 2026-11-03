<?php
/**
 * TEST ONLY auto_prepend_file for the copied disposable app: loopback fixture database, simulated Gmail API
 * (curl) and simulated PHP mail(). Run PHP with -d disable_functions=<PRISM_SIMULATED_FUNCTIONS> so these
 * definitions replace the real ones; nothing here can reach a network or a mail server.
 * PRISM_TEST_PROVIDER_PLAN names a JSON plan: {"token":"ok|fail","gmail":"<scenario>","byRecipient":{...},"mail":"accept|refuse"}.
 * Every provider decision is appended to PRISM_TEST_PROVIDER_LEDGER as one JSON line.
 */
if (PHP_SAPI !== 'cli') exit;
const PRISM_SIMULATED_FUNCTIONS = ['mail','curl_init','curl_setopt_array','curl_exec','curl_getinfo','curl_error','curl_errno','curl_close'];
foreach (PRISM_SIMULATED_FUNCTIONS as $real) {
    if (function_exists($real)) { fwrite(STDERR, "Real $real() is still enabled; refusing to run.\n"); exit(70); }
}
define('PRISM_TEST_RUNTIME', true);
require __DIR__.'/../ierb-group-tests/environment.php';
define('DB_HOST', '127.0.0.1');
define('DB_PORT', PRISM_TEST_DB_PORT);
define('DB_NAME', 'prism_group_synthetic_v11');
define('DB_USER', 'root');
define('DB_PASS', 'prism_synthetic_only');
define('APP_ENV', 'development');
define('APP_BASE_URL', 'http://127.0.0.1:'.PRISM_TEST_APP_PORT);
define('ALLOWED_EMAIL_DOMAINS', ['example.test']);
define('OPENROUTER_API_KEY', 'synthetic-key-only');
$prismGmail = getenv('PRISM_TEST_GMAIL') !== 'off';
define('GMAIL_CLIENT_ID', $prismGmail ? 'synthetic-client' : '');
define('GMAIL_CLIENT_SECRET', $prismGmail ? 'synthetic-secret' : '');
define('GMAIL_REFRESH_TOKEN', $prismGmail ? 'synthetic-refresh' : '');
define('GMAIL_SENDER_EMAIL', $prismGmail ? 'rpms@example.test' : '');
unset($prismGmail);
if (getenv('PRISM_TEST_MAIL_FALLBACK') === 'true') define('PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED', true);
if (getenv('PRISM_TEST_WORKER_ENABLED') !== false) define('PRISM_NOTIFICATION_WORKER_ENABLED', getenv('PRISM_TEST_WORKER_ENABLED') === 'true');
if (getenv('PRISM_TEST_WORKER_FENCE') !== false) define('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID', (int)getenv('PRISM_TEST_WORKER_FENCE'));

function prism_sim_plan(): array
{
    $file = getenv('PRISM_TEST_PROVIDER_PLAN');
    return $file && is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
}

function prism_sim_ledger(string $provider, string $to, bool $accepted, string $scenario): void
{
    $file = getenv('PRISM_TEST_PROVIDER_LEDGER');
    if ($file) file_put_contents($file, json_encode(compact('provider', 'to', 'accepted', 'scenario'))."\n", FILE_APPEND | LOCK_EX);
}

// Declared conditionally: bound only at run time, after the guard above, and never a redeclaration under php -l.
if (PHP_SAPI === 'cli') {
    final class PrismSimulatedCurl { public string $url = ''; public array $options = []; public int $status = 0; public int $errno = 0; }
    function curl_init(?string $url = null): PrismSimulatedCurl { $h = new PrismSimulatedCurl(); $h->url = (string)$url; return $h; }
    function curl_setopt_array($handle, array $options): bool { $handle->options = $options; return true; }
    function curl_getinfo($handle, ?int $option = null) { return $option === CURLINFO_HTTP_CODE ? $handle->status : ['http_code' => $handle->status]; }
    function curl_error($handle): string { return $handle->errno ? 'Synthetic transport error '.$handle->errno : ''; }
    function curl_errno($handle): int { return $handle->errno; }
    function curl_close($handle): void {}
    function curl_exec($handle)
    {
        $plan = prism_sim_plan();
        if ($handle->url === 'https://oauth2.googleapis.com/token') {
            if (($plan['token'] ?? 'ok') !== 'ok') { $handle->errno = 7; return false; }
            $handle->status = 200;
            return json_encode(['access_token' => 'synthetic-access-token', 'expires_in' => 3600]);
        }
        if ($handle->url !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') { $handle->errno = 7; return false; }
        $raw = json_decode((string)($handle->options[CURLOPT_POSTFIELDS] ?? '{}'), true)['raw'] ?? '';
        $to = preg_match('/^To: (.+)$/mi', (string)base64_decode(strtr($raw, '-_', '+/')), $m) ? trim($m[1]) : '';
        $scenario = $plan['byRecipient'][$to] ?? $plan['gmail'] ?? 'success';
        // [accepted by Gmail, HTTP status, curl errno, response body]
        [$accepted, $status, $errno, $body] = match ($scenario) {
            'success' => [true, 200, 0, '{"id":"synthetic"}'],
            'reject' => [false, 400, 0, '{"error":{"code":400}}'],
            'rate_limited' => [false, 429, 0, '{"error":{"code":429}}'],
            'timeout_after_accept' => [true, 0, 28, false],
            'server_error_after_accept' => [true, 503, 0, '{"error":{"code":503}}'],
            'connect_fail' => [false, 0, 7, false],
            default => throw new InvalidArgumentException('Unknown simulated Gmail scenario.'),
        };
        prism_sim_ledger('gmail', $to, $accepted, $scenario);
        $handle->status = $status; $handle->errno = $errno;
        return $body;
    }
    function mail(string $to, string $subject, string $message, array|string $headers = [], string $params = ''): bool
    {
        $accepted = (prism_sim_plan()['mail'] ?? 'refuse') === 'accept';
        prism_sim_ledger('mail', $to, $accepted, 'mail');
        return $accepted;
    }
}
