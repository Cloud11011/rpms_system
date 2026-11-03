<?php
/** TEST ONLY: calls the real copied app's delivery functions under mail-provider-simulator.php. argv: <app dir> <json request>. */
if (PHP_SAPI !== 'cli' || !function_exists('prism_sim_plan')) exit(64);
$app = $argv[1] ?? '';
$in = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
require $app.'/config.php';
require_once $app.'/includes/notification_worker.php';
$pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'"]);
$student = fn(int $user) => $pdo->query('SELECT s.id,s.email,s.full_name FROM students s WHERE s.user_id='.$user)->fetch();
$result = match ($in['action'] ?? '') {
    // Notification-style call (non-sensitive, metadata-only fallback) or a sensitive credential e-mail.
    'send' => send_notification_email($in['to'], 'Synthetic subject', 'Synthetic body.', (bool)($in['sensitive'] ?? false), (bool)($in['fallbackLogContent'] ?? false)),
    'reset' => send_notification_email($in['to'], 'PRISM Password Reset Request', 'Synthetic reset link body.', true),
    'invite' => onboarding_deliver($pdo, (int)$in['user'], $in['to'], bin2hex(random_bytes(32))),
    'setup' => (function () use ($pdo, $in) {
        $r = send_account_setup_email($pdo, (int)$in['user'], $in['to'], 'Synthetic Setup');
        return $r + ['response' => account_setup_response_fields($r, null)];
    })(),
    'immediate' => (function () use ($pdo, $student, $in) {
        $s = $student((int)$in['user']);
        return create_notification($pdo, 'student', (int)$s['id'], $s['email'], $s['full_name'], 'Synthetic immediate notice',
            'Synthetic immediate body.', 'Status Update', 'Synthetic provider test');
    })(),
    default => throw new InvalidArgumentException('Unknown harness action.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
