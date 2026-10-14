<?php
require __DIR__.'/config.php';
$user=require_login(['student','adviser']);
if(onboarding_complete(db(),$user)) { header('Location: '.($user['role']==='student'?'student.php':'ierbprog.php')); exit; }
$student=$user['role']==='student';
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Complete your profile | PRISM</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11-public.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/onboarding.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <?php prism_frontend_styles(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/complete-profile-ui.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="unified-login batch11-login onboarding-page profile-page" data-onboarding-role="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>">
<div class="background-overlay">
    <section class="login-campus" aria-labelledby="loginCampusTitle">
        <a class="login-campus-brand" href="index.php" aria-label="PRISM public home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a>
        <div class="login-campus-copy">
            <h2 id="loginCampusTitle">Almost there!</h2>
            <p>Tell us a little about yourself so PRISM can set up your workspace. It only takes a minute.</p>
        </div>
    </section>

    <div class="login-form-column">
        <main class="login-card onboarding-card setup-card profile-card">
            <div class="wiz-progress" aria-live="polite">
                <span id="wizLabel">Step 1 of <?= $student?2:1 ?></span>
                <span class="wiz-bar" aria-hidden="true"><i id="wizFill"></i></span>
            </div>
            <h1 id="profileTitle">Complete your <?= $student?'PRISM':'Adviser' ?> profile</h1>
            <p class="profile-lead" id="wizLead">Your profile is Pending. Complete these details to use the workspace.</p>

            <noscript><p role="alert" class="profile-error">JavaScript is required to complete your profile. Enable it and reload this page; your details have not been submitted.</p></noscript>

            <form id="completeProfileForm" method="post" action="complete_profile.php" aria-labelledby="profileTitle" data-steps="<?= $student?2:1 ?>">
                <section class="wiz-panel" data-step="1">
                    <p class="setup-notice setup-email">
                        <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                        <span>Invited email: <strong class="invited-email"><?= htmlspecialchars($user['email'],ENT_QUOTES,'UTF-8') ?></strong></span>
                    </p>
                    <label for="onboardingName">Full name</label>
                    <input id="onboardingName" name="name" autocomplete="name" maxlength="190" required>

                    <label for="onboardingId"><?= $student?'Student':'Employee' ?> ID</label>
                    <input id="onboardingId" name="<?= $student?'studentId':'employeeId' ?>" maxlength="100" aria-describedby="identityPolicy" required>
                    <p id="identityPolicy" class="profile-hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>After completion, ID corrections require Admin review.</span></p>
                    <?php if(!$student): ?>
                    <label for="onboardingDepartment">Academic unit / Department</label>
                    <select id="onboardingDepartment" name="department" required>
                        <option value="">Choose an academic unit</option>
                        <?php foreach(academic_catalog()['units'] as $unit): ?>
                        <option><?= htmlspecialchars($unit['label'],ENT_QUOTES,'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </section>
                <?php if($student): ?>
                <section class="wiz-panel" data-step="2" hidden>
                    <fieldset id="onboardingAcademics">
                        <legend class="sr-only">Academic information (required)</legend>
                        <div class="academic-fields-grid">
                            <label for="onboardingUnit">Academic unit<select id="onboardingUnit" data-academic="unit" required></select></label>
                            <label for="onboardingProgram">Program<select id="onboardingProgram" data-academic="program" required></select></label>
                            <label for="onboardingYear">Year level<select id="onboardingYear" data-academic="year" required></select></label>
                            <label for="onboardingAcademicYear">Academic year<select id="onboardingAcademicYear" data-academic="academic-year" required></select></label>
                        </div>
                        <p data-academic="summary" aria-live="polite" class="profile-hint"></p>
                        <p data-academic="legacy" hidden></p>
                        <button type="button" data-academic="reset" hidden>Keep existing values</button>
                    </fieldset>
                    <label for="onboardingResearch">Research title <em class="opt">optional</em></label>
                    <input id="onboardingResearch" name="research" maxlength="255" placeholder="You can add this later">
                    <p class="profile-hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Adviser and research-group assignments are managed by staff.</span></p>
                </section>
                <?php endif; ?>

                <p id="onboardingResult" role="alert" aria-live="polite" tabindex="-1" class="profile-error">The profile form is loading. If it does not become ready, enable JavaScript and reload this page. Your details have not been submitted.</p>
                <div class="wiz-actions">
                    <button type="button" class="wiz-back-icon" id="wizBack" aria-label="Back to personal details" title="Back to personal details" hidden>
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="setup-btn" id="wizNext"<?= $student?'':' hidden' ?>>Continue</button>
                    <button type="submit" class="setup-btn" id="wizSubmit"<?= $student?' hidden':'' ?> disabled>Complete profile</button>
                </div>
            </form>
            <p class="profile-help"><a href="help_support.php">Help &amp; Support</a></p>
        </main>
        <footer class="login-legal-footer" aria-label="Legal information">
            <a href="privacy.php">Privacy Policy</a>
            <a href="terms.php">Terms of Service</a>
            <button form="prismLogoutForm" style="all:unset;outline:revert;color:light-dark(#b5146e,#eaa0c4);cursor:pointer">Sign out</button>
        </footer>
    </div>
</div>
<script>window.PRISM_ACADEMIC_CATALOG=<?= json_encode(academic_catalog(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="<?= htmlspecialchars(asset_url('assets/js/academic-fields.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/onboarding.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(asset_url('assets/js/complete-profile.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<form id="prismLogoutForm" action="logout.php" method="post" hidden><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"></form>
</body>
</html>
