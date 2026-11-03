<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
require_once __DIR__.'/includes/current_workflow_eligibility.php';
require_once __DIR__.'/includes/admin_settings_store.php';

$actor=api_require_login('admin'); $pdo=db();
header('Cache-Control: no-store');
$defaults=prism_notification_template_defaults();
if (($_GET['action']??'list')==='list') {
    try {
        $available=prism_settings_store_available($pdo);
        json_out(['ok'=>true,'settingsAvailable'=>$available,
            'message'=>$available?'':'Communication settings require the approved settings migration.',
            'templates'=>prism_settings_get($pdo,'communication.notification_templates',$defaults),
            'reminderIntervals'=>prism_settings_get($pdo,'communication.deadline_reminder_intervals',[7,3,1])]);
    } catch (Throwable $e) {
        log_api_error('admin_settings','Communication settings could not be loaded.');
        json_out(['ok'=>false,'message'=>'Communication settings could not be loaded. Refresh and try again.'],500);
    }
}
require_post_same_origin(); require_session_generation(true);
try {
    $data=json_body();
    $result=match ((string)($_GET['action']??'')) {
        'save-template'=>admin_settings_save_template($pdo,$actor,$data,$defaults),
        'save-reminders'=>admin_settings_save_reminders($pdo,$actor,$data),
        default=>throw new AccountLifecycleValidation('Unknown settings action.'),
    };
    json_out($result);
} catch (Throwable $e) {
    $status=lifecycle_error_status($e);
    if ($status>=500) log_api_error('admin_settings','Communication settings could not be saved.');
    json_out(['ok'=>false,'message'=>$status<500?$e->getMessage():'Communication settings could not be saved. Refresh before retrying.'],$status);
}

function admin_settings_save_template(PDO $pdo,array $actor,array $data,array $defaults): array
{
    $key=$data['templateKey']??null; $template=$data['template']??null;
    if (!is_string($key) || !array_key_exists($key,$defaults) || !is_string($template)
        || !mb_check_encoding($template,'UTF-8') || trim($template)==='' || mb_strlen($template)>1000
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u',$template)) {
        throw new AccountLifecycleValidation('Enter a plain-text template of up to 1,000 characters.');
    }
    preg_match_all('/\{([^{}]*)\}/',$template,$matches);
    foreach ($matches[1] as $placeholder) if (!in_array($placeholder,['message','recipient'],true)) {
        throw new AccountLifecycleValidation('Use only the supported {message} and {recipient} placeholders.');
    }
    if (strpbrk(preg_replace('/\{(?:message|recipient)\}/','',$template),'{}')!==false) {
        throw new AccountLifecycleValidation('Template placeholders must use the supported complete format.');
    }
    if (!in_array('message',$matches[1],true)) throw new AccountLifecycleValidation('The {message} placeholder is required to preserve system-generated details.');
    $templates=prism_settings_update_template($pdo,$actor,$key,trim($template),$defaults);
    return ['ok'=>true,'templates'=>$templates,'message'=>'Notification template saved.'];
}

function admin_settings_save_reminders(PDO $pdo,array $actor,array $data): array
{
    $intervals=$data['intervals']??null;
    if (!is_array($intervals) || array_values($intervals)!==$intervals || !$intervals || count($intervals)>5) {
        throw new AccountLifecycleValidation('Choose between one and five reminder intervals.');
    }
    foreach ($intervals as $interval) if (!is_int($interval) || $interval<1 || $interval>30) {
        throw new AccountLifecycleValidation('Reminder intervals must be whole numbers from 1 to 30 days.');
    }
    if (count(array_unique($intervals))!==count($intervals)) throw new AccountLifecycleValidation('Reminder intervals must not contain duplicates.');
    rsort($intervals,SORT_NUMERIC);
    prism_settings_save($pdo,$actor,'communication.deadline_reminder_intervals',$intervals);
    return ['ok'=>true,'reminderIntervals'=>$intervals,'message'=>'Deadline reminder intervals saved.'];
}
