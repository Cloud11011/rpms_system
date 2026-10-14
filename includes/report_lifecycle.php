<?php
/** Transactional report deletion. No bootstrap, schema changes or filesystem mutations. */
require_once __DIR__.'/student_snapshot.php';
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/account_lifecycle_schema.php';
require_once __DIR__.'/account_purge_files.php';

final class ReportDeletionError extends RuntimeException
{
    public function __construct(string $message, public int $status=409) { parent::__construct($message); }
}

/** Lock order follows account purge: live actor, Student owner, then owned report. */
function report_delete_atomic(PDO $pdo, array $actor, string $id): array
{
    if ($pdo->inTransaction()) throw new LogicException('Report deletion owns its transaction.');
    if (($actor['role']??'')!=='admin') throw new ReportDeletionError('Report deletion is not permitted.',403);
    // Discover ownership before beginning a repeatable-read transaction; revalidate after all parent locks.
    $q=$pdo->prepare('SELECT owner_student_id FROM reports WHERE id=?'); $q->execute([$id]);
    $discovered=$q->fetch(PDO::FETCH_ASSOC);
    if (!$discovered) throw new ReportDeletionError('Report not found.',404);
    $owner=$discovered['owner_student_id']===null ? null : (int)$discovered['owner_student_id'];
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE'); $q->execute([$actor['id']]);
        $live=$q->fetch(PDO::FETCH_ASSOC);
        if (!$live || $live['role']!=='admin' || strcasecmp((string)$live['status'],'Active')!==0
            || $live['email']!==$actor['email'] || !hash_equals((string)$live['password_hash'],(string)$actor['password_hash'])
            || !empty($live['must_change_password'])) {
            throw new ReportDeletionError('Your account changed. Sign in again before deleting a report.',403);
        }
        if ($owner!==null) {
            $q=$pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE'); $q->execute([$owner]);
            if (!$q->fetchColumn()) throw new ReportDeletionError('Report ownership changed. Refresh before deleting.');
            purge_require_no_pending($pdo,'student',$owner);
            foreach (purge_recovery_jobs($pdo) as $job) {
                if ($job['accountType']==='student' && (int)$job['targetId']===$owner) {
                    throw new AccountLifecycleConflict('Student purge recovery must complete before deleting this report.');
                }
            }
        }
        $q=$pdo->prepare('SELECT * FROM reports WHERE id=? FOR UPDATE'); $q->execute([$id]);
        $report=$q->fetch(PDO::FETCH_ASSOC);
        if (!$report) throw new ReportDeletionError('Report not found.',404);
        $lockedOwner=$report['owner_student_id']===null ? null : (int)$report['owner_student_id'];
        if ($owner!==$lockedOwner) throw new ReportDeletionError('Report ownership changed. Refresh before deleting.');
        if (!audit_lock_live_student($pdo,['entity_type'=>'report','entity_id'=>$id,'student_id'=>$owner])) {
            throw new ReportDeletionError('Report ownership is unavailable. Refresh before deleting.');
        }
        // Mandatory INSERT on this connection: failure must roll back deletion rather than disappear in audit_log().
        $pdo->prepare('INSERT INTO activity_logs
            (user_email,action,details,actor_name,actor_role,entity_type,entity_id,student_id,before_value,after_value,is_override)
            VALUES (?,?,?,?,?,?,?,?,?,?,0)')
            ->execute([$live['email'],'report_deleted','',$live['full_name'],$live['role'],'report',$id,$owner,
                mb_substr((string)$report['title'],0,255),'Deleted']);
        $pdo->prepare('DELETE FROM reports WHERE id=?')->execute([$id]);
        $pdo->commit();
        return $report;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
