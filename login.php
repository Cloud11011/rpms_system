<?php
require __DIR__ . '/config.php';

// Already logged in? Send them straight to their landing page instead of
// showing the form again.
if (current_user()) {
    header('Location: index.php');
    exit;
}

$loginError = isset($_GET['expired']) || !empty($GLOBALS['prism_session_expired'])
    ? 'Your session expired due to inactivity. Please sign in again.'
    : ($_SESSION['error'] ?? null);
$loginSuccess = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | PRISM</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11-public.css'), ENT_QUOTES, 'UTF-8') ?>">

<?php prism_frontend_styles(); ?>
</head>

<body class="unified-login batch11-login">

<div class="background-overlay">
    <section class="login-campus" aria-labelledby="loginCampusTitle">
        <a class="login-campus-brand" href="index.php" aria-label="PRISM public home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a>
        <div class="login-campus-copy"><p class="public-eyebrow">IERB PROGRESS &amp; REPORTING SYSTEM</p><h2 id="loginCampusTitle">Welcome to PRISM!</h2><p>Follow your research through ethics review, feedback and RPMS submission, all in one place.</p></div>
        <p class="login-campus-institution"><strong>Centro Escolar University &ndash; Malolos Campus</strong><br>Research Planning and Monitoring Section</p>
    </section>

    <div class="login-form-column">

    <div class="login-card">

        <div class="logo-container">
            <img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">
        </div>

<?php if ($loginError): ?><div class="error-message<?= $loginError === 'Your session expired due to inactivity. Please sign in again.' ? ' session-expiry-notice' : '' ?>"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($loginSuccess): ?><div class="success-message"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <form action="login_process.php" method="POST">

            <div class="input-group">
                <label class="login-field-label" for="loginEmail">Email</label>
                <i class="fa-solid fa-envelope"></i>
                <input id="loginEmail" type="email" name="email" placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email" autocomplete="username" required autofocus>
            </div>

            <div class="input-group">
                <label class="login-field-label" for="password">Password</label>
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required>
                <button type="button" class="toggle-password" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="fa-solid fa-eye" id="togglePassword" aria-hidden="true"></i></button>
            </div>

            <div class="form-options">
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit">Log in</button>

            <p class="login-account-note"><button class="login-access-info" type="button" aria-haspopup="dialog">Why is there no registration form?</button></p>

        </form>



    </div>

    <footer class="login-legal-footer" aria-label="Legal information"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms of Service</a></footer>
    </div>
</div>

<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/login-legal.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>

</body>
</html>
