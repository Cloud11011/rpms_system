<?php
/** Only explicit, bounded page sizes are accepted by record browsers. */
function prism_page_size(array $query): int
{
    $requested = $query['perPage'] ?? 10;
    return is_scalar($requested) && in_array((string)$requested, ['10', '25', '50', '100'], true) ? (int)$requested : 10;
}

/** GET controls for server-rendered history pages, preserving the other view state. */
function prism_page_controls(array $page, array $query, string $pageKey, string $sizeKey): void
{
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    echo '<div class="prism-record-footer"><form method="get"><label class="prism-row-size">Rows per page: <select name="' . $escape($sizeKey) . '">';
    foreach ([10, 25, 50, 100] as $size) echo '<option' . ($size === $page['limit'] ? ' selected' : '') . '>' . $size . '</option>';
    echo '</select></label>';
    foreach ($query as $key => $value) if ($key !== $pageKey && $key !== $sizeKey && is_scalar($value)) {
        echo '<input type="hidden" name="' . $escape($key) . '" value="' . $escape($value) . '">';
    }
    echo '<button class="prism-btn" type="submit">Apply rows</button></form><nav class="prism-pagination" aria-label="History pages">';
    $pages = max(1, (int)ceil($page['total'] / $page['limit']));
    foreach (['Previous' => $page['page'] - 1, 'Next' => $page['page'] + 1] as $label => $target) {
        if ($target < 1 || $target > $pages) echo '<button type="button" disabled>' . $label . '</button>';
        else echo '<a class="prism-btn" href="?' . $escape(http_build_query(array_merge($query, [$pageKey => $target]))) . '">' . $label . '</a>';
    }
    echo '</nav><p>Showing ' . ($page['total'] ? (($page['page'] - 1) * $page['limit'] + 1) : 0) . '–' . min($page['total'], $page['page'] * $page['limit']) . ' of ' . $page['total'] . ' records</p></div>';
}

/** Shared scoped SQL pagination. Callers provide trusted SQL; values stay bound. */
function prism_page_query(PDO $pdo, string $select, string $scope, array $params, string $order, array $query): array
{
    $count = $pdo->prepare('SELECT COUNT(*) ' . $scope);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $limit = isset($query['preview']) ? max(1, min(10, (int)$query['preview'])) : prism_page_size($query);
    $page = isset($query['preview']) ? 1 : max(1, min(max(1, (int)ceil($total / $limit)), (int)($query['page'] ?? 1)));
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare($select . ' ' . $scope . ' ORDER BY ' . $order . " LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit];
}

/** Literal, case-insensitive substring search (wildcards in user input stay literal). */
function prism_search_clause(array $columns, string $term, array &$params): string
{
    $parts = [];
    foreach ($columns as $column) {
        $key = ':search' . count($params);
        $parts[] = "LOWER(COALESCE($column, '')) LIKE $key ESCAPE '!'";
        $params[$key] = '%' . strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/** Same recipient scope for student lists, counts, selectors and aggregate previews. */
function prism_student_scope(array $user): array
{
    $scope = 'FROM students s LEFT JOIN advisers f ON f.id = s.adviser_id WHERE 1=1';
    $params = [];
    if ($user['role'] === 'adviser') {
        $scope .= ' AND f.email = :viewer AND s.archived_at IS NULL';
        $params[':viewer'] = $user['email'];
    } elseif ($user['role'] === 'student') {
        $scope .= ' AND s.email = :viewer';
        $params[':viewer'] = $user['email'];
    }
    return [$scope, $params];
}

/** Current operations only; administrative Student Records retain their historical scope. */
function prism_operational_student_scope(array $user): array
{
    [$scope, $params] = prism_student_scope($user);
    return [$scope . ' AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL', $params];
}
