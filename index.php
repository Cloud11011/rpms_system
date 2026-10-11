<?php
require __DIR__ . '/config.php';

$user = current_user();
if (!$user) {
    header('Location: login.php' . (!empty($GLOBALS['prism_session_expired']) ? '?expired=1' : ''));
    exit;
}

if (!empty($user['must_change_password'])) { header('Location: change_password_required.php'); exit; }
if (legal_acceptance_required($user)) { header('Location: legal_consent.php'); exit; }
if (!onboarding_complete(db(),$user)) { header('Location: complete_profile.php'); exit; }
$target = $user['role'] === 'student' ? 'student.php' : ($user['role'] === 'adviser' ? 'research_adviser.php' : 'dashboard.php');
header("Location: $target");
exit;
