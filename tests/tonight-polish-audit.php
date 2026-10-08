<?php
namespace PrismPolishAudit;
use RuntimeException;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function current_user(): array { return $GLOBALS['fixtureUser']; }
function header(string $value): void { $GLOBALS['headers'][] = $value; }
function session_regenerate_id(bool $delete): bool { $GLOBALS['regenerated'] = $delete; return true; }
function require_post_same_origin(): void {}
function consume_auth_attempt(...$args): bool { return true; }
function too_many_recent_failures(string $email): bool { return false; }
function log_activity(...$args): void {}
function db(): object { return new class {
    function prepare(string $sql): object { return new class {
        function execute(array $params): void {}
        function fetch(): array { return $GLOBALS['fixtureUser']; }
    }; }
}; }
if (($argv[1] ?? '') === '--login') {
    $case = json_decode($argv[2], true);
    $fixtureUser = ['id' => 1, 'role' => $case['role'], 'status' => 'Active', 'full_name' => 'Fixture',
        'email' => 'fixture@example.test', 'username' => 'fixture', 'ref_id' => 'S1',
        'password_hash' => password_hash('fixture-password', PASSWORD_DEFAULT), 'must_change_password' => $case['mustChange']];
    $_POST = ['email' => 'fixture@example.test', 'password' => $case['badPassword'] ? 'incorrect' : 'fixture-password'];
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SESSION = []; $headers = []; $regenerated = false;
    register_shutdown_function(function () { echo json_encode(['headers' => $GLOBALS['headers'], 'session' => $_SESSION, 'regenerated' => $GLOBALS['regenerated']]); });
    $source = file_get_contents(__DIR__ . '/../' . $case['file']);
    $source = str_replace("require __DIR__ . '/config.php';", '', $source);
    eval('namespace ' . __NAMESPACE__ . '; ' . preg_replace('/^<\?php\s*/', '', $source));
    exit;
}
$checks = 0;
function check(bool $condition, string $label): void {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($label);
}
foreach (['admin' => 'dashboard.php', 'adviser' => 'ierbprog.php', 'student' => 'student.php'] as $role => $destination) {
    foreach (['login_process.php', 'login.php', 'index.php'] as $file) {
        foreach ([0, 1] as $mustChange) {
            foreach ([false, true] as $badPassword) {
                $p = proc_open([PHP_BINARY, __FILE__, '--login', json_encode(compact('role','file','mustChange','badPassword'))], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
                fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
                $r = json_decode($out,true);
                check($exit === 0 && $err === '' && is_array($r), 'Isolated login endpoint executes: ' . $err);
                $failed = $file === 'login_process.php' && $badPassword;
                check($r['headers'] === ['Location: ' . ($file === 'index.php' ? $destination : ($failed ? 'login.php' : 'index.php'))], 'Correct direct role routing or failed-login return');
                if ($file === 'login_process.php') {
                    check($failed ? !isset($r['session']['user_id']) : ($r['session']['must_change_password'] === $mustChange && $r['regenerated']), 'Session regeneration and password-change flag preserved');
                }
            }
        }
    }
}
foreach (['dashboard.php','reports.php','assets/js/reports.js','role_portal.php','research_adviser.php'] as $file) {
    $s = file_get_contents(__DIR__ . '/../' . $file);
    check(!preg_match('/Document Summarizer|Summarize Document|summaryModal|action=summarize|data-summary|documentReportForm/', $s), 'No document summary entry points: ' . $file);
}
$reports = file_get_contents(__DIR__ . '/../reports_api.php');
check(!str_contains($reports, "'Document Summary'"), 'New document summary report type removed');
check(str_contains($reports, "if (\$action === 'ai_report')") && str_contains($reports, 'openrouter_generate('), 'Aggregate AI report endpoint retained');
$docs = file_get_contents(__DIR__ . '/../documents_api.php');
check(str_contains($docs, "\$action === 'summarize' && \$user['role'] !== 'admin'") && str_contains($docs, 'Only RPMS Administrators'), 'Document summary API enforces Admin-only access');
check(str_contains($docs, 'SET ai_summary = :summary WHERE id = :id') && str_contains($docs, 'extract_document_text(') && str_contains($docs, 'ai_detect_approval_date('), 'Summary-only writes restored; lightweight approval extraction retained');
foreach (['login.php','login_process.php','index.php','login_admin.php','login_adviser.php','login_students.php'] as $file) {
    check(!str_contains(file_get_contents(__DIR__ . '/../' . $file), 'loading.php'), 'Normal login flow bypasses loading page');
}
echo "PASS: $checks login and Admin-only document-summary boundary checks; no live services.\n";
