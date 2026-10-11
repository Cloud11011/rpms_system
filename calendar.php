<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin','adviser']);

$user_name = $authUser['full_name'];
$user_email = $authUser['email'];
$user_role = $_SESSION['user_role'] ?? 'RPMS Administrator';
$profile_img = 'assets/images/default-avatar.svg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Calendar | PRISM</title>
    <script>
        try {
            if (localStorage.getItem('prismTheme') === 'dark') {
                document.documentElement.classList.add('dark-theme');
            }
        } catch (_) {}
    </script>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/calendar.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?>" data-reminder-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>">
<div class="container">
    <?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'calendar.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>

    <main class="main-content calendar-page">
        <header class="topbar">
            <div class="calendar-heading">
                <h1>Personal Calendar</h1>
            </div>
            <div class="top-controls">
                <button class="today-button" id="todayButton">Today</button>
                <button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button>
            </div>
        </header>

        <p class="calendar-instructions">Click a date to add and manage personal reminders. Personal reminders are stored only in this browser. Official deadlines appear below.</p>
        <section class="calendar-layout">
            <div class="full-calendar-card">
                <div class="calendar-toolbar">
                    <button class="calendar-nav" id="previousMonth" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
                    <h2 id="monthLabel"></h2>
                    <button class="calendar-nav" id="nextMonth" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <div class="weekday-row" aria-hidden="true">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <div class="month-grid" id="monthGrid"></div>
            </div>

            <aside class="reminder-panel">
                <div class="panel-title">
                    <div>
                        <span class="eyebrow">Selected date</span>
                        <h2 id="selectedDateLabel"></h2>
                    </div>
                    <span class="task-count" id="taskCount">0 tasks</span>
                </div>
                <form id="reminderForm" autocomplete="off">
                    <input type="hidden" id="editingId">
                    <label for="taskTitle">Personal Reminder</label>
                    <input id="taskTitle" type="text" maxlength="120" placeholder="What needs to be done?" required>
                    <label for="taskTime">Time <span>(optional)</span></label>
                    <input id="taskTime" type="time">
                    <label for="taskNotes">Notes <span>(optional)</span></label>
                    <textarea id="taskNotes" rows="3" maxlength="500" placeholder="Add helpful details..."></textarea>
                    <div class="form-actions">
                        <button type="button" class="cancel-edit" id="cancelEdit" hidden>Cancel</button>
                        <button type="submit" class="save-task"><i class="fa-solid fa-plus"></i><span id="saveLabel">Add reminder</span></button>
                    </div>
                </form>
                <div class="task-list" id="taskList"></div>
            </aside>
        </section>
        <section id="officialDeadlinePanel" class="reminder-panel official-deadline-panel" data-role="<?php echo htmlspecialchars($authUser['role'], ENT_QUOTES, 'UTF-8'); ?>"></section>
    <?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/calendar-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/calendar.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
