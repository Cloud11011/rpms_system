<?php
namespace PrismBatch5bHttp;
/** Test-only loopback router. Never loads config/local credentials or an installed database. */
use PDO;
use PDOStatement;
use Throwable;
use RuntimeException;
$fixture=getenv('PRISM_BATCH5B_FIXTURE');
if (!in_array(PHP_SAPI,['cli','cli-server'],true) || !$fixture || !str_contains($fixture,'prism-batch5b-') || !is_dir($fixture)) exit(1);
require_once __DIR__.'/../security.php';
class BufferedFixtureStatement extends PDOStatement {
    protected function __construct() {}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);$this->closeCursor();return $row; }
    public function fetchColumn(int $column=0): mixed { $value=parent::fetchColumn($column);$this->closeCursor();return $value; }
}
class FixturePDO extends PDO {
    private bool $active=false;
    public function __construct() { parent::__construct('sqlite:'.$GLOBALS['fixture'].'/fixture.sqlite'); $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[BufferedFixtureStatement::class]);$this->exec('PRAGMA busy_timeout=15000'); }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $query=str_replace([' FOR UPDATE','CURDATE()'],['',"date('now')"],$query);
        if (str_contains($query,'INSERT INTO documents') && ($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='metadata') throw new RuntimeException('Injected metadata failure');
        return parent::prepare($query,$options);
    }
    public function beginTransaction(): bool { $this->exec('BEGIN IMMEDIATE');$this->active=true; if (!empty($_SERVER['HTTP_X_FIXTURE_PAUSE'])) usleep(150000);return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { if (($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='commit') throw new RuntimeException('Injected commit failure'); $this->exec('COMMIT');$this->active=false;return true; }
    public function rollBack(): bool { $this->exec('ROLLBACK');$this->active=false;return true; }
}
function db(): PDO { static $pdo;return $pdo??=new FixturePDO(); }
function onboarding_complete(PDO $pdo,array $user): bool { return $user['role']==='admin' || empty($user['pending']); }
function legal_outstanding(...$args): array { return []; }
function legal_acceptance_required(...$args): bool { return false; }
function consume_auth_attempt(...$args): bool { return true; }
function too_many_recent_failures(...$args): bool { return false; }
function log_activity(...$args): void {}
function json_out(array $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json');echo json_encode($data);exit; }
function json_body(): array { return json_decode(file_get_contents('php://input'),true) ?: []; }
function audit_log(array $actor,string $action,array $data): void { db()->prepare('INSERT INTO audit(action,entity_id) VALUES (?,?)')->execute([$action,$data['entity_id']??'']); }
function log_api_error(...$args): void {}
function purge_require_no_pending(...$args): void {}
function notify_student(...$args): void { db()->exec("INSERT INTO notices(kind) VALUES ('student')"); }
function notify_adviser_of_student(...$args): void { db()->exec("INSERT INTO notices(kind) VALUES ('adviser')"); }
function extract_document_text(...$args): string { return ''; }
function ai_detect_approval_date(...$args): array { return ['date'=>null,'source'=>null]; }
function stage_label(string $stage): string { return $stage; }
function move_uploaded_file(string $from,string $to): bool { return ($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='move' ? false : \move_uploaded_file($from,$to); }
function extract_function(string $source,string $name): string {
    if(!preg_match('/function '.preg_quote($name,'/').'\([^\n]*\n\{[\s\S]*?\n\}/',$source,$m))throw new RuntimeException('Missing helper '.$name);
    return $m[0];
}
if (($argv[1]??'')==='--seed') {
    $pdo=db();
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,email TEXT,password_hash TEXT,full_name TEXT,status TEXT,must_change_password INTEGER,username TEXT,ref_id TEXT,pending INTEGER)');
    $pdo->exec('CREATE TABLE students(id INTEGER PRIMARY KEY,email TEXT,full_name TEXT,stage TEXT,adviser_id INTEGER,archived_at TEXT,research_title TEXT,research_group TEXT,last_submission_date TEXT,course TEXT,protocol_code TEXT)');
    $pdo->exec('CREATE TABLE advisers(id INTEGER PRIMARY KEY,email TEXT)');
    $pdo->exec('CREATE TABLE documents(id TEXT PRIMARY KEY,student_id INTEGER,student_name TEXT,uploaded_by TEXT,uploaded_by_role TEXT,original_name TEXT,stored_name TEXT,mime TEXT,size INTEGER,document_type TEXT,stage TEXT,notes TEXT,review_status TEXT,detected_approval_date TEXT,approval_date_source TEXT,version_no INTEGER,is_current INTEGER,supersedes_id TEXT,uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,review_remarks TEXT,reviewed_by TEXT,reviewed_at TEXT,ai_summary TEXT,rpms_submitted_at TEXT,rpms_submitted_by TEXT,admin_override INTEGER,override_reason TEXT,override_by TEXT,override_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX fixture_versions ON documents(student_id,stage,document_type,version_no)');
    $pdo->exec('CREATE TABLE audit(action TEXT,entity_id TEXT);CREATE TABLE notices(kind TEXT)');
    foreach ([1=>'student',2=>'adviser',3=>'admin',4=>'student',5=>'student',6=>'student',7=>'student'] as $id=>$role) {
        $pdo->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$role,"u$id@example.invalid",password_hash('fixture-password',PASSWORD_DEFAULT),"Fixture $id",$id===5?'Inactive':'Active',$id===7?1:0,"U$id","U$id",$id===4?1:0]);
    }
    $pdo->exec("INSERT INTO advisers VALUES (2,'u2@example.invalid')");
    $pdo->exec("INSERT INTO students VALUES (1,'u1@example.invalid','Student 1','Stage 1',2,NULL,'Authoritative title','Authoritative group',NULL,'IT','FIXTURE'),(6,'u6@example.invalid','Archived','Stage 1',2,'2026-10-01','','',NULL,'IT','ARCHIVED'),(8,'other@example.invalid','Unrelated','Stage 1',99,NULL,'','',NULL,'IT','OTHER')");
    echo 'Seeded isolated fixture';exit;
}
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1')exit(1);
$publicRoute=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(in_array($publicRoute,['privacy.php','terms.php'],true)) {
    require_once __DIR__.'/../includes/assets.php';
    require_once __DIR__.'/../includes/legal_policy.php';
    $legalPageKey=pathinfo($publicRoute,PATHINFO_FILENAME);
    $source=file_get_contents(__DIR__.'/../includes/public_legal.php');
    $source=str_replace("require_once __DIR__.'/../config.php';",'', $source);
    $source=str_replace("require __DIR__.'/legal_placeholders.php'",'require '.var_export(__DIR__.'/../includes/legal_placeholders.php',true),$source);
    eval('namespace '.__NAMESPACE__.'; '.preg_replace('/^<\?php\s*/','',$source)); exit;
}
function legal_current(...$args): array {
    $seed=json_decode(file_get_contents(__DIR__.'/../includes/schema_v10_seed.json'),true);
    foreach ($seed as &$row) $row+=['version_number'=>1,'published_at'=>'2026-10-11 12:00:00']; unset($row);return $seed;
}
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
session_save_path($fixture.'/sessions');
session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax']);session_start();
$config=file_get_contents(__DIR__.'/../config.php');
foreach (['current_user','api_require_login','require_post_same_origin'] as $fn) eval('namespace '.__NAMESPACE__.'; use \\PDO; '.extract_function($config,$fn));
// Namespace source execution below intentionally bypasses all real service bootstrap.
foreach (['document_catalog','document_upload','office_container'] as $helper) {
    $source=file_get_contents(__DIR__.'/../includes/'.$helper.'.php');
    eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\Throwable; use \\RuntimeException; use \\finfo; use \\ZipArchive; '.preg_replace('/^<\?php\s*/','',$source));
}
$workflow=file_get_contents(__DIR__.'/../workflow.php');
foreach (['document_workflow_state','document_is_locked'] as $fn) eval('namespace '.__NAMESPACE__.'; '.extract_function($workflow,$fn));
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
define('ADVISERS_REVIEW_ONLY_OWN_STUDENTS',true);
foreach (['WF_PENDING_REVIEW'=>'Pending Adviser Review','WF_NEEDS_REVISION'=>'Needs Revision','WF_READY_FOR_RPMS'=>'Ready for Formal RPMS Submission','WF_SUBMITTED_RPMS'=>'Submitted to RPMS','WF_SUPERSEDED'=>'Superseded'] as $key=>$value)define($key,$value);
define('DOCS_DIR',($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='write'?$fixture.'/missing':$fixture.'/docs');
$route=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$_SERVER['SCRIPT_NAME']=$route;
if($route==='state') {
    $user=current_user();
    $records=db()->query('SELECT * FROM documents ORDER BY uploaded_at,id')->fetchAll();
    json_out(['ok'=>true,'id'=>$user['id']??null,'role'=>$user['role']??null,'generation'=>prism_session_generation(),
        'documents'=>$records,'files'=>array_map('basename',glob($fixture.'/docs/*')),'audit'=>db()->query('SELECT * FROM audit')->fetchAll(),
        'notices'=>(int)db()->query('SELECT COUNT(*) FROM notices')->fetchColumn()]);
}
if($route==='login.php') { $source=file_get_contents(__DIR__.'/../login.php');$source=substr($source,0,strpos($source,'$loginError')); }
elseif(in_array($route,['login_process.php','logout.php','index.php','documents_api.php'],true))$source=file_get_contents(__DIR__.'/../'.$route);
else {http_response_code(404);exit;}
$source=preg_replace('~(?:require|require_once) __DIR__ \. \'/[^\']+\';~','',$source);
eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\Throwable; use \\RuntimeException; use \\finfo; use \\ZipArchive; '.preg_replace('/^<\?php\s*/','',$source));
