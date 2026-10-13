<?php
// Public presentation only: no private metrics, identities or record queries.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
$prismPublicFooter = true;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PRISM | IERB Progress &amp; Reporting System</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" href="assets/images/prismicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11-public.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php prism_frontend_styles(); ?>
</head><body class="prism-public">
<a class="public-skip" href="#publicMain">Skip to content</a>
<header class="public-nav"><a href="index.php" aria-label="PRISM home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a><nav aria-label="Public navigation"><a href="#stages">IERB process</a><button type="button" id="publicTheme" aria-label="Toggle light or dark theme">Theme</button><a class="public-button" href="login.php">Log in</a></nav></header>
<main id="publicMain"><section class="public-hero"><div><p class="public-eyebrow">CENTRO ESCOLAR UNIVERSITY · MALOLOS</p><h1>IERB Progress &amp;<br>Reporting System</h1><p>Track research progress, review submissions and stay informed throughout the institutional ethics review process.</p><div class="public-hero-actions"><a class="public-button" href="login.php">Log in to PRISM</a><a class="public-button public-secondary" href="#stages">Explore the IERB process</a></div><p class="public-caption">Student · Research Adviser · RPMS Administrator</p></div></section>
<section id="stages" class="public-stages"><p class="public-eyebrow">THE RESEARCH JOURNEY</p><h2>The IERB process, stage by stage</h2><p>Official progress is managed by RPMS and IERB. Sign in to view your own records.</p><ol>
<?php foreach (['Protocol Submission','Initial Ethics Review','Revisions & Resubmission','Certificate of Approval','Continuing Review / Monitoring','IERB Process Completed'] as $number => $label): ?>
<li><span><?= $number + 1 ?></span><h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3></li>
<?php endforeach; ?>
</ol></section><section class="public-cta"><div><h2>Ready to check your progress?</h2><p>Use your institution-provided PRISM account.</p></div><a class="public-button" href="login.php">Log in</a></section></main>
<?php require __DIR__.'/ceu_footer.php'; ?>
<nav class="public-legal" aria-label="Legal information"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms of Service</a></nav>
<script src="<?= htmlspecialchars(asset_url('assets/js/batch11-public.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body></html>
