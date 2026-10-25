<?php
/** Operator-only enrollment/revocation. Never exposes an HTTP or upload enrollment path. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['id:', 'confirm-synthetic', 'revoke']);
$id = $options['id'] ?? '';
if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)
    || (isset($options['confirm-synthetic']) === isset($options['revoke']))) {
    fwrite(STDERR, "Usage: php maintenance/document_summary_synthetic.php --id=<document-id> --confirm-synthetic|--revoke\nEnroll only a reviewed, wholly synthetic test file. Never enroll institutional records.\n");
    exit(1);
}
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/document_summary.php';
$lock = fopen(STORAGE_DIR . '/document-summary-synthetic.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) { fwrite(STDERR, "Cannot lock the synthetic registry.\n"); exit(1); }
$temporary = null; $exitCode = 0;
try {
    $path = STORAGE_DIR . '/document-summary-synthetic.json';
    $registry = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($registry)) throw new RuntimeException('Invalid synthetic registry; review it before continuing.');
    if (isset($options['revoke'])) {
        unset($registry[$id]);
    } else {
        $query = db()->prepare('SELECT id, version_no, stored_name FROM documents WHERE id = ?');
        $query->execute([$id]); $document = $query->fetch();
        if (!$document) throw new RuntimeException('Document not found.');
        $registry[$id] = ['version' => (int)$document['version_no'],
            'sha256' => document_summary_file_hash(summary_stored_document_path($document)), 'registered_at' => gmdate('c')];
    }
    $json = json_encode((object)$registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (strlen($json) > 1048576) throw new RuntimeException('Synthetic registry is full. Revoke unused test entries.');
    $temporary = tempnam(STORAGE_DIR, 'synthetic-');
    if (!$temporary || file_put_contents($temporary, $json) === false) throw new RuntimeException('Cannot write synthetic registry.');
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) throw new RuntimeException('Cannot replace synthetic registry.');
    $temporary = null;
    echo isset($options['revoke']) ? "Synthetic authorization revoked.\n" : "Synthetic document registered. Enable PRISM_DOCUMENT_AI_SYNTHETIC_DEMO and review the excerpt in PRISM.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Synthetic registration failed. Check the document and private storage permissions.\n"); $exitCode = 1;
} finally {
    if ($temporary !== null) @unlink($temporary);
    flock($lock, LOCK_UN); fclose($lock);
}
exit($exitCode);
