<?php
require_once __DIR__.'/ierb_v14_requirements.php';
/** Frozen-v10 document transitions. The caller authenticates transport/session first. */
class DocumentWorkflowError extends RuntimeException
{
    public function __construct(string $message, public int $status = 409) { parent::__construct($message); }
}

/** Display columns have a frozen 190-character budget; audit keeps the full identity. */
function document_attribution(string $name, string $suffix = ''): string
{
    return mb_substr($name,0,max(0,190-mb_strlen($suffix,'UTF-8')),'UTF-8').$suffix;
}

function document_returned_sql(string $alias = 'd'): string
{
    return "EXISTS (SELECT 1 FROM activity_logs wr WHERE wr.entity_type='document' AND wr.entity_id=$alias.id AND wr.action='document_returned_for_revision')";
}

/** Caller holds the Student lock. Contradictory current flags require Admin data review. */
function document_current_version(PDO $pdo, int $studentId, string $stage, string $type): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Current-version validation requires a transaction.');
    $q = $pdo->prepare('SELECT * FROM documents WHERE student_id=? AND stage=? AND document_type=? AND is_current=1
        ORDER BY version_no DESC,uploaded_at DESC,id DESC LIMIT 2 FOR UPDATE');
    $q->execute([$studentId,$stage,$type]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new DocumentWorkflowError('This document has conflicting current versions. Contact RPMS for record review; nothing was changed.',409);
    return $rows[0] ?? null;
}

/** Return is immutable evidence; it never replaces the submitted version's approval. */
function document_with_return(PDO $pdo, array $doc, bool $lock = false): array
{
    $q = $pdo->prepare("SELECT reason, actor_name, created_at FROM activity_logs
        WHERE entity_type='document' AND entity_id=? AND action='document_returned_for_revision'
        ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$doc['id']]);
    $event = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    return $doc + ['rpms_returned_at'=>$event['created_at'] ?? null,
        'rpms_returned_by'=>$event['actor_name'] ?? null, 'rpms_return_reason'=>$event['reason'] ?? null];
}

/** Mandatory evidence must fail the transaction, unlike best-effort legacy audit_log. */
function document_workflow_audit(PDO $pdo, array $actor, string $action, array $ctx): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Document evidence requires a transaction.');
    $q=$pdo->prepare('INSERT INTO activity_logs
        (user_email,action,details,actor_name,actor_role,entity_type,entity_id,student_id,reason,before_value,after_value,is_override)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([
        $actor['email'], $action, mb_substr('Actor user ID '.($actor['id']??'legacy').'; '.($ctx['details'] ?? ''),0,255), $actor['full_name'], $actor['role'],
        $ctx['entity_type'] ?? 'document', (string)$ctx['entity_id'], $ctx['student_id'], $ctx['reason'] ?? null,
        $ctx['before'] ?? null, $ctx['after'] ?? null, !empty($ctx['override']) ? 1 : 0,
    ]);
    if ($q->rowCount()!==1) throw new RuntimeException('Document evidence was not persisted.');
}

/** One B7 workflow campaign, independent recipient copies, no delivery before commit. */
function document_workflow_notifications(PDO $pdo, array $actor, array $student, array $adviser,
                                         string $subject, string $message, bool $admins): array
{
    $recipients = [['student',(int)$student['id'],$student['email'],$student['full_name']]];
    if ($admins) {
        $q=$pdo->query("SELECT id,email,full_name FROM users WHERE role='admin' AND status='Active' ORDER BY id FOR UPDATE");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $recipients[]=['admin',(int)$a['id'],$a['email'],$a['full_name']];
    } elseif ($adviser) {
        // Existing institutional/override changes notify the assigned Adviser as well.
        $recipients[]=['adviser',(int)$adviser['id'],$adviser['email'],$adviser['full_name']];
    }
    $schedule=date('Y-m-d H:i:s');
    $campaign=notification_create_campaign($pdo,$actor,'workflow',$subject,$message,$schedule);
    $ids=[];
    foreach ($recipients as [$type,$id,$email,$name]) {
        $copy=create_notification($pdo,$type,$id,(string)$email,(string)$name,$subject,$message,
            'Status Update',$actor['full_name'],null,$schedule,null,'workflow',$campaign);
        if (!isset($copy['id'])) throw new DocumentWorkflowError('The workflow recipient is no longer current.',409);
        $ids[]=(int)$copy['id'];
    }
    return $ids;
}

/** Serialize on the Student, then revalidate live identities, assignment and exact version. */
function document_workflow_change(PDO $pdo, array $actor, string $id, string $operation,
                                   string $status = '', string $reason = '', ?int $expectedVersion = null, ?string $expectedStatus = null): array
{
    if (!preg_match('/\A[a-f0-9]{24}\z/',$id)) throw new DocumentWorkflowError('Provide a valid document ID.',422);
    if (!in_array($operation,['review','return','submit','member_submit','override','comment'],true)) throw new DocumentWorkflowError('Invalid document action.',422);
    $role=$actor['role']??'';
    $roles=match($operation) { 'review'=>['adviser'], 'return','override'=>['admin'], 'submit'=>['admin'], 'member_submit'=>['student'], 'comment'=>['adviser','admin'] };
    if ($operation==='member_submit' && $expectedVersion===null) throw new DocumentWorkflowError('Refresh and confirm the exact version before formal submission.',422);
    if (!in_array($role,$roles,true)) throw new DocumentWorkflowError('Only the assigned Adviser may record an ordinary review. RPMS interventions require Admin Override.',403);
    $reason=trim($reason);
    if (mb_strlen($reason)>5000 || ($operation==='return' && $reason==='')
        || ($operation==='comment' && $reason==='')
        || ($operation==='review' && in_array($status,['Denied','Resubmission Requested'],true) && $reason==='')) {
        throw new DocumentWorkflowError('Add a reason or reviewer remarks using no more than 5,000 characters.',422);
    }
    if (in_array($operation,['review','override'],true) && !in_array($status,['Submitted','Under Review','Received','Verified','Approved','Denied','Resubmission Requested'],true)) {
        throw new DocumentWorkflowError('Invalid review status.',422);
    }
    if (($operation==='override' || ($operation==='submit' && $role==='admin')) && !override_reason_valid($reason)) {
        throw new DocumentWorkflowError(override_reason_message(),422);
    }
    if ($pdo->inTransaction()) throw new LogicException('Document transition owns its transaction.');
    // Discovery outside the transaction avoids a stale repeatable-read snapshot after waiting.
    $q=$pdo->prepare('SELECT student_id FROM documents WHERE id=?'); $q->execute([$id]); $sid=$q->fetchColumn();
    if (!$sid) throw new DocumentWorkflowError('Document has no eligible Student record.',409);
    $pdo->beginTransaction();
    try {
        research_project_fence($pdo);
        $q=$pdo->prepare('SELECT * FROM students WHERE id=? FOR UPDATE'); $q->execute([$sid]); $student=$q->fetch(PDO::FETCH_ASSOC);
        try { $student=current_workflow_student($pdo,(int)$sid,$actor,$operation==='member_submit'); }
        catch (AccountLifecycleForbidden $e) { throw new DocumentWorkflowError($e->getMessage(),403); }
        catch (AccountLifecycleConflict $e) { throw new DocumentWorkflowError($e->getMessage(),$e->getMessage()==='The Student account is inactive.'?403:409); }
        if (!$student || !empty($student['archived_at']) || empty($student['profile_completed_at'])) {
            throw new DocumentWorkflowError('The Student record is unavailable for current workflow.',409);
        }
        purge_require_no_pending($pdo,'student',(int)$sid);
        $login=lifecycle_resolve_login($pdo,$student,'student');
        if (!$login || $login['status']!=='Active') throw new DocumentWorkflowError('The Student account is inactive.',403);
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE'); $q->execute([$actor['id']]); $live=$q->fetch(PDO::FETCH_ASSOC);
        if (!$live || $live['role']!==$role || $live['status']!=='Active' || $live['email']!==$actor['email']
            || !hash_equals((string)$live['password_hash'],(string)$actor['password_hash']) || !empty($live['must_change_password'])) {
            throw new DocumentWorkflowError('Your account changed. Sign in again before continuing.',403);
        }
        if (legal_outstanding($pdo,(int)$live['id'])) throw new DocumentWorkflowError('Review the current legal policies before continuing.',403);
        if ($role==='admin' && !legal_admin_eligible($live)) throw new DocumentWorkflowError('An eligible active RPMS Admin is required.',403);
        $q=$pdo->prepare('SELECT * FROM advisers WHERE id=? FOR UPDATE'); $q->execute([$student['adviser_id']]); $adviser=$q->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($role==='adviser') {
            if (!$adviser || !empty($adviser['archived_at']) || strcasecmp((string)$adviser['status'],'Active')!==0 || empty($adviser['profile_completed_at'])) {
                throw new DocumentWorkflowError('An active assigned Adviser is required.',403);
            }
            $assigned=lifecycle_resolve_login($pdo,$adviser,'adviser');
            if (!$assigned || (int)$assigned['id']!==(int)$live['id'] || $assigned['status']!=='Active') {
                throw new DocumentWorkflowError('Only the currently assigned Adviser may review this Student.',403);
            }
            purge_require_no_pending($pdo,'adviser',(int)$adviser['id']);
        } elseif ($adviser) {
            if (!empty($adviser['archived_at']) || strcasecmp((string)$adviser['status'],'Active')!==0 || empty($adviser['profile_completed_at'])) $adviser=[];
            else {
                try { $assigned=lifecycle_resolve_login($pdo,$adviser,'adviser'); }
                catch (AccountLifecycleConflict $e) { $assigned=null; }
                if (!$assigned || $assigned['status']!=='Active') $adviser=[];
            }
        }
        $q=$pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $q->execute([$id]); $doc=$q->fetch(PDO::FETCH_ASSOC);
        if (!$doc || (int)$doc['student_id']!==(int)$sid || (int)$doc['is_current']!==1) {
            throw new DocumentWorkflowError('This is an older or changed version. Refresh and use the current version.',409);
        }
        if (($expectedVersion!==null && $expectedVersion!==(int)$doc['version_no'])
            || ($expectedStatus!==null && $expectedStatus!==$doc['review_status'])) {
            throw new DocumentWorkflowError('This document changed. Refresh before continuing.',409);
        }
        $currentVersion=document_current_version($pdo,(int)$sid,$doc['stage'],$doc['document_type']);
        if (!$currentVersion || $currentVersion['id']!==$id) throw new DocumentWorkflowError('Review the authoritative latest version instead.',409);
        $doc=document_with_return($pdo,$doc,true);
        $before=document_workflow_state($doc); $audit=['entity_id'=>$id,'student_id'=>(int)$sid,
            'details'=>'Version '.(int)$doc['version_no'].' ('.$doc['document_type'].', '.$doc['stage'].')','reason'=>$reason];
        $ids=[]; $advanced=null;
        if ($operation==='review' && $status==='Approved' && $doc['review_status']==='Approved'
            && !empty($doc['rpms_submitted_at']) && empty($doc['rpms_returned_at'])
            && $reason === (string)($doc['review_remarks'] ?? '')) {
            $pdo->commit();
            return ['ok'=>true,'noChange'=>true,'message'=>'This approved version is already formally submitted.',
                'workflowState'=>WF_SUBMITTED_RPMS,'stageAdvanced'=>false,'notificationIds'=>[]];
        }
        if ($operation !== 'comment' && ierb_v14_schema_available($pdo)) {
            $q=$pdo->prepare('SELECT stage_key FROM ierb_workflows WHERE student_id=? FOR UPDATE'); $q->execute([$sid]);
            if ($q->fetchColumn() === IerbStage::COMPLETED) throw new DocumentWorkflowError('This completed V14 submission retains its document evidence.',409);
        }
        if ($operation==='return') {
            if ($before!==WF_SUBMITTED_RPMS || $doc['review_status']!=='Approved') {
                throw new DocumentWorkflowError('Only a current formally submitted version can be returned; it may already have changed.',409);
            }
            document_workflow_audit($pdo,$live,'document_returned_for_revision',$audit+['before'=>$before,'after'=>WF_RETURNED_REVISION]);
            $message='RPMS returned "'.$doc['original_name'].'" ('.$doc['document_type'].', version '.$doc['version_no'].') for revision. Reason: '.$reason.'. Next action: upload a NEW PDF/DOCX revision; your Adviser must review it.';
            $ids=document_workflow_notifications($pdo,$live,$student,$adviser,'PRISM RPMS Return for Revision',$message,false);
            $after=WF_RETURNED_REVISION;
        } elseif ($operation==='comment') {
            // Append public feedback without touching the authoritative review or its attribution.
            document_workflow_audit($pdo,$live,'document_commented',$audit+['before'=>$before,'after'=>$before]);
            $message='Comment on "'.$doc['original_name'].'" (version '.$doc['version_no'].'): '.$reason;
            $ids=document_workflow_notifications($pdo,$live,$student,[],'PRISM Document Comment',$message,false);
            $after=$before;
        } elseif ($operation==='override') {
            if (document_is_locked($doc) || !empty($doc['rpms_returned_at'])) throw new DocumentWorkflowError('A submitted or returned version is immutable. Use Return for Revision or the IERB controls.',409);
            if ($status===$doc['review_status']) throw new DocumentWorkflowError('Choose a different status to override.',422);
            // The original Adviser review fields remain evidence even when RPMS overrides the decision.
            $pdo->prepare('UPDATE documents SET review_status=?,admin_override=1,override_reason=?,override_by=?,override_at=NOW() WHERE id=?')
                ->execute([$status,$reason,$live['full_name'],$id]);
            $after=document_workflow_state(array_replace($doc,['review_status'=>$status]));
            $audit['details'].='; review='.$doc['review_status'].' -> '.$status;
            document_workflow_audit($pdo,$live,'admin_override_document',$audit+['before'=>$before,'after'=>$after,'override'=>true]);
            $message='RPMS updated the status of "'.$doc['original_name'].'" (version '.$doc['version_no'].'). Open Documents for its official state. This does not grant IERB approval.';
            $ids=document_notice_atomic($pdo,$live,(int)$sid,'PRISM Document Status Update',$message,
                'Admin Override on a document',$audit['details'].'; '.$before.' -> '.$after.'. Reason: '.$reason);
        } elseif ($operation==='member_submit') {
            // Any verified project member (or the record owner) forwards an Adviser approval; PI status grants nothing extra.
            if (document_is_locked($doc) || !empty($doc['rpms_returned_at'])) throw new DocumentWorkflowError('This version was already submitted or returned. Refresh to see its current state.',409);
            if ($doc['review_status']!=='Approved' || !empty($doc['admin_override']) || trim((string)$doc['reviewed_by'])==='') {
                throw new DocumentWorkflowError('Only a version approved by the assigned Adviser can be formally submitted by a Student.',409);
            }
            [$advanced,$ids,$message]=document_formal_submission($pdo,$live,$student,$doc,$audit);
            $after=WF_SUBMITTED_RPMS;
        } elseif ($operation==='submit') {
            if (document_is_locked($doc) || !empty($doc['rpms_returned_at'])) throw new DocumentWorkflowError('This version was already submitted or returned. Refresh and use the current revision.',409);
            if ($role==='admin') {
                $pdo->prepare("UPDATE documents SET review_status='Approved',admin_override=1,override_reason=?,override_by=?,override_at=NOW() WHERE id=?")
                    ->execute([$reason,$live['full_name'],$id]);
            }
            [$advanced,$ids,$message]=document_formal_submission($pdo,$live,$student,$doc,$audit);
            $after=WF_SUBMITTED_RPMS;
        } else {
            if (!empty($doc['rpms_submitted_at']) || !empty($doc['rpms_returned_at'])) {
                throw new DocumentWorkflowError('This version is already resolved. Refresh to see its current status.',409);
            }
            if (in_array($doc['review_status'],['Denied','Resubmission Requested'],true)) {
                throw new DocumentWorkflowError('This version is already resolved. The Student must upload a new revision.',409);
            }
            if ($status==='Submitted' && $doc['review_status']!=='Submitted') throw new DocumentWorkflowError('Invalid review transition.',422);
            if ($status===$doc['review_status'] && $reason===($doc['review_remarks']??'') && $status!=='Approved') {
                $pdo->commit(); return ['ok'=>true,'noChange'=>true,'message'=>'This review is already saved.','notificationIds'=>[]];
            }
            if (!empty($doc['admin_override']) && $doc['review_status']==='Approved') throw new DocumentWorkflowError('RPMS has authorized this approval by override. RPMS must complete the reasoned administrative submission.',409);
            if ($doc['review_status']!=='Approved') {
                $q=$pdo->prepare('UPDATE documents SET review_status=?,review_remarks=?,reviewed_by=?,reviewed_at=NOW(),admin_override=0 WHERE id=? AND is_current=1 AND rpms_submitted_at IS NULL');
                $q->execute([$status,$reason,$live['full_name'],$id]);
            } elseif ($status!=='Approved') throw new DocumentWorkflowError('This legacy version is already approved. Confirm automatic submission or contact RPMS.',409);
            $action=$status===$doc['review_status']?'document_commented':match($status) {
                'Approved'=>'document_approved','Denied'=>'document_denied','Resubmission Requested'=>'document_resubmission_requested',default=>'document_reviewed'};
            if ($status==='Approved') $action='document_approved';
            document_workflow_audit($pdo,$live,$action,$audit+['before'=>$doc['review_status'],'after'=>$status]);
            $after=$status==='Approved'?WF_READY_FOR_RPMS:(in_array($status,['Denied','Resubmission Requested'],true)?WF_NEEDS_REVISION:WF_PENDING_REVIEW);
            $displayStatus = $status === 'Resubmission Requested' ? 'Revision Requested' : $status;
            $message='Document "'.$doc['original_name'].'" ('.$doc['document_type'].', version '.$doc['version_no'].') is now '.$displayStatus.'.';
            if ($status==='Approved') {
                [$advanced,$ids,$message]=document_formal_submission($pdo,$live,$student,$doc,$audit);
                $after=WF_SUBMITTED_RPMS;
            } elseif (in_array($status,['Denied','Resubmission Requested'],true)) $message.=' Upload a NEW PDF/DOCX revision for Adviser review.';
            if ($status!=='Approved' && $reason!=='') $message.=' Remarks: '.$reason;
            if (!$ids) $ids=document_workflow_notifications($pdo,$live,$student,[],'PRISM Document Status Update',$message,false);
        }
        $pdo->commit();
        return ['ok'=>true,'message'=>$message,'workflowState'=>$after,'stageAdvanced'=>$advanced!==null,'newStage'=>$advanced,'notificationIds'=>$ids];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Caller owns Student/version locks. Approval and its automatic submission are atomic. */
function document_formal_submission(PDO $pdo, array $actor, array $student, array $doc, array $audit): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Formal submission requires a transaction.');
    $override=$actor['role']==='admin'; $memberSubmission=$actor['role']==='student';
    if ($override) $audit['details'].='; review='.$doc['review_status'].' -> Approved';
    if ($memberSubmission) $audit['details'].='; formally submitted by Student '.$actor['full_name'];
    $submitter=document_attribution($actor['full_name'],$override?' (RPMS Admin, Admin Override)':($memberSubmission?' (Student formal submission)':' (Adviser approval)'));
    $q=$pdo->prepare('UPDATE documents SET rpms_submitted_at=NOW(),rpms_submitted_by=? WHERE id=? AND is_current=1 AND rpms_submitted_at IS NULL');
    $q->execute([$submitter,$doc['id']]);
    if ($q->rowCount()!==1) throw new DocumentWorkflowError('This version was already submitted. Refresh to see its current state.',409);
    $q=$pdo->prepare('SELECT COUNT(*) FROM documents WHERE student_id=? AND stage=? AND document_type=? AND id<>? AND rpms_submitted_at IS NOT NULL');
    $q->execute([$student['id'],$doc['stage'],$doc['document_type'],$doc['id']]); $resubmitted=(int)$q->fetchColumn()>0;
    document_workflow_audit($pdo,$actor,$override?'admin_override_submit_to_rpms':($resubmitted?'document_resubmitted_to_rpms':'document_submitted_to_rpms'),
        $audit+['before'=>$override?document_workflow_state($doc):WF_READY_FOR_RPMS,'after'=>WF_SUBMITTED_RPMS,'override'=>$override]);
    $pdo->prepare('UPDATE students SET last_submission_date=CURDATE(),updated_at=NOW() WHERE id=?')->execute([$student['id']]);
    record_history($pdo,(int)$student['id'],$student['stage'],$student['status'],
        ($resubmitted?'Formally resubmitted':'Formally submitted').' to RPMS: "'.$doc['original_name'].'" v'.$doc['version_no'].' ('.$doc['document_type'].', '.$doc['stage'].')'.($override?'; authorized Admin Override.':' after Adviser approval.'),date('Y-m-d'),$submitter);
    $advanced=null;
    if (ierb_v14_requirement_key($doc['document_type']) === 'final_report') {
        try { $completion=ierb_v14_document_event($pdo,$actor,(int)$student['id'],'final_report_submitted',$doc['id']); }
        catch (AccountLifecycleForbidden $e) { throw new DocumentWorkflowError($e->getMessage(),403); }
        catch (AccountLifecycleConflict|AccountLifecycleValidation $e) { throw new DocumentWorkflowError($e->getMessage(),409); }
        if ($completion) $advanced=$completion['stage'];
    }
    $message=$override?'RPMS formally submitted your document on your behalf.':($memberSubmission?$actor['full_name'].' formally submitted your Adviser-approved project document to RPMS.'
        :'Your adviser approved and automatically formally submitted your document to RPMS. No additional submission action is required.');
    $message.=' "'.$doc['original_name'].'" (version '.$doc['version_no'].').';
    if (!$override && $audit['reason']!=='') $message.=' Remarks: '.$audit['reason'];
    $ids=document_workflow_notifications($pdo,$actor,$student,[],'PRISM Formal RPMS Submission Confirmation',$message,false);
    $ids=array_merge($ids,$completion['projectNotificationIds'] ?? []);
    // Staff receive an accurate queue notice, separate from Student wording and private override evidence.
    $q=$pdo->query("SELECT id,email,full_name FROM users WHERE role='admin' AND status='Active' ORDER BY id FOR UPDATE");
    $staffMessage='A new formally submitted document is available for RPMS review: "'.$doc['original_name'].'" v'.$doc['version_no'].($override?'; authorized Admin Override.':($memberSubmission?' (Adviser-approved; submitted by Student '.$actor['full_name'].').':' after Adviser approval.'));
    if ($override) $staffMessage.=' Reason: '.$audit['reason'];
    $schedule=date('Y-m-d H:i:s');
    $campaign=notification_create_campaign($pdo,$actor,'workflow','Document submitted to RPMS',$staffMessage,$schedule);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $admin) {
        $copy=create_notification($pdo,'admin',(int)$admin['id'],$admin['email'],$admin['full_name'],'Document submitted to RPMS',$staffMessage,
            'Status Update',$actor['full_name'],null,$schedule,null,'workflow',$campaign);
        if (!isset($copy['id'])) throw new DocumentWorkflowError('An RPMS recipient changed. Refresh before continuing.',409);
        $ids[]=(int)$copy['id'];
    }
    return [$advanced,$ids,$message];
}

/** Strict queue writes for ordinary Admin actions; callers deliver only after commit. */
function document_notice_atomic(PDO $pdo, array $actor, int $studentId, string $subject, string $message,
                                ?string $staffSubject = null, ?string $staffMessage = null): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Document notices require a transaction.');
    $student=current_workflow_student($pdo,$studentId,$actor);
    $ids=document_workflow_notifications($pdo,$actor,$student,[],$subject,$message,false);
    if ($staffSubject!==null && !empty($student['adviser_id'])) {
        $rows=lifecycle_rows($pdo,'SELECT * FROM advisers WHERE id=? FOR UPDATE',[$student['adviser_id']]);
        $adviser=$rows[0]??null;
        if ($adviser && empty($adviser['archived_at']) && $adviser['status']==='Active' && !empty($adviser['profile_completed_at'])) {
            $copy=create_notification($pdo,'adviser',(int)$adviser['id'],$adviser['email'],$adviser['full_name'],
                $staffSubject,$staffMessage??$message,'Status Update',$actor['full_name'],null,date('Y-m-d H:i:s'));
            if (!isset($copy['id'])) throw new DocumentWorkflowError('The assigned Adviser changed. Refresh before continuing.',409);
            $ids[]=(int)$copy['id'];
        }
    }
    return $ids;
}

function document_workflow_deliver(PDO $pdo, array $ids): void
{
    foreach ($ids as $id) {
        try { notification_dispatch($pdo,(int)$id); }
        catch (Throwable $e) { log_api_error('document_workflow_delivery','Committed workflow notification requires delivery follow-up.'); }
    }
}
