<?php
namespace PrismCrudAudit;
use PDO;
use PDOStatement;
use PDOException;
use RuntimeException;

/** CLI-only endpoint fixtures; no application configuration or live database is loaded. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class FixtureDb extends PDO
{
    public bool $transaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public array $queries = [], $writes = [], $effects = [];
    public function __construct() {}
    public function lastInsertId(?string $name = null): string|false { return '11'; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    { return new FixtureStatement($this, preg_replace('/\s+/', ' ', $query)); }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->commits++; $this->transaction = false; return true; }
    public function rollBack(): bool
    { $this->rollbacks++; $this->effects = []; $this->transaction = false; return true; }
}
class FixtureStatement extends PDOStatement
{
    public function __construct(private FixtureDb $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->db->queries[] = $this->sql;
        if (str_contains($this->sql, 'FOR UPDATE') && !$this->db->transaction) throw new RuntimeException('Lock outside transaction.');
        if (preg_match('/^(UPDATE|DELETE|INSERT)/', $this->sql)) {
            if (!$this->db->transaction) throw new RuntimeException('Write outside transaction.');
            $this->db->writes[] = ['sql' => $this->sql, 'params' => $params];
            if (!empty($GLOBALS['case']['driverError'])) {
                $e = new PDOException('Fixture private database diagnostic.');
                $e->errorInfo = ['23000', $GLOBALS['case']['driverError'], 'Fixture diagnostic'];
                throw $e;
            }
            $this->db->effects[] = $this->sql;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!empty($GLOBALS['case']['create']) && str_contains($this->sql, 'FROM users')) return false;
        return str_contains($this->sql, 'FOR UPDATE') && !empty($GLOBALS['case']['missing']) ? false : $GLOBALS['record'];
    }
    public function fetchColumn(int $column = 0): mixed
    { return !empty($GLOBALS['case']['create']) && str_contains($this->sql, 'FROM users') ? false : 7; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    // An unchanged MySQL UPDATE can affect zero rows without indicating a missing record.
    public function rowCount(): int { return 0; }
}
function generate_temporary_password(): string { return 'FIXTURE-PROVISIONED-PASSWORD'; }
function send_account_setup_email(...$args): array { return $GLOBALS['case']['delivery']; }
function db(): PDO { return $GLOBALS['fixtureDb']; }
function api_require_login($roles): array
{
    if (!in_array($GLOBALS['actor']['role'], (array)$roles, true)) json_out(['ok' => false, 'message' => 'Forbidden.'], 403);
    return $GLOBALS['actor'];
}
function require_post_same_origin(): void { $GLOBALS['originChecks']++; }
function json_body(): array { return $GLOBALS['payload']; }
function json_out(array $data, int $status = 200): never
{ $GLOBALS['response'] = $data; $GLOBALS['status'] = $status; exit; }
function is_allowed_email_domain(string $email): bool { return true; }
function sync_student_login_identity(...$args): void { $GLOBALS['fixtureDb']->effects[] = 'identity sync'; }
function override_reason_valid(string $reason): bool { return strlen(trim($reason)) >= 10; }
function override_reason_message(): string { return 'Supply a meaningful reason.'; }
function stage_label(string $stage): string { return $stage; }
function log_activity(...$args): void { $GLOBALS['audit'][] = $args; }
function audit_log(...$args): void { $GLOBALS['audit'][] = $args; }
function notify_student(...$args): void { $GLOBALS['notifications'][] = $args; }
function log_api_error(...$args): void { $GLOBALS['errors'][] = $args; }

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($case['file'], ['students_api.php', 'advisers_api.php', 'ierb_api.php'], true)) throw new RuntimeException('Unexpected endpoint.');
    $fixtureDb = new FixtureDb();
    $actor = ['id' => 3, 'role' => $case['role'] ?? 'admin', 'email' => 'actor@example.test', 'full_name' => 'Fixture Actor'];
    $record = ['id' => 11, 'email' => 'student@example.test', 'adviser_id' => !empty($case['reassigned']) ? 8 : 7,
        'stage' => 'Stage 1', 'status' => 'On Track', 'protocol_code' => 'FIXTURE', 'is_principal_investigator' => 1,
        'course' => 'Fixture Course', 'requirements' => 'Fixture requirements', 'student_id' => 'ST-11', 'full_name' => 'Fixture Student'];
    $payload = ['id' => !empty($case['create']) ? 0 : 11, 'studentId' => 'ST-11', 'employeeId' => 'AD-11', 'name' => 'Fixture Student',
        'email' => 'student@example.test', 'adviserId' => 7, 'stage' => !empty($case['progress']) ? 'Stage 2' : 'Stage 1',
        'status' => $case['file'] === 'advisers_api.php' ? 'Active' : 'On Track'];
    if (!empty($case['reason'])) $payload['reason'] = 'Verified correction for fixture review.';
    $_GET = ['action' => $case['action'] ?? 'save'];
    $audit = $errors = $notifications = [];
    $originChecks = 0; $response = null; $status = 200;
    define('APP_ENV', $case['env'] ?? 'production');
    define('ALLOW_DEVELOPMENT_PASSWORD_RESPONSE', !empty($case['allowPassword']));
    $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
    require_once __DIR__ . '/../security.php'; // Pure helpers, never application configuration.
    define('STAGE_SEQUENCE', ['Stage 1', 'Stage 2']);
    define('REQUIRE_OVERRIDE_REASON_ON_SAVE', true);
    ob_start();
    register_shutdown_function(function () {
        $unexpected = ob_get_clean();
        echo json_encode(['response' => $GLOBALS['response'], 'status' => $GLOBALS['status'],
            'transaction' => $GLOBALS['fixtureDb']->transaction, 'commits' => $GLOBALS['fixtureDb']->commits,
            'rollbacks' => $GLOBALS['fixtureDb']->rollbacks, 'queries' => $GLOBALS['fixtureDb']->queries,
            'writes' => $GLOBALS['fixtureDb']->writes, 'effects' => $GLOBALS['fixtureDb']->effects,
            'audit' => $GLOBALS['audit'], 'errors' => $GLOBALS['errors'], 'notifications' => $GLOBALS['notifications'],
            'originChecks' => $GLOBALS['originChecks'], 'unexpected' => $unexpected]);
    });
    $source = file_get_contents(__DIR__ . '/../' . $case['file']);
    $source = str_replace("require __DIR__ . '/config.php';", '', $source, $configIncludes);
    $source = str_replace("require_once __DIR__ . '/workflow.php';", '', $source, $workflowIncludes);
    if ($configIncludes !== 1 || $workflowIncludes !== ($case['file'] === 'advisers_api.php' ? 0 : 1)) throw new RuntimeException('Unexpected bootstrap.');
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \PDOException; use \Throwable; use \RuntimeException; use \DateTime; ' . preg_replace('/^<\?php\s*/', '', $source));
    exit;
}

$cases = [];
foreach (['students_api.php', 'advisers_api.php', 'ierb_api.php'] as $file) {
    foreach (['save', 'delete'] as $action) {
        $cases[] = compact('file', 'action') + ['name' => "$file $action missing record", 'missing' => true, 'expectedStatus' => 404, 'noWrites' => true];
        $cases[] = compact('file', 'action') + ['name' => "$file $action existing record", 'expectedStatus' => 200];
    }
    $cases[] = compact('file') + ['name' => "$file duplicate identifier", 'driverError' => 1062, 'expectedStatus' => 422, 'message' => 'already in use'];
    $cases[] = compact('file') + ['name' => "$file unrelated database error", 'driverError' => 9999, 'expectedStatus' => 500, 'logged' => true];
}
$cases[] = ['file' => 'students_api.php', 'name' => 'Invalid adviser reference', 'driverError' => 1452, 'expectedStatus' => 422, 'message' => 'selected adviser is invalid'];
$cases[] = ['file' => 'students_api.php', 'name' => 'Reassigned student denies previous adviser', 'role' => 'adviser', 'reassigned' => true, 'expectedStatus' => 403, 'noWrites' => true];
$cases[] = ['file' => 'students_api.php', 'name' => 'Assigned adviser can save unchanged student', 'role' => 'adviser', 'expectedStatus' => 200, 'protectedFields' => true];
foreach (['students_api.php', 'ierb_api.php'] as $file) {
    $cases[] = compact('file') + ['name' => "$file progress change requires reason", 'progress' => true, 'expectedStatus' => 422, 'requiresReason' => true];
    $cases[] = compact('file') + ['name' => "$file progress change retains audit", 'progress' => true, 'reason' => true, 'expectedStatus' => 200];
}
foreach (['students_api.php', 'advisers_api.php', 'ierb_api.php'] as $file) {
    $cases[] = compact('file') + ['name' => "$file adviser cannot delete", 'action' => 'delete', 'role' => 'adviser', 'expectedStatus' => 403, 'noWrites' => true, 'roleDenied' => true];
}
$cases[] = ['file' => 'ierb_api.php', 'name' => 'IERB adviser cannot save', 'role' => 'adviser', 'expectedStatus' => 403, 'noWrites' => true, 'roleDenied' => true];
$cases[] = ['file' => 'advisers_api.php', 'name' => 'Adviser cannot manage adviser accounts', 'role' => 'adviser', 'expectedStatus' => 403, 'noWrites' => true, 'roleDenied' => true];
foreach (['students_api.php', 'advisers_api.php', 'ierb_api.php'] as $file) {
    foreach ([
        ['label' => 'mail log fallback', 'delivery' => ['ok' => true, 'channel' => 'log'], 'pending' => true],
        ['label' => 'delivery failure', 'delivery' => ['ok' => false, 'channel' => 'none'], 'pending' => true],
        ['label' => 'delivered setup', 'delivery' => ['ok' => true, 'channel' => 'mail'], 'pending' => false],
        ['label' => 'explicit local fallback', 'delivery' => ['ok' => true, 'channel' => 'log'], 'pending' => true,
            'env' => 'development', 'allowPassword' => true, 'disclose' => true],
    ] as $setup) {
        $cases[] = $setup + ['file' => $file, 'name' => $file . ' creation: ' . $setup['label'],
            'create' => true, 'expectedStatus' => 200];
    }
}
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $result = json_decode($output, true);
    $pass = $exit === 0 && $error === '' && is_array($result) && $result['unexpected'] === '' && !$result['transaction'] && $result['status'] === $case['expectedStatus'];
    if ($pass) {
        if (!empty($case['create'])) {
            $pass = $pass && !empty($result['response']['accountCreated'])
                && $result['response']['setupPending'] === $case['pending']
                && $result['response']['setupChannel'] === $case['delivery']['channel']
                && isset($result['response']['temporaryPassword']) === !empty($case['disclose']);
            if (empty($case['disclose'])) $pass = $pass && !str_contains($output, 'FIXTURE-PROVISIONED-PASSWORD');
        }
        if (!empty($case['noWrites'])) $pass = !$result['writes'];
        if ($case['expectedStatus'] !== 200) $pass = $pass && !$result['effects'] && !$result['audit'] && !$result['notifications'] && $result['commits'] === 0;
        else $pass = $pass && $result['commits'] === 1 && $result['audit'] && $result['effects'] && $result['response']['ok'];
        if (empty($case['roleDenied'])) $pass = $pass && $result['originChecks'] === 1;
        if (!empty($case['missing'])) $pass = $pass && $result['rollbacks'] === 1 && str_contains($result['response']['message'], 'not found');
        if (!empty($case['message'])) $pass = $pass && str_contains($result['response']['message'], $case['message']);
        if (!empty($case['requiresReason'])) $pass = $pass && !empty($result['response']['requiresReason']);
        if (!empty($case['logged'])) $pass = $pass && count($result['errors']) === 1 && !str_contains(json_encode($result['response']), 'diagnostic');
        if (!empty($case['protectedFields'])) {
            $params = $result['writes'][0]['params'];
            $pass = $pass && $params[':pcode'] === 'FIXTURE' && $params[':pi'] === 1 && $params[':adv'] === 7;
        }
        if (!empty($case['reassigned'])) $pass = $pass && (bool)array_filter($result['queries'], fn($sql) => str_contains($sql, 'FROM students') && str_contains($sql, 'FOR UPDATE'));
    }
    if (!$pass) { fwrite(STDERR, "FAIL: {$case['name']}\n$error$output\n"); exit(1); }
    echo "PASS: {$case['name']}\n";
}
echo count($cases) . " CRUD endpoint cases passed.\n";
