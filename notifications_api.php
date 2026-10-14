<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/notification_delivery.php';
require_once __DIR__ . '/includes/research_groups.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';
$mutations=['mark_read','mark_all_read','archive','restore','delete','campaign_archive','campaign_restore','campaign_delete','campaign_cancel','recipients_preview','send','support_respond'];
if (in_array($action,$mutations,true)) {
    require_post_same_origin();
    require_session_generation(true);
}
if (in_array($action,['support_context','support_respond'],true)) {
    api_require_login('admin');
    require_session_generation(true);
    try {
        if ($action==='support_respond') json_out(support_respond($pdo,$user,json_body()));
        $target=support_response_target($pdo,$user,support_id($_GET['id']??null));
        json_out(['ok'=>true,'recipient_name'=>$target['user']['full_name'],
            'subject'=>$target['request']['subject'],'category'=>$target['request']['support_category']]);
    } catch (NotificationLifecycleError $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],$e->status); }
    catch (Throwable $e) {
        log_api_error('support_response','Support response could not complete.');
        json_out(['ok'=>false,'message'=>'Support response is temporarily unavailable. Please try again.'],503);
    }
}
try {
    if (in_array($action,['list','sent_history','legacy_history'],true)) json_out(notification_list($pdo,$user,$_GET,$action));
    if ($action==='mark_all_read') json_out(notification_mark_all_read($pdo,$user));
    if (in_array($action,['mark_read','archive','restore','delete','campaign_archive','campaign_restore','campaign_delete','campaign_cancel'],true)) {
        $data=json_body();
        $id=$data['id']??null;
        if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id) || (int)$id>2147483647) {
            throw new NotificationLifecycleError('Invalid notification ID.',422);
        }
        json_out(notification_transition($pdo,$user,$action,(int)$id,($data['confirmed']??false)===true));
    }
} catch (NotificationLifecycleError $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],$e->status); }
catch (Throwable $e) {
    log_api_error('notification_lifecycle','Notification operation failed.');
    json_out(['ok'=>false,'message'=>'Notification operation could not complete. Retry shortly.'],503);
}

// Sending is an RPMS (and, for follow-ups only, research adviser) responsibility.
api_require_login(['admin', 'adviser']);
if ($action === 'group_options') {
    json_out(['ok' => true, 'groups' => research_group_options($pdo, $user, null, true)]);
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

    $queued = !$automated && count($recipients) > 5;
    $isScheduled = $automated || $queued;
    if ($queued) $scheduleAt = date('Y-m-d H:i:s');
    $subject = "PRISM $type - CEU Malolos RPMS";
    $sentCount = 0;
    $loggedCount = 0;
    $persisted = [];
    $pdo->beginTransaction();
    try {
    $recipients=notification_lock_recipients($pdo,$recipients);
    if (!$recipients) throw new NotificationLifecycleError('No current recipients remain.');
    $campaignId=notification_create_campaign($pdo,$user,'staff',$subject,$message,date('Y-m-d H:i:s',strtotime($isScheduled ? $scheduleAt : 'now')));
    foreach ($recipients as $recipient) {
        $body = "Hello {$recipient['name']},\n\n{$message}\n\n"
            . "This is an automated notification from the CEU Malolos Research Planning and Monitoring Section (RPMS) via PRISM.\n";
        $result = create_notification($pdo, $recipient['type'], (int)$recipient['id'],
            (string)($recipient['email'] ?? ''), (string)$recipient['name'], $subject, $message, $type,
            $user['full_name'], $body, $isScheduled ? date('Y-m-d H:i:s', strtotime($scheduleAt)) : date('Y-m-d H:i:s'), null, 'staff', $campaignId);
        if (isset($result['id'])) $persisted[] = ['id'=>$result['id']];
    }
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_api_error('notification_batch', 'Notification records could not be saved.');
        json_out(['ok'=>false, 'message'=>'Notification records could not be saved; no email was sent.'], 500);
    }
    // Every in-app record is committed before any provider call.
    foreach ($isScheduled ? [] : $persisted as $notice) {
        $result = notification_dispatch($pdo, $notice['id']);
        if ($result['status'] === 'Sent') $sentCount++;
        if ($result['status'] === 'Logged') $loggedCount++;
    }

    audit_log($user, 'notification_sent', ['entity_type'=>'notification_batch', 'after'=>$isScheduled ? 'Scheduled' : 'Attempted', 'details'=>"audience=$audience type=$type recipients=" . count($recipients)]);
    json_out(['ok' => true, 'sent' => $sentCount, 'logged' => $loggedCount,
        'campaign_id' => $campaignId, 'total' => count($recipients), 'scheduled' => $isScheduled, 'queued' => $queued]);
}

function resolve_recipients(PDO $pdo, array $user, string $audience, string $group): array
{
    if ($audience === 'Specific Research Group' && !in_array($group, research_group_options($pdo, $user, null, true), true)) {
        json_out(['ok' => false, 'message' => 'Choose an existing research group in your permitted scope.'], 422);
    }
    $recipients = [];

    if ($user['role'] === 'adviser') {
        // Advisers may only notify their own assigned students. Institution-wide audiences are admin-only.
        if (!in_array($audience, ['All Students', 'Specific Research Group'], true)) {
            return [];
        }
        $sql = 'SELECT s.id, s.full_name, s.email, s.research_group, s.adviser_id
            FROM students s JOIN advisers a ON a.id = s.adviser_id
            WHERE a.email = :adv AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL';
        $params = [':adv' => $user['email']];
        if ($audience === 'Specific Research Group') {
            if ($group === '') return [];
            $sql .= ' AND BINARY s.research_group = BINARY :g';
            $params[':g'] = $group;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $s) {
            $recipients[] = ['type' => 'student', 'id' => $s['id'], 'name' => $s['full_name'], 'email' => $s['email'], 'research_group'=>$s['research_group'], 'adviser_id'=>$s['adviser_id']];
        }
        return $recipients;
    }

    if (in_array($audience, ['All Students', 'Students and Advisers', 'Specific Research Group'], true)) {
        $sql = 'SELECT id, full_name, email, research_group FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL';
        $params = [];
        if ($audience === 'Specific Research Group') {
            if ($group === '') return [];
            $sql .= ' AND BINARY research_group = BINARY :g';
            $params[':g'] = $group;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $s) {
            $recipients[] = ['type' => 'student', 'id' => $s['id'], 'name' => $s['full_name'], 'email' => $s['email'], 'research_group'=>$s['research_group']];
        }
    }
    if (in_array($audience, ['All Advisers', 'Students and Advisers'], true)) {
        foreach ($pdo->query('SELECT id, full_name, email FROM advisers WHERE status = "Active" AND profile_completed_at IS NOT NULL AND archived_at IS NULL')->fetchAll() as $f) {
            $recipients[] = ['type' => 'adviser', 'id' => $f['id'], 'name' => $f['full_name'], 'email' => $f['email']];
        }
    }
    return $recipients;
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
