<?php
/** Current mutations only. Retained reads and explicit lifecycle operations do not call this gate. */
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/account_purge_files.php';

function current_workflow_no_recovery(PDO $pdo, string $type, int $id): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Workflow eligibility requires a transaction.');
    purge_require_no_pending($pdo,$type,$id);
    foreach (purge_recovery_jobs($pdo) as $job) {
        if ($job['accountType']===$type && (int)$job['targetId']===$id) {
            throw new AccountLifecycleConflict('Account recovery must complete before current workflow.');
        }
    }
}

/** Revalidate the authenticated credential, account and legal state on a locking read. */
function current_workflow_actor(PDO $pdo, array $actor, bool $credentialChange = false): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Actor validation requires a transaction.');
    $rows=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=? FOR UPDATE',[$actor['id']]);
    $live=$rows[0]??null;
    if (!$live || $live['role']!==$actor['role'] || $live['status']!=='Active'
        || $live['email']!==$actor['email'] || !hash_equals((string)$live['password_hash'],(string)$actor['password_hash'])
        || (!$credentialChange && (!empty($live['must_change_password']) || legal_outstanding($pdo,(int)$live['id'])))) {
        throw new AccountLifecycleForbidden('Your account changed. Sign in again before continuing.');
    }
    return $live;
}

/** Lock Student first, then live logins and assigned Adviser, matching document transitions. */
function current_workflow_student(PDO $pdo, int $id, array $actor): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Student eligibility requires a transaction.');
    $rows=lifecycle_rows($pdo,'SELECT * FROM students WHERE id=? FOR UPDATE',[$id]);
    $row=$rows[0]??null;
    if (!$row || !empty($row['archived_at']) || empty($row['profile_completed_at'])) {
        throw new AccountLifecycleConflict('The Student record is unavailable for current workflow.');
    }
    current_workflow_no_recovery($pdo,'student',$id);
    $login=lifecycle_resolve_login($pdo,$row,'student');
    if (!$login || $login['status']!=='Active') throw new AccountLifecycleConflict('The Student account is inactive.');
    $live=current_workflow_actor($pdo,$actor);
    if ($live['role']==='student' && (int)$login['id']!==(int)$live['id']) {
        throw new AccountLifecycleForbidden('This Student record is not yours.');
    }
    if ($live['role']==='adviser') {
        $assigned=lifecycle_rows($pdo,'SELECT * FROM advisers WHERE id=? FOR UPDATE',[$row['adviser_id']]);
        $adviser=$assigned[0]??null;
        if (!$adviser || !empty($adviser['archived_at']) || empty($adviser['profile_completed_at']) || $adviser['status']!=='Active') {
            throw new AccountLifecycleForbidden('An active assigned Adviser is required.');
        }
        current_workflow_no_recovery($pdo,'adviser',(int)$adviser['id']);
        $assignedLogin=lifecycle_resolve_login($pdo,$adviser,'adviser');
        if (!$assignedLogin || (int)$assignedLogin['id']!==(int)$live['id'] || $assignedLogin['status']!=='Active') {
            throw new AccountLifecycleForbidden('Only the currently assigned Adviser may change this Student record.');
        }
    }
    return $row;
}
