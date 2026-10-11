<?php
namespace PrismDocumentAudit;
use PDO;
use PDOStatement;
use RuntimeException;
// Workflow transport mocks; real recipient revalidation is covered by MariaDB concurrency tests.
function notification_lock_recipients(PDO $pdo,array $recipients): array { return $recipients; }

/** CLI-only workflow fixtures. Database, file, notification and AI operations are simulated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class FixtureDb extends PDO
{
    public bool $transaction = false;
    public array $events = [], $writes = [], $snapshot = [];
    public array $notifications = [], $uploadParams = [];
    public array $student;
    public ?array $document;
    public function __construct()
    {
        $this->student = ['id' => 4, 'full_name' => 'Fixture Student', 'email' => 'student@example.test',
            'adviser_id' => 2, 'stage' => $GLOBALS['case']['studentStage'] ?? 'Stage 1', 'status' => 'On Track',
            'research_title'=>'Authoritative title', 'research_group'=>'AMT-BSIT-Y2-2627-G01'];
        $this->document = ($GLOBALS['case']['action'] === 'upload' && empty($GLOBALS['case']['previous'])) ? null : $GLOBALS['template'];
    }
    public function beginTransaction(): bool
    {
        if (!empty($GLOBALS['case']['archiveDuringUpload'])) $this->student['archived_at']='2026-10-02';
        if (!empty($GLOBALS['case']['stageChanged'])) $this->student['stage'] = 'Stage 2';
        $this->snapshot = [$this->student, $this->document, $GLOBALS['audit'], $GLOBALS['history']];
        $this->events[] = 'BEGIN'; $this->transaction = true; return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function lastInsertId(?string $name = null): string|false { return (string)count($this->notifications); }
    public function commit(): bool { $this->events[] = 'COMMIT'; $this->transaction = false; return true; }
    public function rollBack(): bool
    {
        [$this->student, $this->document, $GLOBALS['audit'], $GLOBALS['history']] = $this->snapshot;
        $this->events[] = 'ROLLBACK'; $this->transaction = false; return true;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    { return new FixtureStatement($this, preg_replace('/\s+/', ' ', $query)); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    { $s = $this->prepare($query); $s->execute(); return $s; }
}
class FixtureStatement extends PDOStatement
{
    private array $bound=[];
    public function __construct(private FixtureDb $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->bound=$params??[];
        $this->db->events[] = $this->sql;
        if (str_starts_with($this->sql, 'INSERT INTO notifications')) {
            if (!$this->db->transaction) throw new RuntimeException('Notification persistence outside transaction.');
            $this->db->notifications[count($this->db->notifications) + 1] = $params;
            return true;
        }
        if (str_starts_with($this->sql, 'UPDATE notifications')) {
            if (str_contains($this->sql,'status="Sending"')) { $this->db->notifications[$params[0]][':status']='Sending';return true; }
            $this->db->notifications[$params[':id']] = array_replace($this->db->notifications[$params[':id']], $params);
            return true;
        }
        if (str_contains($this->sql, 'FOR UPDATE') && !$this->db->transaction) throw new RuntimeException('Lock outside transaction.');
        if (preg_match('/^(UPDATE|DELETE|INSERT)/', $this->sql)) {
            if (!$this->db->transaction) throw new RuntimeException('Write outside transaction.');
            $this->db->writes[] = $this->sql;
        }
        if (str_starts_with($this->sql, 'UPDATE students SET stage')) {
            if (!empty($GLOBALS['case']['failStage'])) throw new RuntimeException('Fixture stage write failure.');
            $this->db->student['stage'] = $params[':stage'];
            $this->db->student['status'] = $params[':status'];
        } elseif (str_starts_with($this->sql, 'UPDATE documents SET review_status')) {
            $this->db->document['review_status'] = $params[':s'];
            $this->db->document['review_remarks'] = $params[':r'];
            if (isset($params[':reason'])) {
                $this->db->document['admin_override'] = 1;
                $this->db->document['override_reason'] = $params[':reason'];
            }
        } elseif (str_starts_with($this->sql, 'UPDATE documents SET rpms_submitted_at')) {
            if (!empty($GLOBALS['case']['failSubmit'])) throw new RuntimeException('Fixture submission failure.');
            $this->db->document['rpms_submitted_at'] = '2026-09-25 12:00:00';
        } elseif (str_starts_with($this->sql, 'INSERT INTO documents')) {
            $this->db->uploadParams = $params;
            if (!empty($GLOBALS['case']['failInsert'])) throw new RuntimeException('Fixture insert failure.');
            $this->db->document = array_replace($GLOBALS['template'], [
                'id' => $params[':id'], 'stage' => $params[':stage'], 'version_no' => $params[':ver'],
                'supersedes_id' => $params[':prev'], 'stored_name' => $params[':stored']]);
        } elseif (str_starts_with($this->sql, 'DELETE FROM documents')) {
            if (!empty($GLOBALS['case']['failDelete'])) throw new RuntimeException('Fixture delete failure.');
            $this->db->document = null;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM documents')) return $this->db->document ?: false;
        if (str_contains($this->sql, 'FROM students')) return $this->db->student;
        throw new RuntimeException('Unexpected fixture fetch.');
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql,'GET_LOCK')||str_contains($this->sql,'RELEASE_LOCK'))return 1;
        if (str_starts_with($this->sql,'SELECT status FROM notifications'))return $this->db->notifications[$this->bound[0]][':status']??false;
        if (str_contains($this->sql, 'FROM advisers') || str_contains($this->sql, 'COALESCE(adviser_id')) return 2;
        if (str_starts_with($this->sql, 'SELECT email')) return $this->db->student['email'];
        if (str_starts_with($this->sql, 'SELECT full_name')) return $this->db->student['full_name'];
        return 4;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (!str_contains($this->sql, "role = 'admin' AND status = 'Active'")) throw new RuntimeException('Unexpected admin query.');
        return [['id' => 1, 'email' => 'admin1@example.test', 'full_name' => 'Admin One'],
            ['id' => 2, 'email' => 'admin2@example.test', 'full_name' => 'Admin Two']];
    }
    public function rowCount(): int { return 1; }
}
function db(): PDO { return $GLOBALS['fixtureDb']; }
function api_require_login($roles): array
{
    if (!in_array($GLOBALS['actor']['role'], (array)$roles, true)) json_out(['ok' => false], 403);
    return $GLOBALS['actor'];
}
function require_post_same_origin(): void { $GLOBALS['originChecks']++; }
function json_body(): array { return $GLOBALS['payload']; }
function json_out(array $body, int $status = 200): never
{ $GLOBALS['response'] = $body; $GLOBALS['status'] = $status; exit; }
function stage_label(string $stage): string { return $stage; }
function next_stage(string $stage): string
{ $index = array_search($stage, STAGE_SEQUENCE, true); return STAGE_SEQUENCE[min($index + 1, count(STAGE_SEQUENCE) - 1)]; }
function record_history(...$args): void
{
    if (!$GLOBALS['fixtureDb']->transaction) throw new RuntimeException('History outside transaction.');
    $GLOBALS['history'][] = array_slice($args, 1);
}
function audit_log($user, string $action, array $context): void { $GLOBALS['audit'][] = [$action, $context]; }
function fixture_notice(array $args): void
{
    if ($GLOBALS['fixtureDb']->transaction) throw new RuntimeException('Notification before commit.');
    $GLOBALS['notices'][] = array_slice($args, 1);
}
function notify_student(...$args): void { fixture_notice($args); }
function notify_adviser_of_student(...$args): void { fixture_notice($args); }
function send_notification_email($to, $subject, $body): array
{
    if ($GLOBALS['fixtureDb']->transaction) throw new RuntimeException('Admin email before commit.');
    $GLOBALS['fixtureDb']->events[] = 'ADMIN EMAIL';
    if (count($GLOBALS['fixtureDb']->notifications) <= count($GLOBALS['emails'])) throw new RuntimeException('Email before in-app record.');
    $GLOBALS['emails'][] = compact('to', 'subject', 'body');
    if (!empty($GLOBALS['case']['emailThrows'])) throw new RuntimeException('Fixture mail exception.');
    return ['ok' => empty($GLOBALS['case']['emailFails']), 'message' => 'Fixture delivery'];
}
function log_api_error(...$args): void { $GLOBALS['errors'][] = $args; }
function move_uploaded_file(string $from, string $to): bool { $GLOBALS['stored'] = true; return true; }
function is_file(string $path): bool { return $GLOBALS['stored']; }
function unlink(string $path): bool
{
    if ($GLOBALS['fixtureDb']->transaction) throw new RuntimeException('File removal inside transaction.');
    $GLOBALS['fixtureDb']->events[] = 'UNLINK';
    if (!empty($GLOBALS['case']['unlinkFails'])) return false;
    $GLOBALS['stored'] = false; return true;
}
class finfo
{
    public function __construct(int $mode) {}
    public function file(string $path): string { return $GLOBALS['case']['mime'] ?? 'text/plain'; }
}
function extract_document_text(string $path): string { return $GLOBALS['case']['text']??'Approved March 14, 2026.'; }
function ai_detect_approval_date(string $text): array { return \ai_detect_approval_date($text); }
require_once __DIR__.'/../ai_helpers.php';
eval('namespace { function openrouter_available() { return true; } function openrouter_generate($messages) { $GLOBALS["openrouterCalls"]++; return ["ok"=>true,"content"=>"2020-01-01"]; } }');
$GLOBALS['openrouterCalls'] = 0;

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $template = ['id' => 'fixture-document', 'student_id' => 4, 'adviser_id' => $case['documentAdviserId'] ?? 2, 'student_name' => 'Fixture Student',
        'archived_at' => !empty($case['archivedDocument']) ? '2026-10-01' : null,
        'stage' => $case['documentStage'] ?? 'Stage 1', 'is_current' => $case['current'] ?? 1,
        'review_status' => $case['beforeStatus'] ?? 'Submitted', 'review_remarks' => '', 'reviewed_by' => null,
        'reviewed_at' => null, 'rpms_submitted_at' => !empty($case['locked']) ? '2026-09-24 12:00:00' : null,
        'rpms_submitted_by' => null, 'admin_override' => 0, 'override_reason' => null, 'override_by' => null,
        'override_at' => null, 'supersedes_id' => null, 'version_no' => !empty($case['previous']) ? 3 : 1,
        'original_name' => 'Fixture.txt', 'stored_name' => 'fixture.txt', 'mime' => 'text/plain', 'size' => 10,
        'document_type' => 'Protocol', 'notes' => '', 'uploaded_by' => 'Fixture Student', 'uploaded_by_role' => 'student',
        'uploaded_at' => '2026-09-25 12:00:00', 'detected_approval_date' => '2026-09-24', 'approval_date_source' => 'regex',
        'ai_summary' => null, 'course' => 'Fixture Course', 'protocol_code' => 'FIXTURE'];
    $actor = ['id' => 1, 'role' => $case['role'] ?? 'admin', 'email' => 'student@example.test', 'full_name' => 'Fixture Actor'];
    $payload = ['id' => 'fixture-document', 'status' => $case['newStatus'] ?? 'Approved',
        'remarks' => 'Fixture review', 'reason' => array_key_exists('reason', $case) ? $case['reason'] : 'Verified fixture correction'];
    $audit = $history = $notices = $errors = $emails = [];
    $stored = $case['action'] !== 'upload'; $response = null; $status = 200; $originChecks = 0;
    $fixtureDb = new FixtureDb();
    $_GET = ['action' => $case['action']];
    $_SERVER = ['REQUEST_METHOD' => 'POST'];
    $_POST = ['studentDbId' => 4, 'documentType' => 'Protocol', 'stage' => 'Stage 1'];
    $_POST = array_replace($_POST, $case['craftedUpload'] ?? []);
    $_FILES = ['document' => ['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'Fixture.txt', 'tmp_name' => 'fixture-upload']];
    define('STAGE_ADVANCE_TRIGGER', $case['mode'] ?? 'approval');
    define('STAGE_SEQUENCE', ['Stage 1', 'Stage 2', 'Completed']);
    define('DOCS_DIR', 'fixture-storage');
    define('ADVISERS_REVIEW_ONLY_OWN_STUDENTS', true);
    define('OVERRIDE_MIN_REASON_LENGTH', 5);
    foreach (['WF_PENDING_REVIEW' => 'Pending Adviser Review', 'WF_NEEDS_REVISION' => 'Needs Revision',
        'WF_READY_FOR_RPMS' => 'Ready for Formal RPMS Submission', 'WF_SUBMITTED_RPMS' => 'Submitted to RPMS', 'WF_SUPERSEDED' => 'Superseded'] as $key => $value) define($key, $value);
    // Extract only real workflow functions; no workflow bootstrap or configuration is evaluated.
    $workflow = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../workflow.php'));
    $delivery = file_get_contents(__DIR__ . '/../includes/notification_delivery.php');
    $delivery = str_replace("require_once __DIR__.'/student_snapshot.php';",'',$delivery);
    eval('namespace ' . __NAMESPACE__ . '; use \\PDO; use \\Throwable; ' . preg_replace('/^<\?php\s*/', '', $delivery));
    foreach (['document_workflow_state', 'document_is_locked', 'override_reason_valid', 'override_reason_message', 'advance_stage_for_document', 'notify_rpms_admins', 'notify_in_app'] as $name) {
        $start = strpos($workflow, 'function ' . $name . '(');
        $end = strpos($workflow, "\n}\n", $start);
        if ($start === false || $end === false) throw new RuntimeException('Cannot isolate workflow function.');
        eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \Throwable; ' . substr($workflow, $start, $end + 2 - $start));
    }
    ob_start();
    register_shutdown_function(function () {
        $unexpected = ob_get_clean();
        echo json_encode(['response' => $GLOBALS['response'], 'status' => $GLOBALS['status'],
            'student' => $GLOBALS['fixtureDb']->student, 'document' => $GLOBALS['fixtureDb']->document,
            'events' => $GLOBALS['fixtureDb']->events, 'writes' => $GLOBALS['fixtureDb']->writes,
            'transaction' => $GLOBALS['fixtureDb']->transaction, 'audit' => $GLOBALS['audit'], 'history' => $GLOBALS['history'],
            'notices' => $GLOBALS['notices'], 'emails' => $GLOBALS['emails'], 'errors' => $GLOBALS['errors'], 'stored' => $GLOBALS['stored'],
            'notifications' => array_values($GLOBALS['fixtureDb']->notifications),
            'uploadParams' => $GLOBALS['fixtureDb']->uploadParams, 'openrouterCalls' => $GLOBALS['openrouterCalls'],
            'originChecks' => $GLOBALS['originChecks'], 'unexpected' => $unexpected]);
    });
    require_once __DIR__ . '/../includes/office_container.php';
    require_once __DIR__.'/retention-fixture-support.php';retention_fixture_support(__NAMESPACE__);
    $source = file_get_contents(__DIR__ . '/../documents_api.php');
    $source = str_replace("require_once __DIR__ . '/includes/account_lifecycle.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/office_container.php';", '', $source);
    foreach (["require __DIR__ . '/config.php';", "require_once __DIR__ . '/ai_helpers.php';", "require_once __DIR__ . '/workflow.php';"] as $require) {
        $source = str_replace($require, '', $source, $count);
        if ($count !== 1) throw new RuntimeException('Unexpected endpoint bootstrap.');
    }
    $source = str_replace("require_once __DIR__ . '/includes/pagination.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/record_filters.php';", '', $source);
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \Throwable; use \RuntimeException; ' . preg_replace('/^<\?php\s*/', '', $source));
    exit;
}
$cases = [
    ['name'=>'Crafted Student review is denied','action'=>'review','role'=>'student','expectedStatus'=>403],
    ['name'=>'Crafted unassigned Adviser review is denied','action'=>'review','role'=>'adviser','documentAdviserId'=>99,'expectedStatus'=>403],
    ['name'=>'Crafted Adviser review for archived student is denied','action'=>'review','role'=>'adviser','archivedDocument'=>true,'expectedStatus'=>403],
    ['name'=>'Crafted Adviser review for superseded version is denied','action'=>'review','role'=>'adviser','current'=>0,'expectedStatus'=>409],
    ['name'=>'Crafted Adviser review for submitted document is denied','action'=>'review','role'=>'adviser','locked'=>true,'expectedStatus'=>409],
    ['name'=>'Concurrent archival rejects Admin upload','action'=>'upload','archiveDuringUpload'=>true,'expectedStatus'=>409],
    ['name'=>'Concurrent archival rejects Adviser upload','action'=>'upload','role'=>'adviser','archiveDuringUpload'=>true,'expectedStatus'=>409],
    ['name'=>'Concurrent archival rejects Student upload','action'=>'upload','role'=>'student','archiveDuringUpload'=>true,'expectedStatus'=>409],
    ['name' => 'Student cannot invoke document summary', 'action' => 'summarize', 'role' => 'student', 'expectedStatus' => 403],
    ['name' => 'Adviser cannot invoke document summary', 'action' => 'summarize', 'role' => 'adviser', 'expectedStatus' => 403],
    ['name' => 'Failed student formal transaction sends no admin email', 'action' => 'submit_to_rpms', 'mode' => 'submission', 'beforeStatus' => 'Approved', 'role' => 'student', 'failSubmit' => true, 'expectedStatus' => 500],
    ['name' => 'Admin email failure cannot undo student submission', 'action' => 'submit_to_rpms', 'beforeStatus' => 'Approved', 'role' => 'student', 'emailFails' => true],
    ['name' => 'Admin email exception cannot undo student submission', 'action' => 'submit_to_rpms', 'beforeStatus' => 'Approved', 'role' => 'student', 'emailThrows' => true],
    ['name' => 'Override approval advances in approval mode', 'action' => 'override_review', 'advanced' => true],
    ['name' => 'Override approval waits in submission mode', 'action' => 'override_review', 'mode' => 'submission', 'advanced' => false],
    ['name' => 'Normal adviser approval advances in approval mode', 'action' => 'review', 'role' => 'adviser', 'advanced' => true],
    ['name' => 'Normal adviser approval waits in submission mode', 'action' => 'review', 'role' => 'adviser', 'mode' => 'submission', 'advanced' => false],
    ['name' => 'Formal submission advances in submission mode', 'action' => 'submit_to_rpms', 'mode' => 'submission', 'beforeStatus' => 'Approved', 'role' => 'student', 'advanced' => true],
    ['name' => 'Formal submission never repeats approval advancement', 'action' => 'submit_to_rpms', 'studentStage' => 'Stage 2', 'beforeStatus' => 'Approved', 'role' => 'student', 'advanced' => false],
    ['name' => 'Admin force-submit advances new approval in approval mode', 'action' => 'submit_to_rpms', 'role' => 'admin', 'advanced' => true],
    ['name' => 'Admin force-submit advances once in submission mode', 'action' => 'submit_to_rpms', 'role' => 'admin', 'mode' => 'submission', 'advanced' => true],
    ['name' => 'Admin force-submit old stage cannot advance newer progress', 'action' => 'submit_to_rpms', 'role' => 'admin', 'studentStage' => 'Stage 2', 'advanced' => false],
    ['name' => 'Admin approved submission does not repeat approval advancement', 'action' => 'submit_to_rpms', 'role' => 'admin', 'beforeStatus' => 'Approved', 'studentStage' => 'Stage 2', 'advanced' => false],
    ['name' => 'Admin force-submit cannot advance beyond Completed', 'action' => 'submit_to_rpms', 'role' => 'admin', 'studentStage' => 'Completed', 'documentStage' => 'Completed', 'advanced' => false],
    ['name' => 'Force-submit stage failure rolls back new approval', 'action' => 'submit_to_rpms', 'role' => 'admin', 'failStage' => true, 'expectedStatus' => 500],
    ['name' => 'Admin force-submit still requires reason', 'action' => 'submit_to_rpms', 'role' => 'admin', 'reason' => '', 'expectedStatus' => 422],
    ['name' => 'Adviser cannot force-submit an unapproved document', 'action' => 'submit_to_rpms', 'role' => 'adviser', 'expectedStatus' => 403],
    ['name' => 'Completed stage cannot advance again', 'action' => 'override_review', 'studentStage' => 'Completed', 'documentStage' => 'Completed', 'advanced' => false],
    ['name' => 'Old-stage override cannot advance newer progress', 'action' => 'override_review', 'studentStage' => 'Stage 2', 'advanced' => false],
    ['name' => 'Denied override cannot advance', 'action' => 'override_review', 'newStatus' => 'Denied', 'advanced' => false],
    ['name' => 'Override still requires reason', 'action' => 'override_review', 'reason' => '', 'expectedStatus' => 422],
    ['name' => 'Adviser cannot override', 'action' => 'override_review', 'role' => 'adviser', 'expectedStatus' => 403],
    ['name' => 'Locked document cannot be overridden', 'action' => 'override_review', 'locked' => true, 'expectedStatus' => 409],
    ['name' => 'Old version cannot be overridden', 'action' => 'override_review', 'current' => 0, 'expectedStatus' => 409],
    ['name' => 'Stage failure rolls back approval and audit', 'action' => 'override_review', 'failStage' => true, 'expectedStatus' => 500],
    ['name' => 'Student upload retains its stage', 'action' => 'upload', 'role' => 'student', 'version' => 1],
    ['name'=>'Student upload ignores forged institutional/workflow metadata','action'=>'upload','role'=>'student','version'=>1,
      'craftedUpload'=>['studentDbId'=>999,'studentId'=>'CHANGED','name'=>'Fake','researchTitle'=>'Forged','researchGroup'=>'Other Group','adviserId'=>999,'stage'=>'Completed','reviewStatus'=>'Approved','protocolCode'=>'FAKE','uploadedAt'=>'1990-01-01','rpmsSubmittedAt'=>'1990-01-01','documentId'=>'forged-id','notes'=>'Editable reviewer note','documentType'=>'Research Protocol']],
    ['name' => 'Stage change rejects student upload and removes file', 'action' => 'upload', 'role' => 'student', 'stageChanged' => true, 'expectedStatus' => 409],
    ['name'=>'Adviser upload uses local detection','action'=>'upload','role'=>'adviser','version'=>1],
    ['name'=>'Upload succeeds without detected date','action'=>'upload','role'=>'student','version'=>1,'text'=>'No date'],
    ['name' => 'Admin upload keeps explicitly selected stage', 'action' => 'upload', 'stageChanged' => true, 'version' => 1],
    ['name' => 'New version uses locked predecessor', 'action' => 'upload', 'role' => 'student', 'previous' => true, 'version' => 4],
    ['name' => 'Failed upload insert removes stored file', 'action' => 'upload', 'role' => 'student', 'failInsert' => true, 'expectedStatus' => 500],
    ['name' => 'MIME rejection removes stored file', 'action' => 'upload', 'role' => 'student', 'mime' => 'text/html', 'expectedStatus' => 415],
    ['name' => 'Upload cleanup failure is logged', 'action' => 'upload', 'role' => 'student', 'stageChanged' => true, 'unlinkFails' => true, 'expectedStatus' => 409, 'cleanupLog' => true],
    ['name' => 'Delete removes file only after commit', 'action' => 'delete'],
    ['name' => 'Delete cleanup failure is logged after commit', 'action' => 'delete', 'unlinkFails' => true, 'cleanupLog' => true],
    ['name' => 'Database delete failure retains file', 'action' => 'delete', 'failDelete' => true, 'expectedStatus' => 500],
];
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $r = json_decode($output, true); $expected = $case['expectedStatus'] ?? 200;
    $pass = $exit === 0 && $error === '' && is_array($r) && !$r['transaction']
        && $r['unexpected'] === '' && $r['status'] === $expected && $r['originChecks'] === ($case['action'] === 'summarize' ? 0 : 1);
    if ($pass && $expected !== 200) $pass = ($case['action'] === 'summarize'
        ? count($r['audit']) === 1 && $r['audit'][0][0] === 'document_summary_failed' : !$r['audit'])
        && !$r['history'] && !$r['notices'] && !in_array('COMMIT', $r['events'], true);
    if ($pass && array_key_exists('advanced', $case)) {
        $pass = $r['response']['stageAdvanced'] === $case['advanced']
            && $r['student']['stage'] === ($case['advanced'] ? 'Stage 2' : ($case['studentStage'] ?? 'Stage 1'));
        $advances = array_values(array_filter($r['audit'], fn($a) => $a[0] === 'stage_auto_advanced'));
        $pass = $pass && count($advances) === ($case['advanced'] ? 1 : 0);
    }
    if ($pass && $case['action'] === 'override_review' && $expected === 200) {
        $overrides = array_values(array_filter($r['audit'], fn($a) => $a[0] === 'admin_override_document'));
        $pass = count($overrides) === 1 && $overrides[0][1]['reason'] === 'Verified fixture correction';
        if (!empty($case['advanced'])) $pass = $pass && count($r['history']) === 1 && str_contains(json_encode($r['notices']), 'Stage 2');
    }
    if ($pass && !empty($case['failStage'])) $pass = $r['document']['review_status'] === 'Submitted' && $r['student']['stage'] === 'Stage 1';
    if ($pass && $case['action'] === 'upload') {
        $pass = $r['openrouterCalls'] === 0 && $r['stored'] === ($expected === 200 || !empty($case['unlinkFails']));
        if ($expected === 200) $pass = $pass && $r['uploadParams'][':adate'] === (isset($case['text']) ? null : '2026-03-14') && $r['uploadParams'][':asrc'] === (isset($case['text']) ? null : 'regex');
        if ($expected === 200) $pass = $pass && $r['document']['stage'] === 'Stage 1' && $r['response']['versionNo'] === $case['version'];
        else $pass = $pass && $r['document'] === null;
        if (!empty($case['craftedUpload']) && $expected === 200) {
            $p=$r['uploadParams']; $pass=$pass && $p[':sid']===4 && $p[':sname']==='Fixture Student'
              && $p[':type']==='Research Protocol' && $p[':review']==='Submitted'
              && $p[':notes']==='Research title: Authoritative title | Group: AMT-BSIT-Y2-2627-G01 | Reviewer note: Editable reviewer note'
              && $p[':id']!=='forged-id' && $r['student']['adviser_id']===2;
        }
        if (!empty($case['stageChanged'])) $pass = $pass && $r['student']['stage'] === 'Stage 2';
        if (!empty($case['previous'])) $pass = $pass && $r['document']['supersedes_id'] === 'fixture-document';
    }
    if ($pass && $case['action'] === 'delete') {
        $pass = $r['stored'] === (!empty($case['unlinkFails']) || $expected !== 200);
        if ($expected === 200) $pass = $pass && $r['document'] === null && array_search('COMMIT', $r['events'], true) < array_search('UNLINK', $r['events'], true);
        else $pass = $pass && $r['document'] !== null && !in_array('UNLINK', $r['events'], true);
    }
    if ($pass && !empty($case['cleanupLog'])) $pass = count(array_filter($r['errors'], fn($e) => $e[0] === 'document_file_cleanup')) === 1;
    if ($pass && $case['action'] === 'submit_to_rpms') {
        $studentSuccess = $expected === 200 && ($case['role'] ?? 'admin') === 'student';
        $pass = count($r['emails']) === ($studentSuccess ? 2 : 0);
        if ($studentSuccess) {
            $pass = $pass && count($r['notices']) === 2 && count($r['notifications']) === 2 && $r['document']['rpms_submitted_at'] !== null
                && array_column($r['emails'], 'to') === ['admin1@example.test', 'admin2@example.test']
                && array_search('COMMIT', $r['events'], true) < array_search('ADMIN EMAIL', $r['events'], true)
                && $r['emails'][0]['subject'] === 'PRISM - New Formal RPMS Submission'
                && str_contains($r['emails'][0]['body'], 'Fixture.txt') && str_contains($r['emails'][0]['body'], 'Protocol')
                && str_contains($r['notices'][0][1], 'Formal RPMS Submission Confirmation');
            $deliveryStatus = !empty($case['emailFails']) || !empty($case['emailThrows']) ? 'Failed' : 'Sent';
            $pass = $pass && array_column($r['notifications'], ':status') === [$deliveryStatus, $deliveryStatus];
        }
    }
    if (!$pass) { fwrite(STDERR, "FAIL: {$case['name']}\n$error$output\n"); exit(1); }
    echo "PASS: {$case['name']}\n";
}
echo count($cases) . " document workflow cases passed.\n";
