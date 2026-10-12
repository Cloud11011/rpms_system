<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/help_support.php';
$user=require_login(['student','adviser']);
try { $profile=support_account(db(),$user); }
catch (NotificationLifecycleError $e) { http_response_code(403); exit('Help & Support is unavailable for this account.'); }
$pending=$profile['profile_completed_at']===null;
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Help &amp; Support | PRISM</title><link rel="icon" href="assets/images/prismicon.png">
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="stylesheet" href="<?= $escape(asset_url('assets/css/public-legal.css')) ?>">
<link rel="stylesheet" href="<?= $escape(asset_url('assets/css/help-support.css')) ?>">
<?php require __DIR__.'/includes/session_browser.php'; ?>
</head><body class="help-support-page"><div class="legal-shell support-shell">
<?php $legalBackUrl=$pending?'complete_profile.php':'index.php'; $legalBackLabel=$pending?'Back to profile setup':'Back to PRISM'; $legalShowLogout=false; require __DIR__.'/includes/legal_header.php'; ?>
<main><h1>Help &amp; Support</h1><p>Send a private request to the currently active RPMS Administrators. Requests and responses are available in PRISM only.</p>
<?php if ($pending): ?><p class="legal-notice">You can use Support now. Complete your profile before using the research workspace.</p><?php endif; ?>
<section class="legal-card" aria-labelledby="supportFormTitle"><h2 id="supportFormTitle">Send to RPMS</h2>
<form id="supportForm"><input id="supportToken" type="hidden" value="<?= $escape(support_token()) ?>">
<label for="supportCategory">Category</label><select id="supportCategory" required><option value="">Choose a category</option><?php foreach (support_categories() as $category): ?><option><?= $escape($category) ?></option><?php endforeach; ?></select>
<label for="supportSubject">Subject</label><input id="supportSubject" required maxlength="255">
<label for="supportMessage">Message</label><textarea id="supportMessage" required rows="5" maxlength="600" aria-describedby="supportMessageHint"></textarea><p id="supportMessageHint">Up to 600 characters. Do not include passwords or unnecessary personal information.</p>
<p id="supportResult" role="status" aria-live="polite"></p><button type="submit">Send to RPMS</button></form><noscript>Enable JavaScript to send a request and view Support history.</noscript></section>
<section class="legal-card" aria-labelledby="supportHistoryTitle"><h2 id="supportHistoryTitle">My Requests &amp; Support Responses</h2><p id="supportCount" aria-live="polite"></p><div id="supportHistory"></div><nav id="supportPagination" aria-label="Support history pages"></nav></section>
</main><footer><a href="privacy.php">Privacy Policy</a> · <a href="terms.php">Terms of Service</a></footer></div>
<dialog id="supportDetail" aria-labelledby="supportDetailTitle"><h2 id="supportDetailTitle"></h2><p id="supportDetailMeta"></p><p id="supportDetailBody"></p><form method="dialog"><button autofocus>Close details</button></form></dialog>
<script src="<?= $escape(asset_url('assets/js/legal-theme.js')) ?>"></script>
<script src="<?= $escape(asset_url('assets/js/prism-ui.js')) ?>"></script><script src="<?= $escape(asset_url('assets/js/notification-history.js')) ?>"></script><script src="<?= $escape(asset_url('assets/js/help-support.js')) ?>"></script>
</body></html>
