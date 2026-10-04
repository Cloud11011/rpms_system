<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notification_delivery.php';
require_once __DIR__ . '/includes/research_groups.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['mark_read', 'mark_all_read', 'recipients_preview', 'send'], true)) {
    require_post_same_origin();
}

if ($action === 'list') {
    $scope = ' FROM notifications n';
    $params = [];
    if ($user['role'] === 'student') {
        $scope .= ' WHERE n.recipient_email = :e';
        $params[':e'] = $user['email'];
    } elseif ($user['role'] === 'adviser') {
        $scope .= ' LEFT JOIN students s ON n.recipient_type = "student" AND n.recipient_id = s.id
            LEFT JOIN advisers a ON a.id = s.adviser_id
            WHERE (n.recipient_email = :self)
               OR (n.recipient_type = "student" AND a.email = :self)';
        $params[':self'] = $user['email'];
    }
    if (isset($_GET['preview']) && !empty($_GET['personal'])) {
        $scope = ' FROM notifications n WHERE n.recipient_email = :personal';
        $params = [':personal' => $user['email']];
    }
    $count = $pdo->prepare('SELECT COUNT(*)' . $scope);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $limit = isset($_GET['preview']) ? max(1, min(5, (int)$_GET['preview'])) : 10;
    $pages = max(1, (int)ceil($total / $limit));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare('SELECT n.*' . $scope . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($params);
    json_out(['ok' => true, 'notifications' => $stmt->fetchAll(), 'total' => $total,
        'page' => $page, 'pages' => $pages, 'limit' => $limit, 'offset' => $offset]);
}

if ($action === 'mark_read') {
    $data = json_body();
    $id = (int)($data['id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE notifications SET read_at = NOW()
        WHERE id = :id AND recipient_email = :e');
    $stmt->execute([':id' => $id, ':e' => $user['email']]);
    json_out(['ok' => true]);
}

if ($action === 'mark_all_read') {
    $pdo->prepare('UPDATE notifications SET read_at = NOW()
        WHERE recipient_email = :e AND read_at IS NULL')->execute([':e' => $user['email']]);
    json_out(['ok' => true]);
}

// Sending is an RPMS (and, for follow-ups only, research adviser) responsibility.
api_require_login(['admin', 'adviser']);
if ($action === 'group_options') {
    json_out(['ok' => true, 'groups' => research_group_options($pdo, $user)]);
}
$data = json_body();

if ($action === 'recipients_preview') {
    $audience = $data['audience'] ?? 'All Students';
    $group = trim((string)($data['group'] ?? ''));
    json_out(['ok' => true, 'recipients' => resolve_recipients($pdo, $user, $audience, $group)]);
}

if ($action === 'send') {
    $audience = trim((string)($data['audience'] ?? 'All Students'));
    $group = trim((string)($data['group'] ?? ''));
    $type = preg_replace('/[\r\n]+/', ' ', trim((string)($data['type'] ?? 'Status Update')));
    $message = trim((string)($data['message'] ?? ''));
    $automated = !empty($data['automated']);
    $scheduleAt = trim((string)($data['scheduleAt'] ?? ''));

    $allowedAudiences = ['All Students', 'All Advisers', 'Students and Advisers', 'Specific Research Group'];
    $allowedTypes = ['Status Update', 'Reminder', 'Follow-up', 'Submission Confirmation'];
    if (!in_array($audience, $allowedAudiences, true)) {
        json_out(['ok' => false, 'message' => 'Invalid notification audience.'], 422);
    }
    if (!in_array($type, $allowedTypes, true)) {
        json_out(['ok' => false, 'message' => 'Invalid notification type.'], 422);
    }
    if ($user['role'] === 'adviser' && !in_array($audience, ['All Students', 'Specific Research Group'], true)) {
        json_out(['ok' => false, 'message' => 'Research advisers may notify only their assigned students.'], 403);
    }
    if ($message === '') {
        json_out(['ok' => false, 'message' => 'A message is required.'], 422);
    }
    if (mb_strlen($message) > 600) {
        json_out(['ok' => false, 'message' => 'Notification messages must be 600 characters or fewer.'], 422);
    }
    if ($audience === 'Specific Research Group' && $group === '') {
        json_out(['ok' => false, 'message' => 'Choose a research group for this audience.'], 422);
    }
    if ($automated) {
        $scheduleTs = $scheduleAt !== '' ? strtotime($scheduleAt) : false;
        if ($scheduleTs === false || $scheduleTs <= time()) {
            json_out(['ok' => false, 'message' => 'Choose a valid future date and time for the scheduled notification.'], 422);
        }
    }

    $recipients = resolve_recipients($pdo, $user, $audience, $group);
    if (!$recipients) {
        json_out(['ok' => false, 'message' => 'No matching recipients were found for that audience.'], 422);
    }

    $isScheduled = $automated;
    $subject = "PRISM $type - CEU Malolos RPMS";
    $sentCount = 0;
    $loggedCount = 0;

    foreach ($recipients as $recipient) {
        $body = "Hello {$recipient['name']},\n\n{$message}\n\n"
            . "This is an automated notification from the CEU Malolos Research Planning and Monitoring Section (RPMS) via PRISM.\n";
        $result = create_notification($pdo, $recipient['type'], (int)$recipient['id'],
            (string)($recipient['email'] ?? ''), (string)$recipient['name'], $subject, $message, $type,
            $user['full_name'], $body, $isScheduled ? date('Y-m-d H:i:s', strtotime($scheduleAt)) : null);
        if ($result['status'] === 'Sent') $sentCount++;
        if ($result['status'] === 'Logged') $loggedCount++;
    }

    log_activity($user['email'], 'notification_sent', "audience=$audience type=$type recipients=" . count($recipients));
    json_out(['ok' => true, 'sent' => $sentCount, 'logged' => $loggedCount,
        'total' => count($recipients), 'scheduled' => $isScheduled]);
}

function resolve_recipients(PDO $pdo, array $user, string $audience, string $group): array
{
    if ($audience === 'Specific Research Group' && !in_array($group, research_group_options($pdo, $user), true)) {
        json_out(['ok' => false, 'message' => 'Choose an existing research group in your permitted scope.'], 422);
    }
    $recipients = [];

    if ($user['role'] === 'adviser') {
        // Advisers may only notify their own assigned students. Institution-wide audiences are admin-only.
        if (!in_array($audience, ['All Students', 'Specific Research Group'], true)) {
            return [];
        }
        $sql = 'SELECT s.id, s.full_name, s.email, s.research_group
            FROM students s JOIN advisers a ON a.id = s.adviser_id
            WHERE a.email = :adv';
        $params = [':adv' => $user['email']];
        if ($audience === 'Specific Research Group') {
            if ($group === '') return [];
            $sql .= ' AND BINARY s.research_group = BINARY :g';
            $params[':g'] = $group;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $s) {
            $recipients[] = ['type' => 'student', 'id' => $s['id'], 'name' => $s['full_name'], 'email' => $s['email']];
        }
        return $recipients;
    }

    if (in_array($audience, ['All Students', 'Students and Advisers', 'Specific Research Group'], true)) {
        $sql = 'SELECT id, full_name, email, research_group FROM students';
        $params = [];
        if ($audience === 'Specific Research Group') {
            if ($group === '') return [];
            $sql .= ' WHERE BINARY research_group = BINARY :g';
            $params[':g'] = $group;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $s) {
            $recipients[] = ['type' => 'student', 'id' => $s['id'], 'name' => $s['full_name'], 'email' => $s['email']];
        }
    }
    if (in_array($audience, ['All Advisers', 'Students and Advisers'], true)) {
        foreach ($pdo->query('SELECT id, full_name, email FROM advisers WHERE status = "Active"')->fetchAll() as $f) {
            $recipients[] = ['type' => 'adviser', 'id' => $f['id'], 'name' => $f['full_name'], 'email' => $f['email']];
        }
    }
    return $recipients;
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
