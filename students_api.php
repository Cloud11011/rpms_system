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
        'status' => $r['status'],
        'requirements' => $r['requirements'],
        'lastSubmissionDate' => $r['last_submission_date'],
        'progress' => stage_progress($r['stage']),
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ];
}

function stage_progress(string $stage): int
{
    $map = ['Stage 1' => 20, 'Stage 2' => 40, 'Stage 3' => 60, 'Stage 4' => 80, 'Stage 5' => 95, 'Completed' => 100];
    return $map[$stage] ?? 0;
}

if ($action === 'list') {
    $rows = $pdo->query('SELECT s.*, f.full_name AS adviser_name FROM students s
        LEFT JOIN advisers f ON f.id = s.adviser_id ORDER BY s.full_name ASC')->fetchAll();
    json_out(['ok' => true, 'students' => array_map('row_to_student', $rows)]);
}

if ($action === 'adviser_options') {
    $rows = $pdo->query('SELECT id, full_name FROM advisers WHERE status = "Active" ORDER BY full_name')->fetchAll();
    json_out(['ok' => true, 'advisers' => $rows]);
}

$data = json_body();

if ($action === 'save') {
    api_require_login('admin');
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

    $validStages = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    $validStatuses = ['On Track', 'Pending', 'Delayed'];

    if ($studentId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Student ID, name, and a valid email are required.'], 422);
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
            $stmt = $pdo->prepare('UPDATE students SET student_id=:sid, full_name=:name, email=:email,
                research_title=:research, research_group=:grp, course=:course, adviser_id=:adv,
                stage=:stage, status=:status, requirements=:req, updated_at=NOW() WHERE id=:id');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => $course, ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => $requirements, ':id' => $id]);

            if ($before['stage'] !== $stage || $before['status'] !== $status) {
                $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, actor)
                    VALUES (:sid,:stage,:status,:note,:req,:actor)')->execute([
                    ':sid' => $id, ':stage' => $stage, ':status' => $status,
                    ':note' => 'Record updated by RPMS.', ':req' => $requirements, ':actor' => $user['full_name'],
                ]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_title,
                research_group, course, adviser_id, stage, status, requirements)
                VALUES (:sid,:name,:email,:research,:grp,:course,:adv,:stage,:status,:req)');
            $stmt->execute([':sid' => $studentId, ':name' => $name, ':email' => $email, ':research' => $research,
                ':grp' => $group, ':course' => $course, ':adv' => $adviserId, ':stage' => $stage,
                ':status' => $status, ':req' => $requirements]);
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
