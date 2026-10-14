<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notification_delivery.php';
$user = api_require_login(['admin','adviser']);
require_post_same_origin();

$payload = json_body();
$studentDbId = (int)($payload['studentDbId'] ?? 0);
if ($studentDbId <= 0) {
    json_out(['ok' => false, 'message' => 'Select a valid student before sending a follow-up.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT s.id, s.student_id, s.full_name, s.email, s.stage, s.status, s.requirements, s.archived_at,
        a.email AS adviser_email
    FROM students s
    LEFT JOIN advisers a ON a.id = s.adviser_id
    WHERE s.id = :id AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL LIMIT 1');
$stmt->execute([':id' => $studentDbId]);
$student = $stmt->fetch();
if (!$student) {
    json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
}
if ($user['role'] === 'adviser' && (!empty($student['archived_at']) || strcasecmp((string)$student['adviser_email'], (string)$user['email']) !== 0)) {
    json_out(['ok' => false, 'message' => 'You can only send follow-ups to students assigned to you.'], 403);
}

$email = (string)($student['email'] ?? '');

$safeName = preg_replace('/[\r\n]+/', ' ', (string)$student['full_name']);
$studentId = preg_replace('/[\r\n]+/', ' ', (string)$student['student_id']);
$stage = (string)$student['stage'];
$status = (string)$student['status'];
$requirements = trim((string)$student['requirements']);

$subject = "IERB Progress Follow-up - {$studentId}";
$message = "Hello {$safeName},\n\nThis is an automated follow-up regarding your IERB progress.\n"
    . "Current stage: " . stage_label($stage) . " ({$stage})\nStatus: {$status}\n";
if ($requirements !== '') {
    $message .= "Pending requirement: {$requirements}\n";
}
$message .= "\nPlease send your latest update to the RPMS office.\n\nThank you.";

// The early read builds the message; authority must still hold on the locked current record.
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id=? FOR UPDATE');
    $stmt->execute([$studentDbId]);
    $current = $stmt->fetch();
    if (!$current || !empty($current['archived_at']) || empty($current['profile_completed_at'])
        || (string)$current['email'] !== $email || (string)$current['full_name'] !== (string)$student['full_name']) {
        throw new NotificationLifecycleError('Student record changed. Refresh before sending a follow-up.', 409);
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
    $stmt->execute([$user['id']]);
    $actor = $stmt->fetch();
    if (!$actor || $actor['role'] !== $user['role'] || strcasecmp((string)$actor['status'], 'Active') !== 0
        || $actor['email'] !== $user['email'] || !hash_equals((string)$actor['password_hash'], (string)$user['password_hash'])
        || !empty($actor['must_change_password'])) {
        throw new NotificationLifecycleError('Your account changed. Sign in again before sending a follow-up.', 403);
    }
    if ($user['role'] === 'adviser') {
        $stmt = $pdo->prepare('SELECT email,status,archived_at,profile_completed_at FROM advisers WHERE id=? FOR UPDATE');
        $stmt->execute([$current['adviser_id']]);
        $assigned = $stmt->fetch();
        if (!$assigned || strcasecmp((string)$assigned['email'], (string)$actor['email']) !== 0
            || strcasecmp((string)$assigned['status'], 'Active') !== 0 || !empty($assigned['archived_at'])
            || empty($assigned['profile_completed_at'])) {
            throw new NotificationLifecycleError('You can only send follow-ups to students currently assigned to you.', 403);
        }
    }
    foreach (['stage','status','requirements','student_id'] as $field) {
        if ((string)$current[$field] !== (string)$student[$field]) {
            throw new NotificationLifecycleError('Student progress changed. Refresh before sending a follow-up.', 409);
        }
    }
    $result = create_notification($pdo, 'student', $studentDbId, $email, $safeName,
        $subject, $message, 'Follow-up', $actor['full_name'], null, date('Y-m-d H:i:s'), null, 'staff');
    if (!isset($result['id'])) throw new NotificationLifecycleError('Student record changed. Refresh before sending a follow-up.', 409);
    $pdo->commit();
} catch (NotificationLifecycleError $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'message'=>$error->getMessage()], $error->status);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_api_error('followup_persistence', 'Follow-up could not be saved.');
    json_out(['ok'=>false,'message'=>'Follow-up could not be saved. Please try again.'], 503);
}
$result = notification_dispatch($pdo, (int)$result['id'], null, [$subject, $message]);

log_activity($user['email'], 'followup_sent', "student=$studentId");

if (!$result['ok']) {
    json_out(['ok' => true, 'message' => 'Follow-up saved in-app. Email delivery failed: ' . $result['message']]);
}
json_out(['ok' => true, 'message' => $result['message']]);
