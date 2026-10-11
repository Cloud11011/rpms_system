<?php
namespace PrismSummaryAudit;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use DocumentSummaryError;

/** Real endpoint SQL on disposable in-memory SQLite; real file extraction; mocked provider only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/document_summary.php';
require __DIR__ . '/../security.php';
class FixtureDb extends PDO
{
    public array $writes = [];
    public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_starts_with($query, 'UPDATE documents SET ai_summary')) {
            $this->writes[] = $query;
            if (!empty($GLOBALS['case']['saveError'])) throw new RuntimeException('Fixture write failure with sensitive text');
        }
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function db(): PDO { return $GLOBALS['fixtureDb']; }
function api_require_login(array $roles): array { return $GLOBALS['actor']; }
function json_body(): array { return $GLOBALS['payload']; }
function json_out(array $body, int $status = 200): never { $GLOBALS['response'] = $body; $GLOBALS['status'] = $status; exit; }
function http_response_code(?int $status = null): int { if ($status !== null) $GLOBALS['status'] = $status; return $GLOBALS['status']; }
function header(string $value): void {}
function stage_label(string $stage): string { return $stage; }
function audit_log(array $actor, string $action, array $ctx = []): void { $GLOBALS['audit'][] = ['action'=>$action,'context'=>$ctx]; }
function source_function(string $source, string $name): string {
    $source = str_replace("\r\n", "\n", $source); $start = strpos($source, 'function ' . $name . '('); $end = strpos($source, "\n}\n", $start);
    if ($start === false || $end === false) throw new RuntimeException('Missing real function ' . $name);
    return substr($source, $start, $end + 2 - $start) . "\n";
}

if (($argv[1] ?? '') === '--case') {
    $GLOBALS['case'] = $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $fixtureDb = $GLOBALS['fixtureDb'] = new FixtureDb();
    $pdo = $fixtureDb;
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, full_name TEXT, student_id TEXT, email TEXT, protocol_code TEXT, research_group TEXT, adviser_id INTEGER, course TEXT, archived_at TEXT, stage TEXT, status TEXT)');
    $pdo->exec("INSERT INTO students VALUES (4,'Maria Santos','2026-12345','maria@example.test','CEU-IERB-2026-071','GROUP-2026',2,'BSIT',NULL,'Stage 3','On Track'), (5,'Ana Reyes','2026-12346','ana@example.test',NULL,'GROUP-2026',2,'BSIT',NULL,'Stage 3','On Track')");
    if (!empty($case['archivedStudent'])) $pdo->exec("UPDATE students SET archived_at='2026-10-02' WHERE id=4");
    $pdo->exec('CREATE TABLE advisers (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT)');
    $pdo->exec("INSERT INTO advisers VALUES (2,'Adviser Person','adviser@example.test')");
    $document = ['id'=>'0123456789abcdef01234567','student_id'=>4,'student_name'=>'Maria Santos','stored_name'=>$case['file']??'research-protocol.txt',
        'original_name'=>'Research protocol.txt','size'=>1163,'mime'=>'text/plain','uploaded_by'=>'Maria Santos','uploaded_at'=>'2026-10-01',
        'document_type'=>'Protocol','stage'=>'Stage 3','notes'=>'Existing notes','review_status'=>'Approved','review_remarks'=>'Existing remarks',
        'reviewed_by'=>'Adviser Person','reviewed_at'=>'2026-10-01','detected_approval_date'=>'2026-09-24','approval_date_source'=>'regex',
        'ai_summary'=>'Existing saved summary','is_current'=>1,'version_no'=>3,'supersedes_id'=>'older-document','rpms_submitted_at'=>'2026-10-02',
        'rpms_submitted_by'=>'Maria Santos','admin_override'=>1,'override_reason'=>'Existing override','override_by'=>'Admin Person','override_at'=>'2026-10-02'];
    $schema = array_map(fn($key) => "$key " . (in_array($key,['student_id','size','is_current','version_no','admin_override'],true)?'INTEGER':'TEXT'),array_keys($document));
    $pdo->exec('CREATE TABLE documents (' . implode(',', $schema) . ')');
    $pdo->prepare('INSERT INTO documents VALUES (' . implode(',',array_fill(0,count($document),'?')) . ')')->execute(array_values($document));
    $before = $pdo->query('SELECT * FROM documents')->fetch(); $studentBefore = $pdo->query('SELECT * FROM students ORDER BY id')->fetchAll();
    if (!empty($case['missingDocument'])) $pdo->exec('DELETE FROM documents');
    $GLOBALS['actor'] = ['role'=>$case['role']??'admin','full_name'=>'Admin Person','email'=>'admin@example.test'];
    $GLOBALS['payload'] = array_key_exists('payload',$case)?$case['payload']:['id'=>$document['id'],'prompt'=>'IGNORE THIS CLIENT PROMPT','path'=>'C:/never/read/me'];
    $GLOBALS['audit'] = []; $GLOBALS['response'] = null; $GLOBALS['status'] = 200; $GLOBALS['aiCalls'] = [];
    $_GET = ['action'=>'summarize']; $_POST = [];
    $_SERVER = ['REQUEST_METHOD'=>$case['method']??'POST','HTTP_HOST'=>'prism.example.test','HTTP_ORIGIN'=>$case['origin']??'https://prism.example.test','HTTPS'=>'on'];
    define('DOCS_DIR', __DIR__ . '/fixtures/document-summary'); define('ADVISERS_REVIEW_ONLY_OWN_STUDENTS', true);
    $config = file_get_contents(__DIR__ . '/../config.php');
    eval('namespace ' . __NAMESPACE__ . ';' . source_function($config, 'require_post_same_origin'));
    // Actual local fallback; provider double never contacts OpenRouter or reads credentials.
    eval('namespace {' . source_function($config, 'local_extractive_summary') . '
        function openrouter_generate($system, $input, $sensitive = false) {
            $GLOBALS["aiCalls"][] = ["system"=>$system,"input"=>$input,"sensitive"=>$sensitive];
            $mode = $GLOBALS["case"]["provider"] ?? "success";
            if (!empty($GLOBALS["case"]["documentChanged"])) $GLOBALS["fixtureDb"]->exec("UPDATE documents SET is_current=0");
            if ($mode === "throw") throw new \\RuntimeException("Sensitive provider failure");
            if (in_array($mode, ["unavailable","error"], true)) return null;
            if ($mode === "empty") return " ";
            if ($mode === "identity") return "Maria Santos submitted the protocol.";
            if ($mode === "long") return str_repeat("x",5000);
            return "Purpose: Review access to university digital library services.\nMethodology: Anonymous questionnaire and descriptive analysis.\nEthics: Participation is voluntary and informed consent is planned.\nAbsent information: Ethical approval is not recorded.";
        }
    }');
    $workflow = file_get_contents(__DIR__ . '/../workflow.php');
    foreach (['document_workflow_state','document_is_locked'] as $name) eval('namespace ' . __NAMESPACE__ . ';' . source_function($workflow,$name));
    ob_start();
    register_shutdown_function(function () use ($before,$studentBefore): void {
        $output = ob_get_clean();
        $after = $GLOBALS['fixtureDb']->query('SELECT * FROM documents')->fetch();
        $students = $GLOBALS['fixtureDb']->query('SELECT * FROM students ORDER BY id')->fetchAll();
        echo json_encode(['status'=>$GLOBALS['status'],'response'=>$GLOBALS['response']??json_decode($output,true),
            'before'=>$before,'after'=>$after,'studentsUnchanged'=>$studentBefore===$students,'writes'=>$GLOBALS['fixtureDb']->writes,
            'audit'=>$GLOBALS['audit'],'aiCalls'=>$GLOBALS['aiCalls'],'unexpected'=>$GLOBALS['response']?$output:'']);
    });
    require_once __DIR__.'/retention-fixture-support.php';retention_fixture_support(__NAMESPACE__);
    $source = file_get_contents(__DIR__ . '/../documents_api.php');
    $source = str_replace("require_once __DIR__ . '/includes/account_lifecycle.php';", '', $source);
    $source = preg_replace("~require(?:_once)? __DIR__ \\. '/(?:config|ai_helpers|workflow|includes/pagination|includes/office_container|includes/document_summary|includes/document_catalog|includes/document_upload)\\.php';~", '', $source);
    eval('namespace ' . __NAMESPACE__ . '; use \\PDO; use \\Throwable; use \\RuntimeException; use \\DocumentSummaryError;' . preg_replace('/^<\?php\s*/','',$source));
    exit;
}
$checks = 0;
function verify(bool $ok,string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
function endpoint(array $case): array {
    $p = proc_open([PHP_BINARY,'-d','extension=zip',__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($p); verify($exit===0 && $err==='', 'Endpoint fixture completes cleanly: '.$err.' '.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
foreach (['academic-research.pdf','research-protocol.docx','research-protocol.txt','research-protocol.rtf'] as $file) {
    foreach (['success','unavailable','error'] as $provider) {
        $r = endpoint(compact('file','provider'));
        verify($r['status']===200 && $r['response']['ok'], "Admin summarizes $file ($provider)");
        verify($r['response']['source']===($provider==='success'?'ai':'local_fallback'), 'Generation source explicit');
        verify($r['after']['ai_summary']===$r['response']['summary'], 'Canonical column contains only summary text');
        $before = $r['before']; $after = $r['after']; unset($before['ai_summary'],$after['ai_summary']);
        verify($before===$after && $r['studentsUnchanged'], 'All other document and student fields unchanged, including locked workflow');
        verify($r['writes']===['UPDATE documents SET ai_summary = :summary WHERE id = :id'], 'Exactly one permitted document update');
        verify(count($r['aiCalls'])===1 && $r['aiCalls'][0]['sensitive']===true, 'Document transport opts into sensitive-content mode');
        $input=$r['aiCalls'][0]['input'];
        foreach (['Maria','Santos','2026-12345','maria@example.test','CEU-IERB-2026-071','IGNORE THIS CLIENT PROMPT','C:/never/read/me'] as $value) verify(!str_contains($input,$value), 'Client prompt/path and identifiers never reach model');
        verify(mb_strlen($input)<=12000, 'External text bounded');
        verify($r['aiCalls'][0]['system']===\DOCUMENT_SUMMARY_PROMPT, 'Only server prompt used');
        verify(count($r['audit'])===1 && $r['audit'][0]['action']==='document_summarized', 'Success structured audit');
        verify(!str_contains(json_encode($r['audit']),$r['response']['summary']), 'Summary excluded from audit');
        verify($r['unexpected']==='', 'No warnings or paths emitted');
    }
}
$r=endpoint(['archivedStudent'=>true]);
verify($r['status']===200 && $r['response']['ok'] && $r['studentsUnchanged'], 'Admin can summarize historical archived-student documents');
foreach (['student','adviser'] as $role) foreach (['POST','GET'] as $method) {
    $r=endpoint(compact('role','method')); verify($r['status']===403 && !$r['aiCalls'] && !$r['writes'], "$role $method denied before extraction/model/write");
}
foreach ([[],['id'=>[]],['id'=>1],['id'=>'../academic-research.pdf'],['id'=>'bad']] as $payload) {
    $r=endpoint(compact('payload')); verify($r['status']===422 && !$r['aiCalls'] && !$r['writes'], 'Invalid ID rejected');
}
foreach ([['method'=>'GET','status'=>405],['origin'=>'https://attacker.example','status'=>403],['origin'=>'','status'=>403],
    ['missingDocument'=>true,'status'=>404],['file'=>'missing.pdf','status'=>404],['file'=>'../composer.json','status'=>404],
    ['file'=>'scanned-image.pdf','status'=>422],['file'=>'malformed.pdf','status'=>422],['file'=>'font-budget.pdf','status'=>422],['file'=>'encrypted.pdf','status'=>422],['file'=>'external-entity.docx','status'=>422],['file'=>'unsupported.doc','status'=>422]] as $case) {
    $r=endpoint($case); verify($r['status']===$case['status'] && !$r['aiCalls'] && !$r['writes'], 'Invalid request/document safely rejected');
    if (($case['file']??'')==='scanned-image.pdf') verify(str_contains($r['response']['message'],'scanned/image-only PDF') && !str_contains($r['response']['message'],'storage'), 'Scanned PDF has expected useful message');
    if (!$r['after']) continue;
    verify($r['after']===$r['before'] && $r['studentsUnchanged'], 'Failure preserves saved summary and all workflow fields');
}
foreach (['throw','empty','identity','long'] as $provider) {
    $r=endpoint(compact('provider')); verify($r['status']===200 && $r['response']['source']==='local_fallback', 'Invalid/error provider reply falls back: '.$provider);
}
$r=endpoint(['documentChanged'=>true]); verify($r['status']===409 && !$r['writes'], 'Document changed during provider request is not overwritten');
$r=endpoint(['saveError'=>true]); verify($r['status']===503 && $r['after']===$r['before'], 'Persistence failure rolls back without content leak');
verify(!str_contains(json_encode($r['response']), 'Sensitive'), 'Internal errors remain private');
echo "PASS: $checks Admin/Adviser/Student endpoint, fallback, privacy, persistence and workflow assertions.\n";
