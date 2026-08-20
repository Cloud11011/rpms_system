<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$accountType = in_array($_POST['account_type'] ?? '', ['admin', 'adviser', 'student'], true)
    ? $_POST['account_type']
    : 'admin';
$loginPage = [
    'admin' => 'login_admin.php',
    'adviser' => 'login_adviser.php',
    'student' => 'login_students.php',
][$accountType];

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $_SESSION['error'] = 'Please enter your username and password.';
    header("Location: $loginPage");
    exit;
}

// Throttle repeated failed attempts against a single username, regardless
// of whether that username actually exists (checked before the DB lookup
// so the block itself doesn't leak which usernames are real).
if (too_many_recent_failures($username)) {
    $_SESSION['error'] = 'Too many failed login attempts for this account. Please try again in a few minutes.';
    header("Location: $loginPage");
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE username = :u AND role = :r LIMIT 1');
$stmt->execute([':u' => $username, ':r' => $accountType]);
$user = $stmt->fetch();

// Always run password_verify(), even for a nonexistent user, against a
// fixed dummy hash — this keeps response time consistent so failed logins
// can't be used to enumerate which usernames exist on the system.
$dummyHash = '$2y$10$vzBXNiy73d.9neRQSAf.f.x75YP44p7wvMVaX2ylMlrmulh0/jJCu';
$passwordOk = $user ? password_verify($password, $user['password_hash']) : password_verify($password, $dummyHash);

if (!$user || !$passwordOk) {
    $_SESSION['error'] = 'Invalid username or password for this account type.';
    log_activity($username, 'login_failed', "account_type=$accountType");
    header("Location: $loginPage");
    exit;
}

if (strcasecmp((string)$user['status'], 'Active') !== 0) {
    $_SESSION['error'] = 'This account is not active. Please contact the RPMS office.';
    header("Location: $loginPage");
    exit;
}

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
