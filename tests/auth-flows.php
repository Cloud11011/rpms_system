<?php
namespace PrismAuthAudit;

/** Isolated authentication endpoint tests; all database, session, and mail operations are fixtures. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/assets.php';

class FixtureDb
{
    public bool $transaction = false;
    private array $snapshot = [];
    public function prepare(string $sql): FixtureStatement { return new FixtureStatement($sql); }
    public function beginTransaction(): bool
    {
        fixture_step('begin');
        $this->snapshot = [$GLOBALS['fixtureUser'], $GLOBALS['invalidations'], $GLOBALS['issued'], $GLOBALS['fixtureReset']];
        return $this->transaction = true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool
    { fixture_step('commit'); $GLOBALS['commits']++; $this->transaction = false; return true; }
    public function rollBack(): bool
    {
        [$GLOBALS['fixtureUser'], $GLOBALS['invalidations'], $GLOBALS['issued'], $GLOBALS['fixtureReset']] = $this->snapshot;
        $GLOBALS['rollbacks']++; $this->transaction = false; return true;
    }
}
class FixtureStatement
{
    private array $params = [];
    public function __construct(private string $sql) {}
    public function execute(array $params): bool
    {
        $this->params = $params;
        if (str_starts_with($this->sql, 'SELECT user_id FROM password_resets')) fixture_step('owner_lookup');
        if (str_starts_with($this->sql, 'SELECT id FROM users')) fixture_step('user_lock');
        if (str_starts_with($this->sql, 'SELECT * FROM password_resets')) fixture_step('token_lookup');
        if (str_starts_with($this->sql, 'UPDATE users SET password_hash')) {
            fixture_step('password_update');
            if (empty($GLOBALS['case']['conflict'])) {
                $GLOBALS['fixtureUser']['password_hash'] = $params[':p'];
                if (str_contains($this->sql, 'must_change_password = 0')) $GLOBALS['fixtureUser']['must_change_password'] = 0;
            }
        } elseif (str_starts_with($this->sql, 'UPDATE password_resets')) {
            fixture_step('token_invalidation');
            $GLOBALS['invalidations']++;
            $GLOBALS['fixtureReset']['used'] = 1;
        } elseif (str_starts_with($this->sql, 'INSERT INTO users')) {
            $GLOBALS['creations']++;
        } elseif (str_starts_with($this->sql, 'INSERT INTO password_resets')) {
            $GLOBALS['issued']++;
        }
        return true;
    }
    public function fetch(): array|false
    {
        if (str_contains($this->sql, 'FROM password_resets')) {
            return $this->resetMatches() ? $GLOBALS['fixtureReset'] : false;
        }
        return empty($GLOBALS['case']['unknown']) ? $GLOBALS['fixtureUser'] : false;
    }
    public function fetchColumn(): int|false
    {
        if (str_contains($this->sql,'FROM account_invitations')) return !empty($GLOBALS['case']['unacceptedInvitation']) ? 1 : false;
        if (str_contains($this->sql, 'created_at >')) return !empty($GLOBALS['case']['recent']) ? 1 : false;
        if (str_contains($this->sql, 'FROM password_resets')) return $this->resetMatches() ? 1 : false;
        return !empty($GLOBALS['case']['unknown']) ? false : 1;
    }
    private function resetMatches(): bool
    {
        $row = $GLOBALS['fixtureReset'];
        return empty($GLOBALS['case']['invalidToken']) && !$row['used'] && strtotime($row['expires_at']) > time()
            && in_array($row['token'], [$this->params[':hashed'] ?? null, $this->params[':legacy'] ?? null], true);
    }
    public function fetchAll(): array { return str_contains($this->sql,'FROM users') && empty($GLOBALS['case']['unknown']) ? [['id'=>1]] : []; }
    public function rowCount(): int { return empty($GLOBALS['case']['conflict']) ? 1 : 0; }
}
function fixture_step(string $step): void
{
    $GLOBALS['steps'][] = $step;
    if (($GLOBALS['case']['failAt'] ?? '') === $step) throw new \RuntimeException('Fixture sensitive database diagnostic.');
}
function db(): FixtureDb { fixture_step('connect'); return $GLOBALS['fixtureDb']; }
function header(string $value): void
{
    $GLOBALS['location'] = $value;
    if (str_starts_with($value, 'Referrer-Policy: ')) $GLOBALS['referrerPolicy'] = substr($value, strlen('Referrer-Policy: '));
}
function session_regenerate_id(bool $delete): bool { $GLOBALS['rotations']++; return true; }
function too_many_recent_failures(string $email): bool { return false; }
function consume_auth_attempt(string $scope, string $subject, int $limit, int $window): bool
{ $GLOBALS['attempts'][] = $scope; return empty($GLOBALS['case']['rateBlocked']); }
function app_base_url_is_valid(): bool { return empty($GLOBALS['case']['badBaseUrl']); }
function is_allowed_email_domain(string $email): bool { return true; }
function log_activity(...$args): void {}
function audit_log(...$args): void {}
function log_api_error(...$args): void { $GLOBALS['logs'][] = $args; }
function send_notification_email(...$args): array { $GLOBALS['mail']++; return ['ok' => true]; }
function api_require_login($roles): array { return $GLOBALS['fixtureUser']; }
function json_body(): array { return $GLOBALS['body']; }
function json_out(array $body, int $status = 200): never
{
    $GLOBALS['response'] = $body;
    $GLOBALS['status'] = $status;
    exit;
}

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $fixtureDb = new FixtureDb();
    $mail = $issued = $invalidations = $rotations = $commits = $rollbacks = 0;
    $steps = $logs = $attempts = [];
    $creations = 0;
    $location = '';
    $response = null;
    $status = 200;
    $fixtureUser = ['id' => 1, 'role' => 'admin', 'full_name' => 'Fixture User', 'email' => 'fixture@example.test',
        'username' => 'fixture', 'ref_id' => 'FIXTURE', 'status' => 'Active', 'must_change_password' => 0,
        'password_hash' => password_hash('Fixture-current-42!', PASSWORD_DEFAULT)];
    $originalHash = $fixtureUser['password_hash'];
    if (!empty($case['setupAccount'])) $fixtureUser['must_change_password'] = 1;
    $fixtureReset = ['user_id' => 1, 'token' => 'sha256:' . hash('sha256', 'fixture-token'),
        'used' => !empty($case['usedToken']) ? 1 : 0,
        'expires_at' => date('Y-m-d H:i:s', time() + (!empty($case['expiredToken']) ? -1 : 3600))];
    if (isset($case['userStatus'])) $fixtureUser['status'] = $case['userStatus'];
    $_SESSION = $case['session'] ?? [];
    if (!empty($case['boundSession'])) $_SESSION['credential_fingerprint'] = hash('sha256', $originalHash);
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'fixture.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_ORIGIN' => !empty($case['crossSite']) ? 'https://attacker.test' : 'https://fixture.test'];
    $_SERVER = array_replace($_SERVER, $case['server'] ?? []);
    $_GET = ['action' => 'change_password'];
    $_POST = ['email' => 'fixture@example.test', 'password' => 'Fixture-current-42!', 'token' => 'fixture-token',
        'confirm_password' => 'Fixture-next-42!'];
    if ($case['file'] === 'update_password.php') $_POST['password'] = 'Fixture-next-42!';
    $_POST = array_replace($_POST, $case['post'] ?? []);
    if ($case['file'] === 'reset_password.php') $_GET['token'] = $case['pageToken'] ?? 'fixture-token';
    if ($case['file'] === 'register_process.php') {
        $_POST = array_replace(['employee_id' => 'FIXTURE-1', 'fullname' => 'Fixture Admin',
            'email' => 'fixture@example.test', 'password' => 'Fixture-next-42!', 'confirm_password' => 'Fixture-next-42!',
            'registration_code' => 'fixture-code'], $case['post'] ?? []);
        define(__NAMESPACE__ . '\\ADMIN_REGISTRATION_CODE', 'fixture-code');
    }
    $body = ['currentPassword' => 'Fixture-current-42!', 'newPassword' => 'Fixture-next-42!'];
    define(__NAMESPACE__ . '\\APP_BASE_URL', 'https://fixture.test');

    require_once __DIR__ . '/../security.php'; // Pure helpers; no bootstrap or secret configuration.
    // Extract the real origin guard, excluding configuration and all bootstrap code.
    $configSource = file_get_contents(__DIR__ . '/../config.php');
    $start = strpos($configSource, 'function require_post_same_origin(): void');
    $end = strpos($configSource, "\n}\n", $start);
    if ($end === false) $end = strpos($configSource, "\r\n}\r\n", $start);
    if ($start === false || $end === false) throw new \RuntimeException('Cannot extract origin guard.');
    $guard = substr($configSource, $start, $end - $start) . "\n}";
    eval('namespace ' . __NAMESPACE__ . '; ' . $guard);

    ob_start();
    register_shutdown_function(function () {
        $output = ob_get_clean();
        $result = [
            'mail' => $GLOBALS['mail'], 'issued' => $GLOBALS['issued'], 'invalidations' => $GLOBALS['invalidations'],
            'rotations' => $GLOBALS['rotations'], 'location' => $GLOBALS['location'],
            'success' => $_SESSION['success'] ?? '', 'error' => $_SESSION['error'] ?? '',
            'binding' => ($_SESSION['credential_fingerprint'] ?? '') === hash('sha256', $GLOBALS['fixtureUser']['password_hash']),
            'response' => $GLOBALS['response'] ?? json_decode($output, true),
            'status' => $GLOBALS['response'] !== null ? $GLOBALS['status'] : (http_response_code() ?: 200),
            'transaction' => $GLOBALS['fixtureDb']->inTransaction(),
            'commits' => $GLOBALS['commits'], 'rollbacks' => $GLOBALS['rollbacks'],
            'passwordChanged' => $GLOBALS['fixtureUser']['password_hash'] !== $GLOBALS['originalHash'],
            'steps' => $GLOBALS['steps'], 'logs' => $GLOBALS['logs'],
            'attempts' => $GLOBALS['attempts'], 'creations' => $GLOBALS['creations'],
            'referrerPolicy' => $GLOBALS['referrerPolicy'] ?? '', 'tokenUsed' => (bool)$GLOBALS['fixtureReset']['used'],
            'passwordChangeRequired' => (int)$GLOBALS['fixtureUser']['must_change_password'],
            'rendered' => $GLOBALS['case']['file'] === 'reset_password.php' ? $output : '',
        ];
        echo json_encode($result);
    });
    $allowed = ['login_process.php', 'forgot_password_process.php', 'update_password.php', 'profile_api.php', 'reset_password.php', 'register_process.php'];
    if (!in_array($case['file'], $allowed, true)) throw new \RuntimeException('Unexpected endpoint.');
    require_once __DIR__.'/../includes/account_identity.php';
    $onboardingSource=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../includes/account_onboarding.php'));
    $from=strpos($onboardingSource,'function onboarding_email_available(');$to=strpos($onboardingSource,"\n}\n",$from);
    $helper=str_replace('PDO $pdo','FixtureDb $pdo',substr($onboardingSource,$from,$to+2-$from));
    $from=strpos($onboardingSource,'function onboarding_requires_invitation_setup(');$to=strpos($onboardingSource,"\n}\n",$from);
    $helper.=str_replace('PDO $pdo','FixtureDb $pdo',substr($onboardingSource,$from,$to+2-$from));
    eval('namespace '.__NAMESPACE__.'; use \AccountLifecycleConflict; function lifecycle_rows(FixtureDb $pdo,string $sql,array $params=[]):array{$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();}'.$helper);
    $source = file_get_contents(__DIR__ . '/../' . $case['file']);
    $source = str_replace("require __DIR__ . '/config.php';", '', $source, $includes);
    $source = str_replace("require_once __DIR__ . '/workflow.php';", '', $source);
    if ($includes !== 1) throw new \RuntimeException('Expected exactly one config require.');
    $source = preg_replace('/^<\?php\s*/', '', $source);
    eval('namespace ' . __NAMESPACE__ . '; use \\Throwable; ' . $source);
    exit;
}

$cases = [
    ['name' => 'Login binds its new session to credentials', 'file' => 'login_process.php', 'binding' => true, 'rotations' => 1],
    ['name' => 'Login rejects cross-site submission', 'file' => 'login_process.php', 'crossSite' => true, 'blocked' => true],
    ['name'=>'Login enforces source IP limiter before lookup', 'file'=>'login_process.php', 'rateBlocked'=>true, 'rotations'=>0, 'attempts'=>['login_ip'], 'location'=>'Location: login.php'],
    ['name'=>'Wrong password never creates a session', 'file'=>'login_process.php', 'post'=>['password'=>'incorrect'], 'rotations'=>0, 'error'=>'Invalid email or password.'],
    ['name'=>'Unknown login uses the same password error', 'file'=>'login_process.php', 'unknown'=>true, 'rotations'=>0, 'error'=>'Invalid email or password.'],
    ['name'=>'Inactive login remains blocked after correct password', 'file'=>'login_process.php', 'userStatus'=>'Inactive', 'rotations'=>0, 'error'=>'This account is not active. Please contact the RPMS office.'],
    ['name' => 'Recent reset link survives repeated requests', 'file' => 'forgot_password_process.php', 'recent' => true, 'mail' => 0, 'issued' => 0, 'invalidations' => 0],
    ['name' => 'A new reset issues one link and one message', 'file' => 'forgot_password_process.php', 'mail' => 1, 'issued' => 1, 'invalidations' => 1],
    ['name' => 'Unknown email retains generic confirmation', 'file' => 'forgot_password_process.php', 'unknown' => true, 'mail' => 0, 'issued' => 0, 'invalidations' => 0],
    ['name' => 'Unaccepted invitation retains generic response without reset or mail', 'file'=>'forgot_password_process.php', 'unacceptedInvitation'=>true, 'mail'=>0, 'issued'=>0, 'invalidations'=>0],
    ['name' => 'Accepted Pending account retains ordinary recovery', 'file'=>'forgot_password_process.php', 'acceptedInvitation'=>true, 'mail'=>1, 'issued'=>1, 'invalidations'=>1],
    ['name' => 'Cross-site reset requests cannot send mail', 'file' => 'forgot_password_process.php', 'crossSite' => true, 'blocked' => true],
    ['name' => 'Password change refreshes its own credential binding', 'file' => 'profile_api.php', 'binding' => true, 'rotations' => 1, 'status' => 200, 'invalidations' => 1],
    ['name' => 'Concurrent credential changes cannot be overwritten', 'file' => 'profile_api.php', 'conflict' => true, 'status' => 409, 'invalidations' => 0],
    ['name' => 'Password reset consumes links and invalidates old binding', 'file' => 'update_password.php',
        'boundSession' => true, 'binding' => false, 'passwordChanged' => true, 'invalidations' => 1, 'commits' => 1, 'rollbacks' => 0,
        'location' => 'Location: login.php',
        'steps' => ['connect', 'owner_lookup', 'begin', 'user_lock', 'token_lookup', 'password_update', 'token_invalidation', 'commit']],
    ['name' => 'Expired reset link makes no changes', 'file' => 'update_password.php', 'invalidToken' => true,
        'invalidations' => 0, 'passwordChanged' => false, 'commits' => 0, 'rollbacks' => 1, 'location' => 'Location: forgot_password.php'],
    ['name' => 'Cross-site password reset is rejected', 'file' => 'update_password.php', 'crossSite' => true, 'blocked' => true],
    ['name'=>'Unaccepted invitation refuses previously issued reset credential', 'file'=>'update_password.php','unacceptedInvitation'=>true,
        'passwordChanged'=>false,'invalidations'=>0,'commits'=>0,'rollbacks'=>1,'location'=>'Location: forgot_password.php'],
    ['name'=>'Unaccepted invitation hides ordinary reset form','file'=>'reset_password.php','unacceptedInvitation'=>true,'passwordChanged'=>false,'hiddenResetForm'=>true],
];
// Origin-only fallback must work with the production HTTPS host and fail closed otherwise.
$httpsReset = ['file' => 'update_password.php', 'server' => ['HTTP_HOST' => 'rpmsceu.online',
    'HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://rpmsceu.online/', 'HTTPS' => 'on']];
$resetSuccess = ['passwordChanged' => true, 'tokenUsed' => true, 'commits' => 1,
    'invalidations' => 1, 'location' => 'Location: login.php'];
$resetFailure = ['passwordChanged' => false, 'commits' => 0, 'invalidations' => 0,
    'location' => 'Location: forgot_password.php'];
$cases[] = ['name' => 'Hostinger HTTPS reset accepts origin-only Referer fallback'] + $resetSuccess + $httpsReset;
$cases[] = ['name' => 'Hostinger account setup accepts origin-only Referer and clears initial-password flag',
    'setupAccount' => true, 'passwordChangeRequired' => 0] + $resetSuccess + $httpsReset;
$cases[] = ['name' => 'Forwarded HTTPS reset accepts canonical same-origin evidence',
    'server' => ['HTTP_HOST' => 'rpmsceu.online', 'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_ORIGIN' => 'https://rpmsceu.online']] + $resetSuccess + ['file' => 'update_password.php'];
foreach ([
    'No origin evidence remains forbidden' => ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => null],
    'Malformed origin remains forbidden' => ['HTTP_ORIGIN' => 'not-an-origin'],
    'Opaque origin remains forbidden even with same-origin Referer' => ['HTTP_ORIGIN' => 'null'],
    'Cross-origin Referer remains forbidden' => ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://attacker.test/'],
    'Cross-origin Origin overrides a same-origin Referer' => ['HTTP_ORIGIN' => 'https://attacker.test'],
] as $name => $server) {
    $cases[] = ['name' => $name, 'blocked' => true, 'passwordChanged' => false, 'commits' => 0,
        'invalidations' => 0, 'server' => array_replace($httpsReset['server'], $server)] + ['file' => 'update_password.php'];
}
foreach ([
    ['name' => 'Missing reset token fails', 'post' => ['token' => '']],
    ['name' => 'Invalid reset token fails', 'post' => ['token' => 'invalid-fixture-token']],
    ['name' => 'Expired reset token fails', 'expiredToken' => true],
    ['name' => 'Used reset token fails', 'usedToken' => true],
] as $invalid) $cases[] = $invalid + $resetFailure + $httpsReset;
$cases[] = ['name' => 'Reset page publishes origin-only referrer policy and retains PRISM branding',
    'file' => 'reset_password.php', 'referrerPolicy' => 'origin', 'prismBranding' => true];
foreach ([
    ['password' => 'short', 'confirm_password' => 'short'],
    ['password' => str_repeat('x', 201), 'confirm_password' => str_repeat('x', 201)],
    ['confirm_password' => 'different-password'],
] as $index => $post) {
    $cases[] = ['name' => 'Invalid reset password rolls back (' . $index . ')', 'file' => 'update_password.php',
        'post' => $post, 'invalidations' => 0, 'passwordChanged' => false, 'commits' => 0, 'rollbacks' => 1,
        'location' => 'Location: reset_password.php?token=fixture-token'];
}
$cases[] = ['name' => 'Reset flash messages escape markup', 'file' => 'reset_password.php', 'escapedFlash' => true,
    'session' => ['error' => '<img src=x onerror="alert(1)"> & error', 'success' => '<script>alert(2)</script> & success']];
foreach (['connect', 'owner_lookup', 'begin', 'user_lock', 'token_lookup', 'password_update', 'token_invalidation', 'commit'] as $step) {
    $cases[] = ['name' => 'Reset handles database failure at ' . $step, 'file' => 'update_password.php',
        'failAt' => $step, 'boundSession' => true, 'binding' => true, 'passwordChanged' => false, 'invalidations' => 0,
        'commits' => 0, 'rollbacks' => in_array($step, ['connect', 'owner_lookup', 'begin'], true) ? 0 : 1,
        'error' => 'Your password could not be updated. Please try again later.', 'success' => '',
        'location' => 'Location: forgot_password.php'];
}
foreach ([
    ['name' => 'Rate-limited reset has generic confirmation', 'rateBlocked' => true],
    ['name' => 'Known account with invalid base URL has generic confirmation', 'badBaseUrl' => true],
    ['name' => 'Unknown account with invalid base URL has generic confirmation', 'badBaseUrl' => true, 'unknown' => true],
] as $extra) {
    $cases[] = $extra + ['file' => 'forgot_password_process.php', 'mail' => 0, 'issued' => 0,
        'invalidations' => 0, 'creations' => 0, 'location' => 'Location: forgot_password.php'];
}
$cases[] = ['name' => 'Registration origin guard rejects cross-site guesses', 'file' => 'register_process.php',
    'crossSite' => true, 'blocked' => true, 'creations' => 0, 'attempts' => []];
$cases[] = ['name' => 'Registration throttle blocks before code verification', 'file' => 'register_process.php',
    'rateBlocked' => true, 'creations' => 0, 'attempts' => ['admin_registration_ip'],
    'error' => 'Too many registration attempts. Please try again later.'];
$cases[] = ['name' => 'Wrong registration code consumes an attempt', 'file' => 'register_process.php',
    'post' => ['registration_code' => 'wrong'], 'creations' => 0, 'attempts' => ['admin_registration_ip'],
    'error' => 'Invalid staff registration code.'];
$cases[] = ['name' => 'Valid registration retains provisioning behavior', 'file' => 'register_process.php',
    'unknown' => true, 'creations' => 1, 'attempts' => ['admin_registration_ip'], 'location' => 'Location: login_admin.php'];
$confirmations = [];
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new \RuntimeException('Cannot start fixture process.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $data = json_decode($output, true);
    $passed = $exit === 0 && $error === '' && is_array($data) && !$data['transaction'];
    foreach (['mail', 'issued', 'invalidations', 'rotations', 'binding', 'status', 'commits', 'rollbacks', 'passwordChanged', 'location', 'error', 'success', 'steps', 'attempts', 'creations', 'referrerPolicy', 'tokenUsed', 'passwordChangeRequired'] as $key) {
        if (isset($case[$key]) && ($data[$key] ?? null) !== $case[$key]) $passed = false;
    }
    if (!empty($case['blocked'])) {
        $passed = $passed && $data['status'] === 403 && $data['mail'] === 0 && $data['rotations'] === 0
            && ($data['response']['message'] ?? '') === 'Cross-site request blocked.';
    }
    if (!empty($case['failAt'])) {
        $passed = $passed && count($data['logs']) === 1 && !str_contains($output, 'sensitive database diagnostic');
    }
    if (!empty($case['escapedFlash'])) {
        foreach ($case['session'] as $key => $value) {
            $escaped = "<div class='$key-message'>" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>';
            $passed = $passed && str_contains($data['rendered'], $escaped)
                && !str_contains($data['rendered'], $value) && $data[$key] === '';
        }
    }
    if (!empty($case['prismBranding'])) $passed = $passed && str_contains($data['rendered'],
        '<img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">');
    if (!empty($case['hiddenResetForm'])) $passed=$passed && str_contains($data['rendered'],'style="display:none"');
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $case['name'] . "\n" . $error . $output . "\n");
        exit(1);
    }
    if ($case['file'] === 'forgot_password_process.php' && empty($case['blocked'])) $confirmations[] = $data['success'];
    echo 'PASS: ' . $case['name'] . "\n";
}
if (count(array_unique($confirmations)) !== 1 || $confirmations[0] === '') throw new \RuntimeException('Reset confirmations differ.');
echo "PASS: Reset confirmations are identical for known, unknown, and throttled emails.\n";
echo (count($cases) + 1) . " authentication flow checks passed.\n";
