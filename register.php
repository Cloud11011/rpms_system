<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RPMS Staff Registration | PRISM</title>

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body>

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
        <div class="register-text"><a href="login.php">Back to login</a></div>
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
                    placeholder="Full Name"
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
                    placeholder="Password (8+ characters)"
                    minlength="8"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <span class="toggle-password">

                    <i class="fa-solid fa-eye" id="togglePassword"></i>

                </span>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    name="confirm_password"
                    id="confirmPassword"
                    placeholder="Confirm Password"
                    minlength="8"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <span class="toggle-password">

                    <i class="fa-solid fa-eye" id="toggleConfirmPassword"></i>

                </span>

            </div>

            <button type="submit">

                REGISTER

            </button>

            <div class="register-text">

                Already have an account?

                <a href="login.php">Login</a>

            </div>

            <a class="back-to-roles" href="login.php">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>

        </form>
        <?php endif; ?>

    </div>

</div>

<script src="assets/js/script.js"></script>

</body>
</html>
