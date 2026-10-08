<?php
/** Pure/SQLite security tests. No application config, live database, providers or runtime storage. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../security.php';
require __DIR__.'/../includes/office_container.php';
$checks=0;
function verify(bool $ok,string $label):void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label); }
foreach ([11=>false,12=>true,200=>true,201=>false] as $length=>$ok) verify(new_password_is_valid(str_repeat('a',$length))===$ok,'Shared passphrase length '.$length);
verify(!new_password_is_valid(str_repeat('é',6)) && new_password_is_valid(str_repeat('é',12)),'Password minimum counts Unicode characters, maximum retains byte limit');
require __DIR__.'/../auth_rate_limit.php';
$rateDir=sys_get_temp_dir().'/prism-login-spray-'.bin2hex(random_bytes(8));mkdir($rateDir,0700);define('STORAGE_DIR',$rateDir);
try {
    for($i=0;$i<60;$i++) verify(consume_auth_attempt('login_ip','192.0.2.10',60,900),'Shared lab/spray attempt consumes same source budget '.$i);
    verify(!consume_auth_attempt('login_ip','192.0.2.10',60,900),'Sixty-first source attempt blocked across account addresses');
    verify(consume_auth_attempt('login_ip','192.0.2.11',60,900),'Independent school source retains its own budget');
} finally {
    foreach(glob($rateDir.'/auth_rate_limits/*.json') as $counter)unlink($counter);
    foreach(['.lock','.htaccess'] as $file)unlink($rateDir.'/auth_rate_limits/'.$file);
    rmdir($rateDir.'/auth_rate_limits');rmdir($rateDir);
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY,token TEXT,used INTEGER,expires_at TEXT)');
$raw=bin2hex(random_bytes(32));$params=reset_token_parameters($raw);
verify(strlen($params[':hashed'])===71&&!str_contains($params[':hashed'],$raw),'New stored digest never contains raw token');
$q=$pdo->prepare('INSERT INTO password_resets VALUES (?,?,?,?)');
$legacy=bin2hex(random_bytes(32));
$q->execute([1,$params[':hashed'],0,'2099-01-01']);$q->execute([2,$legacy,0,'2099-01-01']);
$q->execute([3,reset_token_parameters('expired')[':hashed'],0,'2000-01-01']);$q->execute([4,reset_token_parameters('used')[':hashed'],1,'2099-01-01']);
$source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../reset_password.php'));
preg_match("/prepare\('([^']+)'\)/",$source,$sql);verify(isset($sql[1]),'Actual reset-page lookup extracted');
$lookup=$pdo->prepare(str_replace('NOW()',"'2026-10-08'",$sql[1]));
foreach ([$raw=>1,$legacy=>2,'expired'=>false,'used'=>false,'invalid'=>false,$params[':hashed']=>false] as $token=>$id){$lookup->execute(reset_token_parameters($token));$row=$lookup->fetch();verify(($row?$row['id']:false)===$id,'Reset validity/legacy/digest replay '.$id);}
$pdo->exec('UPDATE password_resets SET used=1 WHERE id=1');$lookup->execute(reset_token_parameters($raw));verify(!$lookup->fetch(),'Replaced/reused token rejected');
verify(str_contains($source,"header('Referrer-Policy: origin')"),'Reset token page shares only origin; HTTPS browser test verifies token secrecy');

verify(class_exists('ZipArchive'),'ZIP extension enabled for container tests');
$tmp=tempnam(sys_get_temp_dir(),'prism-office-');
function archive_fixture(string $path,array $entries):void {
    $z=new ZipArchive();verify($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'Create isolated Office fixture');
    foreach($entries as $name=>$content)$z->addFromString($name,$content);$z->close();
}
try {
    foreach ([['docx',['[Content_Types].xml'=>'<Types/>','word/document.xml'=>'<w:document/>'],true],
        ['odt',['mimetype'=>'application/vnd.oasis.opendocument.text','content.xml'=>'<office:document/>'],true],
        ['docx',['random.txt'=>'Random','other.txt'=>'Arbitrary'],false],
        ['docx',['[Content_Types].xml'=>'<Types/>','other.xml'=>'Missing document'],false],
        ['odt',['mimetype'=>'application/zip','content.xml'=>'<office:document/>'],false],
        ['odt',['mimetype'=>'application/vnd.oasis.opendocument.text','missing.xml'=>'Missing content'],false],
        ['docx',['[Content_Types].xml'=>'<Types/>','word/document.xml'=>'<w:document/>','../escape'=>'Hostile'],false],
        ['docx',['[Content_Types].xml'=>'<Types/>','word/document.xml'=>str_repeat('a',2*1024*1024)],false],
        ] as [$ext,$entries,$expected]) { archive_fixture($tmp,$entries);verify(office_container_is_valid($tmp,$ext)===$expected,'Office structure/resource validation'); }
    file_put_contents($tmp,'arbitrary binary');verify(!office_container_is_valid($tmp,'docx'),'Binary renamed DOCX denied');
    file_put_contents($tmp,"PK\x03\x04malformed");verify(!office_container_is_valid($tmp,'odt'),'Malformed ZIP denied');
    foreach(['pdf','doc','txt','rtf','png','jpg'] as $ext)verify(office_container_is_valid($tmp,$ext),'Non-ZIP formats retain existing MIME validation');
} finally { unlink($tmp); }

function extract_function(string $source,string $name):string{$a=strpos($source,'function '.$name.'(');$b=strpos($source,"\n}\n",$a);return substr($source,$a,$b+2-$a);}
$source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../reports_api.php'));
eval(extract_function($source,'report_pdf_plain').extract_function($source,'ai_narrative_excludes_names'));
foreach(['Maria A. Santos','May Reyes','Mark Tan','José Dela Cruz','Anne-Marie Garcia','Lee Wu'] as $name){
    verify(!ai_narrative_excludes_names('Identified student: '.$name,[['full_name'=>$name]]),'Actual full-name leakage blocked');
}
verify(ai_narrative_excludes_names('A report may summarize Stage 1.',[['full_name'=>'Maria A. Santos'],['full_name'=>'May Reyes']]),'Initial and modal may do not reject ordinary narrative');
foreach(['Santos','Reyes','Tan','Garcia'] as $surname)verify(!ai_narrative_excludes_names('Case: '.$surname,[['full_name'=>'Student '.$surname]]),'Meaningful surname leakage blocked');
verify(!ai_narrative_excludes_names('Jose Dela Cruz',[['full_name'=>'José Dela Cruz']]),'Transliterated leakage blocked');
echo "PASS: $checks token, password, Office container and AI-filter checks.\n";
