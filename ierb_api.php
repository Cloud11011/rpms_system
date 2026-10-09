<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/csv_export.php';
require_once __DIR__ . '/includes/record_filters.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/account_lifecycle.php';
require_once __DIR__ . '/includes/academic_catalog.php';
require_once __DIR__ . '/includes/research_groups.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['override', 'save', 'note', 'delete'], true)) {
    require_post_same_origin();
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

function ierb_row(array $r, array $docCounts = []): array
{
    return [
        'id' => (int)$r['id'],
        'studentId' => $r['student_id'],
        'name' => $r['full_name'],
        'email' => $r['email'],
        'groupId' => $r['research_group'],
        'course' => $r['course'],
        'academicUnitKey' => $r['academic_unit_key'] ?? null,
        'programKey' => $r['program_key'] ?? null,
        'yearLevel' => $r['year_level'] ?? null,
        'academicYear' => $r['academic_year'] ?? null,
        'research' => $r['research_title'],
        'stage' => $r['stage'],
        'stageLabel' => stage_label($r['stage']),
        'status' => $r['status'],
        'requirements' => $r['requirements'],
        'protocolCode' => $r['protocol_code'] ?? null,
        'isPrincipalInvestigator' => !empty($r['is_principal_investigator']),
        'lastSubmissionDate' => $r['last_submission_date'],
        'progress' => stage_progress_percent($r['stage']),
        'adviser' => $r['adviser_name'] ?? null,
        // Current document versions by workflow state (see workflow.php)
        'docs' => $docCounts[(int)$r['id']] ?? empty_doc_counts(),
    ];
}

/** One validated filter/order definition for the current list and complete CSV dataset. */
function ierb_apply_current_filters(string &$scope, array &$params, array $query, array $filterOptions): string
{
    prism_apply_student_filters($scope, $params, $query, $filterOptions);
    $q = prism_record_search($query);
    if ($q !== '') $scope .= ' AND ' . prism_search_clause(['s.protocol_code', 's.student_id', 's.full_name', 's.email', 's.research_title', 's.research_group', 's.course', 'f.full_name'], $q, $params);
    // Stage sequence has Completed last, rather than alphabetically first.
    $stageOrder = "CASE s.stage WHEN 'Stage 1' THEN 0 WHEN 'Stage 2' THEN 1 WHEN 'Stage 3' THEN 2 WHEN 'Stage 4' THEN 3 WHEN 'Stage 5' THEN 4 WHEN 'Completed' THEN 5 ELSE 0 END";
    $sort = $query['sort'] ?? '';
    return in_array($sort, ['high-to-low', 'low-to-high'], true) ? $stageOrder . ($sort === 'high-to-low' ? ' DESC' : ' ASC') . ', s.full_name ASC, s.id ASC' : prism_record_order($query, ['name'=>'s.full_name','studentId'=>'s.student_id','stage'=>$stageOrder,'status'=>'s.status','academicYear'=>'s.academic_year','group'=>'s.research_group'], 'name', 's.id ASC');
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
    $summary = $pdo->prepare('SELECT s.stage, s.status, COUNT(*) AS c ' . $scope . ' GROUP BY s.stage, s.status');
    $summary->execute($params);
    $overview = $summary->fetchAll();
    $courses = $pdo->prepare('SELECT DISTINCT s.course ' . $scope . ' ORDER BY s.course');
    $courses->execute($params);
    $courseOptions = $courses->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions = prism_student_filter_options($pdo, $scope, $params);
    $order = ierb_apply_current_filters($scope, $params, $_GET, $filterOptions);
    $page = prism_page_query($pdo, 'SELECT s.*, f.full_name AS adviser_name', $scope, $params, $order, $_GET);
    $rows = $page['rows']; unset($page['rows']);
    $counts = student_document_counts($pdo, array_column($rows, 'id'));
    json_out(['ok' => true, 'records' => array_map(fn($r) => ierb_row($r, $counts), $rows), 'overview' => $overview, 'courses' => $courseOptions, 'filterOptions'=>$filterOptions] + $page);
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
    $page = prism_page_query($pdo, 'SELECT *', 'FROM ierb_history WHERE student_id = :id', [':id' => $studentId], 'created_at DESC, id DESC', $_GET);
    $rows = $page['rows']; unset($page['rows']);
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
    $stmt = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name ' . $scope . ' ORDER BY ' . $order);
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
    $put(['Protocol Code', 'Student ID', 'Student Name', 'Email', 'Course', 'Research Group', 'Research Title',
        'Adviser', 'Stage', 'Stage Name', 'Status', 'Progress (%)', 'Pending Requirements', 'Last Submission Date',
        'Docs Pending Adviser Review', 'Docs Ready for RPMS Submission', 'Docs Submitted to RPMS', 'Needs Attention']);
    foreach ($rows as $r) {
        $c = $counts[(int)$r['id']] ?? empty_doc_counts();
        $put([
            $r['protocol_code'] ?? '', $r['student_id'], $r['full_name'], $r['email'], $r['course'],
            $r['research_group'], $r['research_title'], $r['adviser_name'] ?? '', $r['stage'], stage_label($r['stage']),
            $r['status'], stage_progress_percent($r['stage']), $r['requirements'], $r['last_submission_date'],
            $c['pendingReview'], $c['readyForRpms'], $c['submittedToRpms'], $attention[(int)$r['id']] ?? '',
        ]);
    }
    fclose($out);
    audit_log($user, 'progress_exported', ['entity_type' => 'progress_list', 'details' => 'CSV export, ' . count($rows) . ' rows']);
    exit;
}

// Everything below is RPMS-only (or the assigned research adviser, read/annotate only).
$data = json_body();

// ---------------------------------------------------------------------
// override (admin only): change a student's stage and/or status, always with a logged reason
// ---------------------------------------------------------------------
if ($action === 'override') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? 0);
    $newStage = trim((string)($data['stage'] ?? ''));
    $newStatus = trim((string)($data['status'] ?? ''));
    $reason = trim((string)($data['reason'] ?? ''));

    $cur = student_with_adviser($pdo, $id);
    if (!$cur) {
        json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
    }
    $newStage = $newStage !== '' ? $newStage : $cur['stage'];
    $newStatus = $newStatus !== '' ? $newStatus : $cur['status'];
    if (!in_array($newStage, STAGE_SEQUENCE, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }
    if (!in_array($newStatus, ['On Track', 'Pending', 'Delayed'], true)) {
        json_out(['ok' => false, 'message' => 'Invalid status.'], 422);
    }
    if ($newStage === $cur['stage'] && $newStatus === $cur['status']) {
        json_out(['ok' => false, 'message' => 'Nothing to override: the stage and status already have those values.'], 422);
    }
    if (!override_reason_valid($reason)) {
        json_out(['ok' => false, 'requiresReason' => true, 'message' => override_reason_message()], 422);
    }

    $change = describe_progress_change($cur, $newStage, $newStatus);
    try {
        $pdo->beginTransaction();
        $locked=$pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');$locked->execute([$id]);
        if(!$locked->fetchColumn())throw new AccountLifecycleNotFound('Student record not found.');
        purge_require_no_pending($pdo,'student',$id);
        $pdo->prepare('UPDATE students SET stage = :stage, status = :status, updated_at = NOW() WHERE id = :id')
            ->execute([':stage' => $newStage, ':status' => $newStatus, ':id' => $id]);
        record_history($pdo, $id, $newStage, $newStatus, 'ADMIN OVERRIDE - ' . $change . '. Reason: ' . $reason,
            null, $user['full_name'] . ' (Admin Override)');
        $pdo->commit();
        research_group_release($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('ierb_override', $e->getMessage());
        if($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleNotFound)json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        json_out(['ok' => false, 'message' => 'The override could not be saved. Nothing was changed.'], 500);
    }

    audit_log($user, 'admin_override_student_progress', [
        'entity_type' => 'student', 'entity_id' => $id, 'student_id' => $id, 'override' => true, 'reason' => $reason,
        'before' => $cur['stage'] . ' / ' . $cur['status'], 'after' => $newStage . ' / ' . $newStatus, 'details' => $change,
    ]);

    notify_student($pdo, $id, 'PRISM IERB Progress Updated',
        "Hello {$cur['full_name']},\n\nAn RPMS administrator updated your IERB record.\n$change\nReason: $reason\n\n- CEU Malolos RPMS / PRISM",
        "RPMS updated your IERB record. $change. Reason: $reason", 'Status Update', $user['full_name'] . ' (Admin Override)');
    notify_adviser_of_student($pdo, $id, 'Admin Override on a student record',
        "{$user['full_name']} updated {$cur['full_name']}'s IERB record. $change. Reason: $reason", 'Admin Override', $user['full_name']);

    json_out(['ok' => true, 'message' => "Admin Override saved for {$cur['full_name']}. Your name, the time and your reason were added to the audit log."]);
}

// ---------------------------------------------------------------------
// save (admin only)
// ---------------------------------------------------------------------
if ($action === 'save') {
    api_require_login('admin');
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
        if ($id > 0) {
            $old = $pdo->prepare('SELECT * FROM students WHERE id = :id FOR UPDATE');
            $old->execute([':id' => $id]);
            $oldRow = $old->fetch();
            if (!$oldRow) {
                $pdo->rollBack();
                json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
            }
            purge_require_no_pending($pdo,'student',$id);
            $identityBefore=lifecycle_identity_capture($pdo,$oldRow,'student');
            try {
                $academic = academic_validate($data, $oldRow);
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
                year_level=:year_level, academic_year=:academic_year, research_title=:research, stage=:stage, status=:status,
                requirements=:req, last_submission_date=:sub, updated_at=NOW() WHERE id=:id')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $academic['course'],
                    ':academic_unit' => $academic['academic_unit_key'], ':program' => $academic['program_key'],
                    ':year_level' => $academic['year_level'], ':academic_year' => $academic['academic_year'], ':research' => $research, ':stage' => $stage, ':status' => $status,
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
                    ->execute([':u' => $studentIdCode, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
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
                            ':p' => password_hash($tempPassword, PASSWORD_DEFAULT), ':id' => $existingLogin['id']]);
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
            ':actor' => $user['full_name'] . ($override ? ' (Admin Override)' : ''),
        ]);
        $identityRow=$pdo->prepare('SELECT * FROM students WHERE id=:id FOR UPDATE');
        $identityRow->execute([':id'=>$id]);
        lifecycle_identity_persist($pdo,$user,'student',$identityBefore??null,lifecycle_identity_capture($pdo,$identityRow->fetch(),'student'));
        $pdo->commit();
        research_group_release($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        research_group_release($pdo);
        if ($e instanceof AccountLifecycleConflict) json_out(['ok'=>false,'message'=>$e->getMessage()],409);
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

    audit_log($user, $override ? 'admin_override_student_progress' : 'ierb_saved', [
        'entity_type' => 'student', 'entity_id' => $id, 'student_id' => $id, 'override' => $override,
        'reason' => $override ? ($reason !== '' ? $reason : '(none recorded)') : null,
        'details' => $override ? $change : "student_id=$studentIdCode stage=$stage status=$status",
        'after' => "$stage / $status",
    ]);
    if ($override) {
        notify_student($pdo, $id, 'PRISM IERB Progress Updated',
            "Hello $name,\n\nAn RPMS administrator updated your IERB record.\n$change"
                . ($reason !== '' ? "\nReason: $reason" : '') . "\n\n- CEU Malolos RPMS / PRISM",
            "RPMS updated your IERB record. $change" . ($reason !== '' ? ". Reason: $reason" : ''),
            'Status Update', $user['full_name']);
    }

    $response = ['ok' => true, 'id' => $id, 'override' => $override,
        'message' => "IERB record for $name saved." . ($override ? ' The stage/status change was logged as an Admin Override.' : '')];
    if ($newLoginUserId) {
        $response['accountCreated'] = true;
        $response = array_merge($response, account_setup_response_fields($setupDelivery, $newLoginTempPassword));
    }
    json_out($response);
}

if ($action === 'note') {
    api_require_login(['admin', 'adviser']);
    $studentId = (int)($data['studentId'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    if ($studentId <= 0 || $note === '') {
        json_out(['ok' => false, 'message' => 'Write a note before saving.'], 422);
    }
    if (mb_strlen($note) > 500) {
        json_out(['ok' => false, 'message' => 'Notes must be 500 characters or fewer.'], 422);
    }
    if (!adviser_may_access_student($pdo, $user, $studentId)) {
        json_out(['ok' => false, 'message' => 'This student is assigned to another adviser.'], 403);
    }
    $current = $pdo->prepare('SELECT stage, status FROM students WHERE id = :id');
    $current->execute([':id' => $studentId]);
    $row = $current->fetch();
    if (!$row) {
        json_out(['ok' => false, 'message' => 'Student not found.'], 404);
    }
    record_history($pdo, $studentId, $row['stage'], $row['status'], $note, null,
        $user['full_name'] . ' (' . ucfirst($user['role']) . ')');
    audit_log($user, 'ierb_note_added', ['entity_type' => 'student', 'entity_id' => $studentId,
        'student_id' => $studentId, 'details' => mb_substr($note, 0, 200)]);
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
