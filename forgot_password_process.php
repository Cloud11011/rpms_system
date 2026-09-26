<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}
require_post_same_origin();

$email = strtolower(trim((string)($_POST['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Please enter a valid email address.';
    header('Location: forgot_password.php');
    exit;
}

// Count requests before looking up accounts, including unknown addresses.
$resetAllowed = consume_auth_attempt('reset_ip', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 900)
    && consume_auth_attempt('reset_email', $email, 5, 900);
if (!$resetAllowed || !app_base_url_is_valid()) {
    if ($resetAllowed) log_api_error('password_reset', 'Canonical URL configuration prevents reset delivery.');
    $_SESSION['success'] = 'If that email is registered, a password reset link has been sent.';
    header('Location: forgot_password.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

// Always show the same confirmation, whether or not the account exists,
// so the form cannot be used to discover which emails are registered.
if ($user) {
    $token = null;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // Serialize resends for this account, retaining recent links for five minutes.
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $user['id']]);
        $exists = $lock->fetchColumn();
        $recent = $pdo->prepare('SELECT id FROM password_resets
            WHERE user_id = :u AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1 FOR UPDATE');
        $recent->execute([':u' => $user['id']]);
        if ($exists && !$recent->fetchColumn()) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = :u AND used = 0')
                ->execute([':u' => $user['id']]);
            $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:u,:t,:x)');
            $ins->execute([':u' => $user['id'], ':t' => $token, ':x' => $expires]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $token = null;
        log_api_error('password_reset', 'The reset request could not be saved.');
    }

    if ($token !== null) {
        $resetLink = rtrim(APP_BASE_URL, '/') . '/reset_password.php?token=' . urlencode($token);

        $body = "Hello {$user['full_name']},\n\n"
            . "A password reset was requested for your PRISM account.\n"
            . "Reset your password using the link below (valid for 1 hour):\n\n"
            . "{$resetLink}\n\n"
            . "If you did not request this, you can safely ignore this email.\n\n"
            . "- CEU Malolos RPMS / PRISM";

        send_notification_email($email, 'PRISM Password Reset Request', $body);
        log_activity($email, 'password_reset_requested', '');
    }
}

$_SESSION['success'] = 'If that email is registered, a password reset link has been sent.';
header('Location: forgot_password.php');
exit;
