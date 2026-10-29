<?php
/** Deterministic database evidence adapter. AI never supplies dates or table cells. */
require_once __DIR__.'/ierb_v14_requirements.php';
require_once __DIR__.'/document_summary_history.php';
require_once __DIR__.'/account_lifecycle_schema.php';

const IERB_TRACK_LABELS=['Initial Submission','1st Resubmission','2nd Resubmission','3rd Resubmission'];

function track_recorded_timestamp(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?\z/',$value,$m)) return null;
    return checkdate((int)$m[2],(int)$m[3],(int)$m[1]) && (int)$m[4]<24 && (int)$m[5]<60 && (int)$m[6]<60?$value:null;
}

/** A formal submission must be a case-affected successor, or the required Amendment. */
function track_case_document(array $doc,array $case,array $documents): bool
{
    if (!empty($case['requires_amendment']) && $doc['document_type']==='Amendment Form') return true;
    $affected=array_column(json_decode($case['affected_documents_json'],true,512,JSON_THROW_ON_ERROR),'id');
    $seen=[];
    while (!empty($doc['supersedes_id']) && !isset($seen[$doc['id']])) {
        $seen[$doc['id']]=true;
        if (in_array($doc['supersedes_id'],$affected,true)) return true;
        $doc=$documents[$doc['supersedes_id']]??[];
    }
    return false;
}

/** Audit IDs resolve same-second ordering; opening a case never becomes a submission/return. */
function track_submission_cycles(array $cases,array $audits,array $documents): array
{
    usort($cases,fn($a,$b)=>[$a['opened_at'],(int)$a['id']]<=>[$b['opened_at'],(int)$b['id']]);
    usort($audits,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    $openings=[]; $notes=[]; $incomplete=[];
    foreach ($cases as $case) {
        foreach ($audits as $a) if ($a['action']==='ierb_v14_open_correction'
            && preg_match('/correction case '.preg_quote((string)$case['id'],'/').'\z/',$a['details'])) $openings[$case['id']]=(int)$a['id'];
        if (!isset($openings[$case['id']])) $notes[]='Correction case #'.$case['id'].' lacks an unambiguous opening audit. Its submissions cannot be assigned safely.';
    }
    $initial=['caseId'=>null,'submittedAt'=>null,'returnedAt'=>null,'submissionEvidence'=>[],'returnEvidence'=>[]];
    $byCase=[]; $formalActions=['document_submitted_to_rpms','document_resubmitted_to_rpms','admin_override_submit_to_rpms'];
    $firstOpening=$cases?($openings[$cases[0]['id']]??null):null;
    foreach ($audits as $a) {
        if (!in_array($a['action'],$formalActions,true) || $a['after_value']!==WF_SUBMITTED_RPMS) continue;
        $doc=$documents[$a['entity_id']]??null;
        if (!$doc || !track_recorded_timestamp($doc['rpms_submitted_at']??null)) { $incomplete[]=(int)$a['id']; continue; }
        $item=['auditId'=>(int)$a['id'],'documentId'=>$doc['id'],'versionNo'=>(int)$doc['version_no'],
            'documentType'=>$doc['document_type'],'submittedAt'=>$doc['rpms_submitted_at'],'auditAt'=>$a['created_at']];
        if (!$cases || ($firstOpening!==null?(int)$a['id']<$firstOpening:$a['created_at']<$cases[0]['opened_at'])) {
            $initial['submissionEvidence'][]=$item; continue;
        }
        foreach (array_reverse($cases) as $case) {
            $opening=$openings[$case['id']]??null;
            if ($opening===null) { if ($a['created_at']>=$case['opened_at']) break; continue; }
            if ((int)$a['id']<=$opening) continue;
            // Later submissions cannot be attributed to a resolved earlier case.
            if (!empty($case['resolved_at']) && $a['created_at']>$case['resolved_at']) break;
            if (track_case_document($doc,$case,$documents)) {
                if (!isset($byCase[$case['id']])) $byCase[$case['id']]=['caseId'=>(int)$case['id'],'submittedAt'=>null,'returnedAt'=>null,
                    'submissionEvidence'=>[],'returnEvidence'=>[],'details'=>$case];
                $byCase[$case['id']]['submissionEvidence'][]=$item;
            }
            break;
        }
    }
    if ($incomplete) $notes[]=count($incomplete).' formal-submission audits lack complete source document evidence. Dates cannot be reconstructed from those records. Audit IDs retained for RPMS review: '.implode(', ',$incomplete).'.';
    $cycles=[$initial];
    foreach ($cases as $case) if (isset($byCase[$case['id']])) $cycles[]=$byCase[$case['id']];
    foreach ($cycles as &$cycle) {
        $dates=array_column($cycle['submissionEvidence'],'submittedAt'); sort($dates,SORT_STRING);
        $cycle['submittedAt']=$dates[0]??null;
    } unset($cycle);
    foreach ($cycles as $i=>&$cycle) {
        $start=$cycle['submissionEvidence'][0]['auditId']??null;
        $end=$cycles[$i+1]['submissionEvidence'][0]['auditId']??PHP_INT_MAX;
        foreach ($audits as $a) if ($start!==null && (int)$a['id']>$start && (int)$a['id']<$end
            && $a['action']==='document_returned_for_revision' && isset($documents[$a['entity_id']]) && track_recorded_timestamp($a['created_at']??null)) {
            $cycle['returnEvidence'][]=['auditId'=>(int)$a['id'],'documentId'=>$a['entity_id'],'returnedAt'=>$a['created_at']];
        }
        $dates=array_column($cycle['returnEvidence'],'returnedAt'); sort($dates,SORT_STRING); $cycle['returnedAt']=$dates[0]??null;
    } unset($cycle);
    $rows=[];
    foreach (IERB_TRACK_LABELS as $i=>$label) $rows[]=array_merge(['caseId'=>null,'submittedAt'=>null,'returnedAt'=>null,
        'submissionEvidence'=>[],'returnEvidence'=>[]],$cycles[$i]??[],['label'=>$label]);
    $overflow=array_slice($cycles,4);
    if ($overflow) $notes[]='RPMS REVIEW REQUIRED: '.(count($cycles)-1).' official correction resubmission cycles exceed the three report slots. All overflow evidence is retained in the report snapshot; no additional tracking rows are generated.';
    if (!$rows[0]['submittedAt']) $notes[]='Initial formal submission date not recorded or cannot be assigned unambiguously.';
    return ['rows'=>$rows,'overflowEvidence'=>$overflow,'notes'=>array_values(array_unique($notes)),
        'allCorrectionCases'=>$cases,'auditEvidence'=>$audits,'officialResubmissionCount'=>count($cycles)-1];
}

/** Caller holds the existing Student fence and transaction throughout capture/persistence. Admin only. */
function track_project_snapshot(PDO $pdo,array $actor,int $id): array
{
    if (($actor['role']??'')!=='admin' || !$pdo->inTransaction()) throw new AccountLifecycleForbidden('RPMS report access required.');
    $q=$pdo->prepare('SELECT * FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$id]); $project=$q->fetch(PDO::FETCH_ASSOC);
    if (!$project) throw new AccountLifecycleValidation('Canonical research project not found.');
    current_workflow_student($pdo,(int)$project['submission_holder_id'],$actor);
    $checklist=ierb_v14_checklist($pdo,$actor,(int)$project['submission_holder_id'],false);
    $q=$pdo->prepare('SELECT * FROM students WHERE id=? FOR UPDATE'); $q->execute([$project['submission_holder_id']]); $holder=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM ierb_workflows WHERE id=? FOR UPDATE'); $q->execute([$project['workflow_id']]); $workflow=$q->fetch(PDO::FETCH_ASSOC);
    if (!$workflow || empty($checklist['enrolled']) || (int)$workflow['student_id']!==(int)$project['submission_holder_id']
        || (int)$checklist['workflow']['workflowId']!==(int)$workflow['id']) throw new AccountLifecycleConflict('Project submission authority requires RPMS reconciliation before reporting.');
    $q=$pdo->prepare('SELECT * FROM ierb_correction_cases WHERE workflow_id=? ORDER BY opened_at,id FOR UPDATE'); $q->execute([$workflow['id']]); $cases=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM documents WHERE student_id=? ORDER BY id FOR UPDATE'); $q->execute([$holder['id']]); $docs=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'id');
    $q=$pdo->prepare("SELECT id,action,entity_id,after_value,details,created_at FROM activity_logs WHERE student_id=? AND (entity_type='document' OR action='ierb_v14_open_correction') ORDER BY id FOR UPDATE"); $q->execute([$holder['id']]); $audits=$q->fetchAll(PDO::FETCH_ASSOC);
    $tracking=track_submission_cycles($cases,$audits,$docs);
    $q=$pdo->prepare('SELECT full_name FROM advisers WHERE id=?'); $q->execute([$project['adviser_id']]); $adviser=$q->fetchColumn();
    $q=$pdo->prepare('SELECT * FROM ierb_workflow_events WHERE workflow_id=? ORDER BY revision FOR UPDATE'); $q->execute([$workflow['id']]); $events=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM research_project_events WHERE project_id=? ORDER BY revision FOR UPDATE'); $q->execute([$id]); $projectEvents=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT academic_year FROM students s JOIN research_project_members m ON m.student_id=s.id WHERE m.project_id=? ORDER BY s.id FOR UPDATE'); $q->execute([$id]); $years=array_values(array_unique(array_filter($q->fetchAll(PDO::FETCH_COLUMN))));
    // PI is an RPMS-designated fact (project event), never derived from the evidence holder or membership order.
    $pi=research_project_principal_investigator($pdo,$id);
    $identity=['Principal Investigator'=>$pi?$pi['name'].' (designated by RPMS '.substr((string)$pi['designatedAt'],0,10).')':'Not recorded','Protocol Code'=>$holder['protocol_code']?:'Not recorded','Research Category'=>'Not recorded',
        'Research / Study Title'=>$project['research_title'],'Co-Investigators'=>'Not recorded','Research Group Identifier'=>$project['public_identifier'],
        'Academic Year'=>count($years)===1?$years[0]:'Not recorded (missing or inconsistent member records)',
        'Assigned Research Adviser'=>$adviser?:'Not recorded','Current IERB Stage'=>$checklist['workflow']['stageLabel']];
    $notes=array_merge($tracking['notes'],['The Principal Investigator is shown only when RPMS recorded a designation; it identifies a role and grants no additional permission. Co-investigator roles and research category have no authoritative fields. The project evidence record and membership order do not establish investigator roles.',
        'Protocol code comes from the designated holder; academic year requires agreement among recorded project members.',
        'Submitted dates use document rpms_submitted_at with mandatory formal-submission audits; returned dates use separate document_returned_for_revision audits. Uploads, acceptances and case openings do not establish those dates.',
        'Each case contributes at most one cycle. Date Submitted is the earliest audited formal submission of an affected successor or required Amendment in that case, not proof that every correction was submitted.',
        'All times are stored database values shown in Asia/Manila. Unknown dates remain Not recorded.']);
    $notes[]='The shared PDF engine uses built-in Latin fonts with Windows-1252 text; characters outside it are shown as ? with a notice. The retained database snapshot preserves the original UTF-8 source values.';
    return ['formatVersion'=>1,'capturedAt'=>$pdo->query('SELECT NOW(6)')->fetchColumn(),'project'=>$project,'identity'=>$identity,
        'workflow'=>$workflow,'checklist'=>$checklist,'tracking'=>$tracking,'workflowEvents'=>$events,'projectEvents'=>$projectEvents,
        'members'=>research_project_members($pdo,$id),'notes'=>$notes];
}

/** Allowlisted facts only: no identities, titles, protocol codes, reasons, free text or document IDs. */
function track_assessment_facts(array $s): array
{
    $requirements=$s['checklist']['requirements'];
    return ['stage'=>$s['checklist']['workflow']['stage'],'progress'=>$s['checklist']['workflow']['progress'],
        'acceptedRequirementCount'=>count(array_filter($requirements,fn($r)=>$r['state']==='Accepted')),
        'formalResubmissionCycles'=>$s['tracking']['officialResubmissionCount'],
        'recordedTrackingDates'=>array_map(fn($r)=>['submission'=>$r['submittedAt'],'return'=>$r['returnedAt']],$s['tracking']['rows']),
        'openCorrection'=>(bool)$s['checklist']['workflow']['correctionCase'],
        'completionRecorded'=>!empty($s['project']['completed_at']),'reportOverflow'=>(bool)$s['tracking']['overflowEvidence']];
}

function track_local_assessment(array $s): string
{
    $f=track_assessment_facts($s);
    return 'Recorded stage: '.$s['identity']['Current IERB Stage'].'. Official progress: '.$f['progress'].'%. '
        .$f['acceptedRequirementCount'].' requirement(s) currently have verified RPMS acceptance; '.$f['formalResubmissionCycles'].' correction case(s) have formal resubmission evidence. '
        .($f['openCorrection']?'A recorded correction remains open. ':'')
        .($f['completionRecorded']?'The project has a recorded official completion. ':'Official project completion is not recorded. ')
        .'Review the requirement table for documented outstanding actions. Missing dates and investigator roles cannot be inferred. No cause of delay or future outcome is established.';
}

function track_report_blocks(array $s): array
{
    $r=$s['checklist']['requirements']; $w=$s['checklist']['workflow'];
    $mandatory=array_filter($r,fn($x)=>!in_array($x['key'],['amendment','final_report'],true) && !str_starts_with($x['key'],'affected:'));
    $accepted=count(array_filter($mandatory,fn($x)=>$x['state']==='Accepted'));
    $form33=array_values(array_filter($r,fn($x)=>$x['key']==='form33'))[0]??null;
    $final=array_values(array_filter($r,fn($x)=>$x['key']==='final_report'))[0]??null;
    $latest=[];
    foreach ($s['tracking']['auditEvidence'] as $a) $latest[]=$a['created_at'];
    foreach (array_merge($s['workflowEvents'],$s['projectEvents']) as $e) $latest[]=$e['created_at']; sort($latest,SORT_STRING);
    $blocks=['CEU | Research, Planning and Monitoring Section','IERB TRACK SHEET - PROJECT PROGRESS REPORT',
        '# '.$s['project']['research_title'],'Snapshot: '.$s['capturedAt'].' (Asia/Manila)','# Research Identification',
        ['headers'=>['Research information','Recorded value'],'widths'=>[160,352],'rows'=>array_map(fn($k,$v)=>[$k,$v],array_keys($s['identity']),array_values($s['identity']))],
        '# Initial Submission and Resubmission Tracking',
        ['headers'=>['Submission','Date Submitted','Date Returned'],'widths'=>[160,176,176],
        'rows'=>array_map(fn($row)=>[$row['label'],$row['submittedAt']?:'Not recorded',$row['returnedAt']?:'Not recorded'],$s['tracking']['rows'])]];
    foreach ($s['tracking']['notes'] as $note) $blocks[]=$note;
    foreach ($s['tracking']['rows'] as $row) if (isset($row['details'])) {
        $c=$row['details']; $affected=json_decode($c['affected_documents_json'],true,512,JSON_THROW_ON_ERROR);
        $blocks[]='# '.$row['label'].' - Correction details';
        $blocks[]='Case #'.$c['id'].'; requested: '.$c['opened_at'].'; Amendment required: '.($c['requires_amendment']?'Yes':'No').'; resolved: '.($c['resolved_at']?:'Not recorded');
        $blocks[]='Return / correction reason: '.$c['reason'];
        $blocks[]='Affected categories: '.implode(', ',array_unique(array_column($affected,'document_type')));
        $blocks[]='Interrupted review: '.(IerbStage::LABELS[$c['interrupted_stage']]??'Not recorded');
    }
    return array_merge($blocks,['# Document Requirement Status',
        ['headers'=>['Requirement','Recorded state'],'widths'=>[295,217],'rows'=>array_map(fn($x)=>[$x['label'],$x['state']],$r)],
        '# Current IERB Progress','Official stage: '.$w['stageLabel'].' | Progress: '.$w['progress'].'%',
        'Accepted mandatory documents: '.$accepted.' / '.count($mandatory).'. The Final Report requires formal submission, with no additional RPMS acceptance.',
        'Missing / pending: '.(implode('; ',array_column(array_filter($mandatory,fn($x)=>$x['state']!=='Accepted' && $x['key']!=='final_report'),'label'))?:'None recorded'),
        'Correction status: '.($w['correctionCase']?'Open case #'.$w['correctionCase']['id']:'No open correction case'),
        'Latest recorded activity: '.($latest?end($latest):'Not recorded'),
        'Form 33 acceptance: '.(($form33['state']??'')==='Accepted'?'Accepted':'Not accepted'),
        'Form 33-0B formal submission: '.(!empty($s['project']['completed_at'])?'Recorded (automatic completion)':($final['state']??'Not recorded')),
        'IERB completion date: '.($s['project']['completed_at']?:'Not recorded'),
        '# AI Progress Assessment (generated review aid)',($s['assessmentSource']??'Local deterministic fallback'),$s['assessment']??track_local_assessment($s),
        '# Source / Verification Notes',implode("\n\n",$s['notes']),
        'Factual tables are database-derived. Generated narrative is an interpretation for review and does not constitute an institutional decision. Verify against retained source evidence.']);
}

/** Explicit units, never inferred group-string deduplication. Legacy distributions remain separate. */
function track_institution_counts(PDO $pdo): array
{
    $students=(int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL')->fetchColumn();
    $projects=(int)$pdo->query('SELECT COUNT(p.id) FROM research_projects p JOIN students s ON s.id=p.submission_holder_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL')->fetchColumn();
    $unreconciled=(int)$pdo->query('SELECT COUNT(*) FROM students s LEFT JOIN research_project_members m ON m.student_id=s.id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL AND m.student_id IS NULL')->fetchColumn();
    return ['studentRecords'=>$students,'distinctResearchProjects'=>$projects,'v14EnrolledProjects'=>$projects,'unreconciledStudentRecords'=>$unreconciled,
        'v14UnreconciledWorkflows'=>(int)$pdo->query('SELECT COUNT(*) FROM ierb_workflows w JOIN students s ON s.id=w.student_id LEFT JOIN research_projects p ON p.workflow_id=w.id WHERE p.id IS NULL AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL')->fetchColumn(),
        'v14ProjectStages'=>$pdo->query('SELECT w.stage_key,COUNT(p.id) AS project_count FROM research_projects p JOIN ierb_workflows w ON w.id=p.workflow_id JOIN students s ON s.id=p.submission_holder_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL GROUP BY w.stage_key')->fetchAll(PDO::FETCH_ASSOC),
        'legacyStudentStages'=>$pdo->query('SELECT s.stage,COUNT(*) AS student_count FROM students s LEFT JOIN ierb_workflows w ON w.student_id=s.id LEFT JOIN research_project_members m ON m.student_id=s.id WHERE w.id IS NULL AND m.student_id IS NULL AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL GROUP BY s.stage')->fetchAll(PDO::FETCH_ASSOC)];
}

function track_aggregate_snapshot(PDO $pdo,array $actor): array
{
    if (($actor['role']??'')!=='admin' || !$pdo->inTransaction()) throw new AccountLifecycleForbidden('RPMS report access required.');
    current_workflow_actor($pdo,$actor);
    $projects=$pdo->query('SELECT p.id,p.research_title,p.public_identifier,w.stage_key FROM research_projects p JOIN ierb_workflows w ON w.id=p.workflow_id JOIN students s ON s.id=p.submission_holder_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL ORDER BY p.id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    return ['formatVersion'=>1,'capturedAt'=>$pdo->query('SELECT NOW(6)')->fetchColumn(),'counts'=>track_institution_counts($pdo),'projects'=>$projects];
}

function track_aggregate_blocks(array $s): array
{
    $c=$s['counts'];
    return ['CEU | Research, Planning and Monitoring Section','INSTITUTIONAL PROJECT PROGRESS REPORT','Snapshot: '.$s['capturedAt'].' (Asia/Manila)',
        '# Reporting Units', ['headers'=>['Statistical unit','Total'],'widths'=>[370,142],'rows'=>[
            ['Active Student records',(string)$c['studentRecords']],['Distinct research projects',(string)$c['distinctResearchProjects']],
            ['V14 enrolled projects',(string)$c['v14EnrolledProjects']],['Unreconciled Student records (including unlinked V14 holders)',(string)$c['unreconciledStudentRecords']],
            ['V14 workflows awaiting project reconciliation',(string)$c['v14UnreconciledWorkflows']]]],
        '# Canonical Research Projects', ['headers'=>['Research title / secondary identifier','Official V14 stage'],'widths'=>[325,187],
            'rows'=>array_map(fn($p)=>[$p['research_title']."\n".$p['public_identifier'],IerbStage::LABELS[$p['stage_key']]],$s['projects'])],
        '# V14 Project Stage Distribution',['headers'=>['V14 stage','Distinct projects'],'widths'=>[370,142],
            'rows'=>array_map(fn($r)=>[IerbStage::LABELS[$r['stage_key']],(string)$r['project_count']],$c['v14ProjectStages'])],
        '# Legacy Student Stage Distribution',['headers'=>['Legacy recorded stage','Student records'],'widths'=>[370,142],
            'rows'=>array_map(fn($r)=>[$r['stage'],(string)$r['student_count']],$c['legacyStudentStages'])],
        '# Source / Verification Notes','Canonical projects are counted by immutable project ID, once per project. Student totals are a separate statistical unit. Legacy stages exclude enrolled workflows and canonical project members; no stage mapping or legacy reconciliation is inferred.',
        'This deterministic database snapshot does not infer submission dates, investigator roles, institutional decisions, causes of delay or future progress. Individual track sheets retain Initial Submission through 3rd Resubmission only.'];
}
