<?php
require_once __DIR__ . '/config.php';
$authUser = require_login('student');
$portalRole = 'Student';
$userName = $authUser['full_name'];
$userEmail = $authUser['email'];
$userId = $authUser['ref_id'] ?: $authUser['username'];
$profileImg = 'assets/images/default-avatar.svg';
$identityKey = hash('sha256', $portalRole . '|' . $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($portalRole); ?> Portal | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/role-portal.css">
<link rel="stylesheet" href="assets/css/calendar.css">
<link rel="stylesheet" href="assets/css/role-topnav.css">
<link rel="stylesheet" href="assets/css/prism-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body data-portal-key="<?php echo htmlspecialchars($identityKey, ENT_QUOTES); ?>" data-role="<?php echo htmlspecialchars($portalRole, ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars($userName, ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($userEmail, ENT_QUOTES); ?>">
<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map()); ?>;</script>
<nav class="portal-navbar" aria-label="Portal navigation">
<button class="portal-brand" type="button" data-go="dashboard" aria-label="PRISM dashboard"><img src="assets/images/prismlogo1.png?v=2" alt="PRISM Logo"></button>
<ul class="portal-nav-links" id="portalNav">
<li class="active"><button data-page="dashboard">Dashboard</button></li>
<li><button data-page="calendar">Calendar</button></li>
<li><button data-page="progress">IERB Progress</button></li>
<li><button data-page="submit">Document Submission</button></li>
<li><button data-page="documents">My Documents</button></li>
</ul>
<div class="portal-nav-right">
<button class="portal-help" id="helpButton" type="button" title="Help and support" aria-label="Help and support"><i class="fa-regular fa-circle-question"></i></button>
<button class="portal-notification" type="button" data-go="notifications" title="Notifications" aria-label="Notifications"><i class="fa-solid fa-bell"></i><b class="nav-badge" id="navBadge">0</b></button>
<div class="portal-profile-menu">
<button class="portal-profile-btn" type="button" aria-haspopup="true"><img id="navProfileImage" src="<?php echo htmlspecialchars($profileImg, ENT_QUOTES); ?>" alt="Profile picture"><span><strong id="sideName"><?php echo htmlspecialchars($userName); ?></strong><small><?php echo htmlspecialchars($portalRole); ?></small></span><i class="fa-solid fa-chevron-down"></i></button>
<div class="portal-profile-dropdown">
<button type="button" data-go="profile"><i class="fa-solid fa-user"></i> Profile</button>
    <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Log out</a>
</div>
</div>
</div>
</nav>
<div class="container">
<main class="main-content portal-main">
<header class="topbar"><div><h1 id="pageTitle">Dashboard</h1><p id="pageSubtitle">Your research and IERB progress at a glance.</p></div><button class="theme-toggle" id="themeToggle" title="Toggle theme"><i class="fa-solid fa-sun light-icon"></i><i class="fa-solid fa-moon dark-icon"></i></button></header>
<section class="portal-page active" data-section="dashboard">
<div class="welcome-card"><div><span><?php echo htmlspecialchars($portalRole); ?> workspace</span><h2>Welcome back, <b id="welcomeName"><?php echo htmlspecialchars($userName); ?></b>!</h2><p class="dashboard-greeting-date"><i class="fa-regular fa-calendar"></i> <b id="dashboardCurrentDate"></b></p><p id="researchTitle">No research details yet.</p></div><button class="primary-btn" data-go="submit"><i class="fa-solid fa-upload"></i> Submit document</button></div>
<div data-prism-student-workflow="compact"></div>
<section class="dashboard-priority" aria-label="My IERB progress"><article class="dashboard-progress-feature"><div class="dashboard-progress-copy"><span>IERB Progress</span><h2 id="dashboardStageValue">Not started</h2><div class="dashboard-progress-track" role="progressbar" aria-label="IERB progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="dashboardProgressTrack"><i id="dashboardProgressBar"></i></div><strong id="dashboardProgressPercent">0%</strong><p>Current Status: <b id="dashboardStatusValue">Not started</b></p></div><button class="dashboard-detail-button" type="button" data-go="progress">View Details <i class="fa-solid fa-arrow-right"></i></button></article><button class="dashboard-pending-card" type="button" data-go="progress"><i class="fa-solid fa-triangle-exclamation"></i><span><strong><b id="dashboardPendingValue">0</b> Pending Requirements</strong><small>View requirements and required actions</small></span><i class="fa-solid fa-chevron-right"></i></button></section>
<section class="dashboard-status-cards" aria-label="Dashboard status cards"><button type="button" data-go="documents"><i class="fa-solid fa-folder-open"></i><span>My Submissions</span><strong id="dashboardSubmissionValue">0 documents</strong><small id="dashboardSubmissionStatus">No submissions yet</small></button><button type="button" data-go="calendar"><i class="fa-solid fa-calendar-day"></i><span>Upcoming Reminder</span><strong id="dashboardDeadlineValue">No deadline</strong><small>View personal reminders</small></button></section>
<div class="dashboard-overview-grid"><article class="panel dashboard-calendar-panel"><div class="dashboard-calendar-head"><div><h2><i class="fa-regular fa-calendar"></i> Calendar</h2><p>View personal reminders stored in this browser.</p></div><div class="dashboard-calendar-nav"><button id="dashboardPreviousMonth" type="button" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button><strong id="dashboardMonthLabel"></strong><button id="dashboardNextMonth" type="button" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button></div></div><div class="dashboard-calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="dashboard-calendar-grid" id="dashboardCalendarGrid"></div></article><article class="panel dashboard-recent-panel"><div class="panel-head"><h2>Recent submissions</h2><button data-go="documents">View all</button></div><div id="recentSubmissions"></div></article></div>
</section>
<section class="portal-page" data-section="progress">
<div class="progress-summary panel"><div><span>Current official stage</span><h2 id="currentStage">Stage 1 — Initial Submission</h2><p>Official status is managed by RPMS/IERB and cannot be changed from this portal.</p><button type="button" id="principalIndicator" class="principal-indicator" hidden><i class="fa-solid fa-award"></i> Principal Investigator <i class="fa-solid fa-chevron-down"></i></button><div id="protocolCodeReveal" class="protocol-reveal" hidden></div></div><div class="progress-ring" id="progressRing"><b>20%</b></div></div>
<div class="stage-list" id="stageList"></div>
<div class="portal-two-col requirement-panels"><article class="panel"><div class="panel-head"><h2>Completed requirements</h2></div><div id="completedRequirements"></div></article><article class="panel"><div class="panel-head"><h2>Pending requirements</h2></div><div id="pendingRequirements"></div></article></div>
<article class="panel"><div class="panel-head"><h2>Progress history &amp; remarks</h2></div><div id="progressHistory"></div></article>
</section>
<section class="portal-page" data-section="submit">
<div data-prism-hint="student-submit"></div>
<article class="panel form-panel"><h2>Submit a document</h2><p>Add the research details and upload a requirement for review. PDF, DOC, DOCX, PNG, or JPG up to 1.5 MB.</p><form id="submissionForm"><div class="form-grid"><label class="wide">Research title<input id="submissionResearchTitle" maxlength="250" required placeholder="Enter the complete research title"></label><label class="wide">Research group / members<input id="submissionResearchGroup" maxlength="250" required placeholder="Enter names or a group ID"></label><label>Document type<select id="documentType" required><option value="">Select a document type</option><option>Research Protocol</option><option>Informed Consent Form</option><option>Data Collection Instrument</option><option>Revision Letter</option><option>Ethics Training Certificate</option><option>Other Supporting Document</option></select></label><label>File<input id="documentFile" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" required></label><label class="wide">Notes for reviewer<textarea id="documentNotes" rows="4" maxlength="500" placeholder="Optional context about this submission"></textarea></label></div><button class="primary-btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Submit for review</button></form></article>
</section>
<section class="portal-page" data-section="documents">
<div id="protocolCodeCard" class="protocol-code-card" hidden><i class="fa-solid fa-shield-halved"></i><div><span>Approved Protocol Code</span><strong id="protocolCodeValue"></strong></div></div>
<div data-prism-hint="student-documents"></div>
<div data-prism-student-workflow></div>
<article class="panel"><div class="panel-head"><div><h2>My Documents</h2><p>Track, preview, download, and resubmit your files.</p></div><select id="documentFilter"><option value="">All statuses</option><option>Submitted</option><option>Under Review</option><option>Received</option><option>Verified</option><option>Resubmission Requested</option><option>Approved</option><option>Denied</option></select></div><div class="document-table-wrap"><table><thead><tr><th>Document</th><th>Type</th><th>Submitted</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead><tbody id="documentRows"></tbody></table></div></article>
</section>
<section class="portal-page" data-section="notifications">
<article class="panel"><div class="panel-head"><div><h2>Notifications</h2><p>Submission confirmations, updates, reminders, and follow-ups.</p></div><button id="markAllRead">Mark all as read</button></div><div id="notificationList"></div></article>
</section>
<section class="portal-page" data-section="calendar">
<div class="calendar-page portal-calendar">
<div class="calendar-page-actions"><div><h2>Research Calendar</h2><p>Manage personal reminders for your research work. These reminders are stored only in this browser.</p></div><button class="today-button" id="todayButton">Today</button></div>
<div class="calendar-layout">
<div class="full-calendar-card"><div class="calendar-toolbar"><button class="calendar-nav" id="previousMonth" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button><h2 id="monthLabel"></h2><button class="calendar-nav" id="nextMonth" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button></div><div class="weekday-row" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="month-grid" id="monthGrid"></div></div>
<aside class="reminder-panel"><div class="panel-title"><div><span class="eyebrow">Selected date</span><h2 id="selectedDateLabel"></h2></div><span class="task-count" id="taskCount">0 events</span></div><form id="reminderForm" autocomplete="off"><input type="hidden" id="editingId"><label for="taskTitle">Personal reminder</label><input id="taskTitle" maxlength="120" placeholder="What needs to be done?" required><label for="taskTime">Time <span>(optional)</span></label><input id="taskTime" type="time"><label for="taskNotes">Notes <span>(optional)</span></label><textarea id="taskNotes" rows="3" maxlength="500" placeholder="Add helpful details..."></textarea><div class="form-actions"><button type="button" class="cancel-edit" id="cancelEdit" hidden>Cancel</button><button type="submit" class="save-task"><i class="fa-solid fa-plus"></i><span id="saveLabel">Add reminder</span></button></div></form><div class="task-list" id="taskList"></div></aside>
</div>
</div>
</section>
<section class="portal-page" data-section="profile">
<div class="portal-two-col"><article class="panel form-panel"><h2>Personal information</h2><p>Your role and account email are shown for reference.</p><form id="profileForm"><div class="profile-photo-control"><img id="profileImagePreview" src="<?php echo htmlspecialchars($profileImg, ENT_QUOTES); ?>" alt="Profile preview"><label class="profile-photo-button"><span>Edit photo</span><input id="profileImageInput" type="file" accept="image/png,image/jpeg,image/webp" hidden></label><button id="removeProfileImage" type="button">Remove</button></div><div class="form-grid"><label>Full name<input id="profileName" required maxlength="120"></label><label>Email<input id="profileEmail" type="email" readonly></label><label>Role<input id="profileRole" readonly></label><label>Student / Employee ID<input id="profileId" maxlength="40"></label></div><button class="primary-btn" type="submit">Save permitted information</button></form></article><article class="panel form-panel"><h2>Change password</h2><p>Use at least 8 characters.</p><form id="passwordForm"><label>Current password<div class="password-field-wrap"><input id="currentPassword" type="password" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalCurrentPassword"></i></span></div></label><label>New password<div class="password-field-wrap"><input id="newPassword" type="password" minlength="8" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalNewPassword"></i></span></div></label><label>Confirm new password<div class="password-field-wrap"><input id="confirmPassword" type="password" minlength="8" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalConfirmPassword"></i></span></div></label><button class="primary-btn" type="submit">Change password</button></form></article></div>
</section>
</main>
</div>
<div class="support-modal" id="supportModal" hidden><div class="support-modal-card" role="dialog" aria-modal="true" aria-labelledby="supportModalTitle"><div class="support-modal-head"><div><span>Help &amp; Support</span><h2 id="supportModalTitle">Contact RPMS staff</h2></div><button id="closeSupportModal" type="button" aria-label="Close help form"><i class="fa-solid fa-xmark"></i></button></div><p>Report a problem or ask the RPMS team a question.</p><form id="supportForm"><label>How can we help?<select id="supportType" required><option value="Ask RPMS staff">Ask RPMS staff</option><option value="Report a problem">Report a problem</option></select></label><label>Subject<input id="supportSubject" maxlength="120" required placeholder="Briefly describe your concern"></label><label>Message<textarea id="supportMessage" rows="5" maxlength="1000" required placeholder="Add the details RPMS staff will need..."></textarea></label><div class="support-modal-actions"><button id="cancelSupport" type="button">Cancel</button><button class="primary-btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Send request</button></div></form></div></div>
<div class="toast" id="toast" role="status"></div>
<script src="assets/js/script.js"></script>
<script>
togglePassword('currentPassword', 'togglePortalCurrentPassword');
togglePassword('newPassword', 'togglePortalNewPassword');
togglePassword('confirmPassword', 'togglePortalConfirmPassword');
</script>
<script src="assets/js/prism-ui.js"></script>
<script src="assets/js/role-portal.js"></script>
<script src="assets/js/role-calendar.js"></script>
</body>
</html>
