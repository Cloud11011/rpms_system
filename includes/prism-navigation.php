<?php
// Presentation partial: the caller must already have authenticated the user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser'], true)) {
    http_response_code(404);
    exit;
}
$prismNavEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
if ($authUser['role'] === 'adviser'):
    $prismPortalLinks = [
        ['research_adviser.php', 'Dashboard'], ['admin_students.php', 'Assigned Students'],
        ['documents.php', 'Document Reviews'], ['ierbprog.php', 'IERB Progress'],
        ['calendar.php', 'Calendar'], ['research_adviser.php#prismResourcesTitle', 'Research Resources'],
    ];
?>
<a class="portal-brand" href="research_adviser.php" aria-label="PRISM dashboard: Progress and Research Information System for Monitoring" title="Progress and Research Information System for Monitoring"><img src="assets/images/prismlogo1.png?v=2" alt="PRISM"><span class="prism-navigation-caption">Progress and Research Information System for Monitoring</span></a>
<button class="portal-navigation-toggle" id="adviserNavigationToggle" type="button" aria-expanded="false" aria-controls="prismPrimaryNavigation">Menu</button>
<nav id="prismPrimaryNavigation" class="portal-nav-links" aria-label="Main navigation">
<?php foreach ($prismPortalLinks as [$prismNavUrl, $prismNavLabel]): ?>
<a class="prism-nav-home" href="<?= $prismNavEscape($prismNavUrl) ?>" <?= ($prismCurrentPage ?? '') === $prismNavUrl ? 'aria-current="page"' : '' ?>><?= $prismNavEscape($prismNavLabel) ?></a>
<?php endforeach; ?>
</nav>
<div class="portal-nav-right">
<a class="portal-help" href="research_adviser.php#prismResourcesTitle" aria-label="Research help" title="Research help"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></a>
<a class="portal-notification" href="admin_notifications.php" aria-label="Notifications" title="Notifications" <?= ($prismCurrentPage ?? '') === 'admin_notifications.php' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-bell" aria-hidden="true"></i></a>
<div class="prism-account-menu">
<button class="portal-profile-btn" data-prism-account-toggle type="button" aria-expanded="false" aria-controls="prismAccountLinks" aria-label="Adviser account menu"><img src="assets/images/default-avatar.svg" alt=""><span><strong><?= $prismNavEscape($authUser['full_name'] ?? '') ?></strong><small>Research Adviser</small></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
<div class="prism-account-links" id="prismAccountLinks" hidden><a href="account.php" <?= ($prismCurrentPage ?? '') === 'account.php' ? 'aria-current="page"' : '' ?>>Profile &amp; settings</a><a href="account.php#activity">Activity logs</a><a href="logout.php">Log out</a></div>
</div>
</div>
<?php else:
$prismNavGroups = [
    'Research' => ['Research Management', 'fa-folder-open', [
        ['admin_students.php', 'Student Records'],
        ...($authUser['role'] === 'admin' ? [['admin_advisers.php', 'Research Advisers']] : []),
        ['ierbprog.php', 'IERB Progress'], ['documents.php', 'Document Submissions'],
    ]],
    'Reporting' => ['Reports & Communication', 'fa-chart-line', [
        ...($authUser['role'] === 'admin' ? [['admin_ai.php', 'AI Progress Reports'], ['reports.php', 'Generated Reports'], ['data_export.php', 'Data Export']] : []),
        ['admin_notifications.php', 'Notifications'],
    ]],
    'Account' => ['Account & Planning', 'fa-user-gear', [
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
<div class="sidebar-header prism-sidebar-brand" title="Progress and Research Information System for Monitoring" aria-label="PRISM: Progress and Research Information System for Monitoring"><img src="assets/images/prismlogo1.png?v=2" class="sidebar-brand-logo" alt="PRISM"><img src="assets/images/prismicon.png" class="sidebar-brand-icon" alt="PRISM: Progress and Research Information System for Monitoring"><span class="prism-navigation-caption">Progress and Research Information System for Monitoring</span></div>
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
<div class="prism-sidebar-utilities"><a class="prism-nav-home" href="account.php#security" aria-label="Settings"><i class="fa-solid fa-gear" aria-hidden="true"></i><span>Settings</span></a><a class="prism-nav-home" href="logout.php" aria-label="Log out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a></div>
<div class="prism-account-menu" data-prism-admin-account>
<button class="portal-profile-btn" data-prism-account-toggle type="button" aria-expanded="false" aria-controls="prismAccountLinks" aria-label="Admin account menu"><img src="assets/images/default-avatar.svg" alt=""><span><strong><?= $prismNavEscape($authUser['full_name'] ?? '') ?></strong><small>RPMS Admin</small></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
<div class="prism-account-links" id="prismAccountLinks" hidden><a href="account.php">My account</a><a href="account.php#activity">Activity logs</a></div>
</div>
<?php endif; ?>
