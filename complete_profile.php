<?php
require __DIR__.'/config.php';
$user=require_login(['student','adviser']);
if(onboarding_complete(db(),$user)) { header('Location: '.($user['role']==='student'?'student.php':'ierbprog.php')); exit; }
$student=$user['role']==='student';
header('Cache-Control: no-store');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complete your profile | PRISM</title><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script><link rel="icon" type="image/png" href="assets/images/prismicon.png"><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/onboarding.css'), ENT_QUOTES, 'UTF-8'); ?>"></head>
<body data-onboarding-role="<?= $user['role'] ?>"><main class="onboarding-card"><img src="assets/images/prismlogo1.png" alt="PRISM" width="180"><h1>Complete your <?= $student?'PRISM':'Adviser' ?> profile</h1><p>Your profile is Pending. Complete these details to use the workspace.</p><p class="invited-email">Invited email: <?= htmlspecialchars($user['email'],ENT_QUOTES,'UTF-8') ?></p>
<noscript><p role="alert">JavaScript is required to complete your profile. Enable it and reload this page; your details have not been submitted.</p></noscript>
<form id="completeProfileForm" method="post" action="complete_profile.php"><label for="onboardingName">Full name (required)</label><input id="onboardingName" name="name" autocomplete="name" maxlength="190" required>
<label for="onboardingId"><?= $student?'Student':'Employee' ?> ID (required)</label><input id="onboardingId" name="<?= $student?'studentId':'employeeId' ?>" maxlength="100" aria-describedby="identityPolicy" required><p id="identityPolicy">After completion, ID corrections require Admin review.</p>
<?php if($student): ?><fieldset id="onboardingAcademics"><legend>Academic information (required)</legend><div class="academic-fields-grid">
<label for="onboardingUnit">Academic unit<select id="onboardingUnit" data-academic="unit" required></select></label>
<label for="onboardingProgram">Program<select id="onboardingProgram" data-academic="program" required></select></label>
<label for="onboardingYear">Year level<select id="onboardingYear" data-academic="year" required></select></label>
<label for="onboardingAcademicYear">Academic year<select id="onboardingAcademicYear" data-academic="academic-year" required></select></label>
</div><p data-academic="summary" aria-live="polite"></p><p data-academic="legacy" hidden></p><button type="button" data-academic="reset" hidden>Keep existing values</button></fieldset>
<label for="onboardingResearch">Research title (optional)</label><input id="onboardingResearch" name="research" maxlength="255"><p>Adviser and research-group assignments are managed by staff.</p>
<?php else: ?><label for="onboardingDepartment">Academic unit / Department (required)</label><select id="onboardingDepartment" name="department" required><option value="">Choose an academic unit</option><?php foreach(academic_catalog()['units'] as $unit): ?><option><?= htmlspecialchars($unit['label'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select><?php endif; ?>
<p id="onboardingResult" role="alert" aria-live="polite" tabindex="-1">The profile form is loading. If it does not become ready, enable JavaScript and reload this page. Your details have not been submitted.</p><button type="submit" disabled>Complete profile</button></form><a href="logout.php">Sign out</a></main>
<script>window.PRISM_ACADEMIC_CATALOG=<?= json_encode(academic_catalog(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="<?php echo htmlspecialchars(asset_url('assets/js/academic-fields.js'), ENT_QUOTES, 'UTF-8'); ?>"></script><script src="<?php echo htmlspecialchars(asset_url('assets/js/onboarding.js'), ENT_QUOTES, 'UTF-8'); ?>"></script></body></html>
