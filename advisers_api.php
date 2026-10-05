<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/record_filters.php';
require_once __DIR__ . '/includes/research_groups.php';
require_once __DIR__ . '/includes/academic_catalog.php';
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

function row_to_adviser(array $r, array $groups = []): array
{
    return [
        'id' => (int)$r['id'],
        'employeeId' => $r['employee_id'],
        'name' => $r['full_name'],
        'email' => $r['email'],
        'department' => $r['department'],
        'groups' => $groups,
        'status' => $r['status'],
        'createdAt' => $r['created_at'],
    ];
}

if ($action === 'list') {
    $scope = 'FROM advisers a WHERE 1=1'; $params = [];
    $departments = $pdo->query('SELECT DISTINCT department FROM advisers ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions = ['department'=>array_map(fn($v)=>['value'=>trim((string)$v) === '' ? '__blank__' : $v,'label'=>trim((string)$v) === '' ? 'Not recorded' : $v], $departments),
        'status'=>[['value'=>'Active','label'=>'Active'],['value'=>'Inactive','label'=>'Inactive']],
        'group'=>array_map(fn($v)=>['value'=>$v,'label'=>$v],research_group_options($pdo,$user))];
    foreach (['department','status','group'] as $key) {
        $value = $_GET[$key] ?? '';
        if (!is_string($value) || ($value !== '' && !in_array($value,array_column($filterOptions[$key],'value'),true))) json_out(['ok'=>false,'message'=>'Invalid adviser filter.'],422);
        if ($value === '') continue;
        if ($key === 'group') {
            $matchingIds = [];
            foreach (research_groups_by_adviser($pdo) as $adviserId => $groups) {
                if (in_array($value, $groups, true)) $matchingIds[] = (int)$adviserId;
            }
            $scope .= ' AND a.id IN (' . ($matchingIds ? implode(',', $matchingIds) : '0') . ')';
            continue;
        }
        elseif ($value === '__blank__') { $scope .= " AND (a.$key IS NULL OR TRIM(a.$key)='')"; continue; }
        else $scope .= " AND a.$key=:filter_$key";
        $params[':filter_'.$key]=$value;
    }
    $q = prism_record_search($_GET);
    if ($q !== '') {
        $search = prism_search_clause(['a.full_name', 'a.employee_id', 'a.email', 'a.department'], $q, $params);
        $matchingGroups = array_values(array_filter(research_group_options($pdo, $user), fn($group) => mb_strpos(mb_strtolower($group), mb_strtolower($q)) !== false));
        if ($matchingGroups) {
            $keys = [];
            foreach ($matchingGroups as $i => $group) { $key = ':group' . $i; $keys[] = $key; $params[$key] = $group; }
            $search .= ' OR EXISTS (SELECT 1 FROM students s WHERE s.adviser_id = a.id AND s.research_group IN (' . implode(',', $keys) . '))';
        }
        $scope .= ' AND (' . $search . ')';
    }
    $page = prism_page_query($pdo, 'SELECT a.*', $scope, $params, prism_record_order($_GET,['name'=>'a.full_name','employeeId'=>'a.employee_id','email'=>'a.email','department'=>'a.department','status'=>'a.status'],'name','a.id ASC'), $_GET);
    $rows = $page['rows']; unset($page['rows']);
    $groups = research_groups_by_adviser($pdo, array_column($rows, 'id'));
    json_out(['ok' => true, 'advisers' => array_map(fn($r) => row_to_adviser($r, $groups[(int)$r['id']] ?? []), $rows), 'filterOptions'=>$filterOptions] + $page);
}

$data = json_body();

if ($action === 'save') {
    $id = max(0, (int)($data['id'] ?? 0));
    $employeeId = trim((string)($data['employeeId'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $department = trim((string)($data['department'] ?? ''));
    $status = trim((string)($data['status'] ?? 'Active'));

    if ($employeeId === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'message' => 'Employee ID, name, and a valid email are required.'], 422);
    }
    if (mb_strlen($employeeId) > 100 || mb_strlen($name) > 190 || mb_strlen($department) > 190) {
        json_out(['ok' => false, 'message' => 'One or more fields are too long. Please shorten the entry and try again.'], 422);
    }
    if (!is_allowed_email_domain($email)) {
        json_out(['ok' => false, 'message' => 'Only ' . allowed_email_domains_hint() . ' email addresses are allowed.'], 422);
    }
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        json_out(['ok' => false, 'message' => 'Invalid adviser account status.'], 422);
    }
    if ($id === 0 && !in_array($department, array_column(academic_catalog()['units'], 'label'), true)) json_out(['ok'=>false,'message'=>'Choose an academic unit from the catalog.'],422);
    if ($id === 0) {
        $collision = $pdo->prepare('SELECT role FROM users WHERE (email = :e OR username = :u) AND role != "adviser"');
        $collision->execute([':e' => $email, ':u' => $employeeId]);
        if ($existingRole = $collision->fetchColumn()) {
            json_out(['ok' => false, 'message' => "That email or ID is already used by a $existingRole account. Use a different email/ID so this adviser can be given their own login."], 422);
        }
    }

    $newLoginUserId = null;
    $newLoginTempPassword = null;
    $setupDelivery = null;
    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $beforeStmt = $pdo->prepare('SELECT email, department FROM advisers WHERE id = :id FOR UPDATE');
            $beforeStmt->execute([':id' => $id]);
            $before = $beforeStmt->fetch();
            if (!$before) {
                $pdo->rollBack();
                json_out(['ok' => false, 'message' => 'Adviser record not found.'], 404);
            }
            if (!in_array($department, array_column(academic_catalog()['units'], 'label'), true)) {
                if ($department !== trim((string)$before['department'])) {
                    $pdo->rollBack(); json_out(['ok'=>false,'message'=>'Choose an academic unit from the catalog.'],422);
                }
                $department = (string)$before['department'];
            }
            $oldEmail = (string)$before['email'];
            $pdo->prepare('UPDATE advisers SET employee_id=:eid, full_name=:name, email=:email,
                department=:dept, status=:status, updated_at=NOW() WHERE id=:id')
                ->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email, ':dept' => $department,
                    ':status' => $status, ':id' => $id]);
            if ($oldEmail !== '') {
                $pdo->prepare("UPDATE users SET username=:u, full_name=:n, email=:new, ref_id=:ref, status=:status
                    WHERE email=:old AND role='adviser'")
                    ->execute([':u' => $employeeId, ':n' => $name, ':new' => $email, ':ref' => $employeeId,
                        ':status' => $status, ':old' => $oldEmail]);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO advisers (employee_id, full_name, email, department, status)
                VALUES (:eid,:name,:email,:dept,:status)');
            $stmt->execute([':eid' => $employeeId, ':name' => $name, ':email' => $email,
                ':dept' => $department, ':status' => $status]);
            $id = (int)$pdo->lastInsertId();

            $userStmt = $pdo->prepare('SELECT id, role, status FROM users WHERE email = :e OR username = :u LIMIT 1');
            $userStmt->execute([':e' => $email, ':u' => $employeeId]);
            $existingLogin = $userStmt->fetch();
            if (!$existingLogin) {
                $tempPassword = adviser_default_password($employeeId);
                $newLoginTempPassword = $tempPassword;
                $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id, status, must_change_password)
                    VALUES (:u,:p,"adviser",:n,:e,:ref,:status,1)')->execute([
                    ':u' => $employeeId, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':n' => $name, ':e' => $email, ':ref' => $employeeId, ':status' => $status,
                ]);
                $newLoginUserId = (int)$pdo->lastInsertId();
            } elseif (($existingLogin['role'] ?? '') === 'adviser') {
                $wasInactive = strcasecmp((string)($existingLogin['status'] ?? ''), 'Active') !== 0;
                if ($wasInactive && $status === 'Active') {
                    $tempPassword = adviser_default_password($employeeId);
                    $newLoginTempPassword = $tempPassword;
                    $pdo->prepare('UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status=:status,
                            password_hash=:p, must_change_password=1 WHERE id=:id')
                        ->execute([':u' => $employeeId, ':n' => $name, ':e' => $email, ':ref' => $employeeId,
                            ':status' => $status, ':p' => password_hash($tempPassword, PASSWORD_DEFAULT), ':id' => $existingLogin['id']]);
                    $newLoginUserId = (int)$existingLogin['id'];
                } else {
                    $pdo->prepare('UPDATE users SET username=:u, full_name=:n, email=:e, ref_id=:ref, status=:status WHERE id=:id')
                        ->execute([':u' => $employeeId, ':n' => $name, ':e' => $email, ':ref' => $employeeId,
                            ':status' => $status, ':id' => $existingLogin['id']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            json_out(['ok' => false, 'message' => 'That employee ID or email is already in use.'], 422);
        }
        log_api_error('adviser_save', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The adviser record could not be saved. Please try again.'], 500);
    }

    if ($newLoginUserId && $status === 'Active') {
        $setupDelivery = send_account_setup_email($pdo, $newLoginUserId, $email, $name);
    }
    log_activity($user['email'], 'adviser_saved', "employee_id=$employeeId");
    $response = ['ok' => true, 'id' => $id, 'message' => 'Adviser record saved.'];
    if ($newLoginUserId && $status === 'Active') {
        $response['accountCreated'] = true;
        $response = array_merge($response, account_setup_response_fields($setupDelivery, $newLoginTempPassword));
    }
    json_out($response);
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    try {
        $pdo->beginTransaction();
        // Student writes take student locks first; use the same order when unassigning.
        $assigned = $pdo->prepare('SELECT id FROM students WHERE adviser_id = :id ORDER BY id FOR UPDATE');
        $assigned->execute([':id' => $id]);
        $assigned->fetchAll();
        $row = $pdo->prepare('SELECT email, department FROM advisers WHERE id = :id FOR UPDATE');
        $row->execute([':id' => $id]);
        $before = $row->fetch();
        if (!$before) {
            $pdo->rollBack();
            json_out(['ok' => false, 'message' => 'Adviser record not found.'], 404);
        }
        $adviserEmail = (string)$before['email'];
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
