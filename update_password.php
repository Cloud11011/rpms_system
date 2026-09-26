<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}
require_post_same_origin();

$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

$pdo = null;
try {
    $pdo = db();
    $owner = $pdo->prepare('SELECT user_id FROM password_resets WHERE token = :t AND used = 0 AND expires_at > NOW() LIMIT 1');
    $owner->execute([':t' => $token]);
    $ownerId = $owner->fetchColumn();
    $pdo->beginTransaction();
    if ($ownerId) {
        // Match the lock order used for reset issuance and signed-in password changes.
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $ownerId]);
        $lock->fetchColumn();
    }
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
} catch (Throwable $e) {
    // Log no token, password, SQL or exception text; keep failure logging independent of the database.
    try {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $rollbackError) {
        log_api_error('password_reset', 'The failed password reset could not be rolled back.');
    }
    log_api_error('password_reset', 'The password reset could not be completed.');
    $_SESSION['error'] = 'Your password could not be updated. Please try again later.';
    header('Location: forgot_password.php');
    exit;
}

log_activity(null, 'password_reset_completed', "user_id={$row['user_id']}");

$_SESSION['success'] = 'Your password has been updated. You may now log in.';
header('Location: login.php');
exit;
