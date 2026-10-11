<?php
/** Real HTTP/session/origin/production API verification boundary; private server and application copy. */
if(!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$http105Start=$GLOBALS['nativeChecks'];
$http105Root=$root.'/http105';mkdir($http105Root);mkdir($http105Root.'/includes');mkdir($http105Root.'/sessions');
foreach(['config.php','security.php','auth_rate_limit.php','workflow.php','account_lifecycle_api.php'] as $file) copy(__DIR__.'/../'.$file,$http105Root.'/'.$file);
foreach(array_merge(glob(__DIR__.'/../includes/*.php'),glob(__DIR__.'/../includes/*.json')) as $file) copy($file,$http105Root.'/includes/'.basename($file));
$http105Dsn='mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4';
$config105=file_get_contents($http105Root.'/config.php');$from105=strpos($config105,'function db(): PDO');$to105=strpos($config105,'// Bump this whenever',$from105);
if($from105===false || $to105===false) throw new RuntimeException('Reviewed fixture bootstrap boundary missing');
$db105='function db(): PDO { static $pdo=null; return $pdo??=new PDO('.var_export($http105Dsn,true).",'root',".var_export($password,true).',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }'."\n\n";
file_put_contents($http105Root.'/config.php',substr_replace($config105,$db105,$from105,$to105-$from105));
$hash105=password_hash('HTTP verification fixture password',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users(id,username,full_name,email,role,status,password_hash) VALUES (902,'ADMIN902','HTTP Admin','http902@example.invalid','admin','Active',?)")->execute([$hash105]);
$pdo->exec('INSERT INTO user_policy_acceptances(user_id,policy_version_id) SELECT 902,current_version_id FROM legal_policies');
file_put_contents($http105Root.'/fixture-auth.php','<?php require __DIR__."/config.php";$u=db()->query("SELECT * FROM users WHERE id=902")->fetch();session_regenerate_id(true);$_SESSION["user_id"]=902;$_SESSION["credential_fingerprint"]=hash("sha256",$u["password_hash"]);$_SESSION["login_generation"]=bin2hex(random_bytes(16));$_SESSION["last_activity_at"]=time();prism_publish_session_generation();echo json_encode(["generation"=>prism_session_generation()]);');
$sock105=stream_socket_server('tcp://127.0.0.1:0');$port105=(int)substr(strrchr(stream_socket_get_name($sock105,false),':'),1);fclose($sock105);$origin105='http://127.0.0.1:'.$port105;
function http105_config(array $evidence): void {
    global $http105Root,$origin105;
    $values=['APP_BASE_URL'=>$origin105,'APP_ENV'=>'development','ALLOWED_EMAIL_DOMAINS'=>['example.invalid'],'GMAIL_CLIENT_ID'=>'','GMAIL_CLIENT_SECRET'=>'','GMAIL_REFRESH_TOKEN'=>'','OPENROUTER_API_KEY'=>'',
        'PRISM_HARD_DELETE_SCHEMA_VERIFIED'=>true,'PRISM_HARD_DELETE_VERIFICATION'=>$evidence,'PRISM_HARD_DELETE_VERIFICATION_KEY'=>PRISM_HARD_DELETE_VERIFICATION_KEY];
    $text="<?php\n";foreach($values as $key=>$value) $text.='define('.var_export($key,true).','.var_export($value,true).");\n";
    file_put_contents($http105Root.'/config.local.php',$text);
}
function http105_request(string $path,?array $body=null,bool $sameOrigin=true): array {
    global $origin105,$http105Root,$generation105;
    $curl=curl_init($origin105.'/'.$path);$headers=['Accept: application/json','Content-Type: application/json','X-Prism-Generation: '.($generation105??'')];
    if($sameOrigin)$headers[]='Origin: '.$origin105;
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$http105Root.'/cookies',CURLOPT_COOKIEJAR=>$http105Root.'/cookies',CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null) {curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));}
    $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if(!is_string($raw))throw new RuntimeException('Private HTTP request failed');
    return ['status'=>$status,'data'=>json_decode($raw,true,512,JSON_THROW_ON_ERROR)];
}
http105_config(PRISM_HARD_DELETE_VERIFICATION);
$server105=proc_open([PHP_BINARY,'-d','session.save_path='.$http105Root.'/sessions','-d','display_errors=0','-S','127.0.0.1:'.$port105,'-t',$http105Root],[0=>['pipe','r'],1=>['file',$root.'/http105.log','a'],2=>['file',$root.'/http105.log','a']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);
try {
    $deadline105=microtime(true)+10;do{$ready105=@fsockopen('127.0.0.1',$port105);if($ready105){fclose($ready105);break;}usleep(50000);}while(microtime(true)<$deadline105);
    reset_migration_expect(401,http105_request('account_lifecycle_api.php?action=availability')['status'],'HTTP availability requires login');
    $generation105=http105_request('fixture-auth.php',[])['data']['generation'];
    $valid105=http105_request('account_lifecycle_api.php?action=availability');
    reset_migration_expect(true,$valid105['data']['available'],'HTTP authenticated persistent availability');
    reset_migration_expect(['ok','available','verificationMode','message'],array_keys($valid105['data']),'HTTP availability exposes no evidence or binding');
    $input105=['action'=>'permanent_delete','accountType'=>'student','targetId'=>903,'currentPassword'=>'HTTP verification fixture password','confirmation'=>'S903','confirmed'=>true,'available'=>true,'verification'=>true];
    reset_migration_expect(403,http105_request('account_lifecycle_api.php',$input105,false)['status'],'HTTP direct purge cannot omit origin');
    $bad105=PRISM_HARD_DELETE_VERIFICATION;$bad105['authenticator']=str_repeat('0',64);http105_config($bad105);
    reset_migration_expect(false,http105_request('account_lifecycle_api.php?action=availability')['data']['available'],'HTTP replaced evidence invalidates availability');
    $refused105=http105_request('account_lifecycle_api.php',$input105);
    reset_migration_expect(409,$refused105['status'],'HTTP crafted flags cannot bypass signature');
    reset_migration_expect('Permanent deletion is unavailable because deployment verification is required. Contact the system operator.',$refused105['data']['message'],'HTTP technical details remain protected');
    http105_config(PRISM_HARD_DELETE_VERIFICATION);
    reset_migration_expect(true,http105_request('account_lifecycle_api.php?action=availability')['data']['available'],'HTTP fresh availability before schema change');
    $pdo->exec('ALTER TABLE students MODIFY research_title VARCHAR(254) NULL');
    reset_migration_expect(409,http105_request('account_lifecycle_api.php',$input105)['status'],'HTTP stale availability cannot authorize deletion after drift');
    reset_migration_expect(false,http105_request('account_lifecycle_api.php?action=availability')['data']['available'],'HTTP live drift disables availability');
    $pdo->exec('ALTER TABLE students MODIFY research_title VARCHAR(255) NULL');
    reset_migration_expect(true,http105_request('account_lifecycle_api.php?action=availability')['data']['available'],'HTTP restoration of unchanged invariants restores persistent proof');
    http105_config([]);
    reset_migration_expect(false,http105_request('account_lifecycle_api.php?action=availability')['data']['available'],'HTTP evidence removal fails closed');
    echo 'PASS: '.($GLOBALS['nativeChecks']-$http105Start)." real HTTP persistent verification, session/origin, tamper, stale availability and diagnostics assertions.\n";
} finally { proc_terminate($server105);proc_close($server105); }
