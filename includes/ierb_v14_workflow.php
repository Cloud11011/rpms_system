<?php
/** Reviewed V14 foundation. General HTTP activation and automatic enrollment remain unavailable. */
require_once __DIR__.'/ierb_stages.php';
require_once __DIR__.'/current_workflow_eligibility.php';

function ierb_v14_schema_available(PDO $pdo): bool
{
    return in_array((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'".($pdo->inTransaction() ? ' LOCK IN SHARE MODE' : ''))->fetchColumn(), ['12','13'], true);
}

function ierb_v14_gate(PDO $pdo): void
{
    // Enrollment/correction administration remain explicitly opted-in CLI operations.
    if (PHP_SAPI !== 'cli' || getenv('PRISM_IERB_V14_FOUNDATION') !== '1' || !ierb_v14_schema_available($pdo)) {
        throw new AccountLifecycleConflict('General V14 activation remains unavailable. Explicit CLI enrollment and correction administration require operator opt-in.');
    }
}

/** Serialize foundation writes with migration/empty rollback and permanent deletion. */
function ierb_v14_begin(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('V14 mutation owns its transaction.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn() !== 1) throw new AccountLifecycleConflict('Schema maintenance is in progress. Retry after review.');
    try { ierb_v14_gate($pdo); $pdo->beginTransaction(); }
    catch (Throwable $e) { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); throw $e; }
}

/** Called under the Student lock by every legacy stage writer, even while V14 is gated. */
function ierb_v14_guard_legacy_write(PDO $pdo, int $studentId): void
{
    if (!ierb_v14_schema_available($pdo)) return;
    if (research_project_for_student($pdo,$studentId,true)) throw new AccountLifecycleConflict('Canonical project progress must use validated Group Advancement.');
    $q = $pdo->prepare('SELECT id FROM ierb_workflows WHERE student_id=? FOR UPDATE');
    $q->execute([$studentId]);
    if ($q->fetchColumn()) throw new AccountLifecycleConflict('This Student has an explicitly enrolled V14 workflow. Legacy stage decisions cannot change it.');
}

function ierb_v14_reason(string $reason): string
{
    $reason = trim($reason);
    if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)) {
        throw new AccountLifecycleValidation('Provide a reason of 5–1,000 characters.');
    }
    return $reason;
}

function ierb_v14_request_key(string $key): void
{
    if (!preg_match('/\A[a-zA-Z0-9_-]{16,80}\z/D', $key)) throw new AccountLifecycleValidation('A unique request key of 16–80 safe characters is required.');
}

/** Student -> login/actor -> workflow lock order, shared with document and legacy mutations. */
function ierb_v14_lock(PDO $pdo, array $actor, int $studentId, bool $admin = true): array
{
    if (!$pdo->inTransaction()) throw new LogicException('V14 locking requires a transaction.');
    if ($admin && ($actor['role'] ?? '') !== 'admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may manage official correction cases.');
    $student = current_workflow_student($pdo, $studentId, $actor);
    $live = current_workflow_actor($pdo, $actor);
    if ($admin && !legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible active RPMS Administrator is required.');
    $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE student_id=? FOR UPDATE');
    $q->execute([$studentId]);
    return [$student, $live, $q->fetch(PDO::FETCH_ASSOC) ?: null];
}

function ierb_v14_case(PDO $pdo, int $workflowId): ?array
{
    $q = $pdo->prepare('SELECT * FROM ierb_correction_cases WHERE workflow_id=? AND resolved_at IS NULL FOR UPDATE');
    $q->execute([$workflowId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ierb_v14_snapshot(PDO $pdo, array $workflow): array
{
    $case = ierb_v14_case($pdo, (int)$workflow['id']);
    return ['workflowId' => (int)$workflow['id'], 'stage' => $workflow['stage_key'],
        'stageLabel' => IerbStage::LABELS[$workflow['stage_key']], 'revision' => (int)$workflow['revision'],
        'correctionCase' => $case, 'progress' => IerbStage::progress($workflow['stage_key'], $case['interrupted_stage'] ?? null)];
}

/** Immutable request digest prevents a request key being reused with a different decision. */
function ierb_v14_replay(PDO $pdo, array $workflow, string $key, string $digest): ?array
{
    $q = $pdo->prepare('SELECT request_sha256,result_json FROM ierb_workflow_events WHERE workflow_id=? AND request_key=?');
    $q->execute([$workflow['id'], $key]);
    $event = $q->fetch(PDO::FETCH_ASSOC);
    if (!$event) return null;
    if (!hash_equals($event['request_sha256'], $digest)) throw new AccountLifecycleConflict('This request key belongs to a different decision.');
    return json_decode($event['result_json'], true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
}

/** Caller holds locks and owns the transaction. Audit failure rolls back the decision. */
function ierb_v14_event(PDO $pdo, array $actor, array $workflow, string $event, string $from, string $to,
    string $key, string $digest, string $reason, ?int $caseId, array $evidence): array
{
    if (!$pdo->inTransaction()) throw new LogicException('V14 event persistence requires the decision transaction.');
    $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE id=? FOR UPDATE');
    $q->execute([$workflow['id']]);
    $result = ['ok' => true] + ierb_v14_snapshot($pdo, $q->fetch(PDO::FETCH_ASSOC));
    $pdo->prepare('INSERT INTO ierb_workflow_events(workflow_id,revision,event_key,from_stage,to_stage,correction_case_id,
        actor_user_id,reason,evidence_json,request_key,request_sha256,result_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$workflow['id'], $result['revision'], $event, $from, $to, $caseId, $actor['id'], $reason,
            json_encode($evidence, JSON_THROW_ON_ERROR), $key, $digest, json_encode($result, JSON_THROW_ON_ERROR)]);
    $workflowEventId = (int)$pdo->lastInsertId();
    $result['projectNotificationIds'] = research_project_sync($pdo,$actor,$workflow,$event,$workflowEventId,$evidence);
    $pdo->prepare('UPDATE ierb_workflow_events SET result_json=? WHERE id=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),$workflowEventId]);
    document_workflow_audit($pdo, $actor, 'ierb_v14_'.$event, ['entity_type' => 'student',
        'entity_id' => $workflow['student_id'], 'student_id' => $workflow['student_id'], 'reason' => $reason,
        'before' => $from, 'after' => $to, 'details' => 'V14 workflow '.$workflow['id'].'; revision '.$result['revision'].'; correction case '.($caseId ?? 'none')]);
    return $result;
}

/** Explicit selected review context + evidence, NEVER a numeric legacy mapping. */
function ierb_v14_enroll(PDO $pdo, array $actor, int $studentId, string $stage, string $reason, string $evidence, string $key): array
{
    ierb_v14_gate($pdo);
    if (!in_array($stage, [IerbStage::INITIAL, IerbStage::GATHERING], true)) throw new AccountLifecycleValidation('Enroll in an explicitly verified review context. Completion requires its authorized formal submission event.');
    $reason = ierb_v14_reason($reason); $evidence = ierb_v14_reason($evidence); ierb_v14_request_key($key);
    $digest = hash('sha256', json_encode([$actor['id'], 'enroll', $studentId, $stage, $reason, $evidence], JSON_THROW_ON_ERROR));
    if ($pdo->inTransaction()) throw new LogicException('Enrollment owns its transaction.');
    ierb_v14_begin($pdo);
    try {
        [$student, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId);
        $project = research_project_for_student($pdo,$studentId,true);
        if ($project && (int)$project['submission_holder_id'] !== $studentId) throw new AccountLifecycleConflict('Only the designated submission holder owns project evidence.');
        if ($workflow) {
            $result = ierb_v14_replay($pdo, $workflow, $key, $digest);
            if (!$result) throw new AccountLifecycleConflict('This Student already has a V14 workflow.');
        } else {
            $pdo->prepare('INSERT INTO ierb_workflows(student_id,stage_key,legacy_stage_snapshot,enrollment_evidence,enrolled_by_user_id)
                VALUES (?,?,?,?,?)')->execute([$studentId, $stage, $student['stage'], $evidence, $live['id']]);
            $workflow = ['id' => (int)$pdo->lastInsertId(), 'student_id' => $studentId];
            $result = ierb_v14_event($pdo, $live, $workflow, 'enrolled', $student['stage'], $stage, $key, $digest, $reason, null, ['reviewEvidence' => $evidence]);
        }
        $pdo->commit(); document_workflow_deliver($pdo,$result['projectNotificationIds'] ?? []); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** Repeatable correction cases retain their original affected version IDs and review context. */
function ierb_v14_open_correction(PDO $pdo, array $actor, int $studentId, int $expectedRevision,
    string $reason, array $documentIds, bool $requiresAmendment, string $key): array
{
    ierb_v14_gate($pdo); $reason = ierb_v14_reason($reason); ierb_v14_request_key($key);
    if (!$documentIds || count($documentIds) > 100 || count(array_unique($documentIds, SORT_REGULAR)) !== count($documentIds)) {
        throw new AccountLifecycleValidation('Select 1–100 distinct affected document versions.');
    }
    foreach ($documentIds as $id) if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/D', $id)) throw new AccountLifecycleValidation('Invalid document reference.');
    sort($documentIds, SORT_STRING);
    $digest = hash('sha256', json_encode([$actor['id'], 'open_correction', $studentId, $expectedRevision, $reason, $documentIds, $requiresAmendment], JSON_THROW_ON_ERROR));
    if ($pdo->inTransaction()) throw new LogicException('Correction opening owns its transaction.');
    ierb_v14_begin($pdo);
    try {
        [, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId);
        if (!$workflow) throw new AccountLifecycleConflict('Explicit V14 enrollment is required.');
        if ($result = ierb_v14_replay($pdo, $workflow, $key, $digest)) { $pdo->commit(); return $result; }
        if ((int)$workflow['revision'] !== $expectedRevision) throw new AccountLifecycleConflict('The workflow changed. Refresh before opening a correction.');
        try { $to = IerbStage::transition($workflow['stage_key'], 'open_correction'); }
        catch (InvalidArgumentException $e) { throw new AccountLifecycleConflict($e->getMessage()); }
        if (ierb_v14_case($pdo, (int)$workflow['id'])) throw new AccountLifecycleConflict('Resolve the open correction case first.');
        $affected = [];
        foreach ($documentIds as $id) {
            $q = $pdo->prepare('SELECT id,student_id,document_type,stage,version_no,is_current FROM documents WHERE id=? FOR UPDATE');
            $q->execute([$id]); $doc = $q->fetch(PDO::FETCH_ASSOC);
            if (!$doc || (int)$doc['student_id'] !== $studentId || (int)$doc['is_current'] !== 1) throw new AccountLifecycleValidation('Affected documents must be current versions owned by this Student.');
            require_once __DIR__.'/ierb_v14_requirements.php';
            $requirement = ierb_v14_requirement_key($doc['document_type']);
            if ($requirement === 'final_report' || ($requirement === 'amendment' && !$requiresAmendment)) {
                throw new AccountLifecycleValidation('Resolve official corrections before Final Report submission; an affected Amendment Form requires the case Amendment policy.');
            }
            $current = document_current_version($pdo, $studentId, $doc['stage'], $doc['document_type']);
            if (!$current || $current['id'] !== $doc['id']) throw new AccountLifecycleConflict('Select the authoritative current document version.');
            $affected[] = $doc;
        }
        $pdo->prepare('INSERT INTO ierb_correction_cases(workflow_id,interrupted_stage,reason,affected_documents_json,requires_amendment,opened_by_user_id)
            VALUES (?,?,?,?,?,?)')->execute([$workflow['id'], $workflow['stage_key'], $reason, json_encode($affected, JSON_THROW_ON_ERROR), (int)$requiresAmendment, $live['id']]);
        $caseId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE ierb_workflows SET stage_key=?,revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([$to, $workflow['id']]);
        $result = ierb_v14_event($pdo, $live, $workflow, 'open_correction', $workflow['stage_key'], $to, $key, $digest, $reason, $caseId,
            ['affectedDocuments' => $affected, 'requiresAmendment' => $requiresAmendment]);
        $pdo->commit(); document_workflow_deliver($pdo,$result['projectNotificationIds'] ?? []); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** The evidence adapter shares the authorized document transaction and never commits it. */
function ierb_v14_document_event(PDO $pdo, array $actor, int $studentId, string $event, string $documentId): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Document events must be atomic with their authoritative submission/review transaction.');
    require_once __DIR__.'/ierb_v14_requirements.php';
    return ierb_v14_apply_document_event($pdo, $actor, $studentId, $event, $documentId);
}
