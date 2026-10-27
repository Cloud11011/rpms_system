<?php
/** Official progress is an explicit RPMS decision, independent of document workflow. */
require_once __DIR__.'/current_workflow_eligibility.php';
require_once __DIR__.'/ierb_v14_workflow.php';

function ierb_progress_version(PDO $pdo, int $id): string
{
    $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM ierb_history WHERE student_id=?');
    $q->execute([$id]);
    return (string)$q->fetchColumn();
}

/** Informational only: a returned earlier-stage file never moves or gates official progress. */
function ierb_earlier_stage_returns(PDO $pdo, array $students): array
{
    if (!$students) return [];
    $byId=array_column($students,null,'id');
    $q=$pdo->prepare('SELECT DISTINCT d.student_id,d.stage FROM documents d WHERE d.student_id IN ('
        .implode(',',array_fill(0,count($byId),'?')).') AND d.is_current=1 AND '.document_returned_sql('d'));
    $q->execute(array_keys($byId)); $result=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $old=array_search($row['stage'],STAGE_SEQUENCE,true);
        $current=array_search($byId[$row['student_id']]['stage'],STAGE_SEQUENCE,true);
        if ($old!==false && $current!==false && $old<$current) $result[$row['student_id']][]=$row['stage'];
    }
    return $result;
}

/** Owns its transaction. Every request is bound to the displayed stage/status/history. */
function ierb_progress_decide(PDO $pdo, array $actor, string $operation, array $data): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may change official IERB progress.');
    if (!in_array($operation,['advance','correct','status'],true)) throw new AccountLifecycleValidation('Invalid progress action.');
    foreach (['reason','expectedStage','expectedStatus','expectedVersion'] as $field) {
        if (!isset($data[$field]) || !is_string($data[$field])) throw new AccountLifecycleValidation('Refresh the progress record and provide its current stage, status and version.');
    }
    foreach (['stage','status'] as $field) {
        if (array_key_exists($field,$data) && !is_string($data[$field])) throw new AccountLifecycleValidation('Invalid progress input.');
    }
    $id=filter_var($data['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $reason=trim($data['reason']);
    if (!$id || ($data['confirmed']??null)!==true) throw new AccountLifecycleValidation('Explicit confirmation and a valid Student reference are required.');
    if (!in_array($data['expectedStage'],STAGE_SEQUENCE,true)
        || !in_array($data['expectedStatus'],['On Track','Pending','Delayed'],true)
        || !preg_match('/\A\d+\z/D',$data['expectedVersion'])) throw new AccountLifecycleValidation('Invalid expected progress state.');
    if (!mb_check_encoding($reason,'UTF-8') || mb_strlen($reason)<5 || mb_strlen($reason)>500
        || !preg_match('/[\p{L}\p{N}]/u',$reason)) throw new AccountLifecycleValidation('Explain this decision in 5–500 characters.');
    if ($operation==='status' && array_key_exists('stage',$data)) throw new AccountLifecycleValidation('A status-only action cannot set an IERB stage.');
    if ($operation==='advance' && array_key_exists('stage',$data)) throw new AccountLifecycleValidation('The next stage is determined by the official sequence.');
    if ($operation==='correct' && (!isset($data['stage']) || !in_array($data['stage'],STAGE_SEQUENCE,true))) throw new AccountLifecycleValidation('Choose a valid correction stage.');
    if (isset($data['status']) && !in_array($data['status'],['On Track','Pending','Delayed'],true)) throw new AccountLifecycleValidation('Invalid progress status.');
    if ($operation==='status' && !isset($data['status'])) throw new AccountLifecycleValidation('Choose a progress status.');
    if ($pdo->inTransaction()) throw new LogicException('IERB decision owns its transaction.');
    $pdo->beginTransaction();
    try {
        $before=current_workflow_student($pdo,(int)$id,$actor);
        ierb_v14_guard_legacy_write($pdo,(int)$id);
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible active RPMS Administrator is required.');
        if ($before['stage']!==$data['expectedStage'] || $before['status']!==$data['expectedStatus']
            || ierb_progress_version($pdo,(int)$id)!==$data['expectedVersion']) {
            throw new AccountLifecycleConflict('This progress record changed. Refresh and review the current stage and status before retrying.');
        }
        $stage=match($operation) { 'advance'=>next_stage($before['stage']), 'correct'=>$data['stage'], default=>$before['stage'] };
        $status=$data['status']??$before['status'];
        if ($operation==='advance' && $stage===$before['stage']) throw new AccountLifecycleValidation('Completed is the final stage; it cannot advance further.');
        if ($operation==='correct' && $stage===$before['stage']) throw new AccountLifecycleValidation('Choose a different stage for a correction. Use Update Progress Status for status-only changes.');
        if ($stage===$before['stage'] && $status===$before['status']) throw new AccountLifecycleValidation('The progress status already has that value.');
        $description='Stage: '.$before['stage'].' -> '.$stage.'; Status: '.$before['status'].' -> '.$status;
        $action=match($operation) { 'advance'=>'ierb_stage_advanced', 'correct'=>'ierb_stage_corrected', default=>'ierb_status_updated' };
        $title=match($operation) { 'advance'=>'RPMS stage advancement', 'correct'=>'RPMS stage correction', default=>'RPMS progress status update' };
        $pdo->prepare('UPDATE students SET stage=?,status=?,updated_at=NOW() WHERE id=?')->execute([$stage,$status,$id]);
        record_history($pdo,(int)$id,$stage,$status,$title.'. '.$description.'. Reason: '.$reason,null,
            document_attribution($live['full_name'],' (RPMS Admin)'));
        document_workflow_audit($pdo,$live,$action,[
            'entity_type'=>'student','entity_id'=>$id,'student_id'=>$id,'reason'=>$reason,
            'before'=>$before['stage'].' / '.$before['status'],'after'=>$stage.' / '.$status,
            'details'=>$description,'override'=>$operation==='correct',
        ]);
        $ids=workflow_queue_notices($pdo,$live,(int)$id,
            ['subject'=>'PRISM IERB Progress Updated','message'=>'RPMS recorded an official '.($operation==='status'?'progress status update':'stage decision').'. Current stage: '.stage_label($stage).' ('.$stage.'). Status: '.$status.'.'],
            ['subject'=>$title,'message'=>$live['full_name'].' updated '.$before['full_name'].': '.$description.'. Reason: '.$reason]);
        $version=ierb_progress_version($pdo,(int)$id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    document_workflow_deliver($pdo,$ids);
    return ['ok'=>true,'stage'=>$stage,'status'=>$status,'progressVersion'=>$version,
        'message'=>$title.' saved. Your identity, time and reason were recorded.'];
}
