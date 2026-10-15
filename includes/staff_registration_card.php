<?php
// Presentation only. Controller supplies safe metadata and immediate, unpersisted response material.
$labels=['pending'=>'Pending Confirmation','acknowledged'=>'Active','expired'=>'Expired','consumed'=>'Consumed','invalidated'=>'Invalidated'];
$state=$staffState['state']??'';
if (!empty($staffEntryOnly)):
?>
<section class="management-card account-card staff-registration-entry" aria-labelledby="staffTitle">
<div class="management-card-head"><div><h2 id="staffTitle">Staff Registration</h2><p>Manage the single-use code for creating a new administrator.</p></div></div>
<p id="staffStatus" class="account-note">Current status: <strong><?= legal_escape($labels[$state]??'No Current Code') ?></strong></p>
<a class="prism-btn prism-btn-secondary" href="staff_registration_manage.php">Manage Staff Registration</a>
</section>
<?php
return;
endif;
$hiddenFields='<input type="hidden" name="prism_generation" value="'.legal_escape(prism_session_generation()).'">'
    .'<input type="hidden" name="mutation_token" value="'.legal_escape($_SESSION['staff_mutation_token']).'">'
    .'<input type="hidden" name="generation_id" value="'.legal_escape($staffState['generation_id']??'').'">';
?>
<section class="management-card staff-registration" aria-labelledby="staffTitle">
<div class="staff-head"><div><h2 id="staffTitle">Staff Registration</h2><p class="staff-sub">Invite one new administrator with a single-use code.</p></div><p id="staffStatus" class="staff-status <?= $state==='pending'?'is-pending':($state==='acknowledged'?'is-active':'') ?>"><span>Current status</span><strong><?= legal_escape($labels[$state]??'No Current Code') ?></strong></p></div>
<ol class="staff-steps" aria-label="Registration steps"><li class="<?= !$staffState?'is-current':'' ?>"><b>1</b><div><strong>Generate</strong><small>Create a single-use code</small></div></li><li class="<?= $state==='pending'?'is-current':'' ?>"><b>2</b><div><strong>Save and confirm</strong><small>Store it securely, then confirm</small></div></li><li class="<?= $state==='acknowledged'?'is-current':'' ?>"><b>3</b><div><strong>Share</strong><small>Send the link and code privately</small></div></li></ol>
<?php if ($error): ?><p role="alert" class="staff-error"><?= legal_escape($error) ?></p><?php endif; ?>
<?php if ($message): ?><p role="status"><?= legal_escape($message) ?></p><?php endif; ?>
<?php if ($staffState): ?><dl class="staff-metadata"><?php foreach (['created_at'=>'Created','acknowledged_at'=>'Confirmed saved','expires_at'=>'Valid until','consumed_at'=>'Consumed'] as $field=>$label): ?>
<?php if ($staffState[$field]): ?><div><dt><?= $label ?></dt><dd><?= legal_escape($staffState[$field]) ?> (Asia/Manila)</dd></div><?php endif; ?><?php endforeach; ?></dl><?php endif; ?>
<?php if (isset($display['code'])): ?>
<div id="staffOneTime" class="staff-code-panel"><p class="staff-notice staff-warning"><strong>This code will not be shown again. Save it securely before continuing.</strong></p><div class="staff-code-row">
<p id="staffPlaintext" class="staff-plaintext"><?= legal_escape($display['code']) ?></p>
<button type="button" class="prism-btn prism-btn-secondary" data-staff-copy="staffPlaintext">Copy Code</button></div>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="acknowledge"><input type="hidden" name="proof" value="<?= legal_escape($display['proof']) ?>">
<label class="staff-check"><input type="checkbox" name="confirmed" value="1" required> <span>I have securely saved this code.</span></label>
<p>Confirm within 10 minutes. Confirmation starts the 24-hour validity period.</p>
<button type="submit" class="prism-btn prism-btn-primary">Confirm Saved</button></form></div>
<?php elseif ($state==='pending'): ?><p>This code is not active and cannot be revealed again. Generate a replacement if the original confirmation page was lost.</p><?php endif; ?>
<section class="staff-share"><h3>Registration link</h3><p class="staff-hint">Share this link and your separately saved code through an appropriate private channel.</p><div class="staff-link-row"><a class="staff-link" id="staffRegistrationLink" href="<?= legal_escape($registrationLink) ?>"><?= legal_escape($registrationLink) ?></a><button type="button" class="prism-btn prism-btn-secondary" data-staff-copy="staffRegistrationLink">Copy Registration Link</button></div></section>
<p id="staffCopyResult" role="status" aria-live="polite"></p>
<?php if (!$error && !isset($display['code']) && $state!=='acknowledged'): ?>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="generate">
<button type="submit" class="prism-btn prism-btn-primary"><?= $state==='pending'?'Generate Replacement':'Generate Staff Registration Code' ?></button></form><?php endif; ?>
<?php if (!$error && in_array($state,['pending','acknowledged'],true)): ?>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="invalidate">
<label class="staff-check"><input type="checkbox" name="confirmed" value="1" required> <span>Invalidate this Staff Registration Code. It will no longer be usable for Admin registration.</span></label>
<button type="submit" class="prism-btn prism-btn-secondary">Invalidate Code</button></form><?php endif; ?>
</section>
