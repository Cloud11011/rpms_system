<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin', 'adviser']);
$user_email = $authUser['email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifications | PRISM</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <link rel="icon" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/admin-management.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/workspace-pages.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="notification-center-page" data-admin-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES); ?>" data-user-role="<?php echo htmlspecialchars($authUser['role'], ENT_QUOTES, 'UTF-8'); ?>">
<div class="container">
    <aside class="sidebar prism-sidebar">
<?php $prismCurrentPage = 'admin_notifications.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
</aside>
    <main class="main-content management-page">
        <header class="topbar">
            <div><h1>Notifications</h1><p>Send updates, schedule automated reminders, and review notification history.</p></div>
            <div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div>
        </header>

        <div class="admin-tool-grid">
            <section class="management-card admin-tool-form">
                <div class="management-card-head"><div><h2>Send notification</h2><p>Choose the recipients, write the message, then send it now or schedule it.</p></div></div>
                <form id="notificationForm" class="notification-form-grid">
                    <label>Recipients
                        <select id="noticeAudience">
                            <?php if ($authUser['role'] === 'admin'): ?>
                                <option value="All Students">All Students</option>
                                <option value="All Advisers">All Advisers</option>
                                <option value="Students and Advisers">Students and Advisers</option>
                            <?php else: ?>
                                <option value="All Students">My Assigned Students</option>
                            <?php endif; ?>
                            <option value="Specific Research Group">Specific Research Group</option>
                        </select>
                    </label>
                    <label>Notification type
                        <select id="noticeType">
                            <option>Status Update</option>
                            <option>Reminder</option>
                            <option>Follow-up</option>
                            <option>Submission Confirmation</option>
                        </select>
                    </label>
                    <label id="groupLabel" hidden>Research group<select id="noticeGroup" disabled><option value="">Loading research groups...</option></select></label>
                    <p id="noticeRecipientPreview" class="notification-recipient-preview" role="status" aria-live="polite">Checking recipients...</p>
                    <label class="notification-message-field">Message<textarea id="noticeMessage" rows="5" maxlength="600" aria-describedby="noticeMessageCount" required></textarea><span id="noticeMessageCount" class="notification-message-count">0 / 600 characters</span></label>
                    <div class="notification-schedule-row">
                        <label class="check-label"><input id="automatedNotice" type="checkbox"> Schedule for later</label>
                        <label id="scheduleLabel" hidden>Send date and time<input id="noticeSchedule" type="datetime-local"></label>
                        <div class="notification-submit"><button id="cancelNotification" class="notification-secondary" type="reset">Clear form</button><button class="management-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> <span id="noticeSubmitLabel">Send notification</span></button></div>
                    </div>
                </form>
            </section>
            <section class="management-card notification-history-card">
                <div class="management-card-head"><div><h2>Notification History</h2><p id="noticeCount">0 notifications</p></div></div>
                <div id="noticeHistory" class="admin-history"></div>
                <nav id="noticePagination" class="prism-pagination" aria-label="Notification history pages"></nav>
            </section>
        </div>
    <?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>
<dialog id="notificationDetail" class="notification-detail" aria-labelledby="notificationDetailTitle">
    <div class="notification-detail-heading"><span>Notification details</span><h2 id="notificationDetailTitle"></h2></div>
    <dl id="notificationDetailMeta" class="notification-detail-meta"></dl>
    <section class="notification-detail-section"><h3>Message</h3><p id="notificationDetailMessage"></p></section>
    <section class="notification-detail-section"><h3>Delivery information</h3><p id="notificationDetailDelivery"></p></section>
    <form method="dialog" class="notification-detail-actions"><button id="closeNotificationDetail" class="management-primary" autofocus>Close details</button></form>
</dialog>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/admin-notifications.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
