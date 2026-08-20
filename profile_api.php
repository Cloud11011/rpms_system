<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'me';
$data = json_body();

if ($action === 'me') {
    json_out(['ok' => true, 'user' => [
        'name' => $user['full_name'], 'email' => $user['email'], 'role' => ucfirst($user['role']),
        'refId' => $user['ref_id'],
    ]]);
}

if ($action === 'update_profile') {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['ok' => false, 'message' => 'Name cannot be empty.'], 422);
    }
    $pdo->prepare('UPDATE users SET full_name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $user['id']]);
    if ($user['role'] === 'student') {
        $pdo->prepare('UPDATE students SET full_name = :n WHERE email = :e')->execute([':n' => $name, ':e' => $user['email']]);
    } elseif ($user['role'] === 'adviser') {
        $pdo->prepare('UPDATE advisers SET full_name = :n WHERE email = :e')->execute([':n' => $name, ':e' => $user['email']]);
    }
    $_SESSION['user_name'] = $name;
    log_activity($user['email'], 'profile_updated', '');
    json_out(['ok' => true]);
}

if ($action === 'change_password') {
    $current = (string)($data['currentPassword'] ?? '');
    $new = (string)($data['newPassword'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        json_out(['ok' => false, 'message' => 'Your current password is incorrect.'], 422);
    }
    if (strlen($new) < 8) {
        json_out(['ok' => false, 'message' => 'New password must be at least 8 characters.'], 422);
    }
    $pdo->prepare('UPDATE users SET password_hash = :p, must_change_password = 0 WHERE id = :id')
        ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
    $_SESSION['must_change_password'] = 0;
    log_activity($user['email'], 'password_changed', '');
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
