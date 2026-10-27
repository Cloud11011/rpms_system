<?php
require_once __DIR__.'/ierb_v14_requirements.php';

function research_project_group_inspect(PDO $pdo, array $actor, int $id): array
{
    research_project_fence($pdo);
    $q = $pdo->prepare('SELECT * FROM research_projects WHERE id=? FOR UPDATE'); $q->execute([$id]); $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new AccountLifecycleConflict('The canonical project is unavailable.');
    $live = ierb_group_live_admin($pdo,$actor);
    $rows = research_project_members($pdo,$id); $members = []; $fingerprints = []; $reasons = [];
    foreach ($rows as $row) {
        $issues = []; $login = null;
        try {
            current_workflow_student($pdo,(int)$row['id'],$live);
            $login = lifecycle_resolve_login($pdo,$row,'student');
            if (!empty($login['must_change_password']) || legal_outstanding($pdo,(int)$login['id'])) $issues[] = 'Student account setup or legal acceptance is incomplete.';
            if ((int)$row['adviser_id'] !== (int)$p['adviser_id'] || $row['research_group'] !== $p['public_identifier'] || $row['research_title'] !== $p['research_title']) $issues[] = 'Canonical membership or Adviser assignment requires administrative reconciliation.';
            $assigned = student_with_adviser($pdo,(int)$row['id']);
            if (empty($assigned['adviser_pk'])) $issues[] = 'Assigned Adviser is unavailable.';
            else {
                $recipients = notification_lock_recipients($pdo,[['type'=>'adviser','id'=>(int)$assigned['adviser_pk'],'email'=>$assigned['adviser_email'],'name'=>$assigned['adviser_name']]]);
                if (!$recipients) $issues[] = 'Assigned Adviser notification account is unavailable.';
                notification_recipient_user($pdo,'adviser',(int)$assigned['adviser_pk'],(string)$assigned['adviser_email']);
            }
        } catch (AccountLifecycleConflict|NotificationLifecycleError $e) { $issues[] = $e->getMessage(); }
        $members[] = ['id'=>(int)$row['id'],'studentId'=>$row['student_id'],'name'=>$row['full_name'],'stage'=>$row['project_stage'],
            'stageLabel'=>IerbStage::LABELS[$row['project_stage']],'status'=>$row['status'],'progressVersion'=>(string)$row['workflow_revision'],'eligible'=>!$issues,'issues'=>$issues];
        $fingerprints[(string)$row['id']] = hash('sha256',json_encode([$row,$login ?? null],JSON_THROW_ON_ERROR));
    }
    $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE id=? FOR UPDATE'); $q->execute([$p['workflow_id']]); $w = $q->fetch(PDO::FETCH_ASSOC);
    if (!$w || (int)$w['student_id'] !== (int)$p['submission_holder_id'] || !in_array((int)$p['submission_holder_id'],array_column($members,'id'),true)) throw new AccountLifecycleConflict('Project submission authority requires reconciliation.');
    $case = ierb_v14_case($pdo,(int)$w['id']);
    if ($case) $reasons[] = 'Unresolved correction case #'.$case['id'].' must return to its interrupted review before normal advancement.';
    if ($w['stage_key'] !== IerbStage::INITIAL) $reasons[] = $w['stage_key'] === IerbStage::GATHERING
        ? 'Final Report formal submission completes this project automatically. Manual completion is unavailable.' : 'This stage is not eligible for normal advancement.';
    $evidence = [];
    foreach (ierb_v14_requirements() as $key=>$r) if ($r[1] === IerbStage::INITIAL) {
        try { $evidence[$key] = ierb_v14_accepted_evidence($pdo,$w,$key); }
        catch (AccountLifecycleConflict $e) {
            $q=$pdo->prepare('SELECT * FROM documents WHERE student_id=? AND is_current=1 AND document_type IN ('.implode(',',array_fill(0,count($r[2]),'?')).') ORDER BY id FOR UPDATE');
            $q->execute(array_merge([(int)$w['student_id']],$r[2])); $docs=$q->fetchAll(PDO::FETCH_ASSOC);
            $state='Missing';
            if (count($docs)>1) $state='Ambiguous current versions';
            elseif ($docs) {
                $doc=document_with_return($pdo,$docs[0],true);
                $state=!empty($doc['rpms_returned_at']) ? 'Returned for Revision' : (in_array($doc['review_status'],['Denied','Resubmission Requested'],true) ? 'Rejected / Needs Revision'
                    : (!empty($doc['rpms_submitted_at']) ? 'Formally submitted; acceptance or revalidation required' : 'Uploaded / Pending Review'));
            }
            $reasons[] = $r[0].' — '.$state.': '.$e->getMessage();
        }
    }
    if (array_filter($members,static fn($m)=>!$m['eligible'])) $reasons[] = 'Review the listed member eligibility issues.';
    foreach ($rows as $row) if ($row['project_stage'] !== $w['stage_key'] || (int)$row['workflow_revision'] !== (int)$w['revision']) $reasons[] = 'Member progress is inconsistent with its authoritative workflow; administrative reconciliation required.';
    $fingerprints['project'] = hash('sha256',json_encode([$p,$w,$case,$evidence],JSON_THROW_ON_ERROR));
    return ['identity'=>['project_id'=>$id],'label'=>$p['research_title'].' / '.$p['public_identifier'],'memberCount'=>count($members),'members'=>$members,
        'currentStage'=>$w['stage_key'],'currentStageLabel'=>IerbStage::LABELS[$w['stage_key']],
        'nextStage'=>$w['stage_key'] === IerbStage::INITIAL ? IerbStage::GATHERING : null,
        'nextStageLabel'=>$w['stage_key'] === IerbStage::INITIAL ? IerbStage::LABELS[IerbStage::GATHERING] : null,
        'eligible'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'fingerprints'=>$fingerprints,'workflow'=>$w,'evidence'=>$evidence];
}

/** Called only after the existing session token and exact preview have been revalidated. */
function research_project_group_advance(PDO $pdo, array $live, array $preview, string $reason, string $correlation): array
{
    $w = $preview['workflow'];
    $to = IerbStage::transition($w['stage_key'],'initial_requirements_accepted');
    $q=$pdo->prepare('UPDATE ierb_workflows SET stage_key=?,revision=revision+1,updated_at=NOW(6) WHERE id=? AND revision=?'); $q->execute([$to,$w['id'],$w['revision']]);
    if ($q->rowCount()!==1) throw new AccountLifecycleConflict('The project workflow changed. Refresh its preview.');
    $result = ierb_v14_event($pdo,$live,$w,'initial_requirements_accepted',$w['stage_key'],$to,'admin-group-'.$correlation,
        hash('sha256',json_encode([$preview['fingerprints'],$reason],JSON_THROW_ON_ERROR)),$reason,null,
        ['projectId'=>$preview['identity']['project_id'],'initialRequirements'=>$preview['evidence'],'administratorConfirmed'=>true]);
    return [['ok'=>true,'stage'=>$to,'stageLabel'=>IerbStage::LABELS[$to],'memberCount'=>$preview['memberCount'],
        'correlationId'=>$correlation,'message'=>'Research group advanced after all mandatory requirements were verified.'], $result['projectNotificationIds'] ?? []];
}
