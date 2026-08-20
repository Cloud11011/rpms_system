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

        <?php
        session_start();
        if (isset($_SESSION['success'])) {
            echo "<div class='success-message'>" . htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') . "</div>";
            unset($_SESSION['success']);
        }
        if (isset($_SESSION['error'])) {
            echo "<div class='error-message'>" . htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') . "</div>";
            unset($_SESSION['error']);
        }
        ?>

        <nav class="role-selector" aria-label="Select account type">
            <p>Login As:</p>
            <a class="role-option" href="login_students.php">
                Student
            </a>
            <a class="role-option" href="login_adviser.php">
                Research Adviser
            </a>
            <a class="role-option" href="login_admin.php">
                Admin
            </a>
        </nav>

    </div>

</div>

</body>
</html>
