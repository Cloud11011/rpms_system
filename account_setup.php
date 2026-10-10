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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up your account | PRISM</title><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script><link rel="icon" type="image/png" href="assets/images/prismicon.png"><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>"><link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/onboarding.css'), ENT_QUOTES, 'UTF-8'); ?>"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></head>
<body class="unified-login onboarding-page"><div class="background-overlay"><main class="login-card onboarding-card"><div class="logo-container"><img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo" width="220"></div><h1>Set up your PRISM account</h1>
<?php if($done): ?><p role="status">Password established. Sign in with your invited email to complete your profile.</p><a href="login.php">Sign in</a>
<?php else: ?><p>Choose a password, then sign in and complete your profile. Setup links expire after one hour and work once.</p>
<p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p>
<form method="post" action="account_setup.php"><input type="hidden" name="token" value="<?= htmlspecialchars($token,ENT_QUOTES,'UTF-8') ?>">
<label for="setupPassword">New password (required, 12–200 characters)</label><div class="onboarding-password"><input id="setupPassword" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required><button type="button" class="toggle-password" aria-label="Show password" aria-controls="setupPassword" aria-pressed="false"><i class="fa-solid fa-eye" id="toggleSetupPassword" aria-hidden="true"></i></button></div>
<label for="setupConfirm">Confirm password (required)</label><div class="onboarding-password"><input id="setupConfirm" name="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="200" required><button type="button" class="toggle-password" aria-label="Show confirm password" aria-controls="setupConfirm" aria-pressed="false"><i class="fa-solid fa-eye" id="toggleSetupConfirm" aria-hidden="true"></i></button></div>
<button type="submit">Establish password</button></form><noscript>Enable JavaScript to open the secure setup link.</noscript><?php endif; ?></main></div><script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script><script src="<?php echo htmlspecialchars(asset_url('assets/js/account-setup.js'), ENT_QUOTES, 'UTF-8'); ?>"></script></body></html>
