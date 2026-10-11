<?php
/** Security helpers only; no configuration, database or service bootstrap. */

/** A public, non-authorizing marker; the HttpOnly PHP cookie remains the identity authority. */
function prism_session_generation(): string
{
    if (empty($_SESSION['user_id'])) return '';
    if (!is_string($_SESSION['login_generation'] ?? null)) {
        $_SESSION['login_generation'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['login_generation'];
}

function prism_publish_session_generation(): void
{
    setcookie('prism_generation', prism_session_generation(), [
        'expires' => 0, 'path' => '/', 'secure' => request_uses_https(),
        'httponly' => false, 'samesite' => 'Lax',
    ]);
}

/** Supplements, and never replaces, same-origin, authentication and ownership checks. */
function require_session_generation(bool $required = false): void
{
    if (empty($_SESSION['user_id'])) return;
    $marker = $_SERVER['HTTP_X_PRISM_GENERATION'] ?? $_POST['prism_generation'] ?? null;
    if ($marker === null && !$required) return; // Older clients retain their existing request guards.
    if (!is_string($marker) || !hash_equals(prism_session_generation(), $marker)) {
        http_response_code(409);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'code' => 'session_changed',
            'message' => 'Your PRISM session changed in another tab. Reload this page to continue.']);
        exit;
    }
}

function request_uses_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function normalized_http_origin(string $url): ?string
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return null;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
    if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/i', $host)) return null;
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . $host . ':' . $port;
}

function is_loopback_development_request(): bool
{
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) return false;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $host);
}

function app_base_url_is_valid(): bool
{
    $url = (string)APP_BASE_URL;
    if (normalized_http_origin($url) === null || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (isset($parts['query']) || isset($parts['fragment'])) return false;
    if (APP_ENV !== 'development' && strtolower($parts['scheme']) !== 'https') return false;
    if (APP_ENV !== 'development' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true)) return false;
    return true;
}

function development_password_response_allowed(): bool
{
    return APP_ENV === 'development' && ALLOW_DEVELOPMENT_PASSWORD_RESPONSE === true
        && is_loopback_development_request();
}

/** Preserve provisioning result keys while making credential disclosure an explicit local opt-in. */
function account_setup_response_fields(?array $delivery, ?string $temporaryPassword): array
{
    $channel = (string)($delivery['channel'] ?? 'none');
    $pending = empty($delivery['ok']) || !in_array($channel, ['gmail_api', 'mail'], true);
    $fields = ['setupChannel' => $channel, 'setupPending' => $pending,
        'setupMessage' => $pending
            ? 'The account was created, but its password setup email could not be delivered. Contact the RPMS office to arrange access.'
            : 'A password setup email has been sent.'];
    if ($pending && $temporaryPassword !== null && development_password_response_allowed()) {
        $fields['temporaryPassword'] = $temporaryPassword;
    }
    return $fields;
}

function application_security_headers(): array
{
    // No script/style restrictions are added here: existing inline code and external assets remain usable.
    $headers = [
        // Bootstrap defaults; streaming/export endpoints may replace these later.
        'Cache-Control: private, no-store, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
        'X-Frame-Options: SAMEORIGIN',
        'Referrer-Policy: strict-origin-when-cross-origin',
        "Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'",
    ];
    if (defined('APP_ENV') && APP_ENV === 'production' && request_uses_https()
        && defined('APP_BASE_URL') && app_base_url_is_valid() && !is_loopback_development_request()) {
        $headers[] = 'Strict-Transport-Security: max-age=31536000';
    }
    return $headers;
}

/** Shared policy for newly chosen passwords; existing credentials are untouched. */
function new_password_is_valid(string $password): bool
{
    return mb_strlen($password, 'UTF-8') >= 12 && strlen($password) <= 200;
}

/** A prefix distinguishes new digests from expiring legacy plaintext records. */
function reset_token_parameters(string $token): array
{
    return [':hashed' => 'sha256:' . hash('sha256', $token),
        // Legacy issuers generated exactly 64 lowercase hex characters. Never let
        // a submitted prefixed digest match the legacy branch (digest replay).
        ':legacy' => preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : ''];
}

function handle_unhandled_application_error(Throwable $error): never
{
    // Do not call db()/log_activity(), or include exception text, SQL, credentials or traces.
    error_log('PRISM: unhandled application failure (' . get_class($error) . ').');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "PRISM could not complete the operation. Check the server log.\n");
        exit(1);
    }
    http_response_code($error instanceof PDOException ? 503 : 500);
    header('Cache-Control: no-store');
    $json = str_ends_with(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), '_api.php')
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo '{"ok":false,"message":"The service is temporarily unavailable. Please try again later."}';
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>PRISM unavailable</title>'
            . '<h1>PRISM is temporarily unavailable</h1><p>Please try again later.</p></html>';
    }
    exit;
}

function install_application_security(): void
{
    set_exception_handler('handle_unhandled_application_error');
    if (PHP_SAPI === 'cli') return;
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    foreach (application_security_headers() as $header) header($header);
}

/** Operator diagnostics without displaying configured URLs, credentials or other values. */
function application_configuration_issues(): array
{
    $issues = [];
    if (!in_array(APP_ENV, ['production', 'development'], true)) $issues[] = 'APP_ENV must be production or development.';
    if (!app_base_url_is_valid()) $issues[] = 'APP_BASE_URL must be a valid canonical URL without credentials, query or fragment; production requires public HTTPS.';
    if (APP_ENV !== 'development' && ALLOW_DEVELOPMENT_PASSWORD_RESPONSE) {
        $issues[] = 'Development password disclosure is requested outside development and is disabled.';
    }
    return $issues;
}
