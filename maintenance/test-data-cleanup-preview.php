<?php
/** READ-ONLY test-record cleanup preview. It never deletes, archives or changes anything.
 * Usage (explicit connection; never loads config.php):
 *   PRISM_CLEANUP_DB_DSN='mysql:host=...;dbname=...' PRISM_CLEANUP_DB_USER=... PRISM_CLEANUP_DB_PASSWORD=... \
 *     php maintenance/test-data-cleanup-preview.php [--confirmed=confirmed.json] [--storage=/path/to/storage] [--redact]
 * confirmed.json lists the records the owner has confirmed as test data: {"students":[ids],"advisers":[ids]}.
 * Heuristic signals are shown to help that review; they never confirm a record by themselves.
 * Execution uses the authenticated Admin lifecycle (Archive, then permanent deletion) per the reviewed procedure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['confirmed::', 'storage::', 'redact']);
try {
    $dsn = getenv('PRISM_CLEANUP_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) throw new InvalidArgumentException('An explicit read-only connection (PRISM_CLEANUP_DB_DSN) is required.');
    try { $pdo = new PDO($dsn, getenv('PRISM_CLEANUP_DB_USER') ?: '', getenv('PRISM_CLEANUP_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
    catch (PDOException $e) { throw new RuntimeException('connection_failed'); }
    $confirmed = ['students' => [], 'advisers' => []];
    if (!empty($options['confirmed'])) {
        $raw = json_decode((string)file_get_contents($options['confirmed']), true, 16, JSON_THROW_ON_ERROR);
        foreach (['students', 'advisers'] as $k) $confirmed[$k] = array_values(array_map('intval', $raw[$k] ?? []));
    }
    $storage = isset($options['storage']) ? rtrim((string)$options['storage'], '/\\') : null;
    $redact = isset($options['redact']);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $one = static function (string $sql, array $p = []) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $has = static fn(string $t) => in_array($t, $tables, true);
    $signals = static function (array $r, array $fields): array {
        $out = [];
        foreach ($fields as $f) if (preg_match('/\b(test|testing|sample|dummy|demo|asdf|qwerty|lorem|trial)\b/i', (string)($r[$f] ?? ''))) $out[] = $f.'_contains_test_marker';
        if (preg_match('/@(example\.(test|com|org)|test\.)/i', (string)($r['email'] ?? ''))) $out[] = 'reserved_test_email_domain';
        return $out;
    };
    $manifest = ['generatedAt' => date(DATE_ATOM), 'schemaVersion' => (string)$one("SELECT v FROM schema_meta WHERE k='schema_version'"),
        'serverVersion' => (string)$one('SELECT VERSION()'), 'readOnly' => true, 'students' => [], 'advisers' => [], 'summary' => []];
    foreach ($pdo->query('SELECT * FROM students ORDER BY id')->fetchAll() as $s) {
        $id = (int)$s['id'];
        $docs = $pdo->prepare('SELECT id,stored_name,document_type,version_no,is_current,review_status,rpms_submitted_at FROM documents WHERE student_id=? ORDER BY id'); $docs->execute([$id]); $docs = $docs->fetchAll();
        $submitted = count(array_filter($docs, static fn($d) => !empty($d['rpms_submitted_at'])));
        $deps = ['documents' => count($docs), 'formallySubmittedDocuments' => $submitted,
            'ierbHistory' => (int)$one('SELECT COUNT(*) FROM ierb_history WHERE student_id=?', [$id]),
            'reportsOwned' => (int)$one('SELECT COUNT(*) FROM reports WHERE owner_student_id=?', [$id]),
            'notificationsToStudent' => (int)$one("SELECT COUNT(*) FROM notifications WHERE recipient_type='student' AND recipient_id=?", [$id]),
            'deadlineRecipients' => (int)$one('SELECT COUNT(*) FROM calendar_deadline_recipients WHERE student_id=?', [$id]),
            'auditRows' => (int)$one('SELECT COUNT(*) FROM activity_logs WHERE student_id=?', [$id]),
            'v14Workflow' => $has('ierb_workflows') ? (int)$one('SELECT COUNT(*) FROM ierb_workflows WHERE student_id=?', [$id]) : 0,
            'canonicalMembership' => $has('research_project_members') ? (int)$one('SELECT COUNT(*) FROM research_project_members WHERE student_id=?', [$id]) : 0];
        $files = array_map(static function ($d) use ($storage) {
            $f = ['documentId' => $d['id'], 'storedName' => basename((string)$d['stored_name']), 'type' => $d['document_type'], 'version' => (int)$d['version_no']];
            if ($storage !== null) $f['fileExists'] = is_file($storage.'/documents/'.basename((string)$d['stored_name']));
            return $f;
        }, $docs);
        $protected = [];
        if (trim((string)$s['protocol_code']) !== '') $protected[] = 'protocol_code_assigned';
        if ($submitted > 0) $protected[] = 'formally_submitted_documents';
        if ($s['stage'] === 'Completed') $protected[] = 'ierb_completed';
        if ($deps['v14Workflow'] || $deps['canonicalMembership']) $protected[] = 'v14_workflow_or_project_history';
        if (!empty($s['retention_hold'])) $protected[] = 'retention_hold';
        $incomplete = empty($s['profile_completed_at']) || ($submitted === 0 && $s['stage'] === 'Stage 1' && trim((string)$s['protocol_code']) === '');
        $isConfirmed = in_array($id, $confirmed['students'], true);
        $decision = !$isConfirmed ? 'retain_unconfirmed' : ($protected ? 'retain_protected' : ($incomplete ? 'delete_via_admin_lifecycle' : 'retain_complete_requires_separate_approval'));
        $manifest['students'][] = ['id' => $id, 'studentNumber' => $redact ? null : $s['student_id'], 'name' => $redact ? null : $s['full_name'], 'email' => $redact ? null : $s['email'],
            'createdAt' => $s['created_at'], 'archivedAt' => $s['archived_at'], 'profileCompleted' => !empty($s['profile_completed_at']), 'stage' => $s['stage'],
            'signals' => $signals($s, ['full_name', 'research_title', 'research_group']), 'incomplete' => $incomplete, 'protectedBy' => $protected,
            'confirmedTest' => $isConfirmed, 'decision' => $decision, 'dependencies' => $deps, 'files' => $files];
    }
    foreach ($pdo->query('SELECT * FROM advisers ORDER BY id')->fetchAll() as $a) {
        $id = (int)$a['id'];
        $assigned = (int)$one('SELECT COUNT(*) FROM students WHERE adviser_id=?', [$id]);
        $isConfirmed = in_array($id, $confirmed['advisers'], true);
        $protected = $assigned ? ['assigned_students'] : [];
        if (!empty($a['retention_hold'])) $protected[] = 'retention_hold';
        $manifest['advisers'][] = ['id' => $id, 'employeeId' => $redact ? null : $a['employee_id'], 'name' => $redact ? null : $a['full_name'], 'email' => $redact ? null : $a['email'],
            'profileCompleted' => !empty($a['profile_completed_at']), 'assignedStudents' => $assigned, 'signals' => $signals($a, ['full_name', 'department']),
            'protectedBy' => $protected, 'confirmedTest' => $isConfirmed,
            'decision' => !$isConfirmed ? 'retain_unconfirmed' : ($protected ? 'retain_protected' : 'delete_via_admin_lifecycle')];
    }
    $pdo->rollBack();
    $count = static fn(array $rows, string $decision) => count(array_filter($rows, static fn($r) => $r['decision'] === $decision));
    foreach (['students', 'advisers'] as $k) foreach (['retain_unconfirmed', 'retain_protected', 'retain_complete_requires_separate_approval', 'delete_via_admin_lifecycle'] as $d) $manifest['summary'][$k][$d] = $count($manifest[$k], $d);
    $ids = array_map(static fn($s) => trim((string)$s['studentNumber']), $manifest['students']);
    $manifest['summary']['students']['blankStudentIds'] = $redact ? null : count(array_filter($ids, static fn($v) => $v === ''));
    $manifest['summary']['students']['nonNumericStudentIds'] = $redact ? null : count(array_filter($ids, static fn($v) => $v !== '' && !preg_match('/\A[0-9]+\z/', $v)));
    $manifest['retainedByDesign'] = 'Audit logs, legal policy versions/approvals/acceptance evidence and institutional history are retained or handled by the reviewed Admin lifecycle; this preview never removes them.';
    $decisions = array_map(static fn($r) => [$r['id'], $r['decision']], [...$manifest['students'], ...$manifest['advisers']]);
    $manifest['decisionSha256'] = hash('sha256', json_encode($decisions, JSON_THROW_ON_ERROR));
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e instanceof RuntimeException && $e->getMessage() === 'connection_failed' ? 'connection_failed' : $e->getMessage()]).PHP_EOL);
    exit(1);
}
