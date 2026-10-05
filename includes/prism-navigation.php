<?php
// Presentation partial: the caller must already have authenticated the user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser'], true)) {
    http_response_code(404);
    exit;
}
$prismNavEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$prismNavGroups = [
    'Research' => ['Research Management', 'fa-folder-open', [
        ['admin_students.php', 'Student Records'],
        ...($authUser['role'] === 'admin' ? [['admin_advisers.php', 'Research Advisers']] : []),
        ['ierbprog.php', 'IERB Progress'], ['documents.php', 'Document Submissions'],
    ]],
    'Reporting' => ['Reports & Communication', 'fa-chart-line', [
        ...($authUser['role'] === 'admin' ? [['admin_ai.php', 'AI Progress Reports'], ['reports.php', 'Generated Reports']] : []),
        ['admin_notifications.php', 'Notifications'],
    ]],
    'Account' => ['Account & Planning', 'fa-user-gear', [
        ['account.php', 'Account & Activity'], ['account.php#activity', 'Activity Logs'],
        ['calendar.php', 'Personal Calendar'],
    ]],
];
?>
<div class="sidebar-header prism-sidebar-brand"><img src="assets/images/prismlogo1.png?v=2" class="sidebar-brand-logo" alt="PRISM"><span class="prism-navigation-caption">Research workspace</span></div>
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
<button type="button" id="prismNav<?= $prismNavKey ?>Toggle" class="prism-nav-group-toggle" aria-label="<?= $prismNavEscape($prismNavTitle) ?>" aria-expanded="<?= $prismNavOpen ? 'true' : 'false' ?>" aria-controls="prismNav<?= $prismNavKey ?>"><i class="fa-solid <?= $prismNavIcon ?>" aria-hidden="true"></i><span><?= $prismNavEscape($prismNavTitle) ?></span><i class="fa-solid fa-chevron-down prism-nav-chevron" aria-hidden="true"></i></button>
<ul class="nav-links prism-nav-submenu" id="prismNav<?= $prismNavKey ?>" <?= $prismNavOpen ? '' : 'hidden' ?>>
<?php foreach ($prismNavLinks as [$prismNavUrl, $prismNavLabel]): ?>
<li><a href="<?= $prismNavEscape($prismNavUrl) ?>" <?= ($prismCurrentPage ?? '') === $prismNavUrl ? 'aria-current="page"' : '' ?>><span><?= $prismNavEscape($prismNavLabel) ?></span></a></li>
<?php endforeach; ?>
</ul></section>
<?php endforeach; ?>
<a class="prism-nav-home" href="logout.php" aria-label="Log out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
</nav>
