<?php
/** Adviser correction requests and RPMS decisions on the reviewed schema-12 event ledger.
 * A request is an immutable 'correction_requested' event that changes no stage. It is decided by exactly one
 * later event that names it: 'open_correction' (approved, or superseded by an RPMS-initiated case) or
 * 'correction_request_rejected'. No new table or schema version is introduced.
 */
require_once __DIR__.'/ierb_v14_workflow.php';
require_once __DIR__.'/ierb_v14_requirements.php';

const IERB_V14_REQUEST_DECISIONS = ['open_correction', 'correction_request_rejected'];

function ierb_v14_request_decided(PDO $pdo, int $workflowId, int $requestId): ?array
{
    $q = $pdo->prepare("SELECT id,event_key,actor_user_id,reason,evidence_json,created_at FROM ierb_workflow_events
        WHERE workflow_id=? AND id>? AND event_key IN ('open_correction','correction_request_rejected') ORDER BY id".($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $q->execute([$workflowId, $requestId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $evidence = json_decode($event['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        if ((int)($evidence['requestEventId'] ?? 0) === $requestId) return $event + ['evidence' => $evidence];
    }
    return null;
}

/** The latest request, if no decision names it. Earlier requests are always decided (one pending at a time). */
function ierb_v14_pending_request(PDO $pdo, int $workflowId): ?array
{
    $q = $pdo->prepare("SELECT * FROM ierb_workflow_events WHERE workflow_id=? AND event_key='correction_requested' ORDER BY id DESC LIMIT 1".($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $q->execute([$workflowId]); $request = $q->fetch(PDO::FETCH_ASSOC);
    if (!$request || ierb_v14_request_decided($pdo, $workflowId, (int)$request['id'])) return null;
    return $request;
}

/** Role-appropriate read model. Students see status only, never staff reasons or affected-version detail. */
function ierb_v14_pending_request_summary(PDO $pdo, array $workflow, array $viewer): ?array
{
    $request = ierb_v14_pending_request($pdo, (int)$workflow['id']);
    if (!$request) return null;
    $summary = ['requestEventId' => (int)$request['id'], 'status' => 'Pending RPMS Review', 'requestedAt' => $request['created_at'],
        'officialStageUnchanged' => true];
    if (in_array($viewer['role'] ?? '', ['admin', 'adviser'], true)) {
        $evidence = json_decode($request['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $q = $pdo->prepare('SELECT full_name FROM users WHERE id=?'); $q->execute([$request['actor_user_id']]);
        $summary += ['requestedBy' => $q->fetchColumn() ?: null, 'reason' => $request['reason'], 'suggestedAmendment' => (bool)$evidence['suggestedAmendment'],
            'proposedDocuments' => array_map(static fn($d) => ['id' => $d['id'], 'documentType' => $d['document_type'], 'versionNo' => (int)$d['version_no']], $evidence['proposedDocuments'])];
    }
    return $summary;
}

/** Transparent in-app notices: every verified member, the assigned Adviser, and optionally RPMS. Caller owns the transaction. */
function ierb_v14_correction_notices(PDO $pdo, array $actor, int $studentId, string $subject, string $message, bool $adviser, bool $admins): array
{
    $project = research_project_for_student($pdo, $studentId, true);
    $memberIds = $project ? array_map('intval', array_column(research_project_members($pdo, (int)$project['id']), 'id')) : [$studentId];
    $ids = [];
    foreach ($memberIds as $memberId) $ids = array_merge($ids, workflow_queue_notices($pdo, $actor, $memberId, ['subject' => $subject, 'message' => $message], null));
    if ($adviser) $ids = array_merge($ids, workflow_queue_notices($pdo, $actor, $studentId, null, ['subject' => $subject, 'message' => $message]));
    if ($admins) {
        $q = $pdo->query("SELECT id,email,full_name FROM users WHERE role='admin' AND status='Active' ORDER BY id FOR UPDATE");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $admin) {
            $copy = create_notification($pdo, 'admin', (int)$admin['id'], $admin['email'], $admin['full_name'], $subject, $message,
                'Review Needed', $actor['full_name'], null, date('Y-m-d H:i:s'));
            if (!isset($copy['id'])) throw new AccountLifecycleConflict('An RPMS recipient changed. Refresh before continuing.');
            $ids[] = (int)$copy['id'];
        }
    }
    return $ids;
}

function ierb_v14_event_id(PDO $pdo, int $workflowId, string $key): int
{
    $q = $pdo->prepare('SELECT id FROM ierb_workflow_events WHERE workflow_id=? AND request_key=?'); $q->execute([$workflowId, $key]);
    return (int)$q->fetchColumn();
}

/** Retired entry point retained only to fail closed for older internal callers.
 * Adviser review/revision of individual documents remains in document_workflow.php.
 * Only an RPMS Administrator may initiate an official IERB resubmission.
 */
function ierb_v14_request_correction(PDO $pdo, array $actor, int $studentId, int $expectedRevision, string $reason,
    array $documentIds, bool $suggestAmendment, string $key): array
{
    throw new AccountLifecycleForbidden('Advisers cannot initiate official IERB resubmissions. RPMS Administrators must open the case directly.');
}

/** RPMS only. Approval opens the official case (Stage 2) atomically; rejection keeps the stage. Both are recorded with a reason. */
function ierb_v14_decide_correction_request(PDO $pdo, array $actor, int $studentId, int $expectedRevision, int $requestEventId,
    string $decision, string $reason, bool $requiresAmendment, ?array $documentIds, string $key): array
{
    ierb_v14_gate($pdo); $reason = ierb_v14_reason($reason); ierb_v14_request_key($key);
    if (!in_array($decision, ['approve', 'reject'], true)) throw new AccountLifecycleValidation('Choose Approve or Reject.');
    if ($documentIds !== null) $documentIds = ierb_v14_document_ids($documentIds);
    $digest = hash('sha256', json_encode([$actor['id'], 'decide_request', $studentId, $expectedRevision, $requestEventId, $decision, $reason, $requiresAmendment, $documentIds], JSON_THROW_ON_ERROR));
    if ($pdo->inTransaction()) throw new LogicException('Correction decisions own their transaction.');
    ierb_v14_begin($pdo);
    try {
        [, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId);
        if (!$workflow) throw new AccountLifecycleConflict('Explicit V14 enrollment is required.');
        if ($result = ierb_v14_replay($pdo, $workflow, $key, $digest)) { $pdo->commit(); return $result; }
        if ((int)$workflow['revision'] !== $expectedRevision) throw new AccountLifecycleConflict('The workflow changed. Refresh before deciding this request.');
        $request = ierb_v14_pending_request($pdo, (int)$workflow['id']);
        if (!$request || (int)$request['id'] !== $requestEventId) throw new AccountLifecycleConflict('This correction request was already decided or replaced. Refresh the review queue.');
        $requestEvidence = json_decode($request['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $origin = ['requestEventId' => $requestEventId, 'requestedByUserId' => (int)$request['actor_user_id'], 'requestDecision' => $decision === 'approve' ? 'approved' : 'rejected'];
        if ($decision === 'approve') {
            $documentIds ??= ierb_v14_document_ids(array_column($requestEvidence['proposedDocuments'], 'id'));
            $result = ierb_v14_open_correction_locked($pdo, $live, $workflow, $studentId, $reason, $documentIds, $requiresAmendment, $key, $digest, $origin);
            $message = 'RPMS approved the correction request. The official stage is now Resubmission / Amendment.'
                .($requiresAmendment ? ' An Amendment Form is required for this case.' : ' No Amendment Form is required for this case.');
        } else {
            $pdo->prepare('UPDATE ierb_workflows SET revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([$workflow['id']]);
            $result = ierb_v14_event($pdo, $live, $workflow, 'correction_request_rejected', $workflow['stage_key'], $workflow['stage_key'], $key, $digest, $reason, null, $origin);
            $message = 'RPMS rejected the correction request. The official stage is unchanged. Reason: '.$reason;
        }
        $ids = array_merge($result['projectNotificationIds'] ?? [], ierb_v14_correction_notices($pdo, $live, $studentId, 'PRISM correction request decision', $message, true, false));
        $pdo->commit(); document_workflow_deliver($pdo, $ids); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** RPMS review queue: pending requests with the facts needed to decide. Read-only. */
function ierb_v14_correction_queue(PDO $pdo, array $actor): array
{
    if (($actor['role'] ?? '') !== 'admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may review correction requests.');
    if (!ierb_v14_schema_available($pdo)) return ['ok' => true, 'requests' => []];
    $projects = research_projects_available($pdo);
    $rows = $pdo->query("SELECT e.id,e.workflow_id,e.reason,e.evidence_json,e.created_at,w.student_id,w.stage_key,w.revision,u.full_name AS requested_by,s.full_name AS student_name"
        .($projects ? ',p.id AS project_id,p.public_identifier,p.research_title' : ',NULL AS project_id,NULL AS public_identifier,s.research_title')
        ." FROM ierb_workflow_events e JOIN ierb_workflows w ON w.id=e.workflow_id JOIN users u ON u.id=e.actor_user_id JOIN students s ON s.id=w.student_id"
        .($projects ? ' LEFT JOIN research_projects p ON p.workflow_id=w.id' : '')
        ." WHERE e.event_key='correction_requested' AND e.id=(SELECT MAX(r.id) FROM ierb_workflow_events r WHERE r.workflow_id=e.workflow_id AND r.event_key='correction_requested')
        ORDER BY e.created_at DESC, e.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $requests = [];
    foreach ($rows as $r) {
        if (ierb_v14_request_decided($pdo, (int)$r['workflow_id'], (int)$r['id'])) continue;
        $evidence = json_decode($r['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $requests[] = ['requestEventId' => (int)$r['id'], 'studentId' => (int)$r['student_id'], 'workflowRevision' => (int)$r['revision'],
            'stage' => $r['stage_key'], 'stageLabel' => IerbStage::LABELS[$r['stage_key']], 'projectId' => $r['project_id'] ? (int)$r['project_id'] : null,
            'projectIdentifier' => $r['public_identifier'], 'researchTitle' => $r['research_title'], 'recordName' => $r['student_name'],
            'requestedBy' => $r['requested_by'], 'requestedAt' => $r['created_at'], 'reason' => $r['reason'], 'suggestedAmendment' => (bool)$evidence['suggestedAmendment'],
            'proposedDocuments' => array_map(static fn($d) => ['id' => $d['id'], 'documentType' => $d['document_type'], 'versionNo' => (int)$d['version_no']], $evidence['proposedDocuments'])];
    }
    return ['ok' => true, 'requests' => $requests];
}
