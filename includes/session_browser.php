<?php
// Called only after a protected page's existing authentication gate.
require_once __DIR__.'/../security.php';
if (!empty($_SESSION['user_id'])):
?>
<script>window.PRISM_SESSION_GENERATION = <?= json_encode(prism_session_generation(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= htmlspecialchars(asset_url('assets/js/session-browser.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
