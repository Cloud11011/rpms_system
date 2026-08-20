<?php
/**
 * PRISM - Core configuration
 * Research Planning and Monitoring Section (RPMS), Centro Escolar University - Malolos
 *
 * Connects to MySQL/MariaDB (per the study's System Architecture, Figure 3/6,
 * and matching Hostinger's shared-hosting database offering), provides
 * session + role-based access helpers, and wraps the two external services
 * described in the paper:
 *   - OpenRouter API   -> predefined-query document summarization / report drafting
 *   - Google Email API (Gmail) -> automated status/reminder/follow-up notifications
 *
 * All settings below are safe local defaults. For production on Hostinger,
 * copy this file's overridable section into config.local.php (git-ignored)
 * or set the matching environment variables in hPanel.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,   // only require HTTPS-only cookies when actually served over HTTPS
        'httponly' => true,     // never expose the session cookie to JavaScript
        'samesite' => 'Lax',    // blocks cross-site POST (CSRF) while still allowing normal link navigation
    ]);
    session_start();
}
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL & ~E_DEPRECATED);

// ---------------------------------------------------------------------
// Paths / storage
// ---------------------------------------------------------------------
define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'storage');
define('DOCS_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'documents');
define('REPORTS_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'reports');
define('MAIL_LOG', STORAGE_DIR . DIRECTORY_SEPARATOR . 'mail.log');

foreach ([STORAGE_DIR, DOCS_DIR, REPORTS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// ---------------------------------------------------------------------
// Local/production overrides (git-ignored). Must be loaded before the
// default define() calls below, since PHP's define() is a no-op if the
// constant already exists — loading this first lets config.local.php's
// values win, while anything it doesn't set still falls back to the
// defaults or environment variables further down.
// ---------------------------------------------------------------------
if (is_file(BASE_DIR . '/config.local.php')) {
    require BASE_DIR . '/config.local.php';
}

// ---------------------------------------------------------------------
// Database connection (MySQL / MariaDB - e.g. Hostinger's hosted MySQL)
// ---------------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: 3306);
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'prism');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'prism_user');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'PrismDev123!');

// ---------------------------------------------------------------------
// External service configuration
// Fill these in (or set the corresponding environment variable) to
// enable live AI-assisted summarization/reporting and real email delivery.
// The system remains fully functional without them: it falls back to a
// local extractive summarizer and to logging outgoing mail to storage/mail.log
// so RPMS staff can still test the workflow end-to-end.
// ---------------------------------------------------------------------
if (!defined('OPENROUTER_API_KEY')) {
    define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
}
if (!defined('OPENROUTER_MODEL')) {
    define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'openrouter/auto');
}

// Google Email API (Gmail). Requires a Google Cloud project with the Gmail
// API enabled, an OAuth 2.0 client, and a refresh token authorized with the
// https://www.googleapis.com/auth/gmail.send scope for the sending mailbox.
// See BACKEND_README.md for the one-time setup steps. PRISM sends mail
// through this API when all four values below are configured; otherwise it
// falls back to PHP's mail() and, failing that, logs to storage/mail.log.
if (!defined('GMAIL_CLIENT_ID'))     define('GMAIL_CLIENT_ID', getenv('GMAIL_CLIENT_ID') ?: '');
if (!defined('GMAIL_CLIENT_SECRET')) define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: '');
if (!defined('GMAIL_REFRESH_TOKEN')) define('GMAIL_REFRESH_TOKEN', getenv('GMAIL_REFRESH_TOKEN') ?: '');
if (!defined('GMAIL_SENDER_EMAIL'))  define('GMAIL_SENDER_EMAIL', getenv('GMAIL_SENDER_EMAIL') ?: '');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'CEU-Malolos RPMS / PRISM');

// Gate for RPMS staff self-registration (register.php). Empty by default,
// which DISABLES self-registration entirely — set this in config.local.php
// to a private value and share it only with staff you want to be able to
// create their own admin account. The seeded rpms_admin account (see
// seed() below) is always available to bootstrap the system regardless.
if (!defined('ADMIN_REGISTRATION_CODE')) {
    define('ADMIN_REGISTRATION_CODE', getenv('ADMIN_REGISTRATION_CODE') ?: '');
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    migrate($pdo); // idempotent: safe to run on every request, keeps schema current on upgrades

    $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($hasAdmin === 0) {
        seed($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','adviser','student') NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        email VARCHAR(190) UNIQUE NOT NULL,
        ref_id VARCHAR(100),
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(128) UNIQUE NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS advisers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(100) UNIQUE NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        email VARCHAR(190) UNIQUE NOT NULL,
        department VARCHAR(190),
        assigned_groups VARCHAR(255),
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(100) UNIQUE NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        email VARCHAR(190) UNIQUE NOT NULL,
        research_title VARCHAR(255),
        research_group VARCHAR(190),
        course VARCHAR(100),
        adviser_id INT NULL,
        stage VARCHAR(20) NOT NULL DEFAULT 'Stage 1',
        status VARCHAR(30) NOT NULL DEFAULT 'On Track',
        requirements VARCHAR(255),
        last_submission_date DATE NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (adviser_id) REFERENCES advisers(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ierb_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        stage VARCHAR(20),
        status VARCHAR(30),
        note TEXT,
        requirements VARCHAR(255),
        submission_date DATE NULL,
        actor VARCHAR(190),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id VARCHAR(40) PRIMARY KEY,
        student_id INT NULL,
        student_name VARCHAR(190),
        uploaded_by VARCHAR(190),
        uploaded_by_role VARCHAR(20),
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        mime VARCHAR(120),
        size INT,
        document_type VARCHAR(100),
        stage VARCHAR(20),
        notes TEXT,
        review_status VARCHAR(30) NOT NULL DEFAULT 'Submitted',
        review_remarks TEXT,
        reviewed_by VARCHAR(190),
        reviewed_at DATETIME NULL,
        ai_summary TEXT,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_type VARCHAR(20) NOT NULL,
        recipient_id INT,
        recipient_email VARCHAR(190),
        recipient_name VARCHAR(190),
        subject VARCHAR(255),
        message TEXT NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'Status Update',
        status VARCHAR(20) NOT NULL DEFAULT 'Sent',
        delivery_info TEXT,
        scheduled_at DATETIME NULL,
        sent_at DATETIME NULL,
        read_at DATETIME NULL,
        created_by VARCHAR(190),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_outputs (
        id VARCHAR(40) PRIMARY KEY,
        type VARCHAR(20) NOT NULL,
        source_ref VARCHAR(255),
        prompt TEXT,
        output MEDIUMTEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        created_by VARCHAR(190),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id VARCHAR(40) PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(50) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        generated_by VARCHAR(190),
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(190),
        action VARCHAR(100) NOT NULL,
        details VARCHAR(255),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function seed(PDO $pdo): void
{
    // Default RPMS administrator account so the system is usable immediately
    // after deployment. Change this password on first login.
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, email, ref_id, must_change_password)
        VALUES (:u, :p, 'admin', :n, :e, 'RPMS-0001', 1)");
    $stmt->execute([
        ':u' => 'rpms_admin',
        ':p' => password_hash('ChangeMe123!', PASSWORD_DEFAULT),
        ':n' => 'RPMS Administrator',
        ':e' => 'rpms@ceu.edu.ph',
    ]);
}

// ---------------------------------------------------------------------
// Auth / session helpers
// ---------------------------------------------------------------------
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    $cached = $user ?: null;
    return $cached;
}

/** Redirects to login if not authenticated; optionally restrict by role(s). */
function require_login($roles = null): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }

    // Force a password change before allowing access to anything else if the
    // account still has its auto-generated, predictable temporary password
    // (see student_default_password()/adviser_default_password()).
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $exemptFromForcedChange = ['change_password_required.php', 'logout.php'];
    if (!empty($_SESSION['must_change_password']) && !in_array($currentScript, $exemptFromForcedChange, true)) {
        header('Location: change_password_required.php');
        exit;
    }

    if ($roles !== null) {
        $roles = (array)$roles;
        if (!in_array($user['role'], $roles, true)) {
            header('Location: ' . ($user['role'] === 'admin' ? 'dashboard.php' : ($user['role'] === 'adviser' ? 'ierbprog.php' : 'student.php')));
            exit;
        }
    }
    return $user;
}

/** Same as require_login but for JSON API endpoints: emits 401/403 instead of redirecting. */
function api_require_login($roles = null): array
{
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    if ($roles !== null && !in_array($user['role'], (array)$roles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not authorized to perform this action.']);
        exit;
    }
    return $user;
}

function log_activity(?string $email, string $action, string $details = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO activity_logs (user_email, action, details) VALUES (:e,:a,:d)');
        $stmt->execute([':e' => $email, ':a' => $action, ':d' => $details]);
    } catch (Throwable $e) {
        // never let logging break the request
    }
}

/**
 * Basic login throttling: blocks further attempts for a given username once
 * too many failures have happened recently. Not a substitute for a proper
 * WAF/CAPTCHA on a public deployment, but stops trivial unlimited brute
 * forcing of a single account.
 */
function too_many_recent_failures(string $username, int $maxAttempts = 8, int $windowMinutes = 15): bool
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM activity_logs
            WHERE action = 'login_failed' AND user_email = :u
            AND created_at > (NOW() - INTERVAL :mins MINUTE)");
        $stmt->bindValue(':u', $username, PDO::PARAM_STR);
        $stmt->bindValue(':mins', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn() >= $maxAttempts;
    } catch (Throwable $e) {
        return false; // never let throttle-check failures lock everyone out
    }
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

// ---------------------------------------------------------------------
// AI and Integration Layer (Figure 3) - OpenRouter API
// PRISM sends only predefined queries/templates, never free-form user
// prompting, per the study's Scope and Delimitations.
// ---------------------------------------------------------------------
function openrouter_available(): bool
{
    return OPENROUTER_API_KEY !== '';
}

/** Appends a line to storage/api_errors.log so failed OpenRouter/Gmail calls are debuggable. */
function log_api_error(string $service, string $message): void
{
    $entry = sprintf("[%s] %s: %s\n", date(DATE_ATOM), $service, $message);
    @file_put_contents(STORAGE_DIR . DIRECTORY_SEPARATOR . 'api_errors.log', $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Calls the OpenRouter chat completions endpoint with a predefined system
 * query template. Returns the generated text, or null on failure/unavailable
 * so callers can fall back to local processing.
 */
function openrouter_generate(string $systemPrompt, string $userContent): ?string
{
    if (!openrouter_available()) {
        return null;
    }
    $payload = json_encode([
        'model' => OPENROUTER_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => mb_substr($userContent, 0, 12000)],
        ],
        'temperature' => 0.3,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://prism.ceu-malolos.local',
            'X-Title: PRISM RPMS',
        ],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        log_api_error('openrouter', $curlError !== '' ? $curlError : "HTTP $status: " . substr((string)$response, 0, 500));
        return null;
    }
    $decoded = json_decode($response, true);
    return $decoded['choices'][0]['message']['content'] ?? null;
}

/** Local extractive fallback used when OpenRouter is not configured/unreachable. */
function local_extractive_summary(string $text, int $sentences = 5): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $summary = implode(' ', array_slice($parts, 0, $sentences));
    return mb_strlen($summary) > 1200 ? mb_substr($summary, 0, 1197) . '...' : $summary;
}

// ---------------------------------------------------------------------
// Automated Email Communication - Google Email API (Gmail)
// ---------------------------------------------------------------------
function gmail_api_available(): bool
{
    return GMAIL_CLIENT_ID !== '' && GMAIL_CLIENT_SECRET !== '' && GMAIL_REFRESH_TOKEN !== '' && GMAIL_SENDER_EMAIL !== '';
}

/** Exchanges the stored refresh token for a short-lived Gmail API access token. */
function gmail_access_token(): ?string
{
    static $cachedToken = null;
    static $cachedExpiry = 0;
    if ($cachedToken && time() < $cachedExpiry) {
        return $cachedToken;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'refresh_token' => GMAIL_REFRESH_TOKEN,
            'grant_type' => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        log_api_error('gmail_token', $curlError !== '' ? $curlError : "HTTP $status: " . substr((string)$response, 0, 500));
        return null;
    }
    $decoded = json_decode($response, true);
    if (empty($decoded['access_token'])) {
        log_api_error('gmail_token', 'No access_token in response: ' . substr((string)$response, 0, 500));
        return null;
    }
    $cachedToken = $decoded['access_token'];
    $cachedExpiry = time() + (int)($decoded['expires_in'] ?? 3000) - 60;
    return $cachedToken;
}

/** Sends a message through the Gmail API (users.messages.send) using an OAuth 2.0 access token. */
function gmail_api_send(string $to, string $subject, string $body): bool
{
    $accessToken = gmail_access_token();
    if (!$accessToken) {
        return false;
    }

    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . GMAIL_SENDER_EMAIL . '>',
        'To: ' . $to,
        'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['raw' => $encoded]),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $ok = $response !== false && $status < 300;
    if (!$ok) {
        log_api_error('gmail_send', $curlError !== '' ? $curlError : "HTTP $status: " . substr((string)$response, 0, 500));
    }
    return $ok;
}

/**
 * Sends an email via the Gmail API when credentials are configured;
 * otherwise falls back to the server's mail() transport, and finally to
 * logging the message to storage/mail.log so the notification workflow can
 * still be demonstrated/tested end-to-end without any live credentials.
 */
function send_notification_email(string $to, string $subject, string $body): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid recipient email is required.'];
    }

    if (gmail_api_available()) {
        if (gmail_api_send($to, $subject, $body)) {
            return ['ok' => true, 'message' => 'Email delivered via the Gmail API.', 'channel' => 'gmail_api'];
        }
        // fall through to the local fallbacks below so the attempt is not silently lost
    }

    $headers = "From: " . MAIL_FROM_NAME . " <" . (GMAIL_SENDER_EMAIL ?: 'rpms@ceu.edu.ph') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $delivered = @mail($to, $subject, $body, $headers);
    if ($delivered) {
        return ['ok' => true, 'message' => 'Email delivered via server mail transport.', 'channel' => 'mail'];
    }

    // No live mail transport configured/reachable in this environment: log it instead of failing the workflow.
    $entry = sprintf(
        "[%s] TO:%s SUBJECT:%s\n%s\n%s\n",
        date(DATE_ATOM),
        $to,
        $subject,
        $body,
        str_repeat('-', 60)
    );
    @file_put_contents(MAIL_LOG, $entry, FILE_APPEND | LOCK_EX);
    return ['ok' => true, 'message' => 'No live mail transport configured; the message was logged to storage/mail.log for review.', 'channel' => 'log'];
}
