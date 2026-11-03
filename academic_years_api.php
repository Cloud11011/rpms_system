<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
require_once __DIR__.'/includes/current_workflow_eligibility.php';
require_once __DIR__.'/includes/academic_years.php';
require_once __DIR__.'/includes/document_workflow.php';
$actor=api_require_login('admin'); $pdo=db();
header('Cache-Control: no-store');
if (($_GET['action']??'list')==='list') json_out([
    'ok'=>true,'academicYears'=>academic_years($pdo),
    'registrationYears'=>academic_year_registration_years($pdo),
    'yearSettings'=>academic_year_settings_list($pdo),
    // Adding to the shared catalog is the existing schema-15 operation; policy settings need schema 16.
    'canAdd'=>academic_years_available($pdo),
    'canManage'=>academic_year_management_available($pdo),
    'canDelete'=>academic_year_removals_available($pdo),
]);
require_post_same_origin(); require_session_generation(true);
try {
    $data=json_body();
    $action=(string)($_GET['action']??'');
    $result=match ($action) {
        'add'=>academic_year_add($pdo,$actor,$data['academicYear']??null),
        'set-availability'=>academic_year_set_availability($pdo,$actor,$data['academicYear']??null,$data['availableForRegistration']??null),
        'set-current'=>academic_year_set_current($pdo,$actor,$data['academicYear']??null),
        'remove'=>academic_year_remove($pdo,$actor,$data['academicYear']??null),
        default=>throw new AccountLifecycleValidation('Unknown action.'),
    };
    json_out($result);
} catch (Throwable $e) {
    $status=lifecycle_error_status($e);
    if ($status>=500) log_api_error('academic_year_settings','Academic-year settings could not be saved.');
    json_out(['ok'=>false,'message'=>$status<500?$e->getMessage():'The academic-year settings could not be saved. Refresh before retrying.'],$status);
}
