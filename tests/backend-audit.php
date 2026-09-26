<?php
/**
 * Isolated regression checks: php tests/backend-audit.php
 * Optional real DOCX extraction checks: php -d extension=zip tests/backend-audit.php
 * Never loads application config, connects to a database, or sends mail/AI requests.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = 0;
function expect_same($expected, $actual, string $message): void
{
    global $checks;
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', received ' . var_export($actual, true));
    }
}

$aiAvailable = false;
$aiResponse = null;
function openrouter_available(): bool { return $GLOBALS['aiAvailable']; }
function openrouter_generate(string $prompt, string $text): ?string { return $GLOBALS['aiResponse']; }
require dirname(__DIR__) . '/ai_helpers.php';

foreach ([
    ['Submitted on 2026-01-01. Approved on 2026-03-14.', '2026-03-14'],
    ['Submitted January 1, 2026. Date of approval: March 14, 2026.', '2026-03-14'],
    ['Approved this 14th day of March 2026.', '2026-03-14'],
    ['Date approved: 14 March 2026.', '2026-03-14'],
    ['Issued on February 29, 2024.', '2024-02-29'],
    ['Approval date: February 30, 2026.', null],
    ['Date approved: 2026-02-29.', null],
    ['Submitted on 2026-01-01.', null],
    ['Not yet approved on 2026-03-14.', null],
    ['No approval has been issued on 2026-01-01.', null],
    ['Will be approved on 2026-03-14.', null],
    ['Approval date: pending; submitted on 2026-01-01.', null],
    ['Approval date: pending, submitted on 2026-01-01.', null],
    ['Approved, expires on 2026-12-31.', null],
    ['Approval date: 2026-02-30. Issued on 2026-03-14.', '2026-03-14'],
    ['Date of issuance: March 14, 2026.', '2026-03-14'],
] as [$text, $expected]) {
    expect_same($expected, regex_detect_approval_date($text), 'Date extraction: ' . $text);
}
expect_same(['date' => null, 'source' => null], ai_detect_approval_date('Submitted on 2026-01-01.'), 'No misleading source without an approval date');
$aiAvailable = true;
$aiResponse = '2026-02-30';
expect_same(['date' => '2026-03-14', 'source' => 'regex'], ai_detect_approval_date('Approved March 14, 2026.'), 'Invalid AI date falls back without calendar normalization');
$aiResponse = '2024-02-29';
expect_same(['date' => '2024-02-29', 'source' => 'ai'], ai_detect_approval_date('Approved February 29, 2024.'), 'Valid AI leap date');
$aiResponse = 'NONE';
expect_same(['date' => null, 'source' => null], ai_detect_approval_date('Submitted on 2026-01-01.'), 'NONE does not invent an approval date');

if (class_exists('ZipArchive')) {
    $temp = tempnam(sys_get_temp_dir(), 'prism-audit-');
    if ($temp === false) throw new RuntimeException('Cannot create temporary DOCX fixture.');
    $docx = $temp . '.docx';
    try {
        foreach ([
            ['<w:document><w:p>Approved March 14, 2026</w:p></w:document>', 'Approved March 14, 2026.'],
            ['<w:document>' . str_repeat('A', 1500001) . '</w:document>', ''],
        ] as [$xml, $expected]) {
            $zip = new ZipArchive();
            if ($zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot build temporary DOCX fixture.');
            }
            $zip->addFromString('word/document.xml', $xml);
            $zip->close();
            expect_same($expected, extract_document_text($docx), 'Expanded DOCX XML is bounded');
        }
    } finally {
        if (is_file($docx)) unlink($docx);
        if (is_file($temp)) unlink($temp);
    }
} else {
    fwrite(STDOUT, "SKIP: DOCX fixtures require the optional ZipArchive extension.\n");
}

class AuditPDO extends PDO
{
    public ?array $current;
    public array $events = [];
    public int $writes = 0;
    private bool $transaction = false;
    public function __construct(?array $current) { $this->current = $current; }
    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Nested transaction');
        $this->events[] = 'BEGIN';
        return $this->transaction = true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function rollBack(): bool { $this->events[] = 'ROLLBACK'; $this->transaction = false; return true; }
    public function commit(): bool { $this->events[] = 'COMMIT'; $this->transaction = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AuditStatement($this, $query);
    }
}
class AuditStatement extends PDOStatement
{
    public function __construct(private AuditPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->pdo->events[] = preg_replace('/\s+/', ' ', $this->sql);
        if (preg_match('/^\s*(UPDATE|DELETE|INSERT)\b/i', $this->sql)) ++$this->pdo->writes;
        if (str_contains($this->sql, 'FOR UPDATE') && !$this->pdo->inTransaction()) {
            throw new RuntimeException('Row lock attempted without a transaction');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->pdo->current ?: false;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->pdo->current['student_id'] ?? false; }
    public function rowCount(): int { return 1; }
}

// Extract declarations only: the config require and endpoint entry point never execute.
$source = file_get_contents(dirname(__DIR__) . '/documents_api.php');
$declarationsStart = strpos($source, 'function fetch_doc(');
$declarationsEnd = strpos($source, "if (\$action === 'list')");
if ($declarationsStart === false || $declarationsEnd === false) throw new RuntimeException('Cannot locate document helper declarations');
eval(substr($source, $declarationsStart, $declarationsEnd - $declarationsStart));

function db(): PDO { throw new RuntimeException('Unexpected database access'); }
require dirname(__DIR__) . '/workflow.php';
class AuditResponse extends RuntimeException
{
    public function __construct(public array $body, public int $status) { parent::__construct('Fixture response'); }
}
function json_out(array $body, int $status = 200): never { throw new AuditResponse($body, $status); }
function api_require_login(array $roles): array
{
    $user = $GLOBALS['auditUser'];
    if (!in_array($user['role'], $roles, true)) json_out(['ok' => false], 403);
    return $user;
}
function log_api_error(string $context, string $message): void { throw new RuntimeException($context . ': ' . $message); }

$doc = [
    'id' => 'fixture-document', 'student_id' => 4, 'adviser_id' => 2, 'is_current' => 1,
    'supersedes_id' => null, 'review_status' => 'Approved', 'review_remarks' => '',
    'reviewed_by' => 'Fixture adviser', 'reviewed_at' => '2026-03-14 10:00:00',
    'rpms_submitted_at' => null, 'admin_override' => 0, 'override_reason' => null,
    'override_by' => null, 'override_at' => null, 'original_name' => 'Fixture.txt',
];
$pdo = new AuditPDO($doc);
begin_document_write($pdo, $doc);
expect_same('BEGIN', $pdo->events[0], 'Transaction starts before locking');
expect_same('SELECT id FROM students WHERE id = :id FOR UPDATE', $pdo->events[1], 'Student lock precedes document lock');
expect_same(true, str_ends_with($pdo->events[2], 'FOR UPDATE'), 'Document is fetched with a locking read');
expect_same(true, $pdo->inTransaction(), 'Unchanged document keeps transaction open for the write');
$pdo->rollBack();

$endpointStart = strpos($source, "if (\$action === 'review')");
$endpointEnd = strpos($source, "if (\$action === 'summarize')");
if ($endpointStart === false || $endpointEnd === false) throw new RuntimeException('Cannot locate document actions');
$endpointCode = substr($source, $endpointStart, $endpointEnd - $endpointStart);
function run_race_fixture(string $action, array $doc, ?array $latest, string $code): array
{
    $pdo = new AuditPDO($latest);
    $user = ['role' => 'admin', 'full_name' => 'Fixture admin', 'email' => 'fixture@example.invalid'];
    $GLOBALS['auditUser'] = $user;
    $viewerAdviserId = null;
    $payload = ['status' => 'Denied', 'remarks' => 'Please revise this fixture.', 'reason' => 'Fixture correction'];
    $isCurrent = (int)$doc['is_current'] === 1;
    $locked = document_is_locked($doc);
    $id = $doc['id'];
    $docName = $doc['original_name'];
    $path = __DIR__ . '/nonexistent-fixture-document.txt';
    try {
        eval($code);
    } catch (AuditResponse $response) {
        return [$response, $pdo];
    }
    throw new RuntimeException('Document action returned no response');
}
foreach (['review', 'override_review', 'submit_to_rpms', 'delete'] as $action) {
    foreach ([
        'formal submission' => array_merge($doc, ['rpms_submitted_at' => '2026-03-15 10:00:00']),
        'adviser denial' => array_merge($doc, ['review_status' => 'Denied']),
        'new version' => array_merge($doc, ['is_current' => 0]),
        'adviser reassignment' => array_merge($doc, ['adviser_id' => 3]),
        'concurrent deletion' => null,
    ] as $case => $latest) {
        [$response, $fixture] = run_race_fixture($action, $doc, $latest, $endpointCode);
        expect_same(409, $response->status, "$action rejects a concurrent $case");
        expect_same(0, $fixture->writes, "$action does not mutate stale state");
        expect_same(false, $fixture->inTransaction(), "$action rolls back before responding");
    }
}

fwrite(STDOUT, "PASS: $checks backend regression assertions; no live database, config, mail, or AI services used.\n");
