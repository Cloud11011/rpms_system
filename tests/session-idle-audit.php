<?php
namespace PrismIdleAudit;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function time():int{return $GLOBALS['now'];}
function header(string $line):void{$GLOBALS['headers'][]=$line;}
function http_response_code($code):void{$GLOBALS['status']=$code;}
// Timer cases start with current legal acceptance; governance has its own suite.
function legal_acceptance_required(array $user):bool{return false;}
function prism_publish_session_generation():void{}
function audit_source(string $file):string{return \file_get_contents((getenv('PRISM_AUDIT_SOURCE_ROOT') ?: dirname(__DIR__)).'/'.$file);}
function db(){ $GLOBALS['queries']++; return new class { function prepare($sql){ return new class {function execute($params):void{} function fetch(){return $GLOBALS['user'];}};}};}
function source_function(string $source,string $name):string{$source=str_replace("\r\n","\n",$source);$a=strpos($source,'function '.$name.'(');$b=strpos($source,"\n}",$a);return substr($source,$a,$b+2-$a);}
if(($argv[1]??'')==='--case'){
 $case=json_decode($argv[2],true);$now=10000;$queries=0;$headers=[];$status=200;
 $_SESSION=['user_id'=>1,'last_activity_at'=>$now-$case['idle'],'credential_fingerprint'=>hash('sha256','fixture'),'must_change_password'=>0,'private'=>'secret'];
 if(!empty($case['anonymous']))$_SESSION=[];
 $_GET=['action'=>'list'];$_SERVER['SCRIPT_NAME']='ierb_api.php';$user=['id'=>1,'role'=>$case['role']??'admin','status'=>$case['deactivated']??'Active','password_hash'=>$case['password']??'fixture','must_change_password'=>$case['forced']??0];
 $source=audit_source('config.php');$functions='';foreach(['current_user','require_login','api_require_login'] as $name)$functions.=source_function($source,$name);
 eval('namespace '.__NAMESPACE__.';'.$functions);
 ob_start();register_shutdown_function(function(){echo json_encode(['body'=>json_decode(ob_get_clean(),true),'status'=>$GLOBALS['status'],'headers'=>$GLOBALS['headers'],'session'=>$_SESSION,'queries'=>$GLOBALS['queries']]);});
 if(!empty($case['html']))require_login('admin');else api_require_login('admin');
 if(!empty($case['second'])){$now+=1801;api_require_login('admin');}
 echo json_encode(['allowed'=>true]);exit;
}
$checks=0;
function check(bool $ok,string $label):void{$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException($label);}
foreach([0,1799,1800,1801,7200] as $idle){foreach([false,true] as $html){
 $case=compact('idle','html');$p=proc_open([PHP_BINARY,__FILE__,'--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0&&$err==='','Isolated guards');$r=json_decode($out,true);
 if($idle>1800){check($r['session']===[]&&$r['queries']===0,'Expiry checked before refresh/query');if($html)check($r['headers']===['Location: login.php?expired=1'],'Expired HTML redirect');else check($r['status']===401&&$r['body']['code']==='session_expired'&&$r['body']['message']==='Your session expired due to inactivity. Please sign in again.','Expired API response');}
 else check($r['body']['allowed']&&$r['session']['last_activity_at']===10000&&$r['queries']===1,'Valid request refreshes activity');
}}
$source=audit_source('login_process.php');check(str_contains($source,"\$_SESSION['last_activity_at'] = time();")&&str_contains($source,'session_regenerate_id(true);'),'Login initializes clock and retains rotation');
echo "PASS: $checks frozen-clock idle/session response checks.\n";
