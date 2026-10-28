<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
require_once __DIR__.'/includes/current_workflow_eligibility.php';
require_once __DIR__.'/includes/academic_years.php';
require_once __DIR__.'/includes/document_workflow.php';
$actor=api_require_login('admin'); $pdo=db();
header('Cache-Control: no-store');
if (($_GET['action']??'list')==='list') json_out(['ok'=>true,'academicYears'=>academic_years($pdo),'canAdd'=>academic_years_available($pdo)]);
if (($_GET['action']??'')!=='add') json_out(['ok'=>false,'message'=>'Unknown action.'],400);
require_post_same_origin(); require_session_generation(true);
try { $data=json_body(); json_out(academic_year_add($pdo,$actor,$data['academicYear']??null)); }
catch (Throwable $e) { $status=lifecycle_error_status($e); if ($status>=500) log_api_error('academic_year_add','Academic-year creation failed.'); json_out(['ok'=>false,'message'=>$status<500?$e->getMessage():'The academic year could not be saved. Refresh before retrying.'],$status); }
