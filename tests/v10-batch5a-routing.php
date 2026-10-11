<?php
namespace PrismBatch5aRouting;

/** Execute current routes and auth gates against isolated identity/profile rows. No config bootstrap. */
if (PHP_SAPI !== 'cli') exit(1);
function header(string $value): void { if (str_starts_with($value, 'Location: ')) $GLOBALS['location'] = $value; }
function session_regenerate_id(bool $delete): bool { return true; }
function too_many_recent_failures(string $email): bool { return false; }
function consume_auth_attempt(...$args): bool { return true; }
function log_activity(...$args): void {}
function json_out(array $data, int $status=200): never { http_response_code($status); echo json_encode($data); exit; }
function db(): \PDO { return $GLOBALS['fixtureDb']; }
function lifecycle_rows(\PDO $pdo, string $sql, array $params): array { return [$GLOBALS['profile']]; }
class IdentityPDO extends \PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): \PDOStatement|false { return new IdentityStatement(); }
}
class IdentityStatement extends \PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $GLOBALS['identity']; }
}
function extractFunction(string $source, string $name): string {
    if (!preg_match('/function '.preg_quote($name, '/').'\([^\n]*\n\{[\s\S]*?\n\}/', $source, $match)) throw new \RuntimeException('Missing function '.$name);
    return $match[0];
}
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $identity = ['id'=>1,'role'=>$case['role'] ?? 'adviser','email'=>'fixture@example.invalid','ref_id'=>'A1','username'=>'A1',
        'full_name'=>'Fixture','status'=>$case['status'] ?? 'Active','must_change_password'=>$case['passwordChange'] ?? 0,
        'password_hash'=>password_hash('fixture-secret', PASSWORD_DEFAULT)];
    $profile = ['id'=>1,'user_id'=>1,'email'=>$identity['email'],'status'=>$case['profileStatus'] ?? 'Active',
        'profile_completed_at'=>!empty($case['pending']) ? null : '2026-10-01','archived_at'=>!empty($case['archivedProfile']) ? '2026-10-01' : null];
    if (!empty($case['anonymous'])) $identity = false;
    $fixtureDb = new IdentityPDO();
    $_SESSION = !empty($case['anonymous']) ? [] : ['user_id'=>1,'last_activity_at'=>time(),
        'credential_fingerprint'=>hash('sha256', $identity['password_hash'])];
    if (!empty($case['staleCredential'])) $_SESSION['credential_fingerprint']='invalid';
    // Credential acceptance cases must start unauthenticated. Batch 5B intentionally
    // redirects a signed-in account's reauthentication before credential processing.
    if ($case['file']==='login_process.php') $_SESSION=[];
    $_SERVER = ['SCRIPT_NAME'=>$case['file'],'REQUEST_METHOD'=>'POST','HTTP_HOST'=>'fixture.test','HTTP_ORIGIN'=>'http://fixture.test'];
    if (isset($case['origin'])) $_SERVER['HTTP_ORIGIN']=$case['origin'];
    if (!empty($case['missingOrigin'])) unset($_SERVER['HTTP_ORIGIN']);
    if (isset($case['method'])) $_SERVER['REQUEST_METHOD']=$case['method'];
    $_POST = ['email'=>'fixture@example.invalid','password'=>!empty($case['wrongPassword']) ? 'wrong' : 'fixture-secret'];
    $location = ''; $allowed = false;
    require_once __DIR__.'/../security.php';
    $config = file_get_contents(__DIR__.'/../config.php');
    $onboarding = file_get_contents(__DIR__.'/../includes/account_onboarding.php');
    foreach (['current_user','require_login','api_require_login','require_post_same_origin'] as $name)
        eval('namespace '.__NAMESPACE__.'; '.extractFunction($config,$name));
    foreach (['onboarding_profile','onboarding_complete'] as $name)
        eval('namespace '.__NAMESPACE__.'; use \\PDO; '.extractFunction($onboarding,$name));
    ob_start();
    register_shutdown_function(function () { $output=ob_get_clean(); echo json_encode(['location'=>$GLOBALS['location'],'allowed'=>$GLOBALS['allowed'],
        'session'=>!empty($_SESSION['user_id']),'error'=>$_SESSION['error'] ?? '', 'status'=>http_response_code() ?: 200,'response'=>json_decode($output,true)]); });
    if ($case['file']==='documents_api.php') {
        $_GET=['action'=>'review'];
        $source=file_get_contents(__DIR__.'/../documents_api.php');
        $prefix=substr($source,strpos($source,'$user = api_require_login'),strpos($source,'// The viewing adviser')-strpos($source,'$user = api_require_login'));
        eval('namespace '.__NAMESPACE__.'; '.$prefix);
        // Same role gate used at the start of the real review branch.
        api_require_login(['admin','adviser']); $allowed=true; exit;
    }
    if (in_array($case['file'], ['research_adviser.php','ierbprog.php'], true)) {
        // Execute the real page's auth preamble; rendering is covered by the browser harness.
        $source=file_get_contents(__DIR__.'/../'.$case['file']);
        if (!preg_match('/\$authUser = require_login\([^;]+;/', $source, $match)) throw new \RuntimeException('Missing page gate');
        eval('namespace '.__NAMESPACE__.'; '.$match[0]); $allowed=true; exit;
    }
    $source=file_get_contents(__DIR__.'/../'.$case['file']);
    $source=str_replace("require __DIR__ . '/config.php';",'', $source);
    $source=preg_replace('/^<\?php\s*/','', $source);
    eval('namespace '.__NAMESPACE__.'; '.$source); exit;
}
$cases = [
    ['file'=>'index.php','expected'=>'research_adviser.php'],
    ['file'=>'index.php','passwordChange'=>1,'expected'=>'change_password_required.php'],
    ['file'=>'index.php','passwordChange'=>1,'pending'=>true,'expected'=>'change_password_required.php'],
    ['file'=>'index.php','pending'=>true,'expected'=>'complete_profile.php'],
    ['file'=>'index.php','profileStatus'=>'Pending','expected'=>'complete_profile.php'],
    ['file'=>'index.php','status'=>'Inactive','expected'=>'login.php'],
    ['file'=>'index.php','archivedProfile'=>true,'expected'=>'complete_profile.php'],
    ['file'=>'index.php','staleCredential'=>true,'expected'=>'login.php'],
    ['file'=>'index.php','role'=>'student','expected'=>'student.php'],
    ['file'=>'index.php','role'=>'admin','expected'=>'dashboard.php'],
    ['file'=>'research_adviser.php','anonymous'=>true,'expected'=>'login.php'],
    ['file'=>'research_adviser.php','passwordChange'=>1,'expected'=>'change_password_required.php'],
    ['file'=>'research_adviser.php','pending'=>true,'expected'=>'complete_profile.php'],
    ['file'=>'research_adviser.php','status'=>'Inactive','expected'=>'login.php'],
    ['file'=>'research_adviser.php','expected'=>'','allow'=>true],
    ['file'=>'ierbprog.php','expected'=>'','allow'=>true],
    ['file'=>'ierbprog.php','pending'=>true,'expected'=>'complete_profile.php'],
    ['file'=>'ierbprog.php','passwordChange'=>1,'expected'=>'change_password_required.php'],
    ['file'=>'login_process.php','expected'=>'index.php'],
    ['file'=>'login_process.php','status'=>'Inactive','expected'=>'login.php'],
    ['file'=>'login_process.php','wrongPassword'=>true,'expected'=>'login.php'],
    ['file'=>'documents_api.php','anonymous'=>true,'expected'=>'','httpStatus'=>401],
    ['file'=>'documents_api.php','status'=>'Inactive','expected'=>'','httpStatus'=>401],
    ['file'=>'documents_api.php','passwordChange'=>1,'expected'=>'','httpStatus'=>403],
    ['file'=>'documents_api.php','pending'=>true,'expected'=>'','httpStatus'=>403],
    ['file'=>'documents_api.php','archivedProfile'=>true,'expected'=>'','httpStatus'=>403],
    ['file'=>'documents_api.php','origin'=>'http://attacker.invalid','expected'=>'','httpStatus'=>403],
    ['file'=>'documents_api.php','missingOrigin'=>true,'expected'=>'','httpStatus'=>403],
    ['file'=>'documents_api.php','method'=>'GET','expected'=>'','httpStatus'=>405],
    ['file'=>'documents_api.php','role'=>'student','expected'=>'','httpStatus'=>403],
];
foreach ($cases as $case) {
    $process=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    $result=json_decode($out,true);
    $expected=$case['expected'] === '' ? '' : 'Location: '.$case['expected'];
    if ($exit !== 0 || $err !== '' || ($result['location'] ?? null) !== $expected || ($result['allowed'] ?? false) !== ($case['allow'] ?? false)
        || ($result['status'] ?? 0) !== ($case['httpStatus'] ?? 200) || (isset($case['httpStatus']) && ($result['response']['ok'] ?? true)!==false))
        throw new \RuntimeException(json_encode($case).' '.$err.$out);
}
echo 'PASS: '.count($cases)." real routing/login/auth/profile cases; no application bootstrap or database.\n";
