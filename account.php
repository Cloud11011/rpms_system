<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin','adviser']);
$user_name = $authUser['full_name'];
$user_email = $authUser['email'];
$user_role = $_SESSION['user_role'] ?? ucfirst($authUser['role']);
$isAdmin = $authUser['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Account & Activity | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/admin-management.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/workspace-pages.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?> account-page">
<div class="container">
<?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'account.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>
<main class="main-content management-page">
<header class="topbar"><div><h1>Account & Activity</h1><p>Manage your PRISM account and review the audit activity available to your role.</p></div><div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
<div class="account-grid">
<section class="management-card account-card" id="profile"><div class="management-card-head"><div><h2>Profile</h2><p>Your account identity in PRISM.</p></div></div>
<form id="accountProfileForm" class="account-form">
<label>Full name<input id="accountName" maxlength="190" required></label>
<label>Email<input id="accountEmail" type="email" readonly></label>
<label>Role<input id="accountRole" readonly></label>
<label>Account ID<input id="accountRef" readonly></label>
<button class="management-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save name</button>
</form></section>
<section class="management-card account-card" id="security"><div class="management-card-head"><div><h2>Security</h2><p>Change your password.</p></div></div>
<form id="accountPasswordForm" class="account-form">
<label>Current password<input id="accountCurrentPassword" type="password" autocomplete="current-password" required></label>
<label>New password<input id="accountNewPassword" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
<label>Confirm new password<input id="accountConfirmPassword" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
<button class="management-primary" type="submit"><i class="fa-solid fa-key"></i> Change password</button>
</form><p class="account-note">Use at least 12 characters and choose a password different from your current one.</p></section>
</div>
<?php if ($isAdmin): ?>
<section class="management-card account-card account-lifecycle" id="lifecycle" data-user-id="<?php echo (int)$authUser['id']; ?>">
<div class="management-card-head"><div><h2>My account lifecycle</h2><p>Archive is the recommended option. Another active Admin must remain.</p></div></div>
<form id="adminArchiveForm" class="account-form lifecycle-form">
<h3>Archive my Admin account</h3><p>Your login will be deactivated and you will be signed out. Institutional records and your historical attribution will remain.</p>
<div class="lifecycle-form-group"><label for="adminArchivePassword">Current password</label><div class="lifecycle-password"><input id="adminArchivePassword" name="currentPassword" type="password" autocomplete="current-password" maxlength="200" required><button type="button" class="toggle-password lifecycle-password-toggle" data-lifecycle-password aria-label="Show password" aria-controls="adminArchivePassword" aria-pressed="false"><i id="adminArchivePasswordEye" class="fa-solid fa-eye" aria-hidden="true"></i></button></div></div>
<label class="lifecycle-check"><input name="confirmed" type="checkbox" required> I confirm that I want to archive my own Admin account.</label>
<button type="submit" class="management-primary"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive my account</button>
</form>
<details class="lifecycle-destructive"><summary>Permanently delete my Admin account</summary>
<form id="adminDeleteForm" class="account-form lifecycle-form"><p><strong>This cannot be undone.</strong> Your own login and password reset/setup tokens will be removed. Institutional history and audit evidence remain. Accounts needed by official deadlines cannot be deleted; use Archive.</p>
<p id="adminDeleteAvailability" role="status">Checking permanent-delete schema verification...</p>
<div class="lifecycle-form-group"><label for="adminDeletePassword">Current password</label><div class="lifecycle-password"><input id="adminDeletePassword" name="currentPassword" type="password" autocomplete="current-password" maxlength="200" required><button type="button" class="toggle-password lifecycle-password-toggle" data-lifecycle-password aria-label="Show password" aria-controls="adminDeletePassword" aria-pressed="false"><i id="adminDeletePasswordEye" class="fa-solid fa-eye" aria-hidden="true"></i></button></div></div>
<div class="lifecycle-form-group"><label for="adminDeleteConfirmation">Type DELETE to confirm</label><input id="adminDeleteConfirmation" name="confirmation" autocomplete="off" maxlength="100" required></div>
<label class="lifecycle-check"><input name="confirmed" type="checkbox" required> I understand that permanent deletion cannot be undone.</label>
<button type="submit" class="lifecycle-danger" disabled>Permanently delete my account</button></form>
</details><p id="adminLifecycleResult" role="status" aria-live="polite"></p>
</section>
<?php endif; ?>
<section class="management-card account-card activity-card" id="activity">
<div class="management-card-head"><div><h2>Activity Logs</h2><p><?php echo $isAdmin ? 'Audit activity across PRISM.' : 'Audit activity for students assigned to you.'; ?></p></div><p id="activityCount">0 entries</p></div>
<form id="activityFilterForm" class="activity-filters">
<label class="activity-field activity-search-field" for="activitySearch"><span>Search</span><input id="activitySearch" type="search" placeholder="Action, student, protocol code, or details"></label>
<fieldset class="activity-date-group"><legend>Date range</legend><div class="activity-date-fields"><label for="activityFrom"><span>From</span><input id="activityFrom" type="date"></label><label for="activityTo"><span>To</span><input id="activityTo" type="date"></label></div></fieldset>
<label class="activity-field" for="activityOverride"><span>Log type</span><span class="activity-override-control"><input id="activityOverride" type="checkbox"><span>Overrides only</span></span></label>
<div class="activity-submit-field"><span class="activity-control-label">Apply filters</span><button class="management-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
</form>
<div id="activityList" class="activity-list"></div>
<nav id="activityPagination" class="prism-pagination" aria-label="Activity log pages"></nav>
</section>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main></div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/account.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/lifecycle-forms.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body></html>
