<?php
namespace PrismEmailFormatAudit;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/email_format.php';
const MAIL_FROM_NAME='Fixture',GMAIL_SENDER_EMAIL='fixture@example.test',MAIL_LOG='fixture-mail-log';
function gmail_access_token():string{return 'fixture-token';}
function gmail_api_available():bool{return $GLOBALS['gmail'];}
function curl_init($url){return $url;}
function curl_setopt_array($handle,$options):bool{$GLOBALS['curlOptions']=$options;return true;}
function curl_exec($handle):string{return $GLOBALS['providerResponse']??'{}';}
function curl_getinfo($handle,$option){return $GLOBALS['providerStatus']??200;}
function curl_error($handle):string{return '';}
function curl_close($handle):void{}
function mail($to,$subject,$body,$headers):bool{$GLOBALS['fallback']=compact('to','subject','body','headers');return $GLOBALS['mailOk'];}
function file_put_contents($path,$body,$flags):int|false{if(!empty($GLOBALS['logFails']))return false;$GLOBALS['log']=$body;return strlen($body);}
function log_api_error(...$args):void{$GLOBALS['apiErrors'][]=$args;}
$source=str_replace("\r\n","\n",\file_get_contents(__DIR__.'/../config.php'));
foreach(['gmail_api_send','send_notification_email'] as $name){$a=strpos($source,'function '.$name.'(');$b=strpos($source,"\n}",$a);eval('namespace '.__NAMESPACE__.'; '.substr($source,$a,$b+2-$a));}
$checks=0;
function check(bool $ok,string $label):void{$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException($label);}
$body="Hello <img src=x onerror=alert(1)> & Student,\n\nYour document \"<script>alert(1)</script>.pdf\" has been reviewed.\nReview status: Approved\nIERB stage: Stage 2\nProtocol code: <b>ABC</b>\n\nRemarks: <svg onload=alert(1)>";
$html=notification_email_html($body);
check(str_contains($html,'<strong>&lt;img src=x onerror=alert(1)&gt; &amp; Student</strong>'),'Recipient emphasized and escaped');
check(str_contains($html,'<strong>&lt;script&gt;alert(1)&lt;/script&gt;.pdf</strong>'),'Filename escaped');
check(str_contains($html,'<strong>Approved</strong>')&&str_contains($html,'<strong>Stage 2</strong>'),'Important status/stage emphasized');
check(str_contains($html,'<strong>&lt;b&gt;ABC&lt;/b&gt;</strong>')&&!str_contains($html,'<svg')&&!str_contains($html,'<script>'),'Stored HTML never renders');
$summary=notification_email_html("Student uploaded \"<b>file</b>.pdf\".\nCurrent stage: Stage 1\nFormal-submission reference: ABC\nStatus: Pending Adviser Review. You will be notified soon.");
check(str_contains($summary,'<strong>&lt;b&gt;file&lt;/b&gt;.pdf</strong>'),'Adviser narrative filename emphasized safely');
check(str_contains($summary,'<strong>Stage 1</strong>')&&str_contains($summary,'<strong>ABC</strong>'),'Existing follow-up and submission labels emphasized');
check(str_contains($summary,'<strong>Pending Adviser Review</strong>. You will be notified soon.'),'Status explanation stays normal weight');
$gmail=true;$mailOk=false;$r=send_notification_email('recipient@example.test','Fixture subject',$body);
check($r['channel']==='gmail_api','Gmail remains primary');
$encoded=json_decode($curlOptions[CURLOPT_POSTFIELDS],true)['raw'];$raw=base64_decode(strtr($encoded,'-_','+/'));
check(str_contains($raw,'MIME-Version: 1.0')&&str_contains($raw,'multipart/alternative'),'Gmail MIME envelope');
check(str_contains($raw,'To: recipient@example.test')&&str_contains($raw,'Subject: =?UTF-8?B?'),'Recipient/subject preserved');
function check_parts(string $raw,string $plain):void {
    preg_match('/boundary="([^"]+)"/',$raw,$match);check(isset($match[1]),'Multipart boundary');
    $parts=explode('--'.$match[1],$raw);$decoded=[];
    foreach($parts as $part){if(preg_match('/Content-Type: (text\/(?:plain|html));.*?\r\n\r\n(.*)/s',$part,$m))$decoded[$m[1]]=base64_decode(trim($m[2]));}
    check(($decoded['text/plain']??null)===$plain,'Readable exact plain alternative');
    check(($decoded['text/html']??null)===notification_email_html($plain),'Safe HTML alternative');
    check(str_ends_with($raw,'--'.$match[1]."--\r\n"),'Closing boundary');
}
check_parts($raw,$body);
$gmail=false;$mailOk=true;$r=send_notification_email('recipient@example.test','Subject',$body);
check($r['channel']==='mail','PHP mail fallback preserved');check_parts($fallback['headers']."\r\n".$fallback['body'],$body);
$mailOk=false;$r=send_notification_email('recipient@example.test','Subject',$body);check($r['channel']==='log'&&str_contains($log,$body),'Log retains readable original plain text');
$r=send_notification_email('invalid','Subject',$body);check(!$r['ok'],'Invalid email handled');
$log='';$apiErrors=[];$providerStatus=500;$providerResponse='Echoed secret-reset-token';$gmail=true;$mailOk=false;
$r=send_notification_email('recipient@example.test','Password reset','https://fixture.test/reset?token=secret-reset-token',true);
check(!$r['ok']&&$r['channel']==='none','Credential email delivery failure is honest');
check($log==='','Sensitive body never enters mail log');
check(!str_contains(json_encode($apiErrors),'secret-reset-token'),'Provider echo never leaks token into API errors');
check(!str_contains(json_encode($r),'secret-reset-token'),'Credential token absent from browser delivery result');
$log='';$apiErrors=[];
$r=send_notification_email('recipient@example.test','Private subject',$body,false,false);
check($r['ok']&&$r['channel']==='log','Notification metadata fallback keeps Logged semantics');
check(preg_match('/^\[[^\]\r\n]+\] notification_delivery_fallback status=Logged\n$/',$log)===1,'Only timestamp/event/status are logged');
foreach(['Private subject',$body,'recipient@example.test','secret-reset-token','<img','<svg'] as $private) check(!str_contains($log,$private),'Notification content/recipient/provider echo excluded');
check(!str_contains(json_encode($apiErrors),'secret-reset-token'),'Notification provider error body excluded');
$log='';$providerStatus=200;$r=send_notification_email('recipient@example.test','Private subject',$body,false,false);
check($r['channel']==='gmail_api'&&$log==='','Notification Gmail success has no fallback log');
$providerStatus=500;$mailOk=true;$r=send_notification_email('recipient@example.test','Private subject',$body,false,false);
check($r['channel']==='mail'&&$log==='','Notification server mail success has no fallback log');
$mailOk=false;
foreach (['SUPPORT PRIVATE MESSAGE','WORKFLOW PRIVATE MESSAGE','<html>PRIVATE HTML EMAIL</html>'] as $privateBody) {
    $r=send_notification_email('recipient@example.test','PRIVATE NOTIFICATION SUBJECT',$privateBody,false,false);
    check($r['channel']==='log' && !str_contains($log,$privateBody) && !str_contains($log,'PRIVATE NOTIFICATION SUBJECT'),'Support/workflow/HTML fallback excludes content');
}
$logFails=true;$r=send_notification_email('recipient@example.test','PRIVATE NOTIFICATION SUBJECT','PRIVATE BODY',false,false);
check(!$r['ok'] && $r['channel']==='none' && !str_contains(json_encode($r),'PRIVATE'),'Unavailable metadata fallback gives a controlled content-free failure');
$logFails=false;$log='';
$mailOk=false;$providerStatus=200;$apiErrors=[];$r=send_notification_email('recipient@example.test','Account setup','secret-reset-token',true);
check($r['ok']&&$r['channel']==='gmail_api'&&$log==='','Sensitive email delivered via configured transport');
echo "PASS: $checks actual Gmail/mail/log MIME and HTML escaping checks; transports stubbed.\n";
