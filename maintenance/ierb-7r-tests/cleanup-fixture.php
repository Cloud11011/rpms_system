<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP.
/** Clean-database rehearsal helpers. Bound to bootstrap's verified disposable MariaDB datadir; a dedicated fixture only.
 * Deletion-verification evidence is issued by maintenance/issue-deletion-verification.php, not here. */
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
try {
    $result = match ($in['action']) {
        // The test web runtime loads evidence only while this sandbox file exists (maintenance/ierb-group-tests/runtime.php).
        'revoke-verification' => @unlink($review.'/sandbox/hard-delete-verification.json'),
        // Two more synthetic Administrators, preserved like Admin 1; Admin 92 later authors a deadline.
        'extra-admins' => (function () use ($pdo) {
            foreach ([[91, 'E91', 'Synthetic Admin 91', 'admin91@example.test'], [92, 'E92', 'Synthetic Admin 92', 'admin92@example.test']] as [$id, $code, $name, $email]) {
                $pdo->prepare("INSERT IGNORE INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) SELECT ?,?,?,?,'admin',?,password_hash,'Active',NOW() FROM users WHERE id=1")->execute([$id, $code, $name, $email, $code]);
                $pdo->prepare('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=1')->execute([$id]);
            }
            return ['admins' => [91, 92]];
        })(),
        // Restored-copy rehearsal only: the real Administrators' passwords are unknown and are never reset, so a clearly
        // labelled test operator performs the steps and removes itself through the Admin lifecycle at the end.
        'operator-admin' => (function () use ($pdo) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id=9001")->fetchColumn()) return ['operator' => 9001, 'existing' => true];
            $pdo->prepare("INSERT INTO users(id,username,full_name,email,role,ref_id,password_hash,status,must_change_password,created_at)
                VALUES (9001,'E9001','Rehearsal Operator (test only)','rehearsal.operator@example.test','admin','E9001',?,'Active',0,NOW())")->execute([password_hash('SyntheticPass123!', PASSWORD_DEFAULT)]);
            $pdo->exec('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT 9001,current_version_id FROM legal_policies WHERE current_version_id IS NOT NULL');
            return ['operator' => 9001, 'existing' => false];
        })(),
        // Functional state of each Administrator without signing in as them: eligibility, legal status, links (booleans only).
        'admin-health' => (function () use ($pdo, $in, $root) {
            require_once $root.'/includes/legal_policy.php';
            $out = [];
            foreach (array_map('intval', $in['ids']) as $id) {
                $q = $pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $u = $q->fetch(PDO::FETCH_ASSOC);
                $out[(string)$id] = $u ? ['exists' => true, 'role' => $u['role'], 'active' => strcasecmp((string)$u['status'], 'Active') === 0, 'mustChangePassword' => !empty($u['must_change_password']),
                    'adminEligible' => legal_admin_eligible($u), 'legalOutstanding' => (bool)legal_outstanding($pdo, $id),
                    'linkedStudentOrAdviser' => (int)$pdo->query('SELECT (SELECT COUNT(*) FROM students WHERE user_id='.$id.')+(SELECT COUNT(*) FROM advisers WHERE user_id='.$id.')')->fetchColumn() > 0] : ['exists' => false];
            }
            return $out;
        })(),
        default => throw new RuntimeException('Unknown cleanup rehearsal operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'type' => get_class($e), 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
