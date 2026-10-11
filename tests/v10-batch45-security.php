<?php
/** Actual guards, endpoints and retention services; memory-only fixtures, no config bootstrap. */
if (PHP_SAPI !== 'cli') exit(1);
if (($argv[1] ?? '') === '--route') {
    $role=$argv[2] === 'anonymous' ? null : $argv[2];$reached=false;$dbCalls=0;
    function current_user(): ?array {return $GLOBALS['role'] ? ['role'=>$GLOBALS['role']] : null;}
    function db(): PDO {++$GLOBALS['dbCalls'];throw new RuntimeException('Unexpected DB bootstrap');}
    $_SESSION=[];$_GET=['type'=>$argv[3] ?? 'student'];$_SERVER=['SCRIPT_NAME'=>'admin_archived_accounts.php'];
    $config=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../config.php'));
    $start=strpos($config,'function require_login(');$end=strpos($config,"\n}\n",$start);
    eval(substr($config,$start,$end+2-$start));
    register_shutdown_function(fn()=>print(json_encode(['reached'=>$GLOBALS['reached'],'dbCalls'=>$GLOBALS['dbCalls']])));
    $shared=file_get_contents(__DIR__.'/../admin_people.php');
    $shared=substr($shared,0,strpos($shared,"require_once __DIR__ . '/includes/academic_catalog.php';"));
    $shared=str_replace("require __DIR__ . '/config.php';",'',$shared);
    $wrapper=file_get_contents(__DIR__.'/../admin_archived_accounts.php');
    $wrapper=str_replace("require __DIR__ . '/admin_people.php';",'?>'.$shared.' $reached=true;',$wrapper);
    eval(preg_replace('/^<\?php\s*/','',$wrapper));exit;
}
$checks=0;
function verify(bool $ok,string $label): void {++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($label);}
function fixture(string $file,array $case): array {
    $p=proc_open([PHP_BINARY,__DIR__.'/'.$file,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($p)===0&&$err==='','Clean actual endpoint fixture: '.$err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
foreach(['anonymous','student','adviser','admin'] as $role)foreach(['student','adviser'] as $type) {
    $p=proc_open([PHP_BINARY,__FILE__,'--route',$role,$type],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$r=json_decode($out,true);
    verify($exit===0&&$err===''&&$r['reached']===($role==='admin')&&$r['dbCalls']===0,'Archive route uses original role guard: '.$role.'/'.$type);
}
$sql=["UPDATE students SET archived_at='2026-01-01' WHERE id IN (2,5)","UPDATE advisers SET archived_at='2026-01-01',status='Inactive' WHERE id=2"];
foreach(['students_api.php','advisers_api.php','bulk'] as $file)foreach([null,'','active','archived','all'] as $state) {
    $query=$state===null?[]:['lifecycle'=>$state];$r=fixture('v10-batch3-filters.php',compact('file','query','sql'));
    $ids=$file==='bulk'?$r['data']['ids']:array_column($r['data'][$file==='advisers_api.php'?'advisers':'students'],'id');
    $archived=$file==='advisers_api.php'?[2]:[2,5];$active=$file==='advisers_api.php'?[1]:[1,3,4,6,7,8];
    $expected=$state==='archived'?$archived:($state==='all'?array_values(array_unique(array_merge($active,$archived))):$active);sort($expected);sort($ids);
    verify($r['status']===200&&$ids===$expected,'Actual list/bulk reconstructs '.$file.'/'.($state??'default'));
}
foreach(['archived','active'] as $state) {
    $query=['lifecycle'=>$state,'q'=>'Student','sortBy'=>'archivedAt','direction'=>'DESC'];
    $r=fixture('v10-batch3-filters.php',['file'=>'students_api.php','query'=>$query,'sql'=>$sql]);
    $b=fixture('v10-batch3-filters.php',['file'=>'bulk','query'=>array_diff_key($query,['sortBy'=>true,'direction'=>true]),'sql'=>$sql]);
    $ids=array_column($r['data']['students'],'id');$bulk=$b['data']['ids'];sort($ids);sort($bulk);verify($ids===$bulk,'Sort cannot widen reconstructed '.$state.' search');
}
foreach(['student','adviser'] as $type)foreach([1,2] as $id) {
    $r=fixture('v10-batch3-filters.php',['file'=>'account_state','accountType'=>$type,'targetId'=>$id,'sql'=>$sql]);
    verify($r['status']===200&&array_key_exists('archivedAt',$r['data']['lifecycle']),'Lifecycle state remains readable for both populations: '.$type.'/'.$id);
}
$gA='AMT-BSIT-Y2-2627-G01';$gB='AMT-BSIT-Y2-2627-G03';
$deadlineSql=["INSERT INTO calendar_deadlines(id,creator_user_id,title,description,deadline_date,target_scope,status) VALUES (1,10,'A','','2026-10-10','groups','Active'),(2,10,'B','','2026-10-10','groups','Active'),(3,10,'AB','','2026-10-10','groups','Active'),(4,10,'Global','','2026-10-10','all','Active'),(5,10,'Cancelled','','2026-10-10','groups','Cancelled'),(6,11,'Adviser A','','2026-10-10','groups','Active')",
    "INSERT INTO calendar_deadline_groups VALUES (1,'$gA'),(2,'$gB'),(3,'$gA'),(3,'$gB'),(5,'$gA'),(6,'$gA')",
    'INSERT INTO calendar_deadline_recipients VALUES (6,1)'];
$viewers=[['role'=>'student','email'=>'s1@example.test','expected'=>[6,4,3,1],'group'=>$gA],['role'=>'student','email'=>'s4@example.test','expected'=>[4,3,2],'group'=>$gB],
    ['role'=>'adviser','expected'=>[6,4,3,1],'group'=>$gA],['role'=>'adviser','email'=>'other@example.test','viewerId'=>99,'expected'=>[4,3,2],'group'=>$gB],['role'=>'admin','expected'=>[6,4,3,2,1]]];
foreach($viewers as $viewer)foreach([[],['group'=>$gB,'studentId'=>4,'creator_user_id'=>99,'manage'=>'1']] as $crafted) {
    $r=fixture('calendar-deadlines-audit.php',$viewer+['action'=>'dashboard_day','method'=>'GET','sql'=>$deadlineSql,'query'=>['date'=>'2026-10-10']+$crafted]);
    verify($r['status']===200&&array_map('intval',array_column($r['response']['deadlines'],'id'))===$viewer['expected'],'Exact read-only scope resists crafted group/identity/manage');
    foreach($r['response']['deadlines'] as $row)if($viewer['role']!=='admin') {
        verify(!array_diff($row['groups'],[$viewer['group']]),'No unrelated mixed-target group labels');
        verify(!isset($row['creator_user_id'],$row['canCancel']),'No creator internals or mutation controls');
    }
    verify(!$r['notifications']&&!$r['mail'],'Read-only deadline lookup never delivers notifications');
}
foreach([['role'=>'student','email'=>'s1@example.test'],['role'=>'adviser']] as $viewer) {
    $r=fixture('calendar-deadlines-audit.php',$viewer+['action'=>'dashboard_day','method'=>'GET','sql'=>$deadlineSql,'lateSql'=>['UPDATE students SET adviser_id=2 WHERE id IN (1,2,3)'],'query'=>['date'=>'2026-10-10']]);
    $expected=$viewer['role']==='student'?[4,3,1]:[4];verify(array_map('intval',array_column($r['response']['deadlines'],'id'))===$expected,'Reassignment immediately removes former assignment/creator visibility');
}
foreach(['dates','dashboard_day'] as $action) {
    $r=fixture('calendar-deadlines-audit.php',['role'=>'anonymous','action'=>$action,'method'=>'GET','query'=>['date'=>'2026-10-10']]);verify($r['status']===401,'Anonymous deadline reads denied');
}
foreach(['student','adviser'] as $role)foreach(['create','cancel'] as $action) {
    $case=['role'=>$role,'action'=>$action,'method'=>$role==='student'?'POST':'GET','data'=>['id'=>1],'sql'=>$deadlineSql];
    $r=fixture('calendar-deadlines-audit.php',$case);verify(in_array($r['status'],[403,405],true)&&!$r['notifications'],'Popup GET cannot mutate; Student mutation role denied');
}
require_once __DIR__.'/../includes/account_lifecycle.php';
class ClosedGateStatement extends PDOStatement {public function fetchColumn(int $column=0): mixed {return 1;}}
class ClosedGatePDO extends PDO {
    public int $writes=0;
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {if(!str_contains($query,'GET_LOCK')&&!str_contains($query,'RELEASE_LOCK'))throw new RuntimeException('Unexpected query');return new ClosedGateStatement();}
    public function inTransaction(): bool {return false;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {++$this->writes;throw new RuntimeException('Unexpected write');}
}
$pdo=new ClosedGatePDO();
foreach(['student','adviser'] as $type) {
    try {retention_purge($pdo,['role'=>'admin','id'=>1],['accountType'=>$type,'targetId'=>1,'action'=>'permanent_delete','currentPassword'=>'fixture','confirmation'=>'S1','confirmed'=>true]);verify(false,'Invalid verification accepted');}
    catch(AccountLifecycleConflict $e) {verify(str_contains($e->getMessage(),'verification')&&$pdo->writes===0,'Invalid deployment verification blocks purge before mutation');}
    foreach(['manual','retention_cleanup','grace_period_override'] as $method) {
        try {retention_require_eligible(['archived_at'=>null,'employee_id'=>'A1','status'=>'Active','grace_elapsed'=>1,'retention_elapsed'=>1],$method);verify(false,'Active purge accepted');}
        catch(AccountLifecycleConflict $e) {verify(str_contains($e->getMessage(),'Archive first'),'Direct active purge rejected: '.$method);}
        try {retention_require_eligible(['archived_at'=>'2026-01-01','retention_hold'=>1],$method);verify(false,'Held purge accepted');}
        catch(AccountLifecycleConflict $e) {verify(str_contains($e->getMessage(),'Hold'),'Hold blocks all methods: '.$method);}
    }
}
echo "PASS: $checks Batch 4.5 route, list/bulk, deadline-scope/reassignment and purge-gate checks; no application DB loaded.\n";
