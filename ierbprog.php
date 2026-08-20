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
    <title>IERB Progress | CEU RPMS Workload Assistant</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/ierbprog.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body data-ierb-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($_SESSION['account_type'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?>">
<div class="container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="assets/images/prismlogo1.png?v=2" alt="PRISM Assistant logo" class="sidebar-brand-logo">
            <div class="sidebar-brand-copy"><strong>IERB Progress &amp; Reporting System</strong><span>Centro Escolar University - Malolos &bull; RPMS</span></div>
        </div>
        <ul class="nav-links"><li><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><li class="active"><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li><li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li><li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li><li><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li><li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li><li><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li></ul>
        <div class="sidebar-bottom"><div class="profile-dropdown-wrapper">
            <div class="sidebar-profile" id="profileToggle"><img src="<?php echo htmlspecialchars($profile_img, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile picture"><div class="profile-info"><h4><?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?></h4><p><?php echo htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8'); ?></p></div><i class="fa-solid fa-chevron-down dropdown-arrow"></i></div>
            <div class="profile-menu" id="profileMenu"><a href="#"><i class="fa-solid fa-user-gear"></i> Profile</a><a href="#"><i class="fa-solid fa-gear"></i> Settings</a><a href="#"><i class="fa-solid fa-sliders"></i> Activity Logs</a><hr><a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a></div>
        </div></div>
    </aside>

    <main class="main-content ierb-page">
        <header class="topbar">
            <div class="ierb-heading"><h1>IERB Progress</h1><p>Monitor research submissions, requirements, stages, and follow-ups.</p></div>
            <div class="top-controls"><div class="theme-toggle" id="themeToggle" title="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon"></i><i class="fa-solid fa-moon dark-icon"></i></div></div>
        </header>

        <section class="progress-overview" aria-labelledby="progressOverviewTitle">
            <div class="overview-heading"><div><span>Stage distribution</span><h2 id="progressOverviewTitle">Progress Overview</h2></div><strong id="overviewTotal">0 students</strong></div>
            <div class="stage-chart" id="stageChart" role="img" aria-label="Bar chart of students by IERB stage"></div>
        </section>

        <section class="ierb-controls" aria-label="IERB progress filters">
            <div class="ierb-search"><i class="fa-solid fa-magnifying-glass"></i><input id="ierbSearch" type="search" placeholder="Search student, ID, group, or research title"></div>
            <div class="ierb-filters">
                <select id="stageFilter" aria-label="Filter by stage"><option value="">All Stages</option><option>Stage 1</option><option>Stage 2</option><option>Stage 3</option><option>Stage 4</option><option>Stage 5</option><option>Completed</option></select>
                <select id="ierbStatusFilter" aria-label="Filter by status"><option value="">All Statuses</option><option>On Track</option><option>Pending</option><option>Delayed</option></select>
            </div>
        </section>

        <section class="ierb-directory" aria-labelledby="ierbTableTitle">
            <div class="ierb-table-heading"><div><h2 id="ierbTableTitle">Detailed Progress</h2><p id="ierbRecordCount">0 records</p></div><button type="button" class="ierb-add-link" id="addIerbEntry"><i class="fa-solid fa-plus"></i> Add IERB entry</button></div>
            <div class="ierb-table-wrap"><table class="ierb-table">
                <thead><tr><th>Student Name</th><th>Current Stage</th><th>Completed Stages</th><th>Pending Requirements</th><th>Submission Dates</th><th>Delay Status</th><th>Actions</th></tr></thead>
                <tbody id="ierbTableBody"></tbody>
            </table></div>
        </section>
    </main>
</div>

<div class="ierb-modal" id="ierbEntryModal" aria-hidden="true">
    <div class="ierb-modal-dialog ierb-entry-dialog" role="dialog" aria-modal="true" aria-labelledby="ierbEntryTitle">
        <div class="ierb-modal-heading"><div><span>IERB monitoring record</span><h2 id="ierbEntryTitle">Add IERB Entry</h2></div><button type="button" id="closeIerbEntry" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <form id="ierbEntryForm">
            <div class="ierb-entry-grid">
                <div><label for="entryStudentName">Student name</label><input id="entryStudentName" maxlength="120" required></div>
                <div><label for="entryStudentId">Student ID</label><input id="entryStudentId" maxlength="40" required></div>
                <div><label for="entryEmail">Email address</label><input id="entryEmail" type="email" maxlength="150" required></div>
                <div><label for="entryGroupId">Research group ID</label><input id="entryGroupId" maxlength="40" required></div>
                <div><label for="entryCourse">Course</label><input id="entryCourse" maxlength="80" required></div>
                <div><label for="entryStage">Current IERB stage</label><select id="entryStage"><option>Stage 1</option><option>Stage 2</option><option>Stage 3</option><option>Stage 4</option><option>Stage 5</option><option>Completed</option></select></div>
                <div class="ierb-entry-wide"><label for="entryResearchTitle">Research title</label><input id="entryResearchTitle" maxlength="250" required></div>
                <div><label for="entryRequirements">Pending requirements</label><input id="entryRequirements" maxlength="180" placeholder="e.g. Missing Ethics Consent Form"></div>
                <div><label for="entrySubmissionDate">Latest submission date</label><input id="entrySubmissionDate" type="date"></div>
                <div><label for="entryStatus">Delay status</label><select id="entryStatus"><option>On Track</option><option>Pending</option><option>Delayed</option></select></div>
            </div>
            <div class="ierb-modal-actions"><button type="button" class="ierb-secondary" id="cancelIerbEntry">Cancel</button><button type="submit" class="ierb-primary"><i class="fa-solid fa-floppy-disk"></i> Save entry</button></div>
        </form>
    </div>
</div>

<div class="ierb-modal" id="ierbActionModal" aria-hidden="true">
    <div class="ierb-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ierbActionTitle">
        <div class="ierb-modal-heading"><div><span id="ierbActionEyebrow">Update record</span><h2 id="ierbActionTitle">Add Note</h2></div><button type="button" id="closeIerbModal" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <form id="ierbActionForm"><label for="ierbActionText" id="ierbActionLabel">Internal note</label><textarea id="ierbActionText" rows="4" maxlength="500" required></textarea><div class="ierb-modal-actions"><button type="button" class="ierb-secondary" id="cancelIerbAction">Cancel</button><button type="submit" class="ierb-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></form>
    </div>
</div>
<script src="assets/js/ierbprog.js"></script>
</body>
</html>
