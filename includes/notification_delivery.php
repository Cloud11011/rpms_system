<?php
/** Shared notification persistence and best-effort email delivery. Config is loaded by the caller. */

/** Deliver an already persisted notification; mail failures must never escape into a workflow. */
function deliver_notification_email(PDO $pdo, int $id, string $email, string $subject, string $body,
                                    bool $scheduled = false): array
{
    try {
        $result = send_notification_email($email, $subject, $body);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'message' => $e->getMessage()];
    }
    $status = !empty($result['ok']) ? (($result['channel'] ?? '') === 'log' ? 'Logged' : 'Sent') : 'Failed';
    $info = (string)($result['message'] ?? 'Email delivery failed.');
    try {
        $pdo->prepare('UPDATE notifications SET status = :status, delivery_info = :info, sent_at = :sent
            WHERE id = :id' . ($scheduled ? ' AND status = "Sending"' : ''))
            ->execute([
                ':status' => $status, ':info' => $info,
                // Preserve the worker's successful-delivery timestamp and immediate attempt timestamp.
                ':sent' => !$scheduled || !empty($result['ok']) ? date('Y-m-d H:i:s') : null,
                ':id' => $id,
            ]);
    } catch (Throwable $e) {
        log_api_error('notification_delivery_status', $e->getMessage());
    }
    return $result + ['status' => $status, 'message' => $info];
}

/** Save the in-app record before attempting email, or leave it Scheduled for the worker. */
function create_notification(PDO $pdo, string $recipientType, ?int $recipientId, string $email, string $name,
                             string $subject, string $message, string $type, string $createdBy,
                             ?string $emailBody = null, ?string $scheduledAt = null, ?string $emailSubject = null): array
{
    $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
        subject, message, type, status, delivery_info, scheduled_at, sent_at, created_by)
        VALUES (:rt,:rid,:re,:rn,:subj,:msg,:type,:status,:delivery,:sched,:sent,:by)')
        ->execute([
            ':rt' => $recipientType, ':rid' => $recipientId, ':re' => $email, ':rn' => $name,
            ':subj' => $subject, ':msg' => $message, ':type' => $type,
            ':status' => $scheduledAt !== null ? 'Scheduled' : 'Failed',
            ':delivery' => $scheduledAt !== null ? null : 'Email delivery has not completed.',
            ':sched' => $scheduledAt, ':sent' => null, ':by' => $createdBy,
        ]);
    if ($scheduledAt !== null) {
        return ['ok' => true, 'status' => 'Scheduled', 'message' => 'Notification scheduled.'];
    }
    return deliver_notification_email($pdo, (int)$pdo->lastInsertId(), $email,
        $emailSubject ?? $subject, $emailBody ?? $message);
}
