<?php
// Public presentation only: no private metrics, identities or record queries.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
$prismPublicFooter = true;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PRISM | IERB Progress &amp; Reporting System</title>
<link rel="icon" href="assets/images/prismicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11-public.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php prism_frontend_styles(); ?>
</head><body class="prism-public">
<a class="public-skip" href="#publicMain">Skip to content</a>
<header class="public-nav"><a href="index.php" aria-label="PRISM home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a><nav aria-label="Public navigation"><a href="#stages">How it works</a><a class="public-button" href="login.php">Log in</a></nav></header>
<main id="publicMain"><section class="public-hero"><div><p class="public-eyebrow">CENTRO ESCOLAR UNIVERSITY &middot; MALOLOS CAMPUS</p><h1>IERB Progress &amp;<br>Reporting System</h1><p>Follow your research through ethics review, feedback and RPMS submission, all in one place.</p><div class="public-hero-actions"><a class="public-button" href="login.php">Log in to PRISM</a><a class="public-button public-secondary" href="#stages">See how it works</a></div></div></section>
<section id="stages" class="public-stages"><p class="public-eyebrow">HOW IT WORKS</p><h2>The IERB process, stage by stage</h2><p>Every submission moves through these stages. PRISM shows students and advisers exactly where each one stands.</p><ol>
<?php foreach (['Protocol Submission','Initial Ethics Review','Revisions & Resubmission','Certificate of Approval','Continuing Review / Monitoring','IERB Process Completed'] as $number => $label): ?>
<li><span><?= $number + 1 ?></span><h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3></li>
<?php endforeach; ?>
</ol></section>
<section class="public-account-process" aria-labelledby="publicAccountTitle">
    <p class="public-eyebrow">GETTING STARTED</p>
    <h2 id="publicAccountTitle">How to get a PRISM account</h2>
    <p>PRISM uses invitations instead of public registration. RPMS and authorized research advisers manage account access and research assignments.</p>
    <ol>
        <li><span>1</span><h3>Receive an invitation</h3><p>RPMS invites students and research advisers. Authorized research advisers can also invite their students.</p></li>
        <li><span>2</span><h3>Set your password</h3><p>Check your email and follow the secure account setup link to create your password.</p></li>
        <li><span>3</span><h3>Complete your profile</h3><p>Sign in with your invited email, complete the required policy steps and profile, and follow any applicable account approval process.</p></li>
    </ol>
    <p class="public-account-contact">Need an invitation? Contact RPMS at <a href="mailto:rpms.ceu@gmail.com">rpms.ceu@gmail.com</a> or ask your research adviser.</p>
</section>
<section class="public-cta"><div><h2>Ready to check your progress?</h2><p>Log in with the account provided by RPMS.</p></div><a class="public-button" href="login.php">Log in</a></section></main>
<?php require __DIR__.'/ceu_footer.php'; ?>
<script>
window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    const hero = document.querySelector('.public-hero > div');
    if (!hero) return;
    const heroSection = hero.parentElement;
    hero.style.animation = 'none';
    heroSection.classList.add('is-restoring');
    void heroSection.offsetWidth;
    heroSection.classList.remove('is-restoring');
    hero.style.removeProperty('animation');
});
</script>
<script src="<?= htmlspecialchars(asset_url('assets/js/login-legal.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body></html>
