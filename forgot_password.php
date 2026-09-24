<?php
require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Forgot Password | PRISM</title>

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body>

<div class="background-overlay">

    <div class="forgot-card">

        <div class="logo-container">

            <img src="assets/images/prismlogo1.png" class="logo-main" alt="PRISM logo">

        </div>

        <h2>Forgot Password?</h2>

        <p class="subtitle">
            Enter your registered CEU email address and we'll send you a password reset link.
        </p>
    <?php

        if(isset($_SESSION["error"])){

        echo "<div class='error-message'>" . htmlspecialchars($_SESSION["error"], ENT_QUOTES, 'UTF-8') . "</div>";

        unset($_SESSION["error"]);

    }

    if(isset($_SESSION["success"])){

        echo "<div class='success-message'>" . htmlspecialchars($_SESSION["success"], ENT_QUOTES, 'UTF-8') . "</div>";

        unset($_SESSION["success"]);

    }

    ?>
        <form action="forgot_password_process.php" method="POST">

            <div class="input-group">

                <i class="fa-solid fa-envelope"></i>

                <input
                    type="email"
                    name="email"
                    placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email"
                    autocomplete="email"
                    required>

            </div>

            <button type="submit">

                SEND RESET LINK

            </button>

            <div class="register-text">

                Remember your password?

                <a href="login.php">Login</a>

            </div>

        </form>

    </div>

</div>

</body>
</html>
