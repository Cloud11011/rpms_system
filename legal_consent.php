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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Privacy &amp; Terms | PRISM</title><link rel="icon" href="assets/images/prismicon.png"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/public-legal.css')) ?>"><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script></head>
<body><div class="legal-shell"><header class="legal-header"><a class="legal-brand" href="index.php"><img src="assets/images/prismlogo1.png" alt="PRISM"></a><a href="logout.php">Log out</a></header>
<main><h1>Review Privacy &amp; Terms</h1><p>Before continuing to PRISM, please review the current Privacy Policy and Terms of Service.</p><p>Signed in as <?= legal_escape($user['email']) ?>.</p>
<?php if ($error): ?><p role="alert" class="legal-notice"><?= legal_escape($error) ?></p><?php endif; ?>
<ul><?php foreach ($current as $key=>$version): ?><li><a href="<?= $key ?>.php" target="_blank" rel="noopener">View <?= legal_escape($version['title']) ?> (version <?= (int)$version['version_number'] ?>)</a><?= isset($outstanding[$key])?' — review required':' — already acknowledged/agreed' ?></li><?php endforeach; ?></ul>
<form method="post"><input type="hidden" name="csrf" value="<?= legal_escape($_SESSION['legal_csrf']) ?>"><input type="hidden" name="prism_generation" value="<?= legal_escape(prism_session_generation()) ?>"><input type="hidden" name="snapshot" value="<?= legal_snapshot($current) ?>">
<?php if (isset($outstanding['privacy'])): ?><label class="legal-checkbox"><input type="checkbox" name="privacy" value="1" required> <span>I have read and acknowledge the PRISM Privacy Policy.</span></label><?php endif; ?>
<?php if (isset($outstanding['terms'])): ?><label class="legal-checkbox"><input type="checkbox" name="terms" value="1" required> <span>I agree to the PRISM Terms of Service.</span></label><?php endif; ?>
<button type="submit">Continue to PRISM</button></form><p>You can read both policies or log out without agreeing. Normal PRISM access requires the outstanding acknowledgment/agreement.</p></main></div>
<?php require __DIR__.'/includes/session_browser.php'; ?></body></html>
