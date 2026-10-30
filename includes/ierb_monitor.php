<?php
/** Read-only monitoring projection. No enrollment, document approval or workflow writes. */
require_once __DIR__.'/ierb_v14_requirements.php';
require_once __DIR__.'/pagination.php';
require_once __DIR__.'/record_filters.php';
require_once __DIR__.'/academic_catalog.php';

/** Audit-supported document stamps only. Legacy missing stamps use the formal audit
 * timestamp, explicitly identified as a fallback. Upload/update/summary dates never count.
 */
function monitor_formal_sql(): string
{
    $valid=static fn($v)=>"YEAR($v) BETWEEN 1000 AND 9999 AND MONTH($v) BETWEEN 1 AND 12 AND DAY($v) BETWEEN 1 AND DAY(LAST_DAY($v))";
    return "SELECT d.student_id,
        MIN(CASE WHEN ".$valid('d.rpms_submitted_at')." THEN d.rpms_submitted_at END) AS first_verified,
        MAX(CASE WHEN ".$valid('d.rpms_submitted_at')." THEN d.rpms_submitted_at END) AS latest_verified,
        MIN(CASE WHEN ".$valid('d.rpms_submitted_at')." THEN d.rpms_submitted_at WHEN ".$valid('a.created_at')." THEN a.created_at END) AS first_submission,
        MAX(CASE WHEN ".$valid('d.rpms_submitted_at')." THEN d.rpms_submitted_at WHEN ".$valid('a.created_at')." THEN a.created_at END) AS latest_submission
        FROM documents d JOIN activity_logs a ON a.entity_type='document' AND a.entity_id=d.id AND a.student_id=d.student_id
        AND a.action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms','admin_override_submit_to_rpms') AND a.after_value='Submitted to RPMS'
        GROUP BY d.student_id";
}

function monitor_scope(PDO $pdo,array $actor): array
{
    if (!in_array($actor['role']??'', ['admin','adviser'],true)) throw new AccountLifecycleForbidden('Staff monitoring access required.');
    $v14=ierb_v14_schema_available($pdo); $projects=research_projects_available($pdo);
    $joins=$v14?' LEFT JOIN ierb_workflows w ON w.student_id=h.id LEFT JOIN ierb_correction_cases c ON c.workflow_id=w.id AND c.resolved_at IS NULL':'';
    if ($projects) $joins.=' LEFT JOIN research_project_members m ON m.student_id=h.id LEFT JOIN research_projects p ON p.id=m.project_id';
    $title=$projects?'COALESCE(p.research_title,h.research_title)':'h.research_title';
    $group=$projects?'COALESCE(p.public_identifier,h.research_group)':'h.research_group';
    $stage=$v14?'COALESCE(w.stage_key,h.stage)':'h.stage';
    $status=$v14?"CASE w.stage_key WHEN 'ierb_completed' THEN 'IERB Completed' WHEN 'resubmission_amendment' THEN 'Correction open' ELSE h.status END":'h.status';
    $project=$projects?'p.id':'NULL'; $projectWorkflow=$projects?'p.workflow_id':'NULL'; $workflow=$v14?'w.id':'NULL';
    $interrupted=$v14?'c.interrupted_stage':'NULL';
    $dateFirst=$v14?'IF(w.id IS NULL,dates.first_submission,dates.first_verified)':'dates.first_submission';
    $dateLatest=$v14?'IF(w.id IS NULL,dates.latest_submission,dates.latest_verified)':'dates.latest_submission';
    $adviser=$projects?'COALESCE(p.adviser_id,h.adviser_id)':'h.adviser_id';
    $protocol=$projects && function_exists('research_onboarding_available') && research_onboarding_available($pdo)?'CASE WHEN p.id IS NOT NULL THEN (SELECT rpc.protocol_code FROM research_project_protocols rpc WHERE rpc.project_id=p.id) ELSE h.protocol_code END':'h.protocol_code';
    $select="SELECT h.id,h.student_id,h.full_name,h.email,h.course,h.academic_unit_key,h.program_key,h.year_level,h.academic_year,
        $protocol AS protocol_code,$status AS status,h.requirements,h.last_submission_date,$title AS research_title,$group AS research_group,$stage AS stage,
        $project AS project_id,$projectWorkflow AS project_workflow_id,$workflow AS workflow_id,$interrupted AS interrupted_stage,$adviser AS adviser_id,
        $dateFirst AS first_submission,$dateLatest AS latest_submission
        FROM students h $joins LEFT JOIN (".monitor_formal_sql().") dates ON dates.student_id=h.id
        WHERE h.archived_at IS NULL AND h.profile_completed_at IS NOT NULL";
    if ($projects) $select.=' AND (m.project_id IS NULL OR p.submission_holder_id=h.id)';
    $scope='FROM ('.$select.') s LEFT JOIN advisers f ON f.id=s.adviser_id WHERE 1=1'; $params=[];
    if ($actor['role']==='adviser') { $scope.=' AND f.email=:viewer AND f.archived_at IS NULL AND f.status=\'Active\''; $params[':viewer']=$actor['email']; }
    return [$scope,$params];
}

function monitor_order(array $query): string
{
    $columns=['submission'=>'s.latest_submission','title'=>'s.research_title','stage'=>'s.stage','status'=>'s.status','studentId'=>'s.student_id','academicYear'=>'s.academic_year'];
    $key=$query['sortBy']??'submission'; $direction=$query['direction']??($key==='submission'?'DESC':'ASC');
    if (!is_string($key) || !isset($columns[$key]) || !in_array($direction,['ASC','DESC'],true)) throw new AccountLifecycleValidation('Choose a supported sort order.');
    return ($key==='submission'?'s.latest_submission IS NULL ASC, ':'').$columns[$key].' '.$direction.', s.id ASC';
}

function monitor_identity(array $r): string
{
    return $r['project_id']?'project:'.$r['project_id']:'student:'.$r['id'];
}

function monitor_row(PDO $pdo,array $actor,array $r): array
{
    $check=$r['workflow_id']?ierb_v14_checklist($pdo,$actor,(int)$r['id'],false):['enrolled'=>false];
    if ($r['project_id'] && (empty($check['enrolled']) || (int)$check['workflow']['workflowId']!==(int)$r['project_workflow_id'])) throw new AccountLifecycleConflict('Canonical submission authority requires RPMS reconciliation before monitoring.');
    $requirements=[]; $pending=[]; $accepted=0; $fulfilled=0; $mandatory=0; $initialAccepted=0;
    $context=$check['workflow']['correctionCase']['interruptedStage']??$check['workflow']['stage']??null;
    foreach ($check['requirements']??[] as $item) {
        $required=$item['stage']===IerbStage::INITIAL || str_starts_with($item['key'],'affected:') || $item['key']==='amendment'
            || ($context!==IerbStage::INITIAL && in_array($item['key'],['form33','final_report'],true));
        $state=$item['state']==='Uploaded / Pending Review'?'Uploaded / Pending Adviser Review':$item['state'];
        $requirements[]=['key'=>$item['key'],'label'=>$item['label'],'state'=>$state,'mandatory'=>$required,'versionNo'=>$item['versionNo']];
        if ($item['stage']===IerbStage::INITIAL && $state==='Accepted') $initialAccepted++;
        if ($required) {
            $mandatory++; if ($state==='Accepted') $accepted++;
            if ($state==='Accepted' || ($item['key']==='final_report' && $state==='Formally Submitted to RPMS')) $fulfilled++;
            if ($state!=='Accepted' && !($item['key']==='final_report' && $state==='Formally Submitted to RPMS')) $pending[]=$item['label'].' — '.$state;
        }
    }
    $semantic=!empty($check['enrolled']);
    if (!$semantic && trim((string)$r['requirements'])!=='') $pending[]=$r['requirements'];
    if (!$semantic) {
        $q=$pdo->prepare('SELECT * FROM documents WHERE student_id=? AND is_current=1 ORDER BY document_type,id'); $q->execute([$r['id']]);
        foreach ($q->fetchAll() as $doc) {
            $doc=document_with_return($pdo,$doc);
            $state=document_workflow_state($doc);
            $requirements[]=['key'=>'legacy:'.$doc['id'],'label'=>$doc['document_type']?:'Historical document','state'=>$state,'mandatory'=>false,'versionNo'=>(int)$doc['version_no']];
        }
    }
    $stage=$semantic?$check['workflow']['stage']:$r['stage'];
    return ['key'=>monitor_identity($r),'projectId'=>$r['project_id']?(int)$r['project_id']:null,'studentRecordId'=>(int)$r['id'],
        'studentId'=>$r['student_id'],'name'=>$r['full_name'],'research'=>$r['research_title'],'groupId'=>$r['research_group'],
        'protocolCode'=>$r['protocol_code']??null,
        'course'=>$r['course'],'academicYear'=>$r['academic_year'],'model'=>$semantic?'V14':'Legacy',
        'stage'=>$stage,'stageLabel'=>$semantic?$check['workflow']['stageLabel']:stage_label($stage),
        'progress'=>$semantic?$check['workflow']['progress']:stage_progress_percent($stage),
        'status'=>$r['status'],
        'firstSubmission'=>$r['first_submission'],'latestSubmission'=>$r['latest_submission'],
        'fulfilledCount'=>$semantic?$fulfilled:null,'requirementCompletionPercent'=>$semantic&&$mandatory?(int)round($fulfilled*100/$mandatory):null,
        'acceptedCount'=>$semantic?$accepted:null,'mandatoryCount'=>$semantic?$mandatory:null,'initialAcceptedCount'=>$semantic?$initialAccepted:null,
        'requirements'=>$requirements,'pending'=>$pending,'correction'=>$check['workflow']['correctionCase']??null,
        'notes'=>$semantic?'Official progress and requirement acceptance are separate metrics. Final Report completion uses formal submission.':'Historical stage model. Mandatory acceptance counts were not recorded; no V14 stage mapping is inferred. Dates use formal document stamps with supporting audits; a missing stamp falls back to its verified formal audit time. Manual record dates and uploads never establish formal submission.'];
}

function monitor_list(PDO $pdo,array $actor,array $query): array
{
    $pdo->beginTransaction();
    try {
        research_project_fence($pdo); current_workflow_actor($pdo,$actor);
        [$scope,$params]=monitor_scope($pdo,$actor);
        $options=prism_student_filter_options($pdo,$scope,$params);
        // Semantic and historical stage labels are explicitly separate.
        foreach ($options['stage'] as &$option) if (isset(IerbStage::LABELS[$option['value']])) $option['label']='V14 — '.IerbStage::LABELS[$option['value']];
        unset($option);
        $overview=$pdo->prepare('SELECT s.stage,COUNT(*) AS c '.$scope.' GROUP BY s.stage'); $overview->execute($params);
        $counts=$overview->fetchAll();
        prism_apply_student_filters($scope,$params,$query,$options);
        $search=prism_record_search($query);
        if ($search!=='') $scope.=' AND '.prism_search_clause(['s.research_title','s.protocol_code','s.research_group','s.full_name','s.student_id'],$search,$params);
        $page=prism_page_query($pdo,'SELECT s.*',$scope,$params,monitor_order($query),$query);
        $records=array_map(fn($row)=>monitor_row($pdo,$actor,$row),$page['rows']); unset($page['rows']);
        $pdo->commit(); return ['ok'=>true,'records'=>$records,'filterOptions'=>$options,'overview'=>$counts]+$page;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Selected keys are always resolved again in the current authorized query. */
function monitor_selected(PDO $pdo,array $actor,mixed $keys,bool $history=false,array $query=[]): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may compare selected records.');
    if (!is_array($keys) || !$keys || count($keys)>100) throw new AccountLifecycleValidation('Select between 1 and 100 records.');
    foreach ($keys as $key) if (!is_string($key) || !preg_match('/\A(project|student):[1-9][0-9]*\z/D',$key)) throw new AccountLifecycleValidation('Invalid monitoring record identity.');
    $keys=array_values(array_unique($keys)); $pdo->beginTransaction();
    try {
        research_project_fence($pdo); current_workflow_actor($pdo,$actor);
        [$scope,$params]=monitor_scope($pdo,$actor); $clauses=[];
        foreach ($keys as $i=>$key) {
            [$kind,$id]=explode(':',$key); $param=':selected'.$i; $params[$param]=$id;
            $clauses[]=$kind==='project'?'s.project_id='.$param:'(s.project_id IS NULL AND s.id='.$param.')';
        }
        $q=$pdo->prepare('SELECT s.* '.$scope.' AND ('.implode(' OR ',$clauses).') ORDER BY s.id'); $q->execute($params);
        $rows=$q->fetchAll(); $found=array_map('monitor_identity',$rows); $records=[];
        foreach ($rows as $r) {
            $item=monitor_row($pdo,$actor,$r);
            if ($history && count($keys)===1) $item['history']=monitor_history($pdo,$r,$query);
            $records[]=$item;
        }
        $pdo->commit(); return ['ok'=>true,'records'=>$records,'unavailableKeys'=>array_values(array_diff($keys,$found))];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** All history uses retained events; uploads and case openings are never submissions.
 * Pagination is independent of the fixed four-row Batch 4 report layout.
 */
function monitor_history(PDO $pdo,array $r,array $query): array
{
    $params=[':sid'=>(int)$r['id']];
    $formal="SELECT CONCAT('audit:',a.id) AS event_id,a.id AS stable_id,
        CASE WHEN a.action='document_returned_for_revision' THEN a.created_at
        WHEN YEAR(d.rpms_submitted_at)>=1000 AND MONTH(d.rpms_submitted_at)>0 AND DAY(d.rpms_submitted_at) BETWEEN 1 AND DAY(LAST_DAY(d.rpms_submitted_at)) THEN d.rpms_submitted_at ELSE a.created_at END AS occurred_at,
        a.action AS event_type,d.document_type AS category,
        CASE WHEN a.action='document_returned_for_revision' THEN 'Recorded return for revision'
        WHEN d.rpms_submitted_at IS NULL OR YEAR(d.rpms_submitted_at)<1000 OR MONTH(d.rpms_submitted_at)=0 OR DAY(d.rpms_submitted_at)=0 THEN 'Formal audit timestamp fallback; document submission stamp unavailable'
        ELSE 'Document submission stamp supported by formal audit' END AS detail
        FROM activity_logs a JOIN documents d ON d.id=a.entity_id AND d.student_id=a.student_id
        WHERE a.entity_type='document' AND a.student_id=:sid AND
        (a.action='document_returned_for_revision' OR (a.action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms','admin_override_submit_to_rpms') AND a.after_value='Submitted to RPMS'))";
    $parts=[$formal];
    if ($r['workflow_id']) {
        $params[':wid']=(int)$r['workflow_id']; $params[':casewid']=(int)$r['workflow_id']; $params[':resolvedwid']=(int)$r['workflow_id'];
        $parts[]="SELECT CONCAT('workflow:',e.id),e.id,e.created_at,e.event_key,CONCAT(e.from_stage,' → ',e.to_stage),'Official workflow event' FROM ierb_workflow_events e WHERE e.workflow_id=:wid";
        $parts[]="SELECT CONCAT('case-open:',c.id),c.id,c.opened_at,'Correction opened',c.interrupted_stage,CONCAT('Case #',c.id,'; Amendment required: ',IF(c.requires_amendment=1,'Yes','No'),'; status: ',IF(c.resolved_at IS NULL,'Open','Resolved')) FROM ierb_correction_cases c WHERE c.workflow_id=:casewid";
        $parts[]="SELECT CONCAT('case-resolved:',c.id),c.id,c.resolved_at,'Correction resolved',c.interrupted_stage,CONCAT('Case #',c.id,' resolved') FROM ierb_correction_cases c WHERE c.workflow_id=:resolvedwid AND c.resolved_at IS NOT NULL";
    }
    if ($r['project_id']) {
        $params[':pid']=(int)$r['project_id'];
        $parts[]="SELECT CONCAT('completion:',p.id),p.id,p.completed_at,'IERB completion','Final Report','Automatic completion on formal Final Report submission' FROM research_projects p WHERE p.id=:pid AND p.completed_at IS NOT NULL";
    }
    $scope='FROM ('.implode(' UNION ALL ',$parts).') events WHERE occurred_at IS NOT NULL AND YEAR(occurred_at)>=1000 AND MONTH(occurred_at)>0 AND DAY(occurred_at) BETWEEN 1 AND DAY(LAST_DAY(occurred_at))';
    $page=prism_page_query($pdo,'SELECT *',$scope,$params,'occurred_at DESC,stable_id DESC,event_id ASC',$query);
    $page['events']=$page['rows']; unset($page['rows']); return $page;
}
