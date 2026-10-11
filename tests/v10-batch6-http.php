<?php
/** Production routes in a private application copy; no installed config or transport credentials. */
if(PHP_SAPI!=='cli'||!isset($pdo,$root,$datadir,$port,$password))exit(1);
$b6HttpRoot=$root.'/batch6-http';mkdir($b6HttpRoot);mkdir($b6HttpRoot.'/sessions');
function b6_copy_dir(string $from,string $to):void {
    if(!is_dir($to))mkdir($to);
    foreach(new DirectoryIterator($from) as $e) {if($e->isDot()||$e->isLink())continue;if($e->isDir())b6_copy_dir($e->getPathname(),$to.'/'.$e->getFilename());else copy($e->getPathname(),$to.'/'.$e->getFilename());}
}
foreach(glob(__DIR__.'/../*.php') as $file)if(basename($file)!=='config.local.php')copy($file,$b6HttpRoot.'/'.basename($file));
b6_copy_dir(__DIR__.'/../includes',$b6HttpRoot.'/includes');b6_copy_dir(__DIR__.'/../assets',$b6HttpRoot.'/assets');
$sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$b6HttpPort=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1);fclose($sock);$b6HttpBase='http://127.0.0.1:'.$b6HttpPort;
$values=['DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>'b6_php','DB_USER'=>'root','DB_PASS'=>$password,'APP_ENV'=>'development','APP_BASE_URL'=>$b6HttpBase,'ALLOWED_EMAIL_DOMAINS'=>['example.invalid'],'GMAIL_CLIENT_ID'=>'','GMAIL_CLIENT_SECRET'=>'','GMAIL_REFRESH_TOKEN'=>'','OPENROUTER_API_KEY'=>''];
$config="<?php\n";foreach($values as $k=>$v)$config.='define('.var_export($k,true).','.var_export($v,true).");\n";file_put_contents($b6HttpRoot.'/config.local.php',$config);
function b6_http(string $route,array &$jar,array $data=[],array $options=[]):array {
    $method=$options['method']??'GET';$headers=['Accept: application/json','Content-Type: application/x-www-form-urlencoded'];
    if($jar)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($jar),$jar));
    if(!($options['noOrigin']??false))$headers[]='Origin: '.($options['origin']??$GLOBALS['b6HttpBase']);
    $body=http_build_query($data);if(isset($options['multipart'])){$body=$options['multipart'];$headers[1]='Content-Type: multipart/form-data; boundary='.$options['boundary'];}
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='GET'?'':$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
    $body=file_get_contents($GLOBALS['b6HttpBase'].'/'.$route,false,$ctx);$reply=$http_response_header??[];preg_match('~HTTP/\S+ (\d+)~',$reply[0]??'',$m);$location=null;
    foreach($reply as $header){if(preg_match('/^Set-Cookie: ([^=;]+)=([^;]*)/i',$header,$c)){if($c[2]!=='')$jar[$c[1]]=$c[2];else unset($jar[$c[1]]);}if(str_starts_with($header,'Location: '))$location=substr($header,10);}
    return ['status'=>(int)($m[1]??0),'body'=>$body,'data'=>json_decode($body,true),'location'=>$location];
}
function b6_http_login(int $id):array {$jar=[];$r=b6_http('login_process.php',$jar,['email'=>b6_actor($id)['email'],'password'=>'Batch6 fixture passphrase'],['method'=>'POST']);b6_expect(302,$r['status'],'Actual fresh login');return $jar;}
function b6_fields(string $body):array {
    $dom=new DOMDocument();$prev=libxml_use_internal_errors(true);$dom->loadHTML($body);libxml_clear_errors();libxml_use_internal_errors($prev);$result=[];
    foreach($dom->getElementsByTagName('input') as $input)if($input->getAttribute('type')==='hidden')$result[$input->getAttribute('name')]=$input->getAttribute('value');return $result;
}
function b6_http_upload(array &$jar,array $files):array {
    $boundary='b6-'.bin2hex(random_bytes(8));$body='';$fields=['documentType'=>'Study Protocol','studentDbId'=>'6','stage'=>'Stage 1','fileCount'=>(string)count($files),'requestId'=>bin2hex(random_bytes(16)),'prism_generation'=>$jar['prism_generation']??''];
    foreach($fields as $name=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    foreach($files as [$name,$path])$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"document[]\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n".file_get_contents($path)."\r\n";
    return b6_http('documents_api.php?action=upload',$jar,[],['method'=>'POST','multipart'=>$body.'--'.$boundary."--\r\n",'boundary'=>$boundary]);
}
foreach([8=>'student',9=>'adviser',10=>'admin',11=>'student',12=>'student'] as $id=>$role)$pdo->prepare('INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id,must_change_password) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,'HTTP-'.$id,password_hash('Batch6 fixture passphrase',PASSWORD_DEFAULT),$role,'HTTP '.$id,'http-'.$id.'@example.invalid','HTTP-'.$id,$id===12?1:0]);
$pdo->exec("INSERT INTO students(id,student_id,full_name,email,user_id,profile_completed_at,stage) VALUES (6,'B6-6','Batch6 6','b6-6@example.invalid',6,NOW(),'Stage 1'),(8,'HTTP-8','HTTP 8','http-8@example.invalid',8,NOW(),'Stage 1'),(11,NULL,NULL,'http-11@example.invalid',11,NULL,'Stage 1'),(12,'HTTP-12','HTTP 12','http-12@example.invalid',12,NOW(),'Stage 1')");
$pdo->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id,status,profile_completed_at) VALUES (9,'HTTP-9','HTTP 9','http-9@example.invalid',9,'Active',NOW())");
$b6HttpProcess=proc_open([PHP_BINARY,'-d','extension=zip','-d','session.save_path='.$b6HttpRoot.'/sessions','-d','upload_tmp_dir='.$b6HttpRoot,'-d','upload_max_filesize=25M','-d','post_max_size=30M','-S','127.0.0.1:'.$b6HttpPort,'-t',$b6HttpRoot],[0=>['pipe','r'],1=>['file',$b6HttpRoot.'/server.log','a'],2=>['file',$b6HttpRoot.'/server.log','a']],$pipes,$b6HttpRoot,null,['bypass_shell'=>true,'create_new_console'=>false]);
if(!is_resource($b6HttpProcess))throw new RuntimeException('Cannot start private HTTP server.');fclose($pipes[0]);
try {
    $deadline=microtime(true)+8;do{$probe=@fsockopen('127.0.0.1',$b6HttpPort,$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(10000);}while(microtime(true)<$deadline);
    $anon=[];foreach(['privacy.php','terms.php'] as $page){$r=b6_http($page.'?content=ATTACK',$anon);b6_expect(200,$r['status'],'Anonymous public policy');b6_expect(false,str_contains($r['body'],'ATTACK'),'Public query ignored');b6_expect(false,str_contains($r['body'],'<script>alert(1)</script>'),'Public DB markup escaped');}b6_expect([],$anon,'Public routes create no cookies');
    foreach([8,9,10] as $id){
        $jar=b6_http_login($id);b6_expect('legal_consent.php',b6_http('index.php',$jar)['location'],'All-role legal route');
        b6_expect(403,b6_http('legal_consent.php',$jar,['csrf'=>'','prism_generation'=>$jar['prism_generation'],'privacy'=>'1','terms'=>'1','snapshot'=>legal_snapshot(legal_current($pdo))],['method'=>'POST'])['status'],'Direct first POST cannot substitute an empty CSRF token');
        $r=b6_http('documents_api.php?action=list',$jar);b6_expect(403,$r['status'],'All-role API gate');b6_expect('legal_acceptance_required',$r['data']['code']??null,'Structured API gate');
        $r=b6_http('legal_consent.php',$jar);b6_expect(200,$r['status'],'Consent accessible');$fields=b6_fields($r['body']);
        b6_expect(422,b6_http('legal_consent.php',$jar,$fields,['method'=>'POST'])['status'],'No implicit checkbox acceptance');
        foreach([['noOrigin'=>true],['origin'=>'https://evil.invalid']] as $o)b6_expect(403,b6_http('legal_consent.php',$jar,$fields+['privacy'=>'1','terms'=>'1'],$o+['method'=>'POST'])['status'],'Same-origin guard');
        foreach(['csrf'=>403,'prism_generation'=>409] as $field=>$status)b6_expect($status,b6_http('legal_consent.php',$jar,array_replace($fields,[$field=>'wrong','privacy'=>'1','terms'=>'1']),['method'=>'POST'])['status'],'CSRF/generation guard');
        b6_expect(422,b6_http('legal_consent.php',$jar,$fields+['privacy'=>'1','terms'=>'1','user_id'=>3],['method'=>'POST'])['status'],'No proxy acceptance');
        b6_expect(302,b6_http('legal_consent.php',$jar,$fields+['privacy'=>'1','terms'=>'1'],['method'=>'POST'])['status'],'Explicit acceptance');b6_expect([],legal_outstanding($pdo,$id),'DB authoritative acceptance');b6_expect(302,b6_http('legal_consent.php',$jar)['status'],'No gate loop');
    }
    $pending=b6_http_login(11);b6_expect('legal_consent.php',b6_http('index.php',$pending)['location'],'Legal before profile');$r=b6_http('legal_consent.php',$pending);b6_http('legal_consent.php',$pending,b6_fields($r['body'])+['privacy'=>'1','terms'=>'1'],['method'=>'POST']);b6_expect('complete_profile.php',b6_http('index.php',$pending)['location'],'Profile after legal');b6_expect(200,b6_http('complete_profile.php',$pending)['status'],'Profile accessible after legal');
    $forced=b6_http_login(12);foreach(['index.php','legal_consent.php'] as $page)b6_expect('change_password_required.php',b6_http($page,$forced)['location'],'Password before legal');b6_expect(200,b6_http('profile_api.php?action=me',$forced)['status'],'Required-password API exception');
    foreach([2,6] as $id)b6_accept_all($id);$admin=b6_http_login(2);$student=b6_http_login(6);$adviser=b6_http_login(9);$pdf=__DIR__.'/fixtures/batch6-valid.pdf';$docx=__DIR__.'/fixtures/document-summary/research-protocol.docx';
    $r=b6_http_upload($student,[['a.pdf',$pdf],['b.docx',$docx],['c.xlsx',$pdf],['d.pdf',$pdf]]);b6_expect(3,$r['data']['uploaded']??null,'Native mixed partial success');b6_expect(1,$r['data']['failed']??null,'Only unsupported file failed');
    b6_expect(1,b6_http_upload($admin,[['ADMIN.DOCX',$docx]])['data']['uploaded']??null,'Admin DOCX');b6_expect(415,b6_http_upload($admin,[['admin.txt',$pdf]])['status'],'No Admin format bypass');b6_expect(415,b6_http_upload($student,[['student.jpg',$pdf]])['status'],'No Student format bypass');b6_expect(422,b6_http_upload($adviser,[['adviser.pdf',$pdf]])['status'],'No new Adviser upload rights');
    b6_expect('old.doc',$pdo->query("SELECT original_name FROM documents WHERE id='legacy'")->fetchColumn(),'Historical format preserved');
    $stale=b6_http_login(8);$pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=8');
    foreach(['documents','ierb','notifications','profile','calendar_deadlines','account_invitation','stage_labels'] as $api){$r=b6_http($api.'_api.php?action='.($api==='profile'||$api==='account_invitation'?'me':'list'),$stale);b6_expect(403,$r['status'],'Next stale API request');b6_expect('legal_acceptance_required',$r['data']['code']??null,'Structured stale API gate');}
    foreach(['student.php','role_portal.php','complete_profile.php'] as $page)b6_expect('legal_consent.php',b6_http($page,$stale)['location'],'Global page gate');b6_expect(302,b6_http('logout.php',$stale)['status'],'Logout without acceptance');
    $r=b6_http('admin_legal_policies.php',$admin);b6_expect(200,$r['status'],'Real Admin Legal Policies page');$fields=b6_fields($r['body']);$fields['action']='create';$fields['policy']='privacy';unset($fields['versionId']);
    b6_expect(403,b6_http('admin_legal_policies.php',$admin,array_replace($fields,['csrf'=>'bad']),['method'=>'POST'])['status'],'Management CSRF');$r=b6_http('admin_legal_policies.php',$admin,$fields,['method'=>'POST']);b6_expect(302,$r['status'],'Real Admin create draft');$draft=(int)substr($r['location'],strrpos($r['location'],'=')+1);
    b6_expect(200,b6_http('admin_legal_policies.php?version='.$draft,$admin)['status'],'Draft editor preview');b6_manage(2,['action'=>'save','policy'=>'privacy','versionId'=>$draft,'title'=>'Privacy Policy','content'=>"# HTTP policy\nSafe text",'summary'=>'HTTP publication']);b6_manage(2,['action'=>'submit','policy'=>'privacy','versionId'=>$draft]);
    $reviewer=b6_http_login(3);$r=b6_http('admin_legal_policies.php?version='.$draft,$reviewer);$fields=array_replace(b6_fields($r['body']),['action'=>'approve','policy'=>'privacy','versionId'=>$draft,'password'=>'Batch6 fixture passphrase','reviewAcknowledged'=>'1']);unset($fields['reason']);b6_expect(302,b6_http('admin_legal_policies.php',$reviewer,$fields,['method'=>'POST'])['status'],'Real independent publication POST');
    b6_expect('legal_consent.php',b6_http('index.php',$student)['location'],'Publication invalidates open session');b6_expect(false,str_contains(b6_http('legal_consent.php',$student)['body'],'name="terms"'),'Unchanged Terms still accepted');foreach([2,3,4,6] as $id)b6_accept_all($id);
    b6_expect(false,(bool)preg_match('/PHP (?:Fatal error|Warning|Parse error)/',file_get_contents($b6HttpRoot.'/server.log')),'No HTTP PHP warnings');
    // Remove the test-created active Admin from the later sole-Admin fixture.
    $pdo->exec("UPDATE users SET status='Inactive' WHERE id=10");
    echo "PASS: real all-role HTTP, legal/CSRF/generation/onboarding/API gates, publication and mixed multipart uploads.\n";
} finally {proc_terminate($b6HttpProcess);proc_close($b6HttpProcess);}
