<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin','adviser']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$payload = json_body();
$email = filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim((string)($payload['name'] ?? 'Student'));
$studentDbId = (int)($payload['studentDbId'] ?? 0);
$studentId = preg_replace('/[\r\n]+/', ' ', trim((string)($payload['studentId'] ?? '')));
$stage = trim((string)($payload['stage'] ?? ''));
$status = trim((string)($payload['status'] ?? ''));
$requirements = trim((string)($payload['requirements'] ?? ''));

if (!$email) {
    json_out(['ok' => false, 'message' => 'A valid student email is required.'], 422);
}

$safeName = preg_replace('/[\r\n]+/', ' ', $name);
$subject = "IERB Progress Follow-up - {$studentId}";
$message = "Hello {$safeName},\n\nThis is an automated follow-up regarding your IERB progress.\nCurrent stage: {$stage}\nStatus: {$status}\n";
if ($requirements !== '') {
    $message .= "Pending requirement: {$requirements}\n";
}
$message .= "\nPlease send your latest update to the RPMS office.\n\nThank you.";

$result = send_notification_email($email, $subject, $message);

db()->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
    subject, message, type, status, delivery_info, sent_at, created_by)
    VALUES ("student",:sid,:email,:name,:subj,:msg,"Follow-up",:status,:info,NOW(),:by)')
    ->execute([
        ':sid' => $studentDbId ?: null, ':email' => $email, ':name' => $safeName, ':subj' => $subject,
        ':msg' => $message, ':status' => $result['ok'] ? 'Sent' : 'Failed', ':info' => $result['message'],
        ':by' => $user['full_name'],
    ]);

log_activity($user['email'], 'followup_sent', "student=$studentId");

if (!$result['ok']) {
    json_out(['ok' => false, 'message' => $result['message']], 503);
}
json_out(['ok' => true, 'message' => $result['message']]);
