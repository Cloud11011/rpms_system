<?php
/**
 * PRISM - Core configuration
 * Research Planning and Monitoring Section (RPMS), Centro Escolar University - Malolos
 *
 * Connects to MySQL/MariaDB (per the study's System Architecture, Figure 3/6,
 * and matching Hostinger's shared-hosting database offering), provides
 * session + role-based access helpers, and wraps the two external services
 * described in the paper:
 *   - OpenRouter API  -> predefined-query document summarization / report drafting
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
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // only require HTTPS-only cookies when actually served over HTTPS
        'httponly' => true,       // never expose the session cookie to JavaScript
        'samesite' => 'Lax',      // blocks cross-site POST (CSRF) while still allowing normal link navigation
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
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

// Canonical public URL used for security-sensitive absolute links (for example password resets).
// Never derive these links from the request Host header.
if (!defined('APP_BASE_URL')) define('APP_BASE_URL', rtrim(getenv('APP_BASE_URL') ?: '', '/'));

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
if (!defined('GMAIL_CLIENT_ID')) define('GMAIL_CLIENT_ID', getenv('GMAIL_CLIENT_ID') ?: '');
if (!defined('GMAIL_CLIENT_SECRET')) define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: '');
if (!defined('GMAIL_REFRESH_TOKEN')) define('GMAIL_REFRESH_TOKEN', getenv('GMAIL_REFRESH_TOKEN') ?: '');
if (!defined('GMAIL_SENDER_EMAIL')) define('GMAIL_SENDER_EMAIL', getenv('GMAIL_SENDER_EMAIL') ?: '');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'CEU-Malolos RPMS / PRISM');

// Gate for RPMS staff self-registration (register.php). Empty by default,
// which DISABLES self-registration entirely — set this in config.local.php
// to a private value and share it only with staff you want to be able to
// create their own admin account. The seeded rpms_admin account (see
// seed() below) is always available to bootstrap the system regardless.
if (!defined('ADMIN_REGISTRATION_CODE')) {
    define('ADMIN_REGISTRATION_CODE', getenv('ADMIN_REGISTRATION_CODE') ?: '');
}

// Only accounts with an email ending in one of these domains may be
// created or log in (feature request: email domain restriction). Adjust
// these to the real CEU Malolos and MLS domains used by your office --
// these are reasonable guesses and should be verified before go-live.
if (!defined('ALLOWED_EMAIL_DOMAINS')) {
    $envDomains = getenv('ALLOWED_EMAIL_DOMAINS');
    define('ALLOWED_EMAIL_DOMAINS', $envDomains ? array_map('trim', explode(',', $envDomains)) : [
        'ceu.edu.ph',
        'mls.edu.ph',
    ]);
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
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);

    migrate($pdo); // idempotent: safe to run on every request, keeps schema current on upgrades

    $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($hasAdmin === 0) {
        seed($pdo);
    }

    return $pdo;
}

// Bump this whenever you add anything to migrate(). migrate() is skipped entirely on requests
// where the stored version already matches, instead of running ~45 INFORMATION_SCHEMA/ALTER
// checks on every single request.
const SCHEMA_VERSION = 4;

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (
        k VARCHAR(40) PRIMARY KEY, v VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $stored = $pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'")->fetchColumn();
    if ($stored !== false && (int)$stored >= SCHEMA_VERSION) {
        return;
    }
    // Two first requests arriving together must not both try to ALTER the same table.
    $locked = (int)$pdo->query("SELECT GET_LOCK('prism_migrate', 30)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Could not obtain the PRISM schema migration lock.');
    }
    try {
        $stored = $pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'")->fetchColumn();
        if ($stored === false || (int)$stored < SCHEMA_VERSION) {
            migrate_schema($pdo);
            $pdo->prepare("REPLACE INTO schema_meta (k, v) VALUES ('schema_version', :v)")
                ->execute([':v' => (string)SCHEMA_VERSION]);
        }
    } finally {
        if ($locked) {
            $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
        }
    }
}

function migrate_schema(PDO $pdo): void
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

    // ---- Feature-request additions (kept separate from the original
    // CREATE TABLE statements above so this file's history stays clear;
    // add_column_if_missing() is safe to re-run on an already-upgraded
    // database, same as everything else in migrate()). ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS stage_labels (
        stage_key VARCHAR(20) PRIMARY KEY,
        label VARCHAR(190) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Protocol code + Principal Investigator indicator (feature requests 10-11)
    add_column_if_missing($pdo, 'students', 'protocol_code', "VARCHAR(100) NULL AFTER requirements");
    add_column_if_missing($pdo, 'students', 'is_principal_investigator', "TINYINT(1) NOT NULL DEFAULT 0 AFTER protocol_code");

    // AI-detected approval date, separate from uploaded_at/reviewed_at
    // (feature request 3 -- the date printed on the actual IERB approval
    // document, which can lag behind when the student got around to
    // uploading it).
    add_column_if_missing($pdo, 'documents', 'detected_approval_date', "DATE NULL AFTER reviewed_at");
    add_column_if_missing($pdo, 'documents', 'approval_date_source', "VARCHAR(20) NULL COMMENT 'ai or regex or manual' AFTER detected_approval_date");

    // ---- PRISM v2: workflow clarity, version history, audit trail --------------------------
    $columnExists = function (string $table, string $column) use ($pdo): bool {
        $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $q->execute([':t' => $table, ':c' => $column]);
        return (int)$q->fetchColumn() > 0;
    };

    // Version history: a re-upload for the same student + stage + document type becomes a new version.
    add_column_if_missing($pdo, 'documents', 'version_no', "INT NOT NULL DEFAULT 1 AFTER stage");
    add_column_if_missing($pdo, 'documents', 'is_current', "TINYINT(1) NOT NULL DEFAULT 1 AFTER version_no");
    add_column_if_missing($pdo, 'documents', 'supersedes_id', "VARCHAR(40) NULL AFTER is_current");

    // Formal RPMS submission + Admin Override marker on documents.
    $hadRpmsColumn = $columnExists('documents', 'rpms_submitted_at');
    add_column_if_missing($pdo, 'documents', 'rpms_submitted_at', "DATETIME NULL");
    add_column_if_missing($pdo, 'documents', 'rpms_submitted_by', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'documents', 'admin_override', "TINYINT(1) NOT NULL DEFAULT 0");
    add_column_if_missing($pdo, 'documents', 'override_reason', "TEXT NULL");
    add_column_if_missing($pdo, 'documents', 'override_by', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'documents', 'override_at', "DATETIME NULL");
    if (!$hadRpmsColumn) {
        // One-time: documents approved BEFORE this step existed were already treated as done (the stage
        // advanced on approval), so mark them submitted. Otherwise every old approval would suddenly
        // show "Ready for Formal RPMS Submission".
        $pdo->exec("UPDATE documents
            SET rpms_submitted_at = COALESCE(reviewed_at, uploaded_at),
                rpms_submitted_by = 'Legacy record (approved before the formal submission step existed)'
            WHERE review_status = 'Approved' AND rpms_submitted_at IS NULL");
    }

    // Audit trail: extend the existing activity_logs table (old rows and log_activity() keep working).
    add_column_if_missing($pdo, 'activity_logs', 'actor_name', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'actor_role', "VARCHAR(20) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'entity_type', "VARCHAR(40) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'entity_id', "VARCHAR(64) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'student_id', "INT NULL");
    add_column_if_missing($pdo, 'activity_logs', 'reason', "TEXT NULL");
    add_column_if_missing($pdo, 'activity_logs', 'before_value', "VARCHAR(255) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'after_value', "VARCHAR(255) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'is_override', "TINYINT(1) NOT NULL DEFAULT 0");

    // Reports are authorized by immutable account id rather than display name.
    add_column_if_missing($pdo, 'reports', 'generated_by_user_id', "INT NULL AFTER generated_by");

    // Seed default stage labels (feature request 7). Safe to re-run --
    // only inserts rows that don't already exist, so an admin's own edits
    // via stage_labels_api.php are never overwritten by this.
    $defaultLabels = [
        'Stage 1'   => 'Protocol Submission',
        'Stage 2'   => 'Initial Ethics Review',
        'Stage 3'   => 'Revisions & Resubmission',
        'Stage 4'   => 'Certificate of Approval',
        'Stage 5'   => 'Continuing Review / Monitoring',
        'Completed' => 'IERB Process Completed',
    ];
    $insertLabel = $pdo->prepare('INSERT IGNORE INTO stage_labels (stage_key, label) VALUES (:k, :l)');
    foreach ($defaultLabels as $key => $label) {
        $insertLabel->execute([':k' => $key, ':l' => $label]);
    }
}

/**
 * Adds a column to an existing table only if it doesn't already exist.
 * MySQL/MariaDB don't reliably support "ADD COLUMN IF NOT EXISTS" across
 * the versions this app might run on, so this checks INFORMATION_SCHEMA
 * first -- safe to call on every request, same as the rest of migrate().
 */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $check->execute([':t' => $table, ':c' => $column]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function seed(PDO $pdo): void
{
    // Bootstrap credentials must be deployment-specific. Never ship a known administrator password.
    $bootstrapPassword = (string)(getenv('PRISM_INITIAL_ADMIN_PASSWORD') ?: '');
    $bootstrapEmail = strtolower(trim((string)(getenv('PRISM_INITIAL_ADMIN_EMAIL') ?: 'rpms@ceu.edu.ph')));

    if (strlen($bootstrapPassword) < 12) {
        throw new RuntimeException(
            'No RPMS administrator exists. Set PRISM_INITIAL_ADMIN_PASSWORD to a unique 12+ character value, then reload once to bootstrap the administrator account.'
        );
    }
    if (!filter_var($bootstrapEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('PRISM_INITIAL_ADMIN_EMAIL must be a valid email address.');
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, email, ref_id, must_change_password)
        VALUES (:u, :p, 'admin', :n, :e, 'RPMS-0001', 1)");
    $stmt->execute([
        ':u' => 'rpms_admin',
        ':p' => password_hash($bootstrapPassword, PASSWORD_DEFAULT),
        ':n' => 'RPMS Administrator',
        ':e' => $bootstrapEmail,
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
    if ($user && strcasecmp((string)$user['status'], 'Active') !== 0) {
        // Deactivated after logging in: end the session instead of letting it keep working.
        unset($_SESSION['user_id']);
        $user = false;
    }
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
    // Same rule as require_login(): an account still on its temporary password can do nothing except change it.
    if (!empty($_SESSION['must_change_password'])) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $exempt = $script === 'profile_api.php' && in_array($_GET['action'] ?? '', ['change_password', 'me'], true);
        if (!$exempt) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'code' => 'password_change_required',
                'message' => 'Please change your temporary password before continuing.']);
            exit;
        }
    }
    if ($roles !== null && !in_array($user['role'], (array)$roles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not authorized to perform this action.']);
        exit;
    }
    return $user;
}

/**
 * CSRF guard for state-changing API actions: POST only, and if the browser sent an Origin
 * (or Referer) header it must be this site. SameSite=Lax alone does not stop a link click
 * from firing a GET like ?action=delete&id=5 with the victim's cookies.
 */
function require_post_same_origin(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'message' => 'This action must be sent as a POST request.']);
        exit;
    }
    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($source !== '') {
        $sourceHost = parse_url($source, PHP_URL_HOST);
        $sourcePort = parse_url($source, PHP_URL_PORT);
        $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
        $sourceAuth = $sourceHost . ($sourcePort ? ':' . $sourcePort : '');
        if ($sourceHost === null || strcasecmp($sourceAuth, $hostHeader) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Cross-site request blocked.']);
            exit;
        }
    }
}

/**
 * Students are linked to their login by email (students.user_id is not populated). When RPMS
 * changes a student's email, the login must follow, or the student instantly loses their record
 * and documents.
 */
function sync_student_login_email(PDO $pdo, string $oldEmail, string $newEmail): void
{
    if ($oldEmail !== '' && strcasecmp($oldEmail, $newEmail) !== 0) {
        $pdo->prepare("UPDATE users SET email = :new WHERE email = :old AND role = 'student'")
            ->execute([':new' => $newEmail, ':old' => $oldEmail]);
    }
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

function generate_temporary_password(int $bytes = 12): string
{
    // URL-safe random credential with mixed character classes. The user is still forced to replace it at first login.
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=') . '!Aa1';
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
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---------------------------------------------------------------------
// Email domain restriction (feature request: only @ceu / @mls addresses
// may hold an account). See ALLOWED_EMAIL_DOMAINS above to adjust.
// ---------------------------------------------------------------------
function is_allowed_email_domain(string $email): bool
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $domain = strtolower(substr($email, $at + 1));
    foreach (ALLOWED_EMAIL_DOMAINS as $allowed) {
        $allowed = strtolower(trim($allowed));
        if ($allowed !== '' && ($domain === $allowed || str_ends_with($domain, '.' . $allowed))) {
            return true;
        }
    }
    return false;
}

function allowed_email_domains_hint(): string
{
    return implode(', ', array_map(fn($d) => '@' . $d, ALLOWED_EMAIL_DOMAINS));
}

// ---------------------------------------------------------------------
// IERB stage sequence, labels, and progress percentages -- centralized
// here so students_api.php, ierb_api.php, documents_api.php, and
// reports_api.php all agree on the same order/labels instead of each
// keeping (and risking drifting) their own copy.
// ---------------------------------------------------------------------
const STAGE_SEQUENCE = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];

function stage_progress_percent(string $stage): int
{
    $map = ['Stage 1' => 20, 'Stage 2' => 40, 'Stage 3' => 60, 'Stage 4' => 80, 'Stage 5' => 95, 'Completed' => 100];
    return $map[$stage] ?? 0;
}

/** Returns the next stage in sequence, or the same stage if it's already the last one. */
function next_stage(string $currentStage): string
{
    $index = array_search($currentStage, STAGE_SEQUENCE, true);
    if ($index === false || $index >= count(STAGE_SEQUENCE) - 1) {
        return $currentStage;
    }
    return STAGE_SEQUENCE[$index + 1];
}

/** All configured stage labels as [stage_key => label], with a safe fallback to the key itself. */
function stage_labels_map(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = [];
    foreach (STAGE_SEQUENCE as $key) {
        $cached[$key] = $key; // fallback
    }
    try {
        $rows = db()->query('SELECT stage_key, label FROM stage_labels')->fetchAll();
        foreach ($rows as $row) {
            $cached[$row['stage_key']] = $row['label'];
        }
    } catch (Throwable $e) {
        // table not migrated yet on this request somehow -- fall back to keys
    }
    return $cached;
}

/** Human-readable label for a stage key, e.g. "Stage 1" -> "Protocol Submission". */
function stage_label(string $stageKey): string
{
    $map = stage_labels_map();
    return $map[$stageKey] ?? $stageKey;
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
        'model'       => OPENROUTER_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => mb_substr($userContent, 0, 12000)],
        ],
        'temperature' => 0.3,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://prism.ceu-malolos.local',
            'X-Title: PRISM RPMS',
        ],
    ]);
    $response  = curl_exec($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'refresh_token' => GMAIL_REFRESH_TOKEN,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response  = curl_exec($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
    $cachedToken  = $decoded['access_token'];
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
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['raw' => $encoded]),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $response  = curl_exec($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
