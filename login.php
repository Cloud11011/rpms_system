<?php
require __DIR__ . '/config.php';

// Already logged in? Send them straight to their landing page instead of
// showing the form again.
if (current_user()) {
    header('Location: loading.php');
    exit;
}

$loginError = $_SESSION['error'] ?? null;
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body>

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

        <?php if ($loginError): ?><div class="error-message"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($loginSuccess): ?><div class="success-message"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <form action="login_process.php" method="POST">

            <div class="input-group">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email" autocomplete="username" required autofocus>
            </div>

            <div class="input-group">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required>
                <span class="toggle-password"><i class="fa-solid fa-eye" id="togglePassword"></i></span>
            </div>

            <div class="form-options">
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit">LOGIN</button>

            <div class="register-text">
                RPMS staff without an account &mdash; <a href="register.php">Register</a>.
                Research advisers and students are given their login by the RPMS office.
            </div>

        </form>

    </div>

</div>

<script src="assets/js/script.js"></script>

</body>
</html>
