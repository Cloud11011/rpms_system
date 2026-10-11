<?php
/** Real route and shared request guards, isolated identity; no DB/bootstrap/migration. */
if (PHP_SAPI !== 'cli') exit(1);
if (($argv[1] ?? '') === '--case') {
    $case=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    require_once __DIR__.'/../security.php';
    require_once __DIR__.'/../includes/account_lifecycle.php';
    $GLOBALS['dbCalls']=0;
    function current_user(): ?array { return $GLOBALS['case']['role']===null?null:['id'=>1,'role'=>$GLOBALS['case']['role'],'email'=>'fixture@example.invalid']; }
    function db(): PDO { ++$GLOBALS['dbCalls']; throw new RuntimeException('Unexpected database access'); }
    function json_out(array $data,int $status=200): never { http_response_code($status);echo json_encode($data);exit; }
    $_SESSION=[]; $_GET=['action'=>$case['action']];
    $_SERVER=['REQUEST_METHOD'=>$case['method'],'SCRIPT_NAME'=>'account_lifecycle_api.php','HTTP_HOST'=>'127.0.0.1:45678'];
    if(isset($case['origin']))$_SERVER['HTTP_ORIGIN']=$case['origin'];
    $config=file_get_contents(__DIR__.'/../config.php');
    foreach(['api_require_login','require_post_same_origin'] as $name) {
        $start=strpos($config,'function '.$name.'(');$end=strpos($config,"\n}\n",$start);
        if($start===false||$end===false)throw new RuntimeException('Guard extraction failed');
        eval(substr($config,$start,$end+2-$start));
    }
    ob_start();register_shutdown_function(function(){ $body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode($body,true),'dbCalls'=>$GLOBALS['dbCalls']]); });
    $source=file_get_contents(__DIR__.'/../account_lifecycle_api.php');
    $source=str_replace(["require __DIR__.'/config.php';","require_once __DIR__.'/includes/account_lifecycle.php';"],'',$source);
    eval(preg_replace('/^<\?php\s*/','',$source));exit;
}
$checks=0;
foreach([null,'student','adviser'] as $role)foreach(['archive','restore','hold','remove_hold','permanent_delete','retention_cleanup','bulk_execute','cleanup_summary','selection'] as $action)foreach(['GET','POST'] as $method) {
    $case=compact('role','action','method')+['expected'=>$role===null?401:403];
    $cases[]=$case;
}
foreach(['archive','permanent_delete','retention_cleanup','bulk_execute'] as $action) {
    $cases[]=['role'=>'admin','action'=>$action,'method'=>'GET','expected'=>422];
    foreach([null,'http://evil.invalid','http://127.0.0.1:45679','https://127.0.0.1:45678'] as $origin)
        $cases[]=['role'=>'admin','action'=>$action,'method'=>'POST','origin'=>$origin,'expected'=>403];
    $cases[]=['role'=>'admin','action'=>$action,'method'=>'PUT','expected'=>405];
}
foreach($cases as $case) {
    $process=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $data=json_decode($out,true);++$checks;
    if($exit!==0||$err!==''||($data['status']??0)!==$case['expected']||($data['dbCalls']??-1)!==0||($data['body']['ok']??true)!==false)
        throw new RuntimeException('Guard failure: '.json_encode($case).' '.$err.$out);
}
echo "PASS: $checks crafted lifecycle/retention requests rejected before database access; original route and guards.\n";
require_once __DIR__.'/../includes/account_lifecycle.php';
class Batch4LifecyclePDO extends PDO {
    public bool $transaction=false;
    public array $writes=[];
    public function __construct(public array $admins,public bool $transactional=true) {}
    public function beginTransaction(): bool { $this->transaction=true;return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction=false;return true; }
    public function rollBack(): bool { $this->transaction=false;$this->writes=[];return true; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new Batch4LifecycleStatement($this,$query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false { return new Batch4LifecycleStatement($this,$query); }
}
class Batch4LifecycleStatement extends PDOStatement {
    public function __construct(private Batch4LifecyclePDO $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        if(preg_match('/^(UPDATE|INSERT|DELETE)/',$this->sql))$this->db->writes[]=[$this->sql,$params];
        if(str_starts_with($this->sql,'UPDATE users SET status="Inactive"'))foreach($this->db->admins as &$admin)if($admin['id']===$params[':id'])$admin['status']='Inactive';
        return true;
    }
    public function rowCount(): int { return 1; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if(str_contains($this->sql,'INFORMATION_SCHEMA.TABLES'))return array_map(fn($name)=>['TABLE_NAME'=>$name,'ENGINE'=>$this->db->transactional?'InnoDB':'MyISAM'],['activity_logs','password_resets','users']);
        if(str_starts_with($this->sql,'SELECT id FROM users'))return array_map(fn($a)=>['id'=>$a['id']],$this->db->admins);
        if(str_starts_with($this->sql,'SELECT * FROM users')||str_starts_with($this->sql,'SELECT id,role,status FROM users'))return $this->db->admins;
        throw new RuntimeException('Unreviewed fixture query: '.$this->sql);
    }
    public function fetchColumn(int $column=0): mixed { return 0; } // Deny permanent-delete schema lock.
}
$actor=['id'=>1,'role'=>'admin','email'=>'fixture@example.invalid'];
$admin=$actor+['full_name'=>'Fixture Admin','username'=>'fixture','status'=>'Active','password_hash'=>password_hash('Synthetic test password',PASSWORD_DEFAULT)];
$second=['id'=>2,'role'=>'admin','status'=>'Active'];
$base=['accountType'=>'admin','action'=>'archive','targetId'=>1,'currentPassword'=>'Synthetic test password','confirmation'=>'ARCHIVE','confirmed'=>true];
$lifecycleCases=[
 ['name'=>'valid archive','success'=>true],
 ['name'=>'unchecked confirmation','change'=>['confirmed'=>false]],
 ['name'=>'missing password','change'=>['currentPassword'=>null]],
 ['name'=>'incorrect password','change'=>['currentPassword'=>'Incorrect password']],
 ['name'=>'incorrect exact identifier','change'=>['confirmation'=>'archive']],
 ['name'=>'last active Admin','admins'=>[$admin]],
 ['name'=>'other Admin inactive','admins'=>[$admin,['id'=>2,'role'=>'admin','status'=>'Inactive']]],
 ['name'=>'non-self Admin target','change'=>['targetId'=>2]],
 ['name'=>'nontransactional account table','transactional'=>false],
 ['name'=>'inactive actor','admins'=>[array_replace($admin,['status'=>'Inactive']),$second]],
 ['name'=>'Student lifecycle caller','actor'=>['id'=>1,'role'=>'student']],
 ['name'=>'Adviser lifecycle caller','actor'=>['id'=>1,'role'=>'adviser']],
 ['name'=>'permanent-delete schema lock gate','change'=>['action'=>'permanent_delete','confirmation'=>'DELETE']],
];
foreach($lifecycleCases as $case) {
    $pdo=new Batch4LifecyclePDO($case['admins']??[$admin,$second],$case['transactional']??true);
    $result=null;$error=null;
    try { $result=lifecycle_execute($pdo,$case['actor']??$actor,array_replace($base,$case['change']??[])); }
    catch(AccountLifecycleValidation|AccountLifecycleForbidden|AccountLifecycleConflict $e) { $error=$e; }
    ++$checks;
    if(!empty($case['success'])?($error!==null||empty($result['logout'])||count($pdo->writes)!==3||$pdo->transaction):($error===null||$pdo->writes||$pdo->transaction))
        throw new RuntimeException('Lifecycle behavior failed: '.$case['name']);
}
echo 'PASS: '.count($lifecycleCases)." original lifecycle password/confirmation/self-target/last-Admin/transaction/schema-gate cases; PDO doubles only. Total $checks checks.\n";
