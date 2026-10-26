<?php
/** Invoked only by the three administrator document-summary actions. */
require_once __DIR__ . '/document_summary_ai.php';
require_session_generation(true);
try { $payload = json_body(true); }
catch (AccountLifecycleBadRequest $error) { json_out(['ok' => false, 'message' => $error->getMessage()], 400); }
$id = $payload['id'] ?? null;
if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)) {
    json_out(['ok' => false, 'code' => 'invalid_document_id', 'message' => 'Provide a valid document ID.'], 422);
}
foreach (($_SESSION['document_summary_previews'] ?? []) as $key => $preview) {
    if (($preview['expires'] ?? 0) < time()) unset($_SESSION['document_summary_previews'][$key]);
}
if ($action === 'summarize_cancel') {
    $preview = $_SESSION['document_summary_previews'][$id] ?? null;
    if ($preview && is_string($payload['previewToken'] ?? null) && hash_equals($preview['token'], $payload['previewToken'])) {
        unset($_SESSION['document_summary_previews'][$id]);
    }
    json_out(['ok' => true]);
}
// Reserve enough memory to return a safe response if a hostile PDF exhausts PHP's budget.
$summaryReserve = str_repeat('x', 262144); $summaryActive = true;
register_shutdown_function(static function () use (&$summaryReserve, &$summaryActive): void {
    $last = error_get_last();
    if (!$summaryActive || !$last || !in_array($last['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $summaryReserve = null;
    if (!headers_sent()) { http_response_code(422); header('Content-Type: application/json'); }
    echo json_encode(['ok' => false, 'code' => 'extraction_failure', 'canGenerateLocal' => true,
        'message' => 'The document exceeded safe processing limits. Try a smaller text-based copy.']);
});
try {
    $doc = fetch_doc($pdo, $id);
    if (!$doc) throw new DocumentSummaryError('document_missing', 'Document not found. It may have been deleted.', 404);
    if ((int)($doc['is_current'] ?? 1) !== 1) throw new DocumentSummaryError('document_changed', 'Select the current document version before generating a summary.', 409);
    begin_document_write($pdo, $doc);
    $pdo->commit();
    $path = summary_stored_document_path($doc);
    $fingerprint = document_summary_fingerprint($doc, $path);
    if ($action === 'summarize_preview') {
        // A new preview replaces any earlier consent for this document.
        unset($_SESSION['document_summary_previews'][$id]);
        $prepared = prepare_document_summary(extract_document_text_for_summary($path), document_summary_identifiers($pdo, $doc, $user));
        if (!hash_equals($fingerprint, document_summary_fingerprint($doc, summary_stored_document_path($doc)))) {
            throw new DocumentSummaryError('document_changed', 'The document changed during extraction. Review a new excerpt.', 409);
        }
        $token = bin2hex(random_bytes(24));
        $_SESSION['document_summary_previews'][$id] = $prepared + ['token' => $token, 'fingerprint' => $fingerprint,
            'actor' => (int)$user['id'], 'generation' => prism_session_generation(), 'expires' => time() + 600];
        while (count($_SESSION['document_summary_previews']) > 4) array_shift($_SESSION['document_summary_previews']);
        $summaryActive = false;
        json_out(['ok' => true, 'eligible' => true, 'previewToken' => $token, 'expiresIn' => 600] + $prepared);
    }
    $mode = $payload['mode'] ?? '';
    if (!in_array($mode, ['local', 'external'], true)) throw new DocumentSummaryError('invalid_mode', 'Choose AI-Powered Summary or Local Summary.');
    if ($mode === 'external') {
        if (($payload['confirmed'] ?? false) !== true) throw new DocumentSummaryError('confirmation_required', 'Review the prepared excerpt and confirm your permission before sending it.', 422);
        $preview = $_SESSION['document_summary_previews'][$id] ?? null;
        if (!$preview || !is_string($payload['previewToken'] ?? null) || !hash_equals($preview['token'], $payload['previewToken'])
            || $preview['actor'] !== (int)$user['id'] || $preview['generation'] !== prism_session_generation()
            || $preview['expires'] < time()) {
            throw new DocumentSummaryError('preview_expired', 'The excerpt review is missing, expired or already used. Review a new excerpt.', 409);
        }
        unset($_SESSION['document_summary_previews'][$id]); // One confirmed request; never retry automatically.
        if (!hash_equals($preview['fingerprint'], $fingerprint)) throw new DocumentSummaryError('document_changed', 'The document or its saved summary changed. Review a new excerpt.', 409);
        audit_log($user, 'document_summary_authorized', ['entity_type' => 'document', 'entity_id' => $id,
            'student_id' => $doc['student_id'], 'details' => 'admin_confirmed_external_excerpt; excerpt_sha256=' . hash('sha256', $preview['excerpt'])]);
        $summary = document_openrouter_summary($preview['excerpt'], document_summary_identifiers($pdo, $doc, $user));
        $result = ['summary' => $summary, 'source' => 'openrouter_ai', 'partial' => $preview['partial'], 'pages' => $preview['pages']];
    } else {
        unset($_SESSION['document_summary_previews'][$id]);
        $result = generate_document_summary(extract_document_text_for_summary($path), []);
    }
    // No provider request runs under a database lock. Recheck permissions, version, saved summary and file bytes before saving.
    begin_document_write($pdo, $doc);
    $current = fetch_doc($pdo, $id, true);
    if (!hash_equals($fingerprint, document_summary_fingerprint($current, summary_stored_document_path($current)))) {
        throw new DocumentSummaryError('document_changed', 'The document or its saved summary changed. The generated result was not saved. Review a new excerpt.', 409);
    }
    $pdo->prepare('UPDATE documents SET ai_summary = :summary WHERE id = :id')
        ->execute([':summary' => document_summary_encode($result), ':id' => $id]);
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
    $retryAfter = $error instanceof DocumentSummaryError ? $error->retryAfter : null;
    if ($retryAfter !== null) header('Retry-After: ' . $retryAfter);
    json_out(['ok' => false, 'code' => $reason, 'canGenerateLocal' => true, 'retryAfter' => $retryAfter,
        'message' => $error instanceof DocumentSummaryError || $error instanceof DocumentWriteConflict
            ? $error->getMessage() : 'The summary could not be saved. Please try again.'],
        $error instanceof DocumentSummaryError ? $error->httpStatus : ($error instanceof DocumentWriteConflict ? $error->status : 503));
}