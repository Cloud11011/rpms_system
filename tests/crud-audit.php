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
    private array $params=[];
    public function __construct(private FixtureDb $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->params=$params??[];
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
            if(str_starts_with($this->sql,'INSERT INTO activity_logs'))$GLOBALS['audit'][]=['transactional_activity',$params];
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if(str_starts_with($this->sql,'SELECT id FROM users WHERE email=? LIMIT 1') && !empty($GLOBALS['case']['inactiveLogin']))return [['id'=>22]];
        if(str_starts_with($this->sql,'SELECT id,status,archived_at FROM advisers'))return ['id'=>7,'status'=>'Active','archived_at'=>null];
        if (str_contains($this->sql,'FROM documents')) return !empty($GLOBALS['case']['documents']) ? ['id'=>1] : false;
        if (!empty($GLOBALS['case']['create']) && str_contains($this->sql, 'FROM users')) return !empty($GLOBALS['case']['inactiveLogin']) ? ['id'=>22,'role'=>'student','status'=>'Inactive'] : false;
        return str_contains($this->sql, 'FOR UPDATE') && !empty($GLOBALS['case']['missing']) ? false : $GLOBALS['record'];
    }
    public function fetchColumn(int $column = 0): mixed
    { if (str_contains($this->sql, 'SELECT v FROM schema_meta')) return 0;
        if (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'RELEASE_LOCK')) return 1;
        return !empty($GLOBALS['case']['create']) && str_contains($this->sql, 'FROM users') ? false : 7; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if(str_starts_with($this->sql,'SELECT * FROM advisers WHERE id=? FOR UPDATE') && ($this->params[0]??null)===7)return [$GLOBALS['fixtureAdviser']];
        if(str_starts_with($this->sql,'SELECT id FROM users WHERE email=:email OR username=:identifier'))return [['id'=>$GLOBALS['fixtureAdviserLogin']['id']]];
        if(str_starts_with($this->sql,'SELECT * FROM users WHERE id IN ('))return [$GLOBALS['fixtureAdviserLogin']];
        if(str_starts_with($this->sql,'SELECT id FROM users WHERE email=? LIMIT 1') && !empty($GLOBALS['case']['inactiveLogin']))return [['id'=>22]];
        if(str_starts_with($this->sql,'SELECT id,status,archived_at FROM advisers'))return [['id'=>7,'status'=>'Active','archived_at'=>null]];
        if(str_contains($this->sql,'INFORMATION_SCHEMA.TABLES'))return array_map(fn($t)=>['TABLE_NAME'=>$t,'ENGINE'=>'InnoDB'],['students','advisers','users','password_resets','account_invitations','activity_logs']);
        if(str_starts_with($this->sql,'SELECT * FROM users WHERE id='))return [$GLOBALS['actor']+['status'=>'Active']];
        if(str_starts_with($this->sql,'SELECT * FROM students WHERE id=')||str_starts_with($this->sql,'SELECT * FROM advisers WHERE id='))return !empty($GLOBALS['case']['missing'])?[]:[$GLOBALS['record']];
        if(str_starts_with($this->sql,'SELECT DATE_ADD')||str_starts_with($this->sql,'SELECT datetime'))return [['unresolved_workflow'=>0]];
        return [];
    }
    // An unchanged MySQL UPDATE can affect zero rows without indicating a missing record.
    public function rowCount(): int { return str_starts_with($this->sql,'INSERT')?1:0; }
}
function generate_temporary_password(): string { return 'FIXTURE-PROVISIONED-PASSWORD'; }
// These CRUD mocks cover endpoint validation/atomicity; real ownership and provenance use MariaDB tests.
function lifecycle_identity_capture(PDO $pdo,array $record,string $type): array { return $record; }
function lifecycle_identity_persist(PDO $pdo,array $actor,string $type,?array $before,array $after): void {
    if (!$pdo->inTransaction()) throw new RuntimeException('Identity evidence outside transaction.');
    $pdo->prepare('INSERT INTO activity_logs (action) VALUES ("account_identity_fixture")')->execute();
}
function lifecycle_archive_login(PDO $pdo,array $record,string $type): void {
    $pdo->prepare('UPDATE users SET status="Inactive" WHERE role=:role AND email=:email')->execute([':role'=>$type,':email'=>$record['email']]);
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=:id')->execute([':id'=>11]);
}
// Ownership is independently verified with real MariaDB; this CRUD fixture supplies its owned login.
function lifecycle_resolve_login(PDO $pdo,array $record,string $type,bool $allowAbsent=false):?array{return ['id'=>11,'role'=>$type,'email'=>$record['email'],'status'=>'Active'];}
function lifecycle_rows(PDO $pdo,string $sql,array $params=[]):array{$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
function retention_table(string $type):string{return retention_type($type)==='student'?'students':'advisers';}
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
    $fixtureAdviser=['id'=>7,'employee_id'=>'AD-7','full_name'=>$actor['role']==='adviser'?$actor['full_name']:'Fixture Adviser',
        'email'=>$actor['role']==='adviser'?$actor['email']:'adviser@example.test','user_id'=>$actor['role']==='adviser'?3:7,
        'status'=>'Active','archived_at'=>null,'profile_completed_at'=>'2026-09-30 00:00:00'];
    $fixtureAdviserLogin=['id'=>$fixtureAdviser['user_id'],'role'=>'adviser','email'=>$fixtureAdviser['email'],
        'username'=>'AD-7','ref_id'=>'AD-7','full_name'=>$fixtureAdviser['full_name'],'status'=>'Active'];
    $record = ['id' => 11, 'email' => 'student@example.test', 'adviser_id' => !empty($case['reassigned']) ? 8 : 7,
        'stage' => 'Stage 1', 'status' => 'On Track', 'protocol_code' => 'FIXTURE', 'is_principal_investigator' => 1,
        'course' => 'Fixture Course', 'requirements' => 'Fixture requirements', 'student_id' => 'ST-11', 'full_name' => 'Fixture Student', 'department'=>'Legacy department'];
    $payload = ['id' => !empty($case['create']) ? 0 : 11, 'studentId' => 'ST-11', 'employeeId' => 'AD-11', 'name' => 'Fixture Student',
        'email' => 'student@example.test', 'adviserId' => 7, 'stage' => !empty($case['progress']) ? 'Stage 2' : 'Stage 1',
        'status' => $case['file'] === 'advisers_api.php' ? 'Active' : 'On Track', 'department'=>!empty($case['create']) ? 'Accountancy / Management / Technology' : 'Legacy department'];
    $record = array_replace($record, $case['storedAcademic'] ?? []);
    $record+=['employee_id'=>'AD-11','archived_at'=>null];
    if($case['file']==='advisers_api.php')$record['status']='Active';
    if (in_array($case['file'], ['students_api.php', 'ierb_api.php'], true) && !empty($case['create']) && !array_key_exists('academicInput', $case)) {
        $payload += ['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '2nd Year', 'academicYear' => '2026-2027'];
    }
    $payload = array_replace($payload, $case['academicInput'] ?? []);
    if ($case['file'] === 'advisers_api.php' && ($case['action'] ?? 'save') === 'delete') $payload['expectedAssignedStudents'] = 0;
    if ($case['file'] === 'ierb_api.php' && !empty($case['create']) && empty($case['omitGroup'])
        && !array_key_exists('group', $payload) && !array_key_exists('groupId', $payload)) {
        $payload['groupId'] = '__create__';
    }
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
    require_once __DIR__.'/retention-fixture-support.php';retention_fixture_support(__NAMESPACE__);
    $retention=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../includes/account_retention.php'));
    foreach(['retention_type','retention_id','retention_unresolved_sql','retention_actor','retention_audit','retention_locked_record','retention_change'] as $function) {
        $from=strpos($retention,'function '.$function.'(');$to=strpos($retention,"\n}\n",$from);
        eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\Throwable; use \\RuntimeException; use \\LogicException; use \\AccountLifecycleConflict; use \\AccountLifecycleForbidden; use \\AccountLifecycleNotFound; use \\AccountLifecycleValidation;'.substr($retention,$from,$to+2-$from));
    }
    $workflow = str_replace("\r\n","\n",file_get_contents(__DIR__ . '/../workflow.php'));
    $studentFrom=strpos($workflow,'function student_with_adviser(');$studentTo=strpos($workflow,"\n}\n",$studentFrom);
    eval('namespace '.__NAMESPACE__.'; use \\PDO;'.substr($workflow,$studentFrom,$studentTo+2-$studentFrom));
    $start = strpos($workflow, 'function archive_student(');
    $end = strpos($workflow, "\n}\n", $start);
    $archive=str_replace("require_once __DIR__ . '/includes/account_lifecycle.php';",'',substr($workflow,$start,$end+2-$start));
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \Throwable; use \InvalidArgumentException; use \DomainException; ' . $archive);
    $source = file_get_contents(__DIR__ . '/../' . $case['file']);
    $source=str_replace(["require_once __DIR__.'/includes/account_lifecycle.php';","require_once __DIR__ . '/includes/account_lifecycle.php';"],'',$source);
    $source = str_replace("require __DIR__ . '/config.php';", '', $source, $configIncludes);
    $source = str_replace("require_once __DIR__ . '/workflow.php';", '', $source, $workflowIncludes);
    if ($configIncludes !== 1 || $workflowIncludes !== 1) throw new RuntimeException('Unexpected bootstrap.');
    $source = str_replace("require_once __DIR__ . '/includes/academic_catalog.php';", '', $source, $academicIncludes);
    if ($academicIncludes !== 1) throw new RuntimeException('Unexpected academic include.');
    $source = str_replace("require_once __DIR__ . '/includes/research_groups.php';", '', $source);
    require_once __DIR__ . '/../includes/research_groups.php';
    $source = str_replace("require_once __DIR__ . '/includes/pagination.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/record_filters.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/csv_export.php';", '', $source);
    if (preg_match('/\b(?:require|include)(?:_once)?\b/', $source)) throw new RuntimeException('Unexpected endpoint include.');
    require_once __DIR__ . '/../includes/academic_catalog.php'; // Pure catalog, exact path only.
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \PDOException; use \Throwable; use \RuntimeException; use \DateTime; use \\AccountLifecycleConflict; use \\AccountLifecycleNotFound; ' . preg_replace('/^<\?php\s*/', '', $source));
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
foreach ([
 ['label'=>'valid catalog edit','input'=>'Nursing','stored'=>'Legacy department','expectedStatus'=>200],
 ['label'=>'unchanged legacy department','input'=>'Legacy department','stored'=>'Legacy department','expectedStatus'=>200],
 ['label'=>'unchanged legacy whitespace preserved','input'=>'Legacy department','stored'=>' Legacy department ','expectedStatus'=>200],
 ['label'=>'legacy blank preserved','input'=>'','stored'=>'','expectedStatus'=>200],
 ['label'=>'crafted arbitrary department rejected','input'=>'Uncontrolled','stored'=>'Legacy department','expectedStatus'=>422,'noWrites'=>true],
] as $dept) $cases[]=$dept+['file'=>'advisers_api.php','name'=>'Adviser department: '.$dept['label'],'academicInput'=>['department'=>$dept['input']],'storedAcademic'=>['department'=>$dept['stored']],'departmentExpected'=>$dept['expectedStatus']===200?($dept['input']==='Nursing'?'Nursing':$dept['stored']):null];
$cases[]=['file'=>'advisers_api.php','name'=>'New adviser arbitrary department rejected','create'=>true,'academicInput'=>['department'=>'Arbitrary'],'expectedStatus'=>422,'noWrites'=>true];
$cases[]=['file'=>'advisers_api.php','name'=>'Negative adviser ID cannot bypass new department validation','academicInput'=>['id'=>-1,'department'=>'Arbitrary'],'expectedStatus'=>422,'noWrites'=>true];
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
$crafted = ['studentId'=>'CHANGED','name'=>'Changed Name','email'=>'changed@example.com','adviserId'=>999,'stage'=>'Completed','status'=>'arbitrary','protocolCode'=>'ABC','isPrincipalInvestigator'=>true,'research'=>'Updated research','requirements'=>'Updated requirements'];
$cases[]=['file'=>'students_api.php','name'=>'Adviser crafted edit keeps locked identity/admin values','role'=>'adviser','academicInput'=>$crafted,'storedAcademic'=>['stage'=>'Completed','status'=>'Delayed'],'expectedStatus'=>200,'lockedIdentity'=>true];
$cases[]=['file'=>'students_api.php','name'=>'Adviser creates own student with safe defaults and setup','role'=>'adviser','create'=>true,'academicInput'=>$crafted+['academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027'],'expectedStatus'=>200,'safeCreation'=>true,'delivery'=>['ok'=>true,'channel'=>'gmail_api'],'pending'=>false];
$cases[]=['file'=>'students_api.php','name'=>'Archived login collision requires Restore; creation cannot reactivate','role'=>'adviser','create'=>true,'inactiveLogin'=>true,'academicInput'=>$crafted+['academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027'],'expectedStatus'=>409,'noWrites'=>true];
foreach(['students_api.php','ierb_api.php'] as $file) {
    $cases[]=compact('file')+['name'=>"$file archives with documents",'action'=>'delete','documents'=>true,'expectedStatus'=>200,'message'=>'historical records are retained'];
    $cases[]=compact('file')+['name'=>"$file student cannot delete",'action'=>'delete','role'=>'student','expectedStatus'=>403,'noWrites'=>true,'roleDenied'=>$file==='students_api.php'];
}
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $result = json_decode($output, true);
    $pass = $exit === 0 && $error === '' && is_array($result) && $result['unexpected'] === '' && !$result['transaction'] && $result['status'] === $case['expectedStatus'];
    if ($pass) {
        if (!empty($case['create']) && $case['expectedStatus']===200) {
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
        if (!empty($case['lockedIdentity'])) {
            $params=$result['writes'][0]['params'];
            $pass=$pass&&$params[':sid']==='ST-11'&&$params[':name']==='Fixture Student'&&$params[':email']==='student@example.test'&&$params[':adv']===7&&$params[':stage']==='Completed'&&$params[':status']==='Delayed'&&$params[':pcode']==='FIXTURE'&&$params[':pi']===1&&$params[':research']==='Updated research'&&$params[':req']==='Updated requirements'&&!in_array('identity sync',$result['effects'],true)&&!$result['notifications'];
        }
        if(!empty($case['safeCreation'])) {
            $params=$result['writes'][0]['params'];
            $pass=$pass&&$params[':adv']===7&&$params[':stage']==='Stage 1'&&$params[':status']==='On Track'&&$params[':pcode']===null&&$params[':pi']===0&&$params[':sid']==='CHANGED'&&$params[':name']==='Changed Name'&&$params[':req']==='Updated requirements';
        }
        // Superseded pre-v9 expectation: creation used to reactivate login 22 and send setup.
        // Final v9 requires explicit Restore; prove no reactivation or other writes occur.
        if (!empty($case['inactiveLogin'])) $pass=$pass && !$result['writes'] && $result['status']===409;
        if (!empty($case['reassigned'])) $pass = $pass && (bool)array_filter($result['queries'], fn($sql) => str_contains($sql, 'FROM students') && str_contains($sql, 'FOR UPDATE'));
        if (isset($case['departmentExpected'])) $pass=$pass && $result['writes'][0]['params'][':dept']===$case['departmentExpected'];
    }
    if (!$pass) { fwrite(STDERR, "FAIL: {$case['name']}\n$error$output\n"); exit(1); }
    echo "PASS: {$case['name']}\n";
}
echo count($cases) . " CRUD endpoint cases passed.\n";
