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
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-management.css">
<link rel="stylesheet" href="assets/css/prism-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
.account-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.7fr);gap:16px}.account-card{padding:18px}.account-form{display:grid;gap:12px}.account-form label{display:grid;gap:6px;font-size:11px;font-weight:600}.account-form input{padding:10px 12px;border:1px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-primary);font:inherit}.account-form input[readonly]{opacity:.75}.activity-filters{display:grid;grid-template-columns:minmax(180px,1fr) 150px 150px auto auto;gap:8px;align-items:end}.activity-list{display:grid;gap:10px;margin-top:14px}.activity-row{padding:12px;border:1px solid var(--border-color);border-radius:9px;background:var(--card-bg)}.activity-row header{display:flex;justify-content:space-between;gap:10px}.activity-row small{display:block;color:var(--text-secondary);margin-top:5px}.activity-row p{margin:7px 0 0;white-space:pre-wrap}.account-note{font-size:11px;color:var(--text-secondary);margin-top:6px}@media(max-width:900px){.account-grid{grid-template-columns:1fr}.activity-filters{grid-template-columns:1fr 1fr}.activity-filters .wide{grid-column:1/-1}}
</style>
</head>
<body>
<div class="container">
<aside class="sidebar">
<div class="sidebar-header"><img src="assets/images/prismlogo1.png?v=2" alt="PRISM" class="sidebar-brand-logo"><div class="sidebar-brand-copy"><strong>IERB Progress &amp; Reporting System</strong><span>Centro Escolar University - Malolos &bull; RPMS</span></div></div>
<ul class="nav-links">
<?php if ($isAdmin): ?><li><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><?php endif; ?>
<li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li>
<?php if ($isAdmin): ?><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><?php endif; ?>
<li><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li>
<li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li>
<li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li>
<li><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li>
<li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li>
<li><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li>
<li class="active"><a href="account.php"><i class="fa-solid fa-user-gear"></i><span>Account</span></a></li>
</ul>
<div class="sidebar-bottom"><div class="sidebar-profile"><img src="assets/images/default-avatar.svg" alt="Profile"><div class="profile-info"><h4 id="sideAccountName"><?php echo htmlspecialchars($user_name,ENT_QUOTES,'UTF-8'); ?></h4><p><?php echo htmlspecialchars($user_role,ENT_QUOTES,'UTF-8'); ?></p></div></div><a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a></div>
</aside>
<main class="main-content management-page">
<header class="topbar"><div><h1>Account & Activity</h1><p>Manage your PRISM account and review the audit activity available to your role.</p></div><div class="theme-toggle" id="themeToggle" title="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon"></i><i class="fa-solid fa-moon dark-icon"></i></div></header>
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
<label>New password<input id="accountNewPassword" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></label>
<label>Confirm new password<input id="accountConfirmPassword" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></label>
<button class="management-primary" type="submit"><i class="fa-solid fa-key"></i> Change password</button>
</form><p class="account-note">Use at least 8 characters and choose a password different from your current one.</p></section>
</div>
<section class="management-card account-card" id="activity" style="margin-top:16px">
<div class="management-card-head"><div><h2>Activity Logs</h2><p><?php echo $isAdmin ? 'Audit activity across PRISM.' : 'Audit activity for students assigned to you.'; ?></p></div><p id="activityCount">0 entries</p></div>
<form id="activityFilterForm" class="activity-filters">
<label class="wide">Search<input id="activitySearch" placeholder="Action, student, protocol code, details"></label>
<label>From<input id="activityFrom" type="date"></label>
<label>To<input id="activityTo" type="date"></label>
<label class="check-label"><input id="activityOverride" type="checkbox"> Overrides only</label>
<button class="management-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
</form>
<div id="activityList" class="activity-list"></div>
</section>
</main></div>
<script src="assets/js/prism-ui.js"></script>
<script src="assets/js/account.js"></script>
</body></html>