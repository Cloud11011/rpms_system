<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/csv_export.php';
require_once __DIR__ . '/includes/academic_catalog.php';
require_once __DIR__ . '/includes/research_groups.php';
$user = api_require_login('admin');
$action = $_GET['action'] ?? '';
if (!is_string($action) || !in_array($action, ['students','advisers','ierb','documents'], true)) {
    json_out(['ok'=>false, 'message'=>'Choose an available data export.'], 422);
}
// Preserve the existing scoped, filtered, ordered IERB exporter and its audit event.
if ($action === 'ierb') {
    $query = ['action'=>'export_csv'];
    foreach (['q','stage','status','course','academicUnitKey','programKey','academicYear','yearLevel','group','adviserId','protocol','sort','sortBy','direction'] as $key) {
        if (isset($_GET[$key])) {
            if (!is_string($_GET[$key]) || mb_strlen($_GET[$key]) > 250) json_out(['ok'=>false,'message'=>'Invalid export filter.'],422);
            $query[$key] = $_GET[$key];
        }
    }
    header('Location: ierb_api.php?' . http_build_query($query));
    exit;
}
$pdo = db();
$catalog = academic_catalog();
$unit = static fn($key) => $catalog['units'][$key ?? '']['label'] ?? ($key ?? '');
$program = static fn(array $r) => $catalog['programs'][$r['program_key'] ?? '']['label'] ?? ($r['course'] ?: ($r['program_key'] ?? ''));
$stamp = date('Ymd_His');
if ($action === 'students') {
    $rows = $pdo->query('SELECT s.student_id,s.full_name,s.email,s.academic_unit_key,s.program_key,s.course,
        s.year_level,s.academic_year,s.research_title,s.research_group,a.full_name AS adviser_name,
        s.stage,s.status,s.requirements,s.protocol_code,s.is_principal_investigator,s.archived_at,s.profile_completed_at,s.created_at,s.updated_at
        FROM students s LEFT JOIN advisers a ON a.id=s.adviser_id ORDER BY s.full_name,s.id');
    $count = prism_stream_csv('prism_student_records_'.$stamp.'.csv',
        ['Student ID','Full Name','Email','Academic Unit / Department','Program / Course','Year Level','Academic Year',
         'Research Title','Research Group','Assigned Adviser','IERB Stage','IERB Status','Pending Requirements','Protocol Code',
         'Principal Investigator','Record Status','Archived At','Created Date','Updated Date','Profile Status'], $rows,
        static fn($r) => [$r['student_id'],$r['full_name'],$r['email'],$unit($r['academic_unit_key']),$program($r),
            $r['year_level'],$r['academic_year'],$r['research_title'],$r['research_group'],$r['adviser_name'],
            $r['stage'],$r['status'],$r['requirements'],$r['protocol_code'],!empty($r['is_principal_investigator'])?'Yes':'No',
            empty($r['archived_at'])?'Active':'Archived',$r['archived_at'],$r['created_at'],$r['updated_at'],$r['profile_completed_at']===null?'Pending':'Complete']);
    $event = 'student_records_exported';
} elseif ($action === 'advisers') {
    $groups = research_groups_by_adviser($pdo);
    // A single aggregate join counts active assignments without per-adviser queries.
    $rows = $pdo->query('SELECT a.id,a.employee_id,a.full_name,a.email,a.department,a.status,a.profile_completed_at,a.created_at,a.updated_at,
        COALESCE(assigned.total,0) AS active_students FROM advisers a LEFT JOIN
        (SELECT adviser_id,COUNT(*) AS total FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL GROUP BY adviser_id) assigned
        ON assigned.adviser_id=a.id ORDER BY a.full_name,a.id');
    $count = prism_stream_csv('prism_adviser_records_'.$stamp.'.csv',
        ['Employee ID','Full Name','Email','Academic Unit / Department','Account Status','Currently Assigned Research Groups',
         'Active Assigned Student Count','Created Date','Updated Date','Profile Status'], $rows,
        static fn($r) => [$r['employee_id'],$r['full_name'],$r['email'],$unit($r['department']),$r['status'],
            implode(' | ', $groups[(int)$r['id']] ?? []),$r['active_students'],$r['created_at'],$r['updated_at'],$r['profile_completed_at']===null?'Pending':'Complete']);
    $event = 'adviser_records_exported';
} else {
    // Explicit metadata allowlist: no storage names, paths, credentials or summary/document text.
    $rows = $pdo->query("SELECT d.id,d.original_name,s.student_id,COALESCE(s.full_name,d.student_name) AS student_name,
        CASE WHEN s.id IS NULL THEN 'Unlinked/Historical' WHEN s.archived_at IS NULL THEN 'Active' ELSE 'Archived' END AS record_status,
        s.research_group,s.course,s.program_key,d.document_type,d.stage,d.version_no,d.is_current,d.uploaded_by,d.uploaded_by_role,
        d.uploaded_at,d.size,d.mime,d.review_status,d.review_remarks,d.reviewed_by,d.reviewed_at,d.rpms_submitted_at,d.rpms_submitted_by,
        d.admin_override,d.override_by,d.override_at,CASE WHEN d.ai_summary IS NOT NULL AND TRIM(d.ai_summary)<>'' THEN 1 ELSE 0 END AS summary_available
        FROM documents d LEFT JOIN students s ON s.id=d.student_id ORDER BY d.uploaded_at DESC,d.id DESC");
    $count = prism_stream_csv('prism_document_records_'.$stamp.'.csv',
        ['Document ID','Original File Name','Student ID','Student Name','Student Record Status','Research Group','Program / Course',
         'Document Type','IERB Stage','Version Number','Current Version','Uploaded By','Uploaded By Role','Uploaded Date','File Size','MIME Type',
         'Adviser Review Status','Review Remarks','Reviewed By','Reviewed Date','Formal RPMS Submission Date','Formal Submission By',
         'Administrative Override','Override By','Override Date','AI Summary Available'], $rows,
        static fn($r) => [$r['id'],$r['original_name'],$r['student_id'],$r['student_name'],$r['record_status'],$r['research_group'],$program($r),
            $r['document_type'],$r['stage'],$r['version_no'],!empty($r['is_current'])?'Yes':'No',$r['uploaded_by'],$r['uploaded_by_role'],
            $r['uploaded_at'],$r['size'],$r['mime'],$r['review_status'],$r['review_remarks'],$r['reviewed_by'],$r['reviewed_at'],
            $r['rpms_submitted_at'],$r['rpms_submitted_by'],!empty($r['admin_override'])?'Yes':'No',$r['override_by'],$r['override_at'],
            !empty($r['summary_available'])?'Yes':'No']);
    $event = 'document_records_exported';
}
audit_log($user, $event, ['entity_type'=>'data_export','after'=>$action,'details'=>'CSV export; rows='.$count]);
exit;
