<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/office_container.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
if ($action === 'summarize' && $user['role'] !== 'admin') {
    audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'details' => 'reason=forbidden_role']);
    json_out(['ok' => false, 'message' => 'Only RPMS Administrators may generate document summaries.'], 403);
}
if (in_array($action, ['upload', 'review', 'submit_to_rpms', 'override_review', 'delete', 'summarize'], true)) {
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
    return $row ?: null;
}

class DocumentWriteConflict extends RuntimeException {}

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
    // Use the same lock order as uploads, including when no previous version exists.
    if (!empty($document['student_id'])) {
        $student = $pdo->prepare('SELECT id FROM students WHERE id = :id FOR UPDATE');
        $student->execute([':id' => $document['student_id']]);
        $student->fetchColumn();
    }
    $current = fetch_doc($pdo, (string)$document['id'], true);
    $fields = ['student_id', 'adviser_id', 'archived_at', 'is_current', 'supersedes_id', 'review_status',
        'review_remarks', 'reviewed_by', 'reviewed_at', 'rpms_submitted_at', 'admin_override',
        'override_reason', 'override_by', 'override_at'];
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
    if (!ADVISERS_REVIEW_ONLY_OWN_STUDENTS) {
        return true;
    }
    return $viewerAdviserId !== null && !empty($d['adviser_id']) && (int)$d['adviser_id'] === $viewerAdviserId && empty($d['archived_at']);
}

function doc_row(array $d, array $user, ?int $viewerAdviserId): array
{
    $state = document_workflow_state($d);
    $locked = document_is_locked($d);
    $isCurrent = !isset($d['is_current']) || (int)$d['is_current'] === 1;
    $role = $user['role'];

    return [
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
        'aiSummary' => $role === 'admin' ? $d['ai_summary'] : null,

        // Workflow + record integrity
        'workflowState' => $state,
        'versionNo' => (int)($d['version_no'] ?? 1),
        'isCurrent' => $isCurrent,
        'supersedesId' => $d['supersedes_id'] ?? null,
        'locked' => $locked,
        'rpmsSubmittedAt' => $d['rpms_submitted_at'] ?? null,
        'rpmsSubmittedBy' => $d['rpms_submitted_by'] ?? null,
        'adminOverride' => !empty($d['admin_override']),
        'overrideReason' => $d['override_reason'] ?? null,
        'overrideBy' => $d['override_by'] ?? null,
        'overrideAt' => $d['override_at'] ?? null,

        // What the signed-in user may do with this row (the UI just reads these flags).
        'actions' => [
            'summarize' => $role === 'admin',
            'review' => $isCurrent && !$locked && can_review_doc($user, $viewerAdviserId, $d),
            'submitToRpms' => empty($d['archived_at']) && $isCurrent && !$locked && $state === WF_READY_FOR_RPMS && in_array($role, ['student', 'admin'], true),
            'forceSubmitToRpms' => empty($d['archived_at']) && $isCurrent && !$locked && $role === 'admin' && $state !== WF_READY_FOR_RPMS,
            'override' => $isCurrent && !$locked && $role === 'admin',
            'delete' => in_array($role, ['admin', 'adviser'], true) && (!$locked || $role === 'admin'),
        ],
    ];
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
    $stateSql = "CASE WHEN d.is_current = 0 THEN 'Superseded' WHEN d.review_status = 'Approved' THEN CASE WHEN d.rpms_submitted_at IS NOT NULL AND CAST(d.rpms_submitted_at AS CHAR) <> '' THEN 'Submitted to RPMS' ELSE 'Ready for Formal RPMS Submission' END WHEN d.review_status IN ('Denied', 'Resubmission Requested') THEN 'Needs Revision' ELSE 'Pending Adviser Review' END";
    if (($_GET['actionable'] ?? '') === '1') {
        $scope .= " AND d.is_current = 1 AND ($stateSql) IN ('Needs Revision','Ready for Formal RPMS Submission')";
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
    $page = prism_page_query($pdo, 'SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at', $scope, $params, $orders[$_GET['sort'] ?? ''] ?? 'd.uploaded_at DESC, d.id DESC', $_GET);
    $rows = array_map(fn($d) => doc_row($d, $user, $viewerAdviserId), $page['rows']); unset($page['rows']);
    json_out(['ok' => true, 'documents' => $rows, 'counts' => $counts, 'filterOptions' => $filterOptions] + $page);
}

// ---------------------------------------------------------------------
// upload (a re-upload for the same student + stage + type becomes a new version)
// ---------------------------------------------------------------------
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document'])) {
        json_out(['ok' => false, 'message' => 'No document was provided. Choose a file and try again.'], 400);
    }
    $file = $_FILES['document'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'message' => 'The upload did not complete. Please try again.'], 400);
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        json_out(['ok' => false, 'message' => 'Files must be 20 MB or smaller.'], 413);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed, true)) {
        json_out(['ok' => false, 'message' => 'This file type is not allowed. Use PDF, Word, text, RTF, ODT, PNG or JPG.'], 415);
    }

    // Resolve which student record this upload belongs to.
    $studentDbId = null;
    $studentName = trim((string)($_POST['student'] ?? ''));
    $studentStage = null;
    if ($user['role'] === 'student') {
        $own = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE email = :e AND archived_at IS NULL');
        $own->execute([':e' => $user['email']]);
        $ownRow = $own->fetch();
        if (!$ownRow) {
            json_out(['ok' => false, 'message' => 'Your login is not linked to a student record yet. Contact the RPMS office before uploading documents.'], 409);
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
        json_out(['ok' => false, 'message' => 'Select a valid student for this document.'], 422);
    }

    if ($user['role'] === 'adviser') {
        $ownsMatch = $studentDbId !== null && $viewerAdviserId !== null && (int)$pdo->query(
            'SELECT COALESCE(adviser_id, 0) FROM students WHERE id = ' . (int)$studentDbId)->fetchColumn() === $viewerAdviserId;
        if (!$ownsMatch) {
            json_out(['ok' => false, 'message' => 'Enter the name or ID of a student who is assigned to you. Advisers can only upload documents for their own students.'], 422);
        }
    }

    $documentType = trim((string)($_POST['documentType'] ?? '')) ?: 'Other';
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($documentType) > 100 || mb_strlen($notes) > 5000 || mb_strlen(basename((string)$file['name'])) > 255) {
        json_out(['ok' => false, 'message' => 'The document type, notes, or file name is too long. Please shorten it and try again.'], 422);
    }
    // Students cannot choose a different workflow stage: uploads always belong to their current IERB stage.
    $stage = $user['role'] === 'student' ? (string)$studentStage : trim((string)($_POST['stage'] ?? ''));
    if ($stage === '') {
        $stage = $studentStage ?: 'Stage 1';
    }
    if (!in_array($stage, STAGE_SEQUENCE, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }

    $id = bin2hex(random_bytes(12));
    $stored = $id . '.' . $ext;
    $target = DOCS_DIR . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        json_out(['ok' => false, 'message' => 'The uploaded file could not be stored. Please try again.'], 500);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream';

    // Extension alone is not enough. Reject clear extension/content mismatches before the file
    // enters document processing or storage history.
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
        'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream'],
    ];
    if (isset($allowedMimes[$ext]) && !in_array($mime, $allowedMimes[$ext], true)) {
        remove_document_file($target, $id, 'rejected upload');
        json_out(['ok' => false, 'message' => 'The uploaded file content does not match its file extension. Please upload the original document without renaming its extension.'], 415);
    }

    if (!office_container_is_valid($target, $ext)) {
        remove_document_file($target, $id, 'invalid Office container');
        json_out(['ok' => false, 'message' => 'The Office document container is invalid or exceeds safe archive limits. Upload the original document.'], 415);
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

    try {
        $pdo->beginTransaction();
        // Lock the student before selecting a version. Two first uploads must also serialize.
        $studentLock = $pdo->prepare('SELECT id, full_name, stage, adviser_id, email, research_title, research_group, archived_at FROM students WHERE id = :id FOR UPDATE');
        $studentLock->execute([':id' => $studentDbId]);
        $currentStudent = $studentLock->fetch();
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
        $p = $pdo->prepare('SELECT * FROM documents
            WHERE student_id = :sid AND stage = :stage AND document_type = :type AND is_current = 1
            ORDER BY version_no DESC, uploaded_at DESC LIMIT 1 FOR UPDATE');
        $p->execute([':sid' => $studentDbId, ':stage' => $stage, ':type' => $documentType]);
        $prev = $p->fetch() ?: null;
        if ($prev && $user['role'] === 'student' && document_is_locked($prev)) {
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
        $pdo->commit();
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        remove_document_file($target, $id, 'upload conflict');
        json_out(['ok' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        remove_document_file($target, $id, 'failed upload');
        log_api_error('document_upload', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The document could not be saved. Please try again.'], 500);
    }

    $origName = basename($file['name']);
    $versionText = $versionNo > 1 ? " (version $versionNo)" : '';

    // Confirm receipt to the submitting student.
    if ($user['role'] === 'student' && $studentDbId) {
        $body = "Hello {$user['full_name']},\n\nYour document \"$origName\"$versionText has been received by the RPMS office."
            . "\nStatus: Pending Adviser Review. You will be notified when your adviser has reviewed it."
            . ($prev ? "\nYour earlier version was kept in the version history." : '')
            . "\n\n- PRISM";
        notify_student($pdo, $studentDbId, 'PRISM Submission Confirmation', $body,
            "Your document \"$origName\"$versionText was received and is pending adviser review.",
            'Submission Confirmation', 'System');
    }
    // Tell the assigned adviser there is something to review.
    if ($studentDbId) {
        notify_adviser_of_student($pdo, $studentDbId, 'New document to review',
            "$studentName uploaded \"$origName\"$versionText ($documentType, " . stage_label($stage) . ').',
            'Review Needed', $user['full_name']);
    }

    audit_log($user, 'document_uploaded', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $studentDbId,
        'after' => "v$versionNo", 'details' => "\"$origName\" v$versionNo ($documentType, $stage)"
            . ($prev ? ' replaces v' . $prev['version_no'] : ''),
    ]);

    json_out([
        'ok' => true,
        'message' => "\"$origName\" was uploaded" . ($versionNo > 1 ? " as version $versionNo. The earlier version was kept." : '.')
            . ' It is now pending adviser review.',
        'versionNo' => $versionNo,
        'replacedPrevious' => (bool)$prev,
        'document' => doc_row(fetch_doc($pdo, $id), $user, $viewerAdviserId),
    ]);
}

// ---------------------------------------------------------------------
// Everything below works on one existing document
// ---------------------------------------------------------------------
if ($action === 'summarize') {
    require_once __DIR__ . '/includes/document_summary.php';
    $payload = json_body();
    $id = $payload['id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)) {
        audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'details' => 'reason=invalid_document_id']);
        json_out(['ok' => false, 'message' => 'Provide a valid document ID.'], 422);
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
    try {
        $doc = fetch_doc($pdo, $id);
        if (!$doc) throw new DocumentSummaryError('document_missing', 'Document not found. It may have been deleted.', 404);
        $path = summary_stored_document_path($doc);
        $extraction = extract_document_text_for_summary($path);
        $result = generate_document_summary($extraction, document_summary_identifiers($pdo, $doc, $user));
        // No external service runs under a database lock. Recheck the document before saving.
        begin_document_write($pdo, $doc);
        $current = fetch_doc($pdo, $id, true);
        if ($current['stored_name'] !== $doc['stored_name'] || $current['version_no'] !== $doc['version_no']) {
            throw new DocumentWriteConflict('This document changed. Refresh and try again.');
        }
        $pdo->prepare('UPDATE documents SET ai_summary = :summary WHERE id = :id')->execute([':summary' => $result['summary'], ':id' => $id]);
        $pdo->commit();
        audit_log($user, 'document_summarized', ['entity_type' => 'document', 'entity_id' => $id,
            'student_id' => $doc['student_id'], 'details' => 'source=' . $result['source'] . '; partial=' . (int)$result['partial']]);
        $summaryActive = false;
        json_out(['ok' => true] + $result);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $reason = $error instanceof DocumentSummaryError ? $error->reasonCode : ($error instanceof DocumentWriteConflict ? 'document_changed' : 'summary_failed');
        audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'entity_id' => $id, 'details' => 'reason=' . $reason]);
        $summaryActive = false;
        json_out(['ok' => false, 'message' => $error instanceof DocumentSummaryError || $error instanceof DocumentWriteConflict
            ? $error->getMessage() : 'The summary could not be saved. Please try again.'],
            $error instanceof DocumentSummaryError ? $error->httpStatus : ($error instanceof DocumentWriteConflict ? 409 : 503));
    }
}
$payload = json_body();
$id = $_GET['id'] ?? $payload['id'] ?? '';
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
    } else {
        $stmt = $pdo->prepare('SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at
            FROM documents d LEFT JOIN students s ON s.id = d.student_id
            WHERE d.student_id = :sid AND d.stage = :stage AND d.document_type = :type
            ORDER BY d.version_no DESC, d.uploaded_at DESC');
        $stmt->execute([':sid' => $doc['student_id'], ':stage' => $doc['stage'], ':type' => $doc['document_type']]);
        $rows = $stmt->fetchAll();
    }
    json_out(['ok' => true, 'versions' => array_map(fn($d) => doc_row($d, $user, $viewerAdviserId), $rows)]);
}

// ---------------------------------------------------------------------
// review (adviser or admin): approve / deny / request resubmission / comment
// ---------------------------------------------------------------------
if ($action === 'review') {
    api_require_login(['admin', 'adviser']);
    $allowedStatuses = ['Under Review', 'Received', 'Verified', 'Resubmission Requested', 'Approved', 'Denied'];
    $status = trim((string)($payload['status'] ?? ''));
    // "Submitted" is the initial status. It isn't something a reviewer can choose, but the comment
    // box sends the current status back with the remark, so accept it when it isn't a change.
    if (!in_array($status, $allowedStatuses, true) && $status !== ($doc['review_status'] ?? null)) {
        json_out(['ok' => false, 'message' => 'Invalid review status.'], 422);
    }
    $remarks = trim((string)($payload['remarks'] ?? ''));
    if (mb_strlen($remarks) > 5000) {
        json_out(['ok' => false, 'message' => 'Reviewer remarks must be 5,000 characters or fewer.'], 422);
    }
    if (in_array($status, ['Denied', 'Resubmission Requested'], true) && $remarks === '') {
        json_out(['ok' => false, 'message' => 'Please add reviewer remarks explaining what the student needs to correct or resubmit.'], 422);
    }

    if (!$isCurrent) {
        json_out(['ok' => false, 'message' => 'This is an older version. Review the latest version instead.'], 409);
    }
    if (!can_review_doc($user, $viewerAdviserId, $doc)) {
        json_out(['ok' => false, 'message' => 'This student is assigned to another adviser. Only the assigned adviser or an RPMS administrator can review this document.'], 403);
    }
    $before = $doc['review_status'];
    $changed = $status !== $before;
    if ($changed && $locked) {
        json_out(['ok' => false, 'message' => 'This document was formally submitted to RPMS on '
            . date('M j, Y', strtotime($doc['rpms_submitted_at']))
            . ' and is locked. If the record must change, an RPMS administrator can use Admin Override.'], 409);
    }
    if (!$changed && $remarks === '') {
        json_out(['ok' => true, 'noChange' => true, 'message' => 'Nothing to save: the status is already ' . $status . '.']);
    }

    $advancedTo = null;
    try {
        begin_document_write($pdo, $doc);
        $pdo->prepare('UPDATE documents SET review_status = :s, review_remarks = :r, reviewed_by = :by, reviewed_at = NOW(),
                admin_override = IF(:changed = 1, 0, admin_override)
            WHERE id = :id')
            ->execute([':s' => $status, ':r' => $remarks, ':by' => $user['full_name'], ':changed' => $changed ? 1 : 0, ':id' => $id]);

        // Legacy behaviour (STAGE_ADVANCE_TRIGGER = 'approval'): advance as soon as the adviser approves.
        if ($changed && $status === 'Approved' && STAGE_ADVANCE_TRIGGER === 'approval' && $doc['student_id']) {
            $advancedTo = advance_stage_for_document($pdo, $doc, $user,
                $doc['detected_approval_date'] ?: date('Y-m-d'), "after \"$docName\" was approved");
        }
        $pdo->commit();
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('document_review', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The review could not be saved. Nothing was changed - please try again.'], 500);
    }

    // Notify the student.
    if ($doc['student_id']) {
        $versionText = (int)($doc['version_no'] ?? 1) > 1 ? ' (version ' . (int)$doc['version_no'] . ')' : '';
        $body = "Hello {student},\n\nYour document \"$docName\"$versionText is now: $status."
            . ($remarks !== '' ? "\nRemarks: $remarks" : '');
        if ($changed && $status === 'Approved') {
            $body .= $advancedTo
                ? "\n\nYour IERB stage has automatically advanced to: " . stage_label($advancedTo) . " ($advancedTo)."
                : "\n\nNext step: sign in to PRISM, open My Documents and click \"Submit to RPMS\" to formally submit this approved document.";
        } elseif ($changed && in_array($status, ['Denied', 'Resubmission Requested'], true)) {
            $body .= "\n\nPlease read the remarks, then upload a corrected version from Document Submission. Your earlier version is kept.";
        }
        $body .= "\n\n- CEU Malolos RPMS / PRISM";
        $srow = $pdo->prepare('SELECT full_name FROM students WHERE id = :id');
        $srow->execute([':id' => $doc['student_id']]);
        $sname = (string)$srow->fetchColumn();
        $inApp = "Document \"$docName\" is now $status."
            . ($changed && $status === 'Approved' && !$advancedTo ? ' You can now submit it to RPMS from My Documents.' : '')
            . ($remarks !== '' ? " Remarks: $remarks" : '');
        notify_student($pdo, (int)$doc['student_id'], 'PRISM Document Status Update', str_replace('{student}', $sname, $body),
            $inApp, 'Status Update', $user['full_name']);
    }

    $auditAction = !$changed ? 'document_commented' : match ($status) {
        'Approved' => 'document_approved',
        'Denied' => 'document_denied',
        'Resubmission Requested' => 'document_resubmission_requested',
        default => 'document_reviewed',
    };
    audit_log($user, $auditAction, [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => $before, 'after' => $status, 'reason' => $remarks,
        'details' => "\"$docName\" " . ($changed ? "$before -> $status" : 'remark added'),
    ]);

    $who = $doc['student_name'] ?: 'The student';
    $message = !$changed ? 'Remark saved and sent to ' . $who . '.'
        : ($status === 'Approved'
            ? "Approved \"$docName\". $who has been notified" . ($advancedTo ? ' and moved to ' . stage_label($advancedTo) . '.' : ' and can now submit it to RPMS.')
            : ($status === 'Denied' || $status === 'Resubmission Requested'
                ? "Marked \"$docName\" as $status. $who has been notified and can upload a corrected version."
                : "Marked \"$docName\" as $status. $who has been notified."));

    $fresh = fetch_doc($pdo, (string)$id);
    json_out(['ok' => true, 'message' => $message, 'stageAdvanced' => $advancedTo !== null,
        'workflowState' => $fresh ? document_workflow_state($fresh) : null]);
}

// ---------------------------------------------------------------------
// submit_to_rpms (student, or admin on the student's behalf)
// ---------------------------------------------------------------------
if ($action === 'submit_to_rpms') {
    api_require_login(['student', 'admin']);
    $reason = trim((string)($payload['reason'] ?? ''));

    if (!empty($doc['archived_at'])) {
        json_out(['ok'=>false, 'message'=>'Current submission is unavailable for an archived student.'], 409);
    }
    if (!$isCurrent) {
        json_out(['ok' => false, 'message' => 'This is an older version. Submit the latest version instead.'], 409);
    }
    if ($locked) {
        json_out(['ok' => false, 'message' => 'This document was already submitted to RPMS on '
            . date('M j, Y g:i A', strtotime($doc['rpms_submitted_at'])) . '.'], 409);
    }
    $state = document_workflow_state($doc);
    $isOverride = false;
    if ($state !== WF_READY_FOR_RPMS) {
        if ($user['role'] !== 'admin') {
            $why = $state === WF_NEEDS_REVISION
                ? 'Your adviser asked for changes. Upload a corrected version first.'
                : 'Your adviser has not approved this document yet. You can submit it to RPMS once it is approved.';
            json_out(['ok' => false, 'message' => $why], 409);
        }
        if (!override_reason_valid($reason)) {
            json_out(['ok' => false, 'requiresReason' => true,
                'message' => 'This document is not approved by the adviser yet. ' . override_reason_message()], 422);
        }
        $isOverride = true;
    }

    $submittedBy = $user['full_name'] . ($user['role'] === 'admin' ? ' (RPMS Admin' . ($isOverride ? ', Admin Override' : '') . ')' : '');
    $advancedTo = null;
    try {
        begin_document_write($pdo, $doc);

        $sql = 'UPDATE documents SET rpms_submitted_at = NOW(), rpms_submitted_by = :by';
        $params = [':by' => $submittedBy, ':id' => $id];
        if ($isOverride) {
            $sql .= ', review_status = "Approved", reviewed_by = :rb, reviewed_at = NOW(),
                admin_override = 1, override_reason = :orsn, override_by = :ob, override_at = NOW()';
            $params[':rb'] = $user['full_name'] . ' (Admin Override)';
            $params[':orsn'] = $reason;
            $params[':ob'] = $user['full_name'];
        }
        $upd = $pdo->prepare($sql . ' WHERE id = :id AND rpms_submitted_at IS NULL AND is_current = 1');
        $upd->execute($params);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            json_out(['ok' => false, 'message' => 'This document was just submitted by someone else. Refresh to see its latest status.'], 409);
        }

        if ($doc['student_id']) {
            $pdo->prepare('UPDATE students SET last_submission_date = CURDATE(), updated_at = NOW() WHERE id = :id')
                ->execute([':id' => $doc['student_id']]);
            $cur = $pdo->prepare('SELECT stage, status FROM students WHERE id = :id');
            $cur->execute([':id' => $doc['student_id']]);
            $curRow = $cur->fetch();
            record_history($pdo, (int)$doc['student_id'], $curRow['stage'] ?? $doc['stage'], $curRow['status'] ?? 'On Track',
                'Formally submitted to RPMS: "' . $docName . '" (' . $doc['document_type'] . ', ' . $doc['stage'] . ')'
                    . ($isOverride ? '. ADMIN OVERRIDE - reason: ' . $reason : '') . '.',
                date('Y-m-d'), $submittedBy);

            if (STAGE_ADVANCE_TRIGGER === 'submission'
                || ($isOverride && STAGE_ADVANCE_TRIGGER === 'approval')) {
                $advancedTo = advance_stage_for_document($pdo, $doc, $user, date('Y-m-d'),
                    "after \"$docName\" was formally submitted to RPMS");
            }
        }
        $pdo->commit();
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('submit_to_rpms', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The submission could not be saved. Nothing was changed - please try again.'], 500);
    }

    audit_log($user, $isOverride ? 'admin_override_submit_to_rpms' : 'document_submitted_to_rpms', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => $state, 'after' => WF_SUBMITTED_RPMS, 'reason' => $isOverride ? $reason : null, 'override' => $isOverride,
        'details' => "\"$docName\" (" . $doc['document_type'] . ', ' . $doc['stage'] . ')'
            . ($user['role'] === 'admin' ? ' submitted on behalf of the student' : ''),
    ]);

    // Confirmations after the data is safely committed.
    $ref = strtoupper(substr((string)$id, 0, 8));
    $whenText = date('M j, Y g:i A');
    if ($doc['student_id']) {
        $body = "Hello {student},\n\nYour document \"$docName\" was formally submitted to RPMS on $whenText."
            . ($user['role'] === 'admin' ? "\nSubmitted on your behalf by RPMS ({$user['full_name']})." : '')
            . ($isOverride ? "\nReason: $reason" : '')
            . ($advancedTo ? "\n\nYour IERB stage has advanced to: " . stage_label($advancedTo) . " ($advancedTo)." : '')
            . "\n\nReference: $ref\n\n- CEU Malolos RPMS / PRISM";
        $srow = $pdo->prepare('SELECT full_name FROM students WHERE id = :id');
        $srow->execute([':id' => $doc['student_id']]);
        notify_student($pdo, (int)$doc['student_id'], 'PRISM Formal RPMS Submission Confirmation',
            str_replace('{student}', (string)$srow->fetchColumn(), $body),
            "\"$docName\" was formally submitted to RPMS (reference $ref)."
                . ($advancedTo ? ' Your stage is now ' . stage_label($advancedTo) . '.' : ''),
            'RPMS Submission', $user['full_name']);
        $who = $doc['student_name'] ?: 'A student';
        if ($user['role'] === 'student') {
            $adminBody = "Student: $who\nDocument: $docName\nType: {$doc['document_type']}\n"
                . 'IERB stage: ' . $doc['stage'] . ' - ' . stage_label($doc['stage']) . "\n"
                . "Formal-submission reference: $ref\nSubmitted: $whenText\n";
            notify_rpms_admins($pdo, 'New formal RPMS submission', "$who formally submitted \"$docName\" ("
                . $doc['document_type'] . ', ' . stage_label($doc['stage']) . "). Reference $ref.", 'RPMS Submission', $who,
                $adminBody, 'PRISM - New Formal RPMS Submission');
        }
        notify_adviser_of_student($pdo, (int)$doc['student_id'], 'Document submitted to RPMS',
            "$who formally submitted \"$docName\" to RPMS. Reference $ref.", 'RPMS Submission', $user['full_name']);
    }

    json_out([
        'ok' => true,
        'message' => "\"$docName\" was submitted to RPMS. Reference $ref."
            . ($advancedTo ? ' Your stage is now ' . stage_label($advancedTo) . '.' : ''),
        'reference' => $ref,
        'stageAdvanced' => $advancedTo !== null,
        'newStage' => $advancedTo,
        'adminOverride' => $isOverride,
    ]);
}

// ---------------------------------------------------------------------
// override_review (admin only): set any review status, with a mandatory, logged reason
// ---------------------------------------------------------------------
if ($action === 'override_review') {
    api_require_login(['admin']);
    $allowedStatuses = ['Submitted', 'Under Review', 'Received', 'Verified', 'Resubmission Requested', 'Approved', 'Denied'];
    $status = trim((string)($payload['status'] ?? ''));
    $reason = trim((string)($payload['reason'] ?? ''));
    if (!in_array($status, $allowedStatuses, true)) {
        json_out(['ok' => false, 'message' => 'Invalid review status.'], 422);
    }
    if (!override_reason_valid($reason)) {
        json_out(['ok' => false, 'requiresReason' => true, 'message' => override_reason_message()], 422);
    }
    if (!$isCurrent) {
        json_out(['ok' => false, 'message' => 'This is an older version. Override the latest version instead.'], 409);
    }
    if ($locked) {
        json_out(['ok' => false, 'message' => 'This document was formally submitted to RPMS and is locked. '
            . 'To correct the student\'s progress, use Admin Override on their IERB record.'], 409);
    }
    $before = $doc['review_status'];
    if ($before === $status) {
        json_out(['ok' => false, 'message' => 'The status is already ' . $status . '. Nothing to override.'], 422);
    }

    $advancedTo = null;
    try {
        begin_document_write($pdo, $doc);
        $pdo->prepare('UPDATE documents SET review_status = :s, review_remarks = :r, reviewed_by = :by, reviewed_at = NOW(),
                admin_override = 1, override_reason = :reason, override_by = :ob, override_at = NOW() WHERE id = :id')
            ->execute([':s' => $status, ':r' => 'Admin override: ' . $reason, ':by' => $user['full_name'] . ' (Admin Override)',
                ':reason' => $reason, ':ob' => $user['full_name'], ':id' => $id]);
        if ($status === 'Approved' && STAGE_ADVANCE_TRIGGER === 'approval' && $doc['student_id']) {
            $advancedTo = advance_stage_for_document($pdo, $doc, $user,
                $doc['detected_approval_date'] ?: date('Y-m-d'),
                "after \"$docName\" was approved by Admin Override (reason: $reason)");
        }
        $pdo->commit();
    } catch (DocumentWriteConflict $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('document_override_review', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The override could not be saved. Nothing was changed - please try again.'], 500);
    }

    audit_log($user, 'admin_override_document', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => $before, 'after' => $status, 'reason' => $reason, 'override' => true,
        'details' => "\"$docName\" $before -> $status",
    ]);

    if ($doc['student_id']) {
        $srow = $pdo->prepare('SELECT full_name FROM students WHERE id = :id');
        $srow->execute([':id' => $doc['student_id']]);
        $sname = (string)$srow->fetchColumn();
        $next = $status === 'Approved'
            ? ($advancedTo
                ? "\n\nYour IERB stage has advanced to: " . stage_label($advancedTo) . " ($advancedTo)."
                : "\n\nNext step: open My Documents in PRISM and click \"Submit to RPMS\".")
            : '';
        notify_student($pdo, (int)$doc['student_id'], 'PRISM Document Status Update',
            "Hello $sname,\n\nAn RPMS administrator changed the status of your document \"$docName\" from $before to $status."
                . "\nReason: $reason$next\n\n- CEU Malolos RPMS / PRISM",
            "An RPMS administrator changed \"$docName\" to $status. Reason: $reason"
                . ($advancedTo ? ' Your stage is now ' . stage_label($advancedTo) . '.' : ''),
            'Status Update', $user['full_name'] . ' (Admin Override)');
        notify_adviser_of_student($pdo, (int)$doc['student_id'], 'Admin Override on a document',
            "{$user['full_name']} changed \"$docName\" from $before to $status. Reason: $reason"
                . ($advancedTo ? ' The student advanced to ' . stage_label($advancedTo) . '.' : ''),
            'Admin Override', $user['full_name']);
    }

    json_out(['ok' => true, 'workflowState' => document_workflow_state(array_merge($doc, ['review_status' => $status])),
        'stageAdvanced' => $advancedTo !== null, 'newStage' => $advancedTo,
        'message' => "Admin Override saved: \"$docName\" is now $status. Your name, the time and your reason were added to the audit log."
            . ($advancedTo ? ' The student advanced to ' . stage_label($advancedTo) . '.' : '')]);
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
        json_out(['ok' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('document_delete', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The document could not be deleted. Nothing was changed.'], 500);
    }
    remove_document_file($path, (string)$id, 'committed deletion');
    audit_log($user, 'document_deleted', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => document_workflow_state($doc), 'reason' => $reason !== '' ? $reason : null, 'override' => $locked,
        'details' => "\"$docName\" v" . (int)($doc['version_no'] ?? 1) . ' deleted' . ($restoredId ? ' (previous version restored)' : ''),
    ]);
    json_out(['ok' => true, 'restoredPrevious' => $restoredId !== null,
        'message' => "Deleted \"$docName\"." . ($restoredId ? ' The previous version is now the current one.' : '')]);
}

// ---------------------------------------------------------------------
// Unknown document action.
json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
