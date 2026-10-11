<?php
namespace PrismBatch6Template;
/** Presentation fixture only; no config/bootstrap/database or real service access. */
if (PHP_SAPI!=='cli' || !in_array($argv[1]??'', ['privacy.php','terms.php','legal_consent.php','admin_legal_policies.php'],true)) exit(1);
require_once __DIR__.'/../includes/legal_policy.php';
require_once __DIR__.'/../includes/assets.php';
require_once __DIR__.'/../security.php';
function db(): \PDO { return new class extends \PDO { public function __construct() {} }; }
function require_login($roles=null): array { return ['id'=>1,'email'=>'fixture@example.invalid','role'=>'admin','full_name'=>'Fixture Admin','username'=>'FIXTURE','status'=>'Active','must_change_password'=>0,'password_hash'=>password_hash('fixture',PASSWORD_DEFAULT)]; }
function legal_current(...$args): array { return $GLOBALS['current']; }
function legal_outstanding(...$args): array { return array_intersect_key($GLOBALS['current'],array_flip(($GLOBALS['scenario']??'')==='partial'?['terms']:['privacy','terms'])); }
function legal_rows(...$args): array { return str_contains($args[1],'FROM users')?array_fill(0,($GLOBALS['scenario']??'')==='sole'?1:2,require_login()):$GLOBALS['history']; }
$_SERVER['REQUEST_METHOD']='GET';$_SESSION=['user_id'=>1,'login_generation'=>str_repeat('a',32)];
$scenario=$argv[2]??'draft';$seed=json_decode(file_get_contents(__DIR__.'/../includes/schema_v10_seed.json'),true);
$current=[];$history=[];
foreach (['privacy','terms'] as $i=>$policy) {
    $v=$seed[$policy]+['id'=>$i+1,'policy_id'=>$policy,'version_number'=>1,'state'=>'published','change_summary'=>'Approved source','published_at'=>'2026-10-11 12:00:00','creator_user_id'=>null,'decision'=>'approved','approval_mode'=>'bootstrap','review_reason'=>null,'reviewed_at'=>'2026-10-11 12:00:00'];
    $current[$policy]=$v; $history[]=$v;
}
$candidate=array_replace($current['privacy'],['id'=>3,'version_number'=>2,'state'=>in_array($scenario,['pending','creator','sole'],true)?'pending':($scenario==='history'?'published':'draft'),'creator_user_id'=>$scenario==='pending'?2:1,'content'=>"# Preview fixture\n\n## Safe policy\n<script>window.__fixtureXss=1</script>\n<img src=x onerror=\"window.__fixtureXss=1\">\n- Plain list",'change_summary'=>'Preview change','decision'=>null]);
$candidate['content_sha256']=hash('sha256',$candidate['content']);$history[]=$candidate;
$_GET=['version'=>'3'];
$file=$argv[1];
if (in_array($file,['privacy.php','terms.php'],true)) { $legalPageKey=pathinfo($file,PATHINFO_FILENAME); $file='includes/public_legal.php'; }
$source=file_get_contents(__DIR__.'/../'.$file);
$source=preg_replace("~require(?:_once)? __DIR__\\s*\\.\\s*'/((?:\\.\\./)?config\\.php)';~",'', $source,1,$count);
if ($count!==1) throw new \RuntimeException('Exact configuration boundary required.');
$source=str_replace("require __DIR__.'/includes/session_browser.php';",'', $source);
// The public renderer reads only owner placeholder data; keep its exact source-relative path.
$source=str_replace("require __DIR__.'/legal_placeholders.php'", "require ".var_export(__DIR__.'/../includes/legal_placeholders.php',true),$source);
if (preg_match('/config\.local|\/config\.php/',$source)) throw new \RuntimeException('Unexpected configuration in fixture.');
eval('namespace '.__NAMESPACE__.'; '.preg_replace('/^<\?php\s*/','',$source));
