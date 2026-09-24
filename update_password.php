<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

$pdo = db();
$pdo->beginTransaction();
$stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = :t AND used = 0 AND expires_at > NOW() LIMIT 1 FOR UPDATE');
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();
$valid = (bool)$row;

if (!$valid) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = 'This password reset link is invalid or has expired. Please request a new one.';
    header('Location: forgot_password.php');
    exit;
}
if ($password === '' || strlen($password) < 8) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = 'Password must be at least 8 characters long.';
    header('Location: reset_password.php?token=' . urlencode($token));
    exit;
}
if (strlen($password) > 200) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = 'Password is too long.';
    header('Location: reset_password.php?token=' . urlencode($token));
    exit;
}
if ($password !== $confirm) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = 'Password and confirmation do not match.';
    header('Location: reset_password.php?token=' . urlencode($token));
    exit;
}

$pdo->prepare('UPDATE users SET password_hash = :p, must_change_password = 0 WHERE id = :id')
    ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['user_id']]);
$pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = :uid')
    ->execute([':uid' => $row['user_id']]);
$pdo->commit();

log_activity(null, 'password_reset_completed', "user_id={$row['user_id']}");

$_SESSION['success'] = 'Your password has been updated. You may now log in.';
header('Location: login.php');
exit;
