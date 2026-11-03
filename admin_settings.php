<?php
require __DIR__.'/config.php';
$authUser=require_login('admin');
$prismCurrentPage='admin_settings.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings | PRISM</title><link rel="icon" href="assets/images/prismicon.png"><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<?php foreach (['dashboard','admin-management','prism-ui','dashboard-sidebar','prism-workspace','ceu-footer','batch11','academic-settings'] as $style): ?><link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/'.$style.'.css'),ENT_QUOTES,'UTF-8') ?>"><?php endforeach; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="<?= htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'),ENT_QUOTES,'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/prism-workspace.js'),ENT_QUOTES,'UTF-8') ?>" defer></script>
<?php prism_frontend_styles(); ?>
</head>
<body class="prism-workspace admin-shell academic-settings-page"><a class="academic-settings-skip" href="#settingsMain">Skip to settings</a>
<div class="container"><aside class="sidebar prism-sidebar"><?php require __DIR__.'/includes/prism-navigation.php'; ?></aside>
<main class="main-content management-page" id="settingsMain" tabindex="-1">
    <header class="topbar"><div><h1>Settings</h1></div><div class="top-controls"><button id="themeToggle" class="theme-toggle" type="button" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
    <nav class="academic-settings-breadcrumb" aria-label="Breadcrumb"><a href="dashboard.php">Dashboard</a><span aria-hidden="true">/</span><span aria-current="page">Settings</span></nav>
    <?php require __DIR__.'/includes/academic_year_manager.php'; ?>
    <section class="settings-section management-card" aria-labelledby="legalComplianceTitle">
        <div class="settings-section-heading"><h2 id="legalComplianceTitle">Legal &amp; Compliance</h2></div>
        <article class="settings-module-card">
            <div class="settings-module-content">
                <h3>Legal Policies</h3>
            </div>
            <a class="prism-btn prism-btn-primary" href="admin_legal_policies.php">Open Legal Policies</a>
        </article>
    </section>
    <section class="settings-section management-card" aria-labelledby="communicationSettingsTitle">
        <div class="settings-section-heading"><h2 id="communicationSettingsTitle">Communication Settings</h2></div>
        <div class="communication-settings" data-communication-settings>
            <form class="communication-template-form" data-template-form>
                <label for="notificationTemplate">Notification template</label>
                <select id="notificationTemplate" name="templateKey">
                    <option value="document_submission">Document submission</option>
                    <option value="adviser_review">Adviser review</option>
                    <option value="document_status">Document status</option>
                    <option value="rpms_correction">RPMS correction &amp; resubmission</option>
                    <option value="deadline_reminder">Deadline reminder</option>
                </select>
                <label for="notificationTemplateBody">Email message template</label>
                <textarea id="notificationTemplateBody" name="template" maxlength="1000" rows="4" required></textarea>
                <p>Plain text for subsequent notification emails. Existing in-app notices stay unchanged. Keep <code>{message}</code> to preserve system details; <code>{recipient}</code> is also available.</p>
                <button class="prism-btn prism-btn-primary" type="submit">Save template</button>
            </form>
            <form class="communication-reminder-form" data-reminder-form>
                <label for="deadlineReminderIntervals">Deadline reminder intervals (days before deadline)</label>
                <input id="deadlineReminderIntervals" name="intervals" inputmode="numeric" placeholder="7, 3, 1" maxlength="30" required>
                <p>Enter up to five whole-number intervals from 1 to 30, separated by commas.</p>
                <button class="prism-btn prism-btn-primary" type="submit">Save reminder intervals</button>
            </form>
            <p class="communication-settings-status" data-communication-status role="status" aria-live="polite">Loading communication settings…</p>
        </div>
    </section>
    <?php require __DIR__.'/includes/ceu_footer.php'; ?>
</main></div>
<script src="<?= htmlspecialchars(asset_url('assets/js/prism-ui.js'),ENT_QUOTES,'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/academic-years.js'),ENT_QUOTES,'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/communication-settings.js'),ENT_QUOTES,'UTF-8') ?>"></script>
</body></html>
