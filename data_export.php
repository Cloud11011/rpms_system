<?php
require __DIR__ . '/config.php';
$authUser = require_login('admin');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Export | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" href="assets/images/prismicon.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php foreach (['dashboard','prism-ui','workspace-pages','dashboard-sidebar','ceu-footer','prism-workspace'] as $sheet): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/'.$sheet.'.css'),ENT_QUOTES,'UTF-8') ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="<?= htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'),ENT_QUOTES,'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/prism-workspace.js'),ENT_QUOTES,'UTF-8') ?>" defer></script>
</head><body class="prism-workspace admin-shell data-export-page">
<div class="container"><aside class="sidebar prism-sidebar">
<?php $prismCurrentPage='data_export.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
</aside><main class="main-content">
<header class="topbar"><div><h1>Data Export</h1></div>
<div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle light or dark theme" title="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
<div class="data-export-grid">
<?php foreach ([
    ['students','Student Records','Active and archived Student records, academic details and adviser assignments.'],
    ['advisers','Adviser Records','Active and inactive Advisers, current research groups and active Student counts.'],
    ['ierb','IERB Progress','Current progress for active Students, including document workflow and attention indicators.'],
    ['documents','Document Records','Document metadata, including historical records and earlier versions.']
] as [$type,$label,$description]): ?>
<section class="panel data-export-card"><h2><?= $label ?></h2><p><?= $description ?></p>
<a class="prism-btn is-secondary" href="data_exports_api.php?action=<?= $type ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Download <?= $label ?> CSV</a></section>
<?php endforeach; ?>
</div><p class="data-export-note">CSV exports support administrative review. Server and database backups remain the recovery mechanism.</p>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main></div>
<script>document.getElementById('themeToggle').addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark-theme');try{localStorage.setItem('prismTheme',dark?'dark':'light')}catch(_){}});</script>
</body></html>
