<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';

function ierb_progress(string $stage): int
{
    $map = ['Stage 1' => 20, 'Stage 2' => 40, 'Stage 3' => 60, 'Stage 4' => 80, 'Stage 5' => 95, 'Completed' => 100];
    return $map[$stage] ?? 0;
}

function ierb_row(array $r): array
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
        'status' => $r['status'],
        'requirements' => $r['requirements'],
        'lastSubmissionDate' => $r['last_submission_date'],
        'progress' => ierb_progress($r['stage']),
        'adviser' => $r['adviser_name'] ?? null,
    ];
}

if ($action === 'list') {
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
    $rows = $stmt->fetchAll();
    json_out(['ok' => true, 'records' => array_map('ierb_row', $rows)]);
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
    }
    $stmt = $pdo->prepare('SELECT * FROM ierb_history WHERE student_id = :id ORDER BY created_at DESC');
    $stmt->execute([':id' => $studentId]);
    json_out(['ok' => true, 'history' => $stmt->fetchAll()]);
}

if ($action === 'stage_distribution') {
    $rows = $pdo->query('SELECT stage, COUNT(*) c FROM students GROUP BY stage')->fetchAll();
    json_out(['ok' => true, 'distribution' => $rows]);
}

// Everything below is RPMS-only (or the assigned research adviser, read/annotate only).
$data = json_body();

if ($action === 'save') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? 0);
    $studentIdCode = trim((string)($data['studentId'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $groupId = trim((string)($data['groupId'] ?? ''));
    $course = trim((string)($data['course'] ?? ''));
    $research = trim((string)($data['research'] ?? ''));
    $stage = trim((string)($data['stage'] ?? 'Stage 1'));
    $status = trim((string)($data['status'] ?? 'On Track'));
    $requirements = trim((string)($data['requirements'] ?? ''));
    $submissionDate = trim((string)($data['submissionDate'] ?? '')) ?: null;

    if ($studentIdCode === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Student ID, name, and a valid email are required.'], 422);
    }
    $validStages = ['Stage 1', 'Stage 2', 'Stage 3', 'Stage 4', 'Stage 5', 'Completed'];
    $validStatuses = ['On Track', 'Pending', 'Delayed'];
    if (!in_array($stage, $validStages, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }
    if (!in_array($status, $validStatuses, true)) {
        json_out(['ok' => false, 'message' => 'Invalid status.'], 422);
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
        } else {
            $pdo->prepare('INSERT INTO students (student_id, full_name, email, research_group, course,
                research_title, stage, status, requirements, last_submission_date)
                VALUES (:sid,:name,:email,:grp,:course,:research,:stage,:status,:req,:sub)')
                ->execute([':sid' => $studentIdCode, ':name' => $name, ':email' => $email, ':grp' => $groupId,
                    ':course' => $course, ':research' => $research, ':stage' => $stage, ':status' => $status,
                    ':req' => $requirements, ':sub' => $submissionDate]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, requirements, submission_date, actor)
            VALUES (:sid,:stage,:status,:note,:req,:sub,:actor)')->execute([
            ':sid' => $id, ':stage' => $stage, ':status' => $status, ':note' => 'IERB entry saved by RPMS.',
            ':req' => $requirements, ':sub' => $submissionDate, ':actor' => $user['full_name'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'message' => 'That student ID or email is already in use.'], 422);
    }

    log_activity($user['email'], 'ierb_saved', "student_id=$studentIdCode stage=$stage status=$status");
    json_out(['ok' => true, 'id' => $id]);
}

if ($action === 'note') {
    api_require_login(['admin', 'adviser']);
    $studentId = (int)($data['studentId'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    if ($studentId <= 0 || $note === '') {
        json_out(['ok' => false, 'message' => 'A note is required.'], 422);
    }
    $current = $pdo->prepare('SELECT stage, status FROM students WHERE id = :id');
    $current->execute([':id' => $studentId]);
    $row = $current->fetch();
    if (!$row) {
        json_out(['ok' => false, 'message' => 'Student not found.'], 404);
    }
    $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, actor)
        VALUES (:sid,:stage,:status,:note,:actor)')->execute([
        ':sid' => $studentId, ':stage' => $row['stage'], ':status' => $row['status'],
        ':note' => $note, ':actor' => $user['full_name'] . ' (' . ucfirst($user['role']) . ')',
    ]);
    log_activity($user['email'], 'ierb_note_added', "student_id=$studentId");
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    api_require_login('admin');
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM students WHERE id = :id')->execute([':id' => $id]);
    log_activity($user['email'], 'ierb_deleted', "id=$id");
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
