<?php
/** reports_api.php has already authenticated a live RPMS administrator. */
if (!summary_history_available($pdo)) json_out(['ok'=>false,'message'=>'Project reports require the reviewed schema 14 migration. Historical reports remain available.'],503);
if ($action==='project_options') {
    $rows=$pdo->query('SELECT p.id,p.research_title,p.public_identifier FROM research_projects p JOIN students s ON s.id=p.submission_holder_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL ORDER BY p.research_title,p.id')->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok'=>true,'projects'=>$rows,'counts'=>track_institution_counts($pdo)]);
}
require_post_same_origin(); require_session_generation(true);
$input=$trackInput??json_body(true);
$aggregate=$action==='project_aggregate';
if (!$aggregate && (!isset($input['projectId']) || !is_int($input['projectId']) || $input['projectId']<1)) json_out(['ok'=>false,'message'=>'Choose a canonical research project.'],422);
$path=null; $commitAttempted=false; $schemaLock=false;
try {
    $capture=static function () use ($pdo,$user,$input,$aggregate): array {
        $pdo->beginTransaction(); research_project_fence($pdo);
        return $aggregate?track_aggregate_snapshot($pdo,$user):track_project_snapshot($pdo,$user,$input['projectId']);
    };
    $snapshot=$capture(); $pdo->commit();
    $fingerprint=static function (array $s): string { unset($s['capturedAt'],$s['assessment'],$s['assessmentSource']); return hash('sha256',json_encode($s,JSON_THROW_ON_ERROR)); };
    $before=$fingerprint($snapshot);
    if (!$aggregate) {
        $snapshot['assessment']=track_local_assessment($snapshot); $snapshot['assessmentSource']='Local deterministic fallback';
        // AI can organize only supported backend-authored sentences. No free-text provider claims reach the report.
        $sentences=['stage'=>'The recorded official stage is '.$snapshot['identity']['Current IERB Stage'].'.',
            'cycles'=>'There are '.$snapshot['tracking']['officialResubmissionCount'].' correction cases with formal resubmission evidence.',
            'verification'=>'Review missing dates and outstanding requirements against the factual tables; causes of delay and future outcomes are not established.'];
        $answer=openrouter_generate('Organize a concise progress assessment using only the supplied verified sentences. Return JSON with a single sentenceKeys array containing each supplied key once. Do not add prose, claims, predictions or keys.',
            json_encode(['facts'=>track_assessment_facts($snapshot),'sentences'=>$sentences],JSON_THROW_ON_ERROR));
        $selection=is_string($answer)?json_decode($answer,true):null; $keys=$selection['sentenceKeys']??null;
        if (is_array($keys) && count($keys)===3 && count(array_unique(array_filter($keys,'is_string')))===3
            && !array_diff($keys,array_keys($sentences))) {
            $snapshot['assessment']=implode(' ',array_map(fn($k)=>$sentences[$k],$keys)); $snapshot['assessmentSource']='AI-assisted organization of verified statements';
        }
    }
    $id=bin2hex(random_bytes(12)); $filename='project_'.$id.'.pdf'; $path=REPORTS_DIR.DIRECTORY_SEPARATOR.$filename;
    $title=$aggregate?'Institutional Research Project Progress':'IERB Track Sheet - '.$snapshot['project']['research_title'];
    $type=$aggregate?'Progress Report':'Student Report';
    $bytes=make_pdf($aggregate?'CEU RPMS - Project Progress':'CEU RPMS - IERB Track Sheet',$aggregate?track_aggregate_blocks($snapshot):track_report_blocks($snapshot));
    if (file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Private PDF write failed.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new StudentSnapshotConflict('Schema maintenance is in progress. Try again after review.');
    $schemaLock=true;
    if (!summary_history_available($pdo)) throw new StudentSnapshotConflict('Report schema changed during generation. Refresh and try again.');
    $now=$capture();
    if (!hash_equals($before,$fingerprint($now))) throw new StudentSnapshotConflict('Project evidence changed during generation. Generate the report again.');
    $pdo->prepare('INSERT INTO reports(id,title,type,filename,generated_by,generated_by_user_id,owner_student_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id,mb_substr($title,0,255),$type,$filename,$user['full_name'],$user['id'],$aggregate?null:$snapshot['project']['submission_holder_id']]);
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO ierb_report_snapshots(report_id,snapshot_json,snapshot_sha256) VALUES (?,?,?)')->execute([$id,$json,hash('sha256',$json)]);
    document_workflow_audit($pdo,$user,'project_report_generated',['entity_type'=>'report','entity_id'=>$id,'student_id'=>$aggregate?null:$snapshot['project']['submission_holder_id'],'details'=>'Canonical project '.($aggregate?'aggregate':$input['projectId']).'; snapshot SHA256 '.hash('sha256',$json)]);
    $commitAttempted=true;
    if (!$pdo->commit()) throw new RuntimeException('Report commit unconfirmed.');
    $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); $schemaLock=false;
    json_out(['ok'=>true,'report'=>['id'=>$id,'name'=>$title,'type'=>$type],'aiUsed'=>!$aggregate && $snapshot['assessmentSource']!=='Local deterministic fallback']);
} catch (Throwable $e) {
    log_api_error('project_report',get_class($e).': '.$e->getMessage());
    $rolledBack=false;
    if ($pdo->inTransaction()) $rolledBack=$pdo->rollBack();
    if ($schemaLock) $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
    if (!$commitAttempted && $path!==null && (!$pdo->inTransaction()) && ($rolledBack || !isset($now))) @unlink($path);
    $known=$e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleValidation || $e instanceof StudentSnapshotConflict;
    json_out(['ok'=>false,'message'=>$known?$e->getMessage():'Project report generation could not be confirmed. Refresh report history before retrying.'], $known?409:503);
}
