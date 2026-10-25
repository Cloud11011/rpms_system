<?php
require __DIR__.'/config.php';
require_once __DIR__.'/workflow.php';
require_once __DIR__.'/includes/account_lifecycle.php';
require_once __DIR__.'/includes/stage_configuration.php';
$user=api_require_login(['admin','adviser','student']);
$pdo=db();
$action=$_GET['action']??'list';
if ($action==='list') json_out(stage_configuration_list($pdo));
if ($action==='save') {
    require_post_same_origin();
    api_require_login('admin');
    $data=json_body();
    try { json_out(stage_configuration_save($pdo,$user,$data)); }
    catch (Throwable $e) {
        if ($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden || $e instanceof AccountLifecycleValidation) {
            json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        }
        log_api_error('stage_configuration',$e->getMessage());
        json_out(['ok'=>false,'message'=>'The stage configuration could not be saved. Refresh to check its current values before retrying.'],500);
    }
}
json_out(['ok'=>false,'message'=>'Unknown action.'],400);
