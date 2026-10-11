<?php
/** Load shared policies without installed configuration or database bootstrap. */
require_once __DIR__.'/../security.php';
require_once __DIR__.'/../includes/staff_registration.php';
if (!defined('ALLOWED_EMAIL_DOMAINS')) define('ALLOWED_EMAIL_DOMAINS',['example.invalid']);
$source=file_get_contents(__DIR__.'/../config.php');
foreach (['is_allowed_email_domain','allowed_email_domains_hint'] as $name) {
    if (function_exists($name)) continue;
    $start=strpos($source,'function '.$name.'('); $end=strpos($source,"\n}",$start);
    eval(substr($source,$start,$end+2-$start));
}

/** Read only the obsolete literal for regression; never execute local configuration or emit its value. */
function b9_legacy_config_code(): ?string
{
    $path=__DIR__.'/../config.local.php'; if (!is_file($path)) return null;
    $tokens=array_values(array_filter(token_get_all(file_get_contents($path)),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
    foreach ($tokens as $i=>$token) {
        if (!is_array($token) || $token[0]!==T_STRING || strtolower($token[1])!=='define' || ($tokens[$i+1]??null)!=='(') continue;
        $name=$tokens[$i+2]??null; $value=$tokens[$i+4]??null;
        if (!is_array($name) || $name[0]!==T_CONSTANT_ENCAPSED_STRING || trim($name[1],"'\"")!=='ADMIN_REGISTRATION_CODE'
            || ($tokens[$i+3]??null)!==',' || !is_array($value) || $value[0]!==T_CONSTANT_ENCAPSED_STRING) continue;
        $literal=$value[1];$body=substr($literal,1,-1);
        return $literal[0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$body):stripcslashes($body);
    }
    return null;
}
