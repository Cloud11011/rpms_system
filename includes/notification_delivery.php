<?php
/** Shared frozen-v10 persistence and best-effort email. Config is loaded by the caller. */
require_once __DIR__.'/student_snapshot.php';
require_once __DIR__.'/notification_lifecycle.php';
require_once __DIR__.'/help_support.php';

/** Operational metadata only. Historical free-text diagnostics are not retry authority. */
function notification_delivery_meta(?string $info): array
{
    $meta=json_decode($info??'',true);
    return is_array($meta) ? $meta : [];
}

function notification_retry_due(array $row): bool
{
    $meta=notification_delivery_meta($row['delivery_info']??null);
    return ($row['status']??'')==='Failed' && ($meta['retryable']??false)===true
        && (int)($meta['attempts']??0)<3 && !empty($row['scheduled_at'])
        && strtotime($row['scheduled_at'])<=time();
}

/** Low-level delivery; production callers claim through notification_dispatch first. */
function deliver_notification_email(PDO $pdo, int $id, string $email, string $subject, string $body,
                                    bool $scheduled = false): array
{
    $name='prism_notification_'.$id;
    try { notification_mutex($pdo,$name); }
    catch (NotificationLifecycleError $e) { return ['ok'=>true,'status'=>'Skipped','message'=>'Delivery is busy.']; }
    try {
        $rows=notification_rows($pdo,'SELECT n.status,n.deleted_at,n.delivery_info,c.kind FROM notifications n LEFT JOIN notification_campaigns c ON c.id=n.campaign_id WHERE n.id=?',[$id]); $row=$rows[0]??null;
        if (($row['kind']??'')==='support') return ['ok'=>true,'status'=>'Skipped','message'=>'Support is available in PRISM only.'];
        if (!$row || $row['deleted_at']!==null || $row['status']!=='Sending') return ['ok'=>true,'status'=>'Skipped','message'=>'Delivery was cancelled or is not claimed.'];
        try { $result=send_notification_email($email,$subject,$body,false,false); }
        catch (Throwable $e) { $result=['ok'=>false,'message'=>'External notification delivery failed.']; }
        $status=!empty($result['ok']) ? (($result['channel']??'')==='log'?'Logged':'Sent') : 'Failed';
        $message=match($status) {
            'Sent'=>'External delivery completed.',
            'Logged'=>'External delivery unavailable; metadata-only local fallback recorded.',
            default=>'External notification delivery failed.',
        };
        $attempts=max(1,(int)(notification_delivery_meta($row['delivery_info']??null)['attempts']??1));
        $retryable=$status==='Failed' && $attempts<3;
        $info=json_encode(['attempts'=>$attempts,'retryable'=>$retryable,'message'=>$message],JSON_THROW_ON_ERROR);
        $retryAt=$retryable ? date('Y-m-d H:i:s',time()+60*(2**($attempts-1))) : null;
        $pdo->prepare('UPDATE notifications SET status=?,delivery_info=?,sent_at=?,sending_started_at=NULL,
            scheduled_at=COALESCE(?,scheduled_at)
            WHERE id=? AND status="Sending" AND deleted_at IS NULL')
            ->execute([$status,$info,!empty($result['ok'])?date('Y-m-d H:i:s'):null,$retryAt,$id]);
        return ['ok'=>!empty($result['ok']),'channel'=>$result['channel']??'none','status'=>$status,'message'=>$message];
    } finally { notification_unlock($pdo,$name); }
}

/** Campaign coordination spans claim/provider/status, independently of recipient tombstones. */
function notification_dispatch(PDO $pdo, int $id, ?string $staleCutoff=null, ?array $emailOverride=null, bool $retryFailed=false): array
{
    if ($pdo->inTransaction()) throw new LogicException('Dispatch requires a committed notification.');
    $rows=notification_rows($pdo,'SELECT campaign_id FROM notifications WHERE id=?',[$id]);
    if (!$rows) return ['ok'=>true,'status'=>'Skipped'];
    $campaignId=(int)($rows[0]['campaign_id']??0); $locks=[];
    try {
        foreach (array_filter([$campaignId?'prism_campaign_'.$campaignId:null,'prism_notification_'.$id]) as $name) {
            notification_mutex($pdo,$name); $locks[]=$name;
        }
        $pdo->beginTransaction();
        $rows=notification_rows($pdo,'SELECT * FROM notifications WHERE id=? FOR UPDATE',[$id]); $row=$rows[0]??null;
        if ($campaignId) {
            $kind=notification_rows($pdo,'SELECT kind FROM notification_campaigns WHERE id=?',[$campaignId]);
            if (($kind[0]['kind']??'')==='support') { $pdo->commit(); return ['ok'=>true,'status'=>'Skipped']; }
        }
        $due=$row && $row['status']==='Scheduled' && $row['scheduled_at']!==null && strtotime($row['scheduled_at'])<=time();
        $recover=$row && $staleCutoff!==null && $row['status']==='Sending' && ($row['sending_started_at']===null || $row['sending_started_at']<$staleCutoff);
        $retry=$row && $retryFailed && notification_retry_due($row);
        if (!$row || $row['deleted_at']!==null || $row['archived_at']!==null || (!$due && !$recover && !$retry)) {
            $pdo->commit(); return ['ok'=>true,'status'=>'Skipped'];
        }
        if ($campaignId) {
            $campaigns=notification_rows($pdo,'SELECT * FROM notification_campaigns WHERE id=? FOR UPDATE',[$campaignId]); $campaign=$campaigns[0]??null;
            if (!$campaign || $campaign['deleted_at']!==null || $campaign['archived_at']!==null
                || !in_array($campaign['status'],$retry?['Scheduled','Sending','Failed']:['Scheduled','Sending'],true)) {
                $pdo->commit(); return ['ok'=>true,'status'=>'Skipped'];
            }
            $pdo->prepare("UPDATE notification_campaigns SET status='Sending' WHERE id=? AND status IN ('Scheduled','Failed')")->execute([$campaignId]);
        }
        if ($recover) {
            // The provider may have accepted a stale claim. Never resend it blindly.
            $meta=notification_delivery_meta($row['delivery_info']??null);
            $info=json_encode(['attempts'=>(int)($meta['attempts']??1),'retryable'=>false,'uncertain'=>true,
                'message'=>'Delivery outcome unknown after an interrupted claim. Reconcile provider evidence before retrying.'],JSON_THROW_ON_ERROR);
            $pdo->prepare("UPDATE notifications SET status='Failed',delivery_info=?,sending_started_at=NULL WHERE id=?")->execute([$info,$id]);
            $pdo->commit(); if ($campaignId) notification_finish_campaign($pdo,$campaignId);
            return ['ok'=>false,'status'=>'Uncertain','message'=>'Interrupted delivery requires operator reconciliation.'];
        }
        if ($row['recipient_user_id']!==null) {
            $users=notification_rows($pdo,'SELECT status,role,email,full_name FROM users WHERE id=?',[$row['recipient_user_id']]);
            if (!$users || strcasecmp($users[0]['status'],'Active')!==0 || $users[0]['role']!==$row['recipient_type']) {
                $pdo->prepare("UPDATE notifications SET status='Cancelled',delivery_info=NULL,sending_started_at=NULL WHERE id=?")->execute([$id]);
                $pdo->commit(); if ($campaignId) notification_finish_campaign($pdo,$campaignId);
                return ['ok'=>true,'status'=>'Skipped'];
            }
            // A queued historical address may have been recycled. Mail follows the
            // immutable account's current address; never the retained email alone.
            $row['recipient_email']=$users[0]['email'];
            $row['recipient_name']=$users[0]['full_name'];
        } else {
            $pdo->prepare("UPDATE notifications SET status='Failed',delivery_info=?,sending_started_at=NULL WHERE id=?")
                ->execute([json_encode(['retryable'=>false,'message'=>'Recipient identity is unbound; operator review required.']),$id]);
            $pdo->commit(); if ($campaignId) notification_finish_campaign($pdo,$campaignId);
            return ['ok'=>false,'status'=>'Failed','message'=>'Unbound recipient requires review.'];
        }
        $attempts=(int)(notification_delivery_meta($row['delivery_info']??null)['attempts']??0)+1;
        $info=json_encode(['attempts'=>$attempts,'retryable'=>false,'message'=>'Delivery claimed.'],JSON_THROW_ON_ERROR);
        $pdo->prepare("UPDATE notifications SET status='Sending',sending_started_at=NOW(),delivery_info=? WHERE id=? AND deleted_at IS NULL")->execute([$info,$id]);
        $pdo->commit();
        if ($row['recipient_type']==='student') {
            // Historical queued copies retain their stored evidence; only the outgoing Student view is projected.
            $deliveryRow=$row+['kind'=>$campaign['kind']??'system'];
            $safe=notification_student_projection($deliveryRow);
            $row['subject']=$safe['subject']??'PRISM Status Update';
            $row['message']=$safe['message']??'';
            if (($safe['destination']??'')==='student.php#calendar') {
                $base=defined('APP_BASE_URL')?rtrim(APP_BASE_URL,'/'):'';
                $row['message'].="\nCalendar: ".($base!==''?$base.'/':'').'student.php#calendar';
            }
            // Use the projected stored copy for Student mail as well: an alternate
            // email body has no stronger provenance than the queued message.
            $emailOverride=null;
        }
        $body='Hello '.($row['recipient_name']?:'there').",\n\n".$row['message']."\n\n"
            ."This is an automated notification from the CEU Malolos Research Planning and Monitoring Section (RPMS) via PRISM.\n";
        $result=deliver_notification_email($pdo,$id,(string)$row['recipient_email'],$emailOverride[0]??(string)$row['subject'],$emailOverride[1]??$body,$emailOverride===null);
        if ($campaignId) notification_finish_campaign($pdo,$campaignId);
        return $result;
    } catch (NotificationLifecycleError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok'=>true,'status'=>'Skipped'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_api_error('notification_dispatch','Notification delivery could not complete.');
        return ['ok'=>false,'status'=>'Failed','message'=>'Notification delivery could not complete.'];
    } finally { foreach (array_reverse($locks) as $name) notification_unlock($pdo,$name); }
}

/** Caller-owned transactions defer delivery. Recipient content is always independently stored. */
function create_notification(PDO $pdo, string $recipientType, ?int $recipientId, string $email, string $name,
                             string $subject, string $message, string $type, string $createdBy,
                             ?string $emailBody=null, ?string $scheduledAt=null, ?string $emailSubject=null,
                             string $kind='workflow', ?int $campaignId=null): array
{
    $owned=!$pdo->inTransaction(); if ($owned) $pdo->beginTransaction();
    try {
        if (!notification_lock_recipients($pdo,[['type'=>$recipientType,'id'=>$recipientId,'email'=>$email,'name'=>$name]])) {
            if ($owned) $pdo->commit(); return ['ok'=>true,'status'=>'Skipped','message'=>'Recipient is no longer current.'];
        }
        $uid=notification_recipient_user($pdo,$recipientType,$recipientId,$email);
        $schedule=$scheduledAt??date('Y-m-d H:i:s');
        if ($campaignId===null) $campaignId=notification_create_campaign($pdo,notification_sender(),$kind,$subject,$message,$schedule);
        $pdo->prepare('INSERT INTO notifications(recipient_type,recipient_id,recipient_user_id,campaign_id,recipient_email,recipient_name,
            subject,message,type,status,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,"Scheduled",?,?)')
            ->execute([$recipientType,$recipientId,$uid,$campaignId,$email,$name,$subject,$message,$type,$schedule,$createdBy]);
        $id=(int)$pdo->lastInsertId(); if ($owned) $pdo->commit();
    } catch (Throwable $e) { if ($owned && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if (!$owned || $scheduledAt!==null) return ['ok'=>true,'status'=>'Scheduled','message'=>'Notification scheduled.','id'=>$id,'campaign_id'=>$campaignId];
    return notification_dispatch($pdo,$id,null,[$emailSubject??$subject,$emailBody??$message])+['id'=>$id,'campaign_id'=>$campaignId];
}
