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

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/email_format.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/includes/account_onboarding.php';
install_application_security();

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

if (!defined('APP_ENV')) {
    $environment = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
    define('APP_ENV', $environment !== '' ? $environment
        : (PHP_SAPI !== 'cli' && is_loopback_development_request() ? 'development' : 'production'));
}
if (!defined('ALLOW_DEVELOPMENT_PASSWORD_RESPONSE')) {
    define('ALLOW_DEVELOPMENT_PASSWORD_RESPONSE', getenv('ALLOW_DEVELOPMENT_PASSWORD_RESPONSE') === '1');
}

// ---------------------------------------------------------------------
// Database connection (MySQL / MariaDB - e.g. Hostinger's hosted MySQL)
// ---------------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: 3306);
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'prism');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'prism_user');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

// Hard deletion requires explicit operator verification of this exact deployment and manifest.
// Never enable this during schema changes. Archive is independent of this gate.
if (!defined('PRISM_HARD_DELETE_SCHEMA_VERIFIED')) define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', false);
if (!defined('PRISM_HARD_DELETE_VERIFICATION')) define('PRISM_HARD_DELETE_VERIFICATION', []);

// Canonical public URL used for security-sensitive absolute links (for example password resets).
// Never derive these links from the request Host header.
if (!defined('APP_BASE_URL')) {
    $configuredBaseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/');
    // Developer convenience only: derive the URL for loopback hosts. Production hosts must
    // configure APP_BASE_URL explicitly so password/setup links never trust arbitrary Host headers.
    if ($configuredBaseUrl === '' && PHP_SAPI !== 'cli') {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $host)) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
            $configuredBaseUrl = ($https ? 'https' : 'http') . '://' . $host . ($basePath ? '/' . ltrim($basePath, '/') : '');
        }
    }
    define('APP_BASE_URL', $configuredBaseUrl);
}
// Reapply after environment and URL defaults so conditional production HSTS is evaluated correctly.
install_application_security();

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
    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
    ]);

    migrate($connection); // idempotent: safe to run on every request, keeps schema current on upgrades

    $hasAdmin = (int)$connection->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($hasAdmin === 0) {
        seed($connection);
    }

    // Cache only a completely initialized connection, including after a caught initialization failure.
    $pdo = $connection;
    return $pdo;
}

// Bump this whenever you add anything to migrate(). migrate() is skipped entirely on requests
// where the stored version already matches, instead of running ~45 INFORMATION_SCHEMA/ALTER
// checks on every single request.
const SCHEMA_VERSION = 9;

function migrate(PDO $pdo): void
{
    // Read before creating even schema_meta: an ordinary request must never apply pending migration DDL.
    $hasMeta = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_meta'")->fetchColumn() > 0;
    $stored = $hasMeta
        ? $pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'")->fetchColumn()
        : false;
    if ($stored !== false && (int)$stored >= SCHEMA_VERSION) {
        return;
    }
    // Keep the existing explicitly authorized v6 command compatible; v7 needs its own opt-in.
    $targetVersion = getenv('PRISM_ALLOW_SCHEMA_V9_MIGRATION') === '1' ? 9
        : (getenv('PRISM_ALLOW_SCHEMA_V8_MIGRATION') === '1' ? 8
        : (getenv('PRISM_ALLOW_SCHEMA_V7_MIGRATION') === '1' ? 7 : 6));
    if (PHP_SAPI !== 'cli' || (getenv('PRISM_ALLOW_SCHEMA_V9_MIGRATION') !== '1'
        && getenv('PRISM_ALLOW_SCHEMA_V8_MIGRATION') !== '1'
        && getenv('PRISM_ALLOW_SCHEMA_V7_MIGRATION') !== '1'
        && getenv('PRISM_ALLOW_SCHEMA_V6_MIGRATION') !== '1')) {
        throw new RuntimeException(
            'PRISM schema migration is pending. Back up the database and verify a disposable copy before running the explicitly enabled CLI migration.'
        );
    }
    if ($stored !== false && (int)$stored >= $targetVersion) return;
    // Two authorized migration processes must not both try to ALTER the same table.
    $locked = (int)$pdo->query("SELECT GET_LOCK('prism_migrate', 30)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Could not obtain the PRISM schema migration lock.');
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (
            k VARCHAR(40) PRIMARY KEY, v VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $stored = $pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'")->fetchColumn();
        if ($stored === false || (int)$stored < $targetVersion) {
            // ALTER TABLE may commit implicitly. Every step must remain safe to retry.
            if ($stored === false || (int)$stored < 5) {
                migrate_schema($pdo); // Retain the v5 academic/reset-key upgrade for older databases.
            }
            if ($stored === false || (int)$stored < 6) migrate_schema_v6($pdo);
            if ($targetVersion >= 7 && ($stored === false || (int)$stored < 7)) migrate_schema_v7($pdo);
            if ($targetVersion >= 8 && ($stored === false || (int)$stored < 8)) migrate_schema_v8($pdo);
            if ($targetVersion >= 9) migrate_schema_v9($pdo);
            $pdo->prepare("REPLACE INTO schema_meta (k, v) VALUES ('schema_version', :v)")
                ->execute([':v' => (string)$targetVersion]);
        }
    } finally {
        if ($locked) {
            $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
        }
    }
}

/** Add official deadlines without changing any existing table or record. */
function migrate_schema_v7(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_deadlines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_user_id INT NULL,
        title VARCHAR(190) NOT NULL,
        description TEXT NULL,
        deadline_date DATE NOT NULL,
        target_scope ENUM('all','groups') NOT NULL,
        status ENUM('Active','Cancelled') NOT NULL DEFAULT 'Active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX deadline_date_status (deadline_date, status),
        INDEX deadline_creator (creator_user_id),
        FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_deadline_groups (
        deadline_id INT NOT NULL,
        research_group VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
        PRIMARY KEY (deadline_id, research_group),
        INDEX deadline_group_lookup (research_group, deadline_id),
        FOREIGN KEY (deadline_id) REFERENCES calendar_deadlines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // A pre-existing incompatible table must fail before the version stamp.
    $pdo->query('SELECT id, creator_user_id, title, description, deadline_date, target_scope,
        status, created_at, updated_at FROM calendar_deadlines LIMIT 0');
    $pdo->query('SELECT deadline_id, research_group FROM calendar_deadline_groups LIMIT 0');
    foreach (['calendar_deadlines' => 'id', 'calendar_deadline_groups' => 'deadline_id,research_group'] as $table => $primary) {
        $stmt = $pdo->prepare("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = 'PRIMARY'");
        $stmt->execute([':table' => $table]);
        if ($stmt->fetchColumn() !== $primary) throw new RuntimeException('Incompatible official deadline primary key: ' . $table);
    }
    foreach ([['calendar_deadlines', 'creator_user_id', 'users', 'SET NULL'],
              ['calendar_deadline_groups', 'deadline_id', 'calendar_deadlines', 'CASCADE']] as [$table, $column, $parent, $rule]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :table AND k.COLUMN_NAME = :column
                AND k.REFERENCED_TABLE_NAME = :parent AND k.REFERENCED_COLUMN_NAME = :id AND r.DELETE_RULE = :rule');
        $stmt->execute([':table' => $table, ':column' => $column, ':parent' => $parent, ':id' => 'id', ':rule' => $rule]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Incompatible official deadline foreign key: ' . $table);
    }
    $column = $pdo->query("SELECT COLUMN_TYPE, COLLATION_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_deadline_groups' AND COLUMN_NAME = 'research_group'")->fetch();
    if (!$column || strtolower($column['COLUMN_TYPE']) !== 'varchar(190)'
        || $column['COLLATION_NAME'] !== 'utf8mb4_bin' || $column['IS_NULLABLE'] !== 'NO') {
        throw new RuntimeException('Incompatible deadline group type/collation; review manual remediation after backup.');
    }
    foreach (['calendar_deadlines', 'calendar_deadline_groups'] as $table) {
        $engine = $pdo->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $engine->execute([':table' => $table]);
        if (strcasecmp((string)$engine->fetchColumn(), 'InnoDB') !== 0) throw new RuntimeException('Incompatible deadline engine: ' . $table);
    }
    foreach ([['calendar_deadlines', 'deadline_date_status', 'deadline_date,status'],
        ['calendar_deadlines', 'deadline_creator', 'creator_user_id'],
        ['calendar_deadline_groups', 'deadline_group_lookup', 'research_group,deadline_id']] as [$table, $index, $columns]) {
        $q = $pdo->prepare('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :idx AND INDEX_TYPE = :kind');
        $q->execute([':table' => $table, ':idx' => $index, ':kind' => 'BTREE']);
        if ($q->fetchColumn() !== $columns) throw new RuntimeException('Incompatible deadline index: ' . $index);
    }
}

/** Add lifecycle/lease fields and a retained original deadline audience; never remove records. */
function migrate_schema_v8(PDO $pdo): void
{
    add_column_if_missing($pdo, 'students', 'archived_at', 'DATETIME NULL');
    add_column_if_missing($pdo, 'notifications', 'sending_started_at', 'DATETIME NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_deadline_recipients (
        deadline_id INT NOT NULL, student_id INT NOT NULL,
        PRIMARY KEY (deadline_id, student_id), INDEX deadline_recipient_student (student_id, deadline_id),
        FOREIGN KEY (deadline_id) REFERENCES calendar_deadlines(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Recover the historical audience from retained notification records first.
    $pdo->exec("INSERT IGNORE INTO calendar_deadline_recipients (deadline_id, student_id)
        SELECT d.id, s.id FROM calendar_deadlines d JOIN notifications n
          ON n.recipient_type = 'student' AND n.subject = 'New official deadline'
          AND LEFT(n.message, CHAR_LENGTH(CONCAT('New official deadline (Deadline #', d.id, ').', CHAR(10), 'Title: ')))
              = CONCAT('New official deadline (Deadline #', d.id, ').', CHAR(10), 'Title: ')
        JOIN students s ON s.id = n.recipient_id");
    // If no historical delivery exists, preserve the currently authorized audience as a baseline.
    $pdo->exec("INSERT IGNORE INTO calendar_deadline_recipients (deadline_id, student_id)
        SELECT d.id, s.id FROM calendar_deadlines d JOIN users u ON u.id = d.creator_user_id
        JOIN students s ON s.archived_at IS NULL LEFT JOIN advisers a ON a.id = s.adviser_id
        WHERE (u.role = 'admin' OR (u.role = 'adviser' AND a.email = u.email))
          AND (d.target_scope = 'all' OR EXISTS (SELECT 1 FROM calendar_deadline_groups g
            WHERE g.deadline_id = d.id AND BINARY g.research_group = BINARY s.research_group))
          AND NOT EXISTS (SELECT 1 FROM calendar_deadline_recipients r WHERE r.deadline_id = d.id)");
    foreach (['students' => 'archived_at', 'notifications' => 'sending_started_at'] as $table => $field) {
        $q = $pdo->prepare('SELECT DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :field');
        $q->execute([':table' => $table, ':field' => $field]); $row = $q->fetch();
        if (!$row || $row['DATA_TYPE'] !== 'datetime' || $row['IS_NULLABLE'] !== 'YES') throw new RuntimeException('Incompatible lifecycle/lease column.');
    }
    foreach ([['calendar_deadline_recipients', 'deadline_id', 'calendar_deadlines', 'CASCADE'],
        ['calendar_deadline_recipients', 'student_id', 'students', 'RESTRICT']] as $relation) {
        if (!migration_foreign_key_exists($pdo, $relation)) throw new RuntimeException('Incompatible deadline audience foreign key.');
    }
    $primary = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_deadline_recipients' AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
    if ($primary !== 'deadline_id,student_id') throw new RuntimeException('Incompatible deadline audience primary key.');
    $engine = $pdo->query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_deadline_recipients'")->fetchColumn();
    $index = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_deadline_recipients'
          AND INDEX_NAME = 'deadline_recipient_student' AND INDEX_TYPE = 'BTREE'")->fetchColumn();
    if (strcasecmp((string)$engine, 'InnoDB') !== 0 || $index !== 'student_id,deadline_id') {
        throw new RuntimeException('Incompatible deadline audience engine/index.');
    }
}

/** Additive retention state. No ownership inferred from names, titles or legacy source_ref. */
function migration_v9_columns(): array
{
    $state = ['retention_hold'=>'TINYINT(1) NOT NULL DEFAULT 0', 'retention_hold_at'=>'DATETIME NULL',
        'retention_hold_by'=>'INT NULL', 'retention_hold_reason'=>'VARCHAR(500) NULL',
        'purge_postponed_at'=>'DATETIME NULL', 'purge_postponed_reason'=>'VARCHAR(255) NULL', 'restored_at'=>'DATETIME NULL'];
    return ['students'=>$state+['profile_completed_at'=>'DATETIME NULL'], 'advisers'=>['archived_at'=>'DATETIME NULL']+$state+['profile_completed_at'=>'DATETIME NULL'],
        'reports'=>['owner_student_id'=>'INT NULL'],
        'ai_outputs'=>['owner_student_id'=>'INT NULL', 'owner_document_id'=>'VARCHAR(40) NULL'],
        'calendar_deadlines'=>['creator_name'=>'VARCHAR(190) NULL']];
}

function migration_v9_indexes(): array
{
    return [['students','student_archive_lifecycle','archived_at,retention_hold'],
        ['advisers','adviser_archive_lifecycle','archived_at,retention_hold'],
        ['reports','report_owner_student','owner_student_id'],['reports','report_stored_file','filename'],
        ['documents','document_stored_file','stored_name'],['documents','document_previous_version','supersedes_id'],
        ['ai_outputs','ai_owner_student','owner_student_id'],['ai_outputs','ai_owner_document','owner_document_id'],
        ['notifications','notification_recipient','recipient_type,recipient_id'],
        ['activity_logs','lifecycle_entity','entity_type,entity_id'],['activity_logs','lifecycle_student','student_id'],
        ['students','student_profile_completion','profile_completed_at'],['advisers','adviser_profile_completion','profile_completed_at']];
}

function migration_v9_invitation_ddl(): string
{
    return "CREATE TABLE IF NOT EXISTS account_invitations (
        user_id INT NOT NULL PRIMARY KEY,
        invited_by_user_id INT NULL,
        token_hash CHAR(64) NULL UNIQUE,
        expires_at DATETIME NULL,
        accepted_at DATETIME NULL,
        last_sent_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT invitation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT invitation_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

/** Deterministic legacy identity backfill; legacy academic values stay under existing preservation rules. */
function migration_v9_backfill_sql(string $role): string
{
    if (!in_array($role,['student','adviser'],true)) throw new RuntimeException('Invalid backfill role.');
    $table=$role==='student'?'students':'advisers'; $field=$role==='student'?'student_id':'employee_id';
    $other=$role==='student'?'advisers':'students';
    return "UPDATE $table p JOIN users u ON u.email=p.email AND u.role='$role'
        SET p.profile_completed_at=p.created_at
        WHERE p.profile_completed_at IS NULL AND TRIM(p.$field)<>'' AND TRIM(p.full_name)<>''
          AND TRIM(p.email)<>'' AND p.email LIKE '%_@_%._%'
          AND u.username=p.$field AND u.ref_id=p.$field AND u.full_name=p.full_name
          AND (p.user_id IS NULL OR p.user_id=u.id)
          AND NOT EXISTS (SELECT 1 FROM $table sibling WHERE sibling.id<>p.id AND sibling.user_id=u.id)
          AND NOT EXISTS (SELECT 1 FROM $other o WHERE o.email=p.email OR o.user_id=u.id)
          AND NOT EXISTS (SELECT 1 FROM account_invitations i WHERE i.user_id=u.id)";
}

/** Use the reviewed inventory as the final prerequisite, with a projected version before stamping. */
function migration_v9_verify(PDO $pdo): void
{
    $expected=json_decode(file_get_contents(__DIR__.'/includes/account_lifecycle_schema.json'),true,512,JSON_THROW_ON_ERROR);
    $queries=[
        'tables'=>'SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME',
        'columns'=>'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA,COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes'=>'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'foreign_keys'=>'SELECT k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
            WHERE (k.TABLE_SCHEMA=DATABASE() OR k.REFERENCED_TABLE_SCHEMA=DATABASE()) AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
        'triggers'=>'SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME'];
    $actual=[]; foreach($queries as $key=>$sql) $actual[$key]=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $schema=$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach($actual['foreign_keys'] as &$fk) foreach(['TABLE_SCHEMA','REFERENCED_TABLE_SCHEMA'] as $field) {
        $fk[$field]=$fk[$field]===$schema?'{PRISM}':'{EXTERNAL}:'.$fk[$field];
    }
    unset($fk); $actual['schema_version']='9';
    foreach($expected as $key=>$value) if(json_encode($actual[$key]??null,JSON_NUMERIC_CHECK)!==json_encode($value,JSON_NUMERIC_CHECK)) {
        $detail='';
        if(is_array($value)) foreach($value as $i=>$row) if(json_encode($row,JSON_NUMERIC_CHECK)!==json_encode($actual[$key][$i]??null,JSON_NUMERIC_CHECK)) {
            $detail=' Expected '.json_encode($row).'; found '.json_encode($actual[$key][$i]??null).'.'; break;
        }
        throw new RuntimeException('Final combined v9 inventory mismatch: '.$key.'. Schema version was not advanced.'.$detail);
    }
}

/** Only these known v8 FK names may be normalized; final verification stays exact. */
function migration_v9_legacy_foreign_keys(): array
{
    return [
        ['calendar_deadlines','creator_user_id','1','calendar_deadlines_ibfk_1','users','id','RESTRICT','SET NULL'],
        ['calendar_deadline_groups','deadline_id','1','calendar_deadline_groups_ibfk_1','calendar_deadlines','id','RESTRICT','CASCADE'],
        ['calendar_deadline_recipients','deadline_id','fk_deadline_recipient_deadline','calendar_deadline_recipients_ibfk_1','calendar_deadlines','id','RESTRICT','CASCADE'],
        ['calendar_deadline_recipients','student_id','fk_deadline_recipient_student','calendar_deadline_recipients_ibfk_2','students','id','RESTRICT','RESTRICT'],
    ];
}

function migration_v9_normalize_foreign_keys(PDO $pdo): void
{
    $schema=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare('SELECT k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,
        k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL
          AND (k.CONSTRAINT_NAME IN (?,?) OR k.COLUMN_NAME=?) ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
    $pending=[];
    // Preflight every relationship before changing any FK, including composite/duplicate shapes.
    foreach(migration_v9_legacy_foreign_keys() as $mapping) {
        [$table,$column,$legacy,$canonical,$parent,$target,$update,$delete]=$mapping;
        $q->execute([$table,$legacy,$canonical,$column]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $row=$rows[0]??[]; $name=(string)($row['CONSTRAINT_NAME']??'');
        if(count($rows)!==1 || !in_array($name,[$legacy,$canonical],true)
            || [$row['TABLE_SCHEMA']??null,$row['TABLE_NAME']??null,$row['COLUMN_NAME']??null,(string)($row['ORDINAL_POSITION']??''),
                $row['REFERENCED_TABLE_SCHEMA']??null,$row['REFERENCED_TABLE_NAME']??null,$row['REFERENCED_COLUMN_NAME']??null,
                $row['UPDATE_RULE']??null,$row['DELETE_RULE']??null]!==[$schema,$table,$column,'1',$schema,$parent,$target,$update,$delete]) {
            throw new RuntimeException('Incompatible v9 legacy foreign key: '.$table.'.'.$column.'. Schema version was not advanced.');
        }
        if($name===$legacy) $pending[]=$mapping;
    }
    if(!$pending) return;
    if((int)$pdo->query("SELECT COALESCE(IS_USED_LOCK('prism_migrate')=CONNECTION_ID(),0)")->fetchColumn()!==1
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new RuntimeException('Legacy FK normalization requires the migration lock and foreign-key enforcement.');
    }
    foreach($pending as [$table,$column,$legacy,$canonical,$parent,$target,$update,$delete]) {
        // Identifiers/rules are migration constants, never request values. One ALTER avoids a drop/add gap.
        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$legacy`, ADD CONSTRAINT `$canonical`
            FOREIGN KEY (`$column`) REFERENCES `$parent` (`$target`) ON UPDATE $update ON DELETE $delete");
        $q->execute([$table,$legacy,$canonical,$column]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1 || $rows[0]['CONSTRAINT_NAME']!==$canonical) {
            throw new RuntimeException('Legacy FK normalization did not produce the canonical constraint.');
        }
    }
}

function migrate_schema_v9(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
        throw new RuntimeException('Schema v9 requires foreign-key enforcement.');
    }
    migration_v9_normalize_foreign_keys($pdo);
    foreach (migration_v9_columns() as $table=>$columns) foreach ($columns as $column=>$definition) {
        add_column_if_missing($pdo,$table,$column,$definition);
        $q=$pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $q->execute([$table,$column]); $actual=$q->fetch(PDO::FETCH_ASSOC);
        $type=strtolower(explode(' ',$definition)[0]);
        // MariaDB displays signed INT as int(11); preserve that existing schema convention.
        if ($type==='int') $type='int(11)';
        if (!$actual || strtolower($actual['COLUMN_TYPE'])!==$type
            || $actual['IS_NULLABLE']!==(str_contains($definition,'NOT NULL')?'NO':'YES')
            || ($column==='retention_hold' && (string)$actual['COLUMN_DEFAULT']!=='0')) {
            throw new RuntimeException('Incompatible v9 lifecycle column: '.$table.'.'.$column);
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_purge_jobs (
        id CHAR(32) PRIMARY KEY, account_type VARCHAR(20) NOT NULL, account_id INT NOT NULL,
        identifier VARCHAR(100) NOT NULL, actor_id INT NOT NULL, method VARCHAR(30) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'files_pending', manifest_sha256 CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
        INDEX purge_job_status (status,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (migration_v9_indexes() as [$table,$index,$columns]) {
        $q=$pdo->prepare('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? AND INDEX_TYPE=\'BTREE\' AND NON_UNIQUE=1 AND SUB_PART IS NULL'); $q->execute([$table,$index]);
        $actual=$q->fetchColumn();
        if ($actual===null) $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
        elseif ($actual!==$columns) throw new RuntimeException('Incompatible v9 index: '.$index);
    }
    $job=$pdo->query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_purge_jobs'")->fetchColumn();
    $columns=$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_purge_jobs'")->fetchColumn();
    if ($job!=='InnoDB' || $columns!=='id,account_type,account_id,identifier,actor_id,method,status,manifest_sha256,created_at,completed_at') {
        throw new RuntimeException('Incompatible purge journal. Schema version was not advanced.');
    }
    $expected=['id'=>['char(32)','NO'], 'account_type'=>['varchar(20)','NO'], 'account_id'=>['int(11)','NO'],
        'identifier'=>['varchar(100)','NO'], 'actor_id'=>['int(11)','NO'], 'method'=>['varchar(30)','NO'],
        'status'=>['varchar(30)','NO'], 'manifest_sha256'=>['char(64)','NO'], 'created_at'=>['datetime','NO'], 'completed_at'=>['datetime','YES']];
    foreach ($pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_purge_jobs'") as $c) {
        if (($expected[$c['COLUMN_NAME']]??null)!==[$c['COLUMN_TYPE'],$c['IS_NULLABLE']]) throw new RuntimeException('Incompatible purge journal column.');
    }
    $keys=$pdo->query("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols,MIN(NON_UNIQUE) AS non_unique
        FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_purge_jobs' AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $actualKeys=[];foreach($keys as $key) $actualKeys[$key['INDEX_NAME']]=[$key['cols'],(int)$key['non_unique']];
    if (($actualKeys['PRIMARY']??null)!==['id',0] || ($actualKeys['purge_job_status']??null)!==['status,created_at',1]) throw new RuntimeException('Incompatible purge journal indexes.');
    // No dependable legacy Adviser archive date: start a new conservative retention clock.
    $pdo->exec("UPDATE advisers SET archived_at=NOW() WHERE status='Inactive' AND archived_at IS NULL");
    $pdo->exec('UPDATE calendar_deadlines d JOIN users u ON u.id=d.creator_user_id SET d.creator_name=u.full_name WHERE d.creator_name IS NULL');
    foreach (['users'=>['username'=>100,'full_name'=>190], 'students'=>['student_id'=>100,'full_name'=>190],
        'advisers'=>['employee_id'=>100,'full_name'=>190]] as $table=>$fields) foreach($fields as $field=>$length) {
        $q=$pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $q->execute([$table,$field]);
        if ($q->fetchColumn()!=="varchar($length)") throw new RuntimeException('Incompatible invitation identity column.');
        $pdo->exec("ALTER TABLE `$table` MODIFY `$field` VARCHAR($length) NULL");
    }
    $pdo->exec(migration_v9_invitation_ddl());
    // Validate before backfill so malformed tables can never influence completion classification.
    migration_v9_verify($pdo);
    $pdo->beginTransaction();
    try {
        foreach(['student','adviser'] as $role) $pdo->exec(migration_v9_backfill_sql($role));
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    migration_v9_verify($pdo);
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

    repair_password_reset_id_if_needed($pdo);

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
        course VARCHAR(255),
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

    // Academic fields are additive; existing course labels and rows are never backfilled.
    widen_student_course_if_needed($pdo);
    add_column_if_missing($pdo, 'students', 'academic_unit_key', "VARCHAR(64) NULL DEFAULT NULL");
    add_column_if_missing($pdo, 'students', 'program_key', "VARCHAR(80) NULL DEFAULT NULL");
    add_column_if_missing($pdo, 'students', 'year_level', "VARCHAR(40) NULL DEFAULT NULL");
    add_column_if_missing($pdo, 'students', 'academic_year', "VARCHAR(9) NULL DEFAULT NULL");

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


/** V6 only reconciles the confirmed legacy keys and the schema's intended relationships. */
function migrate_schema_v6(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
        throw new RuntimeException('Schema v6 requires foreign_key_checks=1; migration stopped.');
    }
    foreach (['password_resets', 'students', 'ierb_history', 'notifications'] as $table) {
        repair_integer_id_if_needed($pdo, $table);
    }
    $missing = [];
    // Preflight every relationship before adding any FK. Never remove or rewrite orphan data.
    foreach (migration_required_foreign_keys() as $relationship) {
        if (!migration_foreign_key_exists($pdo, $relationship)) {
            $missing[] = $relationship;
        }
    }
    foreach ($missing as $relationship) {
        [$table, $column, $parent, $delete] = $relationship;
        // All identifiers/rules come exclusively from the fixed reviewed map.
        $pdo->exec("ALTER TABLE `$table` ADD FOREIGN KEY (`$column`) REFERENCES `$parent` (`id`) ON DELETE $delete");
        if (!migration_foreign_key_exists($pdo, $relationship)) {
            throw new RuntimeException("Foreign key $table.$column could not be verified; schema version was not advanced.");
        }
    }
}

function migration_required_foreign_keys(): array
{
    return [
        ['password_resets', 'user_id', 'users', 'CASCADE'],
        ['advisers', 'user_id', 'users', 'SET NULL'],
        ['students', 'adviser_id', 'advisers', 'SET NULL'],
        ['students', 'user_id', 'users', 'SET NULL'],
        ['ierb_history', 'student_id', 'students', 'CASCADE'],
        ['documents', 'student_id', 'students', 'SET NULL'],
    ];
}

/** Validate semantics and prerequisites, not an installation-specific constraint name. */
function migration_foreign_key_exists(PDO $pdo, array $relationship): bool
{
    if (!in_array($relationship, array_merge(migration_required_foreign_keys(), [
        ['calendar_deadline_recipients', 'deadline_id', 'calendar_deadlines', 'CASCADE'],
        ['calendar_deadline_recipients', 'student_id', 'students', 'RESTRICT'],
    ]), true)) {
        throw new RuntimeException('Unreviewed foreign-key repair target.');
    }
    [$table, $column, $parent, $delete] = $relationship;
    $label = "$table.$column -> $parent.id";
    $metadata = $pdo->prepare("SELECT c.DATA_TYPE, c.COLUMN_TYPE, c.IS_NULLABLE, c.EXTRA, t.ENGINE
        FROM INFORMATION_SCHEMA.COLUMNS c JOIN INFORMATION_SCHEMA.TABLES t
          ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = :table AND c.COLUMN_NAME = :column");
    $metadata->execute([':table' => $table, ':column' => $column]);
    $child = $metadata->fetch(PDO::FETCH_ASSOC);
    $metadata->execute([':table' => $parent, ':column' => 'id']);
    $reference = $metadata->fetch(PDO::FETCH_ASSOC);
    if (!$child || !$reference || strtoupper($child['ENGINE']) !== 'INNODB' || strtoupper($reference['ENGINE']) !== 'INNODB'
        || !in_array(strtolower($child['DATA_TYPE']), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)
        || strtolower($child['DATA_TYPE']) !== strtolower($reference['DATA_TYPE'])
        || str_contains(strtolower($child['COLUMN_TYPE']), 'unsigned') !== str_contains(strtolower($reference['COLUMN_TYPE']), 'unsigned')
        || $child['EXTRA'] !== '' || !in_array(strtolower($reference['EXTRA']), ['', 'auto_increment'], true)
        || ($delete === 'SET NULL' && $child['IS_NULLABLE'] !== 'YES')) {
        throw new RuntimeException("Incompatible columns/engine for foreign key $label; manual review required.");
    }
    $indexes = $pdo->prepare("SELECT COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX");
    $indexes->execute([':table' => $parent]);
    $primary = $indexes->fetchAll(PDO::FETCH_ASSOC);
    if (count($primary) !== 1 || $primary[0]['COLUMN_NAME'] !== 'id' || strtoupper($primary[0]['INDEX_TYPE']) !== 'BTREE') {
        throw new RuntimeException("Missing compatible single-column parent primary key for $label; manual review required.");
    }
    $inspect = $pdo->prepare("SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA,
            k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE,
            DATABASE() AS CURRENT_SCHEMA
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :table AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION");
    $inspect->execute([':table' => $table]);
    $constraints = [];
    foreach ($inspect->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $constraints[$row['CONSTRAINT_NAME']][] = $row;
    }
    $found = 0;
    foreach ($constraints as $rows) {
        if (!in_array($column, array_column($rows, 'COLUMN_NAME'), true)) continue;
        $row = $rows[0];
        if (count($rows) !== 1 || $row['REFERENCED_TABLE_SCHEMA'] !== $row['CURRENT_SCHEMA']
            || $row['REFERENCED_TABLE_NAME'] !== $parent || $row['REFERENCED_COLUMN_NAME'] !== 'id'
            || strtoupper($row['DELETE_RULE']) !== $delete
            || !in_array(strtoupper($row['UPDATE_RULE']), ['RESTRICT', 'NO ACTION'], true) || ++$found > 1) {
            throw new RuntimeException("Conflicting foreign key for $label; manual review required.");
        }
    }
    // Check even existing relationships: legacy imports may have disabled FK checks.
    if ($pdo->query("SELECT 1 FROM `$table` child LEFT JOIN `$parent` parent ON child.`$column` = parent.`id`
        WHERE child.`$column` IS NOT NULL AND parent.`id` IS NULL LIMIT 1")->fetchColumn() !== false) {
        throw new RuntimeException("Orphan rows prevent foreign key $label; repair data explicitly before retrying.");
    }
    $index = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
          AND SEQ_IN_INDEX = 1 AND INDEX_TYPE = 'BTREE' AND SUB_PART IS NULL");
    $index->execute([':table' => $table, ':column' => $column]);
    if ($found && (int)$index->fetchColumn() < 1) {
        throw new RuntimeException("Missing child index for existing foreign key $label; manual review required.");
    }
    // InnoDB creates a missing child index when ADD FOREIGN KEY runs; post-verification checks it.
    return $found === 1;
}

/**
 * Repair only reviewed integer keys, preserving rows and relationships.
 * Called inside the explicitly enabled, advisory-locked migration.
 */
function repair_password_reset_id_if_needed(PDO $pdo): void
{
    repair_integer_id_if_needed($pdo, 'password_resets');
}

function repair_integer_id_if_needed(PDO $pdo, string $table): void
{
    if (!in_array($table, ['password_resets', 'students', 'ierb_history', 'notifications'], true)) {
        throw new RuntimeException('Unreviewed integer-key repair target.');
    }
    $inspect = function () use ($pdo, $table): array {
        $row = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = 'id'")
            ->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Missing $table.id; migration requires manual review.");
        }
        return $row;
    };
    $column = $inspect();
    $primaryColumns = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = 'PRIMARY'")
        ->fetchColumn();
    // Preserve integer width/signedness rather than narrowing a compatible legacy key.
    if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?(?: zerofill)?$/i', $column['COLUMN_TYPE'])
        || $column['COLUMN_NAME'] !== 'id' || $column['COLUMN_KEY'] !== 'PRI'
        || $column['IS_NULLABLE'] !== 'NO' || $primaryColumns !== 1
        || !in_array(strtolower($column['EXTRA']), ['', 'auto_increment'], true)) {
        throw new RuntimeException("Unsupported $table.id definition; migration requires manual review.");
    }
    if (strtolower($column['EXTRA']) === 'auto_increment') {
        return;
    }

    $sqlMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $modes = explode(',', strtoupper($sqlMode));
    $preserveZero = !in_array('NO_AUTO_VALUE_ON_ZERO', $modes, true);
    $setMode = $pdo->prepare('SET SESSION sql_mode = :mode');
    try {
        if ($preserveZero) {
            // ALTER can otherwise renumber an existing zero key, breaking references.
            $setMode->execute([':mode' => ltrim($sqlMode . ',NO_AUTO_VALUE_ON_ZERO', ',')]);
        }
        $comment = $pdo->quote($column['COLUMN_COMMENT']);
        if ($comment === false) {
            throw new RuntimeException("Could not preserve $table.id comment.");
        }
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `id` "
            . $column['COLUMN_TYPE'] . ' NOT NULL AUTO_INCREMENT COMMENT ' . $comment);
    } finally {
        if ($preserveZero) {
            $setMode->execute([':mode' => $sqlMode]);
        }
    }
    $verified = $inspect();
    if ($verified['COLUMN_NAME'] !== 'id' || $verified['COLUMN_KEY'] !== 'PRI'
        || strtolower($verified['EXTRA']) !== 'auto_increment'
        || $verified['IS_NULLABLE'] !== 'NO'
        || $verified['COLUMN_TYPE'] !== $column['COLUMN_TYPE']
        || $verified['COLUMN_COMMENT'] !== $column['COLUMN_COMMENT']) {
        throw new RuntimeException("$table.id repair could not be verified; schema version was not advanced.");
    }
}

/**
 * Read complete top-level declarations from SHOW CREATE TABLE. Keep quoted defaults,
 * comments and generated expressions intact, including commas and newlines in strings.
 */
function migration_column_declarations(string $sql, bool $noBackslashEscapes): array
{
    $opening = strpos($sql, '(');
    if ($opening === false) {
        throw new RuntimeException('Could not inspect the students table definition safely.');
    }
    $depth = 1;
    $quote = null;
    $start = $opening + 1;
    $declarations = [];
    $length = strlen($sql);
    for ($i = $start; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === '\\' && !$noBackslashEscapes && $quote !== '`') {
                ++$i;
            } elseif ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    ++$i;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        } elseif ($char === '(') {
            ++$depth;
        } elseif ($char === ')') {
            --$depth;
            if ($depth === 0) {
                $declarations[] = trim(substr($sql, $start, $i - $start));
                return $declarations;
            }
        } elseif ($char === ',' && $depth === 1) {
            $declarations[] = trim(substr($sql, $start, $i - $start));
            $start = $i + 1;
        }
    }
    throw new RuntimeException('Could not inspect the students table definition safely.');
}

/** Widen only the course type, preserving every other server-reported column attribute. */
function widen_student_course_if_needed(PDO $pdo): void
{
    $definition = (string)$pdo->query('SHOW CREATE TABLE `students`')->fetchColumn(1);
    $sqlMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $noBackslashEscapes = in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($sqlMode)), true);
    $course = null;
    foreach (migration_column_declarations($definition, $noBackslashEscapes) as $declaration) {
        if (preg_match('/^(?:`course`|"course"|course)\s+/i', $declaration)) {
            if ($course !== null) {
                throw new RuntimeException('Ambiguous students.course definition; migration stopped.');
            }
            $course = $declaration;
        }
    }
    if ($course === null) {
        throw new RuntimeException('Missing students.course; migration stopped without replacing legacy data.');
    }
    if (preg_match('/^(?:`course`|"course"|course)\s+(?:mediumtext|longtext|text)\b/i', $course)) {
        return; // These types already hold more than 255 characters; never shrink them.
    }
    if (!preg_match('/^((?:`course`|"course"|course)\s+)(?:var)?char\s*\(\s*(\d+)\s*\)/i', $course, $match)) {
        throw new RuntimeException('Unsupported students.course type; migration requires manual review.');
    }
    if ((int)$match[2] >= 255) {
        return;
    }
    $widened = $match[1] . 'VARCHAR(255)' . substr($course, strlen($match[0]));
    $pdo->exec('ALTER TABLE `students` MODIFY COLUMN ' . $widened);
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
    // Legacy authenticated sessions start their idle clock on their first request.
    $now = time();
    if ($now - (int)($_SESSION['last_activity_at'] ?? $now) > 1800) {
        $_SESSION = [];
        $GLOBALS['prism_session_expired'] = true;
        return null;
    }
    $_SESSION['last_activity_at'] = $now;
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user && (strcasecmp((string)$user['status'], 'Active') !== 0
        || !is_string($_SESSION['credential_fingerprint'] ?? null)
        || !hash_equals(hash('sha256', $user['password_hash']), $_SESSION['credential_fingerprint']))) {
        // A deactivated account or a changed password invalidates every older session.
        // Sessions created before credential binding must sign in again as well.
        $_SESSION = [];
        $user = false;
    }
    if ($user) {
        // Account resets can require a new password after this session was created.
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    }
    $cached = $user ?: null;
    return $cached;
}

/** Redirects to login if not authenticated; optionally restrict by role(s). */
function require_login($roles = null): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php' . (!empty($GLOBALS['prism_session_expired']) ? '?expired=1' : ''));
        exit;
    }

    // Enforce the account's current password-change requirement before continuing.
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
    if (in_array($user['role'],['student','adviser'],true)
        && !in_array($currentScript,['complete_profile.php','change_password_required.php','logout.php'],true)
        && !onboarding_complete(db(),$user)) {
        header('Location: complete_profile.php'); exit;
    }
    return $user;
}

/** Same as require_login but for JSON API endpoints: emits 401/403 instead of redirecting. */
function api_require_login($roles = null): array
{
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(!empty($GLOBALS['prism_session_expired'])
            ? ['ok' => false, 'code' => 'session_expired', 'message' => 'Your session expired due to inactivity. Please sign in again.']
            : ['ok' => false, 'message' => 'You must be logged in.']);
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
    $script=basename($_SERVER['SCRIPT_NAME']??'');
    $pendingAllowed=$script==='account_invitation_api.php' && in_array($_GET['action']??'', ['me','complete'],true);
    $pendingAllowed=$pendingAllowed || ($script==='profile_api.php' && in_array($_GET['action']??'', ['me','change_password'],true));
    if (in_array($user['role'],['student','adviser'],true) && !$pendingAllowed && !onboarding_complete(db(),$user)) {
        json_out(['ok'=>false,'code'=>'profile_completion_required','message'=>'Complete your PRISM profile before using the workspace.'],403);
    }
    return $user;
}

/**
 * State-changing requests require POST and a same-origin Origin or Referer.
 * Browser forms/AJAX send one of these; callers without origin evidence fail closed.
 * CLI workers do not invoke this request guard.
 */
function require_post_same_origin(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'message' => 'This action must be sent as a POST request.']);
        exit;
    }
    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $expected = preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]+)?$/i', $host)
        ? normalized_http_origin((request_uses_https() ? 'https' : 'http') . '://' . $host) : null;
    $actual = normalized_http_origin($source);
    if ($actual === null || $expected === null || $actual !== $expected) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Cross-site request blocked.']);
        exit;
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
            ->execute([':new' => strtolower($newEmail), ':old' => $oldEmail]);
    }
}

/** Keep the login identity aligned when RPMS edits a student's ID/name/email. */
function sync_student_login_identity(PDO $pdo, string $oldEmail, string $studentId, string $name, string $email): void
{
    if ($oldEmail === '') return;
    $pdo->prepare("UPDATE users
        SET username = :username, full_name = :name, email = :email, ref_id = :ref
        WHERE email = :old AND role = 'student'")
        ->execute([
            ':username' => $studentId, ':name' => $name, ':email' => strtolower($email),
            ':ref' => $studentId, ':old' => $oldEmail,
        ]);
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
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $windowMinutes) * 60);
        $stmt = db()->prepare("SELECT COUNT(*) FROM activity_logs
            WHERE action = 'login_failed' AND user_email = :u
            AND created_at > :cutoff");
        $stmt->execute([':u' => $username, ':cutoff' => $cutoff]);
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

/** Issues a one-time password setup link for a newly provisioned account. */
function send_account_setup_email(PDO $pdo, int $userId, string $email, string $name): array
{
    if (!app_base_url_is_valid()) {
        log_api_error('account_setup', 'Canonical URL configuration prevents account setup delivery.');
        return ['ok' => false, 'channel' => 'none', 'message' => 'Password setup email is currently unavailable.'];
    }
    try {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $pdo->beginTransaction();
        $owner = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $owner->execute([':id'=>$userId]);
        if (!$owner->fetchColumn()) throw new RuntimeException('Setup account not found.');
        // A fresh setup link supersedes every earlier reset/setup token for this account.
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = :u AND used = 0')
            ->execute([':u' => $userId]);
        $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:u,:t,:x)')
            ->execute([':u' => $userId, ':t' => 'sha256:' . hash('sha256', $token), ':x' => $expires]);
        $pdo->commit();
        $link = rtrim(APP_BASE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
        $body = "Hello $name,\n\nYour PRISM account has been created. Set your password using the one-time link below (valid for 1 hour):\n\n"
            . $link . "\n\nIf you were not expecting this account, contact the RPMS office.\n\n- CEU Malolos RPMS / PRISM";
        return send_notification_email($email, 'Set up your PRISM account', $body, true);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_api_error('account_setup', 'The setup link could not be issued or delivered.');
        return ['ok' => false, 'channel' => 'none', 'message' => 'The account was created, but the setup link could not be issued. Contact the RPMS office to arrange access.'];
    }
}

function json_body(bool $strict = false): array
{
    $raw = file_get_contents('php://input');
    if ($strict) return lifecycle_decode_body($raw);
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
function openrouter_generate(string $systemPrompt, string $userContent, bool $sensitiveContent = false): ?string
{
    if (!openrouter_available()) {
        return null;
    }

    if ($sensitiveContent && !function_exists('curl_init')) return null;
    $request = [
        'model'       => OPENROUTER_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => mb_substr($userContent, 0, 12000)],
        ],
        'temperature' => 0.3,
    ];
    // Document calls opt in; the aggregate report request remains unchanged.
    if ($sensitiveContent) $request['max_tokens'] = 900;
    $payload = json_encode($request);

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
    $boundedResponse = '';
    if ($sensitiveContent) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$boundedResponse): int {
            if (strlen($boundedResponse) + strlen($chunk) > 65536) return 0;
            $boundedResponse .= $chunk;
            return strlen($chunk);
        });
    }
    $response  = curl_exec($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        log_api_error('openrouter', $sensitiveContent ? "Document summary request failed (HTTP $status)."
            : ($curlError !== '' ? $curlError : "HTTP $status: " . substr((string)$response, 0, 500)));
        return null;
    }
    if ($sensitiveContent) $response = $boundedResponse;
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
function gmail_api_send(string $to, string $subject, string $body, bool $sensitive = false): bool
{
    $accessToken = gmail_access_token();
    if (!$accessToken) {
        return false;
    }

    $mime = notification_email_mime($body);
    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . GMAIL_SENDER_EMAIL . '>',
        'To: ' . $to,
        'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: ' . $mime['contentType'],
    ];
    $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'];
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
        log_api_error('gmail_send', $sensitive ? "Sensitive email delivery failed (HTTP $status)." : ($curlError !== '' ? $curlError : "HTTP $status: " . substr((string)$response, 0, 500)));
    }
    return $ok;
}

/**
 * Sends an email via the Gmail API when credentials are configured;
 * otherwise falls back to the server's mail() transport, and finally to
 * logging the message to storage/mail.log so the notification workflow can
 * still be demonstrated/tested end-to-end without any live credentials.
 */
function send_notification_email(string $to, string $subject, string $body, bool $sensitive = false): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid recipient email is required.'];
    }

    if (gmail_api_available()) {
        if (gmail_api_send($to, $subject, $body, $sensitive)) {
            return ['ok' => true, 'message' => 'Email delivered via the Gmail API.', 'channel' => 'gmail_api'];
        }
        // fall through to the local fallbacks below so the attempt is not silently lost
    }

    $mime = notification_email_mime($body);
    $headers = "From: " . MAIL_FROM_NAME . " <" . (GMAIL_SENDER_EMAIL ?: 'rpms@ceu.edu.ph') . ">\r\nMIME-Version: 1.0\r\nContent-Type: " . $mime['contentType'] . "\r\n";
    $delivered = @mail($to, $subject, $mime['body'], $headers);
    if ($delivered) {
        return ['ok' => true, 'message' => 'Email delivered via server mail transport.', 'channel' => 'mail'];
    }

    if ($sensitive) return ['ok' => false, 'channel' => 'none', 'message' => 'The credential email could not be delivered. Contact the RPMS office.'];

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
