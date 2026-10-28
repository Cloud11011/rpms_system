<?php
require __DIR__.'/config.php';
header('Referrer-Policy: origin');
header('Cache-Control: no-store');
$error=''; $done=false;
$token=is_string($_POST['token']??null)?$_POST['token']:'';
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    require_post_same_origin();
    try {
        if (!consume_auth_attempt('invitation_accept_ip',(string)($_SERVER['REMOTE_ADDR']??''),20,900)) throw new AccountLifecycleForbidden('Too many attempts. Try again later.');
        onboarding_accept(db(),$_POST);
        $done=true;
    } catch(Throwable $e) { $error=lifecycle_error_status($e)<500?$e->getMessage():'Setup is temporarily unavailable. Contact RPMS.'; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up your account | PRISM</title><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script><link rel="icon" href="assets/images/prismicon.png"><link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11-public.css'), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/onboarding.css'), ENT_QUOTES, 'UTF-8') ?>"><?php prism_frontend_styles(); ?><link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/account-setup-ui.css'), ENT_QUOTES, 'UTF-8') ?>"></head>
<body class="unified-login batch11-login account-setup-page"><div class="background-overlay">
<section class="login-campus" aria-labelledby="setupCampusTitle"><a class="login-campus-brand" href="index.php" aria-label="PRISM public home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a><div class="login-campus-copy"><p class="public-eyebrow">IERB PROGRESS &amp; REPORTING SYSTEM</p><h2 id="setupCampusTitle">Welcome to PRISM!</h2><p>Your account was created from RPMS. One quick step and you can start tracking your research.</p></div><p class="login-campus-institution"><strong>Centro Escolar University &ndash; Malolos Campus</strong><br>Research Planning and Monitoring Section</p></section>
<div class="login-form-column">
<?php if($done): ?>
<section class="login-card setup-card setup-done" role="status" aria-labelledby="setupDoneTitle"><div class="logo-container"><img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo"></div><ol class="setup-steps" aria-label="Setup steps"><li class="is-complete"><b aria-label="Complete">✓</b><span>Set password</span></li><li aria-current="step"><b>2</b><span>Sign in</span></li><li><b>3</b><span>Complete profile</span></li></ol><span class="setup-done-icon" aria-hidden="true">&#10003;</span><h1 id="setupDoneTitle">Password set successfully</h1><p>Your password is ready. Sign in with your invited email, then complete your profile.</p><a class="setup-btn" href="login.php">Continue to sign in</a></section>
<?php else: ?>
<section class="login-card setup-card"><div class="logo-container"><img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo"></div><ol class="setup-steps" aria-label="Setup steps"><li aria-current="step"><b>1</b><span>Set password</span></li><li><b>2</b><span>Sign in</span></li><li><b>3</b><span>Complete profile</span></li></ol>
<h1>Set up your PRISM account</h1><p class="setup-lead">Choose a password, then sign in and complete your profile.</p><p class="setup-notice"><span aria-hidden="true">&#9719;</span> This setup link expires in 1 hour and works once.</p><p role="alert" class="setup-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p>
<form method="post" action="account_setup.php" id="setupForm"><input type="hidden" name="token" value="<?= htmlspecialchars($token,ENT_QUOTES,'UTF-8') ?>">
<label for="setupPassword">New password</label><div class="onboarding-password"><input id="setupPassword" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" placeholder="At least 12 characters" required aria-describedby="ruleLength"><button type="button" class="toggle-password" aria-label="Show password" aria-controls="setupPassword" aria-pressed="false">Show</button></div>
<p class="setup-rule" id="ruleLength"><span class="setup-dot" aria-hidden="true"></span><span>At least 12 characters, at most 200 bytes (<span id="setupCount">0</span> so far)</span></p>
<label for="setupConfirm">Confirm password</label><div class="onboarding-password"><input id="setupConfirm" name="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="200" placeholder="Re-enter the new password" required aria-describedby="ruleMatch"><button type="button" class="toggle-password" aria-label="Show confirm password" aria-controls="setupConfirm" aria-pressed="false">Show</button></div>
<p class="setup-rule" id="ruleMatch" hidden><span class="setup-dot" aria-hidden="true"></span><span id="matchText" aria-live="polite"></span></p><button type="submit" class="setup-btn" id="setupSubmit">Establish password</button></form><noscript>Enable JavaScript to open the secure setup link.</noscript><p class="setup-help">Link not working? Ask the Research Planning and Monitoring Section for a new one.</p></section>
<?php endif; ?>
<footer class="login-legal-footer" aria-label="Legal information"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms of Service</a></footer>
</div></div><script src="<?= htmlspecialchars(asset_url('assets/js/account-setup.js'), ENT_QUOTES, 'UTF-8') ?>"></script></body></html>