<?php
/** Bounded queue processing. No bootstrap, DDL, scheduler changes or provider configuration. */
require_once __DIR__.'/notification_delivery.php';

function notification_enqueue_deadline_reminders(PDO $pdo): int
{
    $tableCheck=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prism_deadline_reminder_dispatches'");
    $tableCheck->execute();
    if ((int)$tableCheck->fetchColumn()!==1 || !prism_settings_store_available($pdo)) return 0;
    $intervals=prism_settings_get($pdo,'communication.deadline_reminder_intervals',[7,3,1]);
    if (!is_array($intervals) || !$intervals) return 0;
    foreach ($intervals as $days) if (!is_int($days) || $days<1 || $days>30) throw new RuntimeException('Stored reminder intervals are invalid.');
    $marks=implode(',',array_fill(0,count($intervals),'?'));
    $q=$pdo->prepare('SELECT d.id AS deadline_id,d.title,d.deadline_date,r.student_id,s.email,s.full_name,
            DATEDIFF(d.deadline_date,CURDATE()) AS interval_days
        FROM calendar_deadlines d
        JOIN calendar_deadline_recipients r ON r.deadline_id=d.id
        JOIN students s ON s.id=r.student_id
        WHERE d.status="Active" AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL
          AND DATEDIFF(d.deadline_date,CURDATE()) IN ('.$marks.')
        ORDER BY d.id,r.student_id');
    $q->execute($intervals); $candidates=$q->fetchAll(PDO::FETCH_ASSOC); $queued=0;
    foreach ($candidates as $candidate) {
        $pdo->beginTransaction();
        try {
            $current=$pdo->prepare('SELECT d.title,d.deadline_date,s.email,s.full_name FROM calendar_deadlines d
                JOIN calendar_deadline_recipients r ON r.deadline_id=d.id
                JOIN students s ON s.id=r.student_id
                WHERE d.id=? AND r.student_id=? AND d.status="Active" AND s.archived_at IS NULL
                  AND s.profile_completed_at IS NOT NULL AND DATEDIFF(d.deadline_date,CURDATE())=? FOR UPDATE');
            $current->execute([$candidate['deadline_id'],$candidate['student_id'],$candidate['interval_days']]);
            $live=$current->fetch(PDO::FETCH_ASSOC);
            if (!$live) { $pdo->rollBack(); continue; }
            $record=$pdo->prepare('INSERT INTO prism_deadline_reminder_dispatches(deadline_id,student_id,deadline_date,interval_days) VALUES (?,?,?,?)');
            try { $record->execute([$candidate['deadline_id'],$candidate['student_id'],$live['deadline_date'],$candidate['interval_days']]); }
            catch (PDOException $e) {
                if ((int)($e->errorInfo[1]??0)===1062) { $pdo->rollBack(); continue; }
                throw $e;
            }
            $subject='Official deadline reminder';
            $message=json_encode(['event'=>'official_deadline_reminder','deadline_id'=>(int)$candidate['deadline_id'],
                'title'=>(string)$live['title'],'due_date'=>(string)$live['deadline_date']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $campaign=notification_create_campaign($pdo,null,'system',$subject,$message,date('Y-m-d H:i:s'));
            $notice=create_notification($pdo,'student',(int)$candidate['student_id'],(string)$live['email'],(string)$live['full_name'],
                $subject,$message,'Deadline Reminder','PRISM',null,date('Y-m-d H:i:s'),null,'system',$campaign);
            if (!isset($notice['id'])) throw new RuntimeException('The reminder recipient is no longer current.');
            $pdo->prepare('UPDATE prism_deadline_reminder_dispatches SET notification_id=? WHERE deadline_id=? AND student_id=? AND deadline_date=? AND interval_days=?')
                ->execute([$notice['id'],$candidate['deadline_id'],$candidate['student_id'],$live['deadline_date'],$candidate['interval_days']]);
            $pdo->commit(); $queued++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    return $queued;
}

function notification_worker_run(PDO $pdo, int $limit=25, int $seconds=50): array
{
    if ($pdo->inTransaction()) throw new LogicException('Worker requires an independent connection.');
    $limit=max(1,min(100,$limit)); $seconds=max(1,min(300,$seconds));
    $summary=['selected'=>0,'Sent'=>0,'Logged'=>0,'Failed'=>0,'Skipped'=>0,'Uncertain'=>0,'remindersQueued'=>0,'errors'=>0];
    try { notification_mutex($pdo,'prism_notification_worker'); }
    catch (NotificationLifecycleError $e) { return $summary+['busy'=>true]; }
    try {
        try { $summary['remindersQueued']=notification_enqueue_deadline_reminders($pdo); }
        catch (Throwable $e) { $summary['errors']++; log_api_error('deadline_reminder_scheduler','Deadline reminders could not be queued.'); }
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
