<?php
/** Shared scoped SQL pagination. Callers provide trusted SQL; values stay bound. */
function prism_page_query(PDO $pdo, string $select, string $scope, array $params, string $order, array $query): array
{
    $count = $pdo->prepare('SELECT COUNT(*) ' . $scope);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $limit = isset($query['preview']) ? max(1, min(10, (int)$query['preview'])) : 10;
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
        $key = ':search' . count($parts);
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
