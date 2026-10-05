<?php
/** Scoped filter choices and fixed SQL columns shared by student record lists. */
function prism_student_filter_options(PDO $pdo, string $scope, array $params): array
{
    $columns = ['academicUnitKey'=>'s.academic_unit_key', 'programKey'=>'s.program_key',
        'academicYear'=>'s.academic_year', 'yearLevel'=>'s.year_level', 'group'=>'s.research_group',
        'stage'=>'s.stage', 'status'=>'s.status'];
    $catalog = academic_catalog(); $options = [];
    foreach ($columns as $key => $column) {
        $stmt = $pdo->prepare("SELECT DISTINCT $column AS value $scope ORDER BY value");
        $stmt->execute($params); $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($key === 'stage') $values = array_merge(STAGE_SEQUENCE, $values);
        if ($key === 'status') $values = array_merge(['On Track','Pending','Delayed'], $values);
        $options[$key] = [];
        foreach (array_unique(array_map(fn($v) => trim((string)$v), $values)) as $value) {
            $label = $value === '' ? 'Not recorded' : $value;
            if ($key === 'academicUnitKey') $label = $catalog['units'][$value]['label'] ?? $label;
            if ($key === 'programKey') $label = $catalog['programs'][$value]['label'] ?? $label;
            if ($key === 'stage' && $value !== '') $label = $value . ' - ' . stage_label($value);
            $options[$key][] = ['value'=>$value === '' ? '__blank__' : $value, 'label'=>$label];
        }
    }
    $stmt = $pdo->prepare('SELECT DISTINCT s.adviser_id AS value, f.full_name AS label ' . $scope . ' ORDER BY label, value');
    $stmt->execute($params);
    $options['adviserId'] = array_map(fn($r) => ['value'=>$r['value'] === null ? '__blank__' : (string)$r['value'],
        'label'=>$r['label'] ?: 'Unassigned'], $stmt->fetchAll());
    $options['protocol'] = [['value'=>'present','label'=>'Protocol assigned'], ['value'=>'missing','label'=>'No protocol code']];
    return $options;
}

/** Values must belong to the authorized options; filtering cannot replace authorization. */
function prism_apply_student_filters(string &$scope, array &$params, array $query, array $options): void
{
    $columns = ['academicUnitKey'=>'s.academic_unit_key', 'programKey'=>'s.program_key',
        'academicYear'=>'s.academic_year', 'yearLevel'=>'s.year_level', 'group'=>'s.research_group',
        'adviserId'=>'s.adviser_id', 'stage'=>'s.stage', 'status'=>'s.status', 'course'=>'s.course'];
    foreach ($columns as $key => $column) {
        $value = $query[$key] ?? '';
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > 250) json_out(['ok'=>false,'message'=>'Invalid record filter.'], 422);
        $value = trim($value);
        if ($value === '') continue;
        // The established course filter is bound literally, including legacy course labels.
        if ($key !== 'course' && !in_array($value, array_column($options[$key], 'value'), true)) {
            json_out(['ok'=>false,'message'=>'Choose a filter from the permitted records.'], 422);
        }
        if ($value === '__blank__') $scope .= " AND ($column IS NULL OR TRIM($column) = '')";
        else {
            $matchColumn = $key === 'adviserId' ? $column : "TRIM($column)";
            $scope .= " AND $matchColumn = :filter_$key"; $params[":filter_$key"] = $value;
        }
    }
    $protocol = $query['protocol'] ?? '';
    if (!is_string($protocol) || !in_array($protocol, ['', 'present', 'missing'], true)) {
        json_out(['ok'=>false,'message'=>'Invalid protocol filter.'], 422);
    }
    if ($protocol !== '') $scope .= $protocol === 'present'
        ? " AND s.protocol_code IS NOT NULL AND TRIM(s.protocol_code) <> ''"
        : " AND (s.protocol_code IS NULL OR TRIM(s.protocol_code) = '')";
}

function prism_record_search(array $query): string
{
    $value = $query['q'] ?? '';
    if (!is_string($value)) json_out(['ok'=>false,'message'=>'Invalid search value.'], 422);
    return trim($value);
}

/** Only caller-defined columns and ASC/DESC may enter an ORDER BY clause. */
function prism_record_order(array $query, array $columns, string $fallback, string $tie): string
{
    $key = $query['sortBy'] ?? '';
    $rawDirection = $query['direction'] ?? 'ASC';
    $direction = is_string($rawDirection) ? strtoupper($rawDirection === '' ? 'ASC' : $rawDirection) : '';
    if (!is_string($key) || !isset($columns[$key]) || !in_array($direction, ['ASC','DESC'], true)) {
        return $columns[$fallback] . ' ASC, ' . $tie;
    }
    return $columns[$key] . ' ' . $direction . ', ' . $tie;
}
