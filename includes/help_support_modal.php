<?php
// Shared modal for authenticated student/adviser layouts only. The standalone
// help_support.php page already renders these IDs and must not include this.
if (!isset($authUser) || !in_array($authUser['role'] ?? '', ['student', 'adviser'], true)) return;
if (!empty($prismSupportModalRendered)) return;
$prismSupportModalRendered=true;
require_once __DIR__.'/help_support.php';
$spEsc = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<dialog id="supportModal" class="sp-modal" aria-labelledby="spTitle">
  <div class="sp-card">
    <header class="sp-head">
      <div>
        <p class="sp-eyebrow">Support</p>
        <h2 id="spTitle">Help &amp; Support</h2>
        <p class="sp-sub">Send a private request to the RPMS Administrators. Replies appear here in PRISM only.</p>
      </div>
      <button type="button" class="sp-x" data-sp-close aria-label="Close Help and Support">&times;</button>
    </header>
    <div class="sp-tabs" role="tablist" aria-label="Help and Support sections">
      <button type="button" role="tab" id="spTabSend" class="sp-tab" aria-selected="true" aria-controls="spPaneSend" data-sp-tab="send">New request</button>
      <button type="button" role="tab" id="spTabHistory" class="sp-tab" aria-selected="false" aria-controls="spPaneHistory" data-sp-tab="history" tabindex="-1">My requests &amp; replies</button>
    </div>
    <section class="sp-pane" id="spPaneSend" role="tabpanel" aria-labelledby="spTabSend">
      <form id="supportForm">
        <input id="supportToken" type="hidden" value="<?= $spEsc(support_token()) ?>">
        <p class="sp-label" id="spCatLabel">What do you need help with?</p>
        <div class="sp-chips" role="radiogroup" aria-labelledby="spCatLabel" id="spChips">
<?php foreach (support_categories() as $category): ?>
          <button type="button" class="sp-chip" role="radio" aria-checked="false" data-value="<?= $spEsc($category) ?>"><span class="sp-dot"></span><?= $spEsc($category) ?></button>
<?php endforeach; ?>
        </div>
        <select id="supportCategory" class="sp-sr" tabindex="-1" aria-hidden="true">
          <option value="">Choose a category</option>
<?php foreach (support_categories() as $category): ?>
          <option value="<?= $spEsc($category) ?>"><?= $spEsc($category) ?></option>
<?php endforeach; ?>
        </select>
        <label class="sp-label" for="supportSubject">Subject</label>
        <input id="supportSubject" class="sp-field" required maxlength="255" placeholder="Short summary, e.g. Can't upload my protocol">
        <div class="sp-row"><label class="sp-label" for="supportMessage">Message</label><span class="sp-count" id="spCount">0 / 600</span></div>
        <textarea id="supportMessage" class="sp-field" required rows="5" maxlength="600" aria-describedby="supportMessageHint" placeholder="Describe the issue or question…"></textarea>
        <p id="supportMessageHint" class="sp-hint">Please don't include passwords or unnecessary personal information.</p>
        <p id="supportResult" class="sp-result" role="status" aria-live="polite"></p>
        <div class="sp-actions"><button type="submit" class="sp-btn sp-primary">Send to RPMS</button><button type="button" class="sp-btn" data-sp-close>Cancel</button></div>
      </form>
      <noscript>Enable JavaScript to send a request and view Support history.</noscript>
    </section>
    <section class="sp-pane" id="spPaneHistory" role="tabpanel" aria-labelledby="spTabHistory" hidden>
      <p id="supportCount" class="sp-hint" aria-live="polite"></p>
      <div id="supportHistory"></div>
      <nav id="supportPagination" aria-label="Support history pages"></nav>
    </section>
  </div>
</dialog>
<dialog id="supportDetail" class="sp-modal" aria-labelledby="supportDetailTitle">
  <div class="sp-card"><header class="sp-head"><div><p class="sp-eyebrow">Support details</p><h2 id="supportDetailTitle"></h2><p id="supportDetailMeta" class="sp-sub"></p></div><form method="dialog"><button class="sp-x" aria-label="Close details">&times;</button></form></header><p id="supportDetailBody" class="sp-sub"></p><div class="sp-actions"><form method="dialog"><button class="sp-btn" autofocus>Close details</button></form></div></div>
</dialog>
<?php if (($prismCurrentPage ?? '') !== 'admin_notifications.php'): ?>
<script src="<?= $spEsc(asset_url('assets/js/notification-history.js')) ?>"></script>
<?php endif; ?>
<script src="<?= $spEsc(asset_url('assets/js/help-support.js')) ?>"></script>
<script src="<?= $spEsc(asset_url('assets/js/help-support-modal.js')) ?>"></script>
