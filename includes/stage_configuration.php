<?php
/** Names and guidance share the established stage_labels configuration model. */
require_once __DIR__.'/current_workflow_eligibility.php';

function stage_guidance_available(PDO $pdo): bool
{
    $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    if ($version!=='11') return false;
    $column=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_labels' AND COLUMN_NAME='guidance'")->fetch(PDO::FETCH_ASSOC);
    return $column && $column['COLUMN_TYPE']==='text' && $column['IS_NULLABLE']==='YES';
}

function stage_configuration_version(string $key, string $label, ?string $guidance, ?string $updated, string $revision = '0'): string
{
    return hash('sha256',json_encode([$key,$label,$guidance,$updated,$revision],JSON_THROW_ON_ERROR));
}

/** Immutable edit evidence also distinguishes edits reverted within the same second. */
function stage_configuration_revisions(PDO $pdo): array
{
    $rows=$pdo->query("SELECT entity_id,MAX(id) AS revision FROM activity_logs WHERE entity_type='stage'
        AND action IN ('stage_label_updated','stage_guidance_updated') GROUP BY entity_id")->fetchAll(PDO::FETCH_ASSOC);
    return array_map('strval',array_column($rows,'revision','entity_id'));
}

function stage_configuration_list(PDO $pdo): array
{
    $available=stage_guidance_available($pdo);
    $rows=$pdo->query('SELECT stage_key,label,updated_at'.($available?',guidance':'').' FROM stage_labels')->fetchAll(PDO::FETCH_ASSOC);
    $revisions=stage_configuration_revisions($pdo);
    $byKey=array_column($rows,null,'stage_key'); $stages=[]; $labels=[];
    foreach (STAGE_SEQUENCE as $key) {
        $row=$byKey[$key]??[]; $label=$row['label']??$key; $guidance=$row['guidance']??null;
        $labels[$key]=$label;
        $stages[]=['key'=>$key,'label'=>$label,'guidance'=>$guidance,
            'version'=>stage_configuration_version($key,$label,$guidance,$row['updated_at']??null,$revisions[$key]??'0')];
    }
    return ['ok'=>true,'labels'=>$labels,'order'=>STAGE_SEQUENCE,'stages'=>$stages,'guidanceAvailable'=>$available];
}

function stage_configuration_save(PDO $pdo, array $actor, array $data): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may manage IERB stages.');
    foreach (['stageKey','label','expectedVersion'] as $field) if (!isset($data[$field]) || !is_string($data[$field])) {
        throw new AccountLifecycleValidation('Provide a stage key, display name and the current stage configuration version.');
    }
    $key=$data['stageKey']; $label=trim($data['label']);
    if (!in_array($key,STAGE_SEQUENCE,true)) throw new AccountLifecycleValidation('Unknown stage key.');
    if (!mb_check_encoding($data['label'],'UTF-8') || $label==='' || mb_strlen($data['label'])>190 || preg_match('/[\x00-\x1F\x7F]/u',$data['label'])) {
        throw new AccountLifecycleValidation('Display names must be 1–190 characters on one line.');
    }
    $hasGuidance=array_key_exists('guidance',$data);
    if ($hasGuidance && !is_string($data['guidance'])) throw new AccountLifecycleValidation('Guidance must be plain text.');
    $guidance=$hasGuidance?str_replace(["\r\n","\r"],"\n",$data['guidance']):null;
    if ($hasGuidance && (!mb_check_encoding($guidance,'UTF-8') || mb_strlen($guidance)>4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$guidance))) {
        throw new AccountLifecycleValidation('Guidance must contain no more than 4,000 characters of plain text.');
    }
    if ($hasGuidance) $guidance=trim($guidance);
    $available=stage_guidance_available($pdo);
    if ($hasGuidance && !$available) throw new AccountLifecycleConflict('Guidance saving requires the authorized stage-guidance migration. No changes were saved.');
    if ($pdo->inTransaction()) throw new LogicException('Stage configuration owns its transaction.');
    $pdo->beginTransaction();
    try {
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible active RPMS Administrator is required.');
        $q=$pdo->prepare('SELECT stage_key,label,updated_at'.($available?',guidance':'').' FROM stage_labels WHERE stage_key=? FOR UPDATE');
        $q->execute([$key]); $before=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $oldLabel=$before['label']??$key; $oldGuidance=$before['guidance']??null;
        if (!hash_equals(stage_configuration_version($key,$oldLabel,$oldGuidance,$before['updated_at']??null,stage_configuration_revisions($pdo)[$key]??'0'),$data['expectedVersion'])) {
            throw new AccountLifecycleConflict('This stage configuration changed. Close and reopen Manage IERB Stages before retrying.');
        }
        $newGuidance=$hasGuidance?($guidance!==''?$guidance:null):$oldGuidance;
        if ($before && $label===$oldLabel && $newGuidance===$oldGuidance) { $pdo->commit(); return stage_configuration_list($pdo)+['message'=>'No changes to save.']; }
        if ($available) {
            $pdo->prepare('INSERT INTO stage_labels(stage_key,label,guidance) VALUES (?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),guidance=VALUES(guidance)')
                ->execute([$key,$label,$newGuidance]);
        } else $pdo->prepare('INSERT INTO stage_labels(stage_key,label) VALUES (?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute([$key,$label]);
        $context=['entity_type'=>'stage','entity_id'=>$key,'student_id'=>null,'details'=>'Fixed stage key '.$key.'; configuration only; no progress change'];
        if ($label!==$oldLabel || !$before) document_workflow_audit($pdo,$live,'stage_label_updated',$context+['before'=>$oldLabel,'after'=>$label]);
        if ($newGuidance!==$oldGuidance) document_workflow_audit($pdo,$live,'stage_guidance_updated',$context+[
            'before'=>'sha256:'.hash('sha256',$oldGuidance??''),'after'=>'sha256:'.hash('sha256',$newGuidance??'')]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    stage_labels_map(true);
    return stage_configuration_list($pdo)+['message'=>'IERB stage configuration saved. Official stages are unchanged.'];
}
