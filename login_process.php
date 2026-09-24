<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? $_POST['username'] ?? '')));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    $_SESSION['error'] = 'Please enter your email and password.';
    header('Location: login.php');
    exit;
}

// Throttle repeated failed attempts against a single email, regardless of
// whether that email actually exists (checked before the DB lookup so the
// block itself doesn't leak which accounts are real).
if (too_many_recent_failures($email)) {
    $_SESSION['error'] = 'Too many failed login attempts for this account. Please try again in a few minutes.';
    header('Location: login.php');
    exit;
}

// Login is by email only now (feature request: remove Student ID/Employee
// ID as login identifiers) -- role is whatever the matched account's row
// says, not something the person selects beforehand.
$stmt = db()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

// Always run password_verify(), even for a nonexistent user, against a
// fixed dummy hash — this keeps response time consistent so failed logins
// can't be used to enumerate which emails exist on the system.
$dummyHash = '$2y$10$vzBXNiy73d.9neRQSAf.f.x75YP44p7wvMVaX2ylMlrmulh0/jJCu';
$passwordOk = $user ? password_verify($password, $user['password_hash']) : password_verify($password, $dummyHash);

if (!$user || !$passwordOk) {
    $_SESSION['error'] = 'Invalid email or password.';
    log_activity($email, 'login_failed', '');
    header('Location: login.php');
    exit;
}

if (strcasecmp((string)$user['status'], 'Active') !== 0) {
    $_SESSION['error'] = 'This account is not active. Please contact the RPMS office.';
    header('Location: login.php');
    exit;
}

$accountType = $user['role'];

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $accountType === 'admin'
    ? 'RPMS Administrator'
    : ($accountType === 'adviser' ? 'Research Adviser' : 'Student Researcher');
$_SESSION['account_type'] = $accountType;
$_SESSION['username'] = $user['username'];
$_SESSION['ref_id'] = $user['ref_id'];
$_SESSION['must_change_password'] = (int)$user['must_change_password'];

log_activity($user['email'], 'login_success', "account_type=$accountType");
header('Location: loading.php');
exit;
