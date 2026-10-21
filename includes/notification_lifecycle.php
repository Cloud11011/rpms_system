<?php
require_once __DIR__ . '/pagination.php';
/** Frozen-v10 notification ownership, campaigns and copy lifecycle. No bootstrap or DDL. */
final class NotificationLifecycleError extends RuntimeException
{
    public function __construct(string $message, public int $status = 409) { parent::__construct($message); }
}

function notification_rows(PDO $pdo, string $sql, array $params = []): array
{
    $q=$pdo->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function notification_mutex(PDO $pdo, string $name): void
{
    $q=$pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$name]);
    if ((int)$q->fetchColumn()!==1) throw new NotificationLifecycleError('Notification delivery or another lifecycle operation is in progress. Retry shortly.');
}

function notification_unlock(PDO $pdo, string $name): void
{
    $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$name]);
}

/** Resolve from a locked server-selected profile; a client user ID is never accepted. */
function notification_recipient_user(PDO $pdo, string $type, ?int $id, string $email): ?int
{
    if (!in_array($type,['student','adviser','admin'],true)) throw new NotificationLifecycleError('Invalid recipient category.',422);
    if ($type==='admin') {
        $rows=notification_rows($pdo,'SELECT id,email,role,status FROM users WHERE id=? FOR UPDATE',[$id]);
        $u=$rows[0]??null;
        if (!$u || $u['role']!=='admin' || (string)$u['email']!==$email || strcasecmp($u['status'],'Active')!==0) {
            throw new NotificationLifecycleError('Recipient account is no longer active.');
        }
        return (int)$u['id'];
    }
    $table=$type==='student'?'students':'advisers'; $field=$type==='student'?'student_id':'employee_id';
    $rows=notification_rows($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]); $profile=$rows[0]??null;
    if (!$profile || (string)$profile['email']!==$email || !empty($profile['archived_at']) || empty($profile['profile_completed_at'])) {
        throw new NotificationLifecycleError('Recipient profile is no longer current.');
    }
    $uid=(int)($profile['user_id']??0); $identifier=(string)($profile[$field]??'');
    $users=notification_rows($pdo,'SELECT id,email,role,status,username,ref_id FROM users
        WHERE id=? OR email=? OR (role=? AND (username=? OR ref_id=?)) ORDER BY id FOR UPDATE',
        [$uid,$email,$type,$identifier,$identifier]);
    if (!$users && !$uid) return null; // A profile without a login may still receive existing business notifications.
    if (count($users)!==1) throw new NotificationLifecycleError('Recipient account binding is ambiguous.');
    $u=$users[0];
    if ($u['role']!==$type || (string)$u['email']!==$email || strcasecmp($u['status'],'Active')!==0
        || ($uid && $uid!==(int)$u['id']) || (!$uid && $u['username']!==$identifier && (string)$u['ref_id']!==$identifier)) {
        throw new NotificationLifecycleError('Recipient account binding or active status changed.');
    }
    return (int)$u['id'];
}

function notification_sender(): ?array
{
    return function_exists('current_user') ? current_user() : null;
}

/** Caller owns the creation transaction and supplies an authenticated server actor. */
function notification_create_campaign(PDO $pdo, ?array $sender, string $kind, string $subject,
                                      string $message, ?string $scheduledAt, ?string $supportCategory=null,
                                      ?int $replyToCampaignId=null): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Campaign creation requires a transaction.');
    if (!in_array($kind,['staff','workflow','system','support'],true)) throw new NotificationLifecycleError('Invalid notification kind.',422);
    $categories=['Ask RPMS Staff','Report a Problem','Account/Access','Document/Submission','IERB Progress','Technical Issue','Other'];
    if (($kind==='support' && !in_array($supportCategory,$categories,true)) || ($kind!=='support' && $supportCategory!==null)) {
        throw new NotificationLifecycleError('Invalid support category.',422);
    }
    if ($sender) {
        $rows=notification_rows($pdo,'SELECT id,email,role,status FROM users WHERE id=? FOR UPDATE',[$sender['id']]);
        $u=$rows[0]??null;
        if (!$u || $u['email']!==$sender['email'] || $u['role']!==$sender['role'] || strcasecmp($u['status'],'Active')!==0) {
            throw new NotificationLifecycleError('Sender account is no longer current.',403);
        }
    }
    $q=$pdo->prepare("INSERT INTO notification_campaigns(sender_user_id,kind,subject,message,status,scheduled_at,support_category,reply_to_campaign_id)
        VALUES (?,?,?,?,'Scheduled',?,?,?)");
    $q->execute([$sender['id']??null,$kind,$subject,$message,$scheduledAt,$supportCategory,$replyToCampaignId]);
    return (int)$pdo->lastInsertId();
}

/** One predicate for personal reads, counts and mutations. Email is never identity.
 * Legacy profile binding must predate the copy: a later login/profile cannot
 * inherit retained private content merely by acquiring an old address/profile.
 */
function notification_owner_scope(array $user): array
{
    return ['n.recipient_type=:owner_role AND (n.recipient_user_id=:owner_id OR
        (n.recipient_user_id IS NULL AND n.recipient_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM users owner_user WHERE owner_user.id=:legacy_owner_id
            AND owner_user.created_at <= n.created_at AND (
                (n.recipient_type="admin" AND n.recipient_id=owner_user.id) OR
                (n.recipient_type="student" AND EXISTS (SELECT 1 FROM students owner_profile
                    WHERE owner_profile.id=n.recipient_id AND owner_profile.user_id=owner_user.id
                    AND owner_profile.created_at <= n.created_at)) OR
                (n.recipient_type="adviser" AND EXISTS (SELECT 1 FROM advisers owner_profile
                    WHERE owner_profile.id=n.recipient_id AND owner_profile.user_id=owner_user.id
                    AND owner_profile.created_at <= n.created_at))
            )
        )))', [':owner_role'=>$user['role'], ':owner_id'=>$user['id'], ':legacy_owner_id'=>$user['id']]];
}

/** Missing profile IDs, login bindings or creation provenance fail closed. */
function notification_legacy_owner(PDO $pdo, array $user, array $row): bool
{
    [$scope,$params]=notification_owner_scope($user);
    $rows=notification_rows($pdo,'SELECT n.id FROM notifications n WHERE n.id=:copy_id AND '.$scope,
        $params+[':copy_id'=>$row['id']??0]);
    return (bool)$rows;
}

function notification_owns_copy(PDO $pdo, array $user, array $row): bool
{
    if (($row['recipient_type']??'')!==$user['role']) return false;
    return $row['recipient_user_id']!==null
        ? (int)$row['recipient_user_id']===(int)$user['id'] : notification_legacy_owner($pdo,$user,$row);
}

function notification_audit(PDO $pdo, array $actor, string $action, string $entity, int $id, string $kind): void
{
    $q=$pdo->prepare('INSERT INTO activity_logs(user_email,actor_name,actor_role,action,entity_type,entity_id,details,created_at)
        VALUES (?,?,?,?,?,?,?,NOW())');
    $q->execute([$actor['email'],$actor['full_name'],$actor['role'],$action,$entity,(string)$id,
        'actor_id='.(int)$actor['id'].'; kind='.$kind]);
}

/** Never touch recipient rows on a sender history deletion. Mutexes precede entity row locks. */
function notification_transition(PDO $pdo, array $actor, string $action, int $id, bool $confirmed=false): array
{
    if ($id<=0) throw new NotificationLifecycleError('Invalid notification ID.',422);
    $campaign=str_starts_with($action,'campaign_');
    $operation=$campaign?substr($action,9):$action;
    if (!in_array($operation,['mark_read','archive','restore','delete','cancel'],true) || (!$campaign && $operation==='cancel')) {
        throw new NotificationLifecycleError('Invalid notification action.',422);
    }
    $mutex=($campaign?'prism_campaign_':'prism_notification_').$id;
    notification_mutex($pdo,$mutex);
    try {
        $pdo->beginTransaction();
        $table=$campaign?'notification_campaigns':'notifications';
        $rows=notification_rows($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]); $row=$rows[0]??null;
        if (!$row || ($campaign ? (int)$row['sender_user_id']!==(int)$actor['id'] : !notification_owns_copy($pdo,$actor,$row))) {
            throw new NotificationLifecycleError('Notification not found.',404);
        }
        if ($campaign && !in_array($actor['role'],['admin','adviser'],true)
            && !(($actor['role']??'')==='student' && $row['kind']==='support')) {
            throw new NotificationLifecycleError('Sender action is not permitted.',403);
        }
        if ($row['deleted_at']!==null) {
            if ($operation!=='delete') throw new NotificationLifecycleError('Notification not found.',404);
            $pdo->commit(); return ['ok'=>true,'changed'=>false];
        }
        $kind=$campaign?$row['kind']:'system';
        if (!$campaign && $row['campaign_id']) {
            $k=notification_rows($pdo,'SELECT kind FROM notification_campaigns WHERE id=?',[$row['campaign_id']]);
            $kind=$k[0]['kind']??'system';
        }
        $changed=false;
        if ($operation==='mark_read') {
            if ($campaign || in_array($row['status'],['Scheduled','Sending','Cancelled'],true)) throw new NotificationLifecycleError('Notification is not available to read.');
            if ($row['read_at']===null) { $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND read_at IS NULL AND deleted_at IS NULL')->execute([$id]); $changed=true; }
        } elseif ($operation==='cancel') {
            if ($row['status']!=='Cancelled') {
                if ($row['status']!=='Scheduled') throw new NotificationLifecycleError('Only a Scheduled campaign can be cancelled.');
                $pdo->prepare("UPDATE notification_campaigns SET status='Cancelled' WHERE id=? AND status='Scheduled'")->execute([$id]);
                $pdo->prepare("UPDATE notifications SET status='Cancelled',delivery_info=NULL,sending_started_at=NULL
                    WHERE campaign_id=? AND status='Scheduled'")->execute([$id]); $changed=true;
            }
        } else {
            if (in_array($row['status'],['Scheduled','Sending'],true)) throw new NotificationLifecycleError('Scheduled notifications must be cancelled first; Sending notifications must finish.');
            if ($operation==='archive' && $row['archived_at']===null) {
                $pdo->prepare("UPDATE $table SET archived_at=NOW() WHERE id=? AND archived_at IS NULL AND deleted_at IS NULL")->execute([$id]); $changed=true;
            } elseif ($operation==='restore' && $row['archived_at']!==null) {
                $pdo->prepare("UPDATE $table SET archived_at=NULL WHERE id=? AND deleted_at IS NULL")->execute([$id]); $changed=true;
            } elseif ($operation==='delete') {
                if ($row['archived_at']===null) throw new NotificationLifecycleError('Archive this item before permanently deleting it.');
                if (!$confirmed) throw new NotificationLifecycleError('Explicit permanent deletion confirmation is required.',422);
                $erase=$campaign?'subject=NULL,message=NULL':"subject=NULL,message='',delivery_info=NULL,recipient_email=NULL,recipient_name=NULL,created_by=NULL,recipient_user_id=?";
                $pdo->prepare("UPDATE $table SET deleted_at=NOW(),$erase WHERE id=? AND archived_at IS NOT NULL AND deleted_at IS NULL")
                    ->execute($campaign?[$id]:[(int)$actor['id'],$id]); $changed=true;
            }
        }
        if ($changed && $operation!=='mark_read') notification_audit($pdo,$actor,'notification_'.$action,$campaign?'notification_campaign':'notification',$id,$kind);
        $pdo->commit(); return ['ok'=>true,'changed'=>$changed];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { notification_unlock($pdo,$mutex); }
}

/** Caller holds campaign mutex. Recipient completion, including safe Logged fallback, determines history status. */
function notification_finish_campaign(PDO $pdo, int $campaignId): void
{
    $counts=notification_rows($pdo,"SELECT status,COUNT(*) AS total FROM notifications WHERE campaign_id=? GROUP BY status",[$campaignId]);
    $pending=false; $failed=false;
    foreach ($counts as $c) {
        if (in_array($c['status'],['Scheduled','Sending'],true)) $pending=true;
        if (in_array($c['status'],['Failed','Logged','Cancelled'],true)) $failed=true;
    }
    if (!$pending) $pdo->prepare("UPDATE notification_campaigns SET status=?,sent_at=? WHERE id=? AND status='Sending' AND deleted_at IS NULL")
        ->execute([$failed?'Failed':'Sent',$failed?null:date('Y-m-d H:i:s'),$campaignId]);
}

/** Typed official events (and a narrow historical producer format), never staff descriptions. */
function notification_deadline_event(array $row): ?array
{
    if (($row['kind']??'system')!=='system') return null;
    $event=null;
    if (($row['type']??'')==='Official Deadline') $event=json_decode((string)($row['message']??''),true);
    elseif (($row['type']??'')==='Reminder' && in_array($row['subject']??'',['New official deadline','Official deadline cancelled'],true)
        && preg_match('/\A(New official deadline|Official deadline cancelled) \(Deadline #([1-9][0-9]*)\)\.\r?\nTitle: ([^\r\n]+)\r?\nDeadline date: ([0-9]{4}-[0-9]{2}-[0-9]{2})(?:\r?\n|$)/',(string)($row['message']??''),$m)) {
        $event=['event'=>$m[1]==='New official deadline'?'official_deadline_created':'official_deadline_cancelled',
            'deadline_id'=>(int)$m[2],'title'=>$m[3],'due_date'=>$m[4]];
    }
    if (!is_array($event) || !in_array($event['event']??null,['official_deadline_created','official_deadline_cancelled'],true)
        || !is_int($event['deadline_id']??null) || $event['deadline_id']<=0
        || !is_string($event['title']??null) || trim($event['title'])==='' || mb_strlen($event['title'])>190
        || !is_string($event['due_date']??null) || !preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/',$event['due_date'],$d)
        || !checkdate((int)$d[2],(int)$d[3],(int)$d[1])) return null;
    return array_intersect_key($event,array_flip(['event','deadline_id','title','due_date']));
}

/** Student reads expose explicit notification fields, never operational actor metadata. */
function notification_student_projection(array $row): array
{
    $subject=(string)($row['subject']??''); $message=(string)($row['message']??'');
    $support=($row['kind']??'')==='support';
    $deadline=notification_deadline_event($row);
    $override=stripos((string)($row['created_by']??''),'Admin Override')!==false
        || stripos((string)($row['type']??''),'Admin Override')!==false || stripos($subject,'Admin Override')!==false
        || preg_match('/\AAn RPMS administrator changed\b/i',$message)===1;
    $review=$subject==='PRISM Document Status Update'
        && preg_match('/\ADocument ".+"(?: \([^\r\n]*\))? is now (?:Submitted|Under Review|Received|Verified|Approved|Denied|Resubmission Requested)\./s',$message)===1;
    $returned=$subject==='PRISM RPMS Return for Revision' && str_starts_with($message,'RPMS returned "');
    if (!$support && $override) $row['type']='Status Update';
    if (!$support && ($override || $subject==='PRISM IERB Progress Updated')) {
        $row['message']=$subject==='PRISM IERB Progress Updated'
            ? 'Your IERB progress was updated. Open PRISM to view your official stage and status.'
            : 'Your document status was updated by RPMS. Open your document record in PRISM for details.';
        if ($override && !in_array($subject,['PRISM IERB Progress Updated','PRISM Document Status Update'],true)) $row['subject']='PRISM Status Update';
    } elseif (!$support && $subject==='PRISM Formal RPMS Submission Confirmation') {
        $row['message']='Your document was formally submitted to RPMS. Open your document record in PRISM for details.';
    } elseif (!$support && !$review && !$returned && ($row['type']??'')==='Status Update'
        && in_array($row['kind']??'system',['system','workflow'],true)
        && preg_match('/\b(?:reason\s*:|admin\s+override\b)/i',$message)) {
        // Historical unclassified administrative free text is internal by default.
        $row['subject']='PRISM Status Update';
        $row['message']='Your PRISM record was updated. Open PRISM to view the current details.';
    }
    if ($deadline!==null) {
        $cancelled=$deadline['event']==='official_deadline_cancelled';
        $row['subject']=$cancelled?'Official deadline cancelled':'New official deadline';
        $row['message']=($cancelled?'Cancelled: ':'Deadline: ').$deadline['title']."\nDue date: ".$deadline['due_date']
            ."\nOpen your PRISM Calendar for the current official record.";
        $row['type']='Reminder'; $row['destination']='student.php#calendar';
    } elseif (!$support && !$review && !$returned && in_array($row['kind']??'system',['system','workflow'],true)) {
        // Unknown legacy system free text is not proof of a public message.
        $row['subject']='PRISM Status Update';
        $row['message']='Your PRISM record was updated. Open PRISM to view your documents and official progress.';
        $row['type']='Status Update';
    }
    return array_intersect_key($row,array_flip(['id','campaign_id','subject','message','type','status','kind',
        'scheduled_at','sent_at','read_at','archived_at','created_at','support_category','reply_to_campaign_id',
        'recipient_count','legacy','can_manage','destination']));
}

/** Authorized queries never recover erased content from a campaign/other copy. */
function notification_list(PDO $pdo, array $user, array $query, string $mode='list'): array
{
    $status=$query['status']??'All'; $kind=$query['kind']??'All'; $sort=$query['sort']??'Newest';
    if (!in_array($status,['All','Unread','Read','Archived'],true) || !in_array($kind,['All','staff','workflow','system','support'],true)
        || !in_array($sort,['Newest','Oldest'],true)) throw new NotificationLifecycleError('Invalid notification filter.',422);
    foreach (['page','preview'] as $key) if (isset($query[$key]) && ((!is_int($query[$key]) && !is_string($query[$key]))
        || !preg_match('/\A-?[0-9]+\z/',(string)$query[$key]))) throw new NotificationLifecycleError('Invalid notification page.',422);
    $support=in_array($mode,['support_requests','support_responses'],true);
    $sender=in_array($mode,['sent_history','support_requests'],true); $legacy=$mode==='legacy_history';
    if (($sender || $legacy) && !$support && !in_array($user['role'],['admin','adviser'],true)) throw new NotificationLifecycleError('History is not permitted.',403);
    $params=[];
    if ($sender) {
        $scope='FROM notification_campaigns n WHERE n.sender_user_id=:uid AND n.deleted_at IS NULL'; $params[':uid']=$user['id']; $kindSql='n.kind';
        $select='n.*, (SELECT COUNT(*) FROM notifications r WHERE r.campaign_id=n.id) AS recipient_count';
    } else {
        $scope='FROM notifications n LEFT JOIN notification_campaigns c ON c.id=n.campaign_id';
        if ($legacy && $user['role']==='adviser') {
            $scope.=' LEFT JOIN students s ON n.recipient_type="student" AND n.recipient_id=s.id LEFT JOIN advisers a ON a.id=s.adviser_id';
        }
        $scope.=' WHERE n.deleted_at IS NULL';
        if ($legacy) {
            $scope.=' AND n.campaign_id IS NULL AND n.recipient_user_id IS NULL';
            if ($user['role']==='adviser') {
                [$owner,$params]=notification_owner_scope($user);
                $scope.=' AND (('.$owner.') OR (n.recipient_type="student" AND a.user_id=:assigned
                    AND a.archived_at IS NULL AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL))';
                $params[':assigned']=$user['id'];
            }
        } else {
            [$owner,$params]=notification_owner_scope($user);
            $scope.=' AND ('.$owner.') AND n.status NOT IN ("Scheduled","Sending","Cancelled")';
        }
        $kindSql='COALESCE(c.kind,"system")'; $select='n.*, '.$kindSql.' AS kind,c.support_category,c.reply_to_campaign_id';
    }
    if ($support) {
        $scope.=$sender ? ' AND n.kind="support" AND n.reply_to_campaign_id IS NULL'
            : ' AND c.kind="support" AND c.reply_to_campaign_id IS NOT NULL AND n.recipient_user_id=:support_uid';
        if (!$sender) $params[':support_uid']=$user['id'];
    }
    $scope.=$status==='Archived'?' AND n.archived_at IS NOT NULL':' AND n.archived_at IS NULL';
    if (!$sender && in_array($status,['Read','Unread'],true)) $scope.=$status==='Read'?' AND n.read_at IS NOT NULL':' AND n.read_at IS NULL';
    if ($kind!=='All') { $scope.=' AND '.$kindSql.'=:kind'; $params[':kind']=$kind; }
    $q=$pdo->prepare('SELECT COUNT(*) '.$scope); $q->execute($params); $total=(int)$q->fetchColumn();
    $limit=isset($query['preview'])?max(1,min(5,(int)$query['preview'])):prism_page_size($query);
    $pages=max(1,(int)ceil($total/$limit)); $page=isset($query['preview'])?1:max(1,min($pages,(int)($query['page']??1))); $offset=($page-1)*$limit;
    $order=$sort==='Oldest'?'ASC':'DESC';
    $rows=notification_rows($pdo,'SELECT '.$select.' '.$scope." ORDER BY n.created_at $order,n.id $order LIMIT $limit OFFSET $offset",$params);
    foreach ($rows as &$row) {
        unset($row['delivery_info']); // Operational diagnostics are never exposed to browser APIs.
        $row['legacy']=!$sender && $row['campaign_id']===null;
        $row['can_manage']=$sender || (!$legacy && notification_owns_copy($pdo,$user,$row));
        if (($user['role']??'')==='student') $row=notification_student_projection($row);
    }
    unset($row);
    [$owner,$unreadParams]=notification_owner_scope($user);
    $unread=notification_rows($pdo,'SELECT COUNT(*) AS total FROM notifications n WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
        AND n.read_at IS NULL AND n.status NOT IN ("Scheduled","Sending","Cancelled") AND
        ('.$owner.')'
        .($support ? ' AND n.recipient_user_id=:support_owner AND EXISTS (SELECT 1 FROM notification_campaigns sc WHERE sc.id=n.campaign_id AND sc.kind="support" AND sc.reply_to_campaign_id IS NOT NULL)' : ''),
        $support ? $unreadParams+[':support_owner'=>$user['id']] : $unreadParams);
    return ['ok'=>true,'notifications'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'limit'=>$limit,'offset'=>$offset,'unread_count'=>(int)$unread[0]['total']];
}

function notification_mark_all_read(PDO $pdo, array $user): array
{
    [$owner,$params]=notification_owner_scope($user);
    $rows=notification_rows($pdo,'SELECT n.* FROM notifications n WHERE n.read_at IS NULL AND n.archived_at IS NULL AND n.deleted_at IS NULL
        AND n.status NOT IN ("Scheduled","Sending","Cancelled") AND ('.$owner.')',$params);
    foreach ($rows as $row) notification_transition($pdo,$user,'mark_read',(int)$row['id']);
    return ['ok'=>true];
}

/** Before users deletion, with caller's existing transaction and account eligibility/journal checks. */
function notification_purge_sender(PDO $pdo, int $userId, array &$mutexes): void
{
    $rows=notification_rows($pdo,'SELECT id FROM notification_campaigns WHERE sender_user_id=? ORDER BY id',[$userId]);
    foreach ($rows as $row) {
        $name='prism_campaign_'.$row['id'];
        try { notification_mutex($pdo,$name); }
        catch (NotificationLifecycleError $e) { throw new AccountLifecycleConflict('Sender purge must wait for notification delivery.'); }
        $mutexes[]=$name;
        $current=notification_rows($pdo,'SELECT status FROM notification_campaigns WHERE id=? FOR UPDATE',[$row['id']]);
        if (($current[0]['status']??'')==='Sending') throw new AccountLifecycleConflict('Sender purge must wait for notification delivery.');
        $pdo->prepare("UPDATE notifications SET status='Cancelled',delivery_info=NULL WHERE campaign_id=? AND status='Scheduled'")->execute([$row['id']]);
        $pdo->prepare("UPDATE notification_campaigns SET status=CASE WHEN status='Scheduled' THEN 'Cancelled' ELSE status END,
            archived_at=COALESCE(archived_at,NOW()),deleted_at=COALESCE(deleted_at,NOW()),subject=NULL,message=NULL WHERE id=?")
            ->execute([$row['id']]);
    }
}

/** Admin recipients also use the frozen CASCADE edge; synchronize with their delivery mutexes. */
function notification_purge_recipient(PDO $pdo, int $userId, array &$mutexes): void
{
    $rows=notification_rows($pdo,'SELECT id FROM notifications WHERE recipient_user_id=? ORDER BY id',[$userId]);
    foreach ($rows as $row) {
        $name='prism_notification_'.$row['id'];
        try { notification_mutex($pdo,$name); }
        catch (NotificationLifecycleError $e) { throw new AccountLifecycleConflict('Account purge must wait for notification delivery.'); }
        $mutexes[]=$name;
        $current=notification_rows($pdo,'SELECT status FROM notifications WHERE id=? FOR UPDATE',[$row['id']]);
        if (($current[0]['status']??'')==='Sending') throw new AccountLifecycleConflict('Account purge must wait for notification delivery.');
    }
}
