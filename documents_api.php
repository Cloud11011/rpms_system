<?php

require __DIR__ . '/config.php';
require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/workflow.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
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
function fetch_doc(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT d.*, s.course, s.protocol_code, s.adviser_id
        FROM documents d LEFT JOIN students s ON s.id = d.student_id WHERE d.id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
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
    return $viewerAdviserId !== null && !empty($d['adviser_id']) && (int)$d['adviser_id'] === $viewerAdviserId;
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
        'aiSummary' => $d['ai_summary'],

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
            'review' => $isCurrent && !$locked && can_review_doc($user, $viewerAdviserId, $d),
            'submitToRpms' => $isCurrent && !$locked && $state === WF_READY_FOR_RPMS && in_array($role, ['student', 'admin'], true),
            'forceSubmitToRpms' => $isCurrent && !$locked && $role === 'admin' && $state !== WF_READY_FOR_RPMS,
            'override' => $isCurrent && !$locked && $role === 'admin',
            'delete' => in_array($role, ['admin', 'adviser'], true) && (!$locked || $role === 'admin'),
        ],
    ];
}

// ---------------------------------------------------------------------
// list
// ---------------------------------------------------------------------
if ($action === 'list') {
    $includeOld = ($_GET['includeOld'] ?? '') === '1';
    $sql = 'SELECT d.*, s.course, s.protocol_code, s.adviser_id
            FROM documents d LEFT JOIN students s ON s.id = d.student_id';
    $where = [];
    $params = [];
    if ($user['role'] === 'student') {
        $where[] = 's.email = :e';
        $params[':e'] = $user['email'];
    } elseif ($user['role'] === 'adviser') {
        // Advisers only ever see documents of the students assigned to them.
        $where[] = $viewerAdviserId !== null ? 's.adviser_id = :vad' : '1 = 0';
        if ($viewerAdviserId !== null) {
            $params[':vad'] = $viewerAdviserId;
        }
    }
    if (!$includeOld) {
        $where[] = 'd.is_current = 1';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY d.uploaded_at DESC');
    $stmt->execute($params);
    $rows = array_map(fn($d) => doc_row($d, $user, $viewerAdviserId), $stmt->fetchAll());

    // Counts per workflow state (before optional filters) so the UI can show tabs/badges.
    $counts = [];
    foreach ($rows as $r) {
        $counts[$r['workflowState']] = ($counts[$r['workflowState']] ?? 0) + 1;
    }

    // Optional server-side filters: q (text), state, stage, type.
    $qText = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $fState = trim((string)($_GET['state'] ?? ''));
    $fStage = trim((string)($_GET['stage'] ?? ''));
    $fType = trim((string)($_GET['type'] ?? ''));
    $rows = array_values(array_filter($rows, function ($r) use ($qText, $fState, $fStage, $fType) {
        if ($fState !== '' && $r['workflowState'] !== $fState) return false;
        if ($fStage !== '' && $r['stage'] !== $fStage) return false;
        if ($fType !== '' && $r['documentType'] !== $fType) return false;
        if ($qText !== '') {
            $hay = mb_strtolower(implode(' ', [$r['originalName'], $r['student'], $r['protocolCode'], $r['documentType'], $r['uploadedBy']]));
            if (mb_strpos($hay, $qText) === false) return false;
        }
        return true;
    }));

    json_out(['ok' => true, 'documents' => $rows, 'counts' => $counts]);
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
        $own = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE email = :e');
        $own->execute([':e' => $user['email']]);
        $ownRow = $own->fetch();
        if ($ownRow) {
            $studentDbId = (int)$ownRow['id'];
            $studentName = $ownRow['full_name'];
            $studentStage = $ownRow['stage'];
        }
    } elseif ($studentName !== '') {
        $match = $pdo->prepare('SELECT id, full_name, stage FROM students WHERE full_name = :n OR student_id = :n LIMIT 1');
        $match->execute([':n' => $studentName]);
        $matchRow = $match->fetch();
        if ($matchRow) {
            $studentDbId = (int)$matchRow['id'];
            $studentName = $matchRow['full_name'];
            $studentStage = $matchRow['stage'];
        }
    }

    if ($user['role'] === 'adviser') {
        $ownsMatch = $studentDbId !== null && $viewerAdviserId !== null && (int)$pdo->query(
            'SELECT COALESCE(adviser_id, 0) FROM students WHERE id = ' . (int)$studentDbId)->fetchColumn() === $viewerAdviserId;
        if (!$ownsMatch) {
            json_out(['ok' => false, 'message' => 'Enter the name or ID of a student who is assigned to you. Advisers can only upload documents for their own students.'], 422);
        }
    }

    $documentType = trim((string)($_POST['documentType'] ?? '')) ?: 'Other';
    // No stage sent? Use the student's current stage rather than always "Stage 1".
    $stage = trim((string)($_POST['stage'] ?? ''));
    if ($stage === '') {
        $stage = $studentStage ?: 'Stage 1';
    }
    if (!in_array($stage, STAGE_SEQUENCE, true)) {
        json_out(['ok' => false, 'message' => 'Invalid IERB stage.'], 422);
    }

    // Is this a new version of something already uploaded?
    $prev = null;
    if ($studentDbId) {
        $p = $pdo->prepare('SELECT * FROM documents
            WHERE student_id = :sid AND stage = :stage AND document_type = :type AND is_current = 1
            ORDER BY version_no DESC, uploaded_at DESC LIMIT 1');
        $p->execute([':sid' => $studentDbId, ':stage' => $stage, ':type' => $documentType]);
        $prev = $p->fetch() ?: null;
        if ($prev && $user['role'] === 'student' && document_is_locked($prev)) {
            json_out(['ok' => false, 'message' => 'This document was already submitted to RPMS on '
                . date('M j, Y', strtotime($prev['rpms_submitted_at']))
                . ' and is locked. Contact RPMS if it needs to be replaced.'], 409);
        }
    }
    $versionNo = $prev ? ((int)$prev['version_no'] + 1) : 1;

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
        @unlink($target);
        json_out(['ok' => false, 'message' => 'The uploaded file content does not match its file extension. Please upload the original document without renaming its extension.'], 415);
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
                ':notes' => trim((string)($_POST['notes'] ?? '')), ':review' => 'Submitted',
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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($target);
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

if ($user['role'] === 'adviser' && ($viewerAdviserId === null || (int)($doc['adviser_id'] ?? 0) !== $viewerAdviserId)) {
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
        $stmt = $pdo->prepare('SELECT d.*, s.course, s.protocol_code, s.adviser_id
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

    $pdo->prepare('UPDATE documents SET review_status = :s, review_remarks = :r, reviewed_by = :by, reviewed_at = NOW(),
            admin_override = IF(:changed = 1, 0, admin_override)
        WHERE id = :id')
        ->execute([':s' => $status, ':r' => $remarks, ':by' => $user['full_name'], ':changed' => $changed ? 1 : 0, ':id' => $id]);

    // Legacy behaviour (STAGE_ADVANCE_TRIGGER = 'approval'): advance as soon as the adviser approves.
    $advancedTo = null;
    if ($changed && $status === 'Approved' && STAGE_ADVANCE_TRIGGER === 'approval' && $doc['student_id']) {
        $advancedTo = advance_stage_for_document($pdo, $doc, $user,
            $doc['detected_approval_date'] ?: date('Y-m-d'), "after \"$docName\" was approved");
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
        $pdo->beginTransaction();

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

            if (STAGE_ADVANCE_TRIGGER === 'submission') {
                $advancedTo = advance_stage_for_document($pdo, $doc, $user, date('Y-m-d'),
                    "after \"$docName\" was formally submitted to RPMS");
            }
        }
        $pdo->commit();
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
            notify_rpms_admins($pdo, 'New formal RPMS submission', "$who formally submitted \"$docName\" ("
                . $doc['document_type'] . ', ' . stage_label($doc['stage']) . "). Reference $ref.", 'RPMS Submission', $who);
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

    $pdo->prepare('UPDATE documents SET review_status = :s, review_remarks = :r, reviewed_by = :by, reviewed_at = NOW(),
            admin_override = 1, override_reason = :reason, override_by = :ob, override_at = NOW() WHERE id = :id')
        ->execute([':s' => $status, ':r' => 'Admin override: ' . $reason, ':by' => $user['full_name'] . ' (Admin Override)',
            ':reason' => $reason, ':ob' => $user['full_name'], ':id' => $id]);

    audit_log($user, 'admin_override_document', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => $before, 'after' => $status, 'reason' => $reason, 'override' => true,
        'details' => "\"$docName\" $before -> $status",
    ]);

    if ($doc['student_id']) {
        $srow = $pdo->prepare('SELECT full_name FROM students WHERE id = :id');
        $srow->execute([':id' => $doc['student_id']]);
        $sname = (string)$srow->fetchColumn();
        $next = $status === 'Approved' ? "\n\nNext step: open My Documents in PRISM and click \"Submit to RPMS\"." : '';
        notify_student($pdo, (int)$doc['student_id'], 'PRISM Document Status Update',
            "Hello $sname,\n\nAn RPMS administrator changed the status of your document \"$docName\" from $before to $status."
                . "\nReason: $reason$next\n\n- CEU Malolos RPMS / PRISM",
            "An RPMS administrator changed \"$docName\" to $status. Reason: $reason",
            'Status Update', $user['full_name'] . ' (Admin Override)');
        notify_adviser_of_student($pdo, (int)$doc['student_id'], 'Admin Override on a document',
            "{$user['full_name']} changed \"$docName\" from $before to $status. Reason: $reason",
            'Admin Override', $user['full_name']);
    }

    json_out(['ok' => true, 'workflowState' => document_workflow_state(array_merge($doc, ['review_status' => $status])),
        'message' => "Admin Override saved: \"$docName\" is now $status. Your name, the time and your reason were added to the audit log."]);
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
        $pdo->beginTransaction();
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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_api_error('document_delete', $e->getMessage());
        json_out(['ok' => false, 'message' => 'The document could not be deleted. Nothing was changed.'], 500);
    }
    if (is_file($path)) {
        @unlink($path);
    }
    audit_log($user, 'document_deleted', [
        'entity_type' => 'document', 'entity_id' => $id, 'student_id' => $doc['student_id'],
        'before' => document_workflow_state($doc), 'reason' => $reason !== '' ? $reason : null, 'override' => $locked,
        'details' => "\"$docName\" v" . (int)($doc['version_no'] ?? 1) . ' deleted' . ($restoredId ? ' (previous version restored)' : ''),
    ]);
    json_out(['ok' => true, 'restoredPrevious' => $restoredId !== null,
        'message' => "Deleted \"$docName\"." . ($restoredId ? ' The previous version is now the current one.' : '')]);
}

// ---------------------------------------------------------------------
// summarize (unchanged): predefined AI prompt only, output is a draft for human review
// ---------------------------------------------------------------------
if ($action === 'summarize') {
    $text = extract_document_text($path);
    if ($text === '') {
        json_out(['ok' => false, 'message' => 'Text could not be extracted from this file. Scanned PDFs and legacy Word files require an OCR or AI document service.'], 422);
    }
    $summary = openrouter_generate(
        'You are an assistant for a university Research Planning and Monitoring Section. Summarize this document '
        . 'concisely and factually in under 250 words, highlighting purpose, key points, and ethics-relevant items.',
        $text
    ) ?? local_extractive_summary($text, 4);

    // Backfill approval-date detection here too, in case it wasn't captured at upload time.
    $approvalUpdate = '';
    $approvalParams = [':s' => $summary, ':id' => $id];
    if (empty($doc['detected_approval_date'])) {
        $detection = ai_detect_approval_date($text);
        if ($detection['date']) {
            $approvalUpdate = ', detected_approval_date = :adate, approval_date_source = :asrc';
            $approvalParams[':adate'] = $detection['date'];
            $approvalParams[':asrc'] = $detection['source'];
        }
    }
    $pdo->prepare("UPDATE documents SET ai_summary = :s $approvalUpdate WHERE id = :id")->execute($approvalParams);

    json_out(['ok' => true, 'summary' => $summary, 'wordCount' => str_word_count($text), 'aiConfigured' => openrouter_available(),
        'detectedApprovalDate' => $approvalParams[':adate'] ?? $doc['detected_approval_date'] ?? null]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
