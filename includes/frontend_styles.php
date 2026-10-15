<?php
/** Presentation assets only. Call once, after each page's legacy styles, inside <head>. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/assets.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php foreach (['prism-tokens', 'prism-components', 'prism-calendar'] as $prismSharedSheet): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/' . $prismSharedSheet . '.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; unset($prismSharedSheet); ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/help-support-modal.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= htmlspecialchars(asset_url('assets/js/prism-calendar-presentation.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
