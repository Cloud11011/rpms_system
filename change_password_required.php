<?php
require __DIR__ . '/config.php';
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
// If they've already changed it (e.g. in another tab), send them on their way.
if (empty($_SESSION['must_change_password'])) {
    header('Location: index.php');
    exit;
}
$landing = $user['role'] === 'admin' ? 'dashboard.php' : ($user['role'] === 'adviser' ? 'ierbprog.php' : 'student.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Your Password | PRISM</title>

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

        <h2>Change Your Password</h2>

        <p class="subtitle">
            Your account was created with a temporary password. For your security,
            set a new password before continuing.
        </p>

        <div id="formMessage"></div>

        <form id="forcedPasswordForm">

            <div class="input-group">

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    id="currentPassword"
                    placeholder="Current (temporary) password"
                    required>

                <span class="toggle-password"><i class="fa-solid fa-eye" id="toggleCurrentPassword"></i></span>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-key"></i>

                <input
                    type="password"
                    id="newPassword"
                    placeholder="New password (min. 8 characters)"
                    minlength="8"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <span class="toggle-password"><i class="fa-solid fa-eye" id="toggleNewPassword"></i></span>

            </div>

            <div class="input-group">

                <i class="fa-solid fa-key"></i>

                <input
                    type="password"
                    id="confirmPassword"
                    placeholder="Confirm new password"
                    minlength="8"
                    maxlength="200"
                    autocomplete="new-password"
                    required>

                <span class="toggle-password"><i class="fa-solid fa-eye" id="toggleConfirmNewPassword"></i></span>

            </div>

            <button type="submit">

                CHANGE PASSWORD &amp; CONTINUE

            </button>

            <div class="register-text">

                <a href="logout.php">Log out instead</a>

            </div>

        </form>

    </div>

</div>

<script src="assets/js/script.js"></script>
<script>
togglePassword('currentPassword', 'toggleCurrentPassword');
togglePassword('newPassword', 'toggleNewPassword');
togglePassword('confirmPassword', 'toggleConfirmNewPassword');

document.getElementById('forcedPasswordForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const messageBox = document.getElementById('formMessage');
    messageBox.replaceChildren();
    const showError = message => {
        const error = document.createElement('div');
        error.className = 'error-message';
        error.textContent = message;
        messageBox.replaceChildren(error);
    };
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    if (newPassword !== confirmPassword) {
        showError('New passwords do not match.');
        return;
    }
    try {
        const res = await fetch('profile_api.php?action=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                currentPassword: document.getElementById('currentPassword').value,
                newPassword,
            }),
        });
        const data = await res.json();
        if (!data.ok) {
            showError(data.message || 'Password could not be changed.');
            return;
        }
        window.location.href = <?php echo json_encode($landing); ?>;
    } catch (_) {
        showError('Could not reach the server. Please try again.');
    }
});
</script>

</body>

</html>
