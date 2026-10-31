<?php
require __DIR__ . '/config.php';
$authUser = require_login('admin');
// The existing AI report interface is retained. In V14 mode the endpoint
// summarizes canonical research projects rather than legacy Student records.
$user_email = $authUser['email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>AI Progress Reports | PRISM</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <link rel="icon" href="assets/images/prismicon.png">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/admin-management.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/reports.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/workspace-pages.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php prism_frontend_styles(); ?>
</head>
<body class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?> ai-report-page" data-admin-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES); ?>">
<div class="container">
    <?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'admin_ai.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?>
    <main class="main-content management-page">
        <header class="topbar">
            <div><h1><?= defined('PRISM_IERB_V14_OPERATIONS') && PRISM_IERB_V14_OPERATIONS === true ? 'AI V14 Project Progress Reports' : 'AI Progress Reports' ?></h1></div>
            <div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div>
        </header>

        <section class="report-tools ai-report-tools" aria-label="AI report types">
            <button class="report-tool" id="generateSummarizedReport">
                <span class="report-tool-icon"><i class="fa-solid fa-file-lines"></i></span>
                <span class="report-tool-copy"><span class="report-tool-kicker">Quick overview</span><strong>Generate Summarized Report</strong><small>A concise progress overview of all active students.</small></span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
            <button class="report-tool" id="generateFullReport">
                <span class="report-tool-icon"><i class="fa-solid fa-file-contract"></i></span>
                <span class="report-tool-copy"><span class="report-tool-kicker">Complete analysis</span><strong>Generate Full Report</strong><small>A detailed analysis with complete progress information for all active students.</small></span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </section>
        <p class="report-ai-note" id="reportAiNote" aria-live="polite"></p>

        <section class="management-card ai-history-card">
            <div class="management-card-head"><div><h2>Generated AI Reports</h2><p id="aiHistoryCount">0 reports</p></div></div>
            <div id="aiHistory" class="admin-history"></div>
        </section>
    <?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main>
</div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/admin-ai.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
