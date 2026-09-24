<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';

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
    $q = $pdo->prepare('SELECT 1 FROM students s JOIN advisers a ON a.id = s.adviser_id WHERE s.id = :id AND a.email = :e');
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

/** Student rows visible to this user (students: themselves, advisers: their advisees, admin: all). */
function load_student_rows(PDO $pdo, array $user): array
{
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id WHERE s.email = :e');
        $stmt->execute([':e' => $user['email']]);
    } elseif ($user['role'] === 'adviser') {
        $stmt = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id
            WHERE f.email = :e ORDER BY s.full_name');
        $stmt->execute([':e' => $user['email']]);
    } else {
        $stmt = $pdo->query('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id ORDER BY s.full_name');
    }
    return $stmt->fetchAll();
}

/** Optional filters shared by the list and the CSV export: q, stage, status. */
function apply_student_filters(array $rows, array $query): array
{
    $q = mb_strtolower(trim((string)($query['q'] ?? '')));
    $stage = trim((string)($query['stage'] ?? ''));
    $status = trim((string)($query['status'] ?? ''));
    if ($q === '' && $stage === '' && $status === '') {
        return $rows;
    }
    return array_values(array_filter($rows, function ($r) use ($q, $stage, $status) {
        if ($stage !== '' && $r['stage'] !== $stage) return false;
        if ($status !== '' && $r['status'] !== $status) return false;
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [
                $r['protocol_code'] ?? '', $r['student_id'], $r['full_name'], $r['email'],
                $r['research_title'], $r['research_group'], $r['course'], $r['adviser_name'] ?? '',
            ]));
            if (mb_strpos($hay, $q) === false) return false;
        }
        return true;
    }));
}

/** Spreadsheet formula-injection guard for CSV cells. */
function csv_safe($value): string
{
    $s = (string)($value ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }
    return $s;
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
    $rows = apply_student_filters(load_student_rows($pdo, $user), $_GET);
    $counts = student_document_counts($pdo);
    json_out(['ok' => true, 'records' => array_map(fn($r) => ierb_row($r, $counts), $rows)]);
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
    $stmt = $pdo->prepare('SELECT * FROM ierb_history WHERE student_id = :id ORDER BY created_at DESC, id DESC');
    $stmt->execute([':id' => $studentId]);
    json_out(['ok' => true, 'history' => $stmt->fetchAll()]);
}

if ($action === 'stage_distribution') {
    $distribution = [];
    foreach (load_student_rows($pdo, $user) as $row) {
        $stage = (string)$row['stage'];
        $distribution[$stage] = ($distribution[$stage] ?? 0) + 1;
    }
    $rows = [];
    foreach (STAGE_SEQUENCE as $stage) {
        $rows[] = ['stage' => $stage, 'c' => $distribution[$stage] ?? 0];
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
    $rows = apply_student_filters(load_student_rows($pdo, $user), $_GET);
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
    $newLoginUserId = null;
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE students SET stage = :stage, status = :status, updated_at = NOW() WHERE id = :id')
            ->execute([':stage' => $newStage, ':status' => $newStatus, ':id' => $id]);
        record_history($pdo, $id, $newStage, $newStatus, 'ADMIN OVERRIDE - ' . $change . '. Reason: ' . $reason,
            null, $user['full_name'] . ' (Admin Override)');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('ierb_override', $e->getMessage());
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
    $course = trim((string)($data['course'] ?? ''));
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
        || mb_strlen($course) > 100 || mb_strlen($research) > 255 || mb_strlen($requirements) > 255) {
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
    if ($id > 0) {
        $old = $pdo->prepare('SELECT stage, status, email FROM students WHERE id = :id');
        $old->execute([':id' => $id]);
        $oldRow = $old->fetch();
        $previousEmail = (string)($oldRow['email'] ?? '');
        if ($oldRow && ($oldRow['stage'] !== $stage || $oldRow['status'] !== $status)) {
            if (REQUIRE_OVERRIDE_REASON_ON_SAVE && !override_reason_valid($reason)) {
                json_out(['ok' => false, 'requiresReason' => true,
                    'message' => 'Changing the stage or status is an Admin Override. ' . override_reason_message()], 422);
            }
            $override = true;
            $change = describe_progress_change($oldRow, $stage, $status);
        }
    }

    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $pdo->prepare('UPDATE students SET student_id=:sid, full_name=:name, email=:email,
                research_group=:grp, course=:course, research_title=:research, stage=:stage, status=:status,
                requirements=:req, last_submission_date=:sub, updated_at=NOW() WHERE id=:id')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $course, ':research' => $research, ':stage' => $stage, ':status' => $status,
                    ':req' => $requirements, ':sub' => $submissionDate, ':id' => $id]);
            sync_student_login_identity($pdo, $previousEmail, $studentIdCode, $name, $email);
        } else {
            $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_group, course,
                research_title, stage, status, requirements, last_submission_date)
                VALUES (:sid,:name,:email,:grp,:course,:research,:stage,:status,:req,:sub)')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $course, ':research' => $research, ':stage' => $stage, ':status' => $status,
                    ':req' => $requirements, ':sub' => $submissionDate]);
            $id = (int)$pdo->lastInsertId();

            $collision = $pdo->prepare('SELECT id, role FROM users WHERE email = :e OR username = :u LIMIT 1');
            $collision->execute([':e' => $email, ':u' => $studentIdCode]);
            $existingLogin = $collision->fetch();
            if ($existingLogin && ($existingLogin['role'] ?? '') !== 'student') {
                throw new RuntimeException('The email or student ID already belongs to a different account type.');
            }
            if (!$existingLogin) {
                $tempPassword = generate_temporary_password();
                $pdo->prepare('INSERT INTO users (username,password_hash,role,full_name,email,ref_id,must_change_password)
                    VALUES (:u,:p,"student",:n,:e,:ref,1)')
                    ->execute([':u' => $studentIdCode, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                        ':n' => $name, ':e' => $email, ':ref' => $studentIdCode]);
                $newLoginUserId = (int)$pdo->lastInsertId();
            } else {
                $wasInactive = strcasecmp((string)($existingLogin['status'] ?? ''), 'Active') !== 0;
                $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status='Active'
                    WHERE id=:id AND role='student'")
                    ->execute([':u' => $studentIdCode, ':n' => $name, ':e' => $email,
                        ':ref' => $studentIdCode, ':id' => $existingLogin['id']]);
                if ($wasInactive) $newLoginUserId = (int)$existingLogin['id'];
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
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            json_out(['ok' => false, 'message' => 'That student ID or email is already in use.'], 422);
        }
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'different account type')) {
            json_out(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        log_api_error('ierb_save', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The IERB record could not be saved. Please try again.'], 500);
    }

    if ($newLoginUserId) {
        send_account_setup_email($pdo, $newLoginUserId, $email, $name);
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

    json_out(['ok' => true, 'id' => $id, 'override' => $override,
        'message' => "IERB record for $name saved." . ($override ? ' The stage/status change was logged as an Admin Override.' : '')]);
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
    $info = $pdo->prepare('SELECT student_id, full_name, email, protocol_code, stage, status FROM students WHERE id = :id');
    $info->execute([':id' => $id]);
    $gone = $info->fetch();
    if (!$gone) {
        json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM students WHERE id = :id')->execute([':id' => $id]);
        $pdo->prepare("UPDATE users SET status='Inactive' WHERE role='student' AND email=:email")
            ->execute([':email' => $gone['email']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_api_error('ierb_delete', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The student record could not be deleted.'], 500);
    }
    audit_log($user, 'ierb_deleted', [
        'entity_type' => 'student', 'entity_id' => $id,
        'details' => "{$gone['full_name']} ({$gone['student_id']}" . (!empty($gone['protocol_code']) ? ", {$gone['protocol_code']}" : '')
            . "), {$gone['stage']} / {$gone['status']}",
    ]);
    json_out(['ok' => true, 'message' => "Deleted the IERB record for {$gone['full_name']} and deactivated the associated login."]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
