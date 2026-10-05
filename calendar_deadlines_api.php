<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/calendar_deadlines.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$action = $_GET['action'] ?? 'list';
if (!is_string($action)) json_out(['ok' => false, 'message' => 'Invalid action.'], 422);
if (in_array($action, ['create', 'cancel', 'update', 'delete'], true)) {
    api_require_login(['admin', 'adviser']);
    require_post_same_origin();
}
$pdo = db();
try {
    if ($action === 'group_options') {
        api_require_login(['admin', 'adviser']);
        json_out(['ok' => true, 'groups' => research_group_options($pdo, $user)]);
    }
    if ($action === 'list' || $action === 'dates') {
        $from = deadline_date($_GET['from'] ?? date('Y-m-d'));
        $to = deadline_date($_GET['to'] ?? $from);
        $days = (strtotime($to) - strtotime($from)) / 86400;
        if ($days < 0 || $days > 62) throw new InvalidArgumentException('Choose a calendar range of no more than 63 days.');
        if (isset($_GET['page']) && !is_scalar($_GET['page'])) throw new InvalidArgumentException('Invalid page.');
        if (isset($_GET['manage']) && !in_array($_GET['manage'], ['0', '1'], true)) throw new InvalidArgumentException('Invalid management view.');
        [$scope, $params] = deadline_scope($pdo, $user, ($_GET['manage'] ?? '0') === '1');
        $scope .= ' AND d.deadline_date BETWEEN :from AND :to';
        $params[':from'] = $from; $params[':to'] = $to;
        if ($action === 'dates') {
            $stmt = $pdo->prepare('SELECT d.deadline_date, COUNT(*) AS total ' . $scope . ' GROUP BY d.deadline_date');
            $stmt->execute($params);
            json_out(['ok' => true, 'dates' => $stmt->fetchAll()]);
        }
        $page = prism_page_query($pdo, 'SELECT d.id, d.creator_user_id, d.title, d.description, d.deadline_date,
            d.target_scope, d.status, d.created_at, d.updated_at', $scope, $params, 'd.deadline_date ASC, d.id DESC',
            ['page' => $_GET['page'] ?? 1]);
        foreach ($page['rows'] as &$row) {
            $row['canCancel'] = $row['status'] === 'Active' && ($user['role'] === 'admin'
                || ($user['role'] === 'adviser' && (int)$row['creator_user_id'] === (int)$user['id']));
            // Do not disclose other targeted group identifiers to readers.
            $row['groups'] = [];
            if ($user['role'] === 'admin' || ($user['role'] === 'adviser' && (int)$row['creator_user_id'] === (int)$user['id'])) {
                $stmt = $pdo->prepare('SELECT research_group FROM calendar_deadline_groups WHERE deadline_id = :id ORDER BY research_group');
                $stmt->execute([':id' => $row['id']]); $row['groups'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            unset($row['creator_user_id']);
        }
        unset($row);
        json_out(['ok' => true, 'deadlines' => $page['rows'], 'total' => $page['total'], 'page' => $page['page'],
            'limit' => 10, 'pages' => max(1, (int)ceil($page['total'] / 10))]);
    }
    if ($action === 'create') {
        $data = json_body();
        $title = $data['title'] ?? ''; $description = $data['description'] ?? '';
        if (!is_string($title) || !is_string($description) || trim($title) === ''
            || mb_strlen(trim($title)) > 190 || mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('Enter a title (up to 190 characters) and optional description (up to 2000 characters).');
        }
        $date = deadline_date($data['date'] ?? null);
        $pdo->beginTransaction();
        [$target, $groups] = deadline_targets($pdo, $user, $data);
        $stmt = $pdo->prepare('INSERT INTO calendar_deadlines (creator_user_id, title, description, deadline_date, target_scope)
            VALUES (:creator, :title, :description, :date, :scope)');
        $stmt->execute([':creator' => $user['id'], ':title' => trim($title), ':description' => trim($description),
            ':date' => $date, ':scope' => $target]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO calendar_deadline_groups (deadline_id, research_group) VALUES (:id, :group)');
        foreach ($groups as $group) $stmt->execute([':id' => $id, ':group' => $group]);
        $pdo->commit();
        log_activity($user['email'], 'deadline_created', 'Official deadline #' . $id);
        $deadline = ['id' => $id, 'title' => trim($title), 'description' => trim($description), 'deadline_date' => $date,
            'target_scope' => $target, 'status' => 'Active'];
        $delivery = deadline_notify($pdo, $user, $deadline, $groups);
        json_out(['ok' => true, 'id' => $id, 'message' => 'Official deadline created.', 'delivery' => $delivery]);
    }
    if ($action === 'cancel') {
        $id = deadline_id(json_body()['id'] ?? null);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM calendar_deadlines WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]); $deadline = $stmt->fetch();
        if (!$deadline || ($user['role'] !== 'admin' && (int)$deadline['creator_user_id'] !== (int)$user['id'])) {
            throw new DomainException('You cannot manage this official deadline.');
        }
        $changed = $deadline['status'] !== 'Cancelled';
        if ($changed) $pdo->prepare("UPDATE calendar_deadlines SET status = 'Cancelled' WHERE id = :id")->execute([':id' => $id]);
        $stmt = $pdo->prepare('SELECT research_group FROM calendar_deadline_groups WHERE deadline_id = :id');
        $stmt->execute([':id' => $id]); $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->commit();
        if ($changed) log_activity($user['email'], 'deadline_cancelled', 'Official deadline #' . $id);
        $deadline['status'] = 'Cancelled';
        $delivery = $changed ? deadline_notify($pdo, $user, $deadline, $groups) : null;
        json_out(['ok' => true, 'message' => 'Official deadline cancelled.', 'delivery' => $delivery]);
    }
    json_out(['ok' => false, 'message' => 'Unsupported deadline action.'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof InvalidArgumentException || $e instanceof DomainException) {
        json_out(['ok' => false, 'message' => $e->getMessage()], $e instanceof DomainException ? 403 : 422);
    }
    log_api_error('calendar_deadlines', $e->getMessage());
    json_out(['ok' => false, 'message' => 'Could not save or load official deadlines.'], 500);
}
