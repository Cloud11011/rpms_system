<?php
// Presentation only; the caller owns public access or authenticated legal/support gates.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404); exit; }
$headerEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<header class="legal-header">
<a class="legal-brand" href="index.php" aria-label="PRISM"><img src="assets/images/prismlogo1.png" alt="PRISM — Centro Escolar University"></a>
<a class="legal-back" href="<?= $headerEscape($legalBackUrl??'login.php') ?>" aria-label="<?= $headerEscape($legalBackLabel??'Back to Login') ?>" title="<?= $headerEscape($legalBackLabel??'Back to Login') ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg></a>
<?php if (!empty($legalShowLogout)): ?><form method="post" action="logout.php" class="prism-logout-form"><input type="hidden" name="prism_generation" value="<?= htmlspecialchars(prism_session_generation(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"  class="legal-back" >Log out</button></form><?php endif; ?>
<button class="legal-theme" type="button" id="legalTheme" aria-label="Switch to dark mode" title="Switch to dark mode"><svg class="legal-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.5A8.8 8.8 0 0 1 8.5 4 9 9 0 1 0 20 15.5Z"/></svg><svg class="legal-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg></button>
</header>
