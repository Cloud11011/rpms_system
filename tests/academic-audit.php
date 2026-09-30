<?php
/** CLI-only pure academic catalog/validation tests. No configuration, database, or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/academic_catalog.php';

$checks = 0;
$failures = [];
function academic_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $message;
}
function academic_accept(array $input, ?array $existing, string $message): ?array
{
    try {
        $result = academic_validate($input, $existing);
        academic_check(true, $message);
        return $result;
    } catch (Throwable $error) {
        academic_check(false, $message . ' (' . get_class($error) . ': ' . $error->getMessage() . ')');
        return null;
    }
}
function academic_reject(array $input, ?array $existing, string $message): void
{
    try {
        academic_validate($input, $existing);
        academic_check(false, $message . ' (unexpected acceptance)');
    } catch (InvalidArgumentException $error) {
        academic_check($error->getMessage() !== '' && !str_contains($error->getMessage(), '<script'), $message);
    } catch (Throwable $error) {
        academic_check(false, $message . ' (wrong exception: ' . get_class($error) . ')');
    }
}

// Independent expectations from the supplied catalog, not another application catalog.
$expected = [
    'bsa' => ['amt', 'BS in Accountancy', 4],
    'bsit' => ['amt', 'BS in Information Technology', 4],
    'bsba_im' => ['amt', 'BS in Business Administration Major in International Management', 4],
    'ba_comm_a' => ['elas', 'BA in Communication and Media Curriculum A', 4],
    'ba_comm_b' => ['elas', 'BA in Communication and Media with 21 units of Education Curriculum B', 4],
    'bs_psychology' => ['elas', 'BS in Psychology', 4],
    'bs_psychology_education' => ['elas', 'BS in Psychology (with 18 units of Education)', 4],
    'bsned' => ['elas', 'Bachelor of Special Needs Education', 4],
    'bsned_early_childhood' => ['elas', 'Bachelor of Special Needs Education Specialization in Early Childhood Education', 4],
    'bsihm_cruise' => ['ihtm', 'BS in International Hospitality Management Specialization in Cruise and Integrated Resort Operations', 4],
    'bsihm_hotel' => ['ihtm', 'BS in International Hospitality Management Specialization in Hotel, Restaurant and Culinary Operations', 4],
    'bsittm' => ['ihtm', 'BS in International Tourism and Travel Management', 4],
    'ddm' => ['dentistry', 'Doctor of Dental Medicine', 6],
    'bsn' => ['nursing', 'BS in Nursing', 4],
    'bsmt' => ['pmt', 'BS in Medical Technology', 4],
    'bs_pharmacy' => ['pmt', 'BS in Pharmacy (Four-Year Program) leading to BS in Clinical Pharmacy', 4],
    'bs_clinical_pharmacy' => ['pmt', 'BS in Clinical Pharmacy (Five-Year Program) leading to Doctor of Pharmacy', 5],
    'doctor_optometry' => ['optometry', 'Doctor of Optometry', 6],
    'mba_thesis' => [null, 'Master of Business Administration (Thesis Program)', null],
    'mba_non_thesis' => [null, 'Master of Business Administration (Non-Thesis)', null],
    'mba_tqm' => [null, 'Master of Business Administration (Total Quality Management)', null],
    'ms_psychology' => [null, 'Master of Science in Psychology', null],
];
$catalog = academic_catalog();
$expectedUnitKeys = ['amt', 'elas', 'ihtm', 'dentistry', 'nursing', 'pmt', 'optometry'];
$actualUnitKeys = array_keys($catalog['units']);
sort($actualUnitKeys); sort($expectedUnitKeys);
academic_check($actualUnitKeys === $expectedUnitKeys, 'Only the seven supplied undergraduate unit keys exist.');
$actualProgramKeys = array_keys($catalog['programs']);
$expectedProgramKeys = array_keys($expected);
sort($actualProgramKeys); sort($expectedProgramKeys);
academic_check($actualProgramKeys === $expectedProgramKeys, 'All 18 undergraduate and four graduate programs exist, without invented programs.');
academic_check($catalog['academicYears'] === ['2025-2026', '2026-2027', '2027-2028'], 'Academic Year is the exact central allowlist.');
$ordinals = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', '6th Year'];
foreach ($expected as $key => [$unit, $label, $duration]) {
    $program = $catalog['programs'][$key] ?? [];
    academic_check(($program['label'] ?? null) === $label, "$key retains the complete supplied program label.");
    academic_check(($program['unit'] ?? null) === $unit, "$key belongs only to its supplied unit (or no institutional graduate unit).");
    academic_check(array_key_exists('duration', $program) && $program['duration'] === $duration, "$key has the supplied duration only.");
    academic_check(($program['level'] ?? null) === ($unit === null ? 'graduate' : 'undergraduate'), "$key has the correct program level.");
    $years = $duration === null ? [] : array_slice($ordinals, 0, $duration);
    academic_check(academic_year_levels($key) === $years, "$key generates only catalog-derived year levels.");
    if ($unit === null) {
        $result = academic_accept(['programKey' => $key, 'academicYear' => '2026-2027'], null, "$key accepts an unresolved graduate unit and year.");
        academic_check($result === ['course' => $label, 'academic_unit_key' => null, 'program_key' => $key, 'year_level' => null, 'academic_year' => '2026-2027'], "$key returns the complete canonical graduate tuple.");
        academic_reject(['programKey' => $key, 'academicYear' => '2026-2027', 'academicUnitKey' => 'amt'], null, "$key rejects an invented graduate unit assignment.");
        academic_reject(['programKey' => $key, 'academicYear' => '2026-2027', 'yearLevel' => '1st Year'], null, "$key rejects invented undergraduate graduate standing.");
        continue;
    }
    foreach ($years as $year) {
        $result = academic_accept(['academicUnitKey' => $unit, 'programKey' => $key, 'yearLevel' => $year, 'academicYear' => '2026-2027'], null, "$key accepts $year.");
        academic_check($result === ['course' => $label, 'academic_unit_key' => $unit, 'program_key' => $key, 'year_level' => $year, 'academic_year' => '2026-2027'], "$key / $year returns only the canonical database fields.");
    }
    foreach ($expectedUnitKeys as $otherUnit) {
        if ($otherUnit === $unit) continue;
        academic_reject(['academicUnitKey' => $otherUnit, 'programKey' => $key, 'yearLevel' => '1st Year', 'academicYear' => '2026-2027'], null, "$key cannot be assigned to $otherUnit.");
    }
    foreach (array_slice($ordinals, $duration) as $invalidYear) {
        academic_reject(['academicUnitKey' => $unit, 'programKey' => $key, 'yearLevel' => $invalidYear, 'academicYear' => '2026-2027'], null, "$key rejects $invalidYear beyond its duration.");
    }
}
academic_check(academic_year_levels('unknown') === [], 'Unknown program generates no year levels.');
$valid = ['academicUnitKey' => 'amt', 'programKey' => 'bsit', 'yearLevel' => '2nd Year', 'academicYear' => '2026-2027'];
$validStored = ['course' => 'BS in Information Technology', 'academic_unit_key' => 'amt', 'program_key' => 'bsit', 'year_level' => '2nd Year', 'academic_year' => '2026-2027'];
foreach (['academicUnitKey', 'programKey', 'yearLevel', 'academicYear'] as $field) {
    $missing = $valid; unset($missing[$field]);
    academic_reject($missing, null, "New undergraduate requires $field when omitted.");
    foreach ([null, ''] as $blank) {
        academic_reject(array_replace($valid, [$field => $blank]), null, "New undergraduate requires nonempty $field.");
        academic_reject([$field => $blank], $validStored, "Explicitly clearing $field cannot erase a valid stored tuple.");
    }
}
foreach (['academicUnitKey', 'programKey', 'yearLevel', 'academicYear', 'course'] as $field) {
    foreach ([[], ['unexpected'], (object)['unexpected' => 1], true, false, 1, 1.5] as $invalidType) {
        academic_reject(array_replace($valid, [$field => $invalidType]), null, "$field rejects " . get_debug_type($invalidType) . '.');
    }
}
foreach ([['academicUnitKey' => 'not-a-unit'], ['programKey' => 'not-a-program'], ['yearLevel' => '7th Year'], ['yearLevel' => '2'], ['course' => 'Arbitrary client label'], ['programKey' => 'BS in Information Technology']] as $invalid) {
    academic_reject(array_replace($valid, $invalid), null, 'Unknown or arbitrary academic input is rejected: ' . array_key_first($invalid) . '.');
}
foreach (['2025-2026', '2026-2027', '2027-2028'] as $year) {
    $result = academic_accept(array_replace($valid, ['academicYear' => $year]), null, "$year is accepted from the central allowlist.");
    academic_check(($result['academic_year'] ?? null) === $year, "$year is returned without selecting a different active year.");
}
foreach (['2026', '26-27', '2026/2027', '2026-2028', '2026-2025', '2024-2025', '2028-2029', '2026-2027suffix', '2026-2027\n', '02026-02027'] as $year) {
    academic_reject(array_replace($valid, ['academicYear' => $year]), null, "Malformed, nonconsecutive, or unlisted year $year is rejected.");
}
foreach (['duration', 'programDuration', 'program_duration'] as $durationField) {
    academic_reject(array_replace($valid, ['yearLevel' => '6th Year', $durationField => 6]), null, "$durationField cannot extend BSIT to six years.");
    $result = academic_accept(array_replace($valid, [$durationField => 1]), null, "$durationField cannot shorten the catalog duration either.");
    academic_check($result === $validStored, "$durationField is ignored rather than stored.");
}
$graduate = ['programKey' => 'mba_thesis', 'academicYear' => '2026-2027'];
foreach (['programKey', 'academicYear'] as $field) {
    $missing = $graduate; unset($missing[$field]);
    academic_reject($missing, null, "New graduate requires $field.");
}
$gradEmpty = academic_accept(array_replace($graduate, ['academicUnitKey' => '', 'yearLevel' => '']), null, 'Graduate unit/year may be empty input.');
academic_check(is_array($gradEmpty) && array_key_exists('academic_unit_key', $gradEmpty) && array_key_exists('year_level', $gradEmpty) && $gradEmpty['academic_unit_key'] === null && $gradEmpty['year_level'] === null, 'Empty graduate unit/year normalize to database NULL.');

// Legacy preservation comes from comparison with the stored row, never a client bypass.
$legacyNull = ['course' => 'Legacy free-text course / specialisation', 'academic_unit_key' => null, 'program_key' => null, 'year_level' => null, 'academic_year' => null];
$legacyEmpty = ['course' => 'Original legacy course', 'academic_unit_key' => '', 'program_key' => '', 'year_level' => '', 'academic_year' => ''];
$legacyMixed = ['course' => '  Preserve spaces exactly  ', 'academic_unit_key' => null, 'program_key' => '', 'year_level' => 'Historical standing', 'academic_year' => null];
foreach (['NULL' => $legacyNull, 'empty' => $legacyEmpty, 'mixed' => $legacyMixed, 'complete' => $validStored] as $kind => $stored) {
    foreach ([[], ['name' => 'Unrelated student edit'], ['legacy' => true], ['duration' => 99]] as $unrelated) {
        $result = academic_accept($unrelated, $stored, "$kind stored tuple permits an unrelated edit.");
        academic_check($result === $stored, "$kind stored tuple retains every original academic value exactly.");
    }
    $echo = ['course' => $stored['course'], 'academicUnitKey' => $stored['academic_unit_key'], 'programKey' => $stored['program_key'], 'yearLevel' => $stored['year_level'], 'academicYear' => $stored['academic_year']];
    $result = academic_accept($echo, $stored, "$kind stored tuple permits unchanged fields echoed by an edit form.");
    academic_check($result === $stored, "$kind unchanged echo returns original stored values.");
    $result = academic_accept(['course' => '  ' . trim($stored['course']) . '  '], $stored, "$kind whitespace-equivalent label remains unchanged.");
    academic_check($result === $stored, "$kind whitespace comparison does not rewrite the original course.");
}
foreach ([[$legacyNull, ''], [$legacyEmpty, null]] as [$stored, $oppositeBlank]) {
    $result = academic_accept(['academicUnitKey' => $oppositeBlank, 'programKey' => $oppositeBlank, 'yearLevel' => $oppositeBlank, 'academicYear' => $oppositeBlank], $stored, 'NULL and empty input are equivalent incomplete legacy values.');
    academic_check($result === $stored, 'Blank-equivalent input preserves the actual stored NULL/empty values.');
}
foreach ([['course' => 'New arbitrary course'], ['academicYear' => '2026-2027'], ['programKey' => 'bsit'], ['academicUnitKey' => 'amt'], ['yearLevel' => '1st Year'], ['course' => null]] as $changed) {
    academic_reject($changed, $legacyNull, 'Intentional legacy academic change requires a complete valid tuple: ' . array_key_first($changed) . '.');
}
academic_reject(['legacy' => true], null, 'Client legacy=true cannot bypass new record requiredness.');
academic_reject(['legacy' => true, 'programKey' => 'arbitrary'], $legacyNull, 'Client legacy=true cannot bypass an intentional change.');
academic_reject(['course' => []], $legacyNull, 'Malformed type cannot bypass validation on a legacy record.');
$upgraded = academic_accept($valid, $legacyNull, 'Legacy record can be explicitly upgraded to a complete catalog tuple.');
academic_check($upgraded === $validStored, 'Intentional legacy upgrade derives the canonical program label.');
$changedYear = academic_accept(['yearLevel' => '3rd Year'], $validStored, 'Omitted fields merge from a complete existing tuple.');
academic_check($changedYear === array_replace($validStored, ['year_level' => '3rd Year']), 'Changing year retains all other academic values.');
$changedProgram = academic_accept(['programKey' => 'bsa'], $validStored, 'Changing only a compatible program derives its canonical label.');
academic_check($changedProgram === array_replace($validStored, ['program_key' => 'bsa', 'course' => 'BS in Accountancy']), 'Program change cannot retain the previous program label.');
academic_reject(['programKey' => 'bsa', 'course' => $validStored['course']], $validStored, 'Explicit mismatched old course label is rejected during a program change.');
academic_reject(['academicUnitKey' => 'dentistry'], $validStored, 'Changing unit alone cannot retain an incompatible program.');
$longResult = academic_accept(['academicUnitKey' => 'ihtm', 'programKey' => 'bsihm_hotel', 'yearLevel' => '4th Year', 'academicYear' => '2027-2028'], null, 'Long supplied program label validates.');
academic_check(strlen($expected['bsihm_hotel'][1]) >= 102 && ($longResult['course'] ?? null) === $expected['bsihm_hotel'][1], 'Validation returns the full 102+ character program without truncation (database storage is tested in later batches).');
$hostile = '<script>alert(1)</script>';
foreach (['academicUnitKey', 'programKey', 'yearLevel', 'academicYear', 'course'] as $field) {
    academic_reject(array_replace($valid, [$field => $hostile]), null, "$field rejects hostile new academic text without reflecting script markup.");
}
$hostileLegacy = array_replace($legacyNull, ['course' => $hostile]);
$result = academic_accept(['name' => 'Unrelated update'], $hostileLegacy, 'Legacy hostile-looking course is preserved for safe display by callers.');
academic_check($result === $hostileLegacy, 'Pure validation does not destroy legacy data; rendering escape is an integration responsibility.');

foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
echo 'Academic catalog/validation: ' . ($checks - count($failures)) . '/' . $checks . " checks passed.\n";
echo "G1 only: API authorization/persistence, migration, JSON export, and browser tests belong to their implementation batches.\n";
exit($failures === [] ? 0 : 1);
