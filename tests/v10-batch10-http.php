<?php
/** Real endpoints in an isolated source copy; only db() is replaced. Never load installed config. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$httpRoot=$root.'/http';mkdir($httpRoot);mkdir($httpRoot.'/includes');mkdir($httpRoot.'/sessions');
foreach(glob(__DIR__.'/../*.php') as $file) if(!in_array(basename($file),['config.php','config.local.php'],true))copy($file,$httpRoot.'/'.basename($file));
foreach(array_merge(glob(__DIR__.'/../includes/*.php'),glob(__DIR__.'/../includes/*.json')) as $file)copy($file,$httpRoot.'/includes/'.basename($file));
file_put_contents($httpRoot.'/config.local.php',"<?php\ndefine('ALLOWED_EMAIL_DOMAINS',['example.invalid']);\ndefine('APP_BASE_URL','http://127.0.0.1');\ndefine('APP_ENV','development');\ndefine('MAIL_FROM_EMAIL','fixture@example.invalid');\ndefine('OPENROUTER_API_KEY','');\ndefine('GMAIL_CLIENT_ID','');\ndefine('GMAIL_CLIENT_SECRET','');\ndefine('GMAIL_REFRESH_TOKEN','');\ndefine('GMAIL_SENDER_EMAIL','');\n");
$config=file_get_contents(__DIR__.'/../config.php');$start=strpos($config,'function db(): PDO');$end=strpos($config,'// Bump this whenever',$start);
$connection="function db(): PDO { static \$pdo=null; return \$pdo??=new PDO(".var_export($dsn,true).",'root',".var_export($password,true).",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_INIT_COMMAND=>\"SET time_zone='+08:00'\"]); }\n\n";
file_put_contents($httpRoot.'/config.php',substr_replace($config,$connection,$start,$end-$start));
file_put_contents($httpRoot.'/fixture-auth.php','<?php require __DIR__."/config.php";$q=db()->prepare("SELECT * FROM users WHERE id=?");$q->execute([$_POST["uid"]??0]);$u=$q->fetch();$_SESSION=[];session_regenerate_id(true);$_SESSION["user_id"]=$u["id"];$_SESSION["account_type"]=$u["role"];$_SESSION["user_role"]=$u["role"];$_SESSION["user_email"]=$u["email"];$_SESSION["full_name"]=$u["full_name"];$_SESSION["credential_fingerprint"]=hash("sha256",$u["password_hash"]);$_SESSION["login_generation"]=bin2hex(random_bytes(16));$_SESSION["last_activity_at"]=time();prism_publish_session_generation();echo json_encode(["generation"=>prism_session_generation()]);');
file_put_contents($httpRoot.'/fixture-blank.php','<!doctype html><title>Isolated test fixture</title>');
file_put_contents($httpRoot.'/router.php','<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);if(str_starts_with($path,"/assets/")){$base=realpath('.var_export(__DIR__.'/../assets',true).');$file=realpath($base.substr($path,7));if($file&&str_starts_with($file,$base.DIRECTORY_SEPARATOR)&&is_file($file)){$ext=pathinfo($file,PATHINFO_EXTENSION);header("Content-Type: ".(["css"=>"text/css","js"=>"application/javascript","png"=>"image/png","webp"=>"image/webp","svg"=>"image/svg+xml"][$ext]??"application/octet-stream"));readfile($file);exit;}}return false;');
$socket=stream_socket_server('tcp://127.0.0.1:0');$httpPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$httpPort;
$httpServer=proc_open([PHP_BINARY,'-d','extension=zip','-d','session.save_path='.$httpRoot.'/sessions','-d','display_errors=0','-S','127.0.0.1:'.$httpPort,'-t',$httpRoot,$httpRoot.'/router.php'],[0=>['pipe','r'],1=>['file',$root.'/http-server.log','a'],2=>['file',$root.'/http-server.log','a']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);
$generations=[];
function b10_http(string $file,?array $data=null,string $jar='admin',bool $upload=false,?string $origin=null,?string $generation=null): array {
    $curl=curl_init($GLOBALS['origin'].'/'.$file);$cookie=$GLOBALS['httpRoot'].'/'.$jar.'.cookies';
    $headers=['Origin: '.($origin??$GLOBALS['origin']),'X-Prism-Generation: '.($generation??$GLOBALS['generations'][$jar]??'')];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($data!==null){curl_setopt($curl,CURLOPT_POST,true);if($upload)curl_setopt($curl,CURLOPT_POSTFIELDS,$data);elseif(str_contains($file,'documents_api.php')){$headers[]='Content-Type: application/json';curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data));}else curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data));}
    curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);$raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException('HTTP fixture request failed.');$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    $body=substr($raw,$size);return ['status'=>$code,'headers'=>substr($raw,0,$size),'body'=>$body,'json'=>json_decode($body,true)];
}
function b10_auth(int $uid,string $jar): void { $r=b10_http('fixture-auth.php',['uid'=>$uid],$jar);$GLOBALS['generations'][$jar]=$r['json']['generation']; }
function b10_upload(string $type,?string $revision=null,string $jar='student',?string $fixture=null): array {
    return b10_http('documents_api.php?action=upload',['document'=>new CURLFile($fixture??__DIR__.'/fixtures/batch6-valid.pdf'),'documentType'=>$type,'fileCount'=>'1','requestId'=>bin2hex(random_bytes(12))]+($revision?['revisionOf'=>$revision]:[]),$jar,true);
}
try {
    $deadline=microtime(true)+10;do{$sock=@fsockopen('127.0.0.1',$httpPort);if($sock){fclose($sock);break;}usleep(50000);}while(microtime(true)<$deadline);
    if(!in_array('--uionly1075',$argv,true)){
    b10_auth(1001,'admin');b10_auth(1002,'adviser');b10_auth(1003,'student');b10_auth(1005,'other-adviser');
    $r=b10_upload(prism_document_types()[0]);b10_expect(200,$r['status'],'real Student PDF upload '.$r['body']);$httpV1=$r['json']['document']['id'];
    $q=b10_doc($httpV1);b10_expect('Submitted',$q['review_status'],'upload awaits Adviser');b10_expect(null,$q['rpms_submitted_at'],'upload never auto-submits');
    foreach(['review','return_for_revision','submit_to_rpms'] as $action){$r=b10_http('documents_api.php?action='.$action,['id'=>$httpV1,'status'=>'Approved','reason'=>'Reason'],'student');b10_expect(403,$r['status'],'Student direct '.$action);}
    b10_expect(403,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'other-adviser')['status'],'cross-student Adviser');
    b10_expect(405,b10_http('documents_api.php?action=return_for_revision&id='.$httpV1)['status'],'Return GET rejected');
    b10_expect(403,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'adviser',false,'https://foreign.invalid')['status'],'cross origin');
    b10_expect(409,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'adviser',false,null,str_repeat('0',32))['status'],'stale generation');
    $r=b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'adviser');b10_expect(200,$r['status'],'real approval '.$r['body']);$original=b10_doc($httpV1);
    b10_expect(WF_SUBMITTED_RPMS,$r['json']['workflowState'],'real auto formal submission');
    b10_expect(409,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'adviser')['status'],'approval replay');
    b10_expect(422,b10_http('documents_api.php?action=return_for_revision',['id'=>$httpV1,'reason'=>'  '])['status'],'Return whitespace reason');
    b10_expect(403,b10_http('documents_api.php?action=return_for_revision',['id'=>$httpV1,'reason'=>'Reason'],'adviser')['status'],'Adviser Return denied');
    $r=b10_http('documents_api.php?action=return_for_revision',['id'=>$httpV1,'reason'=>'Revise <script>alert(1)</script> methodology.']);b10_expect(200,$r['status'],'real Return '.$r['body']);
    b10_expect(200,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved','remarks'=>'Institutional follow-up comment.'])['status'],'Admin can append comment to returned version');
    foreach(['review_status','reviewed_at','reviewed_by','rpms_submitted_at','rpms_submitted_by'] as $field)b10_expect($original[$field],b10_doc($httpV1)[$field],'submitted comment preserves '.$field);
    $r=b10_http('documents_api.php?action=list',null,'student');$current=array_values(array_filter($r['json']['documents'],fn($d)=>$d['id']===$httpV1))[0];b10_expect(true,$current['actions']['uploadRevision'],'Student revision action');b10_expect(false,$current['actions']['submitToRpms'],'no Student submit action');b10_expect(false,$current['actions']['returnForRevision'],'no Student Return action');
    // Two real upload processes share the frozen DB and storage; independent sessions avoid session serialization.
    b10_auth(1003,'student-race');$socket=stream_socket_server('tcp://127.0.0.1:0');$secondPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $secondOrigin='http://127.0.0.1:'.$secondPort;
    $second=proc_open([PHP_BINARY,'-d','extension=zip','-d','session.save_path='.$httpRoot.'/sessions','-d','display_errors=0','-S','127.0.0.1:'.$secondPort,'-t',$httpRoot,$httpRoot.'/router.php'],[0=>['pipe','r'],1=>['file',$root.'/http-server-2.log','a'],2=>['file',$root.'/http-server-2.log','a']],$sp,null,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($sp[0]);
    try {
        $deadline=microtime(true)+10;do{$sock=@fsockopen('127.0.0.1',$secondPort);if($sock){fclose($sock);break;}usleep(50000);}while(microtime(true)<$deadline);
        $upload=['operation'=>'upload','file'=>__DIR__.'/fixtures/document-summary/research-protocol.docx','type'=>prism_document_types()[0],'revision'=>$httpV1];
        $race=b10_race([$upload+['url'=>$origin.'/documents_api.php?action=upload','origin'=>$origin,'cookie'=>$httpRoot.'/student.cookies','generation'=>$generations['student']],
            $upload+['url'=>$secondOrigin.'/documents_api.php?action=upload','origin'=>$secondOrigin,'cookie'=>$httpRoot.'/student-race.cookies','generation'=>$generations['student-race']]]);
        b10_expect(1,count(array_filter($race,fn($r)=>$r['ok'])),'two native HTTP revisions one new version');
        $winner=array_values(array_filter($race,fn($r)=>$r['ok']))[0];$httpV2=$winner['result']['document']['id'];
        b10_expect(1,count(b10_rows('SELECT id FROM documents WHERE supersedes_id=?',[$httpV1])),'no duplicate revision edge');
        b10_expect(2,(int)b10_doc($httpV2)['version_no'],'serialized revision version number');
        b10_expect('docx',pathinfo(b10_doc($httpV2)['original_name'],PATHINFO_EXTENSION),'genuine DOCX returned revision');
    } finally { if(is_resource($second)){proc_terminate($second);proc_close($second);} }
    b10_expect($original['stage'],b10_doc($httpV2)['stage'],'revision keeps original stage after advancement');b10_expect($httpV1,b10_doc($httpV2)['supersedes_id'],'revision chain');b10_expect($original['rpms_submitted_at'],b10_doc($httpV1)['rpms_submitted_at'],'revision preserves old timestamp');
    b10_expect(409,b10_upload(prism_document_types()[0],$httpV1)['status'],'stale revision target rejected');
    b10_expect(409,b10_http('documents_api.php?action=review',['id'=>$httpV1,'status'=>'Approved'],'adviser')['status'],'old Adviser tab');
    b10_expect(200,b10_http('documents_api.php?action=review',['id'=>$httpV2,'status'=>'Approved'],'adviser')['status'],'real auto resubmission');
    $race=b10_race([['actor'=>$admin,'id'=>$httpV2,'operation'=>'return','reason'=>'Second Return racing revision.'],
        ['operation'=>'upload','file'=>__DIR__.'/fixtures/batch6-valid.pdf','type'=>prism_document_types()[0],'revision'=>$httpV2,
            'url'=>$origin.'/documents_api.php?action=upload','origin'=>$origin,'cookie'=>$httpRoot.'/student-race.cookies','generation'=>$generations['student-race']]]);
    b10_expect(true,$race[0]['ok'],'Return wins exactly once against racing revision');
    b10_expect(1,count(b10_rows("SELECT id FROM activity_logs WHERE entity_id=? AND action='document_returned_for_revision'",[$httpV2])),'Return/revision race one event');
    $edge=b10_rows('SELECT id FROM documents WHERE supersedes_id=?',[$httpV2]);
    b10_expect($race[1]['ok']?1:0,count($edge),'race keeps exactly committed revision');
    if($edge){$httpV3=$edge[0]['id'];b10_expect(WF_PENDING_REVIEW,document_workflow_state(b10_doc($httpV3)),'Return/revision current pending');}
    else {b10_expect(WF_RETURNED_REVISION,document_workflow_state(b10_doc($httpV2)),'Return/revision current returned');$r=b10_upload(prism_document_types()[0],$httpV2);b10_expect(200,$r['status'],'retry revision after Return');$httpV3=$r['json']['document']['id'];}
    b10_expect(200,b10_http('documents_api.php?action=review',['id'=>$httpV3,'status'=>'Approved'],'adviser')['status'],'third real HTTP auto resubmission');
    b10_expect(200,b10_http('documents_api.php?action=file&id='.$httpV1,null,'student')['status'],'old submitted file remains accessible');
    b10_expect(403,b10_http('documents_api.php?action=file&id='.$httpV1,null,'other-adviser')['status'],'download scope');
    foreach(['xlsx','doc','pptx','jpg','pdf'] as $ext){$fake=$root.'/spoof.'.$ext;file_put_contents($fake,'spoofed file');b10_expect(415,b10_upload(prism_document_types()[0],null,'student',$fake)['status'],'reject prohibited/spoofed '.$ext);}
    $bulk=['document[0]'=>new CURLFile(__DIR__.'/fixtures/batch6-valid.pdf'),'document[1]'=>new CURLFile(__DIR__.'/fixtures/document-summary/research-protocol.docx'),
        'documentType'=>prism_document_types()[1],'fileCount'=>'2','requestId'=>bin2hex(random_bytes(12))];
    $r=b10_http('documents_api.php?action=upload',$bulk,'student',true);b10_expect(200,$r['status'],'real mixed PDF/DOCX bulk upload');
    b10_expect(2,$r['json']['uploaded'],'bulk preserves two accepted files');$bulkCurrent=$r['json']['results'][1]['document']['id'];
    foreach($r['json']['results'] as $item)b10_expect(null,b10_doc($item['document']['id'])['rpms_submitted_at'],'bulk upload awaits review');
    b10_expect(422,b10_http('documents_api.php?action=review&id='.$bulkCurrent,['id'=>$httpV3,'status'=>'Approved'],'adviser')['status'],'conflicting query/body identity rejected');
    $race=b10_race([['actor'=>$adviser,'id'=>$bulkCurrent,'operation'=>'review','status'=>'Approved'],
        ['operation'=>'upload','file'=>__DIR__.'/fixtures/batch6-valid.pdf','type'=>prism_document_types()[1],'revision'=>'',
            'url'=>$origin.'/documents_api.php?action=upload','origin'=>$origin,'cookie'=>$httpRoot.'/student-race.cookies','generation'=>$generations['student-race']]]);
    b10_expect(true,$race[0]['ok']||$race[1]['ok'],'approval/new-version race makes consistent progress');
    $old=b10_doc($bulkCurrent);
    if($race[0]['ok']){b10_expect(WF_SUBMITTED_RPMS,document_workflow_state($old),'winning approval cannot be superseded by same-chain upload');b10_expect(1,(int)$old['is_current'],'approved race winner remains current');}
    else {b10_expect(true,$race[1]['ok'],'new version wins stale approval race');b10_expect(0,(int)$old['is_current'],'losing approval targets superseded version');b10_expect(null,$old['rpms_submitted_at'],'stale approval never formally submits');}
    $pdo->exec('DELETE FROM user_policy_acceptances WHERE user_id=1001');b10_expect(403,b10_http('documents_api.php?action=return_for_revision',['id'=>$httpV2,'reason'=>'Reason'])['status'],'HTTP legal gate');legal_accept($pdo,$admin,['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current($pdo))]);
    $pdo->exec("UPDATE users SET status='Inactive' WHERE id=1001");b10_expect(401,b10_http('documents_api.php?action=return_for_revision',['id'=>$httpV2,'reason'=>'Reason'])['status'],'HTTP inactive Admin');$pdo->exec("UPDATE users SET status='Active' WHERE id=1001");b10_auth(1001,'admin');
    }
    if(in_array('--browser10',$argv,true)){
        if (document_workflow_state(b10_doc($v3)) === WF_SUBMITTED_RPMS) document_workflow_change($pdo,$admin,$v3,'return','','A new revision is required for the presentation fixture.');
        $browser=proc_open(['node',__DIR__.'/v10-batch10-ui.cjs',$origin,$httpRoot],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$bp,null,null,['bypass_shell'=>true,'create_new_console'=>false]);fclose($bp[0]);$out=stream_get_contents($bp[1]);$err=stream_get_contents($bp[2]);fclose($bp[1]);fclose($bp[2]);b10_expect(0,proc_close($browser),'browser '.$err);echo $out;
    }
    if(in_array('--browser1075',$argv,true)){
        // Native workflow doubles intentionally have DB-only file rows. Acceptance file actions
        // require real bytes, materialized only inside this verified disposable HTTP source root.
        if(in_array('--acceptance1075',$argv,true)||in_array('--interaction1075',$argv,true)) {
            $fixtureDocs=$httpRoot.'/storage/documents';if(!is_dir($fixtureDocs))mkdir($fixtureDocs,0700,true);
            foreach(b10_rows('SELECT stored_name FROM documents') as $file) {
                if(basename($file['stored_name'])!==$file['stored_name'])throw new RuntimeException('Unsafe fixture file name');
                if(!is_file($fixtureDocs.'/'.$file['stored_name']))copy(__DIR__.'/fixtures/batch6-valid.pdf',$fixtureDocs.'/'.$file['stored_name']);
            }
            $vendorSource=realpath(__DIR__.'/../vendor');
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendorSource,FilesystemIterator::SKIP_DOTS)) as $file) {
                if($file->isLink())throw new RuntimeException('Unexpected dependency link');
                $target=$httpRoot.'/vendor/'.substr($file->getPathname(),strlen($vendorSource)+1);
                if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);copy($file->getPathname(),$target);
            }
        }
        $legalBaseline=legal_current($pdo);$legalMax=(int)$pdo->query('SELECT MAX(id) FROM legal_policy_versions')->fetchColumn();
        $browserWorkflow=b10_rows('SELECT id,student_id,review_status,version_no,is_current,supersedes_id,rpms_submitted_at FROM documents ORDER BY id');
        $browserProgress=b10_rows('SELECT id,stage,status,requirements FROM students ORDER BY id');
        file_put_contents($httpRoot.'/fixture-idle.php','<?php require __DIR__."/config.php";$_SESSION["last_activity_at"]=time()-1801;echo "fixture idle clock set";');
        $previewId=$bulkCurrent??$pdo->query('SELECT id FROM documents ORDER BY uploaded_at DESC,id DESC LIMIT 1')->fetchColumn();
        $pdo->prepare('UPDATE documents SET original_name=? WHERE id=?')->execute([str_repeat('Long-research-filename-',10).'protocol.pdf',$previewId]);
        if(in_array('--focus1075',$argv,true)||in_array('--acceptance1075',$argv,true)||in_array('--interaction1075',$argv,true))$pdo->exec("INSERT INTO calendar_deadlines(creator_user_id,title,description,deadline_date,target_scope,status) VALUES(1001,'Batch1075 official deadline','Private UI fixture',CURRENT_DATE(),'all','Active')");
        $mode=in_array('--interaction1075',$argv,true)?'interaction':(in_array('--acceptance1075',$argv,true)?'acceptance':(in_array('--contrast1075',$argv,true)?'contrast':(in_array('--focus1075',$argv,true)?'focus':'matrix')));
        $browser=proc_open(['node',__DIR__.'/v10-batch1075-ui.cjs',$origin,$httpRoot,in_array('--before1075',$argv,true)?'before':'after',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$bp,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        fclose($bp[0]);$out=stream_get_contents($bp[1]);$err=stream_get_contents($bp[2]);fclose($bp[1]);fclose($bp[2]);echo $out;b10_expect(0,proc_close($browser),'Batch1075 browser '.$err);
        b10_expect($browserWorkflow,b10_rows('SELECT id,student_id,review_status,version_no,is_current,supersedes_id,rpms_submitted_at FROM documents ORDER BY id'),'cancelled Actions preserve document workflow and versions');
        b10_expect($browserProgress,b10_rows('SELECT id,stage,status,requirements FROM students ORDER BY id'),'cancelled Actions preserve official student progress');
        b10_expect($legalBaseline,legal_current($pdo),'UI Cancel preserves published policy content and pointers');
        foreach(legal_rows($pdo,'SELECT * FROM legal_policy_versions WHERE id>?',[$legalMax]) as $draft){b10_expect('draft',$draft['state'],'Cancel retains saved draft');b10_expect(false,str_contains($draft['title'],'Unsaved browser edit'),'Cancel writes no edits');}
        // Remove only browser-created drafts in this verified disposable fixture before B6 governance tests.
        $pdo->prepare("DELETE FROM legal_policy_versions WHERE id>? AND state='draft'")->execute([$legalMax]);
    }
    lifecycle_compare_schema($pdo);echo (in_array('--uionly1075',$argv,true)?'PASS: isolated UI fixture, frozen schema and policy preservation. ':'PASS: Batch10 real HTTP upload, approval, Return, revision, resubmission, transport/session/legal/role guards and file history. ').($GLOBALS['nativeChecks']-$b10Start)." cumulative assertions.\n";
} finally { if(is_resource($httpServer)){proc_terminate($httpServer);proc_close($httpServer);} }
