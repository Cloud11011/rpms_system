<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/csv_export.php';
require_once __DIR__ . '/includes/record_filters.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/ierb_progress.php';
require_once __DIR__ . '/includes/account_lifecycle.php';
require_once __DIR__ . '/includes/academic_catalog.php';
require_once __DIR__ . '/includes/research_groups.php';
require_once __DIR__ . '/includes/ierb_group_advancement.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action,['monitor','monitor_compare','monitor_detail'],true)) {
    api_require_login(['admin','adviser']);
    header('Cache-Control: no-store');
    require_once __DIR__.'/includes/ierb_monitor.php';
    try {
        if ($action==='monitor') json_out(monitor_list($pdo,$user,$_GET));
        json_out(monitor_selected($pdo,$user,$_GET['keys']??null,$action==='monitor_detail',$_GET));
    } catch (Throwable $e) {
        $status=lifecycle_error_status($e);
        if ($status>=500) log_api_error('ierb_monitor',$e->getMessage());
        json_out(['ok'=>false,'message'=>$status<500?$e->getMessage():'The monitor could not be loaded. Refresh and try again.'],$status);
    }
}
if ($action === 'v14_contract') {
    header('Cache-Control: no-store');
    $hasV14 = ierb_v14_schema_available($pdo) && (bool)$pdo->query('SELECT 1 FROM ierb_workflows LIMIT 1')->fetchColumn();
    json_out(['ok'=>true,'contract'=>IerbStage::contract(),'activeWorkflow'=>$hasV14?'mixed':'legacy',
        'workflowModels'=>$hasV14?['legacy','v14']:['legacy'],'operationsActivated'=>ierb_v14_http_operations_enabled()]);
}
if (in_array($action, ['advance', 'correct', 'status', 'override', 'save', 'note', 'delete', 'group_advance_preview', 'group_advance', 'group_advance_cancel'], true)) {
    require_post_same_origin();
}
// V14 replaces the legacy per-Student stage mutation endpoints.
// Historical records remain readable, but cannot be changed through the old process.
if (ierb_v14_http_operations_enabled() && in_array($action, ['advance','correct','status','override','save','note','delete'], true)) {
    json_out(['ok'=>false,'message'=>'Legacy IERB stages are read-only. Manage active projects through V14 Group Advancement or Student Records.'],409);
}

if (in_array($action, ['group_advance_list','group_advance_preview','group_advance','group_advance_cancel'], true)) {
    api_require_login('admin');
    if ($action !== 'group_advance_list') require_session_generation(true);
    header('Cache-Control: no-store');
    try {
        if ($action === 'group_advance_list') json_out(ierb_group_list($pdo,$user));
        $payload=lifecycle_decode_body(file_get_contents('php://input'));
        if ($action === 'group_advance_preview') json_out(ierb_group_preview($pdo,$user,$payload['groupKey'] ?? null));
        if ($action === 'group_advance_cancel') json_out(ierb_group_cancel($user,$payload['confirmationToken'] ?? null));
        json_out(ierb_group_advance($pdo,$user,$payload));
    } catch (Throwable $e) {
        $status=lifecycle_error_status($e);
        if ($status>=500) log_api_error('ierb_group_advancement','The research group operation could not be completed.');
        json_out(['ok'=>false,'message'=>$status<500 ? $e->getMessage()
            : 'The group operation could not be completed. Refresh and review current group progress before continuing.'], $status);
    }
}

// V14 operations: RPMS-only enrollment, reconciliation, PI designation and official resubmission cases.
// Every mutation is POST, same-origin, session-generation bound, request-key idempotent and revision checked,
// and is refused unless the operator has activated V14 operations (ierb_v14_gate).
const IERB_V14_OPERATIONS = ['v14_correction_queue','v14_correction_request','v14_correction_decide','v14_correction_open','v14_correction_resolve',
    'v14_enroll','v14_project_preview','v14_project_reconcile','v14_project_rename','v14_project_adviser','v14_project_pi'];
if (in_array($action, IERB_V14_OPERATIONS, true)) {
    header('Cache-Control: no-store');
    require_once __DIR__.'/includes/ierb_v14_requirements.php';
    try {
        if ($action === 'v14_correction_queue') { api_require_login('admin'); json_out(ierb_v14_correction_queue($pdo,$user)); }
        require_post_same_origin();
        require_session_generation(true);
        api_require_login('admin');
        // The legacy Adviser request route is deliberately retired; only RPMS may initiate resubmissions.
        if ($action === 'v14_correction_request') json_out(['ok'=>false,'message'=>'Adviser correction requests are retired. RPMS Administrators initiate official resubmissions directly.'],410);
        $p = lifecycle_decode_body(file_get_contents('php://input'));
        $int = static function(string $k) use ($p): int {
            if (!isset($p[$k]) || !is_int($p[$k]) || $p[$k] < 0) throw new AccountLifecycleValidation('Provide a valid '.$k.'.');
            return $p[$k];
        };
        $str = static function(string $k, bool $optional=false) use ($p): string {
            if (!array_key_exists($k,$p) && $optional) return '';
            if (!isset($p[$k]) || !is_string($p[$k])) throw new AccountLifecycleValidation('Provide '.$k.'.');
            return $p[$k];
        };
        $ids = static function(string $k, bool $optional=false) use ($p): ?array {
            if (!array_key_exists($k,$p) && $optional) return null;
            if (!isset($p[$k]) || !is_array($p[$k]) || !array_is_list($p[$k])) throw new AccountLifecycleValidation('Select the affected document versions.');
            return $p[$k];
        };
        $bool = static function(string $k) use ($p): bool {
            if (!isset($p[$k]) || !is_bool($p[$k])) throw new AccountLifecycleValidation('Choose whether an Amendment Form is required.');
            return $p[$k];
        };
        // Authoritative fields are always derived; never accepted from the client.
        foreach (['actor_user_id','stage_key','resolved_by_user_id','opened_by_user_id','submission_holder_id'] as $field) {
            if (array_key_exists($field,$p)) throw new AccountLifecycleValidation('Authoritative workflow fields cannot be supplied.');
        }
        json_out(match ($action) {
            'v14_correction_request' => ierb_v14_request_correction($pdo,$user,$int('studentId'),$int('revision'),$str('reason'),$ids('documentIds'),(bool)($p['suggestAmendment'] ?? false),$str('requestKey')),
            'v14_correction_decide' => ierb_v14_decide_correction_request($pdo,$user,$int('studentId'),$int('revision'),$int('requestEventId'),$str('decision'),$str('reason'),
                ($p['decision'] ?? '') === 'approve' ? $bool('requiresAmendment') : false,$ids('documentIds',true),$str('requestKey')),
            'v14_correction_open' => ierb_v14_open_correction($pdo,$user,$int('studentId'),$int('revision'),$str('reason'),$ids('documentIds'),$bool('requiresAmendment'),$str('requestKey')),
            'v14_correction_resolve' => ierb_v14_resolve_correction($pdo,$user,$int('studentId'),$int('revision'),$str('reason'),$str('requestKey')),
            'v14_enroll' => ierb_v14_enroll($pdo,$user,$int('studentId'),$str('stage'),$str('reason'),$str('evidence'),$str('requestKey')),
            'v14_project_preview' => (function() use ($pdo,$user,$p) { ierb_v14_gate($pdo); return research_project_preview($pdo,$user,is_array($p['studentIds'] ?? null) ? $p['studentIds'] : []); })(),
            'v14_project_reconcile' => research_project_reconcile($pdo,$user,$str('title'),$int('holderId'),$int('adviserId'),is_array($p['mapping'] ?? null) ? $p['mapping'] : [],$str('reason'),$str('historicalCompletionReference',true)),
            'v14_project_rename' => research_project_rename($pdo,$user,$int('projectId'),$int('revision'),$str('title'),$str('reason')),
            'v14_project_adviser' => research_project_reassign_adviser($pdo,$user,$int('projectId'),$int('revision'),$int('adviserId'),$str('reason')),
            'v14_project_pi' => research_project_designate_pi($pdo,$user,$int('projectId'),$int('revision'),$int('studentId'),$str('reason')),
        });
    } catch (Throwable $e) {
        $status = $e instanceof DocumentWorkflowError ? $e->status : lifecycle_error_status($e);
        if ($status >= 500) log_api_error('ierb_v14_operation', get_class($e));
        json_out(['ok'=>false,'message'=>$status < 500 ? $e->getMessage() : 'The V14 operation could not be completed. Refresh and review the current state before retrying.'], $status);
    }
}

/** Advisers only see and annotate their own advisees; admins can do both for anyone. */
function adviser_may_access_student(PDO $pdo, array $user, int $studentId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    $q = $pdo->prepare('SELECT 1 FROM students s JOIN advisers a ON a.id = s.adviser_id WHERE s.id = :id AND a.email = :e AND s.archived_at IS NULL');
    $q->execute([':id' => $studentId, ':e' => $user['email']]);
    return (bool)$q->fetchColumn();
}

function ierb_row(array $r, array $docCounts = [], array $earlierReturns = []): array
{
    $project=research_project_for_student(db(),(int)$r['id']);
    $assignmentState=null;
    if (research_onboarding_available(db())) {
        $assignment=db()->prepare('SELECT state FROM research_onboarding_assignments WHERE student_id=?');
        $assignment->execute([(int)$r['id']]);
        $assignmentState=$assignment->fetchColumn() ?: null;
    }
    return [
        'id' => (int)$r['id'],
        'projectId' => $project ? (int)$project['id'] : null,
        'researchAssignmentState' => $assignmentState,
        'studentId' => $r['student_id'],
        'name' => $r['full_name'],
        'email' => $r['email'],
        'groupId' => $project['public_identifier'] ?? $r['research_group'],
        'course' => $r['course'],
        'academicUnitKey' => $r['academic_unit_key'] ?? null,
        'programKey' => $r['program_key'] ?? null,
        'yearLevel' => $r['year_level'] ?? null,
        'academicYear' => $r['academic_year'] ?? null,
        'research' => $project['research_title'] ?? $r['research_title'],
        'progressVersion' => (string)($r['progress_version'] ?? '0'),
        'earlierStageReturns' => $earlierReturns[(int)$r['id']] ?? [],
        'stage' => $r['stage'],
        'stageLabel' => stage_label($r['stage']),
        'status' => $r['status'],
        'requirements' => $r['requirements'],
        'protocolCode' => $project && research_onboarding_available(db())?research_project_protocol(db(),(int)$project['id']):($r['protocol_code'] ?? null),
        'isPrincipalInvestigator' => !empty($r['is_principal_investigator']),
        'lastSubmissionDate' => $r['verified_submission'] ?? null,
        'progress' => stage_progress_percent($r['stage']),
        'v14' => ierb_v14_read_summary(db(), (int)$r['id']),
        'adviser' => $r['adviser_name'] ?? null,
        // Current document versions by workflow state (see workflow.php)
        'docs' => $docCounts[(int)$r['id']] ?? empty_doc_counts(),
    ];
}

/** One validated filter/order definition for the current list and complete CSV dataset. */
function ierb_apply_current_filters(string &$scope, array &$params, array $query, array $filterOptions): string
{
    require_once __DIR__.'/includes/ierb_monitor.php';
    $holder=research_projects_available(db())?'COALESCE((SELECT p.submission_holder_id FROM research_project_members m JOIN research_projects p ON p.id=m.project_id WHERE m.student_id=s.id),s.id)':'s.id';
    $scope=str_replace(' WHERE ', ' LEFT JOIN ('.monitor_formal_sql().') formal_dates ON formal_dates.student_id='.$holder.' WHERE ', $scope);
    prism_apply_student_filters($scope, $params, $query, $filterOptions);
    $q = prism_record_search($query);
    if ($q !== '') $scope .= ' AND ' . prism_search_clause(['s.protocol_code', 's.student_id', 's.full_name', 's.email', 's.research_title', 's.research_group', 's.course', 'f.full_name'], $q, $params);
    // Stage sequence has Completed last, rather than alphabetically first.
    $stageColumn = prism_student_stage_column(db());
    $stageOrder = "CASE $stageColumn WHEN 'Stage 1' THEN 0 WHEN 'initial_submission' THEN 0 WHEN 'Stage 2' THEN 1 WHEN 'resubmission_amendment' THEN 1 WHEN 'Stage 3' THEN 2 WHEN 'Stage 4' THEN 3 WHEN 'approval_data_gathering' THEN 3 WHEN 'Stage 5' THEN 4 WHEN 'Completed' THEN 5 WHEN 'ierb_completed' THEN 5 ELSE 0 END";
    $sort = $query['sort'] ?? '';
    $date=ierb_v14_schema_available(db())?'IF(EXISTS(SELECT 1 FROM ierb_workflows w WHERE w.student_id='.$holder.'),formal_dates.latest_verified,formal_dates.latest_submission)':'formal_dates.latest_submission';
    $by=$query['sortBy']??'';
    if (($by==='' && !in_array($sort,['high-to-low','low-to-high'],true)) || $by==='submission') return "$date IS NULL ASC, $date ".(($query['direction']??'DESC')==='ASC'?'ASC':'DESC').', s.id ASC';
    if ($by==='title') return 's.research_title '.(($query['direction']??'ASC')==='DESC'?'DESC':'ASC').', s.id ASC';
    return in_array($sort, ['high-to-low', 'low-to-high'], true) ? $stageOrder . ($sort === 'high-to-low' ? ' DESC' : ' ASC') . ', s.full_name ASC, s.id ASC' : prism_record_order($query, ['name'=>'s.full_name','studentId'=>'s.student_id','stage'=>$stageOrder,'status'=>prism_student_status_column(db()),'academicYear'=>'s.academic_year','group'=>'s.research_group'], 'name', 's.id ASC');
}

/** Plain-language description of a stage/status change, e.g. "Stage: A -> B; Status: On Track -> Delayed". */
function describe_progress_change(array $old, string $newStage, string $newStatus): string
{
    $parts = [];
    if ($old['stage'] !== $newStage) {
        $parts[] = 'Stage: ' . stage_label($old['stage']) . ' (' . $old['stage'] . ') -> ' . stage_label($newStage) . ' (' . $newStage . ')';
    }
    if ($old['status'] !== $newStatus) {
        $parts[] = 'Status: ' . $old['status'] . ' -> ' . $newStatus;
    }
    return implode('; ', $parts);
}

// ---------------------------------------------------------------------
// list (search + filters: ?q=&stage=&status=)
// ---------------------------------------------------------------------
if ($action === 'list') {
    [$scope, $params] = prism_operational_student_scope($user);
    $legacyScope=$scope;
    if (ierb_v14_schema_available($pdo)) $legacyScope.=' AND NOT EXISTS (SELECT 1 FROM ierb_workflows w WHERE w.student_id=s.id)';
    if (research_projects_available($pdo)) $legacyScope.=' AND NOT EXISTS (SELECT 1 FROM research_project_members m WHERE m.student_id=s.id)';
    if (($_GET['legacyOnly']??null)==='1' && ierb_v14_http_operations_enabled()) $scope=$legacyScope;
    $summary = $pdo->prepare('SELECT s.stage, s.status, COUNT(*) AS c ' . $legacyScope . ' GROUP BY s.stage, s.status');
    $summary->execute($params);
    $overview = $summary->fetchAll();
    $courses = $pdo->prepare('SELECT DISTINCT s.course ' . $scope . ' ORDER BY s.course');
    $courses->execute($params);
    $courseOptions = $courses->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions = prism_student_filter_options($pdo, $scope, $params);
    $order = ierb_apply_current_filters($scope, $params, $_GET, $filterOptions);
    // Dashboard categories retain the established stage mapping, including Stage 3+4.
    $dashboardStages = ['initial'=>['Stage 1'],'review'=>['Stage 2'],'revision'=>['Stage 3','Stage 4'],'approved'=>['Completed']];
    $dashboardStage = $_GET['dashboardStage'] ?? '';
    if (!is_string($dashboardStage) || ($dashboardStage !== '' && !isset($dashboardStages[$dashboardStage]))) json_out(['ok'=>false,'message'=>'Invalid dashboard stage filter.'],422);
    if ($dashboardStage !== '') {
        $keys=[];
        foreach ($dashboardStages[$dashboardStage] as $i=>$stage) { $key=':dashboardStage'.$i; $keys[]=$key; $params[$key]=$stage; }
        $scope .= ' AND s.stage IN ('.implode(',',$keys).')';
    }
    $page = prism_page_query($pdo, 'SELECT s.*, f.full_name AS adviser_name, (SELECT COALESCE(MAX(h.id),0) FROM ierb_history h WHERE h.student_id=s.id) AS progress_version, formal_dates.latest_submission AS verified_submission', $scope, $params, $order, $_GET);
    $rows = $page['rows']; unset($page['rows']);
    foreach ($rows as &$row) {
        $summary=ierb_v14_read_summary($pdo,(int)$row['id']);
        if ($summary) {
            $holder=research_project_for_student($pdo,(int)$row['id']);
            $q=$pdo->prepare('SELECT latest_verified FROM ('.monitor_formal_sql().') dates WHERE student_id=?'); $q->execute([$holder['submission_holder_id']??$row['id']]); $row['verified_submission']=$q->fetchColumn()?:null;
        }
    } unset($row);
    $counts = student_document_counts($pdo, array_column($rows, 'id'));
    $earlierReturns = ierb_earlier_stage_returns($pdo, $rows);
    json_out(['ok' => true, 'records' => array_map(fn($r) => ierb_row($r, $counts, $earlierReturns), $rows), 'overview' => $overview, 'courses' => $courseOptions, 'filterOptions'=>$filterOptions] + $page);
}

if ($action === 'history') {
    $studentId = (int)($_GET['studentId'] ?? 0);
    if ($user['role'] === 'student') {
        $own = $pdo->prepare('SELECT id FROM students WHERE email = :e');
        $own->execute([':e' => $user['email']]);
        $ownRow = $own->fetch();
        if (!$ownRow || (int)$ownRow['id'] !== $studentId) {
            json_out(['ok' => false, 'message' => 'Not authorized.'], 403);
        }
    } elseif (!adviser_may_access_student($pdo, $user, $studentId)) {
        json_out(['ok' => false, 'message' => 'This student is assigned to another adviser.'], 403);
    }
    if (research_project_for_student($pdo,$studentId)) {
        // Combine retained observations and the member's public canonical timeline.
        $private=$user['role']==='student' ? 'NULL AS note,NULL AS actor' : 'h.note,h.actor';
        $legacy='SELECT h.stage,h.status,h.submission_date,h.created_at,'.$private.' FROM ierb_history h WHERE h.student_id=:legacy_id';
        if ($user['role']==='student') $legacy.=' AND NOT EXISTS (SELECT 1 FROM ierb_history previous WHERE previous.id=(SELECT MAX(prior.id) FROM ierb_history prior WHERE prior.student_id=h.student_id AND prior.id<h.id) AND previous.stage <=> h.stage AND previous.status <=> h.status)';
        $canonical="SELECT mh.to_stage AS stage,'Project progress' AS status,NULL AS submission_date,mh.created_at,
            'Canonical research project progress' AS note,'Project workflow' AS actor FROM research_project_member_history mh
            WHERE mh.student_id=:member_id AND (mh.from_stage IS NULL OR mh.from_stage<>mh.to_stage)";
        $scope='FROM ('.$legacy.' UNION ALL '.$canonical.') timeline WHERE 1=1';
        $params=[':legacy_id'=>$studentId,':member_id'=>$studentId];
        $search=prism_record_search($_GET);
        if ($search!=='') $scope.=' AND '.prism_search_clause(['timeline.stage','timeline.status','timeline.created_at'],$search,$params);
        $page=prism_page_query($pdo,'SELECT *',$scope,$params,'created_at DESC,stage ASC',$_GET);
        $rows=$page['rows']; unset($page['rows']);
        foreach ($rows as &$row) $row['stageLabel']=IerbStage::LABELS[$row['stage']] ?? stage_label($row['stage']);
        unset($row);
        json_out(['ok'=>true,'history'=>$rows]+$page);
    }
    // Historical free-text annotations have no reliable Student-facing classification.
    // Keep official progress/dates; reviewer instructions remain in owned document feedback.
    $select = 'SELECT *';
    $scope = 'FROM ierb_history WHERE student_id = :id';
    $order = 'created_at DESC, id DESC';
    if ($user['role'] === 'student') {
        $select = 'SELECT h.stage, h.status, h.submission_date, h.created_at';
        // Only the initial official observation and actual stage/status changes are public.
        // Note-only/repeated snapshots are filtered before counts and pagination.
        $scope = 'FROM ierb_history h WHERE h.student_id = :id AND NOT EXISTS (
            SELECT 1 FROM ierb_history previous WHERE previous.id = (
                SELECT MAX(prior.id) FROM ierb_history prior
                WHERE prior.student_id = h.student_id AND prior.id < h.id
            ) AND previous.stage <=> h.stage AND previous.status <=> h.status
        )';
        $order = 'h.created_at DESC, h.id DESC';
    }
    $params = [':id' => $studentId];
    $search = prism_record_search($_GET);
    if ($search !== '') {
        // Search the same public projection before counting or slicing Student history.
        $columns = $user['role'] === 'student'
            ? ['h.stage', 'h.status', 'h.submission_date', 'h.created_at']
            : ['stage', 'status', 'submission_date', 'created_at', 'note', 'actor', 'requirements'];
        $scope .= ' AND ' . prism_search_clause($columns, $search, $params);
    }
    $page = prism_page_query($pdo, $select, $scope, $params, $order, $_GET);
    $rows = $page['rows']; unset($page['rows']);
    if ($user['role'] === 'student') $rows = array_map(static fn($row) => [
        'stage' => $row['stage'], 'status' => $row['status'],
        'submission_date' => $row['submission_date'], 'created_at' => $row['created_at'],
        'action' => 'progress_recorded', 'note' => null, 'feedback' => null,
    ], $rows);
    json_out(['ok' => true, 'history' => $rows] + $page);
}

if ($action === 'stage_distribution') {
    [$scope, $params] = prism_operational_student_scope($user);
    $stmt = $pdo->prepare('SELECT s.stage, COUNT(*) AS c ' . $scope . ' GROUP BY s.stage');
    $stmt->execute($params);
    $distribution = array_column($stmt->fetchAll(), 'c', 'stage');
    $rows = [];
    foreach (STAGE_SEQUENCE as $stage) {
        $rows[] = ['stage' => $stage, 'c' => (int)($distribution[$stage] ?? 0)];
    }
    json_out(['ok' => true, 'distribution' => $rows]);
}

// ---------------------------------------------------------------------
// needs_attention: students who need someone to act (admin: all, adviser: own students)
// ---------------------------------------------------------------------
if ($action === 'needs_attention') {
    api_require_login(['admin', 'adviser']);
    $all = students_needing_attention($pdo, $user);
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
    json_out(['ok' => true, 'total' => count($all), 'students' => array_slice($all, 0, $limit),
        'thresholds' => ['reviewDays' => ATTENTION_REVIEW_DAYS, 'unsubmittedDays' => ATTENTION_UNSUBMITTED_DAYS,
                         'overdueDays' => ATTENTION_OVERDUE_DAYS]]);
}

// ---------------------------------------------------------------------
// export_csv: the student progress list (same scope + filters as list)
// ---------------------------------------------------------------------
if ($action === 'export_csv') {
    api_require_login(['admin', 'adviser']);
    [$scope, $params] = prism_operational_student_scope($user);
    $filterOptions = prism_student_filter_options($pdo, $scope, $params);
    $order = ierb_apply_current_filters($scope, $params, $_GET, $filterOptions);
    $stmt = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name, formal_dates.latest_submission AS verified_submission, formal_dates.latest_verified AS verified_v14_submission ' . $scope . ' ORDER BY ' . $order);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $counts = student_document_counts($pdo);
    $attention = [];
    foreach (students_needing_attention($pdo, $user) as $a) {
        $attention[$a['id']] = implode(' | ', array_column($a['reasons'], 'label'));
    }

    $filename = 'prism_student_progress_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    $put = function (array $cells) use ($out) {
        fputcsv($out, array_map('csv_safe', $cells), ',', '"', '');
    };
    $put(['Research Title', 'Protocol Code', 'Student ID', 'Student Name', 'Email', 'Course', 'Internal Research Group ID',
        'Adviser', 'Stage', 'Stage Name', 'Status', 'Progress (%)', 'Pending Requirements', 'Latest Audited Formal Submission (Asia/Manila)', 'Academic Year',
        'Docs Pending Adviser Review', 'Docs Ready for RPMS Submission', 'Docs Submitted to RPMS', 'Needs Attention']);
    foreach ($rows as $r) {
        $c = $counts[(int)$r['id']] ?? empty_doc_counts();
        $row=ierb_row($r,$counts);
        $put([
            $row['research'], $row['protocolCode'] ?? 'Not yet assigned', $r['student_id'], $r['full_name'], $r['email'], $r['course'],
            $row['groupId'], $r['adviser_name'] ?? '', $row['v14']['stage']??$r['stage'], $row['v14']['stageLabel']??stage_label($r['stage']),
            $r['status'], $row['v14']['progress']??stage_progress_percent($r['stage']), $r['requirements'], $row['v14']?($r['verified_v14_submission']??null):$row['lastSubmissionDate'], $r['academic_year'],
            $c['pendingReview'], $c['readyForRpms'], $c['submittedToRpms'], $attention[(int)$r['id']] ?? '',
        ]);
    }
    fclose($out);
    audit_log($user, 'progress_exported', ['entity_type' => 'progress_list', 'details' => 'CSV export, ' . count($rows) . ' rows']);
    exit;
}

// Everything below is RPMS-only (or the assigned research adviser, read/annotate only).
$data = json_body();

// Explicit stage/status decisions share one transactional, reasoned RPMS gate.
if (in_array($action,['advance','correct','status','override'],true)) {
    api_require_login('admin');
    try {
        // Legacy override callers receive the same correction safeguards; there is no bypass.
        json_out(ierb_progress_decide($pdo,$user,$action==='override'?'correct':$action,$data));
    } catch (Throwable $e) {
        if ($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden
            || $e instanceof AccountLifecycleValidation || $e instanceof AccountLifecycleNotFound) {
            json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        }
        log_api_error('ierb_decision',$e->getMessage());
        json_out(['ok'=>false,'message'=>'The progress decision could not be saved. Refresh to check the current state before retrying.'],500);
    }
}

// ---------------------------------------------------------------------
// save (admin only)
// ---------------------------------------------------------------------
if ($action === 'save') {
    if ((int)($data['id']??0)===0 && research_onboarding_available($pdo)) json_out(['ok'=>false,'message'=>'Create or invite this Student in Student Records to select an approved research assignment.','redirect'=>'admin_students.php'],422);
    api_require_login('admin');
    foreach (['stage','status'] as $field) if (isset($data[$field]) && !is_string($data[$field])) json_out(['ok'=>false,'message'=>'Invalid progress value.'],422);
    if (isset($data['reason']) && !is_string($data['reason'])) json_out(['ok'=>false,'message'=>'Invalid progress change reason.'],422);
    $id = (int)($data['id'] ?? 0);
    $studentIdCode = trim((string)($data['studentId'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $groupId = trim((string)($data['groupId'] ?? ''));
    $research = trim((string)($data['research'] ?? ''));
    $stage = trim((string)($data['stage'] ?? 'Stage 1'));
    $status = trim((string)($data['status'] ?? 'On Track'));
    $requirements = trim((string)($data['requirements'] ?? ''));
    $submissionDate = trim((string)($data['submissionDate'] ?? '')) ?: null;
    $reason = trim((string)($data['reason'] ?? ''));

    if ($studentIdCode === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Student ID, name, and a valid email are required.'], 422);
    }
    if (!is_allowed_email_domain($email)) {
        json_out(['ok' => false, 'message' => 'Only ' . allowed_email_domains_hint() . ' email addresses are allowed.'], 422);
    }
    if (mb_strlen($studentIdCode) > 100 || mb_strlen($name) > 190 || mb_strlen($groupId) > 190
        || mb_strlen($research) > 255 || mb_strlen($requirements) > 255) {
        json_out(['ok' => false, 'message' => 'One or more fields are too long. Please shorten the entry and try again.'], 422);
    }
    if ($submissionDate !== null) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $submissionDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $submissionDate || $submissionDate > date('Y-m-d')) {
            json_out(['ok' => false, 'message' => 'Latest submission date must be a valid date that is not in the future.'], 422);
        }
    }

    $validStages = STAGE_SEQUENCE;
    $validStatuses = ['On Track', 'Pending', 'Delayed'];
    if (!in_array($stage, $validStages, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }
    if (!in_array($status, $validStatuses, true)) {
        json_out(['ok' => false, 'message' => 'Invalid status.'], 422);
    }

    // Changing stage/status of an existing record is an Admin Override: always logged.
    $override = false;
    $change = '';
    $previousEmail = '';
    $newLoginUserId = null;
    $newLoginTempPassword = null;
    $setupDelivery = null;
    try {
        $pdo->beginTransaction();
        if ($id===0) {
            current_workflow_actor($pdo,$user);
            if ($stage!=='Stage 1') throw new AccountLifecycleValidation('New records begin at Stage 1. Use a reasoned IERB correction after creation if needed.');
            onboarding_student_id($studentIdCode);
            if ($research === '') throw new AccountLifecycleValidation('Research title is required for a new IERB entry.');
            $research = research_project_title($research);
            onboarding_email_available($pdo,$email);
            onboarding_id_available($pdo,$studentIdCode,'student',0,0);
        }
        if ($id > 0) {
            $old = $pdo->prepare('SELECT * FROM students WHERE id = :id FOR UPDATE');
            $old->execute([':id' => $id]);
            $oldRow = $old->fetch();
            if (!$oldRow) {
                $pdo->rollBack();
                json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
            }
            if (array_key_exists('profile_completed_at',$oldRow) && $oldRow['profile_completed_at']===null) throw new AccountLifecycleConflict('Pending identities must complete secure onboarding before record editing.');
            onboarding_student_id($studentIdCode, (string)$oldRow['student_id']);
            current_workflow_student($pdo,$id,$user);
            // Research Title (B7-F09): never erased; an unchanged blank legacy title stays as recorded pending RPMS
            // decision D2; canonical project titles change only through the audited project rename.
            $titleBefore = trim((string)$oldRow['research_title']);
            if ($research === '' && $titleBefore !== '') throw new AccountLifecycleValidation('A Research Title is required and an existing title cannot be erased.');
            if ($research !== '' && $research !== $titleBefore) $research = research_project_title($research);
            $canonical = research_project_for_student($pdo,$id,true);
            if ($canonical && $research !== $canonical['research_title']) {
                throw new AccountLifecycleConflict('Use Edit official group information in Student Records to change this shared research title.');
            }
            if (!$canonical && $research !== $titleBefore && research_onboarding_available($pdo)) {
                $pending=$pdo->prepare("SELECT student_id FROM research_onboarding_assignments WHERE student_id=? AND state<>'activated' FOR UPDATE");
                $pending->execute([$id]);
                if ($pending->fetchColumn()) throw new AccountLifecycleConflict('Use Review research assignment in Student Records to change approved pending research information.');
            }
            if ($research !== $titleBefore) {
                document_workflow_audit($pdo,$user,'research_title_changed',['entity_type'=>'student','entity_id'=>$id,'student_id'=>$id,
                    'before'=>mb_substr($titleBefore,0,255),'after'=>mb_substr($research,0,255),'details'=>'IERB record edit; Research Title changed']);
            }
            $stage=$data['stage']??$oldRow['stage'];
            $status=$data['status']??$oldRow['status'];
            if ($stage!==$oldRow['stage'] || $status!==$oldRow['status']) {
                throw new AccountLifecycleValidation('Use Advance to Next Stage, Correct IERB Stage or Update Progress Status in IERB Progress. Record editing cannot change official progress.');
            }
            purge_require_no_pending($pdo,'student',$id);
            $identityBefore=lifecycle_identity_capture($pdo,$oldRow,'student');
            try {
                $academic = academic_validate($data, $oldRow);
                if ($canonical && ($academic['program_key']!==$oldRow['program_key'] || $academic['academic_year']!==$oldRow['academic_year'])) throw new InvalidArgumentException('A verified member must keep the research group program and academic year. Request an audited membership review.');
                $groupId = research_group_assignment($pdo, $user, $data, $academic, $oldRow);
            } catch (\InvalidArgumentException $e) {
                $pdo->rollBack();
                json_out(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $previousEmail = (string)$oldRow['email'];
            if ($oldRow['stage'] !== $stage || $oldRow['status'] !== $status) {
                if (REQUIRE_OVERRIDE_REASON_ON_SAVE && !override_reason_valid($reason)) {
                    $pdo->rollBack();
                    json_out(['ok' => false, 'requiresReason' => true,
                        'message' => 'Changing the stage or status is an Admin Override. ' . override_reason_message()], 422);
                }
                $override = true;
                $change = describe_progress_change($oldRow, $stage, $status);
            }
            $pdo->prepare('UPDATE students SET student_id=:sid, full_name=:name, email=:email,
                research_group=:grp, course=:course, academic_unit_key=:academic_unit, program_key=:program,
                year_level=:year_level, academic_year=:academic_year, research_title=:research,
                requirements=:req, last_submission_date=:sub, updated_at=NOW() WHERE id=:id')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $academic['course'],
                    ':academic_unit' => $academic['academic_unit_key'], ':program' => $academic['program_key'],
                    ':year_level' => $academic['year_level'], ':academic_year' => $academic['academic_year'], ':research' => $research,
                    ':req' => $requirements, ':sub' => $submissionDate, ':id' => $id]);
            sync_student_login_identity($pdo, $previousEmail, $studentIdCode, $name, $email);
        } else {
            try {
                $academic = academic_validate($data, null);
                $groupId = research_group_assignment($pdo, $user, $data, $academic, null);
                if ($groupId === '') {
                    throw new \InvalidArgumentException('Choose an existing compatible research group or Create New Group for a new IERB record.');
                }
            } catch (\InvalidArgumentException $e) {
                $pdo->rollBack();
                json_out(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_group, course,
                academic_unit_key, program_key, year_level, academic_year,
                research_title, stage, status, requirements, last_submission_date)
                VALUES (:sid,:name,:email,:grp,:course,:academic_unit,:program,:year_level,:academic_year,
                    :research,:stage,:status,:req,:sub)')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $academic['course'],
                    ':academic_unit' => $academic['academic_unit_key'], ':program' => $academic['program_key'],
                    ':year_level' => $academic['year_level'], ':academic_year' => $academic['academic_year'], ':research' => $research, ':stage' => $stage, ':status' => $status,
                    ':req' => $requirements, ':sub' => $submissionDate]);
            $id = (int)$pdo->lastInsertId();

            $collision = $pdo->prepare('SELECT id, role, status FROM users WHERE email = :e OR username = :u LIMIT 1');
            $collision->execute([':e' => $email, ':u' => $studentIdCode]);
            $existingLogin = $collision->fetch();
            if ($existingLogin && ($existingLogin['role'] ?? '') !== 'student') {
                throw new RuntimeException('The email or student ID already belongs to a different account type.');
            }
            if (!$existingLogin) {
                $tempPassword = generate_temporary_password();
                $newLoginTempPassword = $tempPassword;
                $pdo->prepare('INSERT INTO users (username,password_hash,role,full_name,email,ref_id,must_change_password)
                    VALUES (:u,:p,"student",:n,:e,:ref,1)')
                    ->execute([':u' => $studentIdCode, ':p' => prism_password_hash($tempPassword),
                        ':n' => $name, ':e' => $email, ':ref' => $studentIdCode]);
                $newLoginUserId = (int)$pdo->lastInsertId();
            } else {
                $wasInactive = strcasecmp((string)($existingLogin['status'] ?? ''), 'Active') !== 0;
                if ($wasInactive) {
                    $tempPassword = generate_temporary_password();
                    $newLoginTempPassword = $tempPassword;
                    $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status='Active',
                            password_hash=:p, must_change_password=1
                        WHERE id=:id AND role='student'")
                        ->execute([':u' => $studentIdCode, ':n' => $name, ':e' => $email, ':ref' => $studentIdCode,
                            ':p' => prism_password_hash($tempPassword), ':id' => $existingLogin['id']]);
                    $newLoginUserId = (int)$existingLogin['id'];
                } else {
                    $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref
                        WHERE id=:id AND role='student'")
                        ->execute([':u' => $studentIdCode, ':n' => $name, ':e' => $email,
                            ':ref' => $studentIdCode, ':id' => $existingLogin['id']]);
                }
            }
        }

        $note = 'IERB entry saved by RPMS.'
            . ($override ? ' ADMIN OVERRIDE - ' . $change . '. Reason: ' . ($reason !== '' ? $reason : '(none recorded)') : '');
        $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, submission_date, actor)
            VALUES (:sid,:stage,:status,:note,:req,:sub,:actor)')->execute([
            ':sid' => $id, ':stage' => $stage, ':status' => $status, ':note' => $note,
            ':req' => $requirements, ':sub' => $submissionDate,
            ':actor' => document_attribution($user['full_name'], $override ? ' (Admin Override)' : ''),
        ]);
        if (!isset($identityBefore)) $pdo->prepare('UPDATE students SET profile_completed_at=NOW() WHERE id=?')->execute([$id]);
        academic_year_assignment_audit($pdo,$user,$id,$oldRow['academic_year']??null,$academic['academic_year']);
        $identityRow=$pdo->prepare('SELECT * FROM students WHERE id=:id FOR UPDATE');
        $identityRow->execute([':id'=>$id]);
        lifecycle_identity_persist($pdo,$user,'student',$identityBefore??null,lifecycle_identity_capture($pdo,$identityRow->fetch(),'student'));
        if ($override) {
            audit_override_atomic($pdo, $user, 'admin_override_student_progress', [
                'entity_type' => 'student', 'entity_id' => $id, 'student_id' => $id, 'override' => true,
                'reason' => $reason !== '' ? $reason : '(none recorded)',
                'details' => $change, 'before' => $oldRow['stage'] . ' / ' . $oldRow['status'],
                'after' => "$stage / $status",
            ]);
        }
        $notificationIds=$override ? workflow_queue_notices($pdo,$user,$id,
            ['subject'=>'PRISM IERB Progress Updated','message'=>"RPMS updated your IERB record. Current stage: " . stage_label($stage) . " ($stage). Status: $status."]) : [];
        $pdo->commit();
        research_group_release($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        research_group_release($pdo);
        if ($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden || $e instanceof AccountLifecycleValidation) json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            json_out(['ok' => false, 'message' => 'That student ID or email is already in use.'], 422);
        }
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'different account type')) {
            json_out(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        log_api_error('ierb_save', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The IERB record could not be saved. Please try again.'], 500);
    }

    if ($newLoginUserId) {
        $setupDelivery = send_account_setup_email($pdo, $newLoginUserId, $email, $name);
    }

    if (!$override) audit_log($user, 'ierb_saved', [
        'entity_type' => 'student', 'entity_id' => $id, 'student_id' => $id, 'override' => $override,
        'reason' => $override ? ($reason !== '' ? $reason : '(none recorded)') : null,
        'details' => $override ? $change : "student_id=$studentIdCode stage=$stage status=$status",
        'after' => "$stage / $status",
    ]);
    document_workflow_deliver($pdo,$notificationIds);

    $response = ['ok' => true, 'id' => $id, 'override' => $override,
        'message' => "IERB record for $name saved." . ($override ? ' The stage/status change was logged as an Admin Override.' : '')];
    if ($newLoginUserId) {
        $response['accountCreated'] = true;
        $response = array_merge($response, account_setup_response_fields($setupDelivery, $newLoginTempPassword));
    }
    json_out($response);
}

if ($action === 'note') {
    if (isset($data['studentId']) && !is_int($data['studentId']) && !is_string($data['studentId'])) json_out(['ok'=>false,'message'=>'Invalid Student reference.'],422);
    api_require_login(['admin', 'adviser']);
    if (isset($data['note']) && !is_string($data['note'])) json_out(['ok'=>false,'message'=>'Invalid note.'],422);
    $studentId = (int)($data['studentId'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    if ($studentId <= 0 || $note === '') {
        json_out(['ok' => false, 'message' => 'Write a note before saving.'], 422);
    }
    if (mb_strlen($note) > 500) {
        json_out(['ok' => false, 'message' => 'Notes must be 500 characters or fewer.'], 422);
    }
    try {
        $pdo->beginTransaction();
        $current = $pdo->prepare('SELECT * FROM students WHERE id = :id FOR UPDATE');
        $current->execute([':id' => $studentId]);
        $row = $current->fetch();
        if (!$row) throw new AccountLifecycleNotFound('Student not found.');
        current_workflow_student($pdo,$studentId,$user);
        if (!empty($row['archived_at']) || empty($row['profile_completed_at'])) {
            throw new AccountLifecycleConflict('The Student record is unavailable for current annotations.');
        }
        purge_require_no_pending($pdo,'student',$studentId);
        $actor = $pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
        $actor->execute([$user['id']]); $live = $actor->fetch();
        if (!$live || $live['role'] !== $user['role'] || $live['status'] !== 'Active'
            || $live['email'] !== $user['email'] || !hash_equals((string)$live['password_hash'],(string)$user['password_hash'])
            || !empty($live['must_change_password']) || legal_outstanding($pdo,(int)$live['id'])) {
            throw new AccountLifecycleForbidden('Your account changed. Sign in again before continuing.');
        }
        if ($live['role'] === 'adviser') {
            $assigned = $pdo->prepare('SELECT * FROM advisers WHERE id=? FOR UPDATE');
            $assigned->execute([$row['adviser_id']]); $adviser = $assigned->fetch();
            if (!$adviser || !empty($adviser['archived_at']) || $adviser['status'] !== 'Active'
                || empty($adviser['profile_completed_at'])) throw new AccountLifecycleForbidden('An active assigned Adviser is required.');
            $login = lifecycle_resolve_login($pdo,$adviser,'adviser');
            if (!$login || (int)$login['id'] !== (int)$live['id'] || $login['status'] !== 'Active') {
                throw new AccountLifecycleForbidden('This student is assigned to another adviser.');
            }
            purge_require_no_pending($pdo,'adviser',(int)$adviser['id']);
        }
        record_history($pdo, $studentId, $row['stage'], $row['status'], $note, null,
            document_attribution($live['full_name'],' (' . ucfirst($live['role']) . ')'));
        document_workflow_audit($pdo,$live,'ierb_note_added',[
            'entity_type'=>'student','entity_id'=>$studentId,'student_id'=>$studentId,
            'details'=>mb_substr($note,0,200),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof AccountLifecycleForbidden || $e instanceof AccountLifecycleConflict
            || $e instanceof AccountLifecycleNotFound) json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        log_api_error('ierb_note','The annotation could not be persisted.');
        json_out(['ok'=>false,'message'=>'The note could not be saved. Refresh to check the current state before retrying.'],500);
    }
    json_out(['ok' => true, 'message' => 'Note saved to the progress history.']);
}

if ($action === 'delete') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? 0);
    try {
        archive_student($pdo, $user, $id);
    } catch (Throwable $e) {
        log_api_error('student_archive', 'The archive operation could not be completed.');
        json_out(['ok' => false, 'message' => $e instanceof AccountLifecycleConflict ? $e->getMessage() : ($e instanceof AccountLifecycleNotFound || $e instanceof \InvalidArgumentException ? 'Student record not found.' : 'The student could not be archived.')], $e instanceof AccountLifecycleConflict ? 409 : ($e instanceof AccountLifecycleNotFound || $e instanceof \InvalidArgumentException ? 404 : 500));
    }
    json_out(['ok' => true, 'message' => 'Student archived and login deactivated. All historical records are retained.']);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
