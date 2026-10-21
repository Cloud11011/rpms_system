<?php
/**
 * PRISM - Audit trail (read-only).
 *
 *   audit_api.php?action=list[&studentId=][&action_code=][&override=1][&from=YYYY-MM-DD][&to=YYYY-MM-DD][&q=][&page=]
 *
 * Admins see everything. Advisers only see entries about their own students.
 * Students can only see entries tied to their own login or current student record.
 */

require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/includes/pagination.php';

$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    if ($user['role']==='student') {
        require_once __DIR__.'/includes/student_activity.php';
        try { json_out(student_activity_read($pdo,$user,$_GET)); }
        catch (InvalidArgumentException $e) { json_out(['ok'=>false,'message'=>$e->getMessage()],422); }
    }
    $where = [];
    $params = [];

    if ($user['role'] === 'adviser') {
        $where[] = 'l.student_id IN (SELECT s.id FROM students s JOIN advisers a ON a.id = s.adviser_id WHERE a.email = :adv AND s.archived_at IS NULL)';
        $params[':adv'] = $user['email'];
    } elseif ($user['role'] === 'student') {
        $where[] = '(l.user_email = :student_email OR l.student_id IN (
            SELECT s.id FROM students s WHERE s.archived_at IS NULL
            AND (s.user_id = :student_uid OR (s.email = :profile_email AND s.student_id = :student_ref))))';
        $params[':student_email'] = $user['email'];
        $params[':student_uid'] = (int)$user['id'];
        $params[':profile_email'] = $user['email'];
        $params[':student_ref'] = $user['ref_id'];
    }
    if ((int)($_GET['studentId'] ?? 0) > 0) {
        $where[] = 'l.student_id = :sid';
        $params[':sid'] = (int)$_GET['studentId'];
    }
    $code = trim((string)($_GET['action_code'] ?? ''));
    if ($code !== '') {
        $where[] = 'l.action = :code';
        $params[':code'] = $code;
    }
    if (($_GET['override'] ?? '') === '1') {
        $where[] = 'l.is_override = 1';
    }
    $from = trim((string)($_GET['from'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'l.created_at >= :from';
        $params[':from'] = $from . ' 00:00:00';
    }
    $to = trim((string)($_GET['to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'l.created_at <= :to';
        $params[':to'] = $to . ' 23:59:59';
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(l.details LIKE :q OR l.reason LIKE :q OR l.actor_name LIKE :q OR s.full_name LIKE :q OR s.protocol_code LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $limit = prism_page_size($_GET);
    $scope = ' FROM activity_logs l LEFT JOIN students s ON s.id = l.student_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $count = $pdo->prepare('SELECT COUNT(*)' . $scope);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $limit;

    $sql = 'SELECT l.*, s.full_name AS student_name, s.protocol_code
            ' . $scope
        . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $entries = array_map(function ($r) use ($user) {
        $entry = [
            'id' => (int)$r['id'], 'at' => $r['created_at'], 'action' => $r['action'],
            'actionLabel' => audit_action_label($r['action']), 'details' => $r['details'],
            'reason' => $r['reason'] ?? null,
        ];
        if ($user['role'] !== 'student') {
            $entry += [
                'actorName' => $r['actor_name'] ?? null, 'actorRole' => $r['actor_role'] ?? null,
                'actorEmail' => $r['user_email'], 'entityType' => $r['entity_type'] ?? null,
                'entityId' => $r['entity_id'] ?? null, 'studentId' => $r['student_id'] ? (int)$r['student_id'] : null,
                'studentName' => $r['student_name'] ?? null, 'protocolCode' => $r['protocol_code'] ?? null,
                'before' => $r['before_value'] ?? null, 'after' => $r['after_value'] ?? null,
                'override' => !empty($r['is_override']),
            ];
        }
        return $entry;
    }, $stmt->fetchAll());

    json_out(['ok' => true, 'entries' => $entries, 'limit' => $limit, 'offset' => $offset,
        'page' => $page, 'pages' => $pages, 'total' => $total]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
