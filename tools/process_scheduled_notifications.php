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
require_once dirname(__DIR__) . '/includes/notification_delivery.php';

$pdo = db();
$processed = 0;
$sent = 0;
$failed = 0;
$logged = 0;
$leaseCutoff = date('Y-m-d H:i:s', time() - 900);

// Keep each claim atomic so overlapping cron invocations cannot send the same row twice.
$stmt = $pdo->prepare("SELECT id FROM notifications
    WHERE (status = 'Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW())
       OR (status = 'Sending' AND sending_started_at IS NOT NULL AND sending_started_at < :stale)
    ORDER BY scheduled_at ASC, id ASC
    LIMIT 20");
$stmt->execute([':stale' => $leaseCutoff]);
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

foreach ($ids as $id) {
    // Hold the delivery mutex through the provider call and final status update.
    // Recovery cannot race a slow live worker, even after the lease has expired.
    $mutexName = 'prism_notification_' . $id;
    $mutex = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $mutex->execute([':name' => $mutexName]);
    if ((int)$mutex->fetchColumn() !== 1) continue;
    try {
    $claim = $pdo->prepare("UPDATE notifications
        SET status = 'Sending', sending_started_at = NOW()
        WHERE id = :id
          AND ((status = 'Scheduled' AND scheduled_at <= NOW())
            OR (status = 'Sending' AND sending_started_at IS NOT NULL AND sending_started_at < :stale))");
    $claim->execute([':id' => $id, ':stale' => $leaseCutoff]);
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

    $result = deliver_notification_email($pdo, $id, (string)$row['recipient_email'],
        (string)$row['subject'], $body, true);

    $processed++;
    if ($result['ok'] && ($result['channel'] ?? '') !== 'log') {
        $sent++;
    } elseif ($result['ok']) {
        $logged++;
    } elseif (!$result['ok']) {
        $failed++;
    }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $mutexName]);
    }
}

echo "Processed: $processed; sent: $sent; logged: $logged; failed: $failed\n";
