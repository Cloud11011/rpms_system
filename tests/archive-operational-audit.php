<?php
namespace PrismReleaseFixture;
function require_session_generation(...$args): void {}
function prism_session_generation(): string { return 'synthetic-accepted-generation'; }
function is_uploaded_file(string $path): bool { return $path===__DIR__.'/fixtures/batch6-valid.pdf'; }
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
require_once __DIR__.'/../includes/student_snapshot.php';
require_once __DIR__.'/../includes/office_container.php';
foreach (['document_catalog','document_upload'] as $helper) {
    eval('namespace '.__NAMESPACE__.'; use \\finfo; use \\RuntimeException; '.preg_replace('/^<\?php\s*/','',file_get_contents(__DIR__.'/../includes/'.$helper.'.php')));
}

/** Real endpoint SQL on disposable SQLite and synthetic files. Never bootstrap config.php. */
if (PHP_SAPI !== 'cli') exit(1);
function part(string $file, string $name): string {
    $s = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../'.$file));
    $start = strpos($s, 'function '.$name.'('); $end = strpos($s, "\n}\n", $start);
    if ($start === false || $end === false) throw new RuntimeException('Missing reviewed function '.$name);
    return substr($s, $start, $end + 2 - $start);
}
function db(): PDO { return $GLOBALS['pdo']; }
function api_require_login($roles): array {
    if (!in_array($GLOBALS['actor']['role'], (array)$roles, true)) json_out(['ok'=>false],403);
    return $GLOBALS['actor'];
}
function require_post_same_origin(): void { $GLOBALS['originChecked'] = true; }
function json_body(): array { return $GLOBALS['case']['data'] ?? []; }
function json_out(array $data, int $status = 200): never { $GLOBALS['response']=$data; $GLOBALS['status']=$status; exit; }
function header(string $value): void { $GLOBALS['headers'][]=$value; }
function header_remove(string $name): void {}
function http_response_code(?int $status=null): int { if ($status!==null) $GLOBALS['status']=$status; return $GLOBALS['status']; }
function audit_log(array $actor, string $action, array $context=[]): void { $GLOBALS['audits'][]=compact('action','context'); }
function log_activity(...$args): void { $GLOBALS['activity'][]=$args; }
function log_api_error(...$args): void { throw new RuntimeException('Unexpected endpoint error: '.json_encode($args)); }
function stage_label(string $stage): string { return $stage; }
function stage_progress_percent(string $stage): int { return (int)array_search($stage,STAGE_SEQUENCE,true)*20; }
function next_stage(string $stage): string { return STAGE_SEQUENCE[min(5,(int)array_search($stage,STAGE_SEQUENCE,true)+1)]; }
function openrouter_generate(string $system,string $input,bool $sensitive=false): ?string {
    $GLOBALS['provider'][]=compact('system','input','sensitive');
    return ($GLOBALS['case']['provider']??'fallback')==='success'?'Current active records require routine review.':null;
}
function create_notification(...$args): array { $GLOBALS['notices'][]=$args; return ['ok'=>true,'message'=>'Synthetic delivery']; }
function record_history(...$args): void { throw new RuntimeException('Unexpected history mutation'); }

if (($argv[1]??'')==='--case') {
    $GLOBALS['case']=$case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $allowed=['students_api.php','ierb_api.php','reports_api.php','documents_api.php','send_followup.php','audit_api.php','notifications_api.php','calendar_deadlines_api.php','data_exports_api.php'];
    if (!in_array($case['file'],$allowed,true)) throw new RuntimeException('Unreviewed endpoint');
    class FixturePDO extends PDO {
        public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
    }
    $pdo=$GLOBALS['pdo']=new FixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE advisers (id INTEGER PRIMARY KEY,employee_id TEXT,full_name TEXT,email TEXT,department TEXT,status TEXT,created_at TEXT,updated_at TEXT)');
    $pdo->exec("INSERT INTO advisers VALUES (1,'EMP-1','Adviser One','adv@example.test','AMT','Active','2026-01-01','2026-01-02'),(2,'EMP-2','Inactive Adviser','old@example.test','Legacy Department','Inactive','2026-01-01','2026-01-02')");
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY,student_id TEXT,full_name TEXT,email TEXT,research_title TEXT,research_group TEXT,course TEXT,adviser_id INTEGER,stage TEXT,status TEXT,requirements TEXT,last_submission_date TEXT,protocol_code TEXT,is_principal_investigator INTEGER,academic_unit_key TEXT,program_key TEXT,year_level TEXT,academic_year TEXT,archived_at TEXT,created_at TEXT,updated_at TEXT)');
    $q=$pdo->prepare('INSERT INTO students VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([1,2,3] as $id) $q->execute([$id,'ST-'.$id,['','Active A','Active B','Archived C'][$id],'s'.$id.'@example.test','Research '.$id,'AMT-BSIT-Y2-2627-G0'.$id,'BS in Information Technology',1,$id===2?'Completed':'Stage 1',$id===2?'On Track':'Pending',$id===3?'ARCHIVED REQUIREMENT':'','2026-10-01','P-'.$id,1,'amt','bsit','2nd Year','2026-2027',$id===3?'2026-10-02':null,'2026-01-01','2026-10-01']);
    $doc=['id'=>'cccccccccccccccccccccccc','student_id'=>3,'student_name'=>'Archived C','stored_name'=>'research-protocol.txt','original_name'=>'Historical research.txt','mime'=>'text/plain','size'=>1163,'uploaded_by'=>'Archived C','uploaded_by_role'=>'student','uploaded_at'=>'2026-10-01','document_type'=>'Protocol','stage'=>'Stage 1','version_no'=>1,'is_current'=>1,'supersedes_id'=>null,'notes'=>'Historical notes','review_status'=>'Approved','review_remarks'=>'Historical review','reviewed_by'=>'Adviser One','reviewed_at'=>'2026-10-01','rpms_submitted_at'=>null,'rpms_submitted_by'=>null,'admin_override'=>0,'override_reason'=>null,'override_by'=>null,'override_at'=>null,'detected_approval_date'=>null,'approval_date_source'=>null,'ai_summary'=>'Historical saved summary'];
    $pdo->exec('CREATE TABLE documents ('.implode(',',array_map(fn($key)=>$key.' '.(in_array($key,['student_id','size','version_no','is_current','admin_override'])?'INTEGER':'TEXT'),array_keys($doc))).')');
    $pdo->prepare('INSERT INTO documents VALUES ('.implode(',',array_fill(0,count($doc),'?')).')')->execute(array_values($doc));
    $pdo->exec('CREATE TABLE ierb_history (id INTEGER,student_id INTEGER,stage TEXT,status TEXT,note TEXT,created_at TEXT)');
    $pdo->exec("INSERT INTO ierb_history VALUES (1,3,'Stage 1','Pending','Historical C audit','2026-10-01')");
    $pdo->exec('CREATE TABLE activity_logs (id INTEGER,student_id INTEGER,action TEXT,details TEXT,reason TEXT,actor_name TEXT,user_email TEXT,is_override INTEGER,created_at TEXT)');
    $pdo->exec("INSERT INTO activity_logs VALUES (1,3,'historical','Preserved archive','','Admin','admin@example.test',0,'2026-10-01')");
    $pdo->exec('CREATE TABLE reports (id TEXT,title TEXT,type TEXT,filename TEXT,generated_by TEXT,generated_by_user_id INTEGER,generated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    if (($case['role']??'')==='student') $case['email']='s3@example.test';
    $GLOBALS['actor']=['id'=>1,'role'=>$case['role']??'admin','email'=>$case['email']??(($case['role']??'admin')==='adviser'?'adv@example.test':'admin@example.test'),'full_name'=>'Synthetic Admin'];
    $_GET=['action'=>$case['action']??'list']+($case['query']??[]); $_POST=$case['post']??[];
    $_POST += ['documentType'=>'Study Protocol'];
    if (isset($_POST['studentDbId'])) $_POST['studentDbId']=(string)$_POST['studentDbId'];
    $uploadFixture=__DIR__.'/fixtures/batch6-valid.pdf';
    $_FILES=['document'=>['error'=>0,'name'=>'protocol.pdf','tmp_name'=>$uploadFixture,'size'=>filesize($uploadFixture),'type'=>'application/pdf']];
    $_SERVER=['REQUEST_METHOD'=>'POST']; $GLOBALS['status']=200; $GLOBALS['headers']=[]; $GLOBALS['audits']=[]; $GLOBALS['provider']=[]; $GLOBALS['notices']=[];
    define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
    foreach (['ATTENTION_REVIEW_DAYS'=>3,'ATTENTION_UNSUBMITTED_DAYS'=>3,'ATTENTION_OVERDUE_DAYS'=>30,'ADVISERS_REVIEW_ONLY_OWN_STUDENTS'=>true,'OVERRIDE_MIN_REASON_LENGTH'=>5] as $key=>$value) define($key,$value);
    define('DOCS_DIR',__DIR__.'/fixtures/document-summary');
    $temp=sys_get_temp_dir().'/prism-operational-'.bin2hex(random_bytes(8)); mkdir($temp); define('REPORTS_DIR',$temp);
    foreach (['includes/csv_export.php','includes/pagination.php','includes/academic_catalog.php','includes/record_filters.php','includes/research_groups.php'] as $file) {
        $s=file_get_contents(__DIR__.'/../'.$file);
        $s=str_replace("require_once __DIR__ . '/academic_catalog.php';",'',$s);
        eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\RuntimeException; use \\InvalidArgumentException;'.preg_replace('/^<\?php\s*/','',$s));
    }
    $wf=file_get_contents(__DIR__.'/../workflow.php'); preg_match_all('/^const WF_[^;]+;/m',$wf,$constants);
    eval('namespace '.__NAMESPACE__.';'.implode("\n",$constants[0]));
    foreach (['document_workflow_state','document_is_locked','audit_action_label','empty_doc_counts','student_document_counts','workflow_days_since','plural_days','students_needing_attention','advance_stage_for_document','override_reason_valid','override_reason_message'] as $fn) eval('namespace '.__NAMESPACE__.'; use \\PDO;'.part('workflow.php',$fn));
    if (!empty($case['records'])) {
        $base=$pdo->query('SELECT * FROM students WHERE id=1')->fetch();
        for ($id=4;$id<=$case['records'];$id++) { $row=array_replace($base,['id'=>$id,'student_id'=>'ST-'.$id,'email'=>'s'.$id.'@example.test']); $q->execute(array_values($row)); }
    }
    if (!empty($case['unsafe'])) {
        $pdo->prepare('UPDATE students SET full_name=?,research_title=?,requirements=?,course=?,program_key=?,academic_unit_key=? WHERE id=1')->execute(['=SUM(1,2)',"Caf\u{00E9}, \"quoted\"\nmultiline", "\tformula",'Unsupported Legacy Course','legacy_program','legacy_unit']);
    }
    if (!empty($case['richDocs'])) {
        foreach ([['id'=>'older-history','version_no'=>0,'is_current'=>0],['id'=>'unlinked-history','student_id'=>null,'student_name'=>'Historical unlinked']] as $changes) {
            $r=array_replace($doc,$changes);$pdo->prepare('INSERT INTO documents VALUES ('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));
        }
    }
    if (!empty($case['empty'])) foreach (['documents','students','advisers'] as $table) $pdo->exec('DELETE FROM '.$table);
    require_once __DIR__.'/retention-fixture-support.php';retention_fixture_support(__NAMESPACE__,true);
    $snapshot=[]; foreach (['students','advisers','documents','ierb_history','activity_logs'] as $table) $snapshot[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
    ob_start(); register_shutdown_function(function() use($pdo,$temp,$snapshot) {
        $body=ob_get_clean(); $pdf=''; foreach (glob($temp.'/*.pdf') as $file) { $pdf.=file_get_contents($file); unlink($file); } rmdir($temp);
        $after=[]; foreach (array_keys($snapshot) as $table) $after[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
        echo json_encode(['status'=>$GLOBALS['status'],'response'=>$GLOBALS['response']??null,'body'=>base64_encode($body),'headers'=>$GLOBALS['headers'],'audits'=>$GLOBALS['audits'],'provider'=>$GLOBALS['provider'],'notices'=>$GLOBALS['notices'],'pdf'=>$pdf,'unchanged'=>$after===$snapshot],JSON_INVALID_UTF8_SUBSTITUTE);
    });
    $s=file_get_contents(__DIR__.'/../'.$case['file']);
    $s=str_replace(["require_once __DIR__.'/includes/account_lifecycle.php';","require_once __DIR__ . '/includes/account_lifecycle.php';"],'',$s);
    // Only known local bootstrap/include statements can be removed. No live config is ever evaluated.
    $s=preg_replace("~require(?:_once)? __DIR__ \\. '/(?:config|workflow|ai_helpers|includes/(?:pagination|record_filters|academic_catalog|research_groups|office_container|document_summary|document_catalog|document_upload|notification_delivery|calendar_deadlines|csv_export))\\.php';~",'',$s);
    if (preg_match('/\b(?:require|include)(?:_once)?\s*(?:\(|[\'"$])/',$s)) throw new RuntimeException('Unexpected fixture dependency');
    foreach (['row_to_student','csv_safe'] as $callback) $s=str_replace("'".$callback."'", "'".__NAMESPACE__."\\".$callback."'", $s);
    eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\Throwable; use \\RuntimeException; use \\InvalidArgumentException;'.preg_replace('/^<\?php\s*/','',$s));
    exit;
}

$checks=0;
function verify(bool $ok,string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
function endpoint(array $case): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    verify($exit===0&&$err==='', 'Endpoint fixture clean: '.$err.' '.$out); return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$r=endpoint(['file'=>'students_api.php','query'=>['lifecycle'=>'all']]); verify($r['response']['total']===3,'Explicit All Admin Student Records preserves A/B/C');
foreach ([[],['activeOnly'=>'1']] as $query) { $r=endpoint(['file'=>'students_api.php','action'=>'options','query'=>$query]); verify(count($r['response']['students'])===($query?2:3),'Explicit operational options preserve general historical selector'); }
foreach (['admin','adviser'] as $role) {
    $r=endpoint(['file'=>'ierb_api.php','role'=>$role]);verify($r['response']['total']===2,'Current list has A/B only');
    verify(array_sum(array_column($r['response']['overview'],'c'))===2,'Overview counts only A/B');
    verify(!in_array('AMT-BSIT-Y2-2627-G03',array_column($r['response']['filterOptions']['group'],'value'),true),'No archived-only filter');
    $r=endpoint(['file'=>'ierb_api.php','role'=>$role,'action'=>'stage_distribution']);verify(array_sum(array_column($r['response']['distribution'],'c'))===2,'Distribution active total two');
    $r=endpoint(['file'=>'ierb_api.php','role'=>$role,'action'=>'export_csv']);$csv=base64_decode($r['body']);verify(str_contains($csv,'ST-1')&&str_contains($csv,'ST-2')&&!str_contains($csv,'ST-3'),'Current CSV preserves role scope and excludes C');
    $r=endpoint(['file'=>'send_followup.php','role'=>$role,'data'=>['studentDbId'=>3]]);verify($r['status']===404&&!$r['notices']&&$r['unchanged'],'Archived follow-up rejected without mutation/delivery');
}
foreach (['summary','full'] as $mode) foreach (['success','fallback'] as $provider) {
    $r=endpoint(['file'=>'reports_api.php','action'=>'ai_report','data'=>compact('mode'),'provider'=>$provider]);
    verify($r['status']===200&&$r['unchanged'],'AI report preserves source records');
    $input=$r['provider'][0]['input'];$facts=json_decode(substr($input,strpos($input,'{')),true,512,JSON_THROW_ON_ERROR);
    verify($facts['recordCount']===2&&count($facts['cases'])===2,'Provider structured dataset has A/B only');
    verify(!str_contains($input,'ARCHIVED REQUIREMENT')&&!str_contains($r['pdf'],'ST-3')&&!str_contains($r['pdf'],'Archived C'),'AI narrative/factual/ID tables exclude C');
    verify(str_contains($r['response']['report']['name'],'All Active Students'),'Truthful active scope wording');
}
foreach (['','Stage 1'] as $stage) { $r=endpoint(['file'=>'reports_api.php','action'=>'generate','data'=>['type'=>'Progress Report','stage'=>$stage]]);verify($r['status']===200&&str_contains($r['pdf'],'ST-1')&&!str_contains($r['pdf'],'ST-3'),'Normal/stage report excludes matching archived C'); }
$r=endpoint(['file'=>'reports_api.php','action'=>'generate','data'=>['type'=>'Student Report','studentId'=>3]]);verify($r['status']===404&&$r['pdf']===''&&$r['unchanged'],'Direct C report unavailable');
foreach ([['studentDbId'=>3],['student'=>'Archived C'],['student'=>'ST-3']] as $post) { $r=endpoint(['file'=>'documents_api.php','action'=>'upload','post'=>$post]);verify($r['status']===422&&$r['unchanged'],'ID/name/legacy upload cannot target C'); }
$r=endpoint(['file'=>'documents_api.php','action'=>'submit_to_rpms','data'=>['id'=>'cccccccccccccccccccccccc']]);verify($r['status']===409&&$r['unchanged'],'New formal submission cannot target C');
$r=endpoint(['file'=>'documents_api.php']);verify(count($r['response']['documents'])===1&&$r['response']['documents'][0]['student']==='Archived C','Admin historical document remains visible');
$r=endpoint(['file'=>'documents_api.php','action'=>'file','query'=>['id'=>'cccccccccccccccccccccccc','download'=>'1']]);verify(str_contains(base64_decode($r['body']),'informed consent')&&$r['unchanged'],'Admin historical document remains downloadable');
$r=endpoint(['file'=>'ierb_api.php','action'=>'history','query'=>['studentId'=>3]]);verify(count($r['response']['history'])===1,'Admin archived IERB history retained');
$r=endpoint(['file'=>'audit_api.php','query'=>['studentId'=>3]]);verify($r['response']['total']===1,'Admin archived audit retained');
foreach (['admin','adviser'] as $role) {
    $r=endpoint(['file'=>'documents_api.php','action'=>'upload','role'=>$role,'post'=>['studentDbId'=>3]]);verify($r['status']===422&&$r['unchanged'],'Role upload rejects archived C');
    $r=endpoint(['file'=>'calendar_deadlines_api.php','action'=>'group_options','role'=>$role]);verify(count($r['response']['groups'])===2,'Operational deadline groups exclude archive-only group');
    $r=endpoint(['file'=>'notifications_api.php','action'=>'recipients_preview','role'=>$role,'data'=>['audience'=>'All Students']]);verify(count($r['response']['recipients'])===2,'Broadcast audience includes A/B only');
}
$r=endpoint(['file'=>'documents_api.php','action'=>'upload','role'=>'student']);verify($r['status']===409&&$r['unchanged'],'Archived Student account cannot upload even with forced fixture authentication');
$r=endpoint(['file'=>'ierb_api.php','action'=>'needs_attention']);verify(!in_array(3,array_column($r['response']['students'],'id'),true),'Attention excludes C');
// Execute the real initial dashboard SQL against the same A/B/C fixture through a dedicated child.
$source=file_get_contents(__DIR__.'/../dashboard.php');preg_match_all('/\$([a-z_]+) = \(int\)\$pdo->query\(([^\n]+)\)->fetchColumn\(\);/',$source,$matches,PREG_SET_ORDER);
$pdo=new PDO('sqlite::memory:');$pdo->exec("CREATE TABLE students (archived_at TEXT,status TEXT,stage TEXT,profile_completed_at TEXT DEFAULT '2026-01-01'); INSERT INTO students (archived_at,status,stage) VALUES (NULL,'Pending','Stage 1'),(NULL,'On Track','Completed'),('2026-10-02','Pending','Stage 1')");
$values=[];foreach($matches as $m) $values[$m[1]]=(int)$pdo->query(eval('return '.$m[2].';'))->fetchColumn();
verify($values===['total_researchers'=>2,'pending_ierb'=>1,'approved_ethics'=>1,'delayed_submissions'=>0],'Initial dashboard counters use two active records');
echo "PASS: $checks real SQL archive/operational endpoint assertions; historical records preserved.\n";
