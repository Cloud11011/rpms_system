<?php
/** Bounded queue processing. No bootstrap, DDL, scheduler changes or provider configuration. */
require_once __DIR__.'/notification_delivery.php';

function notification_worker_run(PDO $pdo, int $limit=25, int $seconds=50): array
{
    if ($pdo->inTransaction()) throw new LogicException('Worker requires an independent connection.');
    $limit=max(1,min(100,$limit)); $seconds=max(1,min(300,$seconds));
    $summary=['selected'=>0,'Sent'=>0,'Logged'=>0,'Failed'=>0,'Skipped'=>0,'Uncertain'=>0,'errors'=>0];
    try { notification_mutex($pdo,'prism_notification_worker'); }
    catch (NotificationLifecycleError $e) { return $summary+['busy'=>true]; }
    try {
        $cutoff=date('Y-m-d H:i:s',time()-900);
        $rows=notification_rows($pdo,'SELECT n.id FROM notifications n LEFT JOIN notification_campaigns c ON c.id=n.campaign_id
            WHERE n.deleted_at IS NULL AND n.archived_at IS NULL AND (c.id IS NULL OR
                (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind<>"support" AND c.status IN ("Scheduled","Sending","Failed")))
            AND ((n.status="Scheduled" AND n.scheduled_at<=NOW())
                OR (n.status="Sending" AND (n.sending_started_at IS NULL OR n.sending_started_at<:cutoff))
                OR (n.status="Failed" AND n.scheduled_at<=NOW() AND JSON_VALID(n.delivery_info)
                    AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info),n.delivery_info,"{}"),"$.retryable")=true))
            ORDER BY n.scheduled_at,n.id LIMIT '.$limit,[':cutoff'=>$cutoff]);
        $started=microtime(true);
        foreach ($rows as $row) {
            if (microtime(true)-$started >= $seconds) break;
            $summary['selected']++;
            try {
                $result=notification_dispatch($pdo,(int)$row['id'],$cutoff,null,true);
                $status=$result['status']??'Failed';
                $summary[array_key_exists($status,$summary)?$status:'Failed']++;
            } catch (Throwable $e) { $summary['errors']++; }
        }
        // Also repair a process interruption between recipient completion and campaign completion.
        $campaigns=notification_rows($pdo,'SELECT id FROM notification_campaigns WHERE status="Sending"
            AND kind<>"support" AND deleted_at IS NULL AND archived_at IS NULL ORDER BY id LIMIT '.$limit);
        foreach ($campaigns as $campaign) {
            $name='prism_campaign_'.$campaign['id'];
            try { notification_mutex($pdo,$name); }
            catch (NotificationLifecycleError $e) { continue; }
            try { notification_finish_campaign($pdo,(int)$campaign['id']); }
            finally { notification_unlock($pdo,$name); }
        }
        $summary['queue']=notification_rows($pdo,'SELECT status,COUNT(*) AS total FROM notifications
            WHERE deleted_at IS NULL GROUP BY status');
        return $summary;
    } finally { notification_unlock($pdo,'prism_notification_worker'); }
}
