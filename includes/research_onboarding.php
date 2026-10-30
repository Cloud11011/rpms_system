<?php
/** Staff-approved assignments confer no access until atomic canonical membership activation. */
require_once __DIR__.'/research_groups.php';
require_once __DIR__.'/ierb_v14_workflow.php';
require_once __DIR__.'/document_workflow.php';

function research_onboarding_available(PDO $pdo): bool
{
    return research_projects_available($pdo)
        && $pdo->query("SELECT v FROM schema_meta WHERE k='research_onboarding_v1'".($pdo->inTransaction()?' LOCK IN SHARE MODE':''))->fetchColumn() === '1';
}

function research_protocol_code(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .\/-]{0,99}\z/D', trim($value))) {
        throw new AccountLifecycleValidation('Protocol code must contain 1–100 letters, digits, spaces, dots, slashes or hyphens.');
    }
    return strtoupper(trim($value));
}

/** No fallback to a potentially conflicting Student value for canonical projects. */
function research_project_protocol(PDO $pdo, int $id): ?string
{
    if (!research_onboarding_available($pdo)) return null;
    $q=$pdo->prepare('SELECT protocol_code FROM research_project_protocols WHERE project_id=?'); $q->execute([$id]);
    return $q->fetchColumn() ?: null;
}

function research_onboarding_project(PDO $pdo, int $id): array
{
    $q=$pdo->prepare('SELECT p.*,s.program_key,s.academic_year,s.academic_unit_key,w.stage_key,w.revision AS workflow_revision,
        a.full_name AS adviser_name FROM research_projects p JOIN students s ON s.id=p.submission_holder_id
        JOIN ierb_workflows w ON w.id=p.workflow_id JOIN advisers a ON a.id=p.adviser_id WHERE p.id=? FOR UPDATE');
    $q->execute([$id]); $p=$q->fetch(PDO::FETCH_ASSOC);
    if (!$p || $p['completed_at'] !== null || $p['stage_key'] === IerbStage::COMPLETED) {
        throw new AccountLifecycleConflict('Choose an eligible, unfinished research group. Completed projects cannot accept new members.');
    }
    return $p;
}

/** Called under the shared Student fence. Academic matching intentionally excludes year level. */
function research_onboarding_intent(PDO $pdo, array $actor, array $data, array $academic, ?int $ownStudentId = null): array
{
    if (!research_onboarding_available($pdo)) throw new AccountLifecycleConflict('Research assignment setup is awaiting the reviewed local database extension.');
    research_project_admin($pdo,$actor);
    $selection=$data['group']??null;
    if (!is_string($selection) || ($selection !== '__create__' && !preg_match('/\Aproject:[1-9][0-9]{0,9}\z/D',$selection))) {
        throw new AccountLifecycleValidation('Choose Create New Group or Join Existing Group.');
    }
    $intent=['selection'=>$selection,'academic'=>$academic,'pi'=>($data['isPrincipalInvestigator']??false)===true,
        'reason'=>ierb_v14_reason(onboarding_text($data['membershipReason']??'RPMS approved the research assignment during Student onboarding.','Membership change reason',1000)),
        'confirmed'=>($data['membershipConfirmed']??false)===true];
    if ($selection === '__create__') {
        $intent['title']=research_project_title(onboarding_text($data['research']??null,'Research title',255));
        $intent['adviserId']=onboarding_active_adviser($pdo,$data['adviserId']??null);
        if (!$intent['adviserId']) throw new AccountLifecycleValidation('An assigned research Adviser is required for a new group.');
        $intent['protocolCode']=research_protocol_code($data['protocolCode']??null);
        if ($intent['protocolCode']) {
            $q=$pdo->prepare('SELECT project_id FROM research_project_protocols WHERE protocol_code=?'); $q->execute([$intent['protocolCode']]);
            if ($q->fetchColumn()) throw new AccountLifecycleValidation('This protocol code already belongs to another research group. Select the existing group or use an unassigned code.');
        }
        $q=$pdo->prepare('SELECT id FROM research_projects WHERE research_title=?'); $q->execute([$intent['title']]);
        $pending=$pdo->prepare("SELECT student_id FROM research_onboarding_assignments WHERE state IN ('pending','review') AND JSON_UNQUOTE(JSON_EXTRACT(assignment_json,'$.title'))=? AND student_id<>?"); $pending->execute([$intent['title'],$ownStudentId??0]);
        if (($q->fetchColumn() || $pending->fetchColumn()) && ($data['duplicateTitleConfirmed']??false)!==true) {
            throw new AccountLifecycleValidation('A group or pending assignment has this title. Select that group, or confirm creating a separate group with the same title.');
        }
    } else {
        $p=research_onboarding_project($pdo,(int)substr($selection,8));
        if ($p['program_key']!==$academic['program_key'] || $p['academic_year']!==$academic['academic_year']) {
            throw new AccountLifecycleValidation('The selected group must match the program and academic year. Year levels may differ.');
        }
        research_project_validate($pdo,$p,$actor);
        $intent+=['projectId'=>(int)$p['id'],'title'=>$p['research_title'],'adviserId'=>(int)$p['adviser_id'],
            'protocolCode'=>research_project_protocol($pdo,(int)$p['id'])];
        $q=$pdo->prepare('SELECT COUNT(*) FROM documents WHERE student_id=?'); $q->execute([$p['submission_holder_id']]);
        if ($q->fetchColumn() && (!$intent['confirmed'] || !array_key_exists('membershipReason',$data))) {
            throw new AccountLifecycleValidation('This group has submitted evidence. Confirm the membership change and give a reason before adding a member.');
        }
        if ($intent['pi'] && research_project_principal_investigator($pdo,(int)$p['id'])) {
            throw new AccountLifecycleValidation('This group already has a Principal Investigator. Use the audited group change controls to replace the PI.');
        }
    }
    return $intent;
}

function research_onboarding_store(PDO $pdo, array $actor, int $sid, array $intent): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Assignment requires the account transaction.');
    if (!research_onboarding_available($pdo)) throw new AccountLifecycleConflict('Research onboarding is unavailable during schema maintenance.');
    research_project_admin($pdo,$actor);
    if (research_project_for_student($pdo,$sid,true)) throw new AccountLifecycleConflict('This Student already has verified membership.');
    $q=$pdo->prepare('SELECT stage,research_group FROM students WHERE id=? FOR UPDATE'); $q->execute([$sid]); $student=$q->fetch(PDO::FETCH_ASSOC);
    if (!$student || $student['stage']!=='Stage 1' || trim((string)$student['research_group'])!=='') throw new AccountLifecycleConflict('Historical progress or a legacy research association requires a reviewed institutional transition before a new onboarding assignment.');
    $q=$pdo->prepare('SELECT * FROM research_onboarding_assignments WHERE student_id=? FOR UPDATE'); $q->execute([$sid]); $old=$q->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO research_onboarding_assignments(student_id,approved_by_user_id,assignment_json) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE approved_by_user_id=VALUES(approved_by_user_id),assignment_json=VALUES(assignment_json),state='pending',project_id=NULL,revision=revision+1,updated_at=NOW(6)")
        ->execute([$sid,$actor['id'],json_encode($intent,JSON_THROW_ON_ERROR)]);
    $pdo->prepare('UPDATE students SET research_title=?,adviser_id=?,updated_at=NOW() WHERE id=?')->execute([$intent['title'],$intent['adviserId'],$sid]);
    document_workflow_audit($pdo,$actor,'research_assignment_approved',['entity_type'=>'student','entity_id'=>$sid,'student_id'=>$sid,
        'reason'=>$intent['reason']."\nAssignment evidence: ".json_encode(['previous'=>$old?json_decode($old['assignment_json'],true):null,'assignment'=>$intent],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        'before'=>$old?hash('sha256',$old['assignment_json']):null,'after'=>hash('sha256',json_encode($intent,JSON_THROW_ON_ERROR)),
        'details'=>'Staff-approved assignment revision '.($old?(int)$old['revision']+1:1).'; '.$intent['selection']]);
}

/** One transaction, one migration mutex, one membership fence. Never reconciles later members. */
function research_onboarding_activate(PDO $pdo, int $sid): array
{
    if (!research_onboarding_available($pdo)) return ['state'=>'unavailable'];
    try { ierb_v14_gate($pdo); } catch (AccountLifecycleConflict $e) { return ['state'=>'pending','message'=>'Research setup is awaiting institutional activation.']; }
    ierb_v14_begin($pdo);
    try {
        research_project_fence($pdo);
        $q=$pdo->prepare('SELECT * FROM research_onboarding_assignments WHERE student_id=? FOR UPDATE'); $q->execute([$sid]); $a=$q->fetch(PDO::FETCH_ASSOC);
        if (!$a || $a['state']!=='pending') { $pdo->commit(); return ['state'=>$a['state']??'none']; }
        $intent=json_decode($a['assignment_json'],true,512,JSON_THROW_ON_ERROR);
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE'); $q->execute([$a['approved_by_user_id']]); $admin=$q->fetch(PDO::FETCH_ASSOC);
        // Authority is revalidated at use time; stored approval is never a privilege bypass.
        if (!$admin) throw new AccountLifecycleForbidden('The approving Administrator is unavailable; Admin review is required.');
        research_project_admin($pdo,$admin);
        $q=$pdo->prepare('SELECT * FROM students WHERE id=? FOR UPDATE'); $q->execute([$sid]); $s=$q->fetch(PDO::FETCH_ASSOC);
        if (!$s || $s['archived_at'] || !$s['profile_completed_at']) { $pdo->commit(); return ['state'=>'pending']; }
        $login=lifecycle_resolve_login($pdo,$s,'student');
        if (!$login || $login['status']!=='Active' || !empty($login['must_change_password']) || legal_outstanding($pdo,(int)$login['id'])) { $pdo->commit(); return ['state'=>'pending']; }
        $q=$pdo->prepare('SELECT accepted_at FROM account_invitations WHERE user_id=? FOR UPDATE'); $q->execute([$login['id']]);
        $inv=$q->fetch(PDO::FETCH_ASSOC);
        if ($inv && !$inv['accepted_at']) { $pdo->commit(); return ['state'=>'pending']; }
        if ($s['program_key']!==$intent['academic']['program_key'] || $s['academic_year']!==$intent['academic']['academic_year']) {
            $pdo->prepare("UPDATE research_onboarding_assignments SET state='review',updated_at=NOW(6) WHERE student_id=?")->execute([$sid]);
            document_workflow_audit($pdo,$admin,'research_assignment_review_required',['entity_type'=>'student','entity_id'=>$sid,'student_id'=>$sid,'reason'=>'Profile program or academic year differs from the approved research assignment.']);
            $pdo->commit(); return ['state'=>'review'];
        }
        current_workflow_student($pdo,$sid,$admin);
        if ($s['stage']!=='Stage 1' || trim((string)$s['research_group'])!=='') throw new AccountLifecycleConflict('Historical progress or a legacy research association requires reviewed institutional reconciliation.');
        if (research_project_for_student($pdo,$sid,true)) throw new AccountLifecycleConflict('Student already belongs to a canonical group.');
        onboarding_active_adviser($pdo,$intent['adviserId']);
        $q=$pdo->prepare('SELECT * FROM advisers WHERE id=? FOR UPDATE'); $q->execute([$intent['adviserId']]); $adviser=$q->fetch(PDO::FETCH_ASSOC);
        // The Administrator has already selected a verified active Adviser.
        // Do not hold up a Student's research membership because that Adviser's
        // own password, legal acknowledgement, or recovery flow is unfinished.
        // Adviser actions remain gated by the Adviser's own authenticated account.
        $q=$pdo->prepare('SELECT id FROM ierb_workflows WHERE student_id=? FOR UPDATE'); $q->execute([$sid]);
        if ($q->fetchColumn()) throw new AccountLifecycleConflict('An existing workflow requires reviewed evidence reconciliation.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM documents WHERE student_id=?'); $q->execute([$sid]);
        if ($q->fetchColumn()) throw new AccountLifecycleConflict('Existing individual evidence requires institutional review before membership.');
        if (isset($intent['projectId'])) {
            $p=research_onboarding_project($pdo,$intent['projectId']);
            if ($p['program_key']!==$s['program_key'] || $p['academic_year']!==$s['academic_year'] || (int)$p['adviser_id']!==$intent['adviserId']) throw new AccountLifecycleConflict('The intended group assignment changed. Request Admin review.');
            research_project_validate($pdo,$p,$admin);
            $q=$pdo->prepare('SELECT COUNT(*) FROM documents WHERE student_id=?'); $q->execute([$p['submission_holder_id']]);
            if ($q->fetchColumn() && !$intent['confirmed']) throw new AccountLifecycleConflict('New evidence requires a confirmed membership review in Student Records.');
            $wf=['id'=>$p['workflow_id'],'stage_key'=>$p['stage_key'],'revision'=>$p['workflow_revision']];
        } else {
            $pdo->prepare('INSERT INTO ierb_workflows(student_id,stage_key,legacy_stage_snapshot,enrollment_evidence,enrolled_by_user_id) VALUES (?,?,?,?,?)')
                ->execute([$sid,IerbStage::INITIAL,$s['stage'],'RPMS-approved new group; verified account, academic catalog and Adviser prerequisites.',$admin['id']]);
            $wf=['id'=>(int)$pdo->lastInsertId(),'student_id'=>$sid,'stage_key'=>IerbStage::INITIAL,'revision'=>1];
            ierb_v14_event($pdo,$admin,$wf,'enrolled',$s['stage'],IerbStage::INITIAL,'onboarding-student-'.$sid,hash('sha256',$a['assignment_json']),$intent['reason'],null,['assignmentStudentId'=>$sid]);
            $slug=research_project_identifier($pdo,$intent['title']);
            $pdo->prepare('INSERT INTO research_projects(public_identifier,research_title,adviser_id,submission_holder_id,workflow_id) VALUES (?,?,?,?,?)')->execute([$slug,$intent['title'],$intent['adviserId'],$sid,$wf['id']]);
            $pid=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO research_project_identifiers(identifier,project_id,research_title) VALUES (?,?,?)')->execute([$slug,$pid,$intent['title']]);
            $p=['id'=>$pid,'public_identifier'=>$slug,'research_title'=>$intent['title'],'adviser_id'=>$intent['adviserId']];
        }
        $pdo->prepare('INSERT INTO research_project_members(project_id,student_id,legacy_identity_json,stage_key,workflow_revision) VALUES (?,?,?,?,?)')
            ->execute([$p['id'],$sid,json_encode($s,JSON_THROW_ON_ERROR),$wf['stage_key'],$wf['revision']]);
        $code=isset($intent['projectId'])?research_project_protocol($pdo,(int)$p['id']):$intent['protocolCode'];
        $pdo->prepare('UPDATE students SET research_title=?,research_group=?,adviser_id=?,protocol_code=?,is_principal_investigator=0,updated_at=NOW() WHERE id=?')
            ->execute([$p['research_title'],$p['public_identifier'],$p['adviser_id'],$code,$sid]);
        $event=research_project_event($pdo,$admin,$p,'member_added',$intent['reason'],['studentId'=>$sid,'assignmentRevision'=>(int)$a['revision'],'workflowId'=>(int)$wf['id'],'confirmed'=>$intent['confirmed']]);
        $pdo->prepare('INSERT INTO research_project_member_history(project_event_id,project_id,student_id,from_stage,to_stage,workflow_revision) VALUES (?,?,?,NULL,?,?)')
            ->execute([$event,$p['id'],$sid,$wf['stage_key'],$wf['revision']]);
        if (!isset($intent['projectId']) && $code) research_project_protocol_locked($pdo,$admin,$p,$code,$intent['reason']);
        if ($intent['pi']) {
            if (research_project_principal_investigator($pdo,(int)$p['id'])) throw new AccountLifecycleConflict('The group now has a PI; Admin review is required.');
            $pdo->prepare('UPDATE students SET is_principal_investigator=1 WHERE id=?')->execute([$sid]);
            research_project_event($pdo,$admin,$p,'pi_designated',$intent['reason'],['studentId'=>$sid,'previousStudentId'=>null]);
        }
        $pdo->prepare("UPDATE research_onboarding_assignments SET state='activated',project_id=?,updated_at=NOW(6) WHERE student_id=?")->execute([$p['id'],$sid]);
        $pdo->commit(); return ['state'=>'activated','projectId'=>(int)$p['id']];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** The unique code is authoritative; compatibility fields change atomically with its audit event. */
function research_project_protocol_locked(PDO $pdo, array $admin, array $p, ?string $code, string $reason): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Protocol assignment requires the project transaction.');
    $admin=research_project_admin($pdo,$admin); $reason=ierb_v14_reason($reason); $code=research_protocol_code($code);
    $old=research_project_protocol($pdo,(int)$p['id']);
    if ($old===$code) return;
    $q=$pdo->prepare('SELECT id FROM students WHERE protocol_code=? AND id NOT IN (SELECT student_id FROM research_project_members WHERE project_id=?)'); $q->execute([$code,$p['id']]);
    if ($code && $q->fetchColumn()) throw new AccountLifecycleConflict('This protocol code is already used by another project or legacy record.');
    try {
        $q=$pdo->prepare('SELECT project_id FROM research_project_protocols WHERE project_id=? FOR UPDATE'); $q->execute([$p['id']]);
        if ($q->fetchColumn()) $pdo->prepare('UPDATE research_project_protocols SET protocol_code=? WHERE project_id=?')->execute([$code,$p['id']]);
        else $pdo->prepare('INSERT INTO research_project_protocols(project_id,protocol_code) VALUES (?,?)')->execute([$p['id'],$code]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1]??0)===1062) throw new AccountLifecycleConflict('This protocol code already belongs to another project.');
        throw $e;
    }
    $pdo->prepare('UPDATE students s JOIN research_project_members m ON m.student_id=s.id SET s.protocol_code=?,s.updated_at=NOW() WHERE m.project_id=?')->execute([$code,$p['id']]);
    research_project_event($pdo,$admin,$p,'protocol_code_changed',$reason,['previousCode'=>$old,'protocolCode'=>$code]);
}

/** Resume automatically after password, legal and profile gates, or when staff review records. */
function research_onboarding_resume(PDO $pdo, array $user): void
{
    if ($pdo->inTransaction() || !research_onboarding_available($pdo)) return;
    if ($user['role']==='student') {
        $s=onboarding_profile($pdo,$user); $ids=$s?[(int)$s['id']]:[];
    } elseif (in_array($user['role'],['admin','adviser'],true)) {
        $sql="SELECT r.student_id FROM research_onboarding_assignments r JOIN students s ON s.id=r.student_id WHERE r.state='pending' AND s.profile_completed_at IS NOT NULL AND s.archived_at IS NULL";
        $params=[];
        if ($user['role']==='adviser') { $sql.=' AND s.adviser_id IN (SELECT id FROM advisers WHERE email=?)'; $params[]=$user['email']; }
        $q=$pdo->prepare($sql.' ORDER BY r.updated_at LIMIT 20'); $q->execute($params); $ids=$q->fetchAll(PDO::FETCH_COLUMN);
    } else return;
    foreach ($ids as $sid) {
        try { research_onboarding_activate($pdo,(int)$sid); }
        catch (AccountLifecycleConflict|AccountLifecycleForbidden|AccountLifecycleValidation $e) { /* Durable pending assignment remains reviewable. */ }
        catch (Throwable $e) { log_api_error('research_onboarding','Assignment activation deferred; student ID '.(int)$sid); }
    }
}
