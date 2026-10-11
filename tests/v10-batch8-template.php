<?php
namespace PrismBatch8Template;
/** Source-faithful Support presentation fixture; never loads installed configuration. */
if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/../includes/assets.php';
require_once __DIR__.'/../security.php';
function db(): \PDO { return new class extends \PDO { public function __construct() {} }; }
function require_login($roles=null): array { return ['id'=>3,'role'=>$GLOBALS['role'],'email'=>'fixture@example.invalid','full_name'=>'Fixture Requester','status'=>'Active']; }
function support_account(...$args): array { return ['profile_completed_at'=>$GLOBALS['pending']?null:'2026-01-01']; }
$pending=($argv[1]??'pending')==='pending';$role=$argv[2]??'student';
$_SESSION=['user_id'=>3,'login_generation'=>str_repeat('a',32),'support_send_token'=>'fixture-support-token'];
$helper=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../includes/help_support.php'));
foreach (['support_categories','support_token'] as $name) {
    $a=strpos($helper,'function '.$name.'(');$b=strpos($helper,"\n}\n",$a);
    eval('namespace '.__NAMESPACE__.'; '.substr($helper,$a,$b+2-$a));
}
$source=file_get_contents(__DIR__.'/../help_support.php');
foreach (["require __DIR__.'/config.php';","require_once __DIR__.'/includes/help_support.php';"] as $include) {
    if (substr_count($source,$include)!==1) throw new \RuntimeException('Unexpected Support bootstrap boundary.');
    $source=str_replace($include,'',$source);
}
foreach (['legal_header.php','session_browser.php'] as $partial) $source=str_replace("require __DIR__.'/includes/$partial';",'require '.var_export(__DIR__.'/../includes/'.$partial,true).';',$source);
eval('namespace '.__NAMESPACE__.'; '.preg_replace('/^<\?php\s*/','',$source));
