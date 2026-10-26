<?php
/** Included only by the authenticated, same-origin document API. */
require_once __DIR__ . '/document_summary_ai.php';

$summaryActive = true; $summaryReserve = str_repeat('x', 262144);
register_shutdown_function(static function () use (&$summaryActive, &$summaryReserve): void {
    $last = error_get_last();
    if (!$summaryActive || !$last || !in_array($last['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $summaryReserve = null;
    if (!headers_sent()) { http_response_code(422); header('Content-Type: application/json'); }
    echo json_encode(['ok' => false, 'message' => 'The document exceeded safe processing limits. Try a smaller text-based copy.']);
});
$id = null;
try {
    if (($user['role'] ?? '') !== 'admin') throw new DocumentAIError('forbidden_role', 'Only RPMS Administrators may generate document summaries.', 403);
    $payload = json_body(true);
    $id = $payload['id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)) throw new DocumentAIError('invalid_document_id', 'Provide a valid document ID.');
    $token = $payload['previewToken'] ?? '';
    if (!is_string($token) || strlen($token) > 64) throw new DocumentAIError('invalid_preview', 'Review the outgoing excerpt again.', 409);
    foreach ($_SESSION['document_summary_previews'] ?? [] as $key => $preview) {
        if (($preview['expires'] ?? 0) < time()) unset($_SESSION['document_summary_previews'][$key]);
    }
    if ($action === 'summarize_cancel') {
        unset($_SESSION['document_summary_previews'][$token]);
        $summaryActive = false;
        json_out(['ok' => true, 'message' => 'AI summary canceled.']);
    }
    $doc = fetch_doc($pdo, $id);
    if (!$doc) throw new DocumentAIError('document_missing', 'Document not found. It may have been deleted.', 404);
    // Existing Student/account/actor eligibility and version lock conventions stay in force.
    begin_document_write($pdo, $doc); $pdo->commit();
    $path = summary_stored_document_path($doc);
    $fileHash = document_summary_file_hash($path);
    $fingerprint = document_summary_fingerprint($doc, $fileHash);
    $extraction = extract_document_text_for_summary($path);
    if (!hash_equals($fileHash, document_summary_file_hash($path))) throw new DocumentAIError('document_changed', 'The document changed during extraction. Review it again.', 409);
    $identifiers = document_summary_identifiers($pdo, $doc, $user);
    if ($action === 'summarize_preview') {
        $preview = document_ai_excerpt($extraction, $identifiers);
        $token = bin2hex(random_bytes(24));
        $_SESSION['document_summary_previews'][$token] = ['id' => $id, 'actor' => (string)$user['id'],
            'fingerprint' => $fingerprint, 'excerptHash' => hash('sha256', $preview['excerpt']), 'expires' => time() + 600];
        while (count($_SESSION['document_summary_previews']) > 8) array_shift($_SESSION['document_summary_previews']);
        $summaryActive = false;
        json_out(['ok' => true, 'previewToken' => $token] + $preview);
    }
    $mode = $payload['mode'] ?? 'local';
    if (!is_string($mode) || !in_array($mode, ['local', 'ai'], true)) throw new DocumentAIError('invalid_mode', 'Choose Local Summary or AI-Powered Summary.');
    if ($mode === 'ai') {
        $preview = $_SESSION['document_summary_previews'][$token] ?? null;
        if (($payload['confirmed'] ?? null) !== true || !$preview || $preview['id'] !== $id
            || $preview['actor'] !== (string)$user['id'] || !hash_equals($preview['fingerprint'], $fingerprint)) {
            throw new DocumentAIError('stale_preview', 'Review and authorize the current outgoing excerpt before generating an AI summary.', 409);
        }
        $outgoing = document_ai_excerpt($extraction, $identifiers);
        if (!hash_equals($preview['excerptHash'], hash('sha256', $outgoing['excerpt']))) throw new DocumentAIError('stale_preview', 'The outgoing excerpt changed. Review it again.', 409);
        // A confirmed token authorizes exactly one external attempt, including provider failures.
        unset($_SESSION['document_summary_previews'][$token]);
        $result = ['summary' => generate_document_ai_summary($outgoing['excerpt']), 'source' => 'ai',
            'partial' => $outgoing['partial'], 'pages' => $outgoing['pages']];
    } else {
        $result = generate_document_summary($extraction, $identifiers);
        $result['source'] = 'local';
    }
    // Network/extraction work holds no database locks. Revalidate the exact version and file before saving.
    begin_document_write($pdo, $doc);
    $current = fetch_doc($pdo, $id, true);
    $currentPath = summary_stored_document_path($current);
    if (!hash_equals($fingerprint, document_summary_fingerprint($current, document_summary_file_hash($currentPath)))) {
        throw new DocumentAIError('document_changed', 'The document changed before saving. Your previous summary is unchanged. Refresh and try again.', 409);
    }
    $pdo->prepare('UPDATE documents SET ai_summary = :summary WHERE id = :id')->execute([
        ':summary' => document_summary_storage($result), ':id' => $id]);
    document_workflow_audit($pdo, $user, 'document_summarized', ['entity_id' => $id, 'student_id' => $doc['student_id'],
        'details' => 'source=' . $result['source'] . '; partial=' . (int)$result['partial']]);
    $pdo->commit();
    $summaryActive = false;
    json_out(['ok' => true] + $result);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $badRequest = $error instanceof AccountLifecycleBadRequest;
    $known = $error instanceof DocumentSummaryError || $error instanceof DocumentWriteConflict || $badRequest;
    $reason = $error instanceof DocumentSummaryError ? $error->reasonCode : ($error instanceof DocumentWriteConflict ? 'document_changed' : ($badRequest ? 'bad_request' : 'summary_failed'));
    $auditId = is_string($id) && preg_match('/\A[a-f0-9]{24}\z/', $id) ? $id : null;
    audit_log($user, 'document_summary_failed', ['entity_type' => 'document', 'entity_id' => $auditId, 'details' => 'reason=' . $reason]);
    $summaryActive = false;
    $response = ['ok' => false, 'code' => $reason, 'message' => $known ? $error->getMessage() : 'The summary could not be generated or saved. Your previous summary is unchanged.'];
    if ($error instanceof DocumentAIError && $error->retryAfter !== null) {
        $response['retryAfter'] = $error->retryAfter; header('Retry-After: ' . $error->retryAfter);
    }
    json_out($response, $error instanceof DocumentSummaryError ? $error->httpStatus : ($error instanceof DocumentWriteConflict ? $error->status : ($badRequest ? 400 : 503)));
}
