<?php
namespace PrismGroupAudit;
use PDO;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;
// Recipient grouping mock; real recipient locks are covered by disposable MariaDB tests.
function notification_lock_recipients(PDO $pdo,array $recipients): array { return $recipients; }

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/research_groups.php';

class GroupDb extends PDO
{
    public array $rows = [], $writes = [];
    public function __construct() {}
    public function lastInsertId(?string $name = null): string|false { return (string)count($this->writes); }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function inTransaction(): bool { return false; }
    public function rollBack(): bool { return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new GroupStatement($this, $query); }
}
class GroupStatement extends PDOStatement
{
    public function rowCount(): int { return 1; }
    private array $params = [];
    public function __construct(private GroupDb $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->sql, 'INSERT INTO notifications')) $this->db->writes[] = $params;
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return str_contains($this->sql, 'SELECT v FROM schema_meta') ? 0 : 1; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = array_values(array_filter($this->db->rows, function ($r) {
            $owner = $this->params[':adviser'] ?? $this->params[':adv'] ?? null;
            if ($owner !== null && $r['adviser_email'] !== $owner) return false;
            if (isset($this->params[':g'])) {
                if (!str_contains($this->sql, 'BINARY')) throw new RuntimeException('Recipient equality must be canonical.');
                if ($r['research_group'] !== $this->params[':g']) return false;
            }
            return true;
        }));
        return $mode === PDO::FETCH_COLUMN ? array_column($rows, 'research_group') : $rows;
    }
}
function fixture_row(string $group, array $academic, int $id = 1, string $owner = 'own@example.test'): array
{
    return $academic + ['research_group' => $group, 'id' => $id, 'full_name' => 'Student ' . $id,
        'email' => "student$id@example.test", 'adviser_email' => $owner, 'adviser_id'=>1];
}
function check(bool $ok, string $label): void
{
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException($label);
}
function rejects(callable $call): bool
{
    try { $call(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
function db(): PDO { return $GLOBALS['pdo']; }
function api_require_login($roles): array { return $GLOBALS['actor']; }
function require_post_same_origin(): void {}
function json_body(): array { return $GLOBALS['payload']; }
function json_out(array $body, int $status = 200): never
{
    echo json_encode(['status' => $status, 'body' => $body, 'writes' => $GLOBALS['pdo']->writes]); exit;
}
function send_notification_email(...$args): array { return ['ok' => true, 'channel' => 'fixture', 'message' => 'Fixture']; }
function log_activity(...$args): void {}
function audit_log(...$args): void {}

$checks = 0;
$a = academic_validate(['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '4th Year', 'academicYear' => '2026-2027']);
$pdo = new GroupDb();
$admin = ['role' => 'admin', 'email' => 'admin@example.test', 'full_name' => 'Admin'];
$adviser = ['role' => 'adviser', 'email' => 'own@example.test', 'full_name' => 'Adviser'];
$g1 = 'AMT-BSIT-Y4-2627-G01'; $g2 = 'AMT-BSIT-Y4-2627-G02';
$pdo->rows = [fixture_row($g1, $a), fixture_row($g2, $a, 2, 'other@example.test'), fixture_row('Legacy group', $a, 3), fixture_row(strtolower($g1), $a, 4)];
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $actor = $case['role'] === 'admin' ? $admin : $adviser;
    $payload = ['audience' => 'Specific Research Group', 'group' => $case['group'], 'message' => 'Fixture notice'];
    $_GET = ['action' => $case['action']];
    $source = file_get_contents(__DIR__ . '/../notifications_api.php');
    $source = str_replace("require_once __DIR__ . '/workflow.php';", '', $source);
    $delivery = file_get_contents(__DIR__ . '/../includes/notification_delivery.php');
    $delivery = str_replace("require_once __DIR__.'/student_snapshot.php';",'',$delivery);
    eval('namespace ' . __NAMESPACE__ . '; use \\PDO; use \\Throwable; ' . preg_replace('/^<\?php\s*/', '', $delivery));
    foreach (["require __DIR__ . '/config.php';", "require_once __DIR__ . '/includes/research_groups.php';", "require_once __DIR__ . '/includes/notification_delivery.php';"] as $require) $source = str_replace($require, '', $source);
    eval('namespace ' . __NAMESPACE__ . '; use \\PDO; ' . preg_replace('/^<\?php\s*/', '', $source));
    exit;
}
$pdo->rows = [];
check(research_group_assignment($pdo, $admin, ['group' => '__create__'], $a) === $g1, 'First deterministic undergraduate group');
$pdo->rows = [fixture_row($g1, $a), fixture_row($g2, $a, 2, 'other@example.test')];
check(research_group_assignment($pdo, $adviser, ['group' => '__create__'], $a) === 'AMT-BSIT-Y4-2627-G03', 'Institution-wide sequence without exposing other adviser groups');
check(research_group_assignment($pdo, $admin, ['group' => $g1], $a) === $g1, 'Existing assignment retains ID');
check(research_group_options($pdo, $admin, $a) === [$g1, $g2], 'Admin sees all represented groups');
check(research_group_options($pdo, $adviser, $a) === [$g1], 'Adviser only sees own groups');
check(rejects(fn() => research_group_assignment($pdo, $adviser, ['group' => $g2], $a)), 'Cannot assign another adviser group');
foreach (['FREE TEXT', 'AMT-BSIT-Y4-2627-G99', strtolower($g1), '__keep__'] as $bad) {
    check(rejects(fn() => research_group_assignment($pdo, $admin, ['group' => $bad], $a)), 'Arbitrary groups rejected');
}
foreach ([['programKey' => 'bsa'], ['yearLevel' => '3rd Year'], ['academicYear' => '2027-2028'], ['academicUnitKey' => 'nursing', 'programKey' => 'bsn']] as $change) {
    $cohort = academic_validate($change, $a);
    check(research_group_options($pdo, $admin, $cohort) === [], 'Different cohort has no compatible options');
    check(str_ends_with(research_group_assignment($pdo, $admin, ['group' => '__create__'], $cohort), '-G01'), 'Different cohort starts separate sequence');
    check(rejects(fn() => research_group_assignment($pdo, $admin, ['group' => $g1], $cohort)), 'Cannot assign incompatible cohort');
}
$grad = academic_validate(['academicUnitKey' => null, 'programKey' => 'mba_thesis', 'yearLevel' => null, 'academicYear' => '2026-2027']);
check(research_group_assignment($pdo, $admin, ['group' => '__create__'], $grad) === 'GRAD-MBA-THESIS-2627-G01', 'Graduate format omits year level');
$pdo->rows[] = fixture_row('GRAD-MBA-THESIS-2627-G01', $grad, 5);
check(research_group_options($pdo, $admin, $grad) === ['GRAD-MBA-THESIS-2627-G01'], 'Graduate filtering');
$legacy = ['research_group' => '  Legacy Group  ', 'course' => 'Legacy degree'];
foreach ([[], ['group' => ''], ['group' => 'Legacy Group'], ['group' => '__keep__']] as $input) {
    check(research_group_assignment($pdo, $admin, $input, $a, $legacy) === '  Legacy Group  ', 'Legacy stored value preserved exactly');
}
check(research_group_assignment($pdo, $admin, ['group' => $g1], $a, $legacy) === $g1, 'Explicit legacy reassignment');
foreach (academic_catalog()['programs'] as $key => $program) {
    $cohort = academic_validate(['academicUnitKey' => $program['unit'], 'programKey' => $key, 'yearLevel' => $program['duration'] ? '1st Year' : null, 'academicYear' => '2026-2027']);
    check(strlen(research_group_assignment($pdo, $admin, ['group' => '__create__'], $cohort)) <= 190, 'Catalog group fits existing field');
}
foreach (['admin', 'adviser'] as $role) {
    foreach (['group_options', 'recipients_preview', 'send'] as $action) {
        foreach ([$g1, $g2, strtolower($g1), 'Legacy group', 'Missing'] as $group) {
            $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode(compact('role', 'action', 'group'))], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $r = json_decode($out, true);
            check($exit === 0 && $err === '' && is_array($r), 'Isolated notification endpoint executes: ' . $err . $out);
            if ($action === 'group_options') {
                check($r['body']['groups'] === ($role === 'admin' ? [$g1, $g2] : [$g1]), 'Scoped notification dropdown');
            } else {
                $allowed = $group === $g1 || ($role === 'admin' && $group === $g2);
                check($r['status'] === ($allowed ? 200 : 422), 'Notification validates canonical group and scope');
                if ($allowed && $action === 'recipients_preview') check(array_column($r['body']['recipients'], 'id') === [$group === $g1 ? 1 : 2], 'Exact recipient preview');
                if ($allowed && $action === 'send') check(array_column($r['writes'], ':rid') === [$group === $g1 ? 1 : 2], 'Send uses exact preview recipients');
                if (!$allowed) check(!$r['writes'], 'Rejected group never sends');
            }
        }
    }
}
foreach (['students_api.php', 'ierb_api.php'] as $file) {
    foreach ([['group' => 'Arbitrary group'], ['groupId' => 'Arbitrary group'], ['group' => '__create__']] as $academicInput) {
        $academicInput += ['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '4th Year', 'academicYear' => '2026-2027'];
        $case = ['file' => $file, 'create' => true, 'academicInput' => $academicInput, 'delivery' => ['ok' => false, 'channel' => 'none']];
        $process = proc_open([PHP_BINARY, __DIR__ . '/crud-audit.php', '--case', json_encode($case)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $r = json_decode($out, true);
        check($exit === 0 && $err === '' && is_array($r), 'Group save endpoint executes');
        $create = ($academicInput['group'] ?? '') === '__create__';
        check($r['status'] === ($create ? 200 : 422), 'Both student record endpoints enforce canonical group creation');
        $studentWrites = array_values(array_filter($r['writes'], fn($write) => str_starts_with($write['sql'], 'INSERT INTO students') || str_starts_with($write['sql'], 'UPDATE students')));
        check($create ? $studentWrites[0]['params'][':grp'] === $g1 : !$r['writes'], 'Server generates ID or rejects before write');
    }
}
foreach (['students_api.php', 'ierb_api.php'] as $file) {
    foreach ([[], ['groupId' => ''], ['groupId' => '   '], ['groupId' => null]] as $selection) {
        foreach ([true, false] as $create) {
            $case = compact('file', 'create') + ['omitGroup' => true,
                'academicInput' => $selection + ['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '4th Year', 'academicYear' => '2026-2027'],
                'storedAcademic' => ['research_group' => 'Legacy group'], 'delivery' => ['ok' => false, 'channel' => 'none']];
            $process = proc_open([PHP_BINARY, __DIR__ . '/crud-audit.php', '--case', json_encode($case)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $r = json_decode($out, true);
            check($exit === 0 && $err === '' && is_array($r), 'Required-group endpoint fixture executes');
            $required = $file === 'ierb_api.php' && $create;
            check($r['status'] === ($required ? 422 : 200), 'Only new IERB records require a nonempty group');
            check($required ? (!$r['writes'] && !$r['effects'] && !$r['commits'] && !$r['transaction'])
                : $r['writes'][0]['params'][':grp'] === ($create ? '' : 'Legacy group'),
                'Reject missing groups before writes; preserve legacy edits and optional Student Management groups');
        }
    }
}
echo "PASS: $checks research-group and notification checks; no live services.\n";
