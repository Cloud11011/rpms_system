<?php
/** Authenticated same-origin POST actions; exact snapshots keep filters out of destructive requests. */
require_once __DIR__ . '/document_management.php';
try {
    document_management_admin($user);
    require_session_generation(true);
    $payload=json_body(true);
    $result=match ($action) {
        'selection_snapshot'=>document_selection_snapshot($pdo,$user,$payload),
        'bulk_delete_preview'=>document_bulk_preview($pdo,$user,$payload),
        'bulk_delete'=>document_bulk_delete($pdo,$user,$payload),
        default=>throw new DocumentManagementError('Unknown document-management action.',400),
    };
    json_out(['ok'=>true]+$result);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $known=$e instanceof DocumentManagementError || $e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden || $e instanceof AccountLifecycleBadRequest;
    if (!$known) log_api_error('document_management','A document-management transaction failed (' . get_class($e) . ').');
    json_out(['ok'=>false,'message'=>$known ? $e->getMessage() : 'The operation could not be completed. Refresh the repository before retrying.'],
        $e instanceof DocumentManagementError ? $e->status : ($known ? lifecycle_error_status($e) : 500));
}
