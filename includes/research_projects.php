<?php
/** Canonical projects use immutable keys; legacy labels and titles never grant access. */
function research_projects_available(PDO $pdo): bool
{
    // A locking current read must not create a repeatable-read snapshot before
    // waiting for the Student fence; retries must see the winner's durable event.
    return in_array((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'".($pdo->inTransaction() ? ' LOCK IN SHARE MODE' : ''))->fetchColumn(),['13','14','15'],true);
}

/** Match the reviewed group writer's phantom fence before account/workflow locks.
 * Conservative institution-wide serialization avoids membership races with legacy writers.
 */
function research_project_fence(PDO $pdo): void
{
    if (!research_projects_available($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Project fence requires a transaction.');
    $q = $pdo->query('SELECT id FROM students FORCE INDEX (PRIMARY) ORDER BY id FOR UPDATE');
    while ($q->fetchColumn() !== false) { }
}

function research_project_for_student(PDO $pdo, int $sid, bool $lock = false): ?array
{
    if (!research_projects_available($pdo)) return null;
    $q = $pdo->prepare('SELECT p.* FROM research_projects p JOIN research_project_members m ON m.project_id=p.id WHERE m.student_id=?'.($lock ? ' FOR UPDATE' : ''));
    $q->execute([$sid]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The verified member row of a live Student login in the canonical project of $studentId, if any.
 * Membership comes only from reviewed reconciliation; names, titles and group labels never qualify.
 */
function research_project_member_actor(PDO $pdo, int $studentId, array $live): ?array
{
    if (($live['role'] ?? '') !== 'student' || !$pdo->inTransaction()) return null;
    $project = research_project_for_student($pdo, $studentId, true);
    if (!$project) return null;
    foreach (research_project_members($pdo, (int)$project['id']) as $row) {
        if (!empty($row['archived_at']) || empty($row['profile_completed_at'])) continue;
        $login = lifecycle_resolve_login($pdo, $row, 'student');
        if ($login && (int)$login['id'] === (int)$live['id'] && $login['status'] === 'Active') return $row + ['project_id' => (int)$project['id']];
    }
    return null;
}

/** Read authorization: a Student sees their own record's documents and their own project's evidence record. */
function research_project_student_can_read(PDO $pdo, string $email, int $studentId): bool
{
    $q = $pdo->prepare('SELECT email FROM students WHERE id=?'); $q->execute([$studentId]);
    if (strcasecmp((string)$q->fetchColumn(), $email) === 0) return true;
    if (!research_projects_available($pdo)) return false;
    $q = $pdo->prepare('SELECT 1 FROM research_projects p JOIN research_project_members m ON m.project_id=p.id JOIN students me ON me.id=m.student_id
        WHERE p.submission_holder_id=? AND me.email=? AND me.archived_at IS NULL LIMIT 1');
    $q->execute([$studentId, $email]);
    return (bool)$q->fetchColumn();
}

/** Project evidence (documents, requirements, workflow) is held once, on the designated record. */
function research_project_evidence_owner(PDO $pdo, int $studentId): int
{
    $project = research_project_for_student($pdo, $studentId, $pdo->inTransaction());
    return $project ? (int)$project['submission_holder_id'] : $studentId;
}

/** The RPMS-designated Principal Investigator is a recorded fact, never derived from the evidence holder. */
function research_project_principal_investigator(PDO $pdo, int $projectId): ?array
{
    $q = $pdo->prepare("SELECT id,evidence_json,created_at FROM research_project_events WHERE project_id=? AND event_key='pi_designated' ORDER BY id DESC LIMIT 1");
    $q->execute([$projectId]); $event = $q->fetch(PDO::FETCH_ASSOC);
    if (!$event) return null;
    $evidence = json_decode($event['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
    $q = $pdo->prepare('SELECT s.id,s.full_name,s.student_id FROM research_project_members m JOIN students s ON s.id=m.student_id WHERE m.project_id=? AND m.student_id=?');
    $q->execute([$projectId, (int)$evidence['studentId']]); $member = $q->fetch(PDO::FETCH_ASSOC);
    // A designated Student who is no longer a member is reported as undesignated, never silently replaced.
    return $member ? ['studentId' => (int)$member['id'], 'name' => $member['full_name'], 'studentNumber' => $member['student_id'],
        'designatedAt' => $event['created_at'], 'eventId' => (int)$event['id'], 'source' => 'RPMS designation'] : null;
}

function research_project_slug(string $title): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    // libiconv transliterates "ñ" as "~n" where glibc gives "n"; drop the marks so identifiers are platform-independent.
    $ascii = str_replace(['~', '^', '`', '"', "'"], '', $ascii === false ? '' : $ascii);
    // Truncate first, then trim, so a long title never yields a trailing hyphen (B7-F22).
    $slug = trim(substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-'), 0, 160), '-');
    return $slug ?: 'research-project';
}

function research_project_identifier(PDO $pdo, string $title, ?int $own = null): string
{
    $base = research_project_slug($title);
    for ($n = 1; ; $n++) {
        $slug = $base.($n === 1 ? '' : '-'.$n);
        $q = $pdo->prepare('SELECT project_id FROM research_project_identifiers WHERE identifier=? FOR UPDATE'); $q->execute([$slug]);
        $id = $q->fetchColumn();
        if (!$id || (int)$id === $own) return $slug;
    }
}

function research_project_title(string $title): string
{
    $title = trim($title);
    if (!mb_check_encoding($title, 'UTF-8') || mb_strlen($title) < 3 || mb_strlen($title) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $title)) throw new AccountLifecycleValidation('Provide a research title of 3–255 characters.');
    return $title;
}

function research_project_members(PDO $pdo, int $projectId): array
{
    $q = $pdo->prepare('SELECT s.*,m.stage_key AS project_stage,m.workflow_revision FROM research_project_members m JOIN students s ON s.id=m.student_id WHERE m.project_id=? ORDER BY s.id FOR UPDATE');
    $q->execute([$projectId]); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function research_project_admin(PDO $pdo, array $actor): array
{
    $live = current_workflow_actor($pdo, $actor);
    if ($live['role'] !== 'admin' || !legal_admin_eligible($live)) throw new AccountLifecycleForbidden('Only an eligible RPMS Administrator may reconcile research projects.');
    return $live;
}

function research_project_event(PDO $pdo, array $actor, array $project, string $event, string $reason, array $evidence): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Project history requires a transaction.');
    $pdo->prepare('UPDATE research_projects SET revision=revision+1 WHERE id=?')->execute([$project['id']]);
    $q = $pdo->prepare('SELECT revision FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$project['id']]);
    $pdo->prepare('INSERT INTO research_project_events(project_id,revision,event_key,actor_user_id,reason,evidence_json) VALUES (?,?,?,?,?,?)')
        ->execute([$project['id'], $q->fetchColumn(), $event, $actor['id'], $reason, json_encode($evidence, JSON_THROW_ON_ERROR)]);
    $id = (int)$pdo->lastInsertId();
    document_workflow_audit($pdo, $actor, 'research_project_'.$event, ['entity_type'=>'research_project','entity_id'=>$project['id'],'student_id'=>null,
        'reason'=>$reason,'details'=>'Canonical project '.$project['id'].'; event '.$id]);
    return $id;
}

/** Read-only reconciliation preview: exact snapshots for RPMS to review. Nothing is inferred or changed. */
function research_project_preview(PDO $pdo, array $actor, array $studentIds): array
{
    if (!research_projects_available($pdo)) throw new AccountLifecycleConflict('Schema 13 is required.');
    if (!$studentIds || count($studentIds) > 50 || count(array_unique($studentIds)) !== count($studentIds)) throw new AccountLifecycleValidation('Select 1–50 distinct Student records.');
    foreach ($studentIds as $sid) if (!is_int($sid) || $sid < 1) throw new AccountLifecycleValidation('Invalid Student record.');
    $pdo->beginTransaction();
    try {
        research_project_fence($pdo); $live = research_project_admin($pdo, $actor); $mapping = [];
        foreach ($studentIds as $sid) {
            $row = current_workflow_student($pdo, $sid, $live);
            $q = $pdo->prepare('SELECT stage_key FROM ierb_workflows WHERE student_id=?'); $q->execute([$sid]);
            $mapping[] = ['studentId' => (int)$row['id'], 'snapshotSha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                'name' => $row['full_name'], 'studentNumber' => $row['student_id'], 'previousGroup' => $row['research_group'], 'previousTitle' => $row['research_title'],
                'adviserId' => (int)$row['adviser_id'], 'enrolledStage' => $q->fetchColumn() ?: null,
                'existingProject' => research_project_for_student($pdo, $sid)['public_identifier'] ?? null];
        }
        $pdo->commit();
        return ['ok' => true, 'mapping' => $mapping, 'note' => 'Review every legacy association, the evidence record and the Principal Investigator before applying. No membership changed.'];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** An operator-reviewed mapping binds exact Student snapshots, never similar titles.
 * Existing non-holder enrollments or incomplete legacy groups require reconciliation first.
 */
function research_project_reconcile(PDO $pdo, array $actor, string $title, int $holderId, int $adviserId, array $mapping, string $reason, string $historicalCompletionReference = ''): array
{
    ierb_v14_gate($pdo);
    if (!research_projects_available($pdo)) throw new AccountLifecycleConflict('Schema 13 is required.');
    $title = research_project_title($title); $reason = ierb_v14_reason($reason);
    if (!$mapping) throw new AccountLifecycleValidation('An explicit verified membership mapping is required.');
    $ids = array_column($mapping, 'studentId');
    if (count(array_unique($ids)) !== count($ids) || !in_array($holderId, $ids, true)) throw new AccountLifecycleValidation('Select distinct members including the submission holder.');
    foreach ($mapping as $m) if (!is_int($m['studentId'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $m['snapshotSha256'] ?? '')) throw new AccountLifecycleValidation('Each member requires an exact reviewed snapshot.');
    ierb_v14_begin($pdo);
    try {
        research_project_fence($pdo); $live = research_project_admin($pdo, $actor); $rows = [];
        foreach ($mapping as $m) {
            $q = $pdo->prepare('SELECT * FROM students WHERE id=? FOR UPDATE'); $q->execute([$m['studentId']]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals($m['snapshotSha256'], hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))) throw new AccountLifecycleConflict('Membership snapshot changed. Review the exact mapping again.');
            current_workflow_student($pdo, (int)$row['id'], $live);
            if ((int)$row['adviser_id'] !== $adviserId || research_project_for_student($pdo, (int)$row['id'], true)) throw new AccountLifecycleConflict('Existing membership or inconsistent Adviser assignment requires explicit administrative reconciliation.');
            if ($row['research_group'] !== null && $row['research_group'] !== '') {
                foreach (ierb_group_rows($pdo, ierb_group_identity($row)) as $associated) if (!in_array((int)$associated['id'], $ids, true)) throw new AccountLifecycleConflict('The mapping omits an associated legacy member. Review the complete legacy group.');
            }
            $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE student_id=? FOR UPDATE'); $q->execute([$row['id']]); $w = $q->fetch(PDO::FETCH_ASSOC);
            if ((int)$row['id'] === $holderId) $workflow = $w;
            elseif ($w) throw new AccountLifecycleConflict('Multiple enrolled workflows require explicit evidence reconciliation; they cannot be silently merged.');
            $rows[] = $row;
        }
        if (empty($workflow) || ierb_v14_case($pdo, (int)$workflow['id'])) throw new AccountLifecycleConflict('Choose a verified enrolled submission holder with no unresolved correction case.');
        $completion = null; $finalEvidence = null;
        if ($workflow['stage_key'] === IerbStage::COMPLETED) {
            if (trim($historicalCompletionReference) === '') throw new AccountLifecycleConflict('Historical completion requires an explicit institutional reconciliation reference.');
            $historicalCompletionReference=ierb_v14_reason($historicalCompletionReference);
            ierb_v14_initial_evidence($pdo,$workflow); ierb_v14_accepted_evidence($pdo,$workflow,'form33');
            $q=$pdo->prepare("SELECT * FROM ierb_workflow_events WHERE workflow_id=? AND event_key='final_report_submitted' ORDER BY id DESC FOR UPDATE"); $q->execute([$workflow['id']]); $events=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($events)!==1) throw new AccountLifecycleConflict('Historical completion evidence is missing or ambiguous.');
            $completion=$events[0]; $e=json_decode($completion['evidence_json'],true,512,JSON_THROW_ON_ERROR);
            $finalEvidence=ierb_v14_document_evidence($pdo,$holderId,$e['finalReport']['documentId'],'final_report');
            if ($finalEvidence!==array_intersect_key($e['finalReport'],$finalEvidence)) throw new AccountLifecycleConflict('Historical Final Report evidence changed.');
        }
        $adviser = lifecycle_rows($pdo, 'SELECT * FROM advisers WHERE id=? FOR UPDATE', [$adviserId])[0] ?? null;
        if (!$adviser || $adviser['status'] !== 'Active' || $adviser['archived_at'] || !$adviser['profile_completed_at']) throw new AccountLifecycleConflict('An active assigned Adviser is required.');
        $slug = research_project_identifier($pdo, $title);
        $pdo->prepare('INSERT INTO research_projects(public_identifier,research_title,adviser_id,submission_holder_id,workflow_id) VALUES (?,?,?,?,?)')->execute([$slug,$title,$adviserId,$holderId,$workflow['id']]);
        $projectId = (int)$pdo->lastInsertId(); $project = ['id'=>$projectId];
        if ($completion) $pdo->prepare('UPDATE research_projects SET completed_at=?,completion_document_id=? WHERE id=?')->execute([$completion['created_at'],$finalEvidence['documentId'],$projectId]);
        $pdo->prepare('INSERT INTO research_project_identifiers(identifier,project_id,research_title) VALUES (?,?,?)')->execute([$slug,$projectId,$title]);
        foreach ($rows as $row) {
            $pdo->prepare('INSERT INTO research_project_members(project_id,student_id,legacy_identity_json,stage_key,workflow_revision) VALUES (?,?,?,?,?)')
                ->execute([$projectId,$row['id'],json_encode($row,JSON_THROW_ON_ERROR),$workflow['stage_key'],$workflow['revision']]);
            $pdo->prepare('UPDATE students SET research_group=?,research_title=?,updated_at=NOW() WHERE id=?')->execute([$slug,$title,$row['id']]);
        }
        $event = research_project_event($pdo,$live,$project,'membership_reconciled',$reason,['verifiedMembers'=>$rows,'holderId'=>$holderId,'adviserId'=>$adviserId,'workflowId'=>(int)$workflow['id'],
            'historicalCompletionEventId'=>$completion ? (int)$completion['id'] : null,'historicalCompletionReference'=>$historicalCompletionReference]);
        foreach ($rows as $row) $pdo->prepare('INSERT INTO research_project_member_history(project_event_id,project_id,student_id,from_stage,to_stage,workflow_revision) VALUES (?,?,?,NULL,?,?)')->execute([$event,$projectId,$row['id'],$workflow['stage_key'],$workflow['revision']]);
        $pdo->commit(); return ['ok'=>true,'projectId'=>$projectId,'identifier'=>$slug,'memberCount'=>count($rows)];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** CLI administration deliberately preserves the reviewed operational activation boundary. */
function research_project_rename(PDO $pdo, array $actor, int $id, int $expectedRevision, string $title, string $reason): array
{
    ierb_v14_gate($pdo); $title = research_project_title($title); $reason = ierb_v14_reason($reason);
    ierb_v14_begin($pdo);
    try {
        research_project_fence($pdo); $live = research_project_admin($pdo,$actor);
        $q = $pdo->prepare('SELECT * FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$id]); $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['revision'] !== $expectedRevision) throw new AccountLifecycleConflict('The project changed. Review its current title and members.');
        $slug = research_project_identifier($pdo,$title,$id);
        $pdo->prepare('INSERT IGNORE INTO research_project_identifiers(identifier,project_id,research_title) VALUES (?,?,?)')->execute([$slug,$id,$title]);
        $pdo->prepare('UPDATE research_projects SET research_title=?,public_identifier=? WHERE id=?')->execute([$title,$slug,$id]);
        $pdo->prepare('UPDATE students s JOIN research_project_members m ON m.student_id=s.id SET s.research_title=?,s.research_group=?,s.updated_at=NOW() WHERE m.project_id=?')->execute([$title,$slug,$id]);
        research_project_event($pdo,$live,$p,'title_changed',$reason,['previousTitle'=>$p['research_title'],'previousIdentifier'=>$p['public_identifier'],'title'=>$title,'identifier'=>$slug]);
        $pdo->commit(); return ['ok'=>true,'projectId'=>$id,'identifier'=>$slug];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

function research_project_reassign_adviser(PDO $pdo, array $actor, int $id, int $expectedRevision, int $adviserId, string $reason): array
{
    ierb_v14_gate($pdo); $reason=ierb_v14_reason($reason); ierb_v14_begin($pdo);
    try {
        research_project_fence($pdo); $live=research_project_admin($pdo,$actor);
        $q=$pdo->prepare('SELECT * FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$id]); $p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['revision']!==$expectedRevision) throw new AccountLifecycleConflict('The project changed. Review its current assignment.');
        $adviser=lifecycle_rows($pdo,'SELECT * FROM advisers WHERE id=? FOR UPDATE',[$adviserId])[0] ?? null;
        if (!$adviser || $adviser['status']!=='Active' || $adviser['archived_at'] || !$adviser['profile_completed_at']) throw new AccountLifecycleConflict('Choose an active Adviser.');
        $login=lifecycle_resolve_login($pdo,$adviser,'adviser');
        if (!$login || $login['status']!=='Active') throw new AccountLifecycleConflict('Assigned Adviser login requires review.');
        $members=research_project_members($pdo,$id);
        foreach ($members as $row) current_workflow_student($pdo,(int)$row['id'],$live);
        $pdo->prepare('UPDATE research_projects SET adviser_id=? WHERE id=?')->execute([$adviserId,$id]);
        $pdo->prepare('UPDATE students s JOIN research_project_members m ON m.student_id=s.id SET s.adviser_id=?,s.updated_at=NOW() WHERE m.project_id=?')->execute([$adviserId,$id]);
        research_project_event($pdo,$live,$p,'adviser_reassigned',$reason,['previousAdviserId'=>(int)$p['adviser_id'],'adviserId'=>$adviserId,
            'previousMemberAssignments'=>array_map(static fn($r)=>['studentId'=>(int)$r['id'],'adviserId'=>$r['adviser_id']],$members)]);
        $pdo->commit(); return ['ok'=>true,'projectId'=>$id,'adviserId'=>$adviserId];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** PI is an identifying role only; it grants no upload, submission or approval permission. */
function research_project_designate_pi(PDO $pdo, array $actor, int $id, int $expectedRevision, int $studentId, string $reason): array
{
    ierb_v14_gate($pdo); $reason=ierb_v14_reason($reason); ierb_v14_begin($pdo);
    try {
        research_project_fence($pdo); $live=research_project_admin($pdo,$actor);
        $q=$pdo->prepare('SELECT * FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$id]); $p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['revision']!==$expectedRevision) throw new AccountLifecycleConflict('The project changed. Review its current members.');
        $members=array_map('intval',array_column(research_project_members($pdo,$id),'id'));
        if (!in_array($studentId,$members,true)) throw new AccountLifecycleValidation('Choose a verified member of this research project.');
        $previous=research_project_principal_investigator($pdo,$id);
        if ($previous && $previous['studentId']===$studentId) throw new AccountLifecycleConflict('This member is already the designated Principal Investigator.');
        research_project_event($pdo,$live,$p,'pi_designated',$reason,['studentId'=>$studentId,'previousStudentId'=>$previous['studentId']??null]);
        $pdo->commit(); return ['ok'=>true,'projectId'=>$id,'principalInvestigatorStudentId'=>$studentId];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** Validate membership, live accounts and authority before evidence can change progress. */
function research_project_validate(PDO $pdo, array $project, array $actor): array
{
    // A verified member may drive a formal-submission event (for example the Final Report); PI status is irrelevant.
    if (!in_array($actor['role'],['admin','adviser','student'],true)) throw new AccountLifecycleForbidden('Only authorized project participants may change project evidence.');
    $rows = research_project_members($pdo,(int)$project['id']); $holder = false;
    if ($actor['role']==='student' && !research_project_member_actor($pdo,(int)$project['submission_holder_id'],$actor)) throw new AccountLifecycleForbidden('Only a verified member of this research project may submit its evidence.');
    foreach ($rows as $row) {
        current_workflow_student($pdo,(int)$row['id'],$actor,true);
        $login=lifecycle_resolve_login($pdo,$row,'student');
        if (!empty($login['must_change_password']) || legal_outstanding($pdo,(int)$login['id'])) throw new AccountLifecycleConflict('A project member has incomplete account setup or legal acceptance.');
        if ((int)$row['adviser_id'] !== (int)$project['adviser_id'] || $row['research_group'] !== $project['public_identifier'] || $row['research_title'] !== $project['research_title']) throw new AccountLifecycleConflict('Canonical membership, research title or Adviser assignment is inconsistent. Administrative reconciliation required.');
        if ((int)$row['id'] === (int)$project['submission_holder_id']) $holder = true;
    }
    if (!$holder) throw new AccountLifecycleConflict('The designated submission holder is not a member.');
    return $rows;
}

/** One authoritative event fans out progress/history/notices, never document copies. */
function research_project_sync(PDO $pdo, array $actor, array $workflow, string $event, int $workflowEventId, array $evidence): array
{
    $p = research_project_for_student($pdo,(int)$workflow['student_id'],true);
    if (!$p) return [];
    if ((int)$p['submission_holder_id'] !== (int)$workflow['student_id'] || (int)$p['workflow_id'] !== (int)$workflow['id']) throw new AccountLifecycleConflict('The canonical submission authority changed.');
    $rows = research_project_validate($pdo,$p,$actor);
    foreach ($rows as $row) if ($row['project_stage'] !== $workflow['stage_key'] || (int)$row['workflow_revision'] !== (int)$workflow['revision']) throw new AccountLifecycleConflict('Member progress requires administrative reconciliation before another project event.');
    $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE id=? FOR UPDATE'); $q->execute([$workflow['id']]); $current = $q->fetch(PDO::FETCH_ASSOC);
    if ($event === 'final_report_submitted') {
        if ($p['completed_at']) throw new AccountLifecycleConflict('This project already has retained completion evidence.');
        $pdo->prepare('UPDATE research_projects SET completed_at=NOW(6),completion_document_id=? WHERE id=? AND completed_at IS NULL')->execute([$evidence['finalReport']['documentId'],$p['id']]);
    }
    $projectEvent = research_project_event($pdo,$actor,$p,$event,'Authoritative workflow event '.$workflowEventId,
        ['workflowEventId'=>$workflowEventId,'workflowId'=>(int)$workflow['id'],'workflowRevision'=>(int)$current['revision'],'memberIds'=>array_map('intval',array_column($rows,'id'))]);
    $notices = []; $adviserNotified=false;
    foreach ($rows as $row) {
        $pdo->prepare('UPDATE research_project_members SET stage_key=?,workflow_revision=? WHERE project_id=? AND student_id=?')->execute([$current['stage_key'],$current['revision'],$p['id'],$row['id']]);
        $pdo->prepare('INSERT INTO research_project_member_history(project_event_id,project_id,student_id,from_stage,to_stage,workflow_revision) VALUES (?,?,?,?,?,?)')->execute([$projectEvent,$p['id'],$row['id'],$row['project_stage'],$current['stage_key'],$current['revision']]);
        if ($row['project_stage'] !== $current['stage_key']) {
            document_workflow_audit($pdo,$actor,'research_project_member_progress',['entity_type'=>'student','entity_id'=>$row['id'],'student_id'=>$row['id'],
                'before'=>$row['project_stage'],'after'=>$current['stage_key'],'details'=>'Project '.$p['id'].'; workflow event '.$workflowEventId]);
            $adviserNotice=$adviserNotified ? null : ['subject'=>'PRISM research project progress',
                'message'=>'Research project '.$p['public_identifier'].' ('.count($rows).' Students) is now '.IerbStage::LABELS[$current['stage_key']].'.'];
            $notices = array_merge($notices,workflow_queue_notices($pdo,$actor,(int)$row['id'],[
                'subject'=>'PRISM research project progress','message'=>'Your research project '.$p['public_identifier'].' is now '.IerbStage::LABELS[$current['stage_key']].'.'],$adviserNotice));
            $adviserNotified=true;
        }
    }
    return $notices;
}
