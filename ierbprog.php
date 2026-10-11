<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin','adviser']);
require_once __DIR__ . '/includes/academic_catalog.php';
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ierbprog.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/academic-fields.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?>" data-ierb-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($_SESSION['account_type'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?>">
<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<div class="container">
    <?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'ierbprog.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>

    <main class="main-content ierb-page">
        <header class="topbar">
            <div class="ierb-heading"><h1>IERB Progress</h1></div>
            <div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div>
        </header>

        <section class="progress-overview" aria-labelledby="progressOverviewTitle">
            <div class="overview-heading"><div><span>Stage distribution</span><h2 id="progressOverviewTitle">Progress Overview</h2></div><strong id="overviewTotal">0 students</strong></div>
            <div class="stage-chart" id="stageChart" role="img" aria-label="Bar chart of students by IERB stage"></div>
        </section>

        <section class="ierb-controls" aria-label="IERB progress filters">
            <label class="ierb-control-field ierb-search-field" for="ierbSearch"><span class="prism-sr-only">Search records</span><span class="ierb-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="ierbSearch" type="search" placeholder="Search records"></span></label>
            <div class="ierb-filters">
                <label class="ierb-control-field" for="stageFilter"><span>Stage</span><select id="stageFilter"><option value="">All Stages</option><option value="Stage 1">Stage 1</option><option value="Stage 2">Stage 2</option><option value="Stage 3">Stage 3</option><option value="Stage 4">Stage 4</option><option value="Stage 5">Stage 5</option><option value="Completed">Completed</option></select></label>
                <label class="ierb-control-field" for="ierbStatusFilter"><span>Status</span><select id="ierbStatusFilter"><option value="">All Statuses</option><option>On Track</option><option>Pending</option><option>Delayed</option></select></label>
            </div>
            <?php if ($authUser['role'] === 'admin'): ?><button type="button" class="ierb-add-link" id="addIerbEntry"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add IERB Entry</button><?php endif; ?>
        </section>

        <div id="ierbMoreFilters" class="prism-record-filters" aria-label="Academic and research filters"></div>
        <section class="ierb-directory" aria-labelledby="ierbTableTitle">
            <div class="ierb-table-heading"><div><h2 id="ierbTableTitle">Detailed Progress</h2><p id="ierbRecordCount">0 records</p></div></div>
            <div class="ierb-table-wrap"><table class="ierb-table">
                <thead><tr><th>Student Name</th><th>Current Stage</th><th>Completed Stages</th><th>Pending Requirements</th><th>Submission Dates</th><th>Delay Status</th><th>Actions</th></tr></thead>
                <tbody id="ierbTableBody"></tbody>
            </table></div>
        </section>
    <?php require __DIR__ . '/includes/ceu_footer.php'; ?>
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
                <fieldset class="academic-fields" id="entryAcademicFields">
                    <legend>Academic information</legend>
                    <div class="academic-fields-grid">
                        <label for="entryAcademicUnit">Academic Unit<select id="entryAcademicUnit" data-academic="unit"></select></label>
                        <label for="entryCourse">Program<select id="entryCourse" data-academic="program" aria-describedby="entryAcademicSummary"></select></label>
                        <label for="entryYearLevel">Year Level<select id="entryYearLevel" data-academic="year"></select></label>
                        <label for="entryAcademicYear">Academic Year<select id="entryAcademicYear" data-academic="academic-year"></select></label>
                    </div>
                    <p id="entryAcademicSummary" data-academic="summary" aria-live="polite"></p>
                    <p data-academic="legacy" hidden></p>
                    <button type="button" data-academic="reset" hidden>Keep existing academic values</button>
                </fieldset>
                <div><label for="entryGroupId">Research group</label><select id="entryGroupId" data-research-group required><option value="">Select academic information first</option></select></div>
                <div><label for="entryStage">Current IERB stage</label><select id="entryStage"><option value="Stage 1">Stage 1</option><option value="Stage 2">Stage 2</option><option value="Stage 3">Stage 3</option><option value="Stage 4">Stage 4</option><option value="Stage 5">Stage 5</option><option value="Completed">Completed</option></select></div>
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
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>window.PRISM_ACADEMIC_CATALOG = <?php echo json_encode(academic_catalog(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/academic-fields.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/ierbprog.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
