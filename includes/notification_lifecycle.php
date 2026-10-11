<?php
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

/** Legacy email visibility remains, but personal mutation needs an unambiguous profile/login link. */
function notification_legacy_owner(PDO $pdo, array $user, array $row): bool
{
    if (($row['recipient_email']??'')!==$user['email'] || $row['recipient_type']!==$user['role']) return false;
    if ($user['role']==='admin') return (int)($row['recipient_id']??0)===(int)$user['id'];
    if (!in_array($user['role'],['student','adviser'],true)) return false;
    $table=$user['role']==='student'?'students':'advisers'; $field=$user['role']==='student'?'student_id':'employee_id';
    $profiles=notification_rows($pdo,"SELECT * FROM $table WHERE email=? ORDER BY id",[$user['email']]);
    if (count($profiles)!==1) return false;
    $p=$profiles[0];
    if (!empty($row['recipient_id']) && (int)$row['recipient_id']!==(int)$p['id']) return false;
    if (empty($row['recipient_id']) && !empty($row['recipient_name']) && $row['recipient_name']!==$p['full_name']) return false;
    if (!empty($p['user_id'])) return (int)$p['user_id']===(int)$user['id'];
    $identity=(string)($p[$field]??'');
    return $identity!=='' && (($user['username']??'')===$identity || (string)($user['ref_id']??'')===$identity);
}

function notification_owns_copy(PDO $pdo, array $user, array $row): bool
{
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
    if ($campaign && !in_array($actor['role'],['admin','adviser'],true)) throw new NotificationLifecycleError('Sender action is not permitted.',403);
    $mutex=($campaign?'prism_campaign_':'prism_notification_').$id;
    notification_mutex($pdo,$mutex);
    try {
        $pdo->beginTransaction();
        $table=$campaign?'notification_campaigns':'notifications';
        $rows=notification_rows($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]); $row=$rows[0]??null;
        if (!$row || ($campaign ? (int)$row['sender_user_id']!==(int)$actor['id'] : !notification_owns_copy($pdo,$actor,$row))) {
            throw new NotificationLifecycleError('Notification not found.',404);
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

/** Authorized queries never recover erased content from a campaign/other copy. */
function notification_list(PDO $pdo, array $user, array $query, string $mode='list'): array
{
    $status=$query['status']??'All'; $kind=$query['kind']??'All'; $sort=$query['sort']??'Newest';
    if (!in_array($status,['All','Unread','Read','Archived'],true) || !in_array($kind,['All','staff','workflow','system','support'],true)
        || !in_array($sort,['Newest','Oldest'],true)) throw new NotificationLifecycleError('Invalid notification filter.',422);
    foreach (['page','preview'] as $key) if (isset($query[$key]) && ((!is_int($query[$key]) && !is_string($query[$key]))
        || !preg_match('/\A-?[0-9]+\z/',(string)$query[$key]))) throw new NotificationLifecycleError('Invalid notification page.',422);
    $sender=$mode==='sent_history'; $legacy=$mode==='legacy_history';
    if (($sender || $legacy) && !in_array($user['role'],['admin','adviser'],true)) throw new NotificationLifecycleError('History is not permitted.',403);
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
                $scope.=' AND ((n.recipient_email=:email AND n.recipient_type<>"historical_adviser")
                    OR (n.recipient_type="student" AND a.email=:assigned AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL))';
                $params=[':email'=>$user['email'],':assigned'=>$user['email']];
            }
        } else {
            $scope.=' AND (n.recipient_user_id=:uid OR (n.recipient_user_id IS NULL AND n.recipient_email=:email AND n.recipient_type<>"historical_adviser"))
                AND n.status NOT IN ("Scheduled","Sending","Cancelled")';
            $params=[':uid'=>$user['id'],':email'=>$user['email']];
        }
        $kindSql='COALESCE(c.kind,"system")'; $select='n.*, '.$kindSql.' AS kind';
    }
    $scope.=$status==='Archived'?' AND n.archived_at IS NOT NULL':' AND n.archived_at IS NULL';
    if (!$sender && in_array($status,['Read','Unread'],true)) $scope.=$status==='Read'?' AND n.read_at IS NOT NULL':' AND n.read_at IS NULL';
    if ($kind!=='All') { $scope.=' AND '.$kindSql.'=:kind'; $params[':kind']=$kind; }
    $q=$pdo->prepare('SELECT COUNT(*) '.$scope); $q->execute($params); $total=(int)$q->fetchColumn();
    $limit=isset($query['preview'])?max(1,min(5,(int)$query['preview'])):10;
    $pages=max(1,(int)ceil($total/$limit)); $page=isset($query['preview'])?1:max(1,min($pages,(int)($query['page']??1))); $offset=($page-1)*$limit;
    $order=$sort==='Oldest'?'ASC':'DESC';
    $rows=notification_rows($pdo,'SELECT '.$select.' '.$scope." ORDER BY n.created_at $order,n.id $order LIMIT $limit OFFSET $offset",$params);
    foreach ($rows as &$row) {
        unset($row['delivery_info']); // Operational diagnostics are never exposed to browser APIs.
        $row['legacy']=!$sender && $row['campaign_id']===null;
        $row['can_manage']=$sender || (!$legacy && notification_owns_copy($pdo,$user,$row));
    }
    unset($row);
    $unread=notification_rows($pdo,'SELECT COUNT(*) AS total FROM notifications n WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
        AND n.read_at IS NULL AND n.status NOT IN ("Scheduled","Sending","Cancelled") AND
        (n.recipient_user_id=? OR (n.recipient_user_id IS NULL AND n.recipient_email=? AND n.recipient_type<>"historical_adviser"))',[$user['id'],$user['email']]);
    return ['ok'=>true,'notifications'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'limit'=>$limit,'offset'=>$offset,'unread_count'=>(int)$unread[0]['total']];
}

function notification_mark_all_read(PDO $pdo, array $user): array
{
    $rows=notification_rows($pdo,'SELECT * FROM notifications WHERE read_at IS NULL AND archived_at IS NULL AND deleted_at IS NULL
        AND status NOT IN ("Scheduled","Sending","Cancelled") AND (recipient_user_id=? OR (recipient_user_id IS NULL AND recipient_email=?))',[$user['id'],$user['email']]);
    foreach ($rows as $row) if (notification_owns_copy($pdo,$user,$row)) {
        $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND read_at IS NULL AND deleted_at IS NULL AND archived_at IS NULL')->execute([$row['id']]);
    }
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
