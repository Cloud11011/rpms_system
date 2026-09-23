<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser']);
$pdo = db();
$action = $_GET['action'] ?? 'list';

function student_default_password(string $studentId): string
{
    // Simple, predictable initial credential; students are told to change it.
    return 'Ceu@' . preg_replace('/[^A-Za-z0-9]/', '', $studentId);
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
    $email = trim((string)($data['email'] ?? ''));
    $research = trim((string)($data['research'] ?? ''));
    $group = trim((string)($data['group'] ?? ''));
    $course = trim((string)($data['course'] ?? ''));
    $adviserId = !empty($data['adviserId']) ? (int)$data['adviserId'] : null;
    $stage = trim((string)($data['stage'] ?? 'Stage 1'));
    $status = trim((string)($data['status'] ?? 'On Track'));
    $requirements = trim((string)($data['requirements'] ?? ''));
    $protocolCode = trim((string)($data['protocolCode'] ?? ''));
    $isPrincipal = !empty($data['isPrincipalInvestigator']) ? 1 : 0;

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
    if (!is_allowed_email_domain($email)) {
        json_out(['ok' => false, 'message' => 'Only ' . allowed_email_domains_hint() . ' email addresses are allowed.'], 422);
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

            $stmt = $pdo->prepare('UPDATE students SET student_id=:sid, full_name=:name, email=:email,
                research_title=:research, research_group=:grp, course=:course, adviser_id=:adv,
                stage=:stage, status=:status, requirements=:req, protocol_code=:pcode,
                is_principal_investigator=:pi, updated_at=NOW() WHERE id=:id');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => $course, ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => $requirements, ':pcode' => $finalProtocolCode,
                ':pi' => $finalIsPrincipal, ':id' => $id]);

            if ($before['stage'] !== $stage || $before['status'] !== $status) {
                $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, actor)
                    VALUES (:sid,:stage,:status,:note,:req,:actor)')->execute([
                    ':sid' => $id, ':stage' => $stage, ':status' => $status,
                    ':note' => 'Record updated by ' . ($user['role'] === 'adviser' ? 'research adviser' : 'RPMS') . '.',
                    ':req' => $requirements, ':actor' => $user['full_name'],
                ]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_title,
                research_group, course, adviser_id, stage, status, requirements, protocol_code, is_principal_investigator)
                VALUES (:sid,:name,:email,:research,:grp,:course,:adv,:stage,:status,:req,:pcode,:pi)');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => $course, ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => $requirements,
                ':pcode' => ($protocolCode !== null && $protocolCode !== '') ? $protocolCode : null,
                ':pi' => $isPrincipal ?? 0]);
            $id = (int)$pdo->lastInsertId();

            // Auto-provision a student login account, per the study's "limited submission module" design.
            $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = :e OR username = :u');
            $userStmt->execute([':e' => $email, ':u' => $studentId]);
            if (!$userStmt->fetch()) {
                $tempPassword = student_default_password($studentId);
                $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id, must_change_password)
                    VALUES (:u,:p,"student",:n,:e,:ref,1)')->execute([
                    ':u' => $studentId, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':n' => $name, ':e' => $email, ':ref' => $studentId,
                ]);
            }

            $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, actor)
                VALUES (:sid,:stage,:status,:note,:req,:actor)')->execute([
                ':sid' => $id, ':stage' => $stage, ':status' => $status,
                ':note' => 'Record created by RPMS.', ':req' => $requirements, ':actor' => $user['full_name'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $sqlState = $e instanceof PDOException ? (string)($e->errorInfo[0] ?? '') : '';
        if ($sqlState === '23000' && stripos($e->getMessage(), 'foreign key') !== false) {
            json_out(['ok' => false, 'message' => 'The selected adviser is invalid.'], 422);
        }
        json_out(['ok' => false, 'message' => 'That student ID or email is already in use.'], 422);
    }

    log_activity($user['email'], 'student_saved', "student_id=$studentId");
    json_out(['ok' => true, 'id' => $id]);
}

if ($action === 'delete') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM students WHERE id = :id')->execute([':id' => $id]);
    log_activity($user['email'], 'student_deleted', "id=$id");
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
