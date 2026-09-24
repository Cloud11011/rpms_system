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
    <title>Calendar | CEU RPMS Workload Assistant</title>
    <script>
        try {
            if (localStorage.getItem('prismTheme') === 'dark') {
                document.documentElement.classList.add('dark-theme');
            }
        } catch (_) {}
    </script>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/calendar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body data-reminder-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>">
<div class="container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="assets/images/prismlogo1.png?v=2" alt="PRISM Assistant logo" class="sidebar-brand-logo">
            <div class="sidebar-brand-copy">
                <strong>IERB Progress &amp; Reporting System</strong>
                <span>Centro Escolar University - Malolos &bull; RPMS</span>
            </div>
        </div>
        <ul class="nav-links"><li><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><li><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li><li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li><li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li><li><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li><li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li><li class="active"><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li></ul>
        <div class="sidebar-bottom">
            <div class="profile-dropdown-wrapper">
                <div class="sidebar-profile" id="profileToggle">
                    <img src="<?php echo htmlspecialchars($profile_img, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Picture">
                    <div class="profile-info">
                        <h4><?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <p><?php echo htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <i class="fa-solid fa-chevron-down dropdown-arrow"></i>
                </div>
                <div class="profile-menu" id="profileMenu">
                    <a href="#"><i class="fa-solid fa-user-gear"></i> Profile</a>
                    <a href="#"><i class="fa-solid fa-gear"></i> Settings</a>
                    <a href="#"><i class="fa-solid fa-sliders"></i> Activity Logs</a>
                    <hr>
                    <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content calendar-page">
        <header class="topbar">
            <div class="calendar-heading">
                <h1>Calendar</h1>
                <p>Click a date to add and manage personal reminders. These reminders are stored only in this browser.</p>
            </div>
            <div class="top-controls">
                <button class="today-button" id="todayButton">Today</button>
                <div class="theme-toggle" id="themeToggle" title="Toggle Light/Dark Theme">
                    <i class="fa-solid fa-sun light-icon"></i>
                    <i class="fa-solid fa-moon dark-icon"></i>
                </div>
            </div>
        </header>

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
                    <label for="taskTitle">Task reminder</label>
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
    </main>
</div>
<script src="assets/js/calendar.js"></script>
</body>
</html>
