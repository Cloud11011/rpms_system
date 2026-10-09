<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RPMS Staff Registration | PRISM</title>

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body class="unified-login">

<div class="background-overlay">

    <div class="register-card">

        <div class="logo-container">

            <img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">

        </div>

        <h2>RPMS Staff Registration</h2>

        <p class="subtitle">
            Create an administrator account using the private staff registration code.
        </p>

        <?php
        if (isset($_SESSION['error'])) {
            echo "<div class='error-message'>" . htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') . "</div>";
            unset($_SESSION['error']);
        }
        ?>

        <?php if (ADMIN_REGISTRATION_CODE === ''): ?>
        <div class="error-message">RPMS staff self-registration is currently disabled. Ask an existing RPMS administrator to create your account or enable the private registration code.</div>
        <div class="register-text"><a href="login.php">Login</a></div>
        <?php else: ?>
        <form action="register_process.php" method="POST">

            <div class="input-group">

                <i class="fa-solid fa-key"></i>

                <input
                    type="text"
                    name="registration_code"
                    placeholder="Staff Registration Code"
                    autocomplete="off"
                    required>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-id-badge"></i>

                <input
                    type="text"
                    name="employee_id"
                    placeholder="Employee ID"
                    maxlength="100"
                    required>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-user"></i>

                <input
                    type="text"
                    name="fullname"
                    placeholder="Enter your full name"
                    maxlength="190"
                    required>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-envelope"></i>

                <input
                    type="email"
                    name="email"
                    placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email"
                    autocomplete="email"
                    required>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="Password (12+ characters)"
                    minlength="12"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <button type="button" class="toggle-password" aria-label="Show password" aria-controls="password" aria-pressed="false">

                    <i class="fa-solid fa-eye" id="togglePassword" aria-hidden="true"></i>

                </button>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    name="confirm_password"
                    id="confirmPassword"
                    placeholder="Confirm Password"
                    minlength="12"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <button type="button" class="toggle-password" aria-label="Show confirm password" aria-controls="confirmPassword" aria-pressed="false">

                    <i class="fa-solid fa-eye" id="toggleConfirmPassword" aria-hidden="true"></i>

                </button>

            </div>

            <button type="submit">

                Register

            </button>

            <div class="register-text">

                Already have an account?

                <a href="login.php">Login</a>

            </div>

        </form>
        <?php endif; ?>

    </div>

</div>

<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>

</body>
</html>
