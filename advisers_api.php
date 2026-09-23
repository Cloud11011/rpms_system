<?php
require __DIR__ . '/config.php';
$user = api_require_login('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

function adviser_default_password(string $employeeId): string
{
    return 'Ceu@' . preg_replace('/[^A-Za-z0-9]/', '', $employeeId);
}

function row_to_adviser(array $r): array
{
    return [
        'id' => (int)$r['id'],
        '   ' => $r['employee_id'],
        'name' => $r['full_name'],
        'email' => $r['email'],
        'department' => $r['department'],
        'groups' => $r['assigned_groups'],
        'status' => $r['status'],
        'createdAt' => $r['created_at'],
    ];
}

if ($action === 'list') {
    $rows = $pdo->query('SELECT * FROM advisers ORDER BY full_name ASC')->fetchAll();
    json_out(['ok' => true, 'advisers' => array_map('row_to_adviser', $rows)]);
}

$data = json_body();

if ($action === 'save') {
    $id = (int)($data['id'] ?? 0);
    $employeeId = trim((string)($data['employeeId'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $department = trim((string)($data['department'] ?? ''));
    $groups = trim((string)($data['groups'] ?? ''));
    $status = trim((string)($data['status'] ?? 'Active'));

    if ($employeeId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Employee ID, name, and a valid email are required.'], 422);
    }
    if ($id === 0) {
        $collision = $pdo->prepare('SELECT role FROM users WHERE (email = :e OR username = :u) AND role != "adviser"');
        $collision->execute([':e' => $email, ':u' => $employeeId]);
        if ($existingRole = $collision->fetchColumn()) {
            json_out(['ok' => false, 'message' => "That email or ID is already used by a $existingRole account. Use a different email/ID so this adviser can be given their own login."], 422);
        }
    }

    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $pdo->prepare('UPDATE advisers SET employee_id=:eid, full_name=:name, email=:email,
                department=:dept, assigned_groups=:grp, status=:status, updated_at=NOW() WHERE id=:id')
                ->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email, ':dept' => $department,
                    ':grp' => $groups, ':status' => $status, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO advisers (employee_id, full_name, email, department, assigned_groups, status)
                VALUES (:eid,:name,:email,:dept,:grp,:status)');
            $stmt->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email,
                ':dept' => $department, ':grp' => $groups, ':status' => $status]);
            $id = (int)$pdo->lastInsertId();

            $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = :e OR username = :u');
            $userStmt->execute([':e' => $email, ':u' => $employeeId]);
            if (!$userStmt->fetch()) {
                $tempPassword = adviser_default_password($employeeId);
                $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id, must_change_password)
                    VALUES (:u,:p,"adviser",:n,:e,:ref,1)')->execute([
                    ':u' => $employeeId, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':n' => $name, ':e' => $email, ':ref' => $employeeId,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'message' => 'That employee ID or email is already in use.'], 422);
    }

    log_activity($user['email'], 'adviser_saved', "employee_id=$employeeId");
    json_out(['ok' => true, 'id' => $id]);
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('UPDATE students SET adviser_id = NULL WHERE adviser_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM advisers WHERE id = :id')->execute([':id' => $id]);
    log_activity($user['email'], 'adviser_deleted', "id=$id");
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
