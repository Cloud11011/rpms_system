<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/help_support.php';
$user=api_require_login(['student','adviser']);
$action=$_GET['action']??'responses';
// Reads also bind to the identity that rendered this Support page.
require_session_generation(true);
$mutations=['submit','mark_read','archive','restore','delete','campaign_archive','campaign_restore','campaign_delete'];
if (in_array($action,$mutations,true)) require_post_same_origin();
try {
    $pdo=db(); support_account($pdo,$user);
    if ($action==='submit') json_out(support_submit($pdo,$user,json_body()));
    if (in_array($action,['requests','responses'],true)) json_out(notification_list($pdo,$user,$_GET,'support_'.$action));
    if (in_array($action,$mutations,true)) json_out(support_transition($pdo,$user,$action,json_body()));
    json_out(['ok'=>false,'message'=>'Unknown support action.'],400);
} catch (NotificationLifecycleError $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],$e->status); }
catch (Throwable $e) {
    log_api_error('help_support','Support operation could not complete.');
    json_out(['ok'=>false,'message'=>'Support is temporarily unavailable. Your request has not been sent. Please try again.'],503);
}
