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
$total_researchers = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL')->fetchColumn();
$pending_ierb = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL AND status = 'Pending'")->fetchColumn();
$approved_ethics = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL AND stage = 'Completed'")->fetchColumn();
$delayed_submissions = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE archived_at IS NULL AND profile_completed_at IS NOT NULL AND status = 'Delayed'")->fetchColumn();
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
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-overview.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/research-resources.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php prism_frontend_styles(); ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-polish.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-ai-banner.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?> dashboard-page" data-reminder-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>">

<div class="container">
<!-- SIDEBAR WITH EASY-TO-UNDERSTAND LABELS -->
<?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'dashboard.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>

<!-- MAIN CONTENT -->
<main class="main-content">
<header class="topbar">
<div class="dashboard-heading"><h1>Dashboard</h1></div>
<div class="top-controls">
<button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button>
<div class="notification-menu-wrap">
<button type="button" class="notification-icon" id="notificationToggle" title="Notifications and confirmations" aria-label="Notifications and confirmations" aria-expanded="false">
<i class="fa-solid fa-bell"></i>
</button>
<div class="notification-dropdown" id="notificationDropdown">
<div class="notification-dropdown-heading">
<div><span>Status center</span><strong>Notifications &amp; Confirmations</strong></div>
<i class="fa-solid fa-envelope-circle-check"></i>
</div>
<div class="notification-empty" id="notificationEmptyState" role="status">
<i class="fa-regular fa-bell-slash"></i>
<p>No status updates, email confirmations, or automated notifications to display.</p>
</div>
<ul class="notification-list" id="notificationList" style="display:none;"></ul>
<a class="notification-view-all" href="admin_notifications.php">View all notifications</a>
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
<section class="ai-summary-banner ai-banner" data-report-state="idle" aria-label="AI progress insights">
<span class="ai-orb o1" aria-hidden="true"></span><span class="ai-orb o2" aria-hidden="true"></span><span class="ai-orb o3" aria-hidden="true"></span>
<svg class="ai-spark ai-spark-one" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg>
<svg class="ai-spark ai-spark-two" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg>
<svg class="ai-spark ai-spark-three" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg>
<div class="ai-summary-content ai-content">
<div class="ai-badge ai-chip">
<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg> AI PROGRESS INSIGHTS
</div>
<h2 class="ai-title" id="aiBannerTitle">No workload insights available</h2>
<p class="ai-body" id="aiBannerStatus" role="status" aria-live="polite">Generate a report to review the research progress currently available to RPMS.</p>
</div>
<div class="ai-banner-actions ai-actions" id="aiBannerActions">
<button type="button" id="aiBannerGenerate" class="ai-btn" onclick="openReportModal()"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Generate AI PDF report</button>
</div>
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
<div class="content-box dashboard-attention">
<div class="table-header attention-header">
<div class="attention-title">
<span class="attention-icon"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
<h3>Students needing attention</h3>
</div>
<a class="attention-link" href="ierbprog.php">Open IERB Progress <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
</div>
<div data-prism-needs-attention data-limit="8"></div>
</div>
<!-- STAGE PIPELINE FUNNEL WIDGET -->
<div class="pipeline-card dashboard-stage">
<div class="pipeline-heading">
<span class="pipeline-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h18l-7 8v7l-4-2v-5Z"/></svg></span>
<div>
<h3 class="pipeline-title">Research stage distribution</h3>
</div>
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
<!-- INTERACTIVE CALENDAR WITH MONTH AND YEAR VIEWS -->
<div class="content-box calendar-box">
<div class="calendar-top-bar">
<div class="cal-nav">
<button class="cal-nav-btn" id="prevBtn" title="Previous"><i class="fa-solid fa-chevron-left"></i></button>
<span class="month-year" id="calendarLabel">July 2026</span>
<button class="cal-nav-btn" id="nextBtn" title="Next"><i class="fa-solid fa-chevron-right"></i></button>
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
<p class="prism-deadline-load" id="dashboardDeadlineState" role="status">Loading official deadlines...</p>
<!-- REMINDERS AND FOLLOW-UPS -->
<div class="reminders-section">
<div class="reminders-header">
<h4><i class="fa-solid fa-calendar-check"></i> Tasks & Deadlines</h4>
<a class="add-btn" href="calendar.php" title="Add reminder" aria-label="Add reminder"><i class="fa-solid fa-plus"></i></a>
</div>
<ul class="reminder-list" id="dashboardReminderList"></ul>
</div>
</div>
<!-- REAL-TIME IERB TRACKING TABLE -->
<div class="content-box dashboard-monitor">
<div class="table-header">
<h3><i class="fa-solid fa-list-check"></i> IERB Progress Monitor</h3>
<div class="header-actions">
<a href="ierbprog.php" class="prism-btn dashboard-open-ierb">View All</a>
<select class="table-filter" id="courseFilter" aria-label="Filter by course">
<option value="">All courses</option>
</select>
<select class="table-filter" id="progressSort" aria-label="Sort by group progress">
<option value="default">Sort: Group progress</option>
<option value="high-to-low">Progress: High to low</option>
<option value="low-to-high">Progress: Low to high</option>
</select>
<a class="prism-btn is-secondary" href="ierb_api.php?action=export_csv" title="Download the student progress list as a CSV file"><i class="fa-solid fa-file-arrow-down"></i> Export CSV</a>
</div>
</div>
<div class="dashboard-table-scroll" tabindex="0" role="region" aria-label="IERB progress records"><table class="data-table">
<thead>
<tr>
<th>Protocol Code / Group</th>
<th>Course</th>
<th>Research Title</th>
<th>Stage</th>
<th>Pending Requirements</th>
<th>Overall Progress</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody id="ierbMonitorBody"></tbody>
</table></div>
</div>
<!-- DOCUMENT REPOSITORY SUMMARY -->
<div class="content-box dashboard-repository">
<div class="repository-header">
<div class="repository-title">
<span class="repository-title-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h6l2 2h10v11H3Z"/></svg></span>
<div><h3>Recent repository uploads</h3><p id="repositoryCount" role="status">Loading recent uploads...</p></div>
</div>
<div class="repository-header-actions">
<div id="repositoryFilters" class="prism-filter-pills" aria-label="Recent upload filters"></div>
<a class="repository-view-all" href="documents.php">View all</a>
</div>
</div>
<div class="repo-list">
<p>No documents yet. Uploads from students and advisers will appear here.</p>
</div>
</div>
</section>
<?php require __DIR__ . '/includes/research_resources.php'; ?>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>

<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
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

<!-- MODAL: AI PDF REPORT OPTIONS -->
<div class="modal-overlay" id="reportModal">
<div class="modal-card">
<h3><i class="fa-solid fa-file-pdf"></i> Generate AI Progress Report</h3>
<p>Choose one of the two predefined report formats used by PRISM. The generated report must still be reviewed by RPMS before distribution.</p>
<div class="modal-actions">
<button class="btn-secondary-sm" onclick="closeReportModal()">Cancel</button>
<button class="btn-secondary-sm" onclick="generateAIReport('summary',this)"><i class="fa-solid fa-file-lines"></i> Summarized Report</button>
<button class="small-btn" onclick="generateAIReport('full',this)"><i class="fa-solid fa-file-contract"></i> Full Report</button>
</div>
</div>
</div>

<script>
const notificationToggle = document.getElementById('notificationToggle');
const notificationDropdown = document.getElementById('notificationDropdown');
function positionNotificationDropdown() {
    notificationDropdown.style.transform = '';
    if (!notificationDropdown.classList.contains('show')) return;
    const bounds = notificationDropdown.getBoundingClientRect();
    const shift = bounds.left < 16 ? 16 - bounds.left
        : (bounds.right > window.innerWidth - 16 ? window.innerWidth - 16 - bounds.right : 0);
    notificationDropdown.style.transform = `translateX(${shift}px)`;
}
window.addEventListener('resize', positionNotificationDropdown);
function closeNotificationStatus() {
    notificationDropdown.classList.remove('show');
    notificationToggle.setAttribute('aria-expanded', 'false');
}
notificationToggle.addEventListener('click', event => {
    event.stopPropagation();
    const isOpen = notificationDropdown.classList.toggle('show');
    notificationToggle.setAttribute('aria-expanded', String(isOpen));
    positionNotificationDropdown();
});
notificationDropdown.addEventListener('click', event => event.stopPropagation());
document.addEventListener('click', closeNotificationStatus);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeNotificationStatus();
});

// Dark/Light Mode Switcher
const themeToggle = document.getElementById('themeToggle');
themeToggle?.addEventListener('click', () => {
    const isDark = document.documentElement.classList.toggle('dark-theme');
    try {
        localStorage.setItem('prismTheme', isDark ? 'dark' : 'light');
    } catch (_) {}
});

// Student directory connection and IERB monitor (backed by ierb_api.php)
const courseFilter = document.getElementById('courseFilter');
const progressSort = document.getElementById('progressSort');
const progressTableBody = document.getElementById('ierbMonitorBody');
let monitorRecords = [], monitorOverview = [], monitorCourses = [];
let monitorError = '';
let monitorRequest = 0;
const escapeMonitorHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[char]);
const progressRows = () => Array.from(progressTableBody.querySelectorAll('tr[data-course]'));
const progressNumber = value => parseFloat(String(value).replace('%', '')) || 0;

async function loadMonitorStudents() {
    const requestId = ++monitorRequest;
    progressTableBody.setAttribute('aria-busy', 'true');
    try {
        const data = await PrismUI.request('ierb_api.php?' + new URLSearchParams({action:'list',preview:10,course:courseFilter.value,sort:progressSort.value}));
        if (requestId !== monitorRequest) return;
        monitorRecords = data.records || [];
        monitorOverview = data.overview || monitorRecords.map(r => ({stage:r.stage,status:r.status,c:1}));
        monitorCourses = data.courses || [...new Set(monitorRecords.map(r => r.course).filter(Boolean))];
        monitorError = '';
    } catch (error) {
        if (requestId !== monitorRequest) return;
        monitorRecords = [];
        monitorError = error.message;
    } finally {
        if (requestId === monitorRequest) progressTableBody.setAttribute('aria-busy', 'false');
    }
}

function updateMonitorMetrics() {
    const count = predicate => monitorOverview.filter(predicate).reduce((sum,r) => sum + Number(r.c),0);
    document.getElementById('totalResearchersMetric').textContent = count(() => true);
    document.getElementById('pendingIerbMetric').textContent = count(r => r.status === 'Pending');
    document.getElementById('approvedEthicsMetric').textContent = count(r => r.stage === 'Completed');
    document.getElementById('delayedSubmissionsMetric').textContent = count(r => r.status === 'Delayed');
    const setCount = (id,value) => {
        const host=document.getElementById(id),number=document.createElement('b'),noun=document.createElement('small');
        number.textContent=value;
        noun.textContent=id==='revisionStageCount'?'delayed':(value===1?'group':'groups');
        host.replaceChildren(number,noun);
    };
    setCount('initialStageCount',count(r => r.stage === 'Stage 1'));
    setCount('reviewStageCount',count(r => r.stage === 'Stage 2'));
    setCount('revisionStageCount',count(r => ['Stage 3','Stage 4'].includes(r.stage)));
    setCount('approvedStageCount',count(r => r.stage === 'Completed'));
}

function populateCourseFilter(records) {
    const selected = courseFilter.value;
    courseFilter.querySelectorAll('option:not(:first-child)').forEach(option => option.remove());
    monitorCourses.slice().sort().forEach(course => {
        const option = document.createElement('option');
        option.value = course;
        option.textContent = course;
        courseFilter.appendChild(option);
    });
    courseFilter.value = [...courseFilter.options].some(option => option.value === selected) ? selected : '';
}

function renderIerbMonitor() {
    progressTableBody.replaceChildren();
    progressTableBody.closest('table').classList.toggle('is-empty', !!monitorError || monitorRecords.length === 0);
    if (monitorError) {
        ['totalResearchersMetric','pendingIerbMetric','approvedEthicsMetric','delayedSubmissionsMetric',
         'initialStageCount','reviewStageCount','revisionStageCount','approvedStageCount'].forEach(id => {
            document.getElementById(id).textContent = 'Unavailable';
        });
        const row = document.createElement('tr');
        const cell = document.createElement('td'); cell.colSpan = 8;
        cell.textContent = 'Could not load IERB records. ' + monitorError;
        row.appendChild(cell); progressTableBody.appendChild(row);
        return;
    }
    updateMonitorMetrics(monitorRecords);
    populateCourseFilter(monitorRecords);
    if (!monitorRecords.length) {
        const row = document.createElement('tr');
        row.className = 'ierb-monitor-empty-row';
        row.innerHTML = '<td colspan="8">' + PrismUI.emptyState({ icon: 'fa-user-graduate', title: 'No IERB records yet', text: 'Add a student and assign an adviser to start tracking progress.', actionLabel: 'Add a student', actionHref: 'admin_students.php' }) + '</td>';
        progressTableBody.appendChild(row);
        return;
    }
    monitorRecords.forEach(record => {
        const row = document.createElement('tr');
        row.dataset.course = record.course || '';
        row.dataset.progress = record.progress || '0';
        const statusClass = record.status === 'Delayed' ? 'is-delayed' : (record.status === 'On Track' ? 'is-on-track' : 'is-pending');
        const progress = Math.max(0, Math.min(100, progressNumber(record.progress)));
        row.className = `ierb-monitor-row ${statusClass}`;
        row.innerHTML = `
            <td><strong>${escapeMonitorHtml(record.protocolCode || record.groupId || record.studentId)}</strong>${record.protocolCode ? `<small class="prism-sub">${escapeMonitorHtml(record.groupId || record.studentId)}</small>` : ''}</td>
            <td>${escapeMonitorHtml(record.course || 'Not set')}</td>
            <td><div class="title-cell"><span>${escapeMonitorHtml(record.research || 'Research title not set')}</span><small>Lead: ${escapeMonitorHtml(record.name)}</small></div></td>
            <td><span class="stage-tag" title="${escapeMonitorHtml(record.stage || 'Stage 1')}">${escapeMonitorHtml(record.stageLabel || record.stage || 'Stage 1')}</span>${PrismUI.docMini(record.docs)}</td>
            <td>${escapeMonitorHtml(record.requirements || 'None')}</td>
            <td><div class="ierb-card-progress"><div><span>Overall progress</span><strong>${progress}%</strong></div><span class="ierb-progress-track" role="progressbar" aria-label="Overall progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span style="width:${progress}%"></span></span></div></td>
            <td>${PrismUI.badge(record.status || 'Pending')}</td>
            <td><button class="icon-btn" data-monitor-action="remind" title="Send follow-up email"><i class="fa-solid fa-paper-plane"></i></button><a class="icon-btn" data-monitor-action="documents" href="documents.php" title="Open Documents" aria-label="Open Documents">Open Documents</a><button class="icon-btn prism-override-icon" data-monitor-action="override" title="Admin Override (always logged)" aria-label="Admin Override for ${escapeMonitorHtml(record.name)}"><i class="fa-solid fa-user-shield"></i></button></td>`;
        row.querySelector('[data-monitor-action="remind"]').addEventListener('click', e => PrismUI.runAction(e.currentTarget,'Sending...',() => sendMonitorFollowup(record)));
        row.querySelector('[data-monitor-action="override"]').addEventListener('click', e => PrismUI.runAction(e.currentTarget,'Processing...',async () => { if (await PrismUI.overrideStudent(record)) { await loadMonitorStudents(); renderIerbMonitor(); } }));
        PrismUI.actionMenu(row.cells[7],{primary:row.querySelector('[data-monitor-action="documents"]')});
        progressTableBody.appendChild(row);
        PrismUI.compactRecord(row,2,6,7,[],[0,1,3,4,5]);
        const card = row.querySelector('.prism-record-card');
        if (card) card.classList.add('ierb-monitor-card', statusClass);
    });
}

function filterAndSortProgress() {
    loadMonitorStudents().then(renderIerbMonitor);
}

async function sendMonitorFollowup(record) {
    const answer = await PrismUI.confirm({
        title:'Send follow-up', icon:'fa-paper-plane', confirmText:'Send follow-up',
        message:`Send the standard IERB progress follow-up to ${record.name} at ${record.email}?`
    });
    if (!answer) return;
    try {
        const data = await PrismUI.postJson('send_followup.php', { studentDbId: record.id });
        PrismUI.toast(data.message || 'Follow-up processed.', data.ok ? 'success' : 'error');
    } catch (e) {
        PrismUI.toast(e.message || 'Could not send the follow-up email.', 'error');
    }
}

courseFilter.addEventListener('change', filterAndSortProgress);
progressSort.addEventListener('change', filterAndSortProgress);

// Modal Control Handlers
function setAIBannerActions(state, reportUrl = '') {
    const actions = document.getElementById('aiBannerActions');
    actions.replaceChildren();

    if (state === 'busy') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ai-btn';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<svg class="ai-spin" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9"/></svg>Generating report…<span class="ai-bar" aria-hidden="true"></span>';
        actions.appendChild(button);
        return;
    }

    if (state === 'ready') {
        const download = document.createElement('a');
        download.id = 'aiBannerDownload';
        download.className = 'ai-btn solid';
        download.href = reportUrl;
        download.target = '_blank';
        download.rel = 'noopener';
        download.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg>Download report';
        actions.appendChild(download);

        const again = document.createElement('button');
        again.type = 'button';
        again.className = 'ai-btn ghost';
        again.textContent = 'Generate again';
        again.addEventListener('click', openReportModal);
        actions.appendChild(again);
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'aiBannerGenerate';
    button.className = 'ai-btn';
    button.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 14h6M9 17h4"/></svg>' + (state === 'error' ? 'Try again' : 'Generate AI PDF report');
    button.addEventListener('click', openReportModal);
    actions.appendChild(button);
}

async function openReportModal() {
    if(dashboardGenerating)return;
    const options = '<fieldset class="report-choices" aria-label="Report format">'
        + '<label><input type="radio" name="aiBannerMode" value="summary" checked><span class="report-card"><span class="report-radio" aria-hidden="true"></span><span class="report-text"><strong>AI Summary</strong><small>A short overview of where each research group stands.</small></span></span></label>'
        + '<label><input type="radio" name="aiBannerMode" value="full"><span class="report-card"><span class="report-radio" aria-hidden="true"></span><span class="report-text"><strong>AI Full Report</strong><small>The detailed version with per-group progress and delays.</small></span></span></label>'
        + '</fieldset><p class="report-note">Reports use the current authorized research records.</p>';
    const pending = PrismUI.confirm({
        title:'Generate AI PDF report',
        icon:'fa-file-pdf',
        dialogClass:'report-dialog',
        confirmText:'Generate report',
        message:'Pick the kind of report you need.',
        extraHtml:options,
        collect:d=>({mode:d.querySelector('input[name="aiBannerMode"]:checked').value})
    });
    const dialog = document.querySelector('.prism-overlay .prism-dialog.report-dialog');
    const initialChoice = dialog ? dialog.querySelector('input[name="aiBannerMode"]:checked') : null;
    if(initialChoice)initialChoice.focus();
    const choice = await pending;
    if(choice)await generateAIReport(choice.values.mode,document.getElementById('aiBannerGenerate'));
}
function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
}

let dashboardGenerating = false;
async function generateAIReport(mode = 'summary', button) {
    if(dashboardGenerating)return;
    dashboardGenerating = true;
    const release = button ? PrismUI.busy(button,'Generating...') : () => {};
    const banner=document.querySelector('.ai-summary-banner'),title=document.getElementById('aiBannerTitle'),status=document.getElementById('aiBannerStatus');
    banner.dataset.reportState='busy';banner.setAttribute('aria-busy','true');title.textContent='Preparing your insights';status.textContent='Reviewing current research progress. This takes a few seconds.';setAIBannerActions('busy');
    closeReportModal();
    try {
        const data = await PrismUI.postJson('reports_api.php?action=ai_report', { mode });
        if(!data.report?.id)throw new Error('The report response did not include a downloadable report.');
        banner.dataset.reportState='ready';title.textContent='Your report is ready';status.textContent=data.aiUsed?'Your AI progress report is ready. Review it before distribution.':'Your report uses the local fallback summary because the AI service was unavailable.';
        setAIBannerActions('ready',`reports_api.php?action=file&id=${encodeURIComponent(data.report.id)}&download=1`);
        PrismUI.toast(data.aiUsed
            ? 'AI report generated. Review it before distribution.'
            : 'AI service was unavailable; PRISM used the local fallback summary.', data.aiUsed ? 'success' : 'info');
    } catch (e) {
        banner.dataset.reportState='error';title.textContent='Report could not be generated';status.textContent=e.message||'Please try again.';
        setAIBannerActions('error');
        PrismUI.toast(e.message || 'The report could not be generated right now.', 'error');
    } finally { dashboardGenerating=false;banner.setAttribute('aria-busy','false'); release?.(); }
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
const monthNames = ["January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"];

const calendarLabel = document.getElementById('calendarLabel');
const calendarDays = document.getElementById('calendarDays');
const dashboardReminderList = document.getElementById('dashboardReminderList');

document.getElementById('prevBtn').addEventListener('click', () => navigateCalendar(-1));
document.getElementById('nextBtn').addEventListener('click', () => navigateCalendar(1));

function navigateCalendar(direction) {
    currentDate.setMonth(currentDate.getMonth() + direction);
    renderCalendar();
}

function renderCalendar() {
    calendarLabel.textContent = `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
    renderMonthView();
    window.PrismDashboardDeadlines?.refresh(currentDate, 'month');
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
        calendarDays.innerHTML += `<button type="button" class="day ${isToday} ${hasEvent}" data-deadline-date="${key}" onclick="openDashboardDay('${key}')" title="View tasks for ${key}">${i}<i class="fa-solid fa-calendar-day prism-deadline-marker" aria-hidden="true" hidden></i></button>`;
    }

    const totalSlots = calendarDays.children.length;
    const remainingSlots = (Math.ceil(totalSlots / 7) * 7) - totalSlots;
    for (let j = 1; j <= remainingSlots; j++) {
        calendarDays.innerHTML += `<div class="day text-muted">${j}</div>`;
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
        return true;
    } catch (_) {
        PrismUI.toast('This reminder could not be saved in this browser. Check browser storage settings or clear unused site data.', 'error');
        return false;
    }
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
async function deleteDashboardTask(id) {
    const answer = await PrismUI.confirm({
        title:'Delete reminder', icon:'fa-trash', tone:'danger', confirmText:'Delete',
        dialogClass:'calendar-delete-dialog',
        message:'Delete this personal reminder from this browser?'
    });
    if (!answer) return;
    const previous = dashboardReminders[dashboardSelectedDate] ? dashboardReminders[dashboardSelectedDate].slice() : null;
    dashboardReminders[dashboardSelectedDate] = dashboardReminders[dashboardSelectedDate].filter(task => task.id !== id);
    if (!dashboardReminders[dashboardSelectedDate].length) delete dashboardReminders[dashboardSelectedDate];
    if (!saveDashboardReminders()) {
        if (previous) dashboardReminders[dashboardSelectedDate] = previous;
        return;
    }
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
    if (!saveDashboardReminders()) {
        tasks.pop();
        if (!tasks.length) delete dashboardReminders[dashboardSelectedDate];
        return;
    }
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
        const data = await PrismUI.request('notifications_api.php?action=list&preview=5&personal=1');
        const items = data.ok ? data.notifications.slice(0, 5) : [];
        if (!items.length) return;
        document.getElementById('notificationEmptyState').style.display = 'none';
        const list = document.getElementById('notificationList');
        list.style.display = 'block';
        items.forEach(n => {
            const li = document.createElement('li');
            li.innerHTML = `<strong>${escapeMonitorHtml(n.subject || n.type)}</strong><span>${escapeMonitorHtml(n.recipient_name || n.recipient_email)} &bull; ${escapeMonitorHtml(n.status)}</span><time>${escapeMonitorHtml(n.created_at ? new Date(String(n.created_at).replace(' ', 'T')).toLocaleString('en-PH') : '')}</time>`;
            list.appendChild(li);
        });
    } catch (_) { /* leave the default empty state */ }
})();

(async function loadRepositoryPreview() {
    const container=document.querySelector('.repo-list'),count=document.getElementById('repositoryCount'),filters=document.getElementById('repositoryFilters');
    try {
        const data=await PrismUI.request('documents_api.php?action=list&preview=5');
        const docs=data.ok?data.documents.slice(0,5):[];
        let selected='';
        const definitions=[['','All'],['Pending Adviser Review','Pending review'],['Needs Revision','Needs revision'],['Submitted to RPMS','Submitted to RPMS']];
        const render=()=>{
            const shown=docs.filter(doc=>!selected||doc.workflowState===selected);
            count.textContent='Showing '+shown.length+' of '+docs.length+' uploads';
            filters.replaceChildren();
            definitions.forEach(([value,label])=>{
                const button=document.createElement('button');
                button.type='button';
                button.className='repository-filter';
                button.setAttribute('aria-pressed',String(selected===value));
                button.dataset.repositoryState=value;
                const labelNode=document.createElement('span');
                labelNode.textContent=label;
                const countNode=document.createElement('small');
                countNode.textContent=value?docs.filter(d=>d.workflowState===value).length:docs.length;
                button.append(labelNode,countNode);
                button.addEventListener('click',()=>{
                    selected=value;
                    render();
                    [...filters.querySelectorAll('button')].find(b=>b.dataset.repositoryState===selected)?.focus();
                });
                filters.append(button);
            });
            container.replaceChildren();
            shown.forEach(d=>{
                const row=document.createElement('a');
                row.href='documents.php';
                row.className='repo-item';
                row.title=d.originalName;
                const extension=(d.originalName.match(/\.([A-Za-z0-9]{1,8})$/)?.[1]||'FILE').toUpperCase();
                const status=d.workflowState||d.reviewStatus;
                row.innerHTML='<span class="repo-extension">'+escapeMonitorHtml(extension)+'</span><span class="repo-file-copy"><strong>'+escapeMonitorHtml(d.originalName)+(d.versionNo>1?' (v'+d.versionNo+')':'')+'</strong><small>Uploaded by '+escapeMonitorHtml(d.uploadedBy||'Not recorded')+'</small></span>'+PrismUI.badge(status,{small:true})+'<span class="repo-item-arrow" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>';
                container.append(row);
            });
            if(!shown.length){const empty=document.createElement('p');empty.textContent=docs.length?'No matching uploads in the recent preview. View All to search the repository.':'No current uploads yet.';container.append(empty);}
        };render();
    } catch (_) {count.textContent='Recent uploads unavailable';container.textContent='Could not load uploads. Reload to try again.';}
})();

loadDashboardReminders();
renderCalendar();
renderDashboardReminders();
(async function initDashboardData() {
    await loadMonitorStudents();
    renderIerbMonitor();
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
