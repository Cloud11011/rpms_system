<?php
/** Actual endpoint bodies with isolated PDO/identity/mail fixtures; never connects to MySQL. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/academic_catalog.php';
require_once __DIR__ . '/../includes/account_retention.php';
$checks = 0;
function api_check(bool $condition, string $label): void {
    ++$GLOBALS['checks'];
    if (!$condition) throw new RuntimeException($label);
}
function academic_endpoint(array $case): array {
    $case += ['delivery' => ['ok' => false, 'channel' => 'none']];
    $process = proc_open([PHP_BINARY, __DIR__ . '/crud-audit.php', '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated endpoint.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $result = json_decode($out, true);
    api_check($exit === 0 && $err === '' && is_array($result) && $result['unexpected'] === '', 'Endpoint runs without warnings: ' . $err . $out);
    api_check(!$result['transaction'], 'Endpoint always closes transaction');
    return $result;
}
$valid = ['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '2nd Year', 'academicYear' => '2026-2027'];
$bindings = [':course' => 'course', ':academic_unit' => 'academic_unit_key', ':program' => 'program_key', ':year_level' => 'year_level', ':academic_year' => 'academic_year'];
$files = ['students_api.php', 'ierb_api.php'];
foreach ($files as $file) {
    foreach (academic_catalog()['programs'] as $key => $program) {
        foreach ([true, false] as $create) {
            $input = ['academicUnitKey' => $program['unit'], 'programKey' => $key,
                'yearLevel' => $program['duration'] ? '1st Year' : null, 'academicYear' => '2027-2028', 'duration' => 99];
            $r = academic_endpoint(compact('file', 'create') + ['academicInput' => $input]);
            api_check($r['status'] === 200 && $r['commits'] === 1, "$file accepts $key create/edit");
            $studentWrites = array_values(array_filter($r['writes'], fn($write)=>str_starts_with($write['sql'],'INSERT INTO students') || str_starts_with($write['sql'],'UPDATE students')));
            $p = $studentWrites[0]['params'];
            api_check($p[':course'] === $program['label'] && $p[':program'] === $key && $p[':academic_unit'] === $program['unit']
                && $p[':year_level'] === $input['yearLevel'] && $p[':academic_year'] === '2027-2028', 'All academic columns bind canonical complete values');
            api_check(str_contains($studentWrites[0]['sql'], 'academic_year'), 'Academic fields included in SQL');
            if ($create) api_check($r['response']['setupPending'] && !isset($r['response']['temporaryPassword']), 'Production setup fallback remains safe');
        }
    }
    $bad = [[], $valid + ['course' => 'Arbitrary degree'], array_replace($valid, ['academicUnitKey' => 'nursing']),
        array_replace($valid, ['programKey' => '<script>alert(1)</script>']), array_replace($valid, ['academicUnitKey' => 'unknown']),
        array_replace($valid, ['yearLevel' => '6th Year', 'duration' => 6]), array_replace($valid, ['academicYear' => '2026-2028']),
        array_replace($valid, ['academicYear' => '2030-2031']), array_replace($valid, ['academicYear' => '2026/2027'])];
    foreach (array_keys($valid) as $field) {
        $omitted = $valid; unset($omitted[$field]); $bad[] = $omitted;
        foreach ([null, '', [], ['value' => 'x'], 12, true] as $value) $bad[] = array_replace($valid, [$field => $value]);
    }
    foreach ($bad as $input) {
        $r = academic_endpoint(compact('file') + ['create' => true, 'academicInput' => $input]);
        api_check($r['status'] === 422 && !$r['writes'] && !$r['effects'] && !$r['audit'] && !$r['notifications'], 'Invalid academic input rejected before writes or side effects');
    }
    foreach ([null, '', '  Legacy <img src=x onerror=alert(1)> course  ', str_repeat('Legacy degree ', 20)] as $course) {
        foreach ([null, ''] as $empty) {
            $stored = ['course' => $course, 'academic_unit_key' => $empty, 'program_key' => $empty, 'year_level' => $empty, 'academic_year' => $empty];
            foreach ([[], ['course' => $course]] as $input) {
                $r = academic_endpoint(compact('file') + ['storedAcademic' => $stored, 'academicInput' => $input]);
                api_check($r['status'] === 200, 'Unrelated legacy edit accepted');
                foreach ($bindings as $param => $column) api_check($r['writes'][0]['params'][$param] === $stored[$column], 'Legacy tuple preserved exactly: ' . $column);
            }
        }
    }
    $stored = ['course' => 'BS in Information Technology', 'academic_unit_key' => 'amt', 'program_key' => 'bsit', 'year_level' => '2nd Year', 'academic_year' => '2026-2027'];
    $r = academic_endpoint(compact('file') + ['storedAcademic' => $stored, 'academicInput' => []]);
    foreach ($bindings as $param => $column) api_check($r['writes'][0]['params'][$param] === $stored[$column], 'Complete tuple survives unrelated edit');
    foreach (['course', 'academicUnitKey', 'programKey', 'yearLevel', 'academicYear'] as $field) {
        $r = academic_endpoint(compact('file') + ['storedAcademic' => $stored, 'academicInput' => [$field => null, 'legacy' => true]]);
        api_check($r['status'] === 422 && !$r['writes'], 'Explicit clear cannot use client legacy bypass');
    }
    $r = academic_endpoint(compact('file') + ['missing' => true, 'academicInput' => ['programKey' => []]]);
    api_check($r['status'] === 404 && !$r['writes'], 'Not-found takes precedence on locked row');
    $r = academic_endpoint(compact('file') + ['role' => 'student', 'academicInput' => $valid]);
    api_check($r['status'] === 403 && !$r['writes'], 'Student role cannot mutate academics');
    if ($file === 'students_api.php') {
        $r = academic_endpoint(compact('file') + ['role' => 'adviser', 'academicInput' => $valid]);
        api_check($r['status'] === 200 && $r['writes'][0]['params'][':adv'] === 7 && $r['writes'][0]['params'][':pcode'] === 'FIXTURE'
            && $r['writes'][0]['params'][':pi'] === 1, 'Assigned adviser keeps authority and protected fields');
        $r = academic_endpoint(compact('file') + ['role' => 'adviser', 'reassigned' => true, 'academicInput' => $valid]);
        api_check($r['status'] === 403 && !$r['writes'], 'Reassigned student is forbidden under lock');
    } else {
        $r = academic_endpoint(compact('file') + ['role' => 'adviser', 'academicInput' => $valid]);
        api_check($r['status'] === 403 && !$r['writes'], 'IERB provisioning remains admin-only');
    }
}

// Actual pure response serializers, without executing endpoint bootstrap.
function stage_label(string $stage): string { return $stage; }
function stage_progress_percent(string $stage): int { return 20; }
function empty_doc_counts(): array { return []; }
$baseRow = ['profile_completed_at'=>'2026-09-30 00:00:00','id' => 11, 'student_id' => 'S11', 'full_name' => 'Fixture Student', 'email' => 'fixture@example.test',
    'research_title' => 'Fixture research', 'research_group' => 'G11', 'adviser_id' => 7, 'stage' => 'Stage 1',
    'status' => 'On Track', 'requirements' => '', 'last_submission_date' => null, 'created_at' => '2026-09-30', 'updated_at' => '2026-09-30'];
$responseRows = [];
foreach (academic_catalog()['programs'] as $key => $program) {
    $responseRows[] = $baseRow + ['course' => $program['label'], 'academic_unit_key' => $program['unit'], 'program_key' => $key,
        'year_level' => $program['duration'] ? '1st Year' : null, 'academic_year' => '2026-2027'];
}
foreach ([null, '', 'Legacy course', '<img src=x onerror=alert(1)>'] as $value) {
    $responseRows[] = $baseRow + ['course' => $value, 'academic_unit_key' => $value, 'program_key' => $value, 'year_level' => $value, 'academic_year' => $value];
}
$mapping = ['course' => 'course', 'academicUnitKey' => 'academic_unit_key', 'programKey' => 'program_key', 'yearLevel' => 'year_level', 'academicYear' => 'academic_year'];
foreach (['students_api.php' => 'row_to_student', 'ierb_api.php' => 'ierb_row'] as $file => $function) {
    $source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/' . $file));
    $start = strpos($source, 'function ' . $function . '(');
    $end = $start === false ? false : strpos($source, "\n}", $start);
    api_check($start !== false && $end !== false, 'Expected response serializer declaration exists');
    $declaration = substr($source, $start, $end + 2 - $start);
    api_check(!preg_match('/\b(?:require|include)(?:_once)?\s*(?:\(|[\'"$])/', $declaration), 'Serializer fixture cannot load configuration');
    eval($declaration);
    foreach ($responseRows as $row) {
        $response = json_decode(json_encode($function($row), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        foreach ($mapping as $public => $column) api_check(array_key_exists($public, $response) && $response[$public] === $row[$column], "$file response preserves $column through JSON");
        api_check($response['id'] === 11 && $response['studentId'] === 'S11' && $response['stage'] === 'Stage 1' && $response['status'] === 'On Track', 'Existing response identifiers/workflow fields remain intact');
    }
}

echo "PASS: $checks isolated academic API checks. No live database, mail or application bootstrap.\n";
