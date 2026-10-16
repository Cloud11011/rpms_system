<?php
/** Student-visible actions are classified explicitly; stored free text is never returned. */
function student_activity_actions(): array
{
    return [
        'login_success' => ['Signed in', 'You signed in to PRISM.'],
        'logout' => ['Signed out', 'You signed out of PRISM.'],
        'profile_updated' => ['Profile updated', 'Your permitted profile information was updated.'],
        'password_changed' => ['Password changed', 'Your account password was changed.'],
        'password_reset_completed' => ['Password reset', 'Your account password was reset.'],
        'document_resubmitted_to_rpms' => ['Document resubmitted', 'A revised document was formally resubmitted to RPMS.'],
        'password_reset' => ['Password reset', 'Your account password was reset.'],
        'document_uploaded' => ['Document uploaded', 'A document was uploaded for your research record.'],
        'document_approved' => ['Document approved', 'A document was approved. Review your documents for its official state.'],
        'document_denied' => ['Document denied', 'A document was denied. Review your documents for feedback.'],
        'document_resubmission_requested' => ['Resubmission requested', 'A document needs resubmission. Review your documents for instructions.'],
        'stage_auto_advanced' => ['Progress updated', 'Your official IERB stage advanced.'],
        'document_reviewed' => ['Document reviewed', 'A document review was recorded. Review your documents for feedback.'],
        'document_submitted_to_rpms' => ['Document submitted', 'A document was formally submitted to RPMS.'],
        'document_returned_for_revision' => ['Revision requested', 'A document was returned for revision. Review your documents for instructions.'],
        'student_progress_changed' => ['Progress updated', 'Your official IERB progress was updated.'],
        'ierb_saved' => ['Progress recorded', 'Your IERB record was updated.'],
    ];
}

function student_activity_read(PDO $pdo, array $user, array $query): array
{
    if (($user['role'] ?? '') !== 'student') throw new LogicException('Student activity projection requires a Student.');
    foreach (['page','studentId','action_code','override','from','to','q'] as $key) {
        if (isset($query[$key]) && !is_scalar($query[$key])) throw new InvalidArgumentException('Invalid activity filter.');
    }
    $actions = student_activity_actions();
    $params = [':uid' => (int)$user['id'], ':email' => (string)$user['email'], ':ref' => (string)($user['ref_id'] ?? '')];
    // Current profile linkage wins; legacy fallback cannot claim a profile linked to another user.
    $own = 'SELECT s.id FROM students s WHERE s.archived_at IS NULL
        AND (s.user_id = :uid OR (s.user_id IS NULL AND s.email = :email AND s.student_id = :ref))';
    // Email addresses can be reused. Account events need their immutable account ID;
    // an unbound historical email alone cannot establish ownership of an event.
    $where = ['(l.student_id IN (' . $own . ') OR (l.student_id IS NULL AND l.user_email = :actor_email
        AND l.entity_type = \'user\' AND l.entity_id = :account_id))', 'l.is_override = 0'];
    $params[':actor_email'] = (string)$user['email'];
    $params[':account_id'] = (string)$user['id'];
    $keys = []; $safeText = 'CASE l.action';
    foreach ($actions as $code => [$label, $description]) {
        $index = count($keys); $key = ':action_' . $index; $keys[] = $key; $params[$key] = $code;
        $safeText .= ' WHEN :search_action_' . $index . ' THEN :safe_' . $index;
        $params[':search_action_' . $index] = $code;
        $params[':safe_' . $index] = $label . ' ' . $description;
    }
    $safeText .= ' ELSE NULL END';
    $where[] = 'l.action IN (' . implode(',', $keys) . ')';
    $code = trim((string)($query['action_code'] ?? ''));
    if ($code !== '') {
        $where[] = 'l.action = :code'; $params[':code'] = $code;
    }
    if (($query['override'] ?? '') === '1') $where[] = '1 = 0';
    if ((int)($query['studentId'] ?? 0) > 0) { $where[] = 'l.student_id = :sid'; $params[':sid'] = (int)$query['studentId']; }
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        $date = trim((string)($query[$key] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'l.created_at ' . $operator . ' :' . $key;
            $params[':' . $key] = $date . ($key === 'from' ? ' 00:00:00' : ' 23:59:59');
        }
    }
    $q = trim((string)($query['q'] ?? ''));
    if ($q !== '') { $where[] = '(' . $safeText . ') LIKE :safe_search'; $params[':safe_search'] = '%' . $q . '%'; }
    else {
        // Unused named parameters are not accepted by all PDO drivers.
        foreach (array_keys($params) as $key) if (str_starts_with($key, ':safe_') || str_starts_with($key, ':search_action_')) unset($params[$key]);
    }
    $scope = ' FROM activity_logs l WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*)' . $scope); $count->execute($params);
    $total = (int)$count->fetchColumn(); $limit = 10; $pages = max(1, (int)ceil($total / $limit));
    $page = max(1, min($pages, (int)($query['page'] ?? 1))); $offset = ($page - 1) * $limit;
    $statement = $pdo->prepare('SELECT l.created_at, l.action' . $scope . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $statement->execute($params);
    $entries = array_map(static function (array $row) use ($actions): array {
        [$label, $description] = $actions[$row['action']];
        return ['at' => $row['created_at'], 'action' => $row['action'], 'actionLabel' => $label, 'details' => $description];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
    return ['ok' => true, 'entries' => $entries, 'limit' => $limit, 'offset' => $offset, 'page' => $page, 'pages' => $pages, 'total' => $total];
}
