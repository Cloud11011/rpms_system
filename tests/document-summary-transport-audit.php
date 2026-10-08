<?php
namespace PrismSummaryTransportAudit;
use RuntimeException;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const OPENROUTER_MODEL = 'existing-configured-model';
const OPENROUTER_API_KEY = 'fixture-key';
function openrouter_available(): bool { return $GLOBALS['available']; }
function log_api_error(string $service, string $message): void { $GLOBALS['logs'][] = $message; }
function curl_init(string $url): object { $GLOBALS['url']=$url; return new \stdClass(); }
function curl_setopt_array($handle, array $options): bool { $GLOBALS['options']=$options; return true; }
function curl_setopt($handle, int $option, mixed $value): bool { $GLOBALS['options'][$option]=$value; return true; }
function curl_exec($handle): string|bool {
    if ($GLOBALS['networkError']) return false;
    $response=$GLOBALS['providerBody'];
    if (isset($GLOBALS['options'][CURLOPT_WRITEFUNCTION])) {
        foreach (str_split($response, 4096) as $chunk) if ($GLOBALS['options'][CURLOPT_WRITEFUNCTION]($handle,$chunk)!==strlen($chunk)) return false;
        return true;
    }
    return $response;
}
function curl_getinfo($handle,int $key): int { return $GLOBALS['providerStatus']; }
function curl_error($handle): string { return $GLOBALS['networkError']?'Mock transport error with sensitive data':''; }
function curl_close($handle): void {}
$source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../config.php'));
$start=strpos($source,'function openrouter_generate(');$end=strpos($source,"\n}\n",$start);
eval('namespace '.__NAMESPACE__.';' . substr($source,$start,$end+2-$start));
$checks=0;
function verify(bool $ok,string $label): void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label); }
foreach ([false,true] as $sensitive) foreach (['success','http_error','network_error','oversized','unavailable'] as $mode) {
    $GLOBALS['available']=$mode!=='unavailable'; $GLOBALS['options']=[]; $GLOBALS['logs']=[];
    $GLOBALS['networkError']=$mode==='network_error'; $GLOBALS['providerStatus']=$mode==='http_error'?502:200;
    $GLOBALS['providerBody']=$mode==='oversized'?str_repeat('x',70000):json_encode(['choices'=>[['message'=>['content'=>'Safe generated summary']]],'error'=>'sensitive echoed document']);
    $out=openrouter_generate('Fixed system prompt','Redacted text',$sensitive);
    if ($mode==='success') verify($out==='Safe generated summary','Actual shared transport returns content');
    elseif ($mode==='oversized' && !$sensitive) verify($out===null,'Legacy malformed response behavior preserved');
    else verify($out===null,'Failure/unavailable produces fallback signal');
    if ($mode==='unavailable') { verify(!$GLOBALS['options'],'Unavailable model opens no transport');continue; }
    $payload=json_decode($GLOBALS['options'][CURLOPT_POSTFIELDS],true);
    verify($payload['model']===OPENROUTER_MODEL && $GLOBALS['url']==='https://openrouter.ai/api/v1/chat/completions','Configured model/provider architecture unchanged');
    verify(isset($payload['max_tokens'])===$sensitive,'Output-token setting scoped to document calls');
    verify(isset($GLOBALS['options'][CURLOPT_WRITEFUNCTION])===$sensitive,'Response bound scoped to document calls');
    if ($sensitive) verify(!str_contains(json_encode($GLOBALS['logs']),'sensitive'),'Sensitive request never logs response body or curl text');
    elseif ($mode==='http_error') verify(str_contains(json_encode($GLOBALS['logs']),'sensitive'),'Existing aggregate error behavior unchanged');
}
echo "PASS: $checks sensitive transport and aggregate compatibility assertions (mocked cURL, no network).\n";
