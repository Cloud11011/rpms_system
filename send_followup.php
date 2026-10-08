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
    WHERE s.id = :id LIMIT 1');
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

$result = create_notification($pdo, 'student', $studentDbId, $email, $safeName,
    $subject, $message, 'Follow-up', $user['full_name']);

log_activity($user['email'], 'followup_sent', "student=$studentId");

if (!$result['ok']) {
    json_out(['ok' => true, 'message' => 'Follow-up saved in-app. Email delivery failed: ' . $result['message']]);
}
json_out(['ok' => true, 'message' => $result['message']]);
