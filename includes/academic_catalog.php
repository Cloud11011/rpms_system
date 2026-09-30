<?php
// Pure catalog/validation layer. Callers supply authorization and stored rows.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function academic_catalog(): array
{
    // Supplied catalog groupings; these are not claims about official departments.
    $units = [
        'amt' => ['label' => 'Accountancy / Management / Technology'],
        'elas' => ['label' => 'Education / Liberal Arts / Science'],
        'ihtm' => ['label' => 'International Hospitality Management / Tourism'],
        'dentistry' => ['label' => 'Dentistry'],
        'nursing' => ['label' => 'Nursing'],
        'pmt' => ['label' => 'Pharmacy / Medical Technology'],
        'optometry' => ['label' => 'Optometry'],
    ];
    $definitions = [
        'bsa' => ['BS in Accountancy', 'amt', 4],
        'bsit' => ['BS in Information Technology', 'amt', 4],
        'bsba_im' => ['BS in Business Administration Major in International Management', 'amt', 4],
        'ba_comm_a' => ['BA in Communication and Media Curriculum A', 'elas', 4],
        'ba_comm_b' => ['BA in Communication and Media with 21 units of Education Curriculum B', 'elas', 4],
        'bs_psychology' => ['BS in Psychology', 'elas', 4],
        'bs_psychology_education' => ['BS in Psychology (with 18 units of Education)', 'elas', 4],
        'bsned' => ['Bachelor of Special Needs Education', 'elas', 4],
        'bsned_early_childhood' => ['Bachelor of Special Needs Education Specialization in Early Childhood Education', 'elas', 4],
        'bsihm_cruise' => ['BS in International Hospitality Management Specialization in Cruise and Integrated Resort Operations', 'ihtm', 4],
        'bsihm_hotel' => ['BS in International Hospitality Management Specialization in Hotel, Restaurant and Culinary Operations', 'ihtm', 4],
        'bsittm' => ['BS in International Tourism and Travel Management', 'ihtm', 4],
        'ddm' => ['Doctor of Dental Medicine', 'dentistry', 6],
        'bsn' => ['BS in Nursing', 'nursing', 4],
        'bsmt' => ['BS in Medical Technology', 'pmt', 4],
        'bs_pharmacy' => ['BS in Pharmacy (Four-Year Program) leading to BS in Clinical Pharmacy', 'pmt', 4],
        'bs_clinical_pharmacy' => ['BS in Clinical Pharmacy (Five-Year Program) leading to Doctor of Pharmacy', 'pmt', 5],
        'doctor_optometry' => ['Doctor of Optometry', 'optometry', 6],
        'mba_thesis' => ['Master of Business Administration (Thesis Program)', null, null],
        'mba_non_thesis' => ['Master of Business Administration (Non-Thesis)', null, null],
        'mba_tqm' => ['Master of Business Administration (Total Quality Management)', null, null],
        'ms_psychology' => ['Master of Science in Psychology', null, null],
    ];
    $programs = [];
    foreach ($definitions as $key => [$label, $unit, $duration]) {
        $programs[$key] = ['label' => $label, 'unit' => $unit,
            'level' => $unit === null ? 'graduate' : 'undergraduate', 'duration' => $duration];
    }
    // Maintenance: do not casually remove historical years referenced by records.
    // Append future years unless an explicit migration/legacy-validation plan exists.
    // Explicit allowlist: no inferred rollover month or automatically active year.
    return ['units' => $units, 'programs' => $programs,
        'academicYears' => ['2025-2026', '2026-2027', '2027-2028']];
}

function academic_year_levels(string $programKey): array
{
    $program = academic_catalog()['programs'][$programKey] ?? null;
    if (!$program || $program['level'] !== 'undergraduate') return [];
    return array_slice(['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', '6th Year'], 0, $program['duration']);
}

/** Input names follow the existing camelCase JSON API; output is a stored-row tuple. */
function academic_validate(array $input, ?array $existing = null): array
{
    $fields = ['course' => 'course', 'academicUnitKey' => 'academic_unit_key',
        'programKey' => 'program_key', 'yearLevel' => 'year_level', 'academicYear' => 'academic_year'];
    $stored = [];
    $candidate = [];
    $changed = $existing === null;
    foreach ($fields as $public => $column) {
        $stored[$column] = $existing[$column] ?? null;
        $candidate[$column] = $stored[$column];
        if (!array_key_exists($public, $input)) continue;
        if ($input[$public] !== null && !is_string($input[$public])) {
            throw new InvalidArgumentException('Academic fields must be text values or null.');
        }
        $value = $input[$public] === null ? null : trim($input[$public]);
        $candidate[$column] = $value === '' ? null : $value;
        if (($candidate[$column] ?? '') !== trim((string)($stored[$column] ?? ''))) $changed = true;
    }
    // Only the locked stored row can establish unchanged legacy data; no client bypass flag.
    if (!$changed) return $stored;
    $catalog = academic_catalog();
    $program = $catalog['programs'][$candidate['program_key'] ?? ''] ?? null;
    if (!$program) throw new InvalidArgumentException('Choose a valid program from the academic catalog.');
    $unit = $candidate['academic_unit_key'];
    $year = $candidate['year_level'];
    if ($program['level'] === 'undergraduate') {
        if (!isset($catalog['units'][$unit ?? ''])) throw new InvalidArgumentException('Choose a valid academic unit.');
        if ($program['unit'] !== $unit) throw new InvalidArgumentException('The program does not belong to the selected academic unit.');
        if (!in_array($year, academic_year_levels($candidate['program_key']), true)) {
            throw new InvalidArgumentException('Choose a year level allowed for the selected program.');
        }
    } else {
        if ($unit !== null && $unit !== '') throw new InvalidArgumentException('Graduate academic units are not configured; leave the academic unit empty.');
        if ($year !== null && $year !== '') throw new InvalidArgumentException('Graduate year levels are not configured; leave the year level empty.');
        $candidate['academic_unit_key'] = null;
        $candidate['year_level'] = null;
    }
    $academicYear = $candidate['academic_year'] ?? '';
    if (!preg_match('/\A([0-9]{4})-([0-9]{4})\z/', $academicYear, $parts)
        || (int)$parts[2] !== (int)$parts[1] + 1
        || !in_array($academicYear, $catalog['academicYears'], true)) {
        throw new InvalidArgumentException('Choose an academic year from the configured list.');
    }
    if (array_key_exists('course', $input) && $candidate['course'] !== $program['label']) {
        throw new InvalidArgumentException('The program label must match the selected catalog program.');
    }
    // Duration and display labels always come from this catalog, never from request data.
    $candidate['course'] = $program['label'];
    return $candidate;
}
