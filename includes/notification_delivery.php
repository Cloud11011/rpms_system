<?php
/** Shared notification persistence and best-effort email delivery. Config is loaded by the caller. */
require_once __DIR__.'/student_snapshot.php';

/** Deliver an already persisted notification; mail failures must never escape into a workflow. */
function deliver_notification_email(PDO $pdo, int $id, string $email, string $subject, string $body,
                                    bool $scheduled = false): array
{
    // Immediate claimed delivery and cron share a mutex, including during lease recovery.
    $mutexName = 'prism_notification_' . $id;
    {
        $mutex = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $mutex->execute([':name'=>$mutexName]);
        if ((int)$mutex->fetchColumn() !== 1) return ['ok'=>true, 'status'=>'Skipped', 'message'=>'Delivery is locked by another worker or purge.'];
    }
    try {
    $current=$pdo->prepare('SELECT status FROM notifications WHERE id=?'); $current->execute([$id]);
    $currentStatus=$current->fetchColumn();
    if ($currentStatus===false || $currentStatus==='Cancelled') return ['ok'=>true,'status'=>'Skipped','message'=>'Notification was removed or cancelled.'];
    if (!$scheduled) $pdo->prepare('UPDATE notifications SET status="Sending",sending_started_at=NOW() WHERE id=?')->execute([$id]);
    try {
        $result = send_notification_email($email, $subject, $body);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'message' => $e->getMessage()];
    }
    $status = !empty($result['ok']) ? (($result['channel'] ?? '') === 'log' ? 'Logged' : 'Sent') : 'Failed';
    $info = (string)($result['message'] ?? 'Email delivery failed.');
    try {
        $pdo->prepare('UPDATE notifications SET status = :status, delivery_info = :info, sent_at = :sent, sending_started_at = NULL
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
    } finally {
        {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name'=>$mutexName]);
        }
    }
}

/** Save the in-app record before attempting email, or leave it Scheduled for the worker. */
function create_notification(PDO $pdo, string $recipientType, ?int $recipientId, string $email, string $name,
                             string $subject, string $message, string $type, string $createdBy,
                             ?string $emailBody = null, ?string $scheduledAt = null, ?string $emailSubject = null): array
{
    $ownedTransaction=!$pdo->inTransaction();
    if($ownedTransaction) $pdo->beginTransaction();
    try {
    if(!notification_lock_recipients($pdo,[['type'=>$recipientType,'id'=>$recipientId,'email'=>$email,'name'=>$name]])) {
        if($ownedTransaction) $pdo->commit();
        return ['ok'=>true,'status'=>'Skipped','message'=>'Recipient is no longer current.'];
    }
    // A caller-owned transaction must queue delivery; it may not call a provider while holding locks.
    $deferred=$scheduledAt!==null || !$ownedTransaction;
    $persistSchedule=$scheduledAt ?? (!$ownedTransaction?date('Y-m-d H:i:s'):null);
    $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
        subject, message, type, status, delivery_info, scheduled_at, sent_at, created_by)
        VALUES (:rt,:rid,:re,:rn,:subj,:msg,:type,:status,:delivery,:sched,:sent,:by)')
        ->execute([
            ':rt' => $recipientType, ':rid' => $recipientId, ':re' => $email, ':rn' => $name,
            ':subj' => $subject, ':msg' => $message, ':type' => $type,
            ':status' => $deferred ? 'Scheduled' : 'Failed',
            ':delivery' => $deferred ? null : 'Email delivery has not completed.',
            ':sched' => $persistSchedule, ':sent' => null, ':by' => $createdBy,
        ]);
    $notificationId=(int)$pdo->lastInsertId();
    if($ownedTransaction) $pdo->commit();
    } catch(Throwable $error) {
        if($ownedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if ($deferred) {
        return ['ok' => true, 'status' => 'Scheduled', 'message' => 'Notification scheduled.'];
    }
    return deliver_notification_email($pdo, $notificationId, $email,
        $emailSubject ?? $subject, $emailBody ?? $message);
}
