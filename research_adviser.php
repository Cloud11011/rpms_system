<?php
require __DIR__ . '/config.php';
$authUser = require_login('adviser');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PRISM | Adviser Dashboard</title>
<script>try { if (localStorage.getItem('prismTheme') === 'dark') document.documentElement.classList.add('dark-theme'); } catch (_) {}</script>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/admin-management.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/adviser-dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/adviser-dashboard.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/research-resources.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?> adviser-dashboard-page" data-user-role="adviser">
<div class="container">
<?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'research_adviser.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>
<main class="main-content">
<header class="adviser-page-heading">
<div><p class="adviser-eyebrow">RESEARCH ADVISER / OVERVIEW</p><h1>Adviser Dashboard</h1><p>Manage reviews for your assigned students.</p></div>
<div class="adviser-heading-actions top-controls"><button id="adviserRefresh" type="button" class="icon-btn" aria-label="Refresh dashboard" title="Refresh dashboard"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button><button id="themeToggle" type="button" class="theme-toggle" aria-label="Toggle light or dark theme" title="Toggle light or dark theme" aria-pressed="false"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div>
</header>
<section class="prism-workspace-hero" aria-labelledby="adviserWelcomeTitle">
<div><p class="prism-hero-eyebrow">RESEARCH ADVISER WORKSPACE</p><h2 id="adviserWelcomeTitle">Welcome back, <?= htmlspecialchars($authUser['full_name'], ENT_QUOTES, 'UTF-8') ?>!</h2><p class="prism-hero-date"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></p><p>Support your students through feedback, revisions,<br>and preparation for RPMS submission.</p></div>
<a class="prism-hero-action" href="documents.php">Review Documents <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
</section>
<p id="adviserStatus" class="adviser-feedback" role="status" aria-live="polite"></p>
<section class="adviser-summary-grid" aria-label="Assigned research overview">
<article class="adviser-summary-card"><p>Assigned students</p><strong id="adviserStudentsCount">Loading...</strong><span>Student records assigned to you</span></article>
<article class="adviser-summary-card"><p>Pending adviser review</p><strong id="adviserPendingCount">Loading...</strong><span>Current documents awaiting review</span></article>
<article class="adviser-summary-card"><p>Needs revision</p><strong id="adviserRevisionCount">Loading...</strong><span>Current documents returned for changes</span></article>
<article class="adviser-summary-card"><p>Approved documents</p><strong id="adviserApprovedCount">Loading...</strong><span>Ready for submission or submitted to RPMS</span></article>
</section>
<div class="adviser-content-grid">
<section class="adviser-panel adviser-review-panel" aria-labelledby="adviserQueueTitle">
<div class="adviser-panel-heading"><div><h2 id="adviserQueueTitle">Documents to Review</h2><p>Current document versions for your assigned students.</p></div><a href="documents.php">All document tools</a></div>
<div class="adviser-queue-filters">
<label for="adviserQueueSearch">Search submissions<input id="adviserQueueSearch" type="search" placeholder="Student, document or stage"></label>
<label for="adviserQueueFilter">Workflow status<select id="adviserQueueFilter"><option value="">All statuses</option><option>Pending Adviser Review</option><option>Needs Revision</option><option>Ready for Formal RPMS Submission</option><option>Submitted to RPMS</option></select></label>
</div>
<div id="adviserQueue" class="adviser-queue" aria-live="polite" aria-busy="true"><p class="adviser-panel-state">Loading current submissions...</p></div>
</section>
<section class="adviser-panel adviser-notifications-panel" aria-labelledby="adviserNotificationsTitle"><div class="adviser-panel-heading"><div><h2 id="adviserNotificationsTitle">Recent notifications</h2><p>Recent messages addressed to your account.</p></div><a href="admin_notifications.php">Notification Center</a></div><div id="adviserNotifications" tabindex="0" role="region" aria-label="Recent notification history" aria-live="polite" aria-busy="true"><p class="adviser-panel-state">Loading recent notifications...</p></div></section>
</div>
<div class="adviser-side-panels">
<section class="adviser-panel prism-deadline-calendar" aria-labelledby="adviserCalendarTitle">
<div class="adviser-panel-heading"><h2 id="adviserCalendarTitle">Calendar</h2><a href="calendar.php">View Calendar</a></div>
<div class="prism-deadline-toolbar"><button type="button" data-deadline-month="-1" aria-label="Previous month"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><strong id="adviserCalendarMonth"></strong><button type="button" data-deadline-month="1" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button></div>
<div class="prism-deadline-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
<div class="prism-deadline-grid" id="adviserCalendarDays"></div>
<p class="prism-deadline-load" id="dashboardDeadlineState" role="status">Loading official deadlines...</p>
</section>
<section class="adviser-panel" aria-labelledby="adviserProfileTitle"><div class="adviser-panel-heading"><h2 id="adviserProfileTitle">Your account</h2><a href="account.php">View account</a></div><div id="adviserProfile" aria-live="polite" aria-busy="true"><p class="adviser-panel-state">Loading account...</p></div></section>
</div>
<?php require __DIR__ . '/includes/research_resources.php'; ?>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>
<dialog id="adviserReviewDialog" class="adviser-review-dialog" aria-labelledby="adviserReviewTitle" aria-describedby="adviserReviewHelp">
<form id="adviserReviewForm">
<h2 id="adviserReviewTitle">Review document</h2><p id="adviserReviewHelp">Choose a review outcome and add feedback for the student.</p>
<label for="adviserReviewStatus">Review status<select id="adviserReviewStatus" required><option>Under Review</option><option>Received</option><option>Verified</option><option>Resubmission Requested</option><option>Approved</option><option>Denied</option></select></label>
<label for="adviserReviewRemarks">Reviewer remarks<textarea id="adviserReviewRemarks" rows="6" maxlength="5000" aria-describedby="adviserReviewRemarksHint"></textarea></label><p id="adviserReviewRemarksHint">Required when denying a document or requesting resubmission. Up to 5,000 characters.</p>
<p id="adviserReviewError" class="adviser-review-error" role="alert"></p>
<div class="adviser-dialog-actions"><button id="adviserReviewCancel" class="adviser-button" type="button">Cancel</button><button id="adviserReviewSave" class="adviser-button is-primary" type="submit">Save review</button></div>
</form>
</dialog>
</body>
</html>
