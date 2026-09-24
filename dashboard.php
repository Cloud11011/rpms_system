<?php
require __DIR__ . '/config.php';
$authUser = require_login('admin');
$user_name = $authUser['full_name'];
$user_email = $authUser['email'];
$user_role = $_SESSION['user_role'] ?? 'RPMS Administrator';
$profile_img = 'assets/images/default-avatar.svg';

$current_hour = (int)date('H');
if ($current_hour >= 5 && $current_hour < 12) {
    $greeting = "Good morning";
} elseif ($current_hour >= 12 && $current_hour < 18) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}
$current_date_formatted = date('l, F j, Y');

$pdo = db();
$total_researchers = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$pending_ierb = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Pending'")->fetchColumn();
$approved_ethics = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE stage = 'Completed'")->fetchColumn();
$delayed_submissions = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Delayed'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PRISM | Dashboard</title>
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
<link rel="stylesheet" href="assets/css/prism-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body data-reminder-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>">

<div class="container">
<!-- SIDEBAR WITH EASY-TO-UNDERSTAND LABELS -->
<aside class="sidebar">
<div class="sidebar-header">
<img src="assets/images/prismlogo1.png?v=2" alt="PRISM Assistant logo" class="sidebar-brand-logo">
<div class="sidebar-brand-copy">
<strong>IERB Progress &amp; Reporting System</strong>
<span>Centro Escolar University - Malolos &bull; RPMS</span>
</div>
</div>
<ul class="nav-links"><li class="active"><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><li><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li><li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li><li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li><li><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li><li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li><li><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li></ul>
<div class="sidebar-bottom">
<div class="profile-dropdown-wrapper">
<div class="sidebar-profile" id="profileToggle">
<img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile Picture">
<div class="profile-info">
<h4><?php echo htmlspecialchars($user_name); ?></h4>
<p><?php echo htmlspecialchars($user_role); ?></p>
</div>
<i class="fa-solid fa-chevron-down dropdown-arrow"></i>
</div>
<div class="profile-menu" id="profileMenu">
<a href="account.php#profile"><i class="fa-solid fa-user-gear"></i> Profile</a>
<a href="account.php#security"><i class="fa-solid fa-gear"></i> Settings</a>
<a href="account.php#activity"><i class="fa-solid fa-sliders"></i> Activity Logs</a>
<hr>
<a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
</div>
</div>
</div>
</aside>

<!-- MAIN CONTENT -->
<main class="main-content">
<header class="topbar">
<div class="search-box">
<i class="fa-solid fa-magnifying-glass"></i>
<input type="text" placeholder="Search groups, ethics stage, or document title...">
</div>
<div class="top-controls">
<div class="quick-actions-menu-wrap">
<button type="button" class="quick-actions-toggle" id="quickActionsToggle" title="Quick actions" aria-label="Quick actions" aria-expanded="false">
<i class="fa-solid fa-wand-magic-sparkles"></i>
</button>
<div class="quick-actions-dropdown" id="quickActionsDropdown">
<div class="quick-dropdown-heading">
<div><span>AI workspace</span><strong>Quick Actions</strong></div>
<i class="fa-solid fa-wand-magic-sparkles"></i>
</div>
<button type="button" class="quick-action" onclick="openReportModal()">
<span class="quick-action-icon report"><i class="fa-solid fa-file-pdf"></i></span>
<span class="quick-action-copy"><strong>Generate AI Report</strong><small>Create a consolidated RPMS PDF report</small></span>
<i class="fa-solid fa-chevron-right action-arrow"></i>
</button>
<button type="button" class="quick-action" onclick="openSummaryModal('uploaded document')">
<span class="quick-action-icon summary"><i class="fa-solid fa-file-lines"></i></span>
<span class="quick-action-copy"><strong>Summarize Document</strong><small>Open the document summary workspace</small></span>
<i class="fa-solid fa-chevron-right action-arrow"></i>
</button>
<div class="recent-ai-reports" id="recentAiReports">
<div class="recent-reports-title"><strong>Recent AI Reports</strong><i class="fa-solid fa-clock-rotate-left"></i></div>
<ul id="recentAiReportList"></ul>
</div>
</div>
</div>
<div class="theme-toggle" id="themeToggle" title="Toggle Light/Dark Theme">
<i class="fa-solid fa-sun light-icon"></i>
<i class="fa-solid fa-moon dark-icon"></i>
</div>
<div class="notification-menu-wrap">
<button type="button" class="notification-icon" id="notificationToggle" title="Notifications and confirmations" aria-label="Notifications and confirmations" aria-expanded="false">
<i class="fa-solid fa-bell"></i>
</button>
<div class="notification-dropdown" id="notificationDropdown">
<div class="notification-dropdown-heading">
<div><span>Status center</span><strong>Notifications &amp; Confirmations</strong></div>
<i class="fa-solid fa-envelope-circle-check"></i>
</div>
<div class="notification-empty" id="notificationEmptyState">
<i class="fa-regular fa-bell-slash"></i>
<p>No status updates, email confirmations, or automated notifications to display.</p>
</div>
<ul class="notification-list" id="notificationList" style="display:none;"></ul>
</div>
</div>
</div>
</header>
<section class="welcome-header">
<h1><?php echo $greeting; ?>, <span><?php echo htmlspecialchars($user_name); ?></span>! 👋</h1>
<p class="current-date"><i class="fa-regular fa-calendar"></i> <?php echo $current_date_formatted; ?></p>
</section>
<div data-prism-hint="admin-dashboard"></div>
<!-- AI SUMMARY BANNER -->
<section class="ai-summary-banner">
<div class="ai-summary-content">
<div class="ai-badge">
<i class="fa-solid fa-wand-magic-sparkles"></i> AI Workload Insights
</div>
<h2>No workload insights available</h2>
<p>Insights will appear here after research data has been added.</p>
</div>
<button class="ai-action-btn" onclick="openReportModal()"><i class="fa-solid fa-file-pdf"></i> Generate AI PDF Report</button>
</section>
<!-- STATISTICAL METRICS CARDS -->
<section class="cards">
<div class="card">
<i class="fa-solid fa-user-graduate"></i>
<h1 id="totalResearchersMetric"><?php echo $total_researchers; ?></h1>
<p>Total Student Researchers</p>
</div>
<div class="card">
<i class="fa-solid fa-hourglass-half"></i>
<h1 id="pendingIerbMetric"><?php echo $pending_ierb; ?></h1>
<p>Pending Ethics Review</p>
</div>
<div class="card">
<i class="fa-solid fa-circle-check"></i>
<h1 id="approvedEthicsMetric"><?php echo $approved_ethics; ?></h1>
<p>IERB Approved</p>
</div>
<div class="card card-alert">
<i class="fa-solid fa-triangle-exclamation"></i>
<h1 id="delayedSubmissionsMetric"><?php echo $delayed_submissions; ?></h1>
<p>Delayed Submissions</p>
</div>
</section>
<!-- CONTENT GRID -->
<section class="content">
<div class="left-column">
<div class="content-box">
<div class="table-header">
<h3><i class="fa-solid fa-bell-concierge"></i> Students Needing Attention <span data-prism-tip="Delayed students, documents waiting too long for an adviser, approved documents not yet submitted to RPMS, and students with no recent activity."></span></h3>
<a class="prism-btn is-secondary is-sm" href="ierbprog.php">Open IERB Progress</a>
</div>
<div data-prism-needs-attention data-limit="8"></div>
</div>
<!-- STAGE PIPELINE FUNNEL WIDGET -->
<div class="pipeline-card">
<div class="pipeline-title">
<i class="fa-solid fa-filter"></i> Research Stage Distribution
</div>
<div class="pipeline-steps">
<div class="step-item">
<span>Initial Submission</span>
<strong id="initialStageCount">0 Groups</strong>
</div>
<div class="step-item">
<span>Ethics Review</span>
<strong id="reviewStageCount">0 Groups</strong>
</div>
<div class="step-item delayed">
<span>Revision Phase</span>
<strong id="revisionStageCount">0 Delayed</strong>
</div>
<div class="step-item approved">
<span>Board Approved</span>
<strong id="approvedStageCount">0 Groups</strong>
</div>
</div>
</div>
<!-- REAL-TIME IERB TRACKING TABLE -->
<div class="content-box">
<div class="table-header">
<h3><i class="fa-solid fa-list-check"></i> IERB Progress Monitor</h3>
<div class="header-actions">
<select class="table-filter" id="courseFilter" aria-label="Filter by course">
<option value="">All courses</option>
</select>
<select class="table-filter" id="progressSort" aria-label="Sort by group progress">
<option value="default">Sort: Group progress</option>
<option value="high-to-low">Progress: High to low</option>
<option value="low-to-high">Progress: Low to high</option>
</select>
<a class="btn-secondary-sm prism-link-btn" href="ierb_api.php?action=export_csv" title="Download the student progress list as a CSV file"><i class="fa-solid fa-file-arrow-down"></i> Export CSV</a>
</div>
</div>
<table class="data-table">
<thead>
<tr>
<th>Protocol Code / Group</th>
<th>Course</th>
<th>Research Title</th>
<th>Stage</th>
<th>Pending Requirements</th>
<th>Overall Progress</th>
<th>Status & Email Log</th>
<th>Action</th>
</tr>
</thead>
<tbody id="ierbMonitorBody"></tbody>
</table>
</div>
<!-- DOCUMENT REPOSITORY SUMMARY -->
<div class="content-box">
<h3><i class="fa-solid fa-folder-tree"></i> Recent Repository Uploads</h3>
<div class="repo-list">
<p>No documents yet. Uploads from students and advisers will appear here.</p>
</div>
</div>
</div>
<div class="right-column">
<!-- INTERACTIVE CALENDAR WITH MONTH AND YEAR VIEWS -->
<div class="content-box calendar-box">
<div class="calendar-top-bar">
<div class="cal-nav">
<button class="cal-nav-btn" id="prevBtn" title="Previous"><i class="fa-solid fa-chevron-left"></i></button>
<span class="month-year" id="calendarLabel">July 2026</span>
<button class="cal-nav-btn" id="nextBtn" title="Next"><i class="fa-solid fa-chevron-right"></i></button>
</div>
<div class="cal-view-selector">
<button class="view-btn active" id="viewMonth">Month</button>
<button class="view-btn" id="viewYear">Year</button>
</div>
</div>
<!-- Month View Area -->
<div id="standardCalendarView">
<div class="calendar-grid" id="calendarGridHeader">
<div class="day-name">Sun</div>
<div class="day-name">Mon</div>
<div class="day-name">Tue</div>
<div class="day-name">Wed</div>
<div class="day-name">Thu</div>
<div class="day-name">Fri</div>
<div class="day-name">Sat</div>
</div>
<div class="calendar-grid" id="calendarDays"></div>
</div>
<!-- Year View Area -->
<div id="yearCalendarView" class="year-grid"></div>
<!-- REMINDERS AND FOLLOW-UPS -->
<div class="reminders-section">
<div class="reminders-header">
<h4><i class="fa-solid fa-calendar-check"></i> Tasks & Deadlines</h4>
<a class="add-btn" href="calendar.php" title="Add reminder" aria-label="Add reminder"><i class="fa-solid fa-plus"></i></a>
</div>
<ul class="reminder-list" id="dashboardReminderList"></ul>
</div>
</div>
</div>
</section>
</main>
</div>

<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map()); ?>;</script>
<script src="assets/js/prism-ui.js"></script>
<!-- MODAL: DASHBOARD DAY TASKS -->
<div class="modal-overlay" id="dashboardDayModal">
<div class="modal-card dashboard-day-modal" role="dialog" aria-modal="true" aria-labelledby="dashboardDayTitle">
<div class="day-modal-heading">
<div><span>Selected date</span><h3 id="dashboardDayTitle">Tasks &amp; Notes</h3></div>
<button type="button" class="day-modal-close" onclick="closeDashboardDay()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
</div>
<form id="dashboardTaskForm" autocomplete="off">
<label for="dashboardTaskTitle">Task reminder</label>
<input type="text" id="dashboardTaskTitle" maxlength="120" placeholder="What needs to be done?" required>
<div class="day-form-row">
<div><label for="dashboardTaskTime">Time <span>(optional)</span></label><input type="time" id="dashboardTaskTime"></div>
</div>
<label for="dashboardTaskNotes">Notes <span>(optional)</span></label>
<textarea id="dashboardTaskNotes" rows="3" maxlength="500" placeholder="Add helpful details..."></textarea>
<div class="modal-actions">
<button type="button" class="btn-secondary-sm" onclick="closeDashboardDay()">Close</button>
<button type="submit" class="small-btn"><i class="fa-solid fa-plus"></i> Add task</button>
</div>
</form>
<div class="dashboard-day-tasks" id="dashboardDayTasks"></div>
</div>
</div>

<!-- MODAL: AI DOCUMENT SUMMARY -->
<div class="modal-overlay" id="summaryModal">
<div class="modal-card">
<h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Key Takeaways Summary</h3>
<p id="summaryModalText">No document summary is available.</p>
<div class="ai-disclaimer">
<i class="fa-solid fa-circle-info"></i> Note: AI summaries are generated for quick administrative reference and must be verified by authorized RPMS personnel.
</div>
<div class="modal-actions">
<button class="btn-secondary-sm" onclick="closeSummaryModal()">Close</button>
</div>
</div>
</div>

<!-- MODAL: AI PDF REPORT OPTIONS -->
<div class="modal-overlay" id="reportModal">
<div class="modal-card">
<h3><i class="fa-solid fa-file-pdf"></i> Generate AI Progress Report</h3>
<p>Choose one of the two predefined report formats used by PRISM. The generated report must still be reviewed by RPMS before distribution.</p>
<div class="modal-actions">
<button class="btn-secondary-sm" onclick="closeReportModal()">Cancel</button>
<button class="btn-secondary-sm" onclick="generateAIReport('summary')"><i class="fa-solid fa-file-lines"></i> Summarized Report</button>
<button class="small-btn" onclick="generateAIReport('full')"><i class="fa-solid fa-file-contract"></i> Full Report</button>
</div>
</div>
</div>

<script>
// Profile Dropdown Toggle
const profileToggle = document.getElementById('profileToggle');
const profileMenu = document.getElementById('profileMenu');
profileToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    profileMenu.classList.toggle('show');
});
document.addEventListener('click', () => {
    profileMenu.classList.remove('show');
});

const quickActionsToggle = document.getElementById('quickActionsToggle');
const quickActionsDropdown = document.getElementById('quickActionsDropdown');
function closeQuickActions() {
    quickActionsDropdown.classList.remove('show');
    quickActionsToggle.setAttribute('aria-expanded', 'false');
}
quickActionsToggle.addEventListener('click', event => {
    event.stopPropagation();
    closeNotificationStatus();
    const isOpen = quickActionsDropdown.classList.toggle('show');
    quickActionsToggle.setAttribute('aria-expanded', String(isOpen));
});
quickActionsDropdown.addEventListener('click', event => event.stopPropagation());
document.addEventListener('click', closeQuickActions);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeQuickActions();
});

const notificationToggle = document.getElementById('notificationToggle');
const notificationDropdown = document.getElementById('notificationDropdown');
function closeNotificationStatus() {
    notificationDropdown.classList.remove('show');
    notificationToggle.setAttribute('aria-expanded', 'false');
}
notificationToggle.addEventListener('click', event => {
    event.stopPropagation();
    closeQuickActions();
    const isOpen = notificationDropdown.classList.toggle('show');
    notificationToggle.setAttribute('aria-expanded', String(isOpen));
});
notificationDropdown.addEventListener('click', event => event.stopPropagation());
document.addEventListener('click', closeNotificationStatus);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeNotificationStatus();
});

// Dark/Light Mode Switcher
const themeToggle = document.getElementById('themeToggle');
themeToggle.addEventListener('click', () => {
    const isDark = document.documentElement.classList.toggle('dark-theme');
    try {
        localStorage.setItem('prismTheme', isDark ? 'dark' : 'light');
    } catch (_) {}
});

// Student directory connection and IERB monitor (backed by ierb_api.php)
const courseFilter = document.getElementById('courseFilter');
const progressSort = document.getElementById('progressSort');
const progressTableBody = document.getElementById('ierbMonitorBody');
let monitorRecords = [];
const escapeMonitorHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[char]);
const progressRows = () => Array.from(progressTableBody.querySelectorAll('tr[data-course]'));
const progressNumber = value => parseFloat(String(value).replace('%', '')) || 0;

async function loadMonitorStudents() {
    try {
        const res = await fetch('ierb_api.php?action=list');
        const data = await res.json();
        monitorRecords = data.ok ? data.records : [];
    } catch (_) {
        monitorRecords = [];
    }
}

function updateMonitorMetrics(records) {
    document.getElementById('totalResearchersMetric').textContent = records.length;
    document.getElementById('pendingIerbMetric').textContent = records.filter(r => r.status === 'Pending').length;
    document.getElementById('approvedEthicsMetric').textContent = records.filter(r => r.stage === 'Completed').length;
    document.getElementById('delayedSubmissionsMetric').textContent = records.filter(r => r.status === 'Delayed').length;
    const setCount = (id, value, noun = 'Groups') => document.getElementById(id).textContent = `${value} ${noun}`;
    setCount('initialStageCount', records.filter(r => r.stage === 'Stage 1').length);
    setCount('reviewStageCount', records.filter(r => r.stage === 'Stage 2').length);
    setCount('revisionStageCount', records.filter(r => ['Stage 3', 'Stage 4'].includes(r.stage)).length, 'Delayed');
    setCount('approvedStageCount', records.filter(r => r.stage === 'Completed').length);
}

function populateCourseFilter(records) {
    const selected = courseFilter.value;
    courseFilter.querySelectorAll('option:not(:first-child)').forEach(option => option.remove());
    [...new Set(records.map(r => r.course).filter(Boolean))].sort().forEach(course => {
        const option = document.createElement('option');
        option.value = course;
        option.textContent = course;
        courseFilter.appendChild(option);
    });
    courseFilter.value = [...courseFilter.options].some(option => option.value === selected) ? selected : '';
}

function renderIerbMonitor() {
    progressTableBody.replaceChildren();
    updateMonitorMetrics(monitorRecords);
    populateCourseFilter(monitorRecords);
    if (!monitorRecords.length) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="8">' + PrismUI.emptyState({ icon: 'fa-user-graduate', title: 'No IERB records yet', text: 'Add a student and assign an adviser to start tracking progress.', actionLabel: 'Add a student', actionHref: 'admin_students.php' }) + '</td>';
        progressTableBody.appendChild(row);
        return;
    }
    monitorRecords.forEach(record => {
        const row = document.createElement('tr');
        row.dataset.course = record.course || '';
        row.dataset.progress = record.progress || '0';
        const statusClass = String(record.status || 'Pending').toLowerCase().replace(/\s+/g, '-');
        row.innerHTML = `
            <td><strong>${escapeMonitorHtml(record.protocolCode || record.groupId || record.studentId)}</strong>${record.protocolCode ? `<small class="prism-sub">${escapeMonitorHtml(record.groupId || record.studentId)}</small>` : ''}</td>
            <td>${escapeMonitorHtml(record.course || 'Not set')}</td>
            <td><div class="title-cell"><span>${escapeMonitorHtml(record.research || 'Research title not set')}</span><small>Lead: ${escapeMonitorHtml(record.name)}</small></div></td>
            <td>${PrismUI.badge(record.stage || 'Stage 1', { text: record.stageLabel || record.stage })}${PrismUI.docMini(record.docs)}</td>
            <td>${escapeMonitorHtml(record.requirements || 'None')}</td>
            <td><span class="progress-value">${escapeMonitorHtml(record.progress || '0')}%</span></td>
            <td>${PrismUI.badge(record.status || 'Pending')}</td>
            <td><button class="icon-btn" data-monitor-action="remind" title="Send follow-up email"><i class="fa-solid fa-paper-plane"></i></button><button class="icon-btn" data-monitor-action="summary" title="AI summary" data-id="${record.id}"><i class="fa-solid fa-file-lines"></i></button><button class="icon-btn prism-override-icon" data-monitor-action="override" title="Admin Override (always logged)" aria-label="Admin Override for ${escapeMonitorHtml(record.name)}"><i class="fa-solid fa-user-shield"></i></button></td>`;
        row.querySelector('[data-monitor-action="remind"]').addEventListener('click', () => sendMonitorFollowup(record));
        row.querySelector('[data-monitor-action="summary"]').addEventListener('click', () => openSummaryModal(record.name, record.id));
        row.querySelector('[data-monitor-action="override"]').addEventListener('click', async () => { if (await PrismUI.overrideStudent(record)) { await loadMonitorStudents(); renderIerbMonitor(); } });
        progressTableBody.appendChild(row);
    });
    filterAndSortProgress();
}

function filterAndSortProgress() {
    const rows = progressRows();
    rows.forEach(row => row.style.display = !courseFilter.value || row.dataset.course === courseFilter.value ? '' : 'none');
    if (progressSort.value !== 'default') {
        const direction = progressSort.value === 'high-to-low' ? -1 : 1;
        rows.sort((a, b) => direction * (progressNumber(a.dataset.progress) - progressNumber(b.dataset.progress))).forEach(row => progressTableBody.appendChild(row));
    }
}

async function sendMonitorFollowup(record) {
    if (!confirm(`Send an IERB follow-up email to ${record.name}?`)) return;
    try {
        const res = await fetch('send_followup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: record.email, name: record.name, studentDbId: record.id,
                studentId: record.studentId, stage: record.stage, status: record.status,
                requirements: record.requirements
            })
        });
        const data = await res.json();
        PrismUI.toast(data.message || (data.ok ? 'Follow-up sent.' : 'Could not send follow-up.'), data.ok ? 'success' : 'error');
    } catch (_) {
        PrismUI.toast('Could not reach the server to send the follow-up email. Try again in a moment.', 'error');
    }
}

courseFilter.addEventListener('change', filterAndSortProgress);
progressSort.addEventListener('change', filterAndSortProgress);

// Modal Control Handlers
async function openSummaryModal(targetName, documentId) {
    closeQuickActions();
    const textEl = document.getElementById('summaryModalText');
    document.getElementById('summaryModal').style.display = 'flex';
    if (!documentId) {
        textEl.innerText = 'Open a document from the Documents page and choose "Summarize" to generate an AI summary.';
        return;
    }
    textEl.innerText = `Generating AI summary for ${targetName}...`;
    try {
        const res = await fetch(`documents_api.php?action=summarize&id=${encodeURIComponent(documentId)}`, { method: 'POST' });
        const data = await res.json();
        textEl.innerText = data.ok ? data.summary : (data.message || 'No AI summary is available.');
    } catch (_) {
        textEl.innerText = 'The AI summary could not be generated right now.';
    }
}
function closeSummaryModal() {
    document.getElementById('summaryModal').style.display = 'none';
}
function openReportModal() {
    closeQuickActions();
    document.getElementById('reportModal').style.display = 'flex';
}
function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
}

let reportHistory = [];
async function loadReportHistory() {
    try {
        const res = await fetch('reports_api.php?action=list');
        const data = await res.json();
        reportHistory = data.ok ? data.reports : [];
    } catch (_) {
        reportHistory = [];
    }
}
function renderReportHistory() {
    const list = document.getElementById('recentAiReportList');
    list.replaceChildren();
    const history = reportHistory.slice(0, 3);
    if (!history.length) {
        const empty = document.createElement('li');
        empty.className = 'recent-report-empty';
        empty.textContent = 'No AI reports generated yet.';
        list.appendChild(empty);
        return;
    }
    history.forEach(report => {
        const item = document.createElement('li');
        item.style.cursor = 'pointer';
        item.title = 'Open PDF report';
        item.addEventListener('click', () => window.open(`reports_api.php?action=file&id=${encodeURIComponent(report.id)}`, '_blank'));
        const icon = document.createElement('i');
        icon.className = 'fa-regular fa-file-pdf';
        const copy = document.createElement('span');
        const title = document.createElement('strong');
        title.textContent = report.title;
        const date = document.createElement('small');
        date.textContent = new Date(report.generated_at).toLocaleString('en-PH', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
        });
        copy.append(title, date);
        item.append(icon, copy);
        list.appendChild(item);
    });
}
async function generateAIReport(mode = 'summary') {
    closeReportModal();
    try {
        const data = await PrismUI.postJson('reports_api.php?action=ai_report', { mode });
        await loadReportHistory();
        renderReportHistory();
        PrismUI.toast(data.aiUsed
            ? 'AI report generated. Review it before distribution.'
            : 'AI service was unavailable; PRISM used the local fallback summary.', data.aiUsed ? 'success' : 'info');
        window.open(`reports_api.php?action=file&id=${encodeURIComponent(data.report.id)}&download=1`, '_blank', 'noopener');
    } catch (e) {
        PrismUI.toast(e.message || 'The report could not be generated right now.', 'error');
    }
}

/* CALENDAR ENGINE */
const reminderStorageKey = `prismReminders:${document.body.dataset.reminderUser || 'default'}`;
let dashboardReminders = {};
function loadDashboardReminders() {
    try {
        const stored = JSON.parse(localStorage.getItem(reminderStorageKey));
        dashboardReminders = stored && typeof stored === 'object' ? stored : {};
    } catch (_) {
        dashboardReminders = {};
    }
}
const dateKey = (year, month, day) =>
    `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

let currentDate = new Date();
let currentView = 'month';
const monthNames = ["January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"];

const calendarLabel = document.getElementById('calendarLabel');
const calendarDays = document.getElementById('calendarDays');
const standardView = document.getElementById('standardCalendarView');
const yearView = document.getElementById('yearCalendarView');
const dashboardReminderList = document.getElementById('dashboardReminderList');
const viewMonthBtn = document.getElementById('viewMonth');
const viewYearBtn = document.getElementById('viewYear');

document.getElementById('prevBtn').addEventListener('click', () => navigateCalendar(-1));
document.getElementById('nextBtn').addEventListener('click', () => navigateCalendar(1));
viewMonthBtn.addEventListener('click', () => setView('month'));
viewYearBtn.addEventListener('click', () => setView('year'));

function setView(view) {
    currentView = view;
    [viewMonthBtn, viewYearBtn].forEach(b => b.classList.remove('active'));
    if(view === 'month') viewMonthBtn.classList.add('active');
    if(view === 'year') viewYearBtn.classList.add('active');
    renderCalendar();
}

function navigateCalendar(direction) {
    if (currentView === 'month') {
        currentDate.setMonth(currentDate.getMonth() + direction);
    } else if (currentView === 'year') {
        currentDate.setFullYear(currentDate.getFullYear() + direction);
    }
    renderCalendar();
}

function renderCalendar() {
    if (currentView === 'year') {
        standardView.style.display = 'none';
        yearView.style.display = 'grid';
        calendarLabel.textContent = currentDate.getFullYear();
        renderYearView();
    } else {
        standardView.style.display = 'block';
        yearView.style.display = 'none';
        calendarLabel.textContent = `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
        renderMonthView();
    }
}

function renderMonthView() {
    calendarDays.innerHTML = '';
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDayIndex = new Date(year, month, 1).getDay();
    const lastDate = new Date(year, month + 1, 0).getDate();
    const prevMonthLastDate = new Date(year, month, 0).getDate();

    for (let x = firstDayIndex; x > 0; x--) {
        calendarDays.innerHTML += `<div class="day text-muted">${prevMonthLastDate - x + 1}</div>`;
    }

    const today = new Date();
    for (let i = 1; i <= lastDate; i++) {
        let isToday = (i === today.getDate() && month === today.getMonth() && year === today.getFullYear()) ? 'active-day' : '';
        const key = dateKey(year, month, i);
        const hasEvent = Array.isArray(dashboardReminders[key]) && dashboardReminders[key].length ? 'has-event' : '';
        calendarDays.innerHTML += `<button type="button" class="day ${isToday} ${hasEvent}" onclick="openDashboardDay('${key}')" title="View tasks for ${key}">${i}</button>`;
    }

    const totalSlots = calendarDays.children.length;
    const remainingSlots = (Math.ceil(totalSlots / 7) * 7) - totalSlots;
    for (let j = 1; j <= remainingSlots; j++) {
        calendarDays.innerHTML += `<div class="day text-muted">${j}</div>`;
    }
}

function renderYearView() {
    yearView.innerHTML = '';
    const year = currentDate.getFullYear();
    const today = new Date();

    for (let m = 0; m < 12; m++) {
        let monthCard = document.createElement('div');
        monthCard.className = 'year-month-card';
        let monthHTML = `<span class="month-title">${monthNames[m]}</span>`;
        monthHTML += `<div class="mini-calendar-grid">`;
        const dayHeaders = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
        dayHeaders.forEach(day => {
            monthHTML += `<div class="mini-day-name">${day}</div>`;
        });
        const firstDayIndex = new Date(year, m, 1).getDay();
        const lastDate = new Date(year, m + 1, 0).getDate();
        const prevMonthLastDate = new Date(year, m, 0).getDate();

        for (let x = firstDayIndex; x > 0; x--) {
            monthHTML += `<div class="mini-day text-muted">${prevMonthLastDate - x + 1}</div>`;
        }
        for (let d = 1; d <= lastDate; d++) {
            let isToday = (d === today.getDate() && m === today.getMonth() && year === today.getFullYear()) ? 'active-day' : '';
            monthHTML += `<div class="mini-day ${isToday}">${d}</div>`;
        }
        const totalRendered = firstDayIndex + lastDate;
        const remainingSlots = (Math.ceil(totalRendered / 7) * 7) - totalRendered;
        for (let j = 1; j <= remainingSlots; j++) {
            monthHTML += `<div class="mini-day text-muted">${j}</div>`;
        }
        monthHTML += `</div>`;
        monthCard.innerHTML = monthHTML;
        monthCard.addEventListener('click', () => {
            currentDate.setMonth(m);
            setView('month');
        });
        yearView.appendChild(monthCard);
    }
}

function renderDashboardReminders() {
    dashboardReminderList.replaceChildren();
    const now = new Date();
    const todayKey = dateKey(now.getFullYear(), now.getMonth(), now.getDate());
    const upcoming = Object.entries(dashboardReminders)
        .filter(([key, tasks]) => key >= todayKey && Array.isArray(tasks))
        .flatMap(([key, tasks]) => tasks.map(task => ({ ...task, date: key })))
        .sort((a, b) => `${a.date} ${a.time || '99:99'}`.localeCompare(`${b.date} ${b.time || '99:99'}`))
        .slice(0, 6);
    if (!upcoming.length) {
        const empty = document.createElement('li');
        empty.className = 'reminder-empty';
        empty.textContent = 'No upcoming task reminders.';
        dashboardReminderList.appendChild(empty);
        return;
    }
    upcoming.forEach(reminder => {
        const item = document.createElement('li');
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'reminder-item';
        link.addEventListener('click', () => openDashboardDay(reminder.date));
        const details = document.createElement('div');
        details.className = 'reminder-details';
        const title = document.createElement('strong');
        title.textContent = reminder.title;
        const meta = document.createElement('span');
        const parsedDate = new Date(`${reminder.date}T00:00:00`);
        const formattedDate = parsedDate.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
        meta.innerHTML = `<i class="fa-regular fa-clock"></i> ${formattedDate}${reminder.time ? ` &bull; ${reminder.time}` : ''}`;
        details.append(title, meta);
        const tag = document.createElement('span');
        tag.className = 'reminder-tag';
        tag.textContent = reminder.date === todayKey ? 'Today' : 'Upcoming';
        link.append(details, tag);
        item.appendChild(link);
        dashboardReminderList.appendChild(item);
    });
}

let dashboardSelectedDate = '';
const dashboardDayModal = document.getElementById('dashboardDayModal');
const dashboardDayTitle = document.getElementById('dashboardDayTitle');
const dashboardTaskForm = document.getElementById('dashboardTaskForm');
const dashboardTaskTitle = document.getElementById('dashboardTaskTitle');
const dashboardTaskTime = document.getElementById('dashboardTaskTime');
const dashboardTaskNotes = document.getElementById('dashboardTaskNotes');
const dashboardDayTasks = document.getElementById('dashboardDayTasks');

function openDashboardDay(key) {
    dashboardSelectedDate = key;
    dashboardDayTitle.textContent = new Date(`${key}T00:00:00`).toLocaleDateString('en-PH', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });
    dashboardTaskForm.reset();
    renderDashboardDayTasks();
    dashboardDayModal.style.display = 'flex';
    dashboardTaskTitle.focus();
}
function closeDashboardDay() {
    dashboardDayModal.style.display = 'none';
    dashboardSelectedDate = '';
    dashboardTaskForm.reset();
}
function saveDashboardReminders() {
    try {
        localStorage.setItem(reminderStorageKey, JSON.stringify(dashboardReminders));
    } catch (_) {}
}
function renderDashboardDayTasks() {
    dashboardDayTasks.replaceChildren();
    const tasks = Array.isArray(dashboardReminders[dashboardSelectedDate])
        ? [...dashboardReminders[dashboardSelectedDate]].sort((a, b) => (a.time || '99:99').localeCompare(b.time || '99:99'))
        : [];
    if (!tasks.length) {
        const empty = document.createElement('p');
        empty.className = 'dashboard-day-empty';
        empty.textContent = 'No tasks or notes for this date yet.';
        dashboardDayTasks.appendChild(empty);
        return;
    }
    tasks.forEach(task => {
        const item = document.createElement('article');
        const content = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = task.title;
        content.appendChild(title);
        if (task.time) {
            const time = document.createElement('span');
            time.innerHTML = `<i class="fa-regular fa-clock"></i> ${task.time}`;
            content.appendChild(time);
        }
        if (task.notes) {
            const notes = document.createElement('p');
            notes.textContent = task.notes;
            content.appendChild(notes);
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.title = 'Delete task';
        remove.setAttribute('aria-label', 'Delete task');
        remove.innerHTML = '<i class="fa-solid fa-trash"></i>';
        remove.addEventListener('click', () => deleteDashboardTask(task.id));
        item.append(content, remove);
        dashboardDayTasks.appendChild(item);
    });
}
function deleteDashboardTask(id) {
    if (!confirm('Delete this reminder?')) return;
    dashboardReminders[dashboardSelectedDate] = dashboardReminders[dashboardSelectedDate].filter(task => task.id !== id);
    if (!dashboardReminders[dashboardSelectedDate].length) delete dashboardReminders[dashboardSelectedDate];
    saveDashboardReminders();
    renderDashboardDayTasks();
    renderCalendar();
    renderDashboardReminders();
}
dashboardTaskForm.addEventListener('submit', event => {
    event.preventDefault();
    const title = dashboardTaskTitle.value.trim();
    if (!title || !dashboardSelectedDate) return;
    const tasks = Array.isArray(dashboardReminders[dashboardSelectedDate]) ? dashboardReminders[dashboardSelectedDate] : [];
    tasks.push({
        id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
        title,
        time: dashboardTaskTime.value,
        notes: dashboardTaskNotes.value.trim()
    });
    dashboardReminders[dashboardSelectedDate] = tasks;
    saveDashboardReminders();
    dashboardTaskForm.reset();
    renderDashboardDayTasks();
    renderCalendar();
    renderDashboardReminders();
    dashboardTaskTitle.focus();
});
dashboardDayModal.addEventListener('click', event => {
    if (event.target === dashboardDayModal) closeDashboardDay();
});

(async function loadNotificationBell() {
    try {
        const res = await fetch('notifications_api.php?action=list');
        const data = await res.json();
        const items = data.ok ? data.notifications.slice(0, 8) : [];
        if (!items.length) return;
        document.getElementById('notificationEmptyState').style.display = 'none';
        const list = document.getElementById('notificationList');
        list.style.display = 'block';
        items.forEach(n => {
            const li = document.createElement('li');
            li.innerHTML = `<strong>${escapeMonitorHtml(n.subject || n.type)}</strong><span>${escapeMonitorHtml(n.recipient_name || n.recipient_email)} &bull; ${escapeMonitorHtml(n.status)}</span>`;
            list.appendChild(li);
        });
    } catch (_) { /* leave the default empty state */ }
})();

(async function loadRepositoryPreview() {
    try {
        const res = await fetch('documents_api.php?action=list');
        const data = await res.json();
        const docs = data.ok ? data.documents.slice(0, 5) : [];
        const container = document.querySelector('.repo-list');
        if (!container || !docs.length) return;
        container.innerHTML = '';
        docs.forEach(d => {
            const row = document.createElement('a');
            row.href = `documents.php`;
            row.className = 'repo-item';
            row.innerHTML = `<i class="fa-regular fa-file-lines"></i><span>${escapeMonitorHtml(d.originalName)}${d.versionNo > 1 ? ' (v' + d.versionNo + ')' : ''}</span><small>${escapeMonitorHtml(d.protocolCode || d.student || 'Unassigned')} &bull; ${PrismUI.badge(d.workflowState || d.reviewStatus, { small: true })}</small>`;
            container.appendChild(row);
        });
    } catch (_) { /* keep default empty state */ }
})();

loadDashboardReminders();
renderCalendar();
renderDashboardReminders();
(async function initDashboardData() {
    await loadMonitorStudents();
    renderIerbMonitor();
    await loadReportHistory();
    renderReportHistory();
})();

window.addEventListener('pageshow', () => {
    loadDashboardReminders();
    renderCalendar();
    renderDashboardReminders();
    loadMonitorStudents().then(renderIerbMonitor);
});
window.addEventListener('storage', event => {
    if (event.key === reminderStorageKey) {
        loadDashboardReminders();
        renderCalendar();
        renderDashboardReminders();
    }
});
</script>
</body>
</html>
