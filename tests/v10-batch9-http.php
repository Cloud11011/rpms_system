<?php
/** Source-faithful HTTP endpoints with only the private database connection replaced. */
if (!isset($pdo,$root,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$httpRoot=$root.'/http';mkdir($httpRoot);mkdir($httpRoot.'/includes');mkdir($httpRoot.'/sessions');
foreach (glob(__DIR__.'/../includes/*.php') as $file) copy($file,$httpRoot.'/includes/'.basename($file));
foreach (glob(__DIR__.'/../includes/*.json') as $file) copy($file,$httpRoot.'/includes/'.basename($file));
foreach (['security.php','auth_rate_limit.php','register.php','register_process.php','staff_registration_manage.php','index.php','login.php','login_process.php','legal_consent.php','logout.php'] as $file) copy(__DIR__.'/../'.$file,$httpRoot.'/'.$file);
file_put_contents($httpRoot.'/config.local.php',"<?php\ndefine('ALLOWED_EMAIL_DOMAINS',['example.invalid']);\ndefine('APP_BASE_URL','http://127.0.0.1');\ndefine('APP_ENV','development');\ndefine('ADMIN_REGISTRATION_CODE','obsolete-static-fixture-code');\n");
$config=file_get_contents(__DIR__.'/../config.php');
$start=strpos($config,'function db(): PDO');$end=strpos($config,'// Bump this whenever',$start);
$connection="function db(): PDO { static \$pdo=null; return \$pdo??=new PDO(".var_export('mysql:host=127.0.0.1;port='.$port.';dbname=b7_fixture;charset=utf8mb4',true).",'root',".var_export($password,true).",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_INIT_COMMAND=>\"SET time_zone='+08:00'\"]); }\n\n";
file_put_contents($httpRoot.'/config.php',substr_replace($config,$connection,$start,$end-$start));
file_put_contents($httpRoot.'/router.php','<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);if(str_starts_with($path,"/assets/")){$base=realpath('.var_export(__DIR__.'/../assets',true).');$file=realpath($base.substr($path,7));if($file&&str_starts_with($file,$base.DIRECTORY_SEPARATOR)&&is_file($file)){$ext=pathinfo($file,PATHINFO_EXTENSION);header("Content-Type: ".(["css"=>"text/css","js"=>"application/javascript","png"=>"image/png","svg"=>"image/svg+xml"][$ext]??"application/octet-stream"));readfile($file);exit;}}return false;');
file_put_contents($httpRoot.'/fixture-state.php','<?php require __DIR__."/config.php";$state=$_POST["state"]??"invalidated";if($state==="none"){db()->exec("DELETE FROM staff_registration_code");exit;}if(!in_array($state,["pending","acknowledged","expired","consumed","invalidated"],true))exit;db()->prepare("INSERT INTO staff_registration_code(slot,generation_id,verifier_hash,state,creator_user_id,created_at,acknowledged_at,expires_at) VALUES (1,?,?,?,?,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE generation_id=VALUES(generation_id),verifier_hash=VALUES(verifier_hash),state=VALUES(state),creator_user_id=90,created_at=NOW(),acknowledged_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR),consumed_at=NULL,consumed_by_user_id=NULL")->execute([bin2hex(random_bytes(16)),"synthetic-ui-verifier",$state,90]);');
// Test-only session installer lives solely in the disposable fixture, with no production counterpart.
file_put_contents($httpRoot.'/fixture-auth.php', '<?php require __DIR__."/config.php"; $q=db()->prepare("SELECT * FROM users WHERE id=?");$q->execute([$_POST["uid"]??0]);$u=$q->fetch();$_SESSION=[];session_regenerate_id(true);$_SESSION["user_id"]=$u["id"];$_SESSION["credential_fingerprint"]=hash("sha256",$u["password_hash"]);$_SESSION["login_generation"]=bin2hex(random_bytes(16));$_SESSION["last_activity_at"]=time();prism_publish_session_generation();');
$socket=stream_socket_server('tcp://127.0.0.1:0');$httpPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$origin='http://127.0.0.1:'.$httpPort;
$httpServer=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-d','display_errors=0','-S','127.0.0.1:'.$httpPort,'-t',$httpRoot,$httpRoot.'/router.php'],
    [0=>['pipe','r'],1=>['file',$root.'/http-server.log','a'],2=>['file',$root.'/http-server.log','a']],$httpPipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
fclose($httpPipes[0]);
function b9_http(string $file, ?array $data=null, string $jar='admin', ?string $source=null): array {
    $url=$GLOBALS['origin'].'/'.$file;$cookie=$GLOBALS['httpRoot'].'/'.$jar.'.cookies';
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>10]);
    if ($data!==null) {curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data));curl_setopt($curl,CURLOPT_HTTPHEADER,['Origin: '.($source??$GLOBALS['origin'])]);}
    $raw=curl_exec($curl);if (!is_string($raw)) throw new RuntimeException('Private HTTP request failed.');
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
}
function b9_form(string $html): array {
    $dom=new DOMDocument();@$dom->loadHTML($html);$result=[];
    foreach ($dom->getElementsByTagName('input') as $input) if ($input->getAttribute('type')==='hidden') $result[$input->getAttribute('name')]=$input->getAttribute('value');
    return $result;
}
try {
    $deadline=microtime(true)+10;do {$sock=@fsockopen('127.0.0.1',$httpPort);if ($sock) {fclose($sock);break;}usleep(50000);} while (microtime(true)<$deadline);
    $pdo->exec("INSERT INTO users(id,username,ref_id,full_name,email,role,status,password_hash,must_change_password) SELECT 95,'B9-95','B9-95','Unaccepted Admin','b9-95@example.invalid','admin','Active',password_hash,0 FROM users WHERE id=90");
    foreach ([92=>'student.php',93=>'ierbprog.php',94=>'login.php',95=>'legal_consent.php'] as $uid=>$location) {
        if (in_array($uid,[92,93],true)) {
            $u=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=?',[$uid])[0];legal_accept($pdo,$u,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
        }
        b9_http('fixture-auth.php',['uid'=>$uid]);$r=b9_http('staff_registration_manage.php');
        b9_expect(true,str_contains($r['headers'],'Location: '.$location),'HTTP management identity gate');
        $r=b9_http('staff_registration_manage.php',['action'=>'generate']);b9_expect(true,str_contains($r['headers'],'Location: '.$location),'crafted management POST gate');
    }
    b9_http('fixture-auth.php',['uid'=>90]);
    // Reset the prior service generation to a known terminal state for a real endpoint generation.
    $pdo->exec("UPDATE staff_registration_code SET state='invalidated',verifier_hash=NULL WHERE slot=1");
    $page=b9_http('staff_registration_manage.php');$fields=b9_form($page['body']);
    $cross=b9_http('staff_registration_manage.php',$fields+['action'=>'generate'],'admin','https://foreign.invalid');b9_expect(403,$cross['status'],'HTTP cross origin generation');
    $stale=b9_http('staff_registration_manage.php',array_replace($fields,['action'=>'generate','prism_generation'=>str_repeat('0',32)]));b9_expect(409,$stale['status'],'HTTP stale generation');
    $r=b9_http('staff_registration_manage.php',$fields+['action'=>'generate']);b9_expect(200,$r['status'],'HTTP generation response');
    preg_match('/id="staffPlaintext"[^>]*>([^<]+)</',$r['body'],$m);$httpCode=$m[1]??'';b9_expect(true,staff_code_input($httpCode)!==null,'HTTP immediate code display');
    $ack=b9_form($r['body']);b9_expect(true,isset($ack['proof']),'immediate proof');
    b9_expect(true,str_contains(strtolower($r['headers']),'cache-control: no-store'),'one time no store');
    $again=b9_http('staff_registration_manage.php',$fields+['action'=>'generate']);b9_expect(409,$again['status'],'POST refresh replay rejected');b9_expect(false,str_contains($again['body'],$httpCode),'POST replay no code');
    $fresh=b9_http('staff_registration_manage.php');b9_expect(false,str_contains($fresh['body'],$httpCode),'GET no code');b9_expect(false,isset(b9_form($fresh['body'])['proof']),'GET cannot reconstruct proof');
    foreach (glob($httpRoot.'/sessions/*') as $session) b9_expect(false,str_contains(file_get_contents($session),$httpCode),'HTTP session no Staff plaintext');
    $before=b9_current();b9_http('register_process.php',b9_fields($httpCode,'HTTP-PENDING'),'public');b9_expect($before,b9_current(),'HTTP pending rejected');
    $noProof=b9_http('staff_registration_manage.php',array_replace($ack,['action'=>'acknowledge','confirmed'=>'1','proof'=>'forged']));b9_expect('pending',b9_current()['state'],'HTTP forged proof pending');
    // Failed action rotates form nonce. Original signed proof remains bound to its immediate generation.
    $ack['mutation_token']=b9_form(b9_http('staff_registration_manage.php')['body'])['mutation_token'];
    $activated=b9_http('staff_registration_manage.php',array_replace($ack,['action'=>'acknowledge','confirmed'=>'1']));
    b9_expect(303,$activated['status'],'acknowledge PRG');b9_expect('acknowledged',b9_current()['state'],'HTTP acknowledged');
    $r=b9_http('register.php',null,'public');b9_expect(200,$r['status'],'public direct registration reachable');b9_expect(true,str_contains($r['body'],'name="registration_code"'),'public code field');
    $before=b9_current();b9_http('register_process.php',b9_fields('obsolete-static-fixture-code','HTTP-STATIC'),'public');b9_expect($before,b9_current(),'configured static code powerless');
    $legacy=b9_legacy_config_code();
    if ($legacy!==null && $legacy!=='') {
        b9_expect(false,hash_equals($httpCode,$legacy),'old local credential is distinct from generated fixture code');
        b9_http('register_process.php',b9_fields($legacy,'HTTP-OLD-LOCAL'),'public');
        b9_expect($before,b9_current(),'actual obsolete local credential rejected');
        b9_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='HTTP-OLD-LOCAL'")->fetchColumn(),'actual obsolete local credential creates no user');
    }
    unset($legacy);
    b9_http('register_process.php',b9_fields($httpCode,'HTTP-SIGNED-IN'),'admin');b9_expect($before,b9_current(),'existing browser account prevents creation');
    $result=b9_http('register_process.php',b9_fields($httpCode,'HTTP-ADMIN'),'public');b9_expect(303,$result['status'],'registration PRG');
    b9_expect(true,str_contains($result['headers'],'Location: index.php'),'registration canonical route');
    b9_expect('consumed',b9_current()['state'],'HTTP consumed');
    $router=b9_http('index.php',null,'public');b9_expect(true,str_contains($router['headers'],'Location: legal_consent.php'),'new session legal gate');
    $new=lifecycle_rows($pdo,"SELECT * FROM users WHERE username='HTTP-ADMIN'")[0];
    b9_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id='.(int)$new['id'])->fetchColumn(),'no fabricated legal acceptance');
    b9_expect(true,str_contains($result['headers'],'prism_generation='),'new authentication generation cookie');
    foreach (glob($httpRoot.'/sessions/*') as $session) b9_expect(false,str_contains(file_get_contents($session),$httpCode),'post registration session no code');
    foreach (['staff_registration_code','activity_logs','notifications','notification_campaigns'] as $table) b9_expect(false,str_contains(json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll()),$httpCode),'HTTP secret absent from rows');
    b9_expect(false,str_contains(file_get_contents($root.'/http-server.log'),$httpCode),'HTTP logs no code');
    b9_expect(false,is_file($httpRoot.'/storage/mail.log'),'no mail transport');
    b9_expect(false,str_contains(b9_http('login.php',null,'anonymous')['body'],'href="register.php"'),'Login no Register link');
    // Browser checks use the real source pages while this isolated server is alive.
    if (in_array('--browser9',$argv,true)) {
        $browser=proc_open(['node',__DIR__.'/v10-batch9-ui.cjs',$origin,$httpRoot],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$browserPipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        fclose($browserPipes[0]);$out=stream_get_contents($browserPipes[1]);$err=stream_get_contents($browserPipes[2]);fclose($browserPipes[1]);fclose($browserPipes[2]);
        b9_expect(0,proc_close($browser),'browser process '.$err);echo $out;
    }
} finally { if (is_resource($httpServer)) {proc_terminate($httpServer);proc_close($httpServer);} }
