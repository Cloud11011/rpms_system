<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Please enter a valid email address.';
    header('Location: forgot_password.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

// Always show the same confirmation, whether or not the account exists,
// so the form cannot be used to discover which emails are registered.
if ($user) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $ins = db()->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:u,:t,:x)');
    $ins->execute([':u' => $user['id'], ':t' => $token, ':x' => $expires]);

    if (APP_BASE_URL === '') {
        log_api_error('password_reset', 'APP_BASE_URL is not configured; reset email was not sent.');
    } else {
        $resetLink = APP_BASE_URL . '/reset_password.php?token=' . urlencode($token);

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
