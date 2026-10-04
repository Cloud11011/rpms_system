<?php
require __DIR__ . '/config.php';

$user = current_user();
if (!$user) {
    header('Location: login.php' . (!empty($GLOBALS['prism_session_expired']) ? '?expired=1' : ''));
    exit;
}

$target = $user['role'] === 'student' ? 'student.php' : ($user['role'] === 'adviser' ? 'ierbprog.php' : 'dashboard.php');
header("Location: $target");
exit;
