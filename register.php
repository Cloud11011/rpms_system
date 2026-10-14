<?php
require __DIR__ . '/config.php';
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
if (current_user()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RPMS Staff Registration | PRISM</title>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/account-setup-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/register-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<?php prism_frontend_styles(); ?>
</head>
<body class="unified-login account-setup-page setup-split register-page">
<div class="setup-wrap">
<section class="setup-campus" aria-label="Welcome">
<a href="index.php" aria-label="PRISM public home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a>
<div><h2>RPMS staff registration</h2><p>Create an administrator account for PRISM. You will need the private staff registration code from RPMS.</p></div>
</section>
<main class="setup-panel">
<div class="setup-card register-card">
<div class="wiz-progress" aria-live="polite"><span id="wizLabel">Step 1 of 2</span><span class="wiz-bar" aria-hidden="true"><i id="wizFill"></i></span></div>
<h1 id="wizTitle">Create your account</h1>
<p class="setup-lead" id="wizLead">First, enter your staff code and who you are.</p>
<?php
if (isset($_SESSION['error'])) {
    echo "<p role='alert' class='setup-error'>" . htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') . "</p>";
    unset($_SESSION['error']);
}
?>
<noscript><p role="alert" class="setup-error">JavaScript is required to complete registration. Enable it and reload this page.</p></noscript>
<form action="register_process.php" method="POST" id="registerForm" data-steps="2">
<section class="wiz-panel" data-step="1">
<label for="registration_code">Staff registration code</label>
<div class="reg-field"><i class="fa-solid fa-key" aria-hidden="true"></i><input id="registration_code" type="text" name="registration_code" maxlength="100" placeholder="Enter the private code" autocomplete="off" required></div>
<p class="reg-hint">Ask the Research Planning and Monitoring Section if you do not have one.</p>
<label for="employee_id">Employee ID</label>
<div class="reg-field"><i class="fa-solid fa-id-badge" aria-hidden="true"></i><input id="employee_id" type="text" name="employee_id" maxlength="100" placeholder="Employee ID" aria-describedby="empHint" required></div>
<p class="reg-hint" id="empHint">Your Employee ID is used as your username.</p>
<label for="fullname">Full name</label>
<div class="reg-field"><i class="fa-solid fa-user" aria-hidden="true"></i><input id="fullname" type="text" name="fullname" maxlength="190" placeholder="Your full name" autocomplete="name" required></div>
</section>
<section class="wiz-panel" data-step="2" hidden>
<label for="email" class="first">Institutional email</label>
<div class="reg-field"><i class="fa-solid fa-envelope" aria-hidden="true"></i><input id="email" type="email" name="email" maxlength="190" placeholder="<?php echo htmlspecialchars(allowed_email_domains_hint(), ENT_QUOTES, 'UTF-8'); ?> email" autocomplete="email" required></div>
<label for="password">New password</label>
<div class="reg-field"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" name="password" id="password" placeholder="At least 12 characters" minlength="12" maxlength="200" autocomplete="new-password" aria-describedby="regLen" required><button type="button" class="toggle-password" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="fa-solid fa-eye" id="togglePassword" aria-hidden="true"></i></button></div>
<p class="setup-rule" id="regLen"><span class="setup-dot" aria-hidden="true"></span><span>12&ndash;200 characters (<span id="regCount">0</span> so far)</span></p>
<label for="confirmPassword">Confirm password</label>
<div class="reg-field"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" name="confirm_password" id="confirmPassword" placeholder="Type it again" minlength="12" maxlength="200" autocomplete="new-password" aria-describedby="regMatch" required><button type="button" class="toggle-password" aria-label="Show confirm password" aria-controls="confirmPassword" aria-pressed="false"><i class="fa-solid fa-eye" id="toggleConfirmPassword" aria-hidden="true"></i></button></div>
<p class="setup-rule" id="regMatch" hidden><span class="setup-dot" aria-hidden="true"></span><span id="regMatchText"></span></p>
</section>
<div class="wiz-actions">
<button type="button" class="setup-btn setup-btn-ghost" id="wizBack" hidden>Back</button>
<button type="button" class="setup-btn" id="wizNext">Continue</button>
<button type="submit" class="setup-btn" id="wizSubmit" hidden>Register</button>
</div>
</form>
<p class="setup-help">Already have an account? <a href="login.php">Log in</a></p>
</div>
<footer class="setup-legal"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms of Service</a></footer>
</main>
</div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/script.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/register-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
