<?php
/** Actual list endpoints and bulk reconstruction over volatile SQL. No config or live DB. */
if (PHP_SAPI !== 'cli') exit(1);
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    class FilterPDO extends PDO {
        public function prepare(string $sql, array $options = []): PDOStatement|false {
            $sql = preg_replace('/DATE_ADD\(([a-z]+\.archived_at),INTERVAL (\d+) (DAY|MONTH)\)/', "datetime($1,'+$2 $3')", $sql);
            $sql = preg_replace('/DATE_SUB\((datetime\([^)]*\)),INTERVAL (\d+) DAY\)/', "datetime($1,'-$2 DAY')", $sql);
            return parent::prepare($sql, $options);
        }
    }
    $pdo = new FilterPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', fn()=> '2026-10-10 12:00:00');
    $pdo->sqliteCreateFunction('CURDATE', fn()=> '2026-10-10');
    $pdo->exec('CREATE TABLE advisers(id INTEGER PRIMARY KEY, email TEXT, full_name TEXT, department TEXT, status TEXT, employee_id TEXT, archived_at TEXT, profile_completed_at TEXT, user_id INTEGER, retention_hold INTEGER DEFAULT 0, purge_postponed_reason TEXT)');
    $pdo->exec("INSERT INTO advisers(id,email,full_name,department,status,employee_id,profile_completed_at) VALUES (1,'own@example.test','Own','Nursing','Active','A1','2026-01-01'),(2,'other@example.test','Other','Other','Active','A2','2026-01-01')");
    $pdo->exec('CREATE TABLE students(id INTEGER PRIMARY KEY, student_id TEXT, full_name TEXT, email TEXT, research_title TEXT, research_group TEXT, course TEXT, academic_unit_key TEXT, program_key TEXT, year_level TEXT, academic_year TEXT, adviser_id INTEGER, stage TEXT, status TEXT, protocol_code TEXT, requirements TEXT, is_principal_investigator INTEGER DEFAULT 0, last_submission_date TEXT, created_at TEXT, updated_at TEXT, archived_at TEXT, profile_completed_at TEXT, retention_hold INTEGER DEFAULT 0, purge_postponed_reason TEXT)');
    $q=$pdo->prepare('INSERT INTO students(id,student_id,full_name,email,course,academic_unit_key,program_key,year_level,academic_year,adviser_id,stage,status,profile_completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([[1,'amt','bsit','4th Year',1,'BS in Information Technology'],[2,'nursing','bsn','4th Year',1,'BS in Nursing'],[3,null,null,null,1,'Legacy course'],[4,'amt',null,null,1,null],[5,'dentistry','ddm','6th Year',2,'Doctor of Dental Medicine'],[6,null,'mba_thesis',null,1,'Master of Business Administration (Thesis Program)'],[7,null,'legacy_program','Unknown year',1,'Legacy course'],[8,'amt','bsit','4th Year',2,'BS in Information Technology']] as [$id,$unit,$program,$year,$adviser,$course]) {
        $q->execute([$id,'S'.$id,'Student '.$id,'s'.$id.'@example.test',$course,$unit,$program,$year,'2026-2027',$adviser,'Stage 1','On Track','2026-01-01']);
    }
    $pdo->exec('CREATE TABLE documents(student_id INTEGER, is_current INTEGER, rpms_submitted_at TEXT, review_status TEXT)');
    $pdo->exec('CREATE TABLE notifications(recipient_type TEXT, recipient_id INTEGER, recipient_email TEXT, recipient_name TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE calendar_deadlines(creator_user_id INTEGER,status TEXT,deadline_date TEXT)');
    $pdo->exec('CREATE TABLE users(id INTEGER,role TEXT,email TEXT)');
    $_GET=$case['query']??[]; $_GET['action']='list'; $_SESSION=[];
    $actor=['id'=>1,'role'=>$case['role']??'admin','email'=>'own@example.test'];
    function db(): PDO { return $GLOBALS['pdo']; }
    function api_require_login($roles): array { if(!in_array($GLOBALS['actor']['role'],(array)$roles,true))json_out(['ok'=>false],403); return $GLOBALS['actor']; }
    function json_out(array $data,int $status=200): never { echo json_encode(['status'=>$status,'data'=>$data]);exit; }
    function stage_label(string $stage): string { return $stage; }
    function stage_progress_percent(string $stage): int { return 20; }
    function stage_labels_map(): array { return []; }
    define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
    require_once __DIR__.'/../includes/account_lifecycle.php';
    require_once __DIR__.'/../includes/academic_catalog.php';
    if (($case['file']??'')==='bulk') {
        unset($_GET['action']);
        try { json_out(retention_selection($pdo,$actor,['accountType'=>'student','filters'=>$_GET])); }
        catch (Throwable $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e)); }
    }
    $file=$case['file']??'students_api.php';
    if(!in_array($file,['students_api.php','ierb_api.php','advisers_api.php'],true))throw new RuntimeException('Unexpected fixture endpoint.');
    $source=file_get_contents(__DIR__.'/../'.$file);
    $source=str_replace("require __DIR__ . '/config.php';",'', $source,$count);
    if($count!==1)throw new RuntimeException('Unexpected bootstrap.');
    $source=str_replace('__DIR__',var_export(dirname(__DIR__),true),$source);
    eval(preg_replace('/^<\?php\s*/','',$source));exit;
}
$checks=0;
function verify(bool $ok,string $label): void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label); }
function request_filter(string $file,array $query=[],string $role='admin'): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode(compact('file','query','role'))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($p)===0 && $err==='', 'Fixture warning/failure: '.$err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
foreach (['students_api.php','ierb_api.php','bulk'] as $file) {
    foreach ([['academicUnitKey'=>'unknown'],['programKey'=>'unknown'],['academicUnitKey'=>'amt','programKey'=>'bsn'],['academicUnitKey'=>'nursing','programKey'=>'bsit'],['academicUnitKey'=>'nursing','course'=>'BS in Information Technology'],['programKey'=>'bsit','yearLevel'=>'6th Year'],['academicUnitKey'=>['amt']],['programKey'=>['value'=>'bsit']],['yearLevel'=>['4th Year']],['course'=>[]],['programKey'=>"bsit' OR 1=1 --"],['adviserId'=>'999']] as $bad) {
        verify(request_filter($file,$bad)['status']===422,$file.' rejects crafted filters');
    }
    $r=request_filter($file,['academicUnitKey'=>'amt','programKey'=>'bsit']);
    $ids=$file==='bulk'?$r['data']['ids']:array_column($r['data'][$file==='students_api.php'?'students':'records'],'id');
    verify($r['status']===200 && $ids===[1,8],$file.' applies identical canonical population');
    foreach ([['academicUnitKey'=>'__blank__'],['programKey'=>'__blank__'],['academicUnitKey'=>'amt','programKey'=>'__blank__'],['academicUnitKey'=>'__blank__','programKey'=>'legacy_program'],['programKey'=>'mba_thesis','yearLevel'=>'__blank__']] as $legacy) {
        $r=request_filter($file,$legacy);verify($r['status']===200 && $r['data']['total']>0,$file.' locates legacy and blank records');
    }
}
foreach (['students_api.php','ierb_api.php'] as $file) {
    foreach ([['academicUnitKey'=>'dentistry'],['programKey'=>'ddm'],['adviserId'=>'2']] as $forged)verify(request_filter($file,$forged,'adviser')['status']===422,'Unauthorized scoped option rejected');
    $r=request_filter($file,[],'adviser');verify($r['data']['total']===6,'Adviser scope remains current assignments');
    foreach ([['sortBy'=>"name; DROP TABLE students"],['sortBy'=>['name']],['sortBy'=>'name','direction'=>'DESC; DROP TABLE students'],['sortBy'=>'name','direction'=>[]]] as $badSort) {
        $r=request_filter($file,$badSort);verify($r['status']===200 && $r['data']['total']===8,'Malformed sort uses safe fixed fallback');
    }
}
foreach ([['department'=>'unknown'],['group'=>['hidden']],['status'=>['Active']]] as $bad)verify(request_filter('advisers_api.php',$bad)['status']===422,'Adviser listing rejects malformed filters');
verify(request_filter('advisers_api.php',[],'adviser')['status']===403,'Adviser cannot list institutional adviser roster');
verify(request_filter('bulk',[],'adviser')['status']===403,'Adviser cannot reconstruct lifecycle populations');
echo "PASS: $checks Batch 3 filter/API/bulk/security assertions; volatile SQL only.\n";
