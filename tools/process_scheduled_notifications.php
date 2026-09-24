<?php
/**
 * CLI/cron worker for due PRISM notifications.
 *
 * Example Hostinger cron command:
 *   php /full/path/to/prism/tools/process_scheduled_notifications.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/config.php';

$pdo = db();
$processed = 0;
$sent = 0;
$failed = 0;

// Keep each claim atomic so overlapping cron invocations cannot send the same row twice.
$stmt = $pdo->query("SELECT id FROM notifications
    WHERE status = 'Scheduled'
      AND scheduled_at IS NOT NULL
      AND scheduled_at <= NOW()
    ORDER BY scheduled_at ASC, id ASC
    LIMIT 100");
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

foreach ($ids as $id) {
    $claim = $pdo->prepare("UPDATE notifications
        SET status = 'Sending'
        WHERE id = :id
          AND status = 'Scheduled'
          AND scheduled_at <= NOW()");
    $claim->execute([':id' => $id]);
    if ($claim->rowCount() !== 1) {
        continue;
    }

    $rowStmt = $pdo->prepare('SELECT * FROM notifications WHERE id = :id');
    $rowStmt->execute([':id' => $id]);
    $row = $rowStmt->fetch();
    if (!$row) {
        continue;
    }

    $body = "Hello " . ($row['recipient_name'] ?: 'there') . ",\n\n"
        . $row['message'] . "\n\n"
        . "This is an automated notification from the CEU Malolos Research Planning and Monitoring Section (RPMS) via PRISM.\n";

    $result = send_notification_email((string)$row['recipient_email'], (string)$row['subject'], $body);
    $status = $result['ok'] ? (($result['channel'] ?? '') === 'log' ? 'Logged' : 'Sent') : 'Failed';
    $upd = $pdo->prepare('UPDATE notifications
        SET status = :status, delivery_info = :info, sent_at = :sent
        WHERE id = :id AND status = "Sending"');
    $upd->execute([
        ':status' => $status,
        ':info' => $result['message'] ?? '',
        ':sent' => $result['ok'] ? date('Y-m-d H:i:s') : null,
        ':id' => $id,
    ]);

    $processed++;
    if ($result['ok'] && ($result['channel'] ?? '') !== 'log') {
        $sent++;
    } elseif (!$result['ok']) {
        $failed++;
    }
}

echo "Processed: $processed; sent: $sent; failed: $failed\n";
