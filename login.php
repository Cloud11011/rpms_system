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

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body class="unified-login">

<div class="background-overlay">

    <div class="login-card">

        <div class="logo-container">
            <img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">
        </div>

        <div class="prism-branding">
            <h1>Welcome to <span>PRISM</span>!</h1>
            <p><strong>IERB Progress &amp; Reporting System</strong></p>
            <p>Centro Escolar University - Malolos <span aria-hidden="true">&bull;</span> RPMS</p>
        </div>

        <?php if ($loginError): ?><div class="error-message<?= $loginError === 'Your session expired due to inactivity. Please sign in again.' ? ' session-expiry-notice' : '' ?>"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($loginSuccess): ?><div class="success-message"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <form action="login_process.php" method="POST">

            <div class="input-group">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email" autocomplete="username" required autofocus>
            </div>

            <div class="input-group">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required>
                <button type="button" class="toggle-password" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="fa-solid fa-eye" id="togglePassword" aria-hidden="true"></i></button>
            </div>

            <div class="form-options">
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit">Log in</button>

            <p class="login-account-note">Students and research advisers receive their PRISM account access from RPMS.</p>

        </form>

    </div>

</div>

<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>

</body>
</html>
