<?php
/** Current mutations only. Retained reads and explicit lifecycle operations do not call this gate. */
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/research_projects.php';
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

/** Lock Student first, then live logins and assigned Adviser, matching document transitions.
 * $projectMember admits a verified fellow member of the same canonical project; only the reviewed
 * project-evidence paths (upload, formal submission, project reads) pass it.
 */
function current_workflow_student(PDO $pdo, int $id, array $actor, bool $projectMember = false): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Student eligibility requires a transaction.');
    research_project_fence($pdo);
    $rows=lifecycle_rows($pdo,'SELECT * FROM students WHERE id=? FOR UPDATE',[$id]);
    $row=$rows[0]??null;
    if (!$row || !empty($row['archived_at']) || empty($row['profile_completed_at'])) {
        throw new AccountLifecycleConflict('The Student record is unavailable for current workflow.');
    }
    if (($actor['role']??'')!=='admin' && function_exists('research_onboarding_available') && research_onboarding_available($pdo)) {
        $q=$pdo->prepare("SELECT state FROM research_onboarding_assignments WHERE student_id=? AND state<>'activated' FOR UPDATE"); $q->execute([$id]);
        if ($q->fetchColumn()) throw new AccountLifecycleConflict('Research assignment is awaiting RPMS review or account eligibility. Shared evidence submission is available after verified group setup.');
        if (($actor['role']??'')==='student' && !research_project_for_student($pdo,$id,true)) {
            $q=$pdo->prepare("SELECT 1 FROM activity_logs WHERE entity_type='student' AND entity_id=? AND action='account_identity_created' AND actor_role='adviser' LIMIT 1");$q->execute([(string)$id]);
            if($q->fetchColumn())throw new AccountLifecycleConflict('RPMS must review your research assignment before current document submission. Your account and retained records remain available.');
        }
    }
    current_workflow_no_recovery($pdo,'student',$id);
    $login=lifecycle_resolve_login($pdo,$row,'student');
    if (!$login || $login['status']!=='Active') throw new AccountLifecycleConflict('The Student account is inactive.');
    $live=current_workflow_actor($pdo,$actor);
    if ($live['role']==='student' && (int)$login['id']!==(int)$live['id']
        && !($projectMember && research_project_member_actor($pdo,$id,$live))) {
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
