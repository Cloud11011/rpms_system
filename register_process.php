<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

function back_with_error(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: register.php');
    exit;
}

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$fullname   = trim((string)($_POST['fullname'] ?? ''));
$email      = trim((string)($_POST['email'] ?? ''));
$username   = trim((string)($_POST['username'] ?? ''));
$password   = (string)($_POST['password'] ?? '');
$confirm    = (string)($_POST['confirm_password'] ?? '');
$regCode    = (string)($_POST['registration_code'] ?? '');

if (ADMIN_REGISTRATION_CODE === '') {
    back_with_error('Self-registration is currently disabled. Ask an existing RPMS administrator to set ADMIN_REGISTRATION_CODE in config.local.php to enable it, or to create your account for you.');
}
if (!hash_equals(ADMIN_REGISTRATION_CODE, $regCode)) {
    back_with_error('Invalid staff registration code.');
}
if ($employeeId === '' || $fullname === '' || $email === '' || $username === '' || $password === '') {
    back_with_error('Please complete all fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error('Please enter a valid CEU email address.');
}
if (!is_allowed_email_domain($email)) {
    back_with_error('Only ' . allowed_email_domains_hint() . ' email addresses may register for an RPMS account.');
}
if ($password !== $confirm) {
    back_with_error('Password and confirmation do not match.');
}
if (strlen($password) < 8) {
    back_with_error('Password must be at least 8 characters long.');
}

$pdo = db();

$check = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
$check->execute([':u' => $username, ':e' => $email]);
if ($check->fetch()) {
    back_with_error('That username or email is already registered.');
}

try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, email, ref_id)
        VALUES (:u, :p, "admin", :n, :e, :ref)');
    $stmt->execute([
        ':u' => $username,
        ':p' => password_hash($password, PASSWORD_DEFAULT),
        ':n' => $fullname,
        ':e' => $email,
        ':ref' => $employeeId,
    ]);
} catch (PDOException $e) {
    back_with_error('This account could not be created. The employee ID may already be in use.');
}

log_activity($email, 'register_admin', "employee_id=$employeeId");
$_SESSION['success'] = 'Your RPMS account has been created. You may now log in.';
header('Location: login_admin.php');
exit;
