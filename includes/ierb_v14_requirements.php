<?php
/** V14 project-submission evidence, held by an explicitly enrolled Student.
 * No group identity inference, implicit enrollment, schema DDL or HTTP activation.
 * Schema 12's append-only event ledger retains each version-bound RPMS decision.
 */
require_once __DIR__.'/ierb_v14_workflow.php';
require_once __DIR__.'/document_catalog.php';
require_once __DIR__.'/ierb_v14_corrections.php';

function ierb_v14_requirements(): array
{
    return [
        'ethics' => ['Certification of Ethics', IerbStage::INITIAL, ['Certification of Ethics', 'Certificate of Ethics']],
        'cv' => ['Curriculum Vitae', IerbStage::INITIAL, ['Curriculum Vitae']],
        'questionnaire' => ['Data Collection Questionnaire', IerbStage::INITIAL, ['Data Collection Questionnaire']],
        'workflow' => ['Diagrammatic Workflow', IerbStage::INITIAL, ['Diagrammatic Workflow']],
        'form11' => ['Form 11 Review Checklist', IerbStage::INITIAL, ['Form 11 Review Checklist']],
        'form12' => ['Form 12 Registration and Application Form', IerbStage::INITIAL, ['Form 12 Registration and Application Form']],
        'form13' => ['Form 13 Study Protocol Assessment Form', IerbStage::INITIAL, ['Form 13 Study Protocol Assessment Form']],
        'form14' => ['Form 14 Informed Consent Assessment Form', IerbStage::INITIAL, ['Form 14 Informed Consent Assessment Form', 'Form 14 Informed Consent Assessment']],
        'consent_english' => ['Informed Consent in English', IerbStage::INITIAL, ['Informed Consent in English', 'Informed Consent - English']],
        'consent_local' => ['Informed Consent in Local Language', IerbStage::INITIAL, ['Informed Consent in Local Language', 'Informed Consent - Local Language']],
        'letter' => ['Letter to IERB Chair', IerbStage::INITIAL, ['Letter to IERB Chair']],
        'payment' => ['Payment Receipt', IerbStage::INITIAL, ['Payment Receipt', 'Payment']],
        'protocol' => ['Study Protocol', IerbStage::INITIAL, ['Study Protocol']],
        'amendment' => ['Amendment Form', IerbStage::CORRECTION, ['Amendment Form']],
        'form33' => ['Form 33 - Certificate of Approval', IerbStage::GATHERING, ['Form 33 - Certificate of Approval']],
        'final_report' => ['Form 33-0B - Final Report Form', IerbStage::COMPLETED, ['Form 33-0B - Final Report Form']],
    ];
}

function ierb_v14_requirement_key(string $type): ?string
{
    foreach (ierb_v14_requirements() as $key => $r) if (in_array($type, $r[2], true)) return $key;
    return null;
}

/** Non-mandatory supporting/historical categories can still be affected by a case.
 * Their acceptance is bound to that exact case and original version, not a new
 * permanent mandatory category or an invented group ownership association.
 */
function ierb_v14_case_requirements(?array $case): array
{
    $requirements = ierb_v14_requirements();
    if ($case) foreach (json_decode($case['affected_documents_json'], true, 512, JSON_THROW_ON_ERROR) as $doc) {
        if (ierb_v14_requirement_key($doc['document_type']) === null) {
            $requirements['affected:'.$doc['id']] = [$doc['document_type'], IerbStage::CORRECTION, [$doc['document_type']]];
        }
    }
    return $requirements;
}

function ierb_v14_requirement_definition(PDO $pdo, int $studentId, string $key): array
{
    $r = ierb_v14_requirements()[$key] ?? null;
    if ($r) return $r;
    $q = $pdo->prepare('SELECT c.* FROM ierb_workflows w JOIN ierb_correction_cases c ON c.workflow_id=w.id
        WHERE w.student_id=? AND c.resolved_at IS NULL FOR UPDATE'); $q->execute([$studentId]);
    $r = ierb_v14_case_requirements($q->fetch(PDO::FETCH_ASSOC) ?: null)[$key] ?? null;
    if (!$r) throw new AccountLifecycleValidation('Select a defined requirement or affected document from the current correction case.');
    return $r;
}

/** Storage buckets are explicit category assignments, never enrollment/stage inference. */
function ierb_v14_upload_stage(PDO $pdo, int $studentId, string $type, string $fallback): string
{
    if (!ierb_v14_schema_available($pdo)) return $fallback;
    $q = $pdo->prepare('SELECT stage_key FROM ierb_workflows WHERE student_id=? FOR UPDATE');
    $q->execute([$studentId]);
    $stage = $q->fetchColumn();
    if (!$stage) return $fallback;
    if ($stage === IerbStage::COMPLETED) throw new AccountLifecycleConflict('The completed V14 submission and its evidence are retained.');
    $key = ierb_v14_requirement_key($type);
    return $key === null ? $fallback : [IerbStage::INITIAL=>'Stage 1', IerbStage::CORRECTION=>'Stage 2',
        IerbStage::GATHERING=>'Stage 3', IerbStage::COMPLETED=>'Stage 4'][ierb_v14_requirements()[$key][1]];
}

/** Read-only companion to legacy list rows; legacy numeric fields retain their meaning. */
function ierb_v14_read_summary(PDO $pdo, int $studentId): ?array
{
    if (!ierb_v14_schema_available($pdo)) return null;
    $project = research_project_for_student($pdo,$studentId);
    if ($project) $studentId = (int)$project['submission_holder_id'];
    $q = $pdo->prepare('SELECT w.*,c.interrupted_stage FROM ierb_workflows w LEFT JOIN ierb_correction_cases c
        ON c.workflow_id=w.id AND c.resolved_at IS NULL WHERE w.student_id=?'); $q->execute([$studentId]);
    $w = $q->fetch(PDO::FETCH_ASSOC);
    return $w ? ['stage'=>$w['stage_key'], 'stageLabel'=>IerbStage::LABELS[$w['stage_key']], 'revision'=>(int)$w['revision'],
        'progress'=>IerbStage::progress($w['stage_key'], $w['interrupted_stage']), 'labels'=>IerbStage::LABELS] : null;
}

/** Required evidence is checked under the Student lock and exact current-version lock. */
function ierb_v14_document_evidence(PDO $pdo, int $studentId, string $id, string $key): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Evidence validation requires the document transaction.');
    $q = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $q->execute([$id]);
    $doc = $q->fetch(PDO::FETCH_ASSOC);
    $definition = ierb_v14_requirement_definition($pdo, $studentId, $key);
    if (!$doc || (int)$doc['student_id'] !== $studentId || (int)$doc['is_current'] !== 1
        || !in_array($doc['document_type'], $definition[2], true)) {
        throw new AccountLifecycleConflict('Requirement evidence must be the correct category and current version owned by this submission holder.');
    }
    $current = document_current_version($pdo, $studentId, $doc['stage'], $doc['document_type']);
    $aliases = $definition[2];
    $q = $pdo->prepare('SELECT id FROM documents WHERE student_id=? AND is_current=1 AND document_type IN ('
        .implode(',',array_fill(0,count($aliases),'?')).') ORDER BY id FOR UPDATE');
    $q->execute(array_merge([$studentId],$aliases));
    if ($q->fetchAll(PDO::FETCH_COLUMN) !== [$id]) throw new AccountLifecycleConflict('This requirement has ambiguous current categories or storage stages. RPMS must review the version chains.');
    $doc = document_with_return($pdo, $doc, true);
    if (!$current || $current['id'] !== $id || $doc['review_status'] !== 'Approved'
        || empty($doc['rpms_submitted_at']) || !empty($doc['rpms_returned_at'])) {
        throw new AccountLifecycleConflict('An approved, formally submitted current version without a return is required.');
    }
    $q = $pdo->prepare("SELECT id FROM activity_logs WHERE entity_type='document' AND entity_id=?
        AND action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms','admin_override_submit_to_rpms')
        AND after_value=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([$id, WF_SUBMITTED_RPMS]); $submissionAuditId = $q->fetchColumn();
    if (!$submissionAuditId) throw new AccountLifecycleConflict('Authoritative formal-submission audit evidence is missing.');
    return ['requirementKey'=>$key, 'documentId'=>$id, 'documentOwnerStudentId'=>$studentId,
        'documentType'=>$doc['document_type'], 'documentStage'=>$doc['stage'], 'versionNo'=>(int)$doc['version_no'],
        'reviewStatus'=>$doc['review_status'], 'formallySubmittedAt'=>$doc['rpms_submitted_at'],
        'submissionAuditId'=>(int)$submissionAuditId];
}

function ierb_v14_acceptances(PDO $pdo, int $workflowId): array
{
    $q = $pdo->prepare("SELECT id,evidence_json,created_at FROM ierb_workflow_events WHERE workflow_id=?
        AND event_key='requirement_accepted' ORDER BY id DESC FOR UPDATE");
    $q->execute([$workflowId]); $accepted = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $evidence = json_decode($event['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $key = $evidence['requirementKey'];
        if (!isset($accepted[$key])) $accepted[$key] = $evidence + ['acceptanceEventId'=>(int)$event['id'], 'acceptedAt'=>$event['created_at']];
    }
    return $accepted;
}

function ierb_v14_accepted_evidence(PDO $pdo, array $workflow, string $key, ?array $case = null): array
{
    $e = ierb_v14_acceptances($pdo, (int)$workflow['id'])[$key] ?? null;
    if (!$e || (($key === 'amendment' || str_starts_with($key,'affected:')) && (!$case || (int)($e['correctionCaseId'] ?? 0) !== (int)$case['id']))) {
        throw new AccountLifecycleConflict('RPMS acceptance is required for '.$key.'.');
    }
    $current = ierb_v14_document_evidence($pdo, (int)$workflow['student_id'], $e['documentId'], $key);
    if ($key === 'amendment') ierb_v14_amendment_for_case($pdo, $workflow, $case, $current);
    if ($current['versionNo'] !== $e['versionNo'] || $current['documentType'] !== $e['documentType']
        || $current['documentStage'] !== $e['documentStage'] || $current['submissionAuditId'] !== $e['submissionAuditId']) {
        throw new AccountLifecycleConflict('The accepted document evidence changed. RPMS must review the current version.');
    }
    if ($key === 'form33' && empty($e['institutionalApprovalReference'])) throw new AccountLifecycleConflict('Institutional approval evidence is required.');
    return $e;
}

function ierb_v14_amendment_for_case(PDO $pdo, array $workflow, array $case, array $evidence): void
{
    $q = $pdo->prepare("SELECT id FROM activity_logs WHERE entity_type='student' AND entity_id=?
        AND action='ierb_v14_open_correction' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([(string)$workflow['student_id']]); $openingAudit = $q->fetchColumn();
    if (!$openingAudit || $evidence['submissionAuditId'] <= (int)$openingAudit) throw new AccountLifecycleConflict('Submit an Amendment Form for the current correction case before acceptance.');
}

function ierb_v14_initial_evidence(PDO $pdo, array $workflow): array
{
    $evidence = [];
    foreach (ierb_v14_requirements() as $key => $r) if ($r[1] === IerbStage::INITIAL) {
        $evidence[$key] = ierb_v14_accepted_evidence($pdo, $workflow, $key);
    }
    return $evidence;
}

/** Live RPMS acceptance is distinct from Adviser approval and from formal submission. */
function ierb_v14_accept_requirement(PDO $pdo, array $actor, int $studentId, string $key, string $id,
    int $expectedRevision, string $reason, string $requestKey, string $institutionalReference = ''): array
{
    if (!ierb_v14_schema_available($pdo)) throw new AccountLifecycleConflict('Schema 12 and explicit V14 enrollment are required.');
    if ((!isset(ierb_v14_requirements()[$key]) && !preg_match('/\Aaffected:[a-zA-Z0-9_-]{1,40}\z/D',$key)) || $key === 'final_report') throw new AccountLifecycleValidation('Select an RPMS requirement; Final Report completes on formal submission.');
    $reason = ierb_v14_reason($reason); ierb_v14_request_key($requestKey);
    $institutionalReference = trim($institutionalReference);
    if ($key === 'form33') $institutionalReference = ierb_v14_reason($institutionalReference);
    elseif ($institutionalReference !== '') throw new AccountLifecycleValidation('Institutional approval references apply to Form 33.');
    $digest = hash('sha256', json_encode([$actor['id'], 'accept', $studentId, $key, $id, $expectedRevision, $reason, $institutionalReference], JSON_THROW_ON_ERROR));
    if ($pdo->inTransaction()) throw new LogicException('Requirement acceptance owns its transaction.');
    $pdo->beginTransaction();
    try {
        [, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId);
        if (!$workflow) throw new AccountLifecycleConflict('Explicit V14 enrollment is required.');
        if ($result = ierb_v14_replay($pdo, $workflow, $requestKey, $digest)) { $pdo->commit(); return $result; }
        if ((int)$workflow['revision'] !== $expectedRevision || $workflow['stage_key'] === IerbStage::COMPLETED) throw new AccountLifecycleConflict('The workflow changed. Refresh before accepting a requirement.');
        $case = ierb_v14_case($pdo, (int)$workflow['id']);
        $definition = ierb_v14_requirement_definition($pdo, $studentId, $key);
        if ($key === 'amendment' && (!$case || !(int)$case['requires_amendment'])) throw new AccountLifecycleConflict('This correction case does not require an Amendment Form.');
        $context = $case['interrupted_stage'] ?? $workflow['stage_key'];
        if ($key === 'form33' && $context !== IerbStage::GATHERING) throw new AccountLifecycleConflict('Initial requirements must be accepted before institutional approval.');
        $evidence = ierb_v14_document_evidence($pdo, $studentId, $id, $key)
            + ['correctionCaseId'=>$case ? (int)$case['id'] : null, 'institutionalApprovalReference'=>$institutionalReference];
        if ($key === 'amendment') ierb_v14_amendment_for_case($pdo, $workflow, $case, $evidence);
        $prior = ierb_v14_acceptances($pdo, (int)$workflow['id'])[$key] ?? null;
        $decisionEvent = 'requirement_accepted';
        if ($prior && $prior['documentId'] === $id && ($key !== 'amendment' || (int)$prior['correctionCaseId'] === (int)$case['id'])) {
            // A case interrupted in Initial Submission resumes there. Reconfirming the
            // last requirement then verifies all 13 without manufacturing a new acceptance.
            if ($workflow['stage_key'] !== IerbStage::INITIAL) throw new AccountLifecycleConflict('This exact requirement version is already accepted.');
            ierb_v14_initial_evidence($pdo, $workflow);
            $decisionEvent = 'requirement_reconfirmed';
            $evidence['priorAcceptanceEventId'] = $prior['acceptanceEventId'];
        }
        $pdo->prepare('UPDATE ierb_workflows SET revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([$workflow['id']]);
        $result = ierb_v14_event($pdo, $live, $workflow, $decisionEvent, $workflow['stage_key'], $workflow['stage_key'],
            $requestKey, $digest, $reason, $case ? (int)$case['id'] : null, $evidence);
        // Acceptance establishes eligibility. Only a reviewed Admin group decision advances.
        // Store the complete original response, including any resulting forward transition.
        $pdo->prepare('UPDATE ierb_workflow_events SET result_json=? WHERE workflow_id=? AND request_key=?')
            ->execute([json_encode($result, JSON_THROW_ON_ERROR), $workflow['id'], $requestKey]);
        $student = current_workflow_student($pdo, $studentId, $live);
        $ids = array_merge($result['projectNotificationIds'] ?? [], document_workflow_notifications($pdo, $live, $student, [], 'PRISM requirement accepted',
            'RPMS accepted '.$definition[0].' version '.$evidence['versionNo'].'.', false));
        $pdo->commit();
        document_workflow_deliver($pdo, $ids);
        return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Only the authorized document service calls this inside its submission transaction. */
function ierb_v14_apply_document_event(PDO $pdo, array $actor, int $studentId, string $event, string $documentId): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Document events require their authoritative transaction.');
    if (!ierb_v14_schema_available($pdo)) return null;
    [, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId, $event !== 'final_report_submitted', true);
    if (!$workflow) {
        if (research_project_for_student($pdo,$studentId,true)) throw new AccountLifecycleConflict('The project Final Report belongs to its single enrolled project workflow. RPMS must reconcile the project record.');
        return null;
    }
    if (!in_array($event, ['final_report_submitted'], true)) throw new AccountLifecycleValidation('Unsupported document event.');
    // Adviser approval, RPMS override, or a verified member's submission of an Adviser-approved version.
    if ($event === 'final_report_submitted' && !in_array($live['role'], ['adviser','admin','student'], true)) throw new AccountLifecycleForbidden('Only an authorized formal submission may complete IERB.');
    $key = 'document-'.$event.'-'.$documentId;
    $digest = hash('sha256', json_encode([$event, $studentId, $documentId], JSON_THROW_ON_ERROR));
    if ($result = ierb_v14_replay($pdo, $workflow, $key, $digest)) return $result;
    if (ierb_v14_case($pdo, (int)$workflow['id'])) throw new AccountLifecycleConflict('Resolve the correction case before forward progress.');
    $project = research_project_for_student($pdo,$studentId,true);
    if (research_projects_available($pdo) && (!$project || (int)$project['submission_holder_id'] !== $studentId)) throw new AccountLifecycleConflict('Canonical project identity and designated submission authority require administrative reconciliation.');
    if ($project) research_project_validate($pdo,$project,$live);
    $evidence = ['initialRequirements'=>ierb_v14_initial_evidence($pdo, $workflow)];
    if ($event === 'final_report_submitted') {
        $evidence['institutionalApproval'] = ierb_v14_accepted_evidence($pdo, $workflow, 'form33');
        $evidence['finalReport'] = ierb_v14_document_evidence($pdo, $studentId, $documentId, 'final_report');
    }
    try { $to = IerbStage::transition($workflow['stage_key'], $event); }
    catch (InvalidArgumentException $e) { throw new AccountLifecycleConflict($e->getMessage()); }
    $pdo->prepare('UPDATE ierb_workflows SET stage_key=?,revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([$to, $workflow['id']]);
    return ierb_v14_event($pdo, $live, $workflow, $event, $workflow['stage_key'], $to, $key, $digest,
        $event === 'final_report_submitted' ? 'Final Report formally submitted in the authorized document transaction.' : 'All 13 current Initial Submission requirements accepted by RPMS.', null, $evidence);
}

/** CLI foundation gate remains: no general V14 HTTP mutation or enrollment route. */
function ierb_v14_resolve_correction(PDO $pdo, array $actor, int $studentId, int $expectedRevision, string $reason, string $key): array
{
    ierb_v14_gate($pdo); $reason = ierb_v14_reason($reason); ierb_v14_request_key($key);
    $digest = hash('sha256', json_encode([$actor['id'], 'resolve', $studentId, $expectedRevision, $reason], JSON_THROW_ON_ERROR));
    ierb_v14_begin($pdo);
    try {
        [, $live, $workflow] = ierb_v14_lock($pdo, $actor, $studentId);
        if (!$workflow) throw new AccountLifecycleConflict('Explicit V14 enrollment is required.');
        if ($result = ierb_v14_replay($pdo, $workflow, $key, $digest)) { $pdo->commit(); return $result; }
        if ((int)$workflow['revision'] !== $expectedRevision) throw new AccountLifecycleConflict('The workflow changed. Refresh before resolving this case.');
        $case = ierb_v14_case($pdo, (int)$workflow['id']);
        if (!$case) throw new AccountLifecycleConflict('There is no open correction case.');
        $to = IerbStage::transition($workflow['stage_key'], 'resolve_correction', $case['interrupted_stage']);
        $evidence = [];
        foreach (json_decode($case['affected_documents_json'], true, 512, JSON_THROW_ON_ERROR) as $affected) {
            $requirement = ierb_v14_requirement_key($affected['document_type']) ?? 'affected:'.$affected['id'];
            if ($requirement === 'final_report') throw new AccountLifecycleConflict('Resolve corrections before formal Final Report submission.');
            $e = ierb_v14_accepted_evidence($pdo, $workflow, $requirement, $case);
            $q = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $q->execute([$e['documentId']]); $node = $q->fetch(PDO::FETCH_ASSOC);
            $seen = [];
            while ($node && $node['id'] !== $affected['id']) {
                if (isset($seen[$node['id']]) || (int)$node['student_id'] !== $studentId || $node['stage'] !== $affected['stage']
                    || $node['document_type'] !== $affected['document_type']) break;
                $seen[$node['id']] = true;
                $q->execute([$node['supersedes_id']]); $node = $q->fetch(PDO::FETCH_ASSOC);
            }
            if (!$node || $node['id'] !== $affected['id'] || $e['documentId'] === $affected['id'] || $e['versionNo'] <= (int)$affected['version_no']) {
                throw new AccountLifecycleConflict('Every affected document needs an accepted current successor in its original version chain.');
            }
            $evidence['correctedDocuments'][] = $e;
        }
        if ((int)$case['requires_amendment']) $evidence['amendment'] = ierb_v14_accepted_evidence($pdo, $workflow, 'amendment', $case);
        $pdo->prepare('UPDATE ierb_correction_cases SET resolved_by_user_id=?,resolved_at=NOW(6),resolution_evidence_json=? WHERE id=?')
            ->execute([$live['id'], json_encode($evidence, JSON_THROW_ON_ERROR), $case['id']]);
        $pdo->prepare('UPDATE ierb_workflows SET stage_key=?,revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([$to, $workflow['id']]);
        $result = ierb_v14_event($pdo, $live, $workflow, 'resolve_correction', $workflow['stage_key'], $to, $key, $digest, $reason, (int)$case['id'], $evidence);
        // Resolution resumes its interrupted context; it never grants forward progress.
        $pdo->commit(); document_workflow_deliver($pdo,$result['projectNotificationIds'] ?? []); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

function ierb_v14_guard_document_delete(PDO $pdo, array $doc): void
{
    if (in_array((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),['14','15'],true)) {
        $q=$pdo->prepare('SELECT id FROM document_summary_versions WHERE document_id=? LIMIT 1 FOR UPDATE'); $q->execute([$doc['id']]);
        if ($q->fetchColumn()) throw new DocumentWorkflowError('Summary versions and their source documents are retained. Use Return for Revision.',409);
    }
    if (!ierb_v14_schema_available($pdo)) return;
    $q = $pdo->prepare('SELECT id FROM ierb_workflows WHERE student_id=? FOR UPDATE'); $q->execute([$doc['student_id']]);
    if ($q->fetchColumn()) throw new DocumentWorkflowError('V14 submission documents and prior versions are retained as workflow evidence. Use Return for Revision.', 409);
}

/** Identical backend states for all roles; source documents stay within their existing permissions. */
function ierb_v14_checklist(PDO $pdo, array $actor, int $studentId, bool $ownTransaction = true): array
{
    if ($ownTransaction && $pdo->inTransaction()) throw new LogicException('Checklist owns its consistent read transaction.');
    if ($ownTransaction) $pdo->beginTransaction();
    elseif (!$pdo->inTransaction()) throw new LogicException('Report snapshot requires a transaction.');
    try {
        if (!ierb_v14_schema_available($pdo)) { if ($ownTransaction) $pdo->commit(); return ['ok'=>true, 'enrolled'=>false]; }
        current_workflow_student($pdo,$studentId,$actor);
        $project = research_project_for_student($pdo,$studentId,true);
        if ($project) $studentId = (int)$project['submission_holder_id'];
        $live = current_workflow_actor($pdo,$actor);
        $q = $pdo->prepare('SELECT * FROM ierb_workflows WHERE student_id=? FOR UPDATE'); $q->execute([$studentId]);
        $workflow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$workflow) { if ($ownTransaction) $pdo->commit(); return ['ok'=>true, 'enrolled'=>false]; }
        $case = ierb_v14_case($pdo, (int)$workflow['id']);
        $q = $pdo->prepare('SELECT * FROM documents WHERE student_id=? AND is_current=1 ORDER BY uploaded_at DESC,version_no DESC,id DESC FOR UPDATE');
        $q->execute([$studentId]); $docs = $q->fetchAll(PDO::FETCH_ASSOC); $rows = [];
        foreach (ierb_v14_case_requirements($case) as $key => $r) {
            if ($key === 'amendment' && (!$case || !(int)$case['requires_amendment'])) continue;
            $doc = null; $accepted = null;
            try { $accepted = ierb_v14_accepted_evidence($pdo, $workflow, $key, $case); }
            catch (AccountLifecycleConflict $e) { /* Missing, pending or changed versions cannot be accepted. */ }
            foreach ($docs as $candidate) if (in_array($candidate['document_type'], $r[2], true)
                && (!$accepted || $candidate['id'] === $accepted['documentId'])) { $doc = document_with_return($pdo, $candidate, true); break; }
            $state = 'Missing';
            if ($doc) {
                $state = 'Uploaded / Pending Review';
                if (in_array($doc['review_status'], ['Denied','Resubmission Requested'], true) || !empty($doc['rpms_returned_at'])) $state = 'Returned for Revision';
                elseif ($doc['review_status'] === 'Approved' && !empty($doc['rpms_submitted_at'])) {
                    try { ierb_v14_document_evidence($pdo, $studentId, $doc['id'], $key); $state = 'Formally Submitted to RPMS'; }
                    catch (AccountLifecycleConflict $e) { /* Do not present an unaudited stamp as submission evidence. */ }
                }
                if ($accepted) $state = 'Accepted';
            }
            // Verified members share the project evidence record; any other Student never reaches here.
            $rows[] = ['key'=>$key, 'label'=>$r[0], 'stage'=>$r[1], 'state'=>$state,
                'documentId'=>$doc['id'] ?? null, 'documentType'=>$doc['document_type'] ?? null,
                'versionNo'=>$doc ? (int)$doc['version_no'] : null, 'reviewStatus'=>$doc['review_status'] ?? null,
                'acceptedAt'=>$accepted['acceptedAt'] ?? null, 'acceptanceEventId'=>$accepted['acceptanceEventId'] ?? null,
                'canAccept'=>$live['role'] === 'admin' && $key !== 'final_report' && ($state === 'Formally Submitted to RPMS'
                    || ($state === 'Accepted' && $workflow['stage_key'] === IerbStage::INITIAL))
                    && $workflow['stage_key'] !== IerbStage::COMPLETED
                    && ($key !== 'form33' || ($case['interrupted_stage'] ?? $workflow['stage_key']) === IerbStage::GATHERING)];
        }
        $snapshot = ierb_v14_snapshot($pdo, $workflow);
        // No internal case reason, administrator identity, or affected-document JSON in Student reads.
        $snapshot['correctionCase'] = $case ? ['id'=>(int)$case['id'], 'interruptedStage'=>$case['interrupted_stage'],
            'requiresAmendment'=>(bool)$case['requires_amendment'], 'openedAt'=>$case['opened_at']] : null;
        if ($ownTransaction) $pdo->commit();
        return ['ok'=>true, 'enrolled'=>true, 'submissionScope'=>['kind'=>'project_submission_holder', 'studentId'=>$studentId,
            'groupSynchronization'=>$project ? 'canonical' : 'requires_reconciliation', 'projectId'=>$project ? (int)$project['id'] : null, 'identifier'=>$project['public_identifier'] ?? null,
            'researchTitle'=>$project['research_title'] ?? null, 'principalInvestigator'=>$project ? research_project_principal_investigator($pdo,(int)$project['id']) : null,
            'uploadPolicy'=>$project ? 'Every verified member may upload, revise and formally submit Adviser-approved project documents.' : null],
            'workflow'=>$snapshot, 'requirements'=>$rows, 'pendingCorrectionRequest'=>ierb_v14_pending_request_summary($pdo, $workflow, $live),
            'viewerRole'=>$live['role'], 'operationsActivated'=>ierb_v14_http_operations_enabled()];
    } catch (Throwable $e) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
