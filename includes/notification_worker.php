<?php
/** Bounded queue processing. No bootstrap, DDL, scheduler changes or provider configuration. */
require_once __DIR__.'/notification_delivery.php';

/** Operator-facing refusal. Messages name configuration or schema state only, never data or credentials. */
final class NotificationWorkerRefusal extends RuntimeException {}

/** Schemas whose notification contract this worker was verified against; each also needs a passing preflight. */
const NOTIFICATION_WORKER_SCHEMAS=['15','16'];

/**
 * Deploying code never activates cron delivery. config.local.php must set PRISM_NOTIFICATION_WORKER_ENABLED
 * to true and PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID to the reviewed queue fence: notifications with an
 * id at or below the fence are pre-activation backlog the worker never delivers, retries, recovers or cancels.
 */
function notification_worker_activation(mixed $enabled, mixed $releaseAfterId): array
{
    if ($enabled!==true) return ['enabled'=>false];
    if (!is_int($releaseAfterId) || $releaseAfterId<0) {
        throw new NotificationWorkerRefusal('Set PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID to the reviewed queue fence (a non-negative integer) before enabling the worker.');
    }
    return ['enabled'=>true,'releaseAfterId'=>$releaseAfterId];
}

/** A supported version number is not enough: the read-only exact contract preflight must pass as well. */
function notification_worker_compatibility(PDO $preflightConnection): string
{
    require_once __DIR__.'/runtime_preflight.php';
    $result=prism_runtime_database_preflight($preflightConnection);  // leaves this session read-only
    $version=(string)($result['schema_version']??'');
    if (!in_array($version,NOTIFICATION_WORKER_SCHEMAS,true)) {
        throw new NotificationWorkerRefusal('Worker refused: the database schema version is not one this worker was verified against (v15 or v16).');
    }
    if (!$result['ok'] || ($result['contract']??'')!=='PASS') {
        throw new NotificationWorkerRefusal('Worker refused: the reviewed database contract did not verify. Run maintenance/runtime-preflight.php --database.');
    }
    return $version;
}

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

/** $releaseAfterId is the operator's backlog fence; 0 releases every row (tests and explicitly reviewed queues). */
function notification_worker_run(PDO $pdo, int $limit=25, int $seconds=50, int $releaseAfterId=0): array
{
    if ($pdo->inTransaction()) throw new LogicException('Worker requires an independent connection.');
    $limit=max(1,min(100,$limit)); $seconds=max(1,min(300,$seconds)); $releaseAfterId=max(0,$releaseAfterId);
    $summary=['selected'=>0,'Sent'=>0,'Logged'=>0,'Failed'=>0,'Skipped'=>0,'Uncertain'=>0,'remindersQueued'=>0,'errors'=>0,
        'releaseAfterId'=>$releaseAfterId,'heldBacklog'=>0];
    try { notification_mutex($pdo,'prism_notification_worker'); }
    catch (NotificationLifecycleError $e) { return $summary+['busy'=>true]; }
    try {
        try { $summary['remindersQueued']=notification_enqueue_deadline_reminders($pdo); }
        catch (Throwable $e) { $summary['errors']++; log_api_error('deadline_reminder_scheduler','Deadline reminders could not be queued.'); }
        $cutoff=date('Y-m-d H:i:s',time()-900);
        $eligible='FROM notifications n LEFT JOIN notification_campaigns c ON c.id=n.campaign_id
            WHERE n.deleted_at IS NULL AND n.archived_at IS NULL AND (c.id IS NULL OR
                (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind<>"support" AND c.status IN ("Scheduled","Sending","Failed")))
            AND ((n.status="Scheduled" AND n.scheduled_at<=NOW())
                OR (n.status="Sending" AND (n.sending_started_at IS NULL OR n.sending_started_at<:cutoff))
                OR (n.status="Failed" AND n.scheduled_at<=NOW() AND JSON_VALID(n.delivery_info)
                    AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info),n.delivery_info,"{}"),"$.retryable")=true))';
        $summary['heldBacklog']=(int)(notification_rows($pdo,'SELECT COUNT(*) AS total '.$eligible.' AND n.id<=:fence',
            [':cutoff'=>$cutoff,':fence'=>$releaseAfterId])[0]['total']??0);
        $rows=notification_rows($pdo,'SELECT n.id '.$eligible.' AND n.id>:fence
            ORDER BY n.scheduled_at,n.id LIMIT '.$limit,[':cutoff'=>$cutoff,':fence'=>$releaseAfterId]);
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
        // Fenced: a backlog-only campaign keeps its recorded status until the operator releases it.
        $campaigns=notification_rows($pdo,'SELECT id FROM notification_campaigns c WHERE status="Sending"
            AND kind<>"support" AND deleted_at IS NULL AND archived_at IS NULL
            AND EXISTS (SELECT 1 FROM notifications f WHERE f.campaign_id=c.id AND f.id>:fence) ORDER BY id LIMIT '.$limit,
            [':fence'=>$releaseAfterId]);
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
