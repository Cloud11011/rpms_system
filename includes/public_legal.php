<?php
// Fixed routes select trusted static text. No session, configuration, database, or auth bootstrap.
require_once __DIR__.'/../security.php';
require_once __DIR__.'/assets.php';
install_application_security();
$legalSources = ['privacy' => ['Privacy Policy', 'privacy.md'], 'terms' => ['Terms of Service', 'terms.md']];
if (!isset($legalSources[$legalPageKey ?? ''])) { http_response_code(404); exit; }
[$legalTitle, $legalFile] = $legalSources[$legalPageKey];
$legalText = file_get_contents(__DIR__.'/legal/'.$legalFile);
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
<link rel="icon" href="assets/images/prismicon.png">
<link rel="stylesheet" href="<?= $legalEscape(asset_url('assets/css/public-legal.css')) ?>">
<script>try { if (localStorage.getItem('prismTheme') === 'dark') document.documentElement.classList.add('dark-theme'); } catch (_) {}</script>
</head>
<body>
<a class="legal-skip" href="#legalContent">Skip to policy content</a>
<div class="legal-shell">
<header class="legal-header">
<a class="legal-brand" href="index.php"><img src="assets/images/prismlogo1.png" alt="PRISM"><span>PRISM</span></a>
<a href="login.php">Back to Login / PRISM</a>
<button type="button" id="legalTheme" aria-label="Switch theme">Theme</button>
</header>
<main id="legalContent" tabindex="-1">
<?php
$legalListOpen = false;
foreach (explode("\n", $legalText) as $line) {
    $line = trim($line);
    if (str_starts_with($line, '- ')) {
        if (!$legalListOpen) { echo '<ul>'; $legalListOpen = true; }
        echo '<li>'.$legalEscape(substr($line, 2)).'</li>';
        continue;
    }
    if ($legalListOpen) { echo '</ul>'; $legalListOpen = false; }
    if ($line === '') continue;
    if (str_starts_with($line, '# ')) echo '<h1>'.$legalEscape(substr($line, 2)).'</h1>';
    elseif (str_starts_with($line, '## ')) echo '<h2>'.$legalEscape(substr($line, 3)).'</h2>';
    else echo '<p'.(str_starts_with($line, 'Effective Date:') ? ' class="legal-effective"' : '').'>'.$legalEscape($line).'</p>';
}
if ($legalListOpen) echo '</ul>';
?>
</main>
<footer><a href="privacy.php">Privacy Policy</a><span aria-hidden="true"> · </span><a href="terms.php">Terms of Service</a></footer>
</div>
<script>document.getElementById('legalTheme').addEventListener('click', () => { const dark = document.documentElement.classList.toggle('dark-theme'); try { localStorage.setItem('prismTheme', dark ? 'dark' : 'light'); } catch (_) {} });</script>
</body>
</html>
