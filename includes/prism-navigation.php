<?php
// Presentation partial: the caller must already have authenticated the user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser'], true)) {
    http_response_code(404);
    exit;
}
$prismNavEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
require __DIR__.'/session_browser.php';
?>
<script src="<?= $prismNavEscape(asset_url('assets/js/prism-guidance.js')) ?>" defer></script>
<?php
if ($authUser['role'] === 'adviser'):
    $prismPortalLinks = [
        ['research_adviser.php', 'Dashboard'], ['ierbprog.php', 'IERB Progress'],
        ['calendar.php', 'Calendar'],
    ];
?>
<a class="portal-brand" href="research_adviser.php" aria-label="PRISM dashboard: Progress and Research Information System for Monitoring" title="Progress and Research Information System for Monitoring"><img src="assets/images/prismlogo1.png?v=2" alt="PRISM"></a>
<button class="portal-navigation-toggle" id="adviserNavigationToggle" type="button" aria-expanded="false" aria-controls="prismPrimaryNavigation">Menu</button>
<nav id="prismPrimaryNavigation" class="portal-nav-links" aria-label="Main navigation">
<?php foreach ($prismPortalLinks as [$prismNavUrl, $prismNavLabel]): ?>
<a class="prism-nav-home" href="<?= $prismNavEscape($prismNavUrl) ?>" <?= ($prismCurrentPage ?? '') === $prismNavUrl ? 'aria-current="page"' : '' ?>><?= $prismNavEscape($prismNavLabel) ?></a>
<?php endforeach; ?>
<div class="portal-nav-dropdown" data-prism-dropdown>
<button type="button" class="prism-nav-home portal-dropdown-toggle" aria-expanded="false" aria-haspopup="true" <?= in_array($prismCurrentPage ?? '', ['admin_students.php', 'documents.php'], true) ? 'aria-current="page"' : '' ?>><span>Research Management</span><i class="fa-solid fa-chevron-down prism-dropdown-arrow" aria-hidden="true"></i></button>
<div class="portal-dropdown-menu portal-resources-dropdown" hidden>
<a class="portal-dropdown-item" href="admin_students.php" <?= ($prismCurrentPage ?? '') === 'admin_students.php' ? 'aria-current="page"' : '' ?>>Assigned Students</a>
<a class="portal-dropdown-item" href="documents.php" <?= ($prismCurrentPage ?? '') === 'documents.php' ? 'aria-current="page"' : '' ?>>Documents to Review</a>
</div>
</div>
<div class="portal-nav-dropdown" data-prism-dropdown>
<button type="button" class="prism-nav-home portal-dropdown-toggle" aria-expanded="false" aria-haspopup="true"><span>Research Resources</span><i class="fa-solid fa-chevron-down prism-dropdown-arrow" aria-hidden="true"></i></button>
<div class="portal-dropdown-menu portal-resources-dropdown" hidden>
<a class="portal-dropdown-item" href="https://ceu-ierb.wixsite.com/ierb" target="_blank" rel="noopener noreferrer">IERB Portal</a>
<a class="portal-dropdown-item" href="#prismResourcesTitle" data-prism-resources-link data-prism-resource-target="prism-sdg-panel">Sustainable Development Goals</a>
<a class="portal-dropdown-item" href="#prismResourcesTitle" data-prism-resources-link data-prism-resource-target="prism-agenda-panel">Research Agenda</a>
<div class="portal-dropdown-divider" role="separator"></div>
<a class="portal-dropdown-item portal-dropdown-scroll" href="#prismResourcesTitle" data-prism-resources-link>View all resources</a>
</div>
</div>
</nav>
<div class="portal-nav-right">
<button class="portal-help" type="button" data-prism-tour aria-label="Quick tour" title="Quick tour"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></button><a class="portal-notification" href="admin_notifications.php" aria-label="Notifications" title="Notifications" <?= ($prismCurrentPage ?? '') === 'admin_notifications.php' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-bell" aria-hidden="true"></i></a>
<div class="prism-account-menu">
<button class="portal-profile-btn" data-prism-account-toggle type="button" aria-expanded="false" aria-controls="prismAccountLinks" aria-label="Adviser account menu"><img src="assets/images/default-avatar.svg" alt=""><span><strong><?= $prismNavEscape($authUser['full_name'] ?? '') ?></strong><small>Research Adviser</small></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
<div class="prism-account-links" id="prismAccountLinks" hidden><a href="account.php" <?= ($prismCurrentPage ?? '') === 'account.php' ? 'aria-current="page"' : '' ?>><i class="fa-regular fa-user" aria-hidden="true"></i>My account</a><a href="account.php#activity"><i class="fa-solid fa-list" aria-hidden="true"></i>Activity logs</a><a href="help_support.php" data-open-support><i class="fa-regular fa-circle-question" aria-hidden="true"></i>Help &amp; Support</a><div class="prism-account-divider" role="separator"></div><form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"  class="prism-account-logout" ><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</button></form></div>
</div>
</div>
<?php require __DIR__.'/help_support_modal.php'; ?>
<?php else:
$prismNavGroups = [
    'Research' => ['Research Management', 'fa-folder-open', [
        ['admin_students.php', 'Student Records'],
        ...($authUser['role'] === 'admin' ? [['admin_advisers.php', 'Research Advisers'], ['admin_archived_accounts.php', 'Archived Accounts']] : []),
        ['ierbprog.php', 'IERB Progress'], ['documents.php', 'Document Submissions'],
    ]],
    'Reporting' => ['Reports & Communication', 'fa-chart-line', [
        ...($authUser['role'] === 'admin' ? [['admin_ai.php', 'AI Progress Reports'], ['reports.php', 'Generated Reports'], ['data_export.php', 'Data Export']] : []),
        ['admin_notifications.php', 'Notifications'],
    ]],
    'Account' => ['Account & Planning', 'fa-user-gear', [
        ['admin_legal_policies.php', 'Legal Policies'],
        ['account.php', 'Account & Activity'], ['account.php#activity', 'Activity Logs'],
        ['calendar.php', 'Personal Calendar'],
    ]],
];
?>
<script>
// Apply the canonical sidebar class during parsing, before its branding can paint.
(() => {
    const sidebar = document.currentScript.closest('.prism-sidebar');
    if (!sidebar) return;
    let collapsed = matchMedia('(max-width:900px)').matches;
    if (!collapsed) { try { collapsed = sessionStorage.getItem('prismNavigationMinimized') === 'true'; } catch (_) {} }
    sidebar.classList.add('is-initializing');
    sidebar.classList.toggle('is-collapsed', collapsed);
})();
</script>
<div class="sidebar-header prism-sidebar-brand" title="Progress and Research Information System for Monitoring" aria-label="PRISM: Progress and Research Information System for Monitoring"><img src="assets/images/prismlogo1.png?v=3" class="sidebar-brand-logo" alt="PRISM"><img src="assets/images/prismicon.png" class="sidebar-brand-icon" alt="PRISM: Progress and Research Information System for Monitoring"><span class="prism-navigation-caption">Progress and Research Information System for Monitoring</span></div>
<button type="button" id="prismSidebarToggle" class="prism-sidebar-toggle" aria-expanded="true" aria-controls="prismPrimaryNavigation" aria-label="Collapse navigation"><i class="fa-solid fa-bars" aria-hidden="true"></i><span>Navigation</span></button>
<nav id="prismPrimaryNavigation" aria-label="Main navigation">
<?php if ($authUser['role'] === 'admin'): ?>
<a class="prism-nav-home" href="dashboard.php" aria-label="Dashboard" <?php if (($prismCurrentPage ?? '') === 'dashboard.php') echo 'aria-current="page"'; ?>><i class="fa-solid fa-house" aria-hidden="true"></i><span>Dashboard</span></a>
<?php else: ?>
<a class="prism-nav-home" href="research_adviser.php" aria-label="Dashboard" <?php if (($prismCurrentPage ?? '') === 'research_adviser.php') echo 'aria-current="page"'; ?>><i class="fa-solid fa-house" aria-hidden="true"></i><span>Dashboard</span></a>
<?php endif; ?>
<?php foreach ($prismNavGroups as $prismNavKey => [$prismNavTitle, $prismNavIcon, $prismNavLinks]):
    $prismNavOpen = in_array($prismCurrentPage ?? '', array_column($prismNavLinks, 0), true);
?>
<section class="prism-nav-group">
<p class="prism-nav-section-label"><?= $prismNavEscape($prismNavKey) ?></p>
<button type="button" id="prismNav<?= $prismNavKey ?>Toggle" class="prism-nav-group-toggle" aria-label="<?= $prismNavEscape($prismNavTitle) ?>" aria-expanded="<?= $prismNavOpen ? 'true' : 'false' ?>" aria-controls="prismNav<?= $prismNavKey ?>"><i class="fa-solid <?= $prismNavIcon ?>" aria-hidden="true"></i><span><?= $prismNavEscape($prismNavTitle) ?></span><i class="fa-solid fa-chevron-down prism-nav-chevron" aria-hidden="true"></i></button>
<ul class="nav-links prism-nav-submenu" id="prismNav<?= $prismNavKey ?>" <?= $prismNavOpen ? '' : 'hidden' ?>>
<?php foreach ($prismNavLinks as [$prismNavUrl, $prismNavLabel]): ?>
<li><a href="<?= $prismNavEscape($prismNavUrl) ?>" <?= ($prismCurrentPage ?? '') === $prismNavUrl ? 'aria-current="page"' : '' ?>><span><?= $prismNavEscape($prismNavLabel) ?></span></a></li>
<?php endforeach; ?>
</ul></section>
<?php endforeach; ?>
</nav>
<?php // Render only in the top-right toolbar; never reserve sidebar space. ?>
<template data-prism-admin-account-template>
<div class="prism-account-menu">
<button class="portal-profile-btn" data-prism-account-toggle type="button" aria-expanded="false" aria-controls="prismAccountLinks" aria-label="Admin account menu"><img src="assets/images/default-avatar.svg" alt=""><span><strong><?= $prismNavEscape($authUser['full_name'] ?? '') ?></strong><small>RPMS Admin</small></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
<div class="prism-account-links" id="prismAccountLinks" hidden><a href="account.php"><i class="fa-regular fa-user" aria-hidden="true"></i>My account</a><a href="account.php#activity"><i class="fa-solid fa-list" aria-hidden="true"></i>Activity logs</a><div class="prism-account-divider" role="separator"></div><form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"  class="prism-account-logout" ><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</button></form></div>
</div>
</template>
<?php endif; ?>
<noscript><form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Log out</button></form></noscript>
<template data-prism-guide-role="<?= $prismNavEscape($authUser['role']) ?>" data-prism-guide-user="<?= $prismNavEscape(hash('sha256', ($authUser['role'] ?? '') . '|' . ($authUser['id'] ?? ''))) ?>"></template>
