<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';
$user = api_require_login(['admin', 'adviser']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['save', 'delete'], true)) {
    require_post_same_origin();
}

function student_default_password(string $studentId): string
{
    return generate_temporary_password();
}

function describe_student_progress(string $oldStage, string $oldStatus, string $newStage, string $newStatus): string
{
    $parts = [];
    if ($oldStage !== $newStage) { $parts[] = "Stage: $oldStage -> $newStage"; }
    if ($oldStatus !== $newStatus) { $parts[] = "Status: $oldStatus -> $newStatus"; }
    return implode('; ', $parts);
}

function row_to_student(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'studentId' => $r['student_id'],
        'name' => $r['full_name'],
        'email' => $r['email'],
        'research' => $r['research_title'],
        'group' => $r['research_group'],
        'course' => $r['course'],
        'adviserId' => $r['adviser_id'] ? (int)$r['adviser_id'] : null,
        'adviserName' => $r['adviser_name'] ?? null,
        'stage' => $r['stage'],
        'stageLabel' => stage_label($r['stage']),
        'status' => $r['status'],
        'requirements' => $r['requirements'],
        'protocolCode' => $r['protocol_code'] ?? null,
        'isPrincipalInvestigator' => !empty($r['is_principal_investigator']),
        'lastSubmissionDate' => $r['last_submission_date'],
        'progress' => stage_progress_percent($r['stage']),
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ];
}

// Progress percentage and stage sequencing now live centrally in
// config.php (stage_progress_percent, STAGE_SEQUENCE, next_stage) so
// every API agrees on the same numbers/order.

if ($action === 'list') {
    if ($user['role'] === 'adviser') {
        $rows = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id WHERE f.email = :e ORDER BY s.full_name ASC');
        $rows->execute([':e' => $user['email']]);
        $rows = $rows->fetchAll();
    } else {
        // Admin sees every student, regardless of adviser (feature request: full admin access).
        $rows = $pdo->query('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id ORDER BY s.full_name ASC')->fetchAll();
    }
    json_out(['ok' => true, 'students' => array_map('row_to_student', $rows)]);
}

if ($action === 'adviser_options') {
    $rows = $pdo->query('SELECT id, full_name FROM advisers WHERE status = "Active" ORDER BY full_name')->fetchAll();
    json_out(['ok' => true, 'advisers' => $rows]);
}

$data = json_body();

if ($action === 'save') {
    api_require_login(['admin', 'adviser']);
    $id = (int)($data['id'] ?? 0);
    $studentId = trim((string)($data['studentId'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $research = trim((string)($data['research'] ?? ''));
    $group = trim((string)($data['group'] ?? ''));
    $courseProvided = array_key_exists('course', $data);
    $course = $courseProvided ? trim((string)$data['course']) : null;
    $adviserId = !empty($data['adviserId']) ? (int)$data['adviserId'] : null;
    $stage = trim((string)($data['stage'] ?? 'Stage 1'));
    $status = trim((string)($data['status'] ?? 'On Track'));
    $requirementsProvided = array_key_exists('requirements', $data);
    $requirements = $requirementsProvided ? trim((string)$data['requirements']) : null;
    $protocolCode = trim((string)($data['protocolCode'] ?? ''));
    $isPrincipal = !empty($data['isPrincipalInvestigator']) ? 1 : 0;
    $reason = trim((string)($data['reason'] ?? ''));

    $ownAdviserId = null;
    if ($user['role'] === 'adviser') {
        $ownAdviser = $pdo->prepare('SELECT id FROM advisers WHERE email = :e LIMIT 1');
        $ownAdviser->execute([':e' => $user['email']]);
        $ownAdviserId = $ownAdviser->fetchColumn();
        if (!$ownAdviserId) {
            json_out(['ok' => false, 'message' => 'No adviser record is linked to your account. Ask the RPMS office to set this up.'], 403);
        }
        // Advisers may only add/assign their OWN students -- they cannot
        // hand a student to another adviser, whatever adviserId was sent.
        $adviserId = (int)$ownAdviserId;
        // Protocol code and Principal Investigator status are RPMS-office
        // decisions (item 12: admin has full authority over these), not
        // something an adviser can self-assign while editing a record.
        $protocolCode = null;
        $isPrincipal = null;
    }

    $validStages = STAGE_SEQUENCE;
    $validStatuses = ['On Track', 'Pending', 'Delayed'];

    if ($studentId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Student ID, name, and a valid email are required.'], 422);
    }
    if (mb_strlen($studentId) > 100 || mb_strlen($name) > 190 || mb_strlen($research) > 255
        || mb_strlen($group) > 190
        || ($protocolCode !== null && mb_strlen((string)$protocolCode) > 100)) {
        json_out(['ok' => false, 'message' => 'One or more fields are too long. Please shorten the entry and try again.'], 422);
    }
    if (!is_allowed_email_domain($email)) {
        json_out(['ok' => false, 'message' => 'Only ' . allowed_email_domains_hint() . ' email addresses are allowed.'], 422);
    }
    if ($course !== null && mb_strlen($course) > 100) {
        json_out(['ok' => false, 'message' => 'Course must be 100 characters or fewer.'], 422);
    }
    if ($requirements !== null && mb_strlen($requirements) > 255) {
        json_out(['ok' => false, 'message' => 'Pending requirements must be 255 characters or fewer.'], 422);
    }
    if (!in_array($stage, $validStages, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }
    if (!in_array($status, $validStatuses, true)) {
        json_out(['ok' => false, 'message' => 'Invalid status.'], 422);
    }
    if ($adviserId !== null) {
        $adviserCheck = $pdo->prepare('SELECT id FROM advisers WHERE id = :id');
        $adviserCheck->execute([':id' => $adviserId]);
        if (!$adviserCheck->fetch()) {
            json_out(['ok' => false, 'message' => 'Selected adviser was not found.'], 422);
        }
    }
    if ($id > 0 && $user['role'] === 'adviser') {
        // An adviser may only edit a student that's already assigned to them.
        $ownershipCheck = $pdo->prepare('SELECT adviser_id FROM students WHERE id = :id');
        $ownershipCheck->execute([':id' => $id]);
        $currentAdviserId = $ownershipCheck->fetchColumn();
        if ((string)$currentAdviserId !== (string)$ownAdviserId) {
            json_out(['ok' => false, 'message' => 'You can only manage students assigned to you.'], 403);
        }
    }
    if ($id === 0) {
        // Creating a new student: the email/ID must not already belong to a
        // login of a DIFFERENT role, or the auto-provisioned account below
        // would silently be skipped and this student would have no way to
        // log in.
        $collision = $pdo->prepare('SELECT role FROM users WHERE (email = :e OR username = :u) AND role != "student"');
        $collision->execute([':e' => $email, ':u' => $studentId]);
        if ($existingRole = $collision->fetchColumn()) {
            json_out(['ok' => false, 'message' => "That email or ID is already used by a $existingRole account. Use a different email/ID so this student can be given their own login."], 422);
        }
    }

    $newLoginUserId = null;
    $newLoginTempPassword = null;
    $setupDelivery = null;
    $progressChanged = false;
    $progressChangeText = '';
    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $existing = $pdo->prepare('SELECT * FROM students WHERE id = :id');
            $existing->execute([':id' => $id]);
            $before = $existing->fetch();
            if (!$before) {
                throw new RuntimeException('Student record not found.');
            }
            // Advisers can't touch protocol code / PI status (set to null
            // above) -- keep whatever was already on the record.
            $finalProtocolCode = $protocolCode !== null ? ($protocolCode !== '' ? $protocolCode : null) : $before['protocol_code'];
            $finalIsPrincipal = $isPrincipal !== null ? $isPrincipal : (int)$before['is_principal_investigator'];
            $finalCourse = $courseProvided ? $course : $before['course'];
            $finalRequirements = $requirementsProvided ? $requirements : $before['requirements'];

            $stmt = $pdo->prepare('UPDATE students SET student_id=:sid, full_name=:name, email=:email,
                research_title=:research, research_group=:grp, course=:course, adviser_id=:adv,
                stage=:stage, status=:status, requirements=:req, protocol_code=:pcode,
                is_principal_investigator=:pi, updated_at=NOW() WHERE id=:id');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => $finalCourse, ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => $finalRequirements, ':pcode' => $finalProtocolCode,
                ':pi' => $finalIsPrincipal, ':id' => $id]);

            sync_student_login_identity($pdo, (string)$before['email'], $studentId, $name, $email);

            if ($before['stage'] !== $stage || $before['status'] !== $status) {
                if (!override_reason_valid($reason)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    json_out(['ok' => false, 'requiresReason' => true,
                        'message' => 'Changing a student stage or status needs a reason for the progress history. ' . override_reason_message()], 422);
                }
                $progressChanged = true;
                $progressChangeText = describe_student_progress($before['stage'], $before['status'], $stage, $status);
                // v2 audit trail: this endpoint can also change stage/status, so it must be logged
                // with who/what/before/after just like the IERB Override (it previously wasn't).
                audit_log($user, $user['role'] === 'admin' ? 'admin_override_student_progress' : 'student_progress_changed', [
                    'entity_type' => 'student', 'entity_id' => $id, 'student_id' => $id,
                    'override' => $user['role'] === 'admin',
                    'before' => $before['stage'] . ' / ' . $before['status'], 'after' => "$stage / $status",
                    'details' => $progressChangeText, 'reason' => $reason,
                ]);
                $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, actor)
                    VALUES (:sid,:stage,:status,:note,:req,:actor)')->execute([
                    ':sid' => $id, ':stage' => $stage, ':status' => $status,
                    ':note' => 'Progress updated by ' . ($user['role'] === 'adviser' ? 'research adviser' : 'RPMS')
                        . '. ' . $progressChangeText . '. Reason: ' . $reason,
                    ':req' => ($requirementsProvided ? $requirements : $before['requirements']), ':actor' => $user['full_name'],
                ]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_title,
                research_group, course, adviser_id, stage, status, requirements, protocol_code, is_principal_investigator)
                VALUES (:sid,:name,:email,:research,:grp,:course,:adv,:stage,:status,:req,:pcode,:pi)');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => ($course ?? ''), ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => ($requirements ?? ''),
                ':pcode' => ($protocolCode !== null && $protocolCode !== '') ? $protocolCode : null,
                ':pi' => $isPrincipal ?? 0]);
            $id = (int)$pdo->lastInsertId();

            // Auto-provision a student login account, per the study's "limited submission module" design.
            $userStmt = $pdo->prepare('SELECT id, role, status FROM users WHERE email = :e OR username = :u LIMIT 1');
            $userStmt->execute([':e' => $email, ':u' => $studentId]);
            $existingLogin = $userStmt->fetch();
            if (!$existingLogin) {
                $tempPassword = student_default_password($studentId);
                $newLoginTempPassword = $tempPassword;
                $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id, must_change_password)
                    VALUES (:u,:p,"student",:n,:e,:ref,1)')->execute([
                    ':u' => $studentId, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':n' => $name, ':e' => $email, ':ref' => $studentId,
                ]);
                $newLoginUserId = (int)$pdo->lastInsertId();
            } elseif (($existingLogin['role'] ?? '') === 'student') {
                $wasInactive = strcasecmp((string)($existingLogin['status'] ?? ''), 'Active') !== 0;
                $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status='Active' WHERE id=:id")
                    ->execute([':u' => $studentId, ':n' => $name, ':e' => $email, ':ref' => $studentId, ':id' => $existingLogin['id']]);
                if ($wasInactive) $newLoginUserId = (int)$existingLogin['id'];
            }

            $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, actor)
                VALUES (:sid,:stage,:status,:note,:req,:actor)')->execute([
                ':sid' => $id, ':stage' => $stage, ':status' => $status,
                ':note' => 'Record created by RPMS.', ':req' => ($requirements ?? ''), ':actor' => $user['full_name'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $sqlState = $e instanceof PDOException ? (string)($e->errorInfo[0] ?? '') : '';
        if ($sqlState === '23000' && stripos($e->getMessage(), 'foreign key') !== false) {
            json_out(['ok' => false, 'message' => 'The selected adviser is invalid.'], 422);
        }
        json_out(['ok' => false, 'message' => 'That student ID or email is already in use.'], 422);
    }

    if ($newLoginUserId) {
        $setupDelivery = send_account_setup_email($pdo, $newLoginUserId, $email, $name);
    }
    if ($progressChanged) {
        notify_student($pdo, $id, 'PRISM IERB Progress Updated',
            "Hello $name,\n\nYour IERB progress was updated.\n$progressChangeText\nReason: $reason\n\n- CEU Malolos RPMS / PRISM",
            "Your IERB progress was updated. $progressChangeText. Reason: $reason",
            'Status Update', $user['full_name']);
    }
    log_activity($user['email'], 'student_saved', "student_id=$studentId");
    $response = ['ok' => true, 'id' => $id, 'message' => 'Student record saved.'];
    if ($newLoginUserId) {
        $response['accountCreated'] = true;
        $response['setupChannel'] = $setupDelivery['channel'] ?? 'none';
        $response['setupMessage'] = $setupDelivery['message'] ?? '';
        if (($setupDelivery['channel'] ?? 'none') === 'log' || !($setupDelivery['ok'] ?? false)) {
            $response['temporaryPassword'] = $newLoginTempPassword;
        }
    }
    json_out($response);
}

if ($action === 'delete') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? 0);
    $row = $pdo->prepare('SELECT email FROM students WHERE id = :id');
    $row->execute([':id' => $id]);
    $studentEmail = (string)($row->fetchColumn() ?: '');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM students WHERE id = :id')->execute([':id' => $id]);
        if ($studentEmail !== '') {
            $pdo->prepare("UPDATE users SET status = 'Inactive' WHERE role = 'student' AND email = :e")
                ->execute([':e' => $studentEmail]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'message' => 'The student could not be deleted.'], 500);
    }
    log_activity($user['email'], 'student_deleted', "id=$id");
    json_out(['ok' => true, 'message' => $studentEmail !== ''
        ? 'Student record deleted and the associated login was deactivated.'
        : 'Student record deleted.']);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
