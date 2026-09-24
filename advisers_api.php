<?php
require __DIR__ . '/config.php';
$user = api_require_login('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['save', 'delete'], true)) {
    require_post_same_origin();
}

function adviser_default_password(string $employeeId): string
{
    return generate_temporary_password();
}

function row_to_adviser(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'employeeId' => $r['employee_id'],
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
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $department = trim((string)($data['department'] ?? ''));
    $groups = trim((string)($data['groups'] ?? ''));
    $status = trim((string)($data['status'] ?? 'Active'));

    if ($employeeId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Employee ID, name, and a valid email are required.'], 422);
    }
    if (mb_strlen($employeeId) > 100 || mb_strlen($name) > 190 || mb_strlen($department) > 190 || mb_strlen($groups) > 255) {
        json_out(['ok' => false, 'message' => 'One or more fields are too long. Please shorten the entry and try again.'], 422);
    }
    if (!is_allowed_email_domain($email)) {
        json_out(['ok' => false, 'message' => 'Only ' . allowed_email_domains_hint() . ' email addresses are allowed.'], 422);
    }
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        json_out(['ok' => false, 'message' => 'Invalid adviser account status.'], 422);
    }
    if ($id === 0) {
        $collision = $pdo->prepare('SELECT role FROM users WHERE (email = :e OR username = :u) AND role != "adviser"');
        $collision->execute([':e' => $email, ':u' => $employeeId]);
        if ($existingRole = $collision->fetchColumn()) {
            json_out(['ok' => false, 'message' => "That email or ID is already used by a $existingRole account. Use a different email/ID so this adviser can be given their own login."], 422);
        }
    }

    $newLoginUserId = null;
    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $beforeStmt = $pdo->prepare('SELECT email FROM advisers WHERE id = :id');
            $beforeStmt->execute([':id' => $id]);
            $oldEmail = (string)($beforeStmt->fetchColumn() ?: '');
            $pdo->prepare('UPDATE advisers SET employee_id=:eid, full_name=:name, email=:email,
                department=:dept, assigned_groups=:grp, status=:status, updated_at=NOW() WHERE id=:id')
                ->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email, ':dept' => $department,
                    ':grp' => $groups, ':status' => $status, ':id' => $id]);
            if ($oldEmail !== '') {
                $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:new, ref_id=:ref, status=:status
                    WHERE email=:old AND role='adviser'")
                    ->execute([':u' => $employeeId, ':n' => $name, ':new' => $email, ':ref' => $employeeId,
                        ':status' => $status, ':old' => $oldEmail]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO advisers (employee_id, full_name, email, department, assigned_groups, status)
                VALUES (:eid,:name,:email,:dept,:grp,:status)');
            $stmt->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email,
                ':dept' => $department, ':grp' => $groups, ':status' => $status]);
            $id = (int)$pdo->lastInsertId();

            $userStmt = $pdo->prepare('SELECT id, role, status FROM users WHERE email = :e OR username = :u LIMIT 1');
            $userStmt->execute([':e' => $email, ':u' => $employeeId]);
            $existingLogin = $userStmt->fetch();
            if (!$existingLogin) {
                $tempPassword = adviser_default_password($employeeId);
                $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id, status, must_change_password)
                    VALUES (:u,:p,"adviser",:n,:e,:ref,:status,1)')->execute([
                    ':u' => $employeeId, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':n' => $name, ':e' => $email, ':ref' => $employeeId, ':status' => $status,
                ]);
                $newLoginUserId = (int)$pdo->lastInsertId();
            } elseif (($existingLogin['role'] ?? '') === 'adviser') {
                $wasInactive = strcasecmp((string)($existingLogin['status'] ?? ''), 'Active') !== 0;
                $pdo->prepare('UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status=:status WHERE id=:id')
                    ->execute([':u' => $employeeId, ':n' => $name, ':e' => $email, ':ref' => $employeeId,
                        ':status' => $status, ':id' => $existingLogin['id']]);
                if ($wasInactive && $status === 'Active') $newLoginUserId = (int)$existingLogin['id'];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'message' => 'That employee ID or email is already in use.'], 422);
    }

    if ($newLoginUserId && $status === 'Active') {
        send_account_setup_email($pdo, $newLoginUserId, $email, $name);
    }
    log_activity($user['email'], 'adviser_saved', "employee_id=$employeeId");
    json_out(['ok' => true, 'id' => $id]);
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    $row = $pdo->prepare('SELECT email FROM advisers WHERE id = :id');
    $row->execute([':id' => $id]);
    $adviserEmail = (string)($row->fetchColumn() ?: '');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE students SET adviser_id = NULL WHERE adviser_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM advisers WHERE id = :id')->execute([':id' => $id]);
        if ($adviserEmail !== '') {
            $pdo->prepare("UPDATE users SET status = 'Inactive' WHERE role = 'adviser' AND email = :e")
                ->execute([':e' => $adviserEmail]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'message' => 'The adviser could not be deleted.'], 500);
    }
    log_activity($user['email'], 'adviser_deleted', "id=$id");
    json_out(['ok' => true, 'message' => $adviserEmail !== ''
        ? 'Adviser record deleted, assigned students were unassigned, and the associated login was deactivated.'
        : 'Adviser record deleted and assigned students were unassigned.']);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
