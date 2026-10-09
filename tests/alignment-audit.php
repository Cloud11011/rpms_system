<?php
namespace PrismAlignmentAudit;
use PDO;
use RuntimeException;
require_once __DIR__.'/../includes/student_snapshot.php';
class SnapshotFixturePDO extends PDO {
    public function prepare(string $query,array $options=[]): \PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}

/** Isolated endpoint checks: real in-memory SQL and real role guards, no config/runtime storage/network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function db(): PDO { $GLOBALS['dbCalls']++; return $GLOBALS['pdo']; }
function current_user(): array { return $GLOBALS['actor']; }
function http_response_code(?int $code = null): int { if ($code !== null) $GLOBALS['status'] = $code; return $GLOBALS['status']; }
function header(string $value): void { $GLOBALS['headers'][] = $value; }
function header_remove(string $value): void {}
function json_body(): array { return $GLOBALS['case']['data'] ?? []; }
function json_out(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data); exit; }
function require_post_same_origin(): void { $GLOBALS['originChecks']++; }
function stage_label(string $stage):string{return stage_labels_map()[$stage]??$stage;}
function stage_labels_map(): array { return array_combine(STAGE_SEQUENCE,STAGE_SEQUENCE); }
function audit_action_label(string $code): string { return $code; }
function log_activity(...$args): void {}
function audit_log(...$args): void {}
function openrouter_generate(...$args): ?string { if($GLOBALS['pdo']->inTransaction()) throw new RuntimeException('Provider called inside persistence transaction'); $GLOBALS['aiCalls']++; return empty($GLOBALS['case']['fallback']) ? '# Fixture narrative' : null; }
function file_put_contents(string $path, string $data, int $flags = 0): int { $GLOBALS['pdf'] = $data; return strlen($data); }
function is_file(string $path): bool { return true; }
function filesize(string $path): int { return 16; }
function readfile(string $path): int { $GLOBALS['fileReads']++; echo '%PDF-fixture'; return 12; }
function unlink(string $path): bool { $GLOBALS['fileDeletes']++; return true; }

function source_function(string $source, string $name): string
{
    $source = str_replace("\r\n", "\n", $source);
    $start = strpos($source, 'function ' . $name . '(');
    $end = $start === false ? false : strpos($source, "\n}\n", $start);
    if ($end === false) throw new RuntimeException('Missing function ' . $name);
    return substr($source, $start, $end + 2 - $start);
}
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $status = 200; $headers = []; $dbCalls = $aiCalls = $originChecks = $fileReads = $fileDeletes = 0; $pdf = '';
    $actor = ['id' => 9, 'role' => $case['role'] ?? 'admin', 'email' => ($case['role'] ?? '') === 'student' ? 'student@example.test' : 'adviser@example.test', 'full_name' => 'Fixture Operator'];
    $_SESSION = []; $_SERVER['SCRIPT_NAME'] = $case['file'];
    $_GET = ['action' => $case['action'] ?? 'list', 'id' => 'existing'] + ($case['query'] ?? []);
    $pdo = new SnapshotFixturePDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE advisers (id INTEGER PRIMARY KEY, employee_id TEXT, email TEXT, full_name TEXT, department TEXT, assigned_groups TEXT, status TEXT, created_at TEXT)');
    $pdo->exec("INSERT INTO advisers VALUES (1,'A1','adviser@example.test','Adviser','AMT','LEGACY FREE TEXT','Active','2026-01-01'), (2,'A2','other@example.test','Other','AMT','LEGACY ONLY','Active','2026-01-01'), (3,'A3','new@example.test','New','AMT','IGNORED','Active','2026-01-01')");
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, student_id TEXT, full_name TEXT, email TEXT, adviser_id INTEGER, protocol_code TEXT,
        research_group TEXT, academic_unit_key TEXT, program_key TEXT, year_level TEXT, academic_year TEXT, stage TEXT, status TEXT,
        research_title TEXT, requirements TEXT, last_submission_date TEXT)');
    $pdo->exec("INSERT INTO students VALUES (1,'S1','Student','student@example.test',1,'P1','AMT-BSIT-Y2-2627-G01','amt','bsit','2nd Year','2026-2027','Stage 1','On Track','Research','Notes','2026-09-01'),
        (2,'S2','Other','otherstudent@example.test',2,'P2','AMT-BSIT-Y2-2627-G02','amt','bsit','2nd Year','2026-2027','Stage 2','Delayed','Other Research','','2026-09-02'),
        (3,'S3','Duplicate','duplicate@example.test',1,'P3','AMT-BSIT-Y2-2627-G01','amt','bsit','2nd Year','2026-2027','Stage 1','Pending','','',NULL),
        (4,'S4','Legacy','legacy@example.test',1,'P4','Legacy group',NULL,NULL,NULL,NULL,'Stage 1','Pending','','',NULL)");
    if (!empty($case['reassign'])) $pdo->exec('UPDATE students SET adviser_id = 2 WHERE id IN (1,3)');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY, recipient_type TEXT, recipient_id INTEGER, recipient_email TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE activity_logs (id INTEGER PRIMARY KEY, student_id INTEGER, action TEXT, user_email TEXT, details TEXT, actor_name TEXT,
        reason TEXT, is_override INTEGER, created_at TEXT)');
    for ($i = 1; $i <= 32; $i++) {
        $own = $i <= 23;
        $pdo->prepare('INSERT INTO notifications VALUES (?,?,?,?,?)')->execute([$i, $i > 30 ? 'adviser' : 'student', $own ? 1 : 2,
            $own ? 'student@example.test' : ($i > 30 ? 'adviser@example.test' : 'otherstudent@example.test'), '2026-09-01 12:00:00']);
        if ($i <= 30) $pdo->prepare('INSERT INTO activity_logs VALUES (?,?,?,?,?,?,?,?,?)')->execute([$i, $own ? 1 : 2,
            'document_reviewed','admin@example.test', $i <= 17 ? 'Matched' : 'Other', 'Admin', 'Reason', $i % 2,
            $i <= 13 ? '2026-09-01 12:00:00' : '2026-09-02 12:00:00']);
    }
    $pdo->exec('CREATE TABLE reports (id TEXT PRIMARY KEY, title TEXT, type TEXT, filename TEXT, generated_by TEXT, generated_by_user_id INTEGER, generated_at TEXT)');
    $pdo->exec("INSERT INTO reports VALUES ('existing','Existing report','Student Report','fixture.pdf','Former Adviser',9,'2026-01-01')");
    $pdo->exec('CREATE TABLE ierb_history (id INTEGER PRIMARY KEY, student_id INTEGER, stage TEXT, status TEXT, note TEXT, actor TEXT, created_at TEXT)');
    $config = \file_get_contents(__DIR__ . '/../config.php');
    if (!empty($case['readiness'])) {
        for($i=1;$i<=32;$i++)$pdo->prepare('INSERT INTO ierb_history (student_id,stage,status,note,actor,created_at) VALUES (?,?,?,?,?,?)')->execute([$i<=23?1:2,'Stage 1','On Track','History '.$i,'Admin','2026-09-01']);
        foreach (['course TEXT','is_principal_investigator INTEGER DEFAULT 0','created_at TEXT','updated_at TEXT'] as $column) $pdo->exec('ALTER TABLE students ADD COLUMN '.$column);
        $pdo->exec("UPDATE students SET course='BSIT', created_at='2026-09-01', updated_at='2026-09-01'");
        $insert=$pdo->prepare('INSERT INTO students (id,student_id,full_name,email,adviser_id,research_group,stage,status,course,research_title,requirements,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        for($i=5;$i<=34;$i++) $insert->execute([$i,'S'.$i,sprintf('Student %02d',$i),'s'.$i.'@example.test',$i<=27?1:2,'AMT-BSIT-Y2-2627-G01','Stage 1',$i%2?'Pending':'On Track','BSIT','Research','','2026-09-01','2026-09-01']);
        $pdo->exec('CREATE TABLE documents (id TEXT PRIMARY KEY, student_id INTEGER, original_name TEXT, student_name TEXT, size INTEGER, mime TEXT, uploaded_by TEXT, uploaded_at TEXT, document_type TEXT, stage TEXT, notes TEXT, review_status TEXT, review_remarks TEXT, reviewed_by TEXT, reviewed_at TEXT, ai_summary TEXT, is_current INTEGER, version_no INTEGER, supersedes_id TEXT, rpms_submitted_at TEXT, rpms_submitted_by TEXT, admin_override INTEGER, override_reason TEXT, override_by TEXT, override_at TEXT)');
        $insert=$pdo->prepare("INSERT INTO documents (id,student_id,original_name,student_name,size,mime,uploaded_by,uploaded_at,document_type,stage,review_status,is_current,version_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        for($i=1;$i<=34;$i++) $insert->execute([sprintf('d%02d',$i),$i<=23?1:2,'Document '.$i.'.pdf','Student '.$i,100,'application/pdf','Student','2026-09-01 12:00:00','Thesis','Stage 1',$i%2?'Approved':'Submitted',1,1]);
        for($i=4;$i<=26;$i++) $pdo->prepare('INSERT INTO advisers VALUES (?,?,?,?,?,?,?,?)')->execute([$i,'A'.$i,'a'.$i.'@example.test',sprintf('Adviser %02d',$i),'AMT','legacy','Active','2026-09-01']);
        for($i=2;$i<=26;$i++) $pdo->prepare('INSERT INTO reports VALUES (?,?,?,?,?,?,?)')->execute([sprintf('r%02d',$i),'Report '.$i,$i%2?'AI Full Report':'Student Report','fixture.pdf','Admin',1,'2026-09-01']);
        $pdo->exec("UPDATE reports SET title='=SUM(1,2)' WHERE id='existing'");
        $workflow=\file_get_contents(__DIR__.'/../workflow.php');
        $constants="const WF_PENDING_REVIEW='Pending Adviser Review', WF_NEEDS_REVISION='Needs Revision', WF_READY_FOR_RPMS='Ready for Formal RPMS Submission', WF_SUBMITTED_RPMS='Submitted to RPMS', WF_SUPERSEDED='Superseded', ADVISERS_REVIEW_ONLY_OWN_STUDENTS=true;";
        $functions=''; foreach(['document_workflow_state','document_is_locked','empty_doc_counts','student_document_counts'] as $function) $functions.=source_function($workflow,$function);
        $functions.=source_function($config,'stage_progress_percent');
        eval('namespace '.__NAMESPACE__.'; use \PDO; '.$constants.$functions);
    }
    define('REPORTS_DIR', 'fixture-only'); define('STAGE_SEQUENCE', ['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
    $config = \file_get_contents(__DIR__ . '/../config.php');
    eval('namespace ' . __NAMESPACE__ . ';' . source_function($config, 'api_require_login') . source_function($config, 'require_login'));
    require_once __DIR__ . '/../includes/research_groups.php';
    $pdo->exec('ALTER TABLE students ADD COLUMN archived_at TEXT');
    foreach ($case['fixtureSql'] ?? [] as $sql) $pdo->exec($sql);
    require_once __DIR__.'/retention-fixture-support.php';retention_fixture_support(__NAMESPACE__,true);
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; ' . preg_replace('/^<\?php\s*/','',\file_get_contents(__DIR__ . '/../includes/pagination.php')));
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; ' . preg_replace('/^<\?php\s*/','',\file_get_contents(__DIR__ . '/../includes/record_filters.php')));
    $source = \file_get_contents(__DIR__ . '/../' . $case['file']);
    $source=str_replace(["require_once __DIR__.'/includes/account_lifecycle.php';","require_once __DIR__ . '/includes/account_lifecycle.php';"],'',$source);
    $source = str_replace("require_once __DIR__ . '/includes/office_container.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/document_summary.php';", '', $source);
    foreach (["require __DIR__ . '/config.php';", "require_once __DIR__ . '/workflow.php';", "require_once __DIR__ . '/ai_helpers.php';", "require_once __DIR__ . '/includes/academic_catalog.php';", "require_once __DIR__ . '/includes/notification_delivery.php';", "require_once __DIR__ . '/includes/research_groups.php';"] as $include) $source = str_replace($include, '', $source);
    $source = str_replace("array_map('row_to_student',", "array_map('\\PrismAlignmentAudit\\row_to_student',", $source);
    $isPage = in_array($case['file'], ['admin_ai.php','reports.php'], true);
    if ($isPage) $source = explode('?>', $source, 2)[0];
    $source = str_replace("require_once __DIR__ . '/includes/pagination.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/record_filters.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/includes/csv_export.php';", '', $source);
    if (preg_match('/^\s*(?:require|include)(?:_once)?\s/m', $source)) throw new RuntimeException('Unexpected include');
    ob_start();
    register_shutdown_function(function () {
        $output = ob_get_clean();
        echo json_encode(['status'=>$GLOBALS['status'], 'response'=>json_decode($output,true), 'output'=>$output,
            'headers'=>$GLOBALS['headers'], 'dbCalls'=>$GLOBALS['dbCalls'], 'aiCalls'=>$GLOBALS['aiCalls'],
            'pdf'=>$GLOBALS['pdf'], 'fileReads'=>$GLOBALS['fileReads'], 'fileDeletes'=>$GLOBALS['fileDeletes'],
            'reports'=>(int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM reports')->fetchColumn()]);
    });
    eval('namespace ' . __NAMESPACE__ . '; use \\PDO; use \\Throwable; use \\RuntimeException; ' . preg_replace('/^<\?php\s*/', '', $source));
    if ($isPage) echo json_encode(['allowed'=>true]);
    exit;
}
$checks = 0;
function check(bool $ok, string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
function run_case(array $case): array
{
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    check($exit === 0 && $err === '', 'Fixture executes: ' . $err . $out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
foreach (['audit_api.php','notifications_api.php'] as $file) {
    foreach ($file === 'audit_api.php' ? ['admin'=>30,'adviser'=>23] : ['admin'=>32,'adviser'=>25,'student'=>23] as $role=>$total) {
        $key = $file === 'audit_api.php' ? 'entries' : 'notifications';
        $seen = [];
        foreach (array_merge(range(1,(int)ceil($total/10)),[999,0,-2]) as $requested) {
            $r = run_case(compact('file','role') + ['query'=>['page'=>$requested,'limit'=>500,'offset'=>999]])['response'];
            $page = max(1,min((int)ceil($total/10),$requested));
            check($r['total'] === $total && $r['limit'] === 10 && $r['page'] === $page, "$file/$role scoped totals and page boundaries");
            check(count($r[$key]) === min(10,$total-($page-1)*10), 'Fixed page size, including last partial page');
            if ($requested >= 1 && $requested <= ceil($total/10)) $seen = array_merge($seen,array_column($r[$key],'id'));
        }
        check(count($seen) === $total && count(array_unique($seen)) === $total, 'Stable ordering with tied timestamps: no missing/duplicate rows');
    }
}
foreach ([['q'=>'Matched'],['q'=>'Matched','override'=>'1'],['q'=>'Matched','override'=>'1','from'=>'2026-09-01','to'=>'2026-09-01'],['q'=>'No match']] as $i=>$query) {
    foreach ([1,2,999] as $page) {
        $r = run_case(['file'=>'audit_api.php','query'=>$query+['page'=>$page]])['response'];
        check($r['total'] === [17,9,7,0][$i], 'Combined filters return filtered totals on every page');
        check(count($r['entries']) <= 10 && ($r['total'] || ($r['page'] === 1 && $r['entries'] === [])), 'Empty filtered boundary');
    }
}
foreach ([false,true] as $reassign) {
    $r = run_case(['file'=>'advisers_api.php','reassign'=>$reassign])['response']['advisers'];
    $groups = array_column($r,'groups','id');
    check($groups[1] === ($reassign ? [] : ['AMT-BSIT-Y2-2627-G01']), 'Groups derive uniquely from assigned students');
    check($groups[2] === ($reassign ? ['AMT-BSIT-Y2-2627-G01','AMT-BSIT-Y2-2627-G02'] : ['AMT-BSIT-Y2-2627-G02']), 'Reassignment changes groups without syncing legacy fields');
    check($groups[3] === [] && !str_contains(json_encode($groups),'LEGACY'), 'No students means no groups; legacy adviser text excluded');
}
foreach (['adviser','student'] as $role) {
    foreach (['admin_ai.php','reports.php'] as $file) {
        $r = run_case(compact('file','role'));
        check($r['response'] === null && $r['headers'] === ['Location: ' . ($role === 'adviser' ? 'ierbprog.php' : 'student.php')], 'Page retains existing unauthorized redirect');
        check($r['dbCalls'] === 0 && $r['aiCalls'] === 0, 'Denied page has no report work');
    }
    foreach (['list','export_csv','ai_report','generate','file','delete'] as $action) {
        $r = run_case(['file'=>'reports_api.php','role'=>$role,'action'=>$action,'data'=>['mode'=>'full','type'=>'Student Report','studentId'=>1]]);
        check($r['status'] === 403 && !$r['response']['ok'], 'Non-admin report operation denied: ' . $action);
        check($r['dbCalls'] === 0 && $r['aiCalls'] === 0 && $r['fileReads'] === 0 && $r['fileDeletes'] === 0 && $r['pdf'] === '', 'Authorization precedes DB, OpenRouter and files');
    }
}
foreach (['admin_ai.php','reports.php'] as $file) check(run_case(compact('file'))['response']['allowed'], 'Admin page remains accessible');
foreach (['summary','full'] as $mode) foreach ([false,true] as $fallback) {
    $r = run_case(['file'=>'reports_api.php','action'=>'ai_report','data'=>compact('mode'),'fallback'=>$fallback]);
    check($r['status'] === 200 && $r['response']['ok'] && $r['aiCalls'] === 1 && $r['reports'] === 2, 'Admin AI generation persists report');
    check(str_starts_with($r['pdf'],'%PDF-1.4') && $r['response']['aiUsed'] === !$fallback, 'Admin PDF and local fallback retained');
}
foreach (['Student Report','Progress Report'] as $type) {
    $r = run_case(['file'=>'reports_api.php','action'=>'generate','data'=>['type'=>$type,'studentId'=>1]]);
    check($r['response']['ok'] && str_starts_with($r['pdf'],'%PDF-1.4') && $r['reports'] === 2 && $r['aiCalls'] === 0, 'Admin individual/aggregate report generation');
}
check(count(run_case(['file'=>'reports_api.php'])['response']['reports']) === 1, 'Admin can list legacy adviser-generated reports');
foreach ([[],['download'=>'1']] as $query) check(run_case(['file'=>'reports_api.php','action'=>'file','query'=>$query])['fileReads'] === 1, 'Admin can view/download report');
$r = run_case(['file'=>'reports_api.php','action'=>'delete']);
check($r['response']['ok'] && $r['reports'] === 0 && $r['fileDeletes'] === 1, 'Admin can delete report');
check(str_contains(\file_get_contents(__DIR__ . '/../storage/.htaccess'), 'Require all denied'), 'Raw stored reports remain denied by Apache');
foreach (['admin','adviser','student'] as $role) {
    $r = run_case(['file'=>'documents_api.php','role'=>$role,'readiness'=>true,'fixtureSql'=>["UPDATE documents SET ai_summary='Stored review aid'"]]);
    $rows=$r['response']['documents'];
    check(count($rows)>0 && count(array_filter($rows,fn($row)=>$row['actions']['summarize']===($role==='admin')))===count($rows), 'Summary action flag is Admin-only');
    check(count(array_filter($rows,fn($row)=>$row['aiSummary']===($role==='admin'?'Stored review aid':null)))===count($rows), 'Saved summary visibility is Admin-only');
}
echo "PASS: $checks pagination, adviser-group and report authorization checks; no live services or runtime writes.\n";
