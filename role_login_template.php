<?php
require __DIR__ . '/config.php';
if (!isset($accountType, $accountLabel)) {
    header('Location: login.php');
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
    <title><?php echo htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8'); ?> Login | PRISM</title>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="background-overlay">
    <div class="login-card role-login-card">
        <div class="logo-container">
            <img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">
        </div>

        <div class="prism-branding">
            <h1>Welcome Back!</h1>
            <p>Please enter your details to login.</p>
        </div>

        <?php if ($loginError): ?><div class="error-message"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($loginSuccess): ?><div class="success-message"><?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <form action="login_process.php" method="POST">
            <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-group">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="username" placeholder="Username" autocomplete="username" required autofocus>
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

            <?php if ($accountType === 'admin'): ?>
            <div class="register-text">
                Don't have an RPMS staff account?
                <a href="register.php">Register</a>
            </div>
            <?php else: ?>
            <div class="register-text">
                Accounts for this role are created by the RPMS office.
            </div>
            <?php endif; ?>

            <a class="back-to-roles" href="login.php"><i class="fa-solid fa-arrow-left"></i> Choose another account type</a>
        </form>
    </div>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>
