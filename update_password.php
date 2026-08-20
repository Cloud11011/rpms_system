<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

$stmt = db()->prepare('SELECT * FROM password_resets WHERE token = :t LIMIT 1');
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();
$valid = $row && !$row['used'] && strtotime($row['expires_at']) > time();

if (!$valid) {
    $_SESSION['error'] = 'This password reset link is invalid or has expired. Please request a new one.';
    header('Location: forgot_password.php');
    exit;
}
if ($password === '' || strlen($password) < 8) {
    $_SESSION['error'] = 'Password must be at least 8 characters long.';
    header('Location: reset_password.php?token=' . urlencode($token));
    exit;
}
if ($password !== $confirm) {
    $_SESSION['error'] = 'Password and confirmation do not match.';
    header('Location: reset_password.php?token=' . urlencode($token));
    exit;
}

$pdo = db();
$pdo->prepare('UPDATE users SET password_hash = :p, must_change_password = 0 WHERE id = :id')
    ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['user_id']]);
$pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = :id')->execute([':id' => $row['id']]);

log_activity(null, 'password_reset_completed', "user_id={$row['user_id']}");

$_SESSION['success'] = 'Your password has been updated. You may now log in.';
header('Location: login.php');
exit;
