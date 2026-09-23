<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/ai_helpers.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

function doc_row(array $d): array
{
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
        'course' => $d['course'] ?? null,
        'documentType' => $d['document_type'],
        'stage' => $d['stage'],
        'stageLabel' => stage_label($d['stage']),
        'notes' => $d['notes'],
        'reviewStatus' => $d['review_status'],
        'reviewRemarks' => $d['review_remarks'],
        'reviewedBy' => $d['reviewed_by'],
        'reviewedAt' => $d['reviewed_at'],
        // The AI/regex-detected approval date printed on the document itself,
        // which reflects when the paper was actually approved -- not when the
        // student got around to uploading it (feature request 3).
        'detectedApprovalDate' => $d['detected_approval_date'] ?? null,
        'approvalDateSource' => $d['approval_date_source'] ?? null,
        'aiSummary' => $d['ai_summary'],
    ];
}

if ($action === 'list') {
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare('SELECT d.*, s.course FROM documents d
            LEFT JOIN students s ON s.id = d.student_id
            WHERE s.email = :e ORDER BY d.uploaded_at DESC');
        $stmt->execute([':e' => $user['email']]);
    } else {
        $stmt = $pdo->query('SELECT d.*, s.course FROM documents d
            LEFT JOIN students s ON s.id = d.student_id
            ORDER BY d.uploaded_at DESC');
    }
    json_out(['ok' => true, 'documents' => array_map('doc_row', $stmt->fetchAll())]);
}

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document'])) {
        json_out(['ok' => false, 'message' => 'No document was provided.'], 400);
    }
    $file = $_FILES['document'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'message' => 'The upload did not complete.'], 400);
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        json_out(['ok' => false, 'message' => 'Files must be 20 MB or smaller.'], 413);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed, true)) {
        json_out(['ok' => false, 'message' => 'This file type is not allowed.'], 415);
    }

    // Resolve which student record this upload belongs to.
    $studentDbId = null;
    $studentName = trim((string)($_POST['student'] ?? ''));
    if ($user['role'] === 'student') {
        $own = $pdo->prepare('SELECT id, full_name FROM students WHERE email = :e');
        $own->execute([':e' => $user['email']]);
        $ownRow = $own->fetch();
        if ($ownRow) {
            $studentDbId = (int)$ownRow['id'];
            $studentName = $ownRow['full_name'];
        }
    } elseif ($studentName !== '') {
        $match = $pdo->prepare('SELECT id, full_name FROM students WHERE full_name = :n OR student_id = :n LIMIT 1');
        $match->execute([':n' => $studentName]);
        $matchRow = $match->fetch();
        if ($matchRow) {
            $studentDbId = (int)$matchRow['id'];
            $studentName = $matchRow['full_name'];
        }
    }

    $id = bin2hex(random_bytes(12));
    $stored = $id . '.' . $ext;
    $target = DOCS_DIR . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        json_out(['ok' => false, 'message' => 'The uploaded file could not be stored.'], 500);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream';

    // Best-effort: try to detect a printed approval date on the document
    // itself at upload time (e.g. certificates the office re-uploads after
    // a delay still carry their true approval date, not the upload date).
    // Never blocks the upload if extraction/detection fails or is slow.
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

    $pdo->prepare('INSERT INTO documents (id, student_id, student_name, uploaded_by, uploaded_by_role,
        original_name, stored_name, mime, size, document_type, stage, notes, review_status,
        detected_approval_date, approval_date_source)
        VALUES (:id,:sid,:sname,:by,:role,:orig,:stored,:mime,:size,:type,:stage,:notes,"Submitted",:adate,:asrc)')
        ->execute([
            ':id' => $id, ':sid' => $studentDbId, ':sname' => $studentName ?: 'Unassigned',
            ':by' => $user['full_name'], ':role' => $user['role'], ':orig' => basename($file['name']),
            ':stored' => $stored, ':mime' => $mime, ':size' => (int)$file['size'],
            ':type' => trim((string)($_POST['documentType'] ?? 'Other')),
            ':stage' => trim((string)($_POST['stage'] ?? 'Stage 1')),
            ':notes' => trim((string)($_POST['notes'] ?? '')),
            ':adate' => $detectedDate, ':asrc' => $detectedSource,
        ]);

    if ($studentDbId) {
        $pdo->prepare('UPDATE students SET last_submission_date = CURDATE() WHERE id = :id')
            ->execute([':id' => $studentDbId]);
    }

    // Confirm receipt to the submitting student, per the study's automated-notification requirement.
    if ($user['role'] === 'student') {
        $body = "Hello {$user['full_name']},\n\nYour document \"" . basename($file['name'])
            . "\" has been received by the RPMS office and is now marked Submitted.\n\n- PRISM";
        $result = send_notification_email($user['email'], 'PRISM Submission Confirmation', $body);
        $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
            subject, message, type, status, delivery_info, sent_at, created_by)
            VALUES ("student",:sid,:email,:name,"PRISM Submission Confirmation",:msg,"Submission Confirmation",:status,:info,NOW(),"System")')
            ->execute([
                ':sid' => $studentDbId, ':email' => $user['email'], ':name' => $user['full_name'],
                ':msg' => 'Your document "' . basename($file['name']) . '" was received.',
                ':status' => $result['ok'] ? 'Sent' : 'Failed', ':info' => $result['message'],
            ]);
    }

    log_activity($user['email'], 'document_uploaded', "id=$id name=" . basename($file['name']));

    $get = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
    $get->execute([':id' => $id]);
    json_out(['ok' => true, 'document' => doc_row($get->fetch())]);
}

$payload = json_body();
$id = $_GET['id'] ?? $payload['id'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();

if (!$doc) {
    json_out(['ok' => false, 'message' => 'Document not found.'], 404);
}

// Students may only touch their own documents (including downloading/viewing the file itself).
if ($user['role'] === 'student') {
    $own = $pdo->prepare('SELECT email FROM students WHERE id = :id');
    $own->execute([':id' => $doc['student_id']]);
    $ownEmail = $own->fetchColumn();
    if ($ownEmail !== $user['email']) {
        json_out(['ok' => false, 'message' => 'Not authorized.'], 403);
    }
}

$path = DOCS_DIR . DIRECTORY_SEPARATOR . basename($doc['stored_name']);

if ($action === 'file') {
    if (!is_file($path)) {
        json_out(['ok' => false, 'message' => 'File missing from storage.'], 404);
    }
    header_remove('Content-Type');
    header('Content-Type: ' . $doc['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline')
        . '; filename="' . str_replace(["\r", "\n", '"'], '', $doc['original_name']) . '"');
    readfile($path);
    exit;
}

if ($action === 'review') {
    api_require_login(['admin', 'adviser']);
    $allowed = ['Under Review', 'Received', 'Verified', 'Resubmission Requested', 'Approved', 'Denied'];
    $status = trim((string)($payload['status'] ?? ''));
    if (!in_array($status, $allowed, true)) {
        json_out(['ok' => false, 'message' => 'Invalid review status.'], 422);
    }
    $remarks = trim((string)($payload['remarks'] ?? ''));
    $pdo->prepare('UPDATE documents SET review_status=:s, review_remarks=:r, reviewed_by=:by, reviewed_at=NOW()
        WHERE id=:id')->execute([':s' => $status, ':r' => $remarks, ':by' => $user['full_name'], ':id' => $id]);

    $stageAdvanceNote = '';
    if ($status === 'Approved' && $doc['student_id']) {
        // Auto stage progression (feature 6): once the document tied to a
        // student's CURRENT stage is approved, move them on to the next
        // stage automatically. Comparing against the document's own stage
        // (not just "any approval") means re-approving an old document, or
        // approving a document for a stage the student has already moved
        // past, doesn't re-trigger progression.
        $studentRow = $pdo->prepare('SELECT stage, status FROM students WHERE id = :id');
        $studentRow->execute([':id' => $doc['student_id']]);
        $student = $studentRow->fetch();

        if ($student && $doc['stage'] === $student['stage']) {
            $newStage = next_stage($student['stage']);
            if ($newStage !== $student['stage']) {
                // Prefer the date actually printed on the approved document
                // (feature 3) over "today", since students sometimes delay
                // sending the file after it was really approved.
                $effectiveDate = $doc['detected_approval_date'] ?: date('Y-m-d');

                $pdo->prepare('UPDATE students SET stage = :stage, status = "On Track",
                    last_submission_date = :sub, updated_at = NOW() WHERE id = :id')
                    ->execute([':stage' => $newStage, ':sub' => $effectiveDate, ':id' => $doc['student_id']]);

                $historyNote = 'Auto-advanced to ' . stage_label($newStage) . ' (' . $newStage . ')'
                    . ' after "' . $doc['original_name'] . '" was approved'
                    . ($doc['detected_approval_date'] ? " (approval date detected on document: {$doc['detected_approval_date']})" : '')
                    . '.';
                $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, submission_date, actor)
                    VALUES (:sid,:stage,"On Track",:note,:sub,:actor)')->execute([
                    ':sid' => $doc['student_id'], ':stage' => $newStage, ':note' => $historyNote,
                    ':sub' => $effectiveDate, ':actor' => $user['full_name'] . ' (auto)',
                ]);

                $stageAdvanceNote = "\n\nYour IERB stage has automatically advanced to: "
                    . stage_label($newStage) . " ($newStage).";
                log_activity($user['email'], 'stage_auto_advanced', "student_id={$doc['student_id']} new_stage=$newStage");
            }
        }
    }

    if ($doc['student_id']) {
        $email = $pdo->prepare('SELECT email, full_name FROM students WHERE id = :id');
        $email->execute([':id' => $doc['student_id']]);
        $student = $email->fetch();
        if ($student) {
            $body = "Hello {$student['full_name']},\n\nYour document \"{$doc['original_name']}\" is now: {$status}."
                . ($remarks !== '' ? "\nRemarks: {$remarks}" : '') . $stageAdvanceNote . "\n\n- CEU Malolos RPMS / PRISM";
            $result = send_notification_email($student['email'], 'PRISM Document Status Update', $body);
            $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
                subject, message, type, status, delivery_info, sent_at, created_by)
                VALUES ("student",:sid,:email,:name,"PRISM Document Status Update",:msg,"Status Update",:status2,:info,NOW(),:by)')
                ->execute([
                    ':sid' => $doc['student_id'], ':email' => $student['email'], ':name' => $student['full_name'],
                    ':msg' => "Document \"{$doc['original_name']}\" is now {$status}." . $stageAdvanceNote,
                    ':status2' => $result['ok'] ? 'Sent' : 'Failed', ':info' => $result['message'],
                    ':by' => $user['full_name'],
                ]);
        }
    }

    log_activity($user['email'], 'document_reviewed', "id=$id status=$status");
    json_out(['ok' => true, 'stageAdvanced' => $stageAdvanceNote !== '']);
}

if ($action === 'delete') {
    api_require_login(['admin', 'adviser']);
    if (is_file($path)) {
        @unlink($path);
    }
    $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $id]);
    log_activity($user['email'], 'document_deleted', "id=$id");
    json_out(['ok' => true]);
}

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

    // Backfill approval-date detection here too, in case it wasn't captured
    // at upload time (e.g. documents uploaded before this feature existed).
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
