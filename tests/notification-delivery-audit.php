<?php
/** CLI-only regressions using an in-memory DB and fake transport; never loads config or sends mail. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class NotificationFixturePDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}

function db(): PDO { return $GLOBALS['pdo']; }
function log_api_error(...$args): void { $GLOBALS['errors'][] = $args; }
function log_activity(...$args): void {}
function stage_label(string $stage): string { return $stage; }
function api_require_login(array $roles): array { return ['role' => 'admin', 'full_name' => 'Admin', 'email' => 'admin@example.test']; }
function require_post_same_origin(): void {}
function json_body(): array { return $GLOBALS['payload']; }
function send_notification_email(string $to, string $subject, string $body): array
{
    if (db()->inTransaction()) throw new LogicException('Email must follow persistence commit.');
    $rows = db()->query('SELECT * FROM notifications')->fetchAll();
    if (!$rows || count($rows) <= count($GLOBALS['emails'])) throw new LogicException('Email attempted before record persisted.');
    $GLOBALS['emails'][] = compact('to', 'subject', 'body');
    if (!empty($GLOBALS['overlap'])) {
        $GLOBALS['overlap'] = false;
        $GLOBALS['overlapOutput'] = run_worker();
    }
    if ($GLOBALS['mode'] === 'throw') throw new RuntimeException('Fixture transport exception');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'A valid recipient email is required.'];
    return ['ok' => $GLOBALS['mode'] !== 'fail', 'channel' => $GLOBALS['mode'], 'message' => 'Fixture ' . $GLOBALS['mode']];
}
function isolated_source(string $file): string
{
    $source = file_get_contents(__DIR__ . '/../' . $file);
    foreach (["require __DIR__ . '/config.php';", "require dirname(__DIR__) . '/config.php';",
        "require_once __DIR__ . '/includes/notification_delivery.php';",
        "require_once dirname(__DIR__) . '/includes/notification_delivery.php';",
        "require_once __DIR__ . '/includes/research_groups.php';", "require_once __DIR__ . '/workflow.php';"] as $include) {
        $source = str_replace($include, '', $source);
    }
    if (preg_match('/\b(?:require|include)(?:_once)?\s/', $source)) throw new RuntimeException('Unexpected include in fixture');
    return preg_replace('/^<\?php\s*/', '', $source);
}
function run_worker(): string
{
    ob_start();
    eval(isolated_source('tools/process_scheduled_notifications.php'));
    return ob_get_clean();
}
function json_out(array $body, int $status = 200): never
{
    $before = db()->query('SELECT * FROM notifications')->fetchAll();
    $early = count($GLOBALS['emails']);
    $worker = $rerun = '';
    if (!empty($GLOBALS['payload']['automated'])) {
        $futureRun = run_worker();
        if ($futureRun !== "Processed: 0; sent: 0; logged: 0; failed: 0\n") throw new RuntimeException('Future schedule processed early');
        db()->exec("UPDATE notifications SET scheduled_at = '2000-01-01 00:00:00'");
        $GLOBALS['overlap'] = true;
        $worker = run_worker();
        $rerun = run_worker();
    }
    echo json_encode(compact('body', 'status', 'before', 'early', 'worker', 'rerun') + [
        'rows' => db()->query('SELECT * FROM notifications')->fetchAll(), 'emails' => $GLOBALS['emails'],
        'overlap' => $GLOBALS['overlapOutput'] ?? null]);
    exit;
}
function reset_fixture(): void
{
    $GLOBALS['pdo'] = new NotificationFixturePDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    db()->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    db()->sqliteCreateFunction('GET_LOCK', fn($name,$timeout) => 1);
    db()->sqliteCreateFunction('RELEASE_LOCK', fn($name) => 1);
    db()->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_type TEXT, recipient_id INTEGER,
        recipient_email TEXT, recipient_name TEXT, subject TEXT, message TEXT, type TEXT, status TEXT, delivery_info TEXT,
        scheduled_at TEXT, sent_at TEXT, created_by TEXT, sending_started_at TEXT)');
    db()->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, student_id TEXT, email TEXT, full_name TEXT, adviser_id INTEGER,
        stage TEXT, status TEXT, requirements TEXT, research_group TEXT)');
    db()->exec('CREATE TABLE advisers (id INTEGER PRIMARY KEY, email TEXT, full_name TEXT, status TEXT)');
    db()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, full_name TEXT, role TEXT, status TEXT)');
    db()->exec("INSERT INTO students VALUES (1, 'S1', 'student@example.test', 'Student', 2, 'Stage 1', 'On Track', '', '')");
    db()->exec("INSERT INTO advisers VALUES (2, 'adviser@example.test', 'Adviser', 'Active')");
    db()->exec("INSERT INTO users VALUES (3, 'admin@example.test', 'Admin', 'admin', 'Active'),
        (4, 'inactive@example.test', 'Inactive', 'admin', 'Inactive'), (5, 'other@example.test', 'Other', 'student', 'Active')");
    db()->exec('ALTER TABLE students ADD COLUMN archived_at TEXT');
    foreach(['students','advisers'] as $table) db()->exec("ALTER TABLE $table ADD COLUMN profile_completed_at TEXT DEFAULT '2026-01-01 00:00:00'");
    db()->exec('ALTER TABLE advisers ADD COLUMN archived_at TEXT');
    $GLOBALS['emails'] = $GLOBALS['errors'] = [];
    $GLOBALS['mode'] = 'gmail_api';
    $GLOBALS['overlap'] = false;
}
require_once __DIR__ . '/../workflow.php'; // db() stub prevents configuration bootstrap.
reset_fixture();

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['mode'] = $case['mode'];
    if (isset($case['email'])) {
        $table = ($case['audience'] ?? '') === 'All Advisers' ? 'advisers' : 'students';
        db()->prepare("UPDATE $table SET email = ?")->execute([$case['email']]);
    }
    $GLOBALS['payload'] = ['studentDbId' => 1, 'audience' => $case['audience'] ?? 'All Students', 'message' => 'Manual notice',
        'automated' => $case['scheduled'] ?? false, 'scheduleAt' => date('Y-m-d H:i:s', time() + 3600)];
    $_GET = ['action' => 'send'];
    eval(isolated_source($case['file']));
    exit;
}

$checks = 0;
function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($label);
}
foreach (['student', 'adviser', 'admin'] as $role) {
    foreach (['gmail_api', 'mail', 'log', 'fail', 'throw', 'invalid', 'missing'] as $mode) {
        reset_fixture();
        $GLOBALS['mode'] = $mode;
        if (in_array($mode, ['invalid', 'missing'], true)) {
            $table = ['student' => 'students', 'adviser' => 'advisers', 'admin' => 'users'][$role];
            db()->prepare("UPDATE $table SET email = ?")->execute([$mode === 'invalid' ? 'bad-address' : null]);
        }
        db()->beginTransaction();
        db()->exec("UPDATE students SET status = 'Delayed'");
        db()->commit();
        if ($role === 'student') notify_student(db(), 1, 'Subject', 'Long email body', 'Short in-app message', 'Reminder', 'Actor');
        if ($role === 'adviser') notify_adviser_of_student(db(), 1, 'Subject', 'Adviser message', 'Reminder', 'Actor');
        if ($role === 'admin') notify_rpms_admins(db(), 'Subject', 'Admin message', 'Reminder', 'Actor');
        check(db()->query('SELECT status FROM students')->fetchColumn() === 'Delayed', "$role/$mode workflow commits");
        $rows = db()->query('SELECT * FROM notifications')->fetchAll();
        // Existing adviser lookup deliberately skips recipients with no adviser email; preserve that scope.
        if ($role === 'adviser' && $mode === 'missing') {
            check(!$rows && !$GLOBALS['emails'], 'Missing adviser email retains existing recipient scoping');
            continue;
        }
        check(count($rows) === 1 && count($GLOBALS['emails']) === 1, "$role/$mode one record and one attempt");
        $expected = in_array($mode, ['fail', 'throw', 'invalid', 'missing'], true) ? 'Failed' : ($mode === 'log' ? 'Logged' : 'Sent');
        check($rows[0]['status'] === $expected && $rows[0]['delivery_info'] !== '' && $rows[0]['sent_at'] !== null, "$role/$mode status and details");
        check($rows[0]['recipient_type'] === $role, "$role recipient preserved");
        if ($role === 'student') check($rows[0]['message'] === 'Short in-app message' && $GLOBALS['emails'][0]['body'] === 'Long email body', 'Student bodies preserved');
    }
}
reset_fixture();
db()->beginTransaction();
notify_student(db(),1,'Subject','Deferred body','Deferred message','Reminder','Actor');
check(!$GLOBALS['emails'],'Caller-owned transaction never performs external delivery');
check(db()->query('SELECT status FROM notifications')->fetchColumn()==='Scheduled','Caller-owned delivery is queued');
db()->commit();
run_worker();
check(count($GLOBALS['emails'])===1,'Queued delivery runs after commit');
reset_fixture();
notify_student(db(), 999, 'Subject', 'Body', 'Message', 'Reminder', 'Actor');
notify_adviser_of_student(db(), 999, 'Subject', 'Message', 'Reminder', 'Actor');
check(!db()->query('SELECT * FROM notifications')->fetchAll() && !$GLOBALS['emails'], 'Nonexistent students do not create events');

foreach ([['notifications_api.php', 'All Students'], ['notifications_api.php', 'All Advisers'],
    ['send_followup.php', 'All Students']] as [$file, $audience]) {
    foreach (['gmail_api', 'log', 'fail', 'throw', 'invalid', 'missing'] as $mode) {
        foreach ($file === 'notifications_api.php' ? [false, true] : [false] as $scheduled) {
            $case = compact('file', 'mode', 'scheduled', 'audience');
            if (in_array($mode, ['invalid', 'missing'], true)) $case['email'] = $mode === 'invalid' ? 'bad-address' : '';
            $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            check($exit === 0 && $err === '', "$file/$mode fixture executes: $err$out");
            $r = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            check($r['status'] === 200 && $r['body']['ok'], "$file/$mode succeeds even when email fails");
            check(count($r['rows']) === 1 && count($r['emails']) === 1, "$file/$mode exactly one row and email");
            check($r['rows'][0]['recipient_type'] === ($audience === 'All Advisers' ? 'adviser' : 'student'), 'Manual recipient type preserved');
            $expected = in_array($mode, ['fail', 'throw', 'invalid', 'missing'], true) ? 'Failed' : ($mode === 'log' ? 'Logged' : 'Sent');
            check($r['rows'][0]['status'] === $expected && $r['rows'][0]['delivery_info'] !== '', "$file/$mode final delivery details");
            if ($scheduled) {
                check($r['early'] === 0 && $r['before'][0]['status'] === 'Scheduled' && $r['before'][0]['sent_at'] === null
                    && $r['before'][0]['delivery_info'] === null, 'Scheduled record sends no premature email');
                check($r['rerun'] === "Processed: 0; sent: 0; logged: 0; failed: 0\n" && $r['overlap'] === $r['rerun'], 'Reruns and overlapping claims do not duplicate mail');
                check(($r['rows'][0]['sent_at'] === null) === ($expected === 'Failed'), 'Worker timestamp semantics preserved');
            } elseif ($file === 'notifications_api.php') {
                check($r['body']['sent'] === (int)($expected === 'Sent') && $r['body']['logged'] === (int)($expected === 'Logged'), 'Immediate response counts preserved');
            }
        }
    }
}
echo "PASS: $checks notification delivery checks; in-memory database, no live services.\n";
