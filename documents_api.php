<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/account_lifecycle.php';
require_once __DIR__ . '/includes/office_container.php';
require_once __DIR__ . '/includes/document_catalog.php';
require_once __DIR__ . '/includes/document_upload.php';
require_once __DIR__ . '/includes/document_summary.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
if (in_array($action, ['summarize', 'summarize_preview', 'summarize_cancel'], true) && $user['role'] !== 'admin') {
    audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'details' => 'source=none; category=forbidden_role; partial=unknown']);
    json_out(['ok' => false, 'message' => 'Only RPMS Administrators may generate document summaries.'], 403);
}
if (in_array($action, ['upload', 'review', 'comment', 'return_for_revision', 'submit_to_rpms', 'override_review', 'delete', 'summarize', 'summarize_preview', 'summarize_cancel'], true)) {
    require_post_same_origin();
}

// The viewing adviser's row id in `advisers` (used to scope who may review what).
$viewerAdviserId = null;
if ($user['role'] === 'adviser') {
    $q = $pdo->prepare('SELECT id FROM advisers WHERE email = :e');
    $q->execute([':e' => $user['email']]);
    $found = $q->fetchColumn();
    $viewerAdviserId = $found ? (int)$found : null;
}

/** Loads one document joined with the student fields the UI needs. */
function fetch_doc(PDO $pdo, string $id, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at
        FROM documents d LEFT JOIN students s ON s.id = d.student_id WHERE d.id = :id'
        . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? document_with_return($pdo, $row, $forUpdate) : null;
}

class DocumentWriteConflict extends RuntimeException { public int $status=409; }

/** Filesystem cleanup cannot participate in a database rollback; record any failure for follow-up. */
function remove_document_file(string $path, string $documentId, string $context): void
{
    if (is_file($path) && !@unlink($path)) {
        log_api_error('document_file_cleanup', 'Could not remove file for document ' . $documentId . ' (' . $context . ').');
    }
}

/** Serialize document/version writes and reject decisions made from an outdated row. */
function begin_document_write(PDO $pdo, array $document): void
{
    $pdo->beginTransaction();
    global $user;
    try {
        if (empty($document['student_id'])) throw new AccountLifecycleConflict('Document has no eligible Student record.');
        current_workflow_student($pdo,(int)$document['student_id'],$user);
    } catch (AccountLifecycleForbidden $e) { $error=new DocumentWriteConflict($e->getMessage(),0,$e); $error->status=403; throw $error; }
        catch (AccountLifecycleConflict $e) { throw new DocumentWriteConflict($e->getMessage(),0,$e); }
    $current = fetch_doc($pdo, (string)$document['id'], true);
    if ($current && !empty($current['student_id'])) {
        try {
            $version = document_current_version($pdo, (int)$current['student_id'], $current['stage'], $current['document_type']);
            if ((int)$current['is_current'] === 1 && (!$version || $version['id'] !== $current['id'])) {
                throw new DocumentWorkflowError('The current version changed. Refresh before continuing.',409);
            }
        } catch (DocumentWorkflowError $e) { throw new DocumentWriteConflict($e->getMessage(),0,$e); }
    }
    $fields = ['student_id', 'adviser_id', 'archived_at', 'is_current', 'supersedes_id', 'review_status',
        'review_remarks', 'reviewed_by', 'reviewed_at', 'rpms_submitted_at', 'admin_override',
        'override_reason', 'override_by', 'override_at', 'rpms_returned_at', 'rpms_returned_by', 'rpms_return_reason'];
    if ($current) {
        foreach ($fields as $field) {
            if (($current[$field] ?? null) !== ($document[$field] ?? null)) {
                $current = null;
                break;
            }
        }
    }
    if (!$current) {
        throw new DocumentWriteConflict('This document changed while you were working. Refresh to see its latest status and try again.');
    }
}

/** May this user change the review status of this document? (Locking is checked separately.) */
function can_review_doc(array $user, ?int $viewerAdviserId, array $d): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($user['role'] !== 'adviser') {
        return false;
    }
    return $viewerAdviserId !== null && !empty($d['adviser_id']) && (int)$d['adviser_id'] === $viewerAdviserId && empty($d['archived_at']);
}

function doc_row(array $d, array $user, ?int $viewerAdviserId): array
{
    $d = document_with_return(db(), $d);
    $state = document_workflow_state($d);
    $locked = document_is_locked($d);
    $isCurrent = !isset($d['is_current']) || (int)$d['is_current'] === 1;
    $role = $user['role'];
    $savedSummary = $role === 'admin' ? document_summary_unpack($d['ai_summary']) : null;

    $row = [
        'id' => $d['id'],
        'originalName' => $d['original_name'],
        'size' => (int)$d['size'],
        'mime' => $d['mime'],
        'uploadedBy' => $d['uploaded_by'],
        'uploadedAt' => $d['uploaded_at'],
        'year' => $d['uploaded_at'] ? (int)date('Y', strtotime($d['uploaded_at'])) : null,
        'student' => $d['student_name'],
        'studentId' => $d['student_id'] ? (int)$d['student_id'] : null,
        'protocolCode' => $d['protocol_code'] ?? null,
        'course' => $d['course'] ?? null,
        'documentType' => $d['document_type'],
        'stage' => $d['stage'],
        'stageLabel' => stage_label($d['stage']),
        'notes' => $d['notes'],
        'reviewStatus' => $d['review_status'],
        'reviewRemarks' => $d['review_remarks'],
        'reviewedBy' => $d['reviewed_by'],
        'reviewedAt' => $d['reviewed_at'],
        // The AI/regex-detected approval date printed on the document itself.
        'detectedApprovalDate' => $d['detected_approval_date'] ?? null,
        'approvalDateSource' => $d['approval_date_source'] ?? null,
        'aiSummary' => $savedSummary['summary'] ?? null,
        'summarySource' => $savedSummary['source'] ?? null,
        'summaryPartial' => $savedSummary['partial'] ?? false,
        'summaryNotice' => $savedSummary['notice'] ?? '',
        'summaryReason' => $savedSummary['reason'] ?? null,

        // Workflow + record integrity
        'workflowState' => $state,
        'versionNo' => (int)($d['version_no'] ?? 1),
        'isCurrent' => $isCurrent,
        'supersedesId' => $d['supersedes_id'] ?? null,
        'locked' => $locked,
        'rpmsSubmittedAt' => $d['rpms_submitted_at'] ?? null,
        'rpmsSubmittedBy' => $d['rpms_submitted_by'] ?? null,
        'rpmsReturnedAt' => $d['rpms_returned_at'] ?? null,
        'rpmsReturnedBy' => $d['rpms_returned_by'] ?? null,
        'rpmsReturnReason' => $d['rpms_return_reason'] ?? null,
        'adminOverride' => !empty($d['admin_override']),
        'overrideReason' => $d['override_reason'] ?? null,
        'overrideBy' => $d['override_by'] ?? null,
        'overrideAt' => $d['override_at'] ?? null,

        // What the signed-in user may do with this row (the UI just reads these flags).
        'actions' => [
            'summarize' => empty($d['archived_at']) && $role === 'admin',
            'review' => empty($d['archived_at']) && $isCurrent && !$locked && $role === 'adviser' && (empty($d['admin_override']) || $d['review_status'] !== 'Approved')
                && can_review_doc($user, $viewerAdviserId, $d) && !in_array($d['review_status'], ['Denied','Resubmission Requested'], true),
            'comment' => empty($d['archived_at']) && $isCurrent && can_review_doc($user, $viewerAdviserId, $d),
            'submitToRpms' => empty($d['archived_at']) && $isCurrent && !$locked && $state === WF_READY_FOR_RPMS && $role === 'admin',
            'returnForRevision' => empty($d['archived_at']) && $isCurrent && $state === WF_SUBMITTED_RPMS && $role === 'admin',
            'uploadRevision' => empty($d['archived_at']) && $isCurrent && $role === 'student' && in_array($state, [WF_NEEDS_REVISION,WF_RETURNED_REVISION], true),
            'forceSubmitToRpms' => empty($d['archived_at']) && $isCurrent && !$locked && $role === 'admin' && $state !== WF_READY_FOR_RPMS,
            'override' => empty($d['archived_at']) && $isCurrent && !$locked && $role === 'admin',
            'delete' => empty($d['archived_at']) && in_array($role, ['admin', 'adviser'], true) && (!$locked || $role === 'admin'),
        ],
    ];
    if ($role === 'student') {
        // Review feedback and explicit RPMS Return instructions are Student-facing;
        // administrative override evidence and staff identity belong to staff views.
        foreach (['adminOverride','overrideReason','overrideBy','overrideAt','reviewedBy','rpmsSubmittedBy','rpmsReturnedBy'] as $field) unset($row[$field]);
        if (($d['uploaded_by_role'] ?? '') !== 'student') {
            unset($row['uploadedBy']);
            $row['notes'] = null; // Unclassified staff upload annotations are internal.
        }
        if (!empty($d['admin_override']) || stripos((string)($row['reviewRemarks'] ?? ''),'Admin override: ') === 0) $row['reviewRemarks'] = null;
    }
    return $row;
}

// ---------------------------------------------------------------------
// list
// ---------------------------------------------------------------------
if ($action === 'list') {
    $scope = 'FROM documents d LEFT JOIN students s ON s.id = d.student_id WHERE 1=1';
    $params = [];
    if ($user['role'] === 'student') {
        $scope .= ' AND s.email = :e'; $params[':e'] = $user['email'];
    } elseif ($user['role'] === 'adviser') {
        $scope .= $viewerAdviserId !== null ? ' AND s.adviser_id = :vad AND s.archived_at IS NULL' : ' AND 1=0';
        if ($viewerAdviserId !== null) $params[':vad'] = $viewerAdviserId;
    }
    if (($_GET['includeOld'] ?? '') !== '1') $scope .= ' AND d.is_current = 1';
    $stateSql = "CASE WHEN d.is_current = 0 THEN 'Superseded' WHEN " . document_returned_sql() . " THEN 'Returned for Revision' WHEN d.review_status = 'Approved' THEN CASE WHEN d.rpms_submitted_at IS NOT NULL AND CAST(d.rpms_submitted_at AS CHAR) <> '' THEN 'Submitted to RPMS' ELSE 'Ready for Formal RPMS Submission' END WHEN d.review_status IN ('Denied', 'Resubmission Requested') THEN 'Needs Revision' ELSE 'Pending Adviser Review' END";
    if (($_GET['actionable'] ?? '') === '1') {
        $scope .= " AND d.is_current = 1 AND ($stateSql) IN ('Needs Revision','Returned for Revision','Ready for Formal RPMS Submission')";
    }
    $stmt = $pdo->prepare('SELECT ' . $stateSql . ' AS state, COUNT(*) AS c ' . $scope . ' GROUP BY ' . $stateSql);
    $stmt->execute($params);
    $counts = array_map('intval', array_column($stmt->fetchAll(), 'c', 'state'));
    $filterOptions = [];
    foreach (['types' => 'd.document_type', 'courses' => 's.course', 'years' => 'SUBSTR(d.uploaded_at,1,4)'] as $key => $column) {
        $stmt = $pdo->prepare('SELECT DISTINCT ' . $column . ' AS value ' . $scope . ' ORDER BY value');
        $stmt->execute($params);
        $filterOptions[$key] = array_values(array_filter(array_column($stmt->fetchAll(), 'value'), fn($v) => $v !== null && $v !== ''));
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $search = prism_search_clause(['d.original_name', 'd.student_name', 's.protocol_code', 'd.document_type', 'd.uploaded_by', 'd.stage'], $q, $params);
        foreach (STAGE_SEQUENCE as $i => $stage) {
            if (mb_strpos(mb_strtolower(stage_label($stage)), mb_strtolower($q)) !== false) {
                $search .= ' OR d.stage = :labelStage' . $i; $params[':labelStage' . $i] = $stage;
            }
        }
        $scope .= ' AND (' . $search . ')';
    }
    foreach (['review' => 'd.review_status', 'stage' => 'd.stage', 'type' => 'd.document_type', 'course' => 's.course', 'year' => 'SUBSTR(d.uploaded_at,1,4)', 'state' => $stateSql] as $key => $column) {
        if (trim((string)($_GET[$key] ?? '')) !== '') {
            $scope .= " AND ($column) = :$key"; $params[":$key"] = trim((string)$_GET[$key]);
        }
    }
    $orders = ['oldest' => 'd.uploaded_at ASC, d.id ASC', 'name-asc' => 'd.original_name ASC, d.id ASC', 'name-desc' => 'd.original_name DESC, d.id ASC', 'student-asc' => 'd.student_name ASC, d.id ASC'];
    // Presentation grouping only: preserve the distinct underlying workflow states.
    if (($_GET['stateGroup'] ?? '') === 'approved') {
        $scope .= " AND ($stateSql) IN ('Ready for Formal RPMS Submission','Submitted to RPMS')";
    }
    $page = prism_page_query($pdo, 'SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at', $scope, $params, $orders[$_GET['sort'] ?? ''] ?? 'd.uploaded_at DESC, d.id DESC', $_GET);
    $rows = array_map(fn($d) => doc_row($d, $user, $viewerAdviserId), $page['rows']); unset($page['rows']);
    json_out(['ok' => true, 'documents' => $rows, 'counts' => $counts, 'filterOptions' => $filterOptions] + $page);
}

// ---------------------------------------------------------------------
// upload (a re-upload for the same student + stage + type becomes a new version)
// ---------------------------------------------------------------------
function upload_document_file(PDO $pdo, array $user, ?int $viewerAdviserId, array $file): array
{
    $validated = prism_validate_upload($file);
    $ext = $validated['ext'];
    $mime = $validated['mime'];
    // Resolve which student record this upload belongs to.
    $studentDbId = null;
    $studentName = trim((string)($_POST['student'] ?? ''));
    $studentStage = null;
    if ($user['role'] === 'student') {
        $own = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE email = :e AND archived_at IS NULL');
        $own->execute([':e' => $user['email']]);
        $ownRow = $own->fetch();
        if (!$ownRow) {
            throw new DocumentUploadError('Your login is not linked to a student record yet. Contact the RPMS office before uploading documents.', 409);
        }
        $studentDbId = (int)$ownRow['id'];
        $studentName = $ownRow['full_name'];
        $studentStage = $ownRow['stage'];
    } else {
        $requestedStudentId = (int)($_POST['studentDbId'] ?? 0);
        if ($requestedStudentId > 0) {
            $match = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE id = :id AND archived_at IS NULL LIMIT 1');
            $match->execute([':id' => $requestedStudentId]);
        } elseif ($studentName !== '') {
            // Backward-compatible fallback for older clients.
            $match = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE (full_name = :n OR student_id = :n) AND archived_at IS NULL LIMIT 1');
            $match->execute([':n' => $studentName]);
        } else {
            $match = null;
        }
        $matchRow = $match ? $match->fetch() : false;
        if ($matchRow) {
            $studentDbId = (int)$matchRow['id'];
            $studentName = $matchRow['full_name'];
            $studentStage = $matchRow['stage'];
        }
    }

    if ($user['role'] !== 'student' && $studentDbId === null) {
        throw new DocumentUploadError('Select a valid student for this document.', 422);
    }

    if ($user['role'] === 'adviser') {
        $ownsMatch = $studentDbId !== null && $viewerAdviserId !== null && (int)$pdo->query(
            'SELECT COALESCE(adviser_id, 0) FROM students WHERE id = ' . (int)$studentDbId)->fetchColumn() === $viewerAdviserId;
        if (!$ownsMatch) {
            throw new DocumentUploadError('Enter the name or ID of a student who is assigned to you. Advisers can only upload documents for their own students.', 422);
        }
    }

    $documentType = $_POST['documentType'];
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($documentType) > 100 || mb_strlen($notes) > 5000 || mb_strlen(basename((string)$file['name'])) > 255) {
        throw new DocumentUploadError('The document type, notes, or file name is too long. Please shorten it and try again.', 422);
    }
    // Students cannot choose a different workflow stage: uploads always belong to their current IERB stage.
    $stage = $user['role'] === 'student' ? (string)$studentStage : trim((string)($_POST['stage'] ?? ''));
    if ($stage === '') {
        $stage = $studentStage ?: 'Stage 1';
    }
    if (!in_array($stage, STAGE_SEQUENCE, true)) {
        throw new DocumentUploadError('Invalid IERB stage.', 422);
    }
    $revisionOf = trim((string)($_POST['revisionOf'] ?? ''));
    if ($revisionOf !== '') {
        $revision = fetch_doc($pdo, $revisionOf);
        if ($user['role'] !== 'student' || !$revision || (int)$revision['student_id'] !== $studentDbId
            || (int)$revision['is_current'] !== 1 || $revision['document_type'] !== $documentType
            || !in_array(document_workflow_state($revision), [WF_NEEDS_REVISION, WF_RETURNED_REVISION], true)) {
            throw new DocumentUploadError('Select a current document requiring revision from your own documents.', 409);
        }
        // A submitted version may belong to a stage the Student has already advanced past.
        $stage = $revision['stage'];
    }

    $id = bin2hex(random_bytes(12));
    $stored = $id . '.' . $ext;
    $target = DOCS_DIR . DIRECTORY_SEPARATOR . $stored;
    // Exclusive reservation prevents any destination collision from overwriting a file.
    $reservation = @fopen($target, 'xb');
    if (!$reservation) throw new DocumentUploadError('The file could not be stored. Please try again.', 500);
    fclose($reservation);
    try { $moved = move_uploaded_file($file['tmp_name'], $target); }
    catch (Throwable $e) { $moved = false; }
    if (!$moved) {
        remove_document_file($target, $id, 'failed move');
        throw new DocumentUploadError('The uploaded file could not be stored. Please try again.', 500);
    }
    // Best-effort approval-date detection; never blocks the upload.
    $detectedDate = null;
    $detectedSource = null;
    try {
        $uploadText = extract_document_text($target);
        if ($uploadText !== '') {
            $detection = ai_detect_approval_date($uploadText);
            $detectedDate = $detection['date'];
            $detectedSource = $detection['source'];
        }
    } catch (Throwable $e) {
        // extraction/AI hiccup shouldn't fail the whole upload
    }

    $commitAttempted = false;
    try {
        $pdo->beginTransaction();
        // Lock the student before selecting a version. Two first uploads must also serialize.
        $studentLock = $pdo->prepare('SELECT * FROM students WHERE id = :id FOR UPDATE');
        $studentLock->execute([':id' => $studentDbId]);
        try { current_workflow_student($pdo,(int)$studentDbId,$user); }
        catch (AccountLifecycleForbidden $e) { $error=new DocumentWriteConflict($e->getMessage(),0,$e); $error->status=403; throw $error; }
        catch (AccountLifecycleConflict $e) { throw new DocumentWriteConflict($e->getMessage(),0,$e); }
        $currentStudent = $studentLock->fetch();
        if ($currentStudent) {
            try { purge_require_no_pending($pdo,'student',(int)$currentStudent['id']); }
            catch (AccountLifecycleForbidden $e) { $error=new DocumentWriteConflict($e->getMessage(),0,$e); $error->status=403; throw $error; }
        catch (AccountLifecycleConflict $e) { throw new DocumentWriteConflict($e->getMessage(),0,$e); }
        }
        if (!$currentStudent || !empty($currentStudent['archived_at'])
            || ($user['role'] === 'student' && strcasecmp((string)$currentStudent['email'], (string)$user['email']) !== 0)
            || ($user['role'] === 'adviser' && ((int)$currentStudent['adviser_id'] !== $viewerAdviserId || !empty($currentStudent['archived_at'])))) {
            throw new DocumentWriteConflict('The student record changed while the file was uploading. Refresh and select the student again.');
        }
        $studentName = $currentStudent['full_name'];
        if ($user['role'] === 'student') {
            // Metadata is taken from the locked record, never from form controls.
            $notes = 'Research title: ' . (string)($currentStudent['research_title'] ?? '')
                . ' | Group: ' . (string)($currentStudent['research_group'] ?? '')
                . ($notes !== '' ? ' | Reviewer note: ' . $notes : '');
        }
        if ($user['role'] === 'student' && (string)$currentStudent['stage'] !== (string)$studentStage) {
            throw new DocumentWriteConflict('Your IERB stage changed while the file was uploading. Refresh and upload the document for your current stage.');
        }
        try { $prev = document_current_version($pdo, (int)$studentDbId, $stage, $documentType); }
        catch (DocumentWorkflowError $e) { throw new DocumentWriteConflict($e->getMessage(),0,$e); }
        if ($prev) $prev = document_with_return($pdo, $prev, true);
        if ($revisionOf !== '' && (!$prev || $prev['id'] !== $revisionOf
            || !in_array(document_workflow_state($prev), [WF_NEEDS_REVISION, WF_RETURNED_REVISION], true))) {
            throw new DocumentWriteConflict('This revision was already replaced or changed. Refresh your documents.');
        }
        if ($prev && document_is_locked($prev)
            && !($user['role'] === 'student' && $revisionOf === $prev['id']
                && document_workflow_state($prev) === WF_RETURNED_REVISION)) {
            throw new DocumentWriteConflict('This document was already submitted to RPMS on '
                . date('M j, Y', strtotime($prev['rpms_submitted_at']))
                . ' and is locked. Contact RPMS if it needs to be replaced.');
        }
        $versionNo = $prev ? ((int)$prev['version_no'] + 1) : 1;
        $pdo->prepare('INSERT INTO documents (id, student_id, student_name, uploaded_by, uploaded_by_role,
                original_name, stored_name, mime, size, document_type, stage, notes, review_status,
                detected_approval_date, approval_date_source, version_no, is_current, supersedes_id)
            VALUES (:id,:sid,:sname,:by,:role,:orig,:stored,:mime,:size,:type,:stage,:notes,:review,
                :adate,:asrc,:ver,1,:prev)')
            ->execute([
                ':id' => $id, ':sid' => $studentDbId, ':sname' => $studentName ?: 'Unassigned',
                ':by' => $user['full_name'], ':role' => $user['role'], ':orig' => basename($file['name']),
                ':stored' => $stored, ':mime' => $mime, ':size' => (int)$file['size'],
                ':type' => $documentType, ':stage' => $stage,
                ':notes' => $notes, ':review' => 'Submitted',
                ':adate' => $detectedDate, ':asrc' => $detectedSource,
                ':ver' => $versionNo, ':prev' => $prev ? $prev['id'] : null,
            ]);
        if ($prev) {
            $pdo->prepare('UPDATE documents SET is_current = 0 WHERE id = :id')->execute([':id' => $prev['id']]);
        }
        if ($studentDbId) {
            $pdo->prepare('UPDATE students SET last_submission_date = CURDATE() WHERE id = :id')->execute([':id' => $studentDbId]);
        }
        audit_log($user, 'document_uploaded', [
            'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $studentDbId,
            'after' => "v$versionNo", 'details' => '"' . basename($file['name']) . '" v' . $versionNo . " ($documentType, $stage)"
                . ($prev ? ' replaces v' . $prev['version_no'] : ''),
        ]);
        $origName = basename($file['name']);
        $versionText = $versionNo > 1 ? " (version $versionNo)" : '';
        $notificationIds = $studentDbId ? workflow_queue_notices($pdo,$user,$studentDbId,
            $user['role']==='student' ? ['subject'=>'PRISM Submission Confirmation',
                'message'=>"Your document \"$origName\"$versionText was received and is pending adviser review.",
                'type'=>'Submission Confirmation'] : null,
            ['subject'=>'New document to review',
                'message'=>"$studentName uploaded \"$origName\"$versionText ($documentType, " . stage_label($stage) . ').',
                'type'=>'Review Needed']) : [];
        $commitAttempted = true;
        if (!$pdo->commit()) throw new RuntimeException('Upload commit could not be confirmed.');
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        remove_document_file($target, $id, 'upload conflict');
        throw new DocumentUploadError($e->getMessage(), $e->status);
    } catch (Throwable $e) {
        $rollbackConfirmed = false;
        if ($pdo->inTransaction()) {
            try { $rollbackConfirmed = $pdo->rollBack(); } catch (Throwable $ignored) {}
        }
        // A lost COMMIT acknowledgement may already have persisted this file's row.
        // Preserve private bytes for reconciliation instead of breaking a durable document.
        if (!$commitAttempted && $rollbackConfirmed) remove_document_file($target, $id, 'failed upload');
        else log_api_error('document_upload_reconcile', 'Unconfirmed upload outcome; retained private file for document ' . $id . '.');
        log_api_error('document_upload', $e->getMessage());
        throw new DocumentUploadError('The upload outcome could not be confirmed. Refresh your documents before retrying.', 500);
    }

    document_workflow_deliver($pdo,$notificationIds);
    // A read-back failure after commit must not report the durable upload as failed.
    $uploadedDocument = null;
    try { $uploadedDocument = doc_row(fetch_doc($pdo, $id), $user, $viewerAdviserId); }
    catch (Throwable $e) { log_api_error('document_upload_readback', 'Committed document ' . $id . ' needs refresh.'); }
    return [
        'ok' => true,
        'message' => "\"$origName\" was uploaded" . ($versionNo > 1 ? " as version $versionNo. The earlier version was kept." : '.')
            . ' It is now pending adviser review.',
        'versionNo' => $versionNo,
        'replacedPrevious' => (bool)$prev,
        'notificationIntent' => $notificationIds ? 'recorded' : 'not_required',
        'document' => $uploadedDocument,
    ];
}

if ($action === 'upload') {
    require_session_generation(true);
    try {
        foreach (['documentType', 'notes', 'student', 'stage', 'studentDbId', 'requestId', 'revisionOf'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new DocumentUploadError('The upload request is malformed.', 400);
        }
        // Historical labels are permitted only through the existing owned/current Student revision validation.
        if (!in_array($_POST['documentType'] ?? null, prism_document_types(), true)
            && !($user['role'] === 'student' && trim((string)($_POST['revisionOf'] ?? '')) !== '')) {
            throw new DocumentUploadError('Choose a document type from the PRISM document catalog.');
        }
        $files = prism_upload_files($_FILES['document'] ?? null);
        if (!empty($_POST['revisionOf']) && count($files) !== 1) throw new DocumentUploadError('Upload one new file for this revision.', 422);
        $expected = $_POST['fileCount'] ?? null;
        if ($expected !== null && (!is_string($expected) || !ctype_digit($expected) || (int)$expected !== count($files))) {
            throw new DocumentUploadError('The server did not receive the complete file selection. Select fewer files or smaller files and try again.', 400);
        }
        $requestId = $_POST['requestId'] ?? '';
        if ($requestId !== '' && !preg_match('/\A[a-zA-Z0-9-]{16,80}\z/', $requestId)) throw new DocumentUploadError('The upload request is malformed.', 400);
        $fingerprint = $requestId !== '' ? hash('sha256', serialize([$_POST, array_map(static fn($f) => [$f['name'], $f['size'], $f['error'],
            is_uploaded_file($f['tmp_name']) ? hash_file('sha256', $f['tmp_name']) : null], $files)])) : '';
        $receiptKey = prism_session_generation() . ':' . $requestId;
        $receipt = $requestId !== '' ? ($_SESSION['upload_receipts'][$receiptKey] ?? null) : null;
        if ($receipt) {
            if (!hash_equals($receipt['fingerprint'], $fingerprint)) throw new DocumentUploadError('This upload request was already used for a different selection.', 409);
            json_out($receipt['response'], $receipt['status']);
        }
        $results = [];
        foreach ($files as $file) {
            try {
                $result = upload_document_file($pdo, $user, $viewerAdviserId, $file);
                $results[] = ['name' => $file['name']] + $result;
            } catch (DocumentUploadError $e) {
                $results[] = ['name' => basename(str_replace('\\', '/', $file['name'])), 'ok' => false, 'message' => $e->getMessage(), 'status' => $e->status];
            } catch (Throwable $e) {
                log_api_error('document_upload', 'Upload processing failed (' . get_class($e) . ').');
                $results[] = ['name' => basename(str_replace('\\', '/', $file['name'])), 'ok' => false, 'message' => 'The document could not be saved. Please try again.', 'status' => 500];
            }
        }
        $uploaded = count(array_filter($results, static fn($r) => $r['ok']));
        $count = count($results);
        $response = ['ok' => $uploaded > 0, 'outcome' => $uploaded === $count ? 'complete_success' : ($uploaded ? 'partial_success' : 'complete_failure'),
            'message' => "$uploaded of $count files uploaded successfully.", 'uploaded' => $uploaded, 'failed' => $count - $uploaded, 'results' => $results];
        // Preserve scalar single-file response fields for existing clients.
        if ($count === 1) $response += array_diff_key($results[0], ['name' => true, 'ok' => true, 'message' => true]);
        $status = $uploaded ? 200 : ($count === 1 ? $results[0]['status'] : 422);
        if ($requestId !== '') {
            $_SESSION['upload_receipts'][$receiptKey] = ['fingerprint' => $fingerprint, 'response' => $response, 'status' => $status];
            // Bounded session bookkeeping, not an institutional file-count restriction.
            while (count($_SESSION['upload_receipts']) > 16) array_shift($_SESSION['upload_receipts']);
        }
        json_out($response, $status);
    } catch (DocumentUploadError $e) {
        json_out(['ok' => false, 'message' => $e->getMessage()], $e->status);
    }
}

// ---------------------------------------------------------------------
// Everything below works on one existing document
// ---------------------------------------------------------------------
if (in_array($action, ['summarize', 'summarize_preview', 'summarize_cancel'], true)) {
    header('Cache-Control: no-store');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) json_out(['ok' => false, 'message' => 'Summary request is too large.'], 413);
    $payload = json_body();
    $id = $payload['id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)) {
        audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'details' => 'source=none; category=invalid_document_id; partial=unknown']);
        json_out(['ok' => false, 'message' => 'Provide a valid document ID.'], 422);
    }
    if ($action === 'summarize_cancel') {
        $token = $payload['previewToken'] ?? null;
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) json_out(['ok' => false, 'message' => 'Provide a valid preview reference.'], 422);
        revoke_document_summary_previews($id, $user, $token);
        json_out(['ok' => true]);
    }
    // Reserve enough memory to return a safe response if a hostile PDF exhausts PHP's budget.
    $summaryReserve = str_repeat('x', 262144); $summaryActive = true;
    register_shutdown_function(static function () use (&$summaryReserve, &$summaryActive): void {
        $last = error_get_last();
        if (!$summaryActive || !$last || !in_array($last['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        $summaryReserve = null;
        if (!headers_sent()) { http_response_code(422); header('Content-Type: application/json'); }
        echo json_encode(['ok' => false, 'message' => 'The document exceeded safe processing limits. Try a smaller text-based copy.']);
    });
    $summaryLock = null; $summaryPartial = null; $summarySourceUsed = 'none';
    try {
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, ['id', 'mode', 'previewToken', 'confirmed'], true)) {
                throw new DocumentSummaryError('invalid_request', 'Only server-prepared document excerpts may be summarized.');
            }
        }
        $mode = $payload['mode'] ?? ($action === 'summarize_preview' ? 'external' : 'local');
        if (!is_string($mode) || !in_array($mode, ['local', 'external'], true)) throw new DocumentSummaryError('invalid_request', 'Choose a valid summary mode.');
        if ($action === 'summarize' && $mode === 'local') revoke_document_summary_previews($id, $user);
        if ($action === 'summarize') $summaryLock = document_summary_lock($id);
        $doc = fetch_doc($pdo, $id);
        if (!$doc) throw new DocumentSummaryError('document_missing', 'Document not found. It may have been deleted.', 404);
        if (!empty($doc['archived_at'])) throw new DocumentSummaryError('document_inactive', 'This document is unavailable for current processing.', 409);
        begin_document_write($pdo,$doc);
        $pdo->commit();
        $path = summary_stored_document_path($doc);
        $fileHash = document_summary_file_hash($path);
        $extraction = extract_document_text_for_summary($path);
        $identifiers = document_summary_identifiers($pdo, $doc, $user);
        $summaryContext = ['id' => $id, 'version' => (int)$doc['version_no'], 'sha256' => $fileHash];
        $prepared = prepare_document_summary($extraction, $identifiers, $summaryContext);
        $summaryPartial = $mode === 'local' ? !empty($extraction['partial']) : $prepared['partial'];
        // Revalidate before issuing a preview or disclosing any text externally.
        begin_document_write($pdo, $doc);
        if (fetch_doc($pdo, $id, true) !== $doc || summary_stored_document_path($doc) !== $path
            || !hash_equals($fileHash, document_summary_file_hash($path))) {
            throw new DocumentWriteConflict('This document changed. Refresh and review a fresh preview.');
        }
        $pdo->commit();
        $binding = document_summary_binding($doc, $fileHash, $prepared, $user);
        if ($action === 'summarize_preview') {
            $token = $prepared['eligible'] ? issue_document_summary_preview($binding, $id, $user) : null;
            $summaryActive = false;
            json_out(['ok' => true, 'eligible' => $prepared['eligible'], 'reason' => $prepared['reason'],
                'notice' => document_summary_notice($prepared['reason']), 'partial' => $prepared['partial'],
                'privacyCategory' => $prepared['privacyCategory'], 'privacyReason' => $prepared['privacyReason'],
                'configurationReason' => $prepared['configurationReason'], 'requiresIdentifierReview' => $prepared['requiresIdentifierReview'],
                'excerpt' => $prepared['eligible'] ? $prepared['excerpt'] : null, 'previewToken' => $token,
                'policyReference' => $prepared['policy']['reference'], 'authorizationMode' => $prepared['policy']['authorizationMode'],
                'expiresIn' => $token ? 300 : 0]);
        }
        $externalConfirmed = false;
        if ($mode === 'external') {
            if (!$prepared['eligible']) throw new DocumentSummaryError($prepared['reason'], document_summary_notice($prepared['reason']), 422);
            if (($payload['confirmed'] ?? false) !== true || !is_string($payload['previewToken'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/', $payload['previewToken'])) {
                throw new DocumentSummaryError('confirmation_required', 'Review a fresh excerpt and confirm document authorization before sending.', 409);
            }
            consume_document_summary_preview($payload['previewToken'], $binding, $id, $user);
            if (!$prepared['eligible']) throw new DocumentSummaryError('privacy_changed', 'The document is no longer eligible. Review a fresh preview.', 409);
            $externalConfirmed = true;
            begin_document_write($pdo, $doc);
            if (fetch_doc($pdo, $id, true) !== $doc || !hash_equals($fileHash, document_summary_file_hash($path))) {
                throw new DocumentWriteConflict('This document changed. Review a fresh preview.');
            }
            document_workflow_audit($pdo, $user, 'document_summary_authorized', ['entity_type' => 'document', 'entity_id' => $id,
                'student_id' => $doc['student_id'], 'details' => 'category=admin_attested; authorization=' . $prepared['policy']['authorizationMode'] . '; policy_sha256='
                    . hash('sha256', $prepared['policy']['reference']) . '; privacy_class=' . $prepared['privacyCategory'] . '; partial=' . (int)$prepared['partial']]);
            $pdo->commit();
        }
        // Commit consumed preview state before releasing the session lock. Replays cannot retry disclosure.
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new DocumentSummaryError('session_storage', 'Authorization state could not be saved. Sign in again before retrying.', 503);
        }
        $result = generate_document_summary($extraction, $identifiers, $externalConfirmed,
            $mode === 'local', $summaryContext);
        $summarySourceUsed = $result['source'];
        // No external service runs under a database lock. Recheck every row field and the file before saving.
        begin_document_write($pdo, $doc);
        $current = fetch_doc($pdo, $id, true);
        if ($current !== $doc || summary_stored_document_path($doc) !== $path
            || !hash_equals($fileHash, document_summary_file_hash($path))) {
            throw new DocumentWriteConflict('This document changed. Refresh and try again.');
        }
        $pdo->prepare('UPDATE documents SET ai_summary = :summary WHERE id = :id')->execute([':summary' => document_summary_pack($result), ':id' => $id]);
        document_workflow_audit($pdo, $user, 'document_summarized', ['entity_type' => 'document', 'entity_id' => $id,
            'student_id' => $doc['student_id'], 'details' => 'source=' . $result['source'] . '; category=' . $result['reason'] . '; partial=' . (int)$result['partial']]);
        $pdo->commit();
        $summaryActive = false;
        if (is_resource($summaryLock)) { flock($summaryLock, LOCK_UN); fclose($summaryLock); $summaryLock = null; }
        json_out(['ok' => true] + $result);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $reason = $error instanceof DocumentSummaryError ? $error->reasonCode : ($error instanceof DocumentWriteConflict ? 'document_changed' : 'summary_failed');
        audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'entity_id' => $id,
            'details' => 'source=' . $summarySourceUsed . '; category=' . $reason . '; mode=' . (in_array($mode ?? null, ['external','local'], true) ? $mode : 'unknown') . '; partial=' . ($summaryPartial === null ? 'unknown' : (int)$summaryPartial)]);
        $summaryActive = false;
        if (is_resource($summaryLock)) { flock($summaryLock, LOCK_UN); fclose($summaryLock); }
        json_out(['ok' => false, 'code' => $reason, 'selectedMode' => in_array($mode ?? null, ['external','local'], true) ? $mode : null,
            'canGenerateLocal' => ($mode ?? '') === 'external' && isset($extraction) && !in_array($reason, ['no_useful_text','document_changed','document_missing'], true),
            'message' => $error instanceof DocumentSummaryError || $error instanceof DocumentWriteConflict
            ? $error->getMessage() : 'The summary could not be saved. Please try again.'],
            $error instanceof DocumentSummaryError ? $error->httpStatus : ($error instanceof DocumentWriteConflict ? $error->status : 503));
    }
}
$workflowAction=in_array($action,['review','comment','return_for_revision','submit_to_rpms','override_review'],true);
try { $payload = json_body($workflowAction); }
catch (AccountLifecycleBadRequest $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],400); }
if (in_array($action, ['review','comment','return_for_revision','submit_to_rpms','override_review'], true)) {
    foreach (['id','status','remarks','reason','expectedStatus'] as $field) {
        if (isset($payload[$field]) && !is_string($payload[$field])) json_out(['ok'=>false,'message'=>'Malformed workflow request.'],422);
    }
    if (isset($payload['versionNo']) && (!is_int($payload['versionNo']) || $payload['versionNo'] < 1)) json_out(['ok'=>false,'message'=>'Invalid document version.'],422);
    // Approval identity, timestamps, ownership and override flags are always server derived.
    foreach (['review_status','reviewed_by','reviewed_at','rpms_submitted_at','rpms_submitted_by','student_id','admin_override','override_by','override_at'] as $field) {
        if (array_key_exists($field,$payload)) json_out(['ok'=>false,'message'=>'Authoritative workflow fields cannot be supplied.'],422);
    }
}
if ($action === 'submit_to_rpms' && $user['role'] !== 'admin') {
    json_out(['ok'=>false,'message'=>'Adviser approval automatically submits the eligible current version to RPMS. A separate Student formal submission is not permitted.'],403);
}
$id = $_GET['id'] ?? $payload['id'] ?? '';
if (!is_string($id) || $id==='' || strlen($id)>128) json_out(['ok'=>false,'message'=>'Provide a valid record reference.'],422);
$doc = fetch_doc($pdo, (string)$id);
if (!$doc) {
    json_out(['ok' => false, 'message' => 'Document not found. It may have been deleted.'], 404);
}

// Students may only touch their own documents (including downloading/viewing the file itself).
if ($user['role'] === 'student') {
    $own = $pdo->prepare('SELECT email FROM students WHERE id = :id');
    $own->execute([':id' => $doc['student_id']]);
    if (strcasecmp((string)$own->fetchColumn(), (string)$user['email']) !== 0) {
        json_out(['ok' => false, 'message' => 'Not authorized.'], 403);
    }
}

if ($user['role'] === 'adviser' && ($viewerAdviserId === null || (int)($doc['adviser_id'] ?? 0) !== $viewerAdviserId || !empty($doc['archived_at']))) {
    json_out(['ok' => false, 'message' => 'This document belongs to a student assigned to another adviser.'], 403);
}

$path = DOCS_DIR . DIRECTORY_SEPARATOR . basename($doc['stored_name']);
$docName = $doc['original_name'];
$isCurrent = (int)($doc['is_current'] ?? 1) === 1;
$locked = document_is_locked($doc);

if (in_array($action,['review','comment','return_for_revision','submit_to_rpms','override_review'],true)) {
    require_session_generation(true);
    if (isset($_GET['id'], $payload['id']) && $_GET['id'] !== $payload['id']) json_out(['ok'=>false,'message'=>'Conflicting document identity.'],422);
    $operation=match($action) { 'return_for_revision'=>'return', 'submit_to_rpms'=>'submit', 'override_review'=>'override', default=>$action };
    try {
        $result=document_workflow_change($pdo,$user,(string)$id,$operation,$payload['status']??'',
            in_array($operation,['return','submit','override'],true)?($payload['reason']??''):($payload['remarks']??''),
            $payload['versionNo']??null, $payload['expectedStatus']??null);
        $ids=$result['notificationIds']; unset($result['notificationIds']);
        document_workflow_deliver($pdo,$ids);
        json_out($result);
    } catch (DocumentWorkflowError $e) {
        json_out(['ok'=>false,'message'=>$e->getMessage()],$e->status);
    } catch (AccountLifecycleConflict $e) {
        json_out(['ok'=>false,'message'=>'Account workflow changed. Refresh before continuing.'],409);
    } catch (Throwable $e) {
        log_api_error('document_workflow','The document transition could not be persisted.');
        json_out(['ok'=>false,'message'=>'Refresh to check the current state before retrying. Please refresh and try again.'],500);
    }
}

if ($action === 'file') {
    if (!is_file($path)) {
        json_out(['ok' => false, 'message' => 'File missing from storage.'], 404);
    }
    // Only formats a browser can display without running any script are shown inline. Everything
    // else (including a .txt whose content is really HTML, which finfo reports as text/html) is
    // forced to download as an opaque binary, so an uploaded file can never run as a page on this origin.
    $inlineSafe = ['application/pdf', 'image/png', 'image/jpeg'];
    $safeInline = in_array($doc['mime'], $inlineSafe, true);
    header_remove('Content-Type');
    header('Content-Type: ' . ($safeInline ? $doc['mime'] : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ((($_GET['download'] ?? '') === '1' || !$safeInline) ? 'attachment' : 'inline')
        . '; filename="' . str_replace(["\r", "\n", '"'], '', $doc['original_name']) . '"');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------
// versions: every version of this document (same student + stage + type), newest first
// ---------------------------------------------------------------------
if ($action === 'versions') {
    if (!$doc['student_id']) {
        $rows = [$doc];
        $page = ['total' => 1, 'page' => 1, 'limit' => prism_page_size($_GET)];
    } else {
        $page = prism_page_query($pdo, 'SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at',
            'FROM documents d LEFT JOIN students s ON s.id = d.student_id
            WHERE d.student_id = :sid AND d.stage = :stage AND d.document_type = :type',
            [':sid' => $doc['student_id'], ':stage' => $doc['stage'], ':type' => $doc['document_type']],
            'd.version_no DESC, d.uploaded_at DESC, d.id DESC', $_GET);
        $rows = $page['rows'];
    }
    json_out(['ok' => true, 'versions' => array_map(fn($d) => doc_row($d, $user, $viewerAdviserId), $rows),
        'total' => $page['total'], 'page' => $page['page'], 'limit' => $page['limit']]);
}

// ---------------------------------------------------------------------
// delete (admin/adviser). Formally submitted documents: admin only, with a reason.
// ---------------------------------------------------------------------
if ($action === 'delete') {
    api_require_login(['admin', 'adviser']);
    $reason = trim((string)($payload['reason'] ?? ''));
    if (!can_review_doc($user, $viewerAdviserId, $doc)) {
        json_out(['ok' => false, 'message' => 'This student is assigned to another adviser. Only the assigned adviser or an RPMS administrator can delete this document.'], 403);
    }
    if ($locked) {
        if ($user['role'] !== 'admin') {
            json_out(['ok' => false, 'message' => 'Documents formally submitted to RPMS can only be deleted by an RPMS administrator.'], 403);
        }
        if (!override_reason_valid($reason)) {
            json_out(['ok' => false, 'requiresReason' => true,
                'message' => 'This document was formally submitted to RPMS. ' . override_reason_message()], 422);
        }
    }
    $restoredId = null;
    try {
        begin_document_write($pdo, $doc);
        if (!empty($doc['supersedes_id'])) {
            $predecessor=fetch_doc($pdo,(string)$doc['supersedes_id'],true);
            if (!$predecessor || (int)$predecessor['student_id']!==(int)$doc['student_id']
                || $predecessor['stage']!==$doc['stage'] || $predecessor['document_type']!==$doc['document_type']
                || ($isCurrent && (int)$predecessor['is_current']!==0)) {
                throw new DocumentWriteConflict('The document chain is inconsistent. Contact RPMS for review.');
            }
        }
        $links=$pdo->prepare('SELECT student_id,stage,document_type FROM documents WHERE supersedes_id=? FOR UPDATE');
        $links->execute([$id]);
        foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $link) {
            if ((int)$link['student_id']!==(int)$doc['student_id'] || $link['stage']!==$doc['stage'] || $link['document_type']!==$doc['document_type']) {
                throw new DocumentWriteConflict('The document chain is inconsistent. Contact RPMS for review.');
            }
        }
        if ($locked) {
            // Audit while ownership is available; deletion and version restoration share this transaction.
            audit_override_atomic($pdo, $user, 'document_deleted', [
                'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
                'before' => document_workflow_state($doc), 'reason' => $reason, 'override' => true,
                'details' => "\"$docName\" v" . (int)($doc['version_no'] ?? 1) . ' deleted'
                    . ($isCurrent && !empty($doc['supersedes_id']) ? ' (previous version restored)' : ''),
            ]);
        }
    if (!$locked) document_workflow_audit($pdo, $user, 'document_deleted', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => document_workflow_state($doc), 'reason' => $reason !== '' ? $reason : null, 'override' => $locked,
        'details' => "\"$docName\" v" . (int)($doc['version_no'] ?? 1) . ' deleted' . ($restoredId ? ' (previous version restored)' : ''),
    ]);
        $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $id]);
        // Keep the version chain intact: link the next version to this one's predecessor,
        // and if the newest version was deleted, make the previous one current again.
        $pdo->prepare('UPDATE documents SET supersedes_id = :prev WHERE supersedes_id = :id')
            ->execute([':prev' => $doc['supersedes_id'] ?: null, ':id' => $id]);
        if ($isCurrent && !empty($doc['supersedes_id'])) {
            $pdo->prepare('UPDATE documents SET is_current = 1 WHERE id = :id')->execute([':id' => $doc['supersedes_id']]);
            $restoredId = $doc['supersedes_id'];
        }
        $pdo->commit();
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'message' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('document_delete', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The document could not be deleted. Refresh to check the current state before retrying.'], 500);
    }
    remove_document_file($path, (string)$id, 'committed deletion');
    json_out(['ok' => true, 'restoredPrevious' => $restoredId !== null,
        'message' => "Deleted \"$docName\"." . ($restoredId ? ' The previous version is now the current one.' : '')]);
}

// ---------------------------------------------------------------------
// Unknown document action.
json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
