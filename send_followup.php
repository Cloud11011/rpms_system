<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin','adviser']);
require_post_same_origin();

$payload = json_body();
$studentDbId = (int)($payload['studentDbId'] ?? 0);
if ($studentDbId <= 0) {
    json_out(['ok' => false, 'message' => 'Select a valid student before sending a follow-up.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT s.id, s.student_id, s.full_name, s.email, s.stage, s.status, s.requirements,
        a.email AS adviser_email
    FROM students s
    LEFT JOIN advisers a ON a.id = s.adviser_id
    WHERE s.id = :id LIMIT 1');
$stmt->execute([':id' => $studentDbId]);
$student = $stmt->fetch();
if (!$student) {
    json_out(['ok' => false, 'message' => 'Student record not found.'], 404);
}
if ($user['role'] === 'adviser' && strcasecmp((string)$student['adviser_email'], (string)$user['email']) !== 0) {
    json_out(['ok' => false, 'message' => 'You can only send follow-ups to students assigned to you.'], 403);
}

$email = filter_var($student['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    json_out(['ok' => false, 'message' => 'This student does not have a valid email address.'], 422);
}

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

$result = send_notification_email($email, $subject, $message);

$pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
    subject, message, type, status, delivery_info, sent_at, created_by)
    VALUES ("student",:sid,:email,:name,:subj,:msg,"Follow-up",:status,:info,NOW(),:by)')
    ->execute([
        ':sid' => $studentDbId, ':email' => $email, ':name' => $safeName, ':subj' => $subject,
        ':msg' => $message, ':status' => $result['ok'] ? (($result['channel'] ?? '') === 'log' ? 'Logged' : 'Sent') : 'Failed', ':info' => $result['message'],
        ':by' => $user['full_name'],
    ]);

log_activity($user['email'], 'followup_sent', "student=$studentId");

if (!$result['ok']) {
    json_out(['ok' => false, 'message' => $result['message']], 503);
}
json_out(['ok' => true, 'message' => $result['message']]);
