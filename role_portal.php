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
<?php require __DIR__.'/includes/document_catalog_client.php'; ?>
<?php require __DIR__.'/includes/session_browser.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($portalRole); ?> Portal | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/role-portal.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/calendar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/role-topnav.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/student-dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/student-calendar-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/research-resources.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-guidance.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php prism_frontend_styles(); ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/student-ierb-progress.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body data-prism-allow-demo="<?= defined('APP_ENV') && APP_ENV==='development' && is_loopback_development_request() ? '1' : '0' ?>" class="prism-workspace portal-shell student-page student-dashboard-page" data-portal-key="<?php echo htmlspecialchars($identityKey, ENT_QUOTES); ?>" data-role="<?php echo htmlspecialchars($portalRole, ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars($userName, ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($userEmail, ENT_QUOTES); ?>">
<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<nav class="portal-navbar" aria-label="Portal navigation">
<button class="portal-brand" type="button" data-go="dashboard" aria-label="PRISM dashboard: Progress and Research Information System for Monitoring" title="Progress and Research Information System for Monitoring"><img src="assets/images/prismlogo1.png?v=2" alt="PRISM Logo"></button>
<button id="portalNavigationToggle" class="portal-navigation-toggle" type="button" aria-expanded="false" aria-controls="portalNav">Menu</button>
<ul class="portal-nav-links" id="portalNav">
<li class="active"><button data-page="dashboard" aria-current="page">Dashboard</button></li>
<li><button data-page="progress">IERB Progress</button></li>
<li><button data-page="documents">My Documents</button></li>
<li><button data-page="calendar">Calendar</button></li>
<li class="portal-nav-dropdown-item" data-prism-dropdown>
<button type="button" class="portal-dropdown-toggle" aria-expanded="false" aria-haspopup="true"><span>Research Resources</span><i class="fa-solid fa-chevron-down prism-dropdown-arrow" aria-hidden="true"></i></button>
<div class="portal-dropdown-menu portal-resources-dropdown" hidden>
<a class="portal-dropdown-item" href="https://ceu-ierb.wixsite.com/ierb" target="_blank" rel="noopener noreferrer">IERB Portal</a>
<a class="portal-dropdown-item" href="#prismResourcesTitle" data-prism-resources-link data-prism-resource-target="prism-sdg-panel">Sustainable Development Goals</a>
<a class="portal-dropdown-item" href="#prismResourcesTitle" data-prism-resources-link data-prism-resource-target="prism-agenda-panel">Research Agenda</a>
<div class="portal-dropdown-divider" role="separator"></div>
<a class="portal-dropdown-item portal-dropdown-scroll" href="#prismResourcesTitle" data-prism-resources-link>View all resources</a>
</div>
</li>
</ul>
<div class="portal-nav-right">
<button class="portal-help" type="button" data-prism-tour title="Quick tour" aria-label="Quick tour"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></button><button class="portal-notification" type="button" data-go="notifications" title="Notifications" aria-label="Notifications"><i class="fa-solid fa-bell"></i><b class="nav-badge" id="navBadge">0</b></button>
<div class="portal-profile-menu">
<button class="portal-profile-btn" id="portalProfileToggle" type="button" aria-expanded="false" aria-controls="portalProfileLinks" aria-label="Account menu"><img id="navProfileImage" src="<?php echo htmlspecialchars($profileImg, ENT_QUOTES); ?>" alt="Profile picture"><span><strong id="sideName"><?php echo htmlspecialchars($userName); ?></strong><small><?php echo htmlspecialchars($portalRole); ?></small></span><i class="fa-solid fa-chevron-down"></i></button>
<div class="portal-profile-dropdown" id="portalProfileLinks" hidden>
<button type="button" data-go="profile"><i class="fa-regular fa-user" aria-hidden="true"></i> My account</button>
<button type="button" data-go="activity"><i class="fa-solid fa-list" aria-hidden="true"></i> Activity logs</button>
<a href="help_support.php" data-open-support><i class="fa-regular fa-circle-question" aria-hidden="true"></i> Help &amp; Support</a>
<div class="prism-account-divider" role="separator"></div>
<form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"  class="prism-account-logout" ><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</button></form>
</div>
</div>
</div>
</nav>
<noscript><form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Log out</button></form></noscript>
<?php require __DIR__ . '/includes/help_support_modal.php'; ?>
<template data-prism-guide-role="student" data-prism-guide-user="<?php echo htmlspecialchars($identityKey, ENT_QUOTES, 'UTF-8'); ?>" data-prism-guide-auto="<?php echo ($_GET['welcome'] ?? '') === '1' ? '1' : '0'; ?>"></template>
<div class="container">
<main class="main-content portal-main">
<header class="topbar"><div><h1 id="pageTitle">Dashboard</h1><p id="pageSubtitle">Your research and IERB progress at a glance.</p></div><div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
<p id="studentDashboardState" class="student-dashboard-state" role="status" aria-live="polite" aria-busy="true">Loading your workspace...</p>
<section class="portal-page active" data-section="dashboard">
<div class="welcome-card"><div><span><?php echo htmlspecialchars($portalRole); ?> workspace</span><h2>Welcome, <b id="welcomeName"><?php echo htmlspecialchars($userName); ?></b>!</h2><p class="dashboard-greeting-date"><i class="fa-regular fa-calendar"></i> <b id="dashboardCurrentDate"></b></p><p id="researchTitle">No research details yet.</p><p id="dashboardProtocol" class="dashboard-protocol" hidden><b>Protocol Code:</b> <span id="dashboardProtocolCode"></span></p></div><button class="primary-btn" data-go="submit"><i class="fa-solid fa-upload"></i> Submit document</button></div>
<div data-prism-student-workflow="compact"></div>
<section class="dashboard-priority" aria-label="My IERB progress">
<article class="student-ierb-progress-card">
  <div class="student-ierb-progress-head">
    <div><span class="student-ierb-eyebrow">Your IERB progress</span><h2 id="studentProgressHeading">Loading progress…</h2><span class="student-ierb-status" id="studentProgressStatus"><i aria-hidden="true"></i><span>Loading</span></span></div>
    <div class="student-ierb-percent" id="studentProgressPercent" aria-live="polite">0%</div>
  </div>
  <div class="student-ierb-track" id="studentProgressTrack" role="progressbar" aria-label="IERB progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="student-ierb-fill" id="studentProgressFill"></div></div>
  <ol class="student-ierb-steps" id="studentProgressSteps" aria-label="IERB stages"></ol>
  <div class="student-ierb-detail" id="studentProgressDetail" aria-live="polite"></div>
</article>
<button class="dashboard-pending-card is-clear" id="dashboardPendingCard" type="button" data-go="progress"><i class="dashboard-pending-icon fa-regular fa-circle-check" id="dashboardPendingIcon" aria-hidden="true"></i><span><strong id="dashboardPendingTitle">You have no pending requirements</strong><small id="dashboardPendingDescription">You're all caught up. Tap to review past requirements.</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
</section>
<section class="dashboard-status-cards student-summary-grid" aria-label="Your research overview">
<article class="student-summary-card"><div class="student-summary-heading"><span class="student-summary-icon"><i class="fa-regular fa-folder" aria-hidden="true"></i></span><h2>Current submission</h2></div><strong id="dashboardSubmissionValue">Loading...</strong><p id="studentCurrentDocument">Loading your latest submission...</p><small class="student-summary-tag" id="dashboardSubmissionStatus" hidden></small><button type="button" class="student-summary-cta" data-go="documents"><span id="dashboardSubmissionAction">Open my documents</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></article>
<article class="student-summary-card"><div class="student-summary-heading"><span class="student-summary-icon"><i class="fa-regular fa-comment" aria-hidden="true"></i></span><h2>Adviser review</h2></div><strong id="studentReviewState">Loading...</strong><p id="studentReviewRemarks">Checking reviewer remarks...</p><small class="student-summary-tag" id="studentReviewTag" hidden></small><button type="button" class="student-summary-cta" data-go="documents"><span id="studentReviewAction">View review</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></article>
<article class="student-summary-card"><div class="student-summary-heading"><span class="student-summary-icon"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><h2>Upcoming reminder</h2></div><strong id="dashboardDeadlineValue">No deadline</strong><p id="dashboardDeadlineHint">You're clear for now. New deadlines will show up here.</p><small class="student-summary-tag" id="dashboardDeadlineTag" hidden></small><button type="button" class="student-summary-cta" data-go="calendar"><span>Open calendar</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></article>
<article class="student-summary-card"><div class="student-summary-heading"><span class="student-summary-icon"><i class="fa-regular fa-bell" aria-hidden="true"></i></span><h2>Notifications</h2></div><strong id="studentNotificationValue">Loading...</strong><p id="studentNotificationSummary">Checking notifications...</p><small class="student-summary-tag student-summary-tag-new" id="studentNotificationTag" hidden></small><button type="button" class="student-summary-cta" data-go="notifications"><span>View all</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></article>
</section>
<div class="dashboard-overview-grid">
<article class="panel dashboard-calendar-panel">
<div class="dashboard-calendar-head"><div><h2><i class="fa-regular fa-calendar"></i> Calendar</h2><p>View official deadlines and personal reminders.</p></div><div class="dashboard-calendar-nav"><button id="dashboardPreviousMonth" type="button" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button><strong id="dashboardMonthLabel"></strong><button id="dashboardNextMonth" type="button" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button></div></div>
<div class="dashboard-calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
<div class="dashboard-calendar-grid" id="dashboardCalendarGrid"></div>
<div class="calendar-legend" aria-label="Calendar legend"><span><i class="official-dot" aria-hidden="true"></i> Official deadline</span><span><i class="today-dot" aria-hidden="true">16</i> Today</span><span><i class="selected-dot" aria-hidden="true">16</i> Selected date</span></div>
<p id="dashboardDeadlineState" class="prism-deadline-load" role="status"></p>
<div class="dashboard-calendar-tasks-head"><h3><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Tasks &amp; Deadlines</h3><button type="button" data-go="calendar" aria-label="Add a reminder" title="Add a reminder"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
<div id="dashboardCalendarTasks" class="dashboard-calendar-tasks" aria-live="polite"></div>
</article>
<article class="panel dashboard-recent-panel"><div class="panel-head"><h2>Recent submissions</h2><button data-go="documents">View all</button></div><div id="recentSubmissions"></div></article>
</div>
<?php require __DIR__ . '/includes/research_resources.php'; ?>
</section>
<section class="portal-page" data-section="progress">
<div class="progress-summary panel"><div><span>Current official stage</span><h2 id="currentStage">Stage 1 — Initial Submission</h2><p id="progressStageNumber" class="progress-stage-number">Stage 1 of 6</p><p id="progressActionStatus" class="progress-action-status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Nothing is needed from you right now.</span></p><p class="progress-official-note">Only RPMS Administrators change your official IERB stage. Document approval and submission do not grant institutional ethics approval.</p><dl class="progress-meta"><div><dt>Research title</dt><dd id="progressResearchTitle">Not recorded</dd></div><div><dt>Protocol code</dt><dd id="progressProtocolCode">Not recorded</dd></div></dl></div><div class="progress-ring" id="progressRing"><b>20%</b><span class="progress-ring-label">complete</span></div></div>
<article class="panel progress-journey"><div class="progress-journey-head"><h2>Your journey</h2><p>Select a stage to see what happens in it.</p></div><div class="stage-list" id="stageList"></div><div class="progress-stage-detail" id="progressStageDetail" aria-live="polite"><span>Stage 1</span><strong>Loading stage details</strong><span>RPMS maintains the official stage and its informational guidance.</span></div></article>
<div class="portal-two-col requirement-panels"><article class="panel"><div class="panel-head"><h2>Earlier stages</h2></div><div id="completedRequirements"></div></article><article class="panel"><div class="panel-head"><h2>Pending requirements</h2></div><div id="pendingRequirements"></div></article></div>
<article class="panel progress-history-panel"><div class="panel-head"><h2>Progress history &amp; remarks</h2></div><div id="progressHistory"></div></article>
</section>
<section class="portal-page" data-section="submit">
<div class="submission-page">
  <div data-prism-hint="student-submit"></div>
  <button class="submission-back-button" type="button" data-go="documents"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>My Documents</span></button>
  <div class="submission-layout">
    <div class="submission-main">
      <form id="submissionForm" class="panel submission-card">
        <div class="submission-form-heading"><h2>Submit a document</h2><p>Three quick steps. Your research details are filled in for you.</p></div>
        <section class="submission-step is-active" id="submissionStepType">
          <span class="submission-step-number" aria-hidden="true">1</span><div class="submission-step-body"><h3><label for="documentType">Choose the document type</label></h3><p>Pick the requirement you are uploading.</p><select id="documentType" required><option value="">Select a document type</option></select><div class="submission-version-note" id="submissionVersionNote" hidden></div></div>
        </section>
        <section class="submission-step" id="submissionStepFile">
          <span class="submission-step-number" aria-hidden="true">2</span><div class="submission-step-body"><h3>Upload your file</h3><p>PDF or DOCX only, up to 20 MB per file.</p><button class="submission-dropzone" id="submissionDropzone" type="button" aria-describedby="uploadSelection uploadResults"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i><strong>Drag &amp; drop your file here</strong><span>or <u>browse from your computer</u></span></button><input class="submission-file-input" aria-label="Choose files" aria-describedby="uploadSelection uploadResults" id="documentFile" type="file" multiple accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required><div id="uploadSelection" class="submission-file-selection" role="status"></div><div id="uploadResults" class="submission-upload-results" role="status" aria-live="polite" tabindex="-1"></div></div>
        </section>
        <section class="submission-step" id="submissionStepNotes">
          <span class="submission-step-number" aria-hidden="true">3</span><div class="submission-step-body"><h3><label for="documentNotes">Notes for the reviewer <span>(optional)</span></label></h3><p>Anything the reviewer should know about this submission.</p><textarea id="documentNotes" maxlength="500" rows="4" placeholder="e.g. Updated the methodology section based on feedback."></textarea><div class="submission-character-count"><span id="submissionNotesCount">0 / 500</span></div></div>
        </section>
        <button class="submission-submit" type="submit" disabled><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit for review</button><p class="submission-submit-hint" id="submissionSubmitHint">Choose a document type and upload a file to continue.</p>
      </form>
    </div>
    <aside class="submission-side">
      <section class="panel submission-research-card" aria-labelledby="submissionResearchHeading"><h2 id="submissionResearchHeading">Your research</h2><dl>
        <div><dt>Research title</dt><dd id="submissionResearchTitle">Not recorded</dd></div>
        <div><dt>Research group</dt><dd id="submissionResearchGroup">Not recorded</dd></div>
        <div><dt>Current stage</dt><dd><span class="submission-stage-pill" id="submissionStage">Not recorded</span></dd></div>
        <div><dt>Assigned adviser</dt><dd id="submissionAdviser">Unassigned</dd></div>
        <div><dt>Student ID</dt><dd id="submissionStudentId">Not recorded</dd></div>
        <div><dt>Protocol code</dt><dd id="submissionProtocol">No protocol code yet.</dd></div>
      </dl><p class="submission-record-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Taken from your student record. Contact your adviser if something is wrong.</p></section>
      <section class="panel submission-ready-card" aria-labelledby="submissionReadyHeading"><h2 id="submissionReadyHeading">Ready to submit?</h2><ul><li id="submissionTypeReady"><i aria-hidden="true"></i><span>Document type chosen</span></li><li id="submissionFileReady"><i aria-hidden="true"></i><span>File uploaded</span></li></ul></section>
    </aside>
  </div>
</div>
</section>
<section class="portal-page" data-section="documents">
<div id="protocolCodeCard" class="protocol-code-card" hidden><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><div><span>Approved Protocol Code</span><strong id="protocolCodeValue"></strong></div><button type="button" id="copyProtocolCode"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy</button></div>
<div data-prism-hint="student-documents"></div>
<div data-prism-student-workflow data-prism-hide-submit></div>
<header class="student-documents-heading"><div><h1>My Documents</h1><p>Track, preview, download and resubmit your files.</p></div><button type="button" class="primary-btn" data-go="submit"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload document</button></header>
<section class="student-documents-how" id="studentDocumentsHow"><div class="student-documents-how-head"><strong>How your documents move</strong><button type="button" id="studentDocumentsHowToggle" aria-expanded="true">Hide</button></div><div class="student-documents-flow"><article><b>1</b><strong>You upload</strong><p>It shows as Pending Adviser Review.</p></article><article><b>2</b><strong>Adviser decides</strong><p>Your adviser approves it or asks for changes.</p></article><article><b>3</b><strong>Sent to RPMS</strong><p>Approval automatically submits the current version to RPMS.</p></article><article><b>4</b><strong>If RPMS returns it</strong><p>Upload a revision. Earlier versions are kept.</p></article></div></section>
<article class="panel student-documents-panel"><div class="student-document-tools"><label class="student-document-search" for="studentDocumentSearch"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="studentDocumentSearch" type="search" placeholder="Search by file name, type or stage"></label><label class="prism-sr-only" for="studentDocumentSort">Sort documents</label><select id="studentDocumentSort" aria-label="Sort documents"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name-asc">Name A–Z</option></select><div class="student-document-status-select"><button type="button" id="studentDocumentStatusToggle" aria-haspopup="listbox" aria-expanded="false"><span id="studentDocumentStatusLabel">All statuses</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button><div id="studentDocumentStatusMenu" class="student-document-status-menu" role="listbox" aria-label="Filter documents by status" hidden><button type="button" role="option" data-document-status-option="" aria-selected="true">All statuses</button><strong>Workflow</strong><button type="button" role="option" data-document-status-option="Pending Adviser Review" aria-selected="false">Pending Adviser Review</button><button type="button" role="option" data-document-status-option="Needs Revision" aria-selected="false">Needs Revision</button><button type="button" role="option" data-document-status-option="Returned for Revision" aria-selected="false">Returned for Revision</button><button type="button" role="option" data-document-status-option="Ready for Formal RPMS Submission" aria-selected="false">Ready for Formal RPMS Submission</button><button type="button" role="option" data-document-status-option="Submitted to RPMS" aria-selected="false">Submitted to RPMS</button><strong>Review status</strong><button type="button" role="option" data-document-status-option="Submitted" aria-selected="false">Submitted</button><button type="button" role="option" data-document-status-option="Under Review" aria-selected="false">Under Review</button><button type="button" role="option" data-document-status-option="Received" aria-selected="false">Received</button><button type="button" role="option" data-document-status-option="Verified" aria-selected="false">Verified</button><button type="button" role="option" data-document-status-option="Resubmission Requested" aria-selected="false">Resubmission Requested</button><button type="button" role="option" data-document-status-option="Approved" aria-selected="false">Approved</button><button type="button" role="option" data-document-status-option="Denied" aria-selected="false">Denied</button></div></div><label class="prism-sr-only" for="documentFilter">Document status</label><select id="documentFilter" aria-hidden="true" tabindex="-1" class="prism-sr-only"><option value="">All statuses</option><optgroup label="Workflow"><option>Pending Adviser Review</option><option>Needs Revision</option><option>Returned for Revision</option><option>Ready for Formal RPMS Submission</option><option>Submitted to RPMS</option></optgroup><optgroup label="Review status"><option>Submitted</option><option>Under Review</option><option>Received</option><option>Verified</option><option>Resubmission Requested</option><option>Approved</option><option>Denied</option></optgroup></select></div><div id="studentDocumentPills" class="prism-filter-pills" aria-label="Document status filters"></div><p id="studentDocumentCount" class="student-document-count" aria-live="polite"></p><div id="studentDocumentList" class="student-document-list" aria-live="polite"></div></article>
</section>
<section class="portal-page" data-section="notifications">
<article class="panel student-notification-history"><div class="panel-head"><div><h2>Notification History</h2><p id="studentNotificationCount" aria-live="polite">Showing 0–0 of 0 notifications</p></div><button id="markAllRead" type="button" hidden>Mark all as read</button></div><div id="notificationList"></div><nav id="studentNotificationPagination" class="prism-pagination" aria-label="Notification pages"></nav></article>
</section>
<section class="portal-page" data-section="calendar">
<div class="calendar-page portal-calendar">
<div class="calendar-layout">
<section class="full-calendar-card">
<div class="calendar-toolbar"><button class="calendar-nav" id="previousMonth" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button><h2 id="monthLabel"></h2><button class="calendar-nav" id="nextMonth" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button></div>
<div class="weekday-row" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="month-grid" id="monthGrid"></div>
</section>
<div class="calendar-side">
<aside class="reminder-panel">
<div class="panel-title"><div><span class="eyebrow">Selected date</span><h2 id="selectedDateLabel"></h2></div><span class="task-count" id="taskCount">0 tasks</span></div>
<form id="reminderForm" autocomplete="off"><input type="hidden" id="editingId"><label for="taskTitle">Task reminder</label><input id="taskTitle" type="text" maxlength="120" placeholder="What needs to be done?" required><label for="taskTime">Time <span>(optional)</span></label><input id="taskTime" type="time"><label for="taskNotes">Notes (optional)</label><textarea id="taskNotes" rows="3" maxlength="500" placeholder="Add helpful details..."></textarea><div class="form-actions"><button type="button" class="cancel-edit" id="cancelEdit" hidden>Cancel</button><button type="submit" class="save-task"><i class="fa-solid fa-plus" aria-hidden="true"></i><span id="saveLabel">Add task</span></button></div></form>
<div class="task-list" id="taskList"></div>
</aside>
<section id="officialDeadlinePanel" class="reminder-panel official-deadline-panel" data-role="student"></section>
</div>
</div>
</div>
</section>
<section class="portal-page" data-section="profile">
<div class="portal-two-col"><article class="panel form-panel"><h2>Personal information</h2><p>Your role and account email are shown for reference.</p><form id="profileForm"><div class="form-grid"><label>Full name<input id="profileName" value="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>" required maxlength="120"></label><label>Email<input id="profileEmail" type="email" value="<?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>" readonly></label><label>Role<input id="profileRole" value="<?php echo htmlspecialchars($portalRole, ENT_QUOTES, 'UTF-8'); ?>" readonly></label><label>Student / Employee ID<input id="profileId" value="<?php echo htmlspecialchars((string)$userId, ENT_QUOTES, 'UTF-8'); ?>" maxlength="40" readonly></label></div><button class="primary-btn" type="submit">Save permitted information</button></form></article><article class="panel form-panel"><h2>Change password</h2><p>Use at least 12 characters.</p><form id="passwordForm"><label>Current password<div class="password-field-wrap"><input id="currentPassword" type="password" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalCurrentPassword"></i></span></div></label><label>New password<div class="password-field-wrap"><input id="newPassword" type="password" minlength="12" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalNewPassword"></i></span></div></label><label>Confirm new password<div class="password-field-wrap"><input id="confirmPassword" type="password" minlength="12" required><span class="toggle-password"><i class="fa-solid fa-eye" id="togglePortalConfirmPassword"></i></span></div></label><button class="primary-btn" type="submit">Change password</button></form></article></div>
</section>
<section class="portal-page" data-section="activity">
<article class="panel student-activity-panel"><div class="panel-head"><div><h2>Activity Logs</h2><p>Recent actions recorded for your student account.</p></div><p id="studentActivityCount" aria-live="polite">0 entries</p></div><div id="studentActivityList" class="student-activity-list" aria-live="polite"></div><nav id="studentActivityPagination" class="prism-pagination" aria-label="Activity log pages"></nav></article>
</section>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>
<div class="toast" id="toast" role="status"></div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
togglePassword('currentPassword', 'togglePortalCurrentPassword');
togglePassword('newPassword', 'togglePortalNewPassword');
togglePassword('confirmPassword', 'togglePortalConfirmPassword');
</script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/document-upload.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/role-portal.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/calendar-deadlines.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/role-calendar.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
