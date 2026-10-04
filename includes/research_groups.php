<?php
require_once __DIR__ . '/academic_catalog.php';

/** Canonical prefix, derived only from validated catalog keys. */
function research_group_prefix(array $academic): string
{
    $a = academic_validate([
        'academicUnitKey' => $academic['academic_unit_key'] ?? null,
        'programKey' => $academic['program_key'] ?? null,
        'yearLevel' => $academic['year_level'] ?? null,
        'academicYear' => $academic['academic_year'] ?? null,
    ]);
    $graduate = academic_catalog()['programs'][$a['program_key']]['level'] === 'graduate';
    $year = $graduate ? '' : '-Y' . substr($a['year_level'], 0, 1);
    $ay = substr($a['academic_year'], 2, 2) . substr($a['academic_year'], 7, 2);
    return strtoupper($graduate ? 'GRAD' : $a['academic_unit_key']) . '-'
        . strtoupper(str_replace('_', '-', $a['program_key'])) . $year . '-' . $ay . '-G';
}

function research_group_number(string $group, string $prefix): ?int
{
    if (!str_starts_with($group, $prefix)) return null;
    $suffix = substr($group, strlen($prefix));
    if (!preg_match('/\A[0-9]{2,9}\z/', $suffix)) return null;
    $number = (int)$suffix;
    return $number > 0 && sprintf('%02d', $number) === $suffix ? $number : null;
}

function research_group_is_standard(array $row): bool
{
    try {
        return research_group_number((string)($row['research_group'] ?? ''), research_group_prefix($row)) !== null;
    } catch (InvalidArgumentException $e) {
        return false;
    }
}

/** Only groups represented by students in the actor's existing management scope. */
function research_group_options(PDO $pdo, array $user, ?array $academic = null): array
{
    $prefix = $academic === null ? null : research_group_prefix($academic);
    $sql = 'SELECT DISTINCT s.research_group, s.academic_unit_key, s.program_key, s.year_level, s.academic_year FROM students s';
    $params = [];
    if ($user['role'] === 'adviser') {
        $sql .= ' JOIN advisers a ON a.id = s.adviser_id WHERE a.email = :adviser';
        $params[':adviser'] = $user['email'];
    } elseif ($user['role'] !== 'admin') {
        return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        if (research_group_is_standard($row) && ($prefix === null || research_group_prefix($row) === $prefix)) {
            $groups[] = $row['research_group'];
        }
    }
    $groups = array_values(array_unique($groups));
    sort($groups, SORT_NATURAL);
    return $groups;
}

/** Current standardized groups keyed by adviser ID; legacy adviser free text is never consulted. */
function research_groups_by_adviser(PDO $pdo): array
{
    $rows = $pdo->query('SELECT DISTINCT adviser_id, research_group, academic_unit_key, program_key, year_level, academic_year
        FROM students WHERE adviser_id IS NOT NULL')->fetchAll();
    $groups = [];
    foreach ($rows as $row) {
        if (research_group_is_standard($row)) {
            $groups[(int)$row['adviser_id']][] = $row['research_group'];
        }
    }
    foreach ($groups as &$assigned) {
        $assigned = array_values(array_unique($assigned));
        sort($assigned, SORT_NATURAL);
    }
    unset($assigned);
    return $groups;
}

function research_group_assignment(PDO $pdo, array $user, array $data, array $academic, ?array $existing = null): string
{
    $selection = $data['group'] ?? $data['groupId'] ?? ($existing['research_group'] ?? '');
    if (!is_string($selection)) throw new InvalidArgumentException('Choose a research group from the list.');
    $selection = trim($selection);
    $old = (string)($existing['research_group'] ?? '');
    // Only a stored legacy value can establish this exception. No client legacy flag.
    if ($existing && !research_group_is_standard($existing)
        && ($selection === '' || $selection === '__keep__' || $selection === trim($old))) return $old;
    if ($selection === '') return '';
    $prefix = research_group_prefix($academic);
    if ($selection === '__create__') {
        // Reserve suffixes institution-wide without revealing other advisers' groups.
        $stmt = $pdo->prepare('SELECT DISTINCT research_group FROM students WHERE research_group LIKE :prefix');
        $stmt->execute([':prefix' => $prefix . '%']);
        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $group) {
            $max = max($max, research_group_number((string)$group, $prefix) ?? 0);
        }
        $group = $prefix . sprintf('%02d', $max + 1);
        if (strlen($group) > 190) throw new InvalidArgumentException('The generated group ID is too long.');
        return $group;
    }
    if (!in_array($selection, research_group_options($pdo, $user, $academic), true)) {
        throw new InvalidArgumentException('Choose an existing group in this academic cohort and your permitted scope, or create a new group.');
    }
    return $selection;
}
