<?php
// Public visitors may read current published policies without authentication or acceptance.
require_once __DIR__.'/../config.php';
$legalSources = ['privacy' => ['Privacy Policy', 'privacy.md'], 'terms' => ['Terms of Service', 'terms.md']];
if (!isset($legalSources[$legalPageKey ?? ''])) { http_response_code(404); exit; }
[$legalTitle, $legalFile] = $legalSources[$legalPageKey];
$legalVersion = legal_current(db())[$legalPageKey];
$legalTitle = $legalVersion['title'];
$legalText = $legalVersion['content'];
$legalPlaceholders = require __DIR__.'/legal_placeholders.php';
$legalEscape = static function (string $text) use ($legalPlaceholders): string {
    $text = strtr($text, array_filter($legalPlaceholders, static fn($v) => is_string($v)));
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $legalEscape($legalTitle) ?> | PRISM</title>
<link rel="canonical" href="<?= $legalEscape(prism_public_legal_url($legalPageKey)) ?>">
<link rel="icon" href="assets/images/prismicon.png">
<link rel="stylesheet" href="<?= $legalEscape(asset_url('assets/css/public-legal.css')) ?>">
<script>try { if (localStorage.getItem('prismTheme') === 'dark') document.documentElement.classList.add('dark-theme'); } catch (_) {}</script>
</head>
<body class="public-legal-page">
<a class="legal-skip" href="#legalContent">Skip to policy content</a>
<div class="legal-shell">
<?php $legalBackUrl=current_user()?'index.php':'login.php'; $legalBackLabel=$legalBackUrl==='index.php'?'Back to PRISM':'Back to Login'; require __DIR__.'/legal_header.php'; ?>
<main id="legalContent" tabindex="-1">
<h1><?= $legalEscape($legalTitle) ?></h1>
<p class="legal-effective">Version <?= (int)$legalVersion['version_number'] ?> · Published/effective: <time><?= $legalEscape($legalVersion['published_at']) ?></time> (Asia/Manila)</p>
<?php
echo legal_render($legalText);
?>
</main>
<footer><a href="privacy.php">Privacy Policy</a><span aria-hidden="true"> · </span><a href="terms.php">Terms of Service</a></footer>
</div>
<script src="<?= $legalEscape(asset_url('assets/js/legal-theme.js')) ?>"></script>
</body>
</html>
