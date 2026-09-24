<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['mark_read', 'mark_all_read', 'recipients_preview', 'send'], true)) {
    require_post_same_origin();
}

if ($action === 'list') {
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE recipient_email = :e
            ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([':e' => $user['email']]);
    } elseif ($user['role'] === 'admin') {
        $stmt = $pdo->query('SELECT * FROM notifications ORDER BY created_at DESC LIMIT 200');
    } else {
        $stmt = $pdo->prepare('SELECT n.* FROM notifications n
            LEFT JOIN students s ON n.recipient_type = "student" AND n.recipient_id = s.id
            LEFT JOIN advisers a ON a.id = s.adviser_id
            WHERE (n.recipient_email = :self)
               OR (n.recipient_type = "student" AND a.email = :self)
            ORDER BY n.created_at DESC LIMIT 200');
        $stmt->execute([':self' => $user['email']]);
    }
    json_out(['ok' => true, 'notifications' => $stmt->fetchAll()]);
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

    if ($message === '') {
        json_out(['ok' => false, 'message' => 'A message is required.'], 422);
    }

    $recipients = resolve_recipients($pdo, $user, $audience, $group);
    if (!$recipients) {
        json_out(['ok' => false, 'message' => 'No matching recipients were found for that audience.'], 422);
    }

    $isScheduled = $automated && $scheduleAt !== '' && strtotime($scheduleAt) > time();
    $subject = "PRISM $type - CEU Malolos RPMS";
    $sentCount = 0;

    foreach ($recipients as $recipient) {
        $status = $isScheduled ? 'Scheduled' : 'Sent';
        $delivery = null;
        $sentAt = null;

        if (!$isScheduled) {
            $body = "Hello {$recipient['name']},\n\n{$message}\n\n"
                . "This is an automated notification from the CEU Malolos Research Planning and Monitoring Section (RPMS) via PRISM.\n";
            $result = send_notification_email($recipient['email'], $subject, $body);
            $delivery = $result['message'];
            $sentAt = date('Y-m-d H:i:s');
            if ($result['ok']) {
                $sentCount++;
            } else {
                $status = 'Failed';
            }
        }

        $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
            subject, message, type, status, delivery_info, scheduled_at, sent_at, created_by)
            VALUES (:rt,:rid,:re,:rn,:subj,:msg,:type,:status,:delivery,:sched,:sent,:by)')
            ->execute([
                ':rt' => $recipient['type'], ':rid' => $recipient['id'], ':re' => $recipient['email'],
                ':rn' => $recipient['name'], ':subj' => $subject, ':msg' => $message, ':type' => $type,
                ':status' => $status, ':delivery' => $delivery,
                ':sched' => $isScheduled ? date('Y-m-d H:i:s', strtotime($scheduleAt)) : null,
                ':sent' => $sentAt, ':by' => $user['full_name'],
            ]);
    }

    log_activity($user['email'], 'notification_sent', "audience=$audience type=$type recipients=" . count($recipients));
    json_out(['ok' => true, 'sent' => $sentCount, 'total' => count($recipients), 'scheduled' => $isScheduled]);
}

function resolve_recipients(PDO $pdo, array $user, string $audience, string $group): array
{
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
            $sql .= ' AND s.research_group = :g';
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
            $sql .= ' WHERE research_group = :g';
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
