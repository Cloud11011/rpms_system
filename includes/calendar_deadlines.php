<?php
/** Canonical official deadlines. Configuration/authentication are loaded by the caller. */
require_once __DIR__ . '/research_groups.php';
require_once __DIR__ . '/notification_delivery.php';
require_once __DIR__ . '/pagination.php';

function deadline_date($value): string
{
    if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m)
        || (int)$m[1] < 1000 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('Choose a valid deadline date.');
    }
    return $value;
}

function deadline_id($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$value)
        || (int)$value > 2147483647) throw new InvalidArgumentException('Invalid deadline ID.');
    return (int)$value;
}

/** Recipient detail labels use the same current assignment as deadline visibility. */
function deadline_viewer_groups(PDO $pdo, array $user): array
{
    if ($user['role'] === 'adviser') return research_group_options($pdo, $user);
    if ($user['role'] !== 'student') return [];
    $stmt = $pdo->prepare('SELECT research_group, academic_unit_key, program_key, year_level, academic_year
        FROM students WHERE email = :email AND archived_at IS NULL');
    $stmt->execute([':email' => $user['email']]);
    $student = $stmt->fetch();
    return $student && research_group_is_standard($student) ? [$student['research_group']] : [];
}

/** Use the exact same visibility scope for rows, counts and calendar markers. */
function deadline_scope(PDO $pdo, array $user, bool $manage = false): array
{
    $where = 'FROM calendar_deadlines d WHERE 1=1'; $params = [];
    if ($manage) {
        if ($user['role'] === 'student') throw new DomainException('Students cannot manage official deadlines.');
        if ($user['role'] === 'adviser') { $where .= ' AND d.creator_user_id = :creator'; $params[':creator'] = $user['id']; }
        return [$where, $params];
    }
    $where .= " AND d.status = 'Active'";
    if ($user['role'] === 'admin') return [$where, $params];
    $adminCreated = "EXISTS (SELECT 1 FROM users creator WHERE creator.id = d.creator_user_id AND creator.role = 'admin')";
    if ($user['role'] === 'adviser') {
        $groups = research_group_options($pdo, $user);
        $where .= ' AND (' . $adminCreated . ' OR d.creator_user_id = :creator)';
        $params[':creator'] = $user['id'];
    } else {
        $groups = deadline_viewer_groups($pdo, $user);
        $where .= ' AND (' . $adminCreated . " OR EXISTS (SELECT 1 FROM students own_student
            JOIN advisers a ON a.id = own_student.adviser_id JOIN users creator ON creator.email = a.email
            JOIN calendar_deadline_recipients r ON r.student_id = own_student.id AND r.deadline_id = d.id
            WHERE own_student.email = :viewer AND own_student.archived_at IS NULL
              AND creator.id = d.creator_user_id AND creator.role = 'adviser'))";
        $params[':viewer'] = $user['email'];
    }
    $parts = ["d.target_scope = 'all' AND " . $adminCreated];
    if ($groups) {
        $keys = [];
        foreach ($groups as $i => $group) { $keys[] = ':scope' . $i; $params[':scope' . $i] = $group; }
        $parts[] = 'EXISTS (SELECT 1 FROM calendar_deadline_groups g WHERE g.deadline_id = d.id
            AND BINARY g.research_group IN (' . implode(',', $keys) . '))';
    }
    return [$where . ' AND (' . implode(' OR ', $parts) . ')', $params];
}

/** Original recipients are captured in the canonical creation transaction. */
function deadline_snapshot_recipients(PDO $pdo, array $user, int $id, string $target, array $groups): void
{
    $sql = 'SELECT s.id FROM students s LEFT JOIN advisers a ON a.id = s.adviser_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL';
    $params = [];
    if ($user['role'] === 'adviser') { $sql .= ' AND a.email = :adviser'; $params[':adviser'] = $user['email']; }
    if ($target === 'groups') {
        $keys = [];
        foreach ($groups as $i => $group) { $keys[] = ':g'.$i; $params[':g'.$i] = $group; }
        $sql .= ' AND BINARY s.research_group IN (' . implode(',', $keys) . ')';
    }
    $q = $pdo->prepare($sql); $q->execute($params);
    $insert = $pdo->prepare('INSERT INTO calendar_deadline_recipients (deadline_id, student_id) VALUES (:id, :student)');
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $student) $insert->execute([':id' => $id, ':student' => $student]);
}

/** Called inside the creation transaction. Lock assignments before resolving authorized groups. */
function deadline_targets(PDO $pdo, array $user, array $data): array
{
    $target = $data['target'] ?? '';
    $requested = $data['groups'] ?? [];
    if (!in_array($target, ['all', 'selected'], true) || !is_array($requested)
        || array_values($requested) !== $requested) throw new InvalidArgumentException('Choose a valid target.');
    foreach ($requested as $group) {
        if (!is_string($group) || $group === '' || strlen($group) > 190) {
            throw new InvalidArgumentException('Choose research groups from the list.');
        }
    }
    if ($target === 'all' && $requested) throw new InvalidArgumentException('All groups cannot include a selected group list.');
    if ($user['role'] === 'adviser') {
        $lock = $pdo->prepare('SELECT s.id FROM students s JOIN advisers a ON a.id = s.adviser_id
            WHERE a.email = :email FOR UPDATE');
        $lock->execute([':email' => $user['email']]);
        $lock->fetchAll();
    } elseif ($target === 'all') {
        return ['all', []];
    } elseif ($requested) {
        $keys = []; $params = [];
        foreach (array_values(array_unique($requested)) as $i => $group) {
            $keys[] = ':lock' . $i; $params[':lock' . $i] = $group;
        }
        $lock = $pdo->prepare('SELECT id FROM students WHERE BINARY research_group IN (' . implode(',', $keys) . ') FOR UPDATE');
        $lock->execute($params); $lock->fetchAll();
    }
    $allowed = research_group_options($pdo, $user, null, true);
    $groups = $target === 'all' ? $allowed : array_values(array_unique($requested));
    if (!$groups) throw new InvalidArgumentException('Choose at least one currently assigned/existing research group.');
    foreach ($groups as $group) {
        if (!in_array($group, $allowed, true)) throw new DomainException('A selected research group is outside your current permitted scope.');
    }
    return ['groups', $groups];
}

/** Ordinary per-student notifications, after canonical commit; no per-student deadline records. */
function deadline_notify(PDO $pdo, array $user, array $deadline, array $groups): array
{
    $result = ['recipients' => 0, 'notificationFailures' => 0, 'emailFailures' => 0, 'queued' => 0];
    try {
        $stmt = $pdo->prepare('SELECT s.id, s.email, s.full_name FROM students s
            JOIN calendar_deadline_recipients r ON r.student_id = s.id
            WHERE r.deadline_id = :id AND s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL');
        $stmt->execute([':id' => $deadline['id']]);
        $seen = [];
        while ($student = $stmt->fetch()) {
            if (isset($seen[$student['id']])) continue;
            $seen[$student['id']] = true; $result['recipients']++;
            $cancelled = $deadline['status'] === 'Cancelled';
            $subject = $cancelled ? 'Official deadline cancelled' : 'New official deadline';
            $message = $subject . ' (Deadline #' . $deadline['id'] . ").\nTitle: " . $deadline['title']
                . "\nDeadline date: " . $deadline['deadline_date']
                . ($deadline['description'] !== '' ? "\nDetails: " . $deadline['description'] : '')
                . "\nView your PRISM Calendar for the current official record.";
            try {
                $delivery = create_notification($pdo, 'student', (int)$student['id'], (string)$student['email'],
                    (string)$student['full_name'], $subject, $message, 'Reminder', (string)$user['full_name'], null, date('Y-m-d H:i:s'));
                if (($delivery['status'] ?? '') === 'Scheduled') $result['queued']++;
                if (empty($delivery['ok'])) $result['emailFailures']++;
            } catch (Throwable $e) {
                $result['notificationFailures']++;
                log_api_error('deadline_notification', $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        $result['notificationFailures']++;
        log_api_error('deadline_recipients', $e->getMessage());
    }
    return $result;
}
