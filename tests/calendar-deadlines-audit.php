<?php
/** Actual endpoint + real scoped SQLite SQL, existing guards/delivery; no config/bootstrap/live services. */
if (PHP_SAPI !== 'cli') exit(1);
function check(bool $ok, string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
function function_source(string $source, string $name): string {
    $source = str_replace("\r\n", "\n", $source); $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, "\n}\n", $start); return substr($source, $start, $end + 2 - $start);
}
if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR); $checks = 0; $errors = []; $mail = [];
    class FixturePDO extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false {
            if (!empty($GLOBALS['case']['insertFailure']) && str_starts_with($query, 'INSERT INTO notifications')) throw new RuntimeException('Simulated notification insert failure');
            return parent::prepare(str_replace(['FOR UPDATE', 'BINARY '], '', $query), $options);
        }
    }
    $pdo = new FixturePDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE advisers (id INTEGER PRIMARY KEY, email TEXT, assigned_groups TEXT)');
    $pdo->exec("INSERT INTO advisers VALUES (1,'adviser@example.test','LEGACY OTHER GROUP'),(2,'other@example.test','OWN GROUP')");
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, email TEXT, full_name TEXT, adviser_id INTEGER, research_group TEXT,
        academic_unit_key TEXT, program_key TEXT, year_level TEXT, academic_year TEXT)');
    $insert = $pdo->prepare('INSERT INTO students VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ([[1,1,'G01'],[2,1,'G01'],[3,1,'G02'],[4,2,'G03'],[5,2,'Legacy']] as [$id,$adviser,$suffix]) {
        $insert->execute([$id,'s'.$id.'@example.test','Student '.$id,$adviser,$suffix==='Legacy'?$suffix:'AMT-BSIT-Y2-2627-'.$suffix,'amt','bsit','2nd Year','2026-2027']);
    }
    $pdo->exec("CREATE TABLE calendar_deadlines (id INTEGER PRIMARY KEY AUTOINCREMENT,creator_user_id INTEGER,title TEXT,description TEXT,deadline_date TEXT,target_scope TEXT,status TEXT DEFAULT 'Active',created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE calendar_deadline_groups (deadline_id INTEGER, research_group TEXT, PRIMARY KEY(deadline_id,research_group))');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_type TEXT, recipient_id INTEGER, recipient_email TEXT, recipient_name TEXT, subject TEXT, message TEXT, type TEXT,status TEXT,delivery_info TEXT,scheduled_at TEXT,sent_at TEXT,created_by TEXT)');
    $pdo->exec('ALTER TABLE students ADD COLUMN archived_at TEXT');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, email TEXT)');
    $pdo->exec("INSERT INTO users VALUES (10,'admin','admin@example.test'),(11,'adviser','adviser@example.test'),(99,'adviser','other@example.test')");
    $pdo->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'Active'");
    $pdo->exec("ALTER TABLE users ADD COLUMN full_name TEXT DEFAULT 'Fixture Actor'");
    $pdo->exec('ALTER TABLE calendar_deadlines ADD COLUMN creator_name TEXT');
    $pdo->exec('CREATE TABLE calendar_deadline_recipients (deadline_id INTEGER, student_id INTEGER, PRIMARY KEY(deadline_id,student_id))');
    foreach ($case['sql'] ?? [] as $sql) $pdo->exec($sql);
    $role = $case['role'] ?? 'admin';
    $actor = $role === 'anonymous' ? null : ['id'=>$role==='admin'?10:($role==='adviser'?11:12), 'role'=>$role,
        'email'=>$role==='adviser'?'adviser@example.test':($role==='student'?($case['email']??'s1@example.test'):'admin@example.test'), 'full_name'=>'Fixture Actor'];
    $_SESSION = []; $_SERVER = ['SCRIPT_NAME'=>'calendar_deadlines_api.php','REQUEST_METHOD'=>$case['method']??'POST','HTTP_HOST'=>'prism.test','HTTP_ORIGIN'=>$case['origin']??'http://prism.test'];
    $_GET = ['action'=>$case['action']??'create'] + ($case['query']??[]);
    function db(): PDO { return $GLOBALS['pdo']; }
    function current_user(): ?array { return $GLOBALS['actor']; }
    function json_body(): array { return $GLOBALS['case']['data']??[]; }
    function json_out(array $data, int $status=200): never { http_response_code($status); echo json_encode($data); exit; }
    function log_api_error(...$args): void { $GLOBALS['errors'][]=$args; }
    function log_activity(...$args): void {}
    function audit_log(...$args): void {}
    function send_notification_email(string $email, string $subject, string $body): array {
        check(!$GLOBALS['pdo']->inTransaction(), 'Delivery occurs after commit');
        $GLOBALS['mail'][]=$email;
        if (!empty($GLOBALS['case']['mailThrow'])) throw new RuntimeException('Transport failed');
        return ['ok'=>empty($GLOBALS['case']['mailFail']), 'channel'=>'fixture', 'message'=>'Synthetic delivery'];
    }
    require_once __DIR__.'/../includes/account_onboarding.php';
    foreach(['students','advisers'] as $table) { $pdo->exec("ALTER TABLE $table ADD COLUMN profile_completed_at TEXT DEFAULT '2026-01-01 00:00:00'"); $pdo->exec("ALTER TABLE $table ADD COLUMN user_id INTEGER"); if($table==='advisers')$pdo->exec('ALTER TABLE advisers ADD COLUMN archived_at TEXT'); }
    $pdo->exec('ALTER TABLE advisers ADD COLUMN employee_id TEXT');
    $pdo->exec('ALTER TABLE students ADD COLUMN student_id TEXT');
    $pdo->exec("ALTER TABLE advisers ADD COLUMN status TEXT DEFAULT 'Active'");
    foreach ($case['lateSql'] ?? [] as $sql) $pdo->exec($sql);
    require __DIR__.'/../security.php';
    $config=file_get_contents(__DIR__.'/../config.php');
    eval(function_source($config,'api_require_login').function_source($config,'require_post_same_origin'));
    require __DIR__.'/../includes/calendar_deadlines.php';
    ob_start();
    register_shutdown_function(function() {
        $out=ob_get_clean();
        echo json_encode(['status'=>http_response_code()?:200,'response'=>json_decode($out,true),'raw'=>$out,
            'deadlines'=>$GLOBALS['pdo']->query('SELECT * FROM calendar_deadlines')->fetchAll(),
            'groups'=>$GLOBALS['pdo']->query('SELECT * FROM calendar_deadline_groups')->fetchAll(),
            'notifications'=>$GLOBALS['pdo']->query('SELECT * FROM notifications')->fetchAll(), 'mail'=>$GLOBALS['mail'],'errors'=>$GLOBALS['errors']]);
    });
    $source=file_get_contents(__DIR__.'/../calendar_deadlines_api.php');
    $source=str_replace(["require __DIR__ . '/config.php';", "require_once __DIR__ . '/includes/calendar_deadlines.php';", "require_once __DIR__ . '/workflow.php';"], '', $source);
    eval(preg_replace('/^<\?php\s*/','',$source)); exit;
}
$checks=0;
function run_case(array $case): array {
    $proc=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($proc)===0 && $err==='', 'Endpoint executes without warning: '.$err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$g1='AMT-BSIT-Y2-2627-G01'; $g2='AMT-BSIT-Y2-2627-G02'; $g3='AMT-BSIT-Y2-2627-G03';
$base=['title'=>'<img src=x onerror=alert(1)>','date'=>'2026-10-10','description'=>'<script>Stored text only</script>','target'=>'all','groups'=>[]];
foreach ([['admin','all',[],5],['admin','selected',[$g1],2],['admin','selected',[$g1,$g3],3],['adviser','all',[],3],['adviser','selected',[$g1],2],['adviser','selected',[$g1,$g2,$g1],3]] as [$role,$target,$groups,$recipients]) {
    $r=run_case(['role'=>$role,'data'=>array_replace($base,['target'=>$target,'groups'=>$groups,'creator_user_id'=>999,'status'=>'Cancelled'])]);
    check($r['status']===200 && $r['response']['ok'], "$role creates $target");
    check(count($r['deadlines'])===1 && $r['deadlines'][0]['status']==='Active', 'One active canonical record, mass assignment blocked');
    check((int)$r['deadlines'][0]['creator_user_id']===($role==='admin'?10:11),'Authenticated creator');
    check(count($r['notifications'])===$recipients && count($r['mail'])===0 && $r['response']['delivery']['queued']===$recipients,'Exact scoped delivery attempts');
    check(count(array_unique(array_column($r['notifications'],'recipient_id')))===$recipients,'No duplicate recipients');
    check($r['deadlines'][0]['title']===$base['title'],'Title stored as literal content');
    check(count($r['groups'])===($role==='admin'&&$target==='all'?0:($target==='all'?2:count(array_unique($groups)))),'Canonical target mappings');
}
foreach ([['admin',[$g1,'invalid']],['adviser',[$g1,$g3]],['adviser',['LEGACY OTHER GROUP']],['adviser',[$g1,"x') OR 1=1 --"]]] as [$role,$groups]) {
    $r=run_case(['role'=>$role,'data'=>array_replace($base,['target'=>'selected','groups'=>$groups])]);
    check($r['status']===403 && !$r['deadlines'] && !$r['mail'],'Entire unauthorized target rejected');
}
foreach ([['groups'=>[$g1]],['target'=>'selected','groups'=>[]],['groups'=>'arbitrary'],['groups'=>['arbitrary'=>$g1]],['date'=>'2026-02-30'],['title'=>[]],['description'=>[]],['title'=>str_repeat('x',191)],['description'=>str_repeat('x',2001)],['target'=>'selected','groups'=>[['x']]]] as $bad) {
    $r=run_case(['data'=>array_replace($base,$bad)]);check($r['status']===422 && !$r['deadlines'],'Malformed data rejected');
}
foreach (['mailFail','mailThrow','insertFailure'] as $fail) {
    $r=run_case(['data'=>$base,$fail=>true]);
    check($r['status']===200 && count($r['deadlines'])===1,'Delivery failure never corrupts canonical persistence');
    check($fail==='insertFailure'?(!$r['notifications']&&$r['response']['delivery']['notificationFailures']===5):(count($r['notifications'])===5&&$r['notifications'][0]['status']==='Scheduled'),'Existing in-app persistence/delivery failure semantics');
}
$r=run_case(['data'=>array_replace($base,['target'=>'selected','groups'=>[$g1]]),
    'sql'=>["CREATE TRIGGER reject_mapping BEFORE INSERT ON calendar_deadline_groups BEGIN SELECT RAISE(ABORT,'Synthetic mapping failure'); END"]]);
check($r['status']===500&&!$r['deadlines']&&!$r['mail'],'Mapping failure rolls back entire canonical create before delivery');
foreach (['create','cancel'] as $action) {
    $r=run_case(['role'=>'student','action'=>$action,'data'=>$base]); check($r['status']===403&&!$r['deadlines'],'Student mutation denied: '.$action);
}
foreach (['update','delete'] as $action) {
    foreach (['admin','adviser','student'] as $role) {
        $r=run_case(['role'=>$role,'action'=>$action,'data'=>$base]);
        check($r['status']===400&&!$r['deadlines']&&!$r['mail'],'Unsupported action unavailable without mutation: '.$role.' '.$action);
    }
}
check(run_case(['role'=>'anonymous'])['status']===401,'Anonymous rejected');
foreach (['http://evil.test',''] as $origin) check(run_case(['origin'=>$origin,'data'=>$base])['status']===403,'CSRF denied');
check(run_case(['method'=>'GET','data'=>$base])['status']===405,'Mutation POST required');
$sql=["INSERT INTO calendar_deadlines (id,creator_user_id,title,description,deadline_date,target_scope,status) VALUES (1,10,'Global','','2026-10-10','all','Active'),(2,11,'Own','','2026-10-10','groups','Active'),(3,99,'Other','','2026-10-10','groups','Active'),(4,11,'Cancelled','','2026-10-10','groups','Cancelled')",
    "INSERT INTO calendar_deadline_groups VALUES (2,'$g1'),(2,'$g2'),(3,'$g3'),(4,'$g1')",
    "INSERT INTO calendar_deadline_recipients VALUES (2,1),(2,2),(2,3),(3,4),(4,1),(4,2)"];
foreach ([['admin',null,[3,2,1]],['adviser',null,[2,1]],['student','s1@example.test',[2,1]],['student','s4@example.test',[3,1]],['student','s5@example.test',[1]]] as [$role,$email,$ids]) {
    $case=['role'=>$role,'action'=>'list','sql'=>$sql,'query'=>['from'=>'2026-10-10','to'=>'2026-10-10']];if($email)$case['email']=$email;
    $r=run_case($case); check(array_map('intval',array_column($r['response']['deadlines'],'id'))===$ids,'Correct list scope: '.$role.' '.$email);
    check($r['response']['total']===count($ids),'Scoped count matches');
    if($role==='student') foreach($r['response']['deadlines'] as $row)check(!$row['canCancel']&&!$row['groups'],'Student read-only, no other group disclosure');
    $case['action']='dates';$r=run_case($case);check((int)$r['response']['dates'][0]['total']===count($ids),'Same marker authorization');
}
check(run_case(['role'=>'student','action'=>'list','sql'=>$sql,'query'=>['manage'=>'1']])['status']===403,'No Student management view');
$r=run_case(['role'=>'adviser','action'=>'list','sql'=>$sql,'query'=>['manage'=>'1','from'=>'2026-10-10']]);
check(array_map('intval',array_column($r['response']['deadlines'],'id'))===[4,2],'Adviser management includes only own active/cancelled records');
$r=run_case(['role'=>'student','action'=>'list','sql'=>array_merge($sql,["UPDATE students SET research_group='$g3' WHERE id=1"]),'query'=>['from'=>'2026-10-10']]);
check(array_map('intval',array_column($r['response']['deadlines'],'id'))===[1],'Student group change cannot expose another adviser deadline');
foreach (['bad',0,-1,[],2147483648] as $id)check(run_case(['action'=>'cancel','data'=>['id'=>$id],'sql'=>$sql])['status']===422,'Invalid ID denied');
$r=run_case(['role'=>'adviser','action'=>'cancel','data'=>['id'=>3],'sql'=>$sql]);check($r['status']===403&&$r['deadlines'][2]['status']==='Active','Cross-adviser cancel denied');
foreach (['admin','adviser'] as $role) {
    $r=run_case(['role'=>$role,'action'=>'cancel','data'=>['id'=>2],'sql'=>$sql]);check($r['status']===200&&$r['deadlines'][1]['status']==='Cancelled','Authorized cancellation');
    $r=run_case(['role'=>$role,'action'=>'cancel','data'=>['id'=>4],'sql'=>$sql]);check($r['status']===200&&!$r['mail'],'Repeat cancellation no duplicate email');
}
foreach ([['groups'=>[$g1],'target'=>'selected'],['target'=>'all']] as $data) {
    $r=run_case(['role'=>'adviser','data'=>array_replace($base,$data),'sql'=>['UPDATE students SET adviser_id=2 WHERE id IN (1,2,3)']]);
    check(in_array($r['status'],[403,422],true)&&!$r['deadlines'],'Reassignment immediately removes targeting authority');
}
$more=$sql;
for($i=5;$i<=29;$i++) $more[]="INSERT INTO calendar_deadlines (id,creator_user_id,title,description,deadline_date,target_scope) VALUES ($i,10,'Record $i','','2026-10-10','all')";
foreach ([1,2,3,999,-9] as $page) {
    $r=run_case(['role'=>'student','action'=>'list','sql'=>$more,'query'=>['from'=>'2026-10-10','page'=>(string)$page]]);
    $p=max(1,min(3,$page));check($r['response']['total']===27&&$r['response']['page']===$p,'Bounded scoped pages');
    check(count($r['response']['deadlines'])===($p===3?7:10),'Exactly ten or final boundary');
}
$r=run_case(['action'=>'list','query'=>['from'=>'2026-10-11']]);check($r['response']['total']===0&&!$r['response']['deadlines'],'Zero results');
check(run_case(['action'=>'dates','query'=>['from'=>'2026-01-01','to'=>'2026-12-31']])['status']===422,'Calendar range bounded');
echo "PASS: $checks official deadline endpoint/scope/security/delivery assertions; real isolated SQL, no live services.\n";
