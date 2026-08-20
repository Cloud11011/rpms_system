<?php
require __DIR__ . '/config.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$target = $user['role'] === 'student' ? 'student.php' : ($user['role'] === 'adviser' ? 'ierbprog.php' : 'dashboard.php');
header("Location: $target");
exit;
