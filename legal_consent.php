<?php
require __DIR__.'/config.php';
$user=require_login();
$error='';
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    require_post_same_origin(); require_session_generation(true);
    try {
        if (empty($_SESSION['legal_csrf']) || !is_string($_POST['csrf']??null) || !hash_equals($_SESSION['legal_csrf'],$_POST['csrf'])) throw new LegalPolicyError('This form expired. Reload and try again.',403);
        if (array_diff(array_keys($_POST),['privacy','terms','snapshot','csrf','prism_generation'])) throw new LegalPolicyError('Unexpected acceptance fields.');
        legal_accept(db(),$user,['privacy'=>($_POST['privacy']??'')==='1','terms'=>($_POST['terms']??'')==='1','snapshot'=>$_POST['snapshot']??null]);
        header('Location: index.php'); exit;
    } catch (LegalPolicyError $e) { http_response_code($e->status); $error=$e->getMessage(); }
}
$current=legal_current(db());
$outstanding=legal_outstanding(db(),(int)$user['id'],$current);
if (!$outstanding) { header('Location: index.php'); exit; }
$_SESSION['legal_csrf']??=bin2hex(random_bytes(32));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Privacy &amp; Terms | PRISM</title><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script><link rel="icon" href="assets/images/prismicon.png"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/style.css')) ?>"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/batch11-public.css')) ?>"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/onboarding.css')) ?>"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"><?php prism_frontend_styles(); ?><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/account-setup-ui.css')) ?>"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/legal-consent-ui.css')) ?>">
</head>
<body class="unified-login batch11-login account-setup-page consent-page">
<div class="background-overlay">
<section class="login-campus" aria-labelledby="consentCampusTitle">
<a class="login-campus-brand" href="index.php" aria-label="PRISM public home"><img src="assets/images/prismlogo1.png" alt="PRISM"></a>
<div class="login-campus-copy"><h2 id="consentCampusTitle">One last check</h2><p>Please review how PRISM handles your information and the rules for using it. You can read both before agreeing.</p></div>
</section>
<div class="login-form-column">
<main class="login-card setup-card consent-card">
<h1>Review Privacy &amp; Terms</h1>
<p class="setup-lead">Before continuing to PRISM, please review the current Privacy Policy and Terms of Service.</p>
<p class="setup-notice setup-email"><span>Signed in as <strong class="invited-email"><?= legal_escape($user['email']) ?></strong></span></p>
<?php if ($error): ?><p role="alert" class="setup-error"><?= legal_escape($error) ?></p><?php endif; ?>
<form method="post" id="consentForm">
<input type="hidden" name="csrf" value="<?= legal_escape($_SESSION['legal_csrf']) ?>">
<input type="hidden" name="prism_generation" value="<?= legal_escape(prism_session_generation()) ?>">
<input type="hidden" name="snapshot" value="<?= legal_snapshot($current) ?>">
<?php foreach ($current as $key=>$version): $need=isset($outstanding[$key]); $text=$key==='privacy'?'I have read and acknowledge the PRISM Privacy Policy.':'I agree to the PRISM Terms of Service.'; ?>
<div class="consent-item">
<a class="consent-doc" href="<?= $key ?>.php" target="_blank" rel="noopener">
<span class="consent-doc-icon" aria-hidden="true"><?php if($key==='privacy'): ?><svg viewBox="0 0 24 24" fill="none"><path d="M12 3 5.5 5.7v5.1c0 4.4 2.6 7.8 6.5 10.2 3.9-2.4 6.5-5.8 6.5-10.2V5.7L12 3Z"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none"><path d="M6 3.5h8l4 4V21H6V3.5Z"/><path d="M14 3.5V8h4"/></svg><?php endif; ?></span>
<span class="consent-doc-text"><span class="consent-doc-title"><?= $key==='privacy'?'Privacy Policy':'Terms of Service' ?></span><span class="consent-doc-ver">Version <?= (int)$version['version_number'] ?> &middot; <?= $need?'Tap to read':'Already agreed' ?></span></span>
<span class="consent-doc-open" aria-hidden="true"><i class="fa-solid fa-arrow-up-right-from-square"></i></span>
<span class="sr-only">(opens in a new tab)</span>
</a>
<?php if ($need): ?><label class="legal-checkbox consent-check"><input type="checkbox" name="<?= legal_escape($key) ?>" value="1" required><span><?= legal_escape($text) ?></span></label><?php endif; ?>
</div>
<?php endforeach; ?>
<div class="consent-actions">
<button class="consent-back" form="prismLogoutForm" aria-label="Log out" title="Log out" style="padding:0"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg></button>
<button type="submit" class="setup-btn" id="consentSubmit">Continue to PRISM</button>
</div>
</form>
<p class="setup-help">You can review either policy before agreeing. Normal PRISM access requires the outstanding acknowledgment/agreement.</p>
</main>
</div>
</div>
<form id="prismLogoutForm" action="logout.php" method="post" hidden><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"></form>
<?php require __DIR__.'/includes/session_browser.php'; ?></body></html>
