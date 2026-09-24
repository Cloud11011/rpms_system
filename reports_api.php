<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser']);

/** Advisers may only build reports about their own students' documents/records; admins about anyone's. */
function report_adviser_may_access_student(PDO $pdo, array $user, ?int $studentDbId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if (!$studentDbId) {
        return false;
    }
    $q = db()->prepare('SELECT 1 FROM students s JOIN advisers a ON a.id = s.adviser_id WHERE s.id = :id AND a.email = :e');
    $q->execute([':id' => $studentDbId, ':e' => $user['email']]);
    return (bool)$q->fetchColumn();
}
$pdo = db();
$action = $_GET['action'] ?? 'list';
if (in_array($action, ['ai_report', 'generate', 'delete'], true)) {
    require_post_same_origin();
}

/** Returns only students this user is allowed to include in aggregate reports. */
function report_students_for_user(PDO $pdo, array $user, string $stage = ''): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'adviser') {
        $where[] = 'a.email = :adv';
        $params[':adv'] = $user['email'];
    }
    if ($stage !== '') {
        $where[] = 's.stage = :stage';
        $params[':stage'] = $stage;
    }
    $sql = 'SELECT s.*, a.full_name AS adviser_name FROM students s
        LEFT JOIN advisers a ON a.id = s.adviser_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY s.full_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// --- minimal, dependency-free PDF writer (no external library required) ---
function pdf_escape($s)
{
    $s = (string)$s;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($converted !== false) $s = $converted;
    }
    $s = preg_replace('/[^\x20-\x7E]/', ' ', $s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $s);
}
function wrap_lines($text, $width = 88)
{
    $out = [];
    foreach (preg_split('/\R/', (string)$text) as $line) {
        $wrapped = wordwrap(trim($line), $width, "\n", true);
        foreach (explode("\n", $wrapped) as $part) {
            $out[] = $part;
        }
    }
    return $out;
}
function make_pdf($title, $lines)
{
    $all = array_merge([$title, 'CEU Malolos RPMS - PRISM: IERB Progress & Reporting System',
        'Generated: ' . date('F j, Y g:i A'), ''], $lines);
    $pages = array_chunk($all, 43);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $fontId = 3;
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $kids = [];
    $id = 4;
    foreach ($pages as $pageLines) {
        $pageId = $id++;
        $contentId = $id++;
        $kids[] = "$pageId 0 R";
        $stream = "BT\n/F1 10 Tf\n";
        $y = 760;
        foreach ($pageLines as $i => $line) {
            $font = $i === 0 ? '14' : '10';
            $stream .= "/F1 $font Tf 1 0 0 1 50 $y Tm (" . pdf_escape($line) . ") Tj\n";
            $y -= 16;
        }
        $stream .= "ET";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 $fontId 0 R >> >> /Contents $contentId 0 R >>";
        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $num => $obj) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "$num 0 obj\n$obj\nendobj\n";
    }
    $xref = strlen($pdf);
    $max = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= $max; $n++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$n] ?? 0) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}

if ($action === 'list') {
    if ($user['role'] === 'admin') {
        $rows = $pdo->query('SELECT * FROM reports ORDER BY generated_at DESC')->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM reports WHERE generated_by_user_id = :uid ORDER BY generated_at DESC');
        $stmt->execute([':uid' => $user['id']]);
        $rows = $stmt->fetchAll();
    }
    json_out(['ok' => true, 'reports' => $rows]);
}

$data = json_body();

// ---------------------------------------------------------------------
// One-click AI report generation (Summarized / Full only — guardrailed).
// This is the ONLY entry point that calls the AI for progress reporting:
// it accepts exactly two predefined modes and nothing else, always falls
// back to a deterministic local summary if the AI is unavailable or
// errors, and never accepts free-form prompts from the client.
// ---------------------------------------------------------------------
const AI_REPORT_MODES = ['summary', 'full'];

const AI_SUMMARY_PROMPT = "You are an assistant for a university Research Planning and Monitoring Section (RPMS). "
    . "Given structured IERB progress data for the institution's students, write a SHORT, factual executive summary "
    . "(150-220 words) for RPMS staff: overall completion picture, how many students are on track vs delayed, and "
    . "the single most important action RPMS should take this week. Do not invent information not present in the data.";

const AI_FULL_PROMPT = "You are an assistant for a university Research Planning and Monitoring Section (RPMS). "
    . "Given structured IERB progress data for the institution's students, write a THOROUGH narrative analysis "
    . "(400-700 words) for RPMS staff, covering: distribution across IERB stages, patterns among delayed or pending "
    . "cases and likely causes, notable research groups/courses needing attention, and specific, prioritized "
    . "recommendations for RPMS follow-up this month. Do not invent information not present in the data.";

if ($action === 'ai_report') {
    $mode = trim((string)($data['mode'] ?? ''));
    if (!in_array($mode, AI_REPORT_MODES, true)) {
        json_out(['ok' => false, 'message' => 'Invalid report mode.'], 422);
    }

    $students = report_students_for_user($pdo, $user);
    if (!$students) {
        json_out(['ok' => false, 'message' => 'There are no student records yet to report on.'], 422);
    }

    $scopeText = $user['role'] === 'adviser'
        ? "Scope: only students assigned to the signed-in research adviser. Do not describe this as an institution-wide report.\n"
        : "Scope: all student records visible to RPMS administration.\n";
    $structured = $scopeText . build_structured_progress_text($students);
    $prompt = $mode === 'full' ? AI_FULL_PROMPT : AI_SUMMARY_PROMPT;
    $narrative = openrouter_generate($prompt, $structured);
    $aiUsed = $narrative !== null;
    if (!$aiUsed) {
        $narrative = $mode === 'full'
            ? local_full_narrative($students, $user['role'] === 'adviser' ? 'your assigned students' : 'the institution')
            : local_summary_narrative($students);
    }

    $scopeTitle = $user['role'] === 'adviser' ? 'Assigned Students' : 'All Students';
    $title = $mode === 'full' ? "AI Full Progress Report - $scopeTitle" : "AI Summarized Progress Report - $scopeTitle";
    $lines = [];
    $lines[] = $aiUsed
        ? ($mode === 'full' ? 'AI-ASSISTED FULL ANALYSIS' : 'AI-ASSISTED SUMMARY')
        : ($mode === 'full' ? 'LOCAL FALLBACK FULL ANALYSIS' : 'LOCAL FALLBACK SUMMARY');
    $lines[] = $aiUsed ? 'Narrative source: OpenRouter AI (' . OPENROUTER_MODEL . ')' : 'Narrative source: Local fallback summarizer (AI service unavailable)';
    $lines[] = 'Generated for review by: ' . $user['full_name'] . ' (' . ucfirst($user['role']) . '). Verify before distribution.';
    $lines[] = '';
    $lines = array_merge($lines, wrap_lines($narrative));

    if ($mode === 'full') {
        $lines[] = '';
        $lines[] = str_repeat('=', 40);
        $lines[] = 'FULL STUDENT-BY-STUDENT DETAIL';
        $lines[] = str_repeat('=', 40);
        foreach ($students as $s) {
            $lines[] = '';
            $lines = array_merge($lines, wrap_lines("{$s['full_name']} ({$s['student_id']}) - {$s['stage']} - {$s['status']}"));
            $lines = array_merge($lines, wrap_lines('Research: ' . ($s['research_title'] ?: 'Not provided')));
            $lines = array_merge($lines, wrap_lines('Adviser: ' . ($s['adviser_name'] ?: 'Unassigned')));
            $lines = array_merge($lines, wrap_lines('Pending requirements: ' . ($s['requirements'] ?: 'None')));
            $lines[] = 'Last submission: ' . ($s['last_submission_date'] ?: 'N/A');
        }
    }

    $reportId = bin2hex(random_bytes(12));
    $filename = $reportId . '.pdf';
    if (file_put_contents(REPORTS_DIR . DIRECTORY_SEPARATOR . $filename, make_pdf($title, $lines), LOCK_EX) === false) {
        json_out(['ok' => false, 'message' => 'PDF could not be generated.'], 500);
    }
    $reportType = $mode === 'full' ? 'AI Full Report' : 'AI Summarized Report';
    $pdo->prepare('INSERT INTO reports (id, title, type, filename, generated_by, generated_by_user_id) VALUES (:id,:title,:type,:file,:by,:uid)')
        ->execute([':id' => $reportId, ':title' => $title, ':type' => $reportType, ':file' => $filename, ':by' => $user['full_name'], ':uid' => $user['id']]);

    log_activity($user['email'], 'ai_report_generated', "mode=$mode ai_used=" . ($aiUsed ? '1' : '0'));
    json_out(['ok' => true, 'report' => ['id' => $reportId, 'name' => $title, 'type' => $reportType, 'generatedAt' => date(DATE_ATOM)], 'aiUsed' => $aiUsed]);
}

function build_structured_progress_text(array $students): string
{
    $lines = [];
    foreach ($students as $s) {
        $lines[] = sprintf(
            "%s (%s) | Course: %s | Adviser: %s | Stage: %s | Status: %s | Pending: %s | Last submission: %s",
            $s['full_name'], $s['student_id'], $s['course'] ?: 'N/A', $s['adviser_name'] ?: 'Unassigned',
            $s['stage'], $s['status'], $s['requirements'] ?: 'None', $s['last_submission_date'] ?: 'N/A'
        );
    }
    return implode("\n", $lines);
}

function local_summary_narrative(array $students): string
{
    $total = count($students);
    $onTrack = count(array_filter($students, fn($s) => strcasecmp($s['status'], 'On Track') === 0));
    $delayed = count(array_filter($students, fn($s) => strcasecmp($s['status'], 'Delayed') === 0));
    $pending = $total - $onTrack - $delayed;
    return "Out of {$total} monitored student(s), {$onTrack} are On Track, {$delayed} are Delayed, and {$pending} "
        . "have another pending status. RPMS should prioritize following up with the {$delayed} delayed case(s) "
        . "this week, as they represent the highest risk to on-time IERB completion. Students who have not "
        . "submitted requirements recently should be sent an automated reminder.";
}

function local_full_narrative(array $students, string $scopeLabel = 'the institution'): string
{
    $counts = [];
    $delayedNames = [];
    foreach ($students as $s) {
        $counts[$s['stage']] = ($counts[$s['stage']] ?? 0) + 1;
        if (strcasecmp($s['status'], 'Delayed') === 0) {
            $delayedNames[] = "{$s['full_name']} ({$s['student_id']}) - " . ($s['requirements'] ?: 'no requirement noted');
        }
    }
    $out = ucfirst($scopeLabel) . " currently includes " . count($students) . " monitored student(s) across the IERB process.\n\n";
    $out .= "Stage distribution:\n";
    foreach ($counts as $stage => $c) {
        $out .= "- {$stage}: {$c} student(s)\n";
    }
    $out .= "\nDelayed cases requiring follow-up (" . count($delayedNames) . "):\n";
    $out .= $delayedNames ? ('- ' . implode("\n- ", $delayedNames)) : 'None identified.';
    $out .= "\n\nRecommendation: RPMS should contact each delayed student directly this week, confirm the specific "
        . "blocking requirement, and set a resubmission deadline. Students without a recent submission date should "
        . "be flagged for a status-check email regardless of their current stage.";
    return $out;
}

if ($action === 'generate') {
    $type = trim((string)($data['type'] ?? 'Progress Report')); // Progress Report | Student Report | Document Summary
    if (!in_array($type, ['Progress Report', 'Student Report', 'Document Summary'], true)) {
        json_out(['ok' => false, 'message' => 'Invalid report type.'], 422);
    }
    $lines = [];
    $title = 'IERB Progress Report';

    if ($type === 'Document Summary') {
        $docId = trim((string)($data['documentId'] ?? ''));
        if ($docId === '') {
            json_out(['ok' => false, 'message' => 'Select a repository document first.'], 422);
        }
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
        $stmt->execute([':id' => $docId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            json_out(['ok' => false, 'message' => 'Document not found.'], 404);
        }
        if (!report_adviser_may_access_student($pdo, $user, $doc['student_id'] ? (int)$doc['student_id'] : null)) {
            json_out(['ok' => false, 'message' => 'This document belongs to a student assigned to another adviser.'], 403);
        }
        $docName = $doc['original_name'];
        $summary = $doc['ai_summary'];
        if (!$summary) {
            json_out(['ok' => false, 'message' => 'Generate an AI summary for this document first (Documents > Summarize).'], 422);
        }
        $title = 'Document Summary - ' . $docName;
        $lines = array_merge(['DOCUMENT SUMMARY', 'Document: ' . $docName, ''], wrap_lines($summary));
    } elseif ($type === 'Student Report') {
        $studentDbId = (int)($data['studentId'] ?? 0);
        if (!report_adviser_may_access_student($pdo, $user, $studentDbId)) {
            json_out(['ok' => false, 'message' => 'This student is assigned to another adviser.'], 403);
        }
        $stmt = $pdo->prepare('SELECT s.*, f.full_name AS adviser_name FROM students s
            LEFT JOIN advisers f ON f.id = s.adviser_id WHERE s.id = :id');
        $stmt->execute([':id' => $studentDbId]);
        $s = $stmt->fetch();
        if (!$s) {
            json_out(['ok' => false, 'message' => 'Student not found.'], 404);
        }
        $title = 'Student IERB Progress Report - ' . $s['full_name'];
        $lines[] = 'STUDENT IERB PROGRESS REPORT';
        $lines[] = '';
        $lines = array_merge($lines, wrap_lines("Name: {$s['full_name']} ({$s['student_id']})"));
        $lines = array_merge($lines, wrap_lines("Research group: " . ($s['research_group'] ?: 'N/A')));
        $lines = array_merge($lines, wrap_lines("Assigned adviser: " . ($s["adviser_name"] ?: "Unassigned")));
        $lines = array_merge($lines, wrap_lines("Research title: " . ($s['research_title'] ?: 'Not provided')));
        $lines[] = "Current stage: {$s['stage']}   Status: {$s['status']}";
        $lines = array_merge($lines, wrap_lines('Pending requirements: ' . ($s['requirements'] ?: 'None')));
        $lines[] = 'Last submission date: ' . ($s['last_submission_date'] ?: 'N/A');
        $lines[] = '';
        $lines[] = 'PROGRESS HISTORY';
        $hist = $pdo->prepare('SELECT * FROM ierb_history WHERE student_id = :id ORDER BY created_at DESC LIMIT 20');
        $hist->execute([':id' => $studentDbId]);
        foreach ($hist->fetchAll() as $h) {
            $lines = array_merge($lines, wrap_lines("- [{$h['created_at']}] {$h['stage']} / {$h['status']} - " . ($h['note'] ?: 'No note') . ' (' . ($h['actor'] ?: 'System') . ')'));
        }
    } else {
        $stage = trim((string)($data['stage'] ?? ''));
        if ($stage !== '' && !in_array($stage, STAGE_SEQUENCE, true)) {
            json_out(['ok' => false, 'message' => 'Invalid IERB stage for this report.'], 422);
        }
        $students = report_students_for_user($pdo, $user, $stage);

        $scopeTitle = $user['role'] === 'adviser' ? 'Assigned Students' : 'All Students';
        $title = $stage !== '' ? "IERB Progress Report - $stage" : "IERB Progress Report - $scopeTitle";
        $lines[] = 'REPORT OVERVIEW';
        $lines[] = 'Students included: ' . count($students);
        $lines[] = '';
        foreach ($students as $s) {
            $lines = array_merge($lines, wrap_lines("{$s['full_name']} ({$s['student_id']}) - {$s['stage']} - {$s['status']}"));
            $lines = array_merge($lines, wrap_lines('Research: ' . ($s['research_title'] ?: 'Not provided')));
            $lines = array_merge($lines, wrap_lines('Pending requirements: ' . ($s['requirements'] ?: 'None')));
            $lines[] = '';
        }
        if (!$students) {
            $lines[] = 'No students match this scope yet.';
        }
    }

    $reportId = bin2hex(random_bytes(12));
    $filename = $reportId . '.pdf';
    if (file_put_contents(REPORTS_DIR . DIRECTORY_SEPARATOR . $filename, make_pdf($title, $lines), LOCK_EX) === false) {
        json_out(['ok' => false, 'message' => 'PDF could not be generated.'], 500);
    }
    $pdo->prepare('INSERT INTO reports (id, title, type, filename, generated_by, generated_by_user_id) VALUES (:id,:title,:type,:file,:by,:uid)')
        ->execute([':id' => $reportId, ':title' => $title, ':type' => $type, ':file' => $filename, ':by' => $user['full_name'], ':uid' => $user['id']]);

    log_activity($user['email'], 'report_generated', "type=$type title=$title");
    json_out(['ok' => true, 'report' => ['id' => $reportId, 'name' => $title, 'type' => $type, 'generatedAt' => date(DATE_ATOM)]]);
}

$id = $_GET['id'] ?? $data['id'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id');
$stmt->execute([':id' => $id]);
$found = $stmt->fetch();

if (!$found) {
    json_out(['ok' => false, 'message' => 'Report not found.'], 404);
}
if ($user['role'] === 'adviser' && (int)($found['generated_by_user_id'] ?? 0) !== (int)$user['id']) {
    json_out(['ok' => false, 'message' => 'You are not authorized to access this report.'], 403);
}
$path = REPORTS_DIR . DIRECTORY_SEPARATOR . basename($found['filename']);

if ($action === 'file') {
    if (!is_file($path)) {
        json_out(['ok' => false, 'message' => 'File missing from storage.'], 404);
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline')
        . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $found['title']) . '.pdf"');
    readfile($path);
    exit;
}

if ($action === 'delete') {
    api_require_login('admin');
    if (is_file($path)) {
        @unlink($path);
    }
    $pdo->prepare('DELETE FROM reports WHERE id = :id')->execute([':id' => $id]);
    log_activity($user['email'], 'report_deleted', "id=$id");
    json_out(['ok' => true, 'message' => 'Report deleted.']);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
