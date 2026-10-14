<?php
/** Support is an in-app use of frozen-v10 campaigns and independent copies. No DDL or mail. */
require_once __DIR__.'/notification_lifecycle.php';

function support_categories(): array
{
    return ['Ask RPMS Staff','Report a Problem','Account/Access','Document/Submission','IERB Progress','Technical Issue','Other'];
}

/** Pending is permitted; inactive, archived, missing or ambiguous profile bindings are not. */
function support_account(PDO $pdo, array $user, bool $lock=false): array
{
    if (!in_array($user['role']??'',['student','adviser'],true) || strcasecmp($user['status']??'','Active')!==0) {
        throw new NotificationLifecycleError('Help & Support is unavailable for this account.',403);
    }
    $profile=onboarding_profile($pdo,$user,$lock);
    if (!$profile || $profile['archived_at']!==null || ($user['role']==='adviser' && $profile['status']!=='Active')) {
        throw new NotificationLifecycleError('Help & Support is unavailable for this account.',403);
    }
    return $profile;
}

function support_fields(array $data, array $allowed): void
{
    if (array_diff(array_keys($data),$allowed)) throw new NotificationLifecycleError('Unexpected support fields.',422);
}

function support_text(mixed $value, string $label, int $max, bool $multiline=false): string
{
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8')) throw new NotificationLifecycleError($label.' must be valid text.',422);
    if (preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u',$value)) {
        throw new NotificationLifecycleError($label.' contains unsupported control characters.',422);
    }
    $value=trim($value);
    if ($value==='' || mb_strlen($value)>$max) throw new NotificationLifecycleError($label.' is required and must be '.$max.' characters or fewer.',422);
    return $value;
}

function support_id(mixed $value): int
{
    if ((!is_int($value)&&!is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$value) || (int)$value>2147483647) {
        throw new NotificationLifecycleError('Invalid support item.',422);
    }
    return (int)$value;
}

function support_token(): string
{
    return $_SESSION['support_send_token']??=bin2hex(random_bytes(32));
}

function support_check_token(mixed $token): void
{
    if (!is_string($token) || !hash_equals(support_token(),$token)) {
        throw new NotificationLifecycleError('This support form was already used or expired. Reload before sending again.',409);
    }
}

/** Recipient is always selected and locked by the server, never supplied by a browser. */
function support_copy(PDO $pdo, array $recipient, int $campaignId, string $subject, string $message, string $type, array $sender): void
{
    $profileId=$recipient['id'];
    if ($recipient['role']==='admin') notification_recipient_user($pdo,'admin',(int)$recipient['id'],$recipient['email']);
    else $profileId=support_account($pdo,$recipient,true)['id'];
    $pdo->prepare("INSERT INTO notifications(recipient_type,recipient_id,recipient_user_id,campaign_id,recipient_email,recipient_name,
        subject,message,type,status,sent_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,'Sent',NOW(),?)")
        ->execute([$recipient['role'],$profileId,$recipient['id'],$campaignId,$recipient['email'],$recipient['full_name'],
            $subject,$message,$type,$sender['full_name']]);
}

function support_submit(PDO $pdo, array $sender, array $data): array
{
    support_fields($data,['category','subject','message','request_token']);
    support_check_token($data['request_token']??null);
    $category=$data['category']??null;
    if (!is_string($category) || !in_array($category,support_categories(),true)) throw new NotificationLifecycleError('Choose a valid support category.',422);
    $subject=support_text($data['subject']??null,'Subject',255);
    $message=support_text($data['message']??null,'Message',600,true);
    $pdo->beginTransaction();
    try {
        support_account($pdo,$sender,true);
        $admins=notification_rows($pdo,"SELECT * FROM users WHERE role='admin' AND status='Active' ORDER BY id FOR UPDATE");
        if (!$admins) throw new NotificationLifecycleError('No active RPMS Administrator is currently available to receive this request. Please try again later.',422);
        $id=notification_create_campaign($pdo,$sender,'support',$subject,$message,null,$category);
        foreach ($admins as $admin) support_copy($pdo,$admin,$id,$subject,$message,'Support Request',$sender);
        $pdo->prepare("UPDATE notification_campaigns SET status='Sent',sent_at=NOW() WHERE id=?")->execute([$id]);
        notification_audit($pdo,$sender,'support_request_sent','notification_campaign',$id,'support');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $_SESSION['support_send_token']=bin2hex(random_bytes(32));
    return ['ok'=>true,'campaign_id'=>$id,'request_token'=>support_token(),'message'=>'Your request is available to RPMS Administrators in PRISM.'];
}

/** Correlation only: retained Admin copy grants response authority, even if sender history was erased. */
function support_response_target(PDO $pdo, array $admin, int $copyId, bool $lock=false): array
{
    if (($admin['role']??'')!=='admin') throw new NotificationLifecycleError('Support response is not permitted.',403);
    $rows=notification_rows($pdo,"SELECT n.*,c.sender_user_id,c.kind,c.support_category,c.reply_to_campaign_id
        FROM notifications n JOIN notification_campaigns c ON c.id=n.campaign_id
        WHERE n.id=? AND n.recipient_user_id=? AND n.deleted_at IS NULL AND c.kind='support'
        AND c.reply_to_campaign_id IS NULL AND n.type='Support Request'".($lock?' FOR UPDATE':''),[$copyId,$admin['id']]);
    $request=$rows[0]??null;
    if (!$request) throw new NotificationLifecycleError('Support request not found.',404);
    $users=notification_rows($pdo,'SELECT * FROM users WHERE id=?'.($lock?' FOR UPDATE':''),[$request['sender_user_id']]);
    $target=$users[0]??null;
    if (!$target) throw new NotificationLifecycleError('The requester account is no longer available.',409);
    support_account($pdo,$target,$lock);
    return ['request'=>$request,'user'=>$target];
}

function support_respond(PDO $pdo, array $admin, array $data): array
{
    support_fields($data,['request_id','subject','message','request_token']);
    support_check_token($data['request_token']??null);
    $copyId=support_id($data['request_id']??null);
    $subject=support_text($data['subject']??null,'Subject',255);
    $message=support_text($data['message']??null,'Message',600,true);
    $target=support_response_target($pdo,$admin,$copyId);
    $locks=['prism_campaign_'.$target['request']['campaign_id'],'prism_notification_'.$copyId]; $held=[];
    try {
        foreach ($locks as $name) { notification_mutex($pdo,$name); $held[]=$name; }
        $pdo->beginTransaction();
        $target=support_response_target($pdo,$admin,$copyId,true);
        $id=notification_create_campaign($pdo,$admin,'support',$subject,$message,null,
            $target['request']['support_category'],(int)$target['request']['campaign_id']);
        support_copy($pdo,$target['user'],$id,$subject,$message,'Support Response',$admin);
        $pdo->prepare("UPDATE notification_campaigns SET status='Sent',sent_at=NOW() WHERE id=?")->execute([$id]);
        notification_audit($pdo,$admin,'support_response_sent','notification_campaign',$id,'support');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { foreach (array_reverse($held) as $name) notification_unlock($pdo,$name); }
    $_SESSION['support_send_token']=bin2hex(random_bytes(32));
    return ['ok'=>true,'campaign_id'=>$id,'request_token'=>support_token(),'message'=>'Support Response sent in PRISM.'];
}

function support_transition(PDO $pdo, array $user, string $action, array $data): array
{
    support_fields($data,['id','confirmed']); $id=support_id($data['id']??null);
    $sender=str_starts_with($action,'campaign_');
    $sql=$sender ? "SELECT id FROM notification_campaigns WHERE id=? AND sender_user_id=? AND kind='support' AND reply_to_campaign_id IS NULL"
        : "SELECT n.id FROM notifications n JOIN notification_campaigns c ON c.id=n.campaign_id WHERE n.id=? AND n.recipient_user_id=? AND c.kind='support' AND c.reply_to_campaign_id IS NOT NULL";
    if (!notification_rows($pdo,$sql,[$id,$user['id']])) throw new NotificationLifecycleError('Support item not found.',404);
    return notification_transition($pdo,$user,$action,$id,($data['confirmed']??false)===true);
}
