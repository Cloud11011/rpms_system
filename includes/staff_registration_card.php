<?php
// Presentation only. Controller supplies safe metadata and immediate, unpersisted response material.
$labels=['pending'=>'Pending Confirmation','acknowledged'=>'Active','expired'=>'Expired','consumed'=>'Consumed','invalidated'=>'Invalidated'];
$state=$staffState['state']??'';
$hiddenFields='<input type="hidden" name="prism_generation" value="'.legal_escape(prism_session_generation()).'">'
    .'<input type="hidden" name="mutation_token" value="'.legal_escape($_SESSION['staff_mutation_token']).'">'
    .'<input type="hidden" name="generation_id" value="'.legal_escape($staffState['generation_id']??'').'">';
?>
<section class="management-card staff-registration" aria-labelledby="staffTitle">
<h2 id="staffTitle">Staff Registration</h2>
<?php if ($error): ?><p role="alert" class="staff-error"><?= legal_escape($error) ?></p><?php endif; ?>
<?php if ($message): ?><p role="status"><?= legal_escape($message) ?></p><?php endif; ?>
<p id="staffStatus">Current status: <strong><?= legal_escape($labels[$state]??'No Current Code') ?></strong></p>
<?php if ($staffState): ?><dl class="staff-metadata"><?php foreach (['created_at'=>'Created','acknowledged_at'=>'Confirmed saved','expires_at'=>'Valid until','consumed_at'=>'Consumed'] as $field=>$label): ?>
<?php if ($staffState[$field]): ?><div><dt><?= $label ?></dt><dd><?= legal_escape($staffState[$field]) ?> (Asia/Manila)</dd></div><?php endif; ?><?php endforeach; ?></dl><?php endif; ?>
<?php if (isset($display['code'])): ?>
<div id="staffOneTime"><p><strong>This code will not be shown again. Save it securely before continuing.</strong></p>
<p id="staffPlaintext" class="staff-plaintext"><?= legal_escape($display['code']) ?></p>
<button type="button" class="prism-btn prism-btn-secondary" data-staff-copy="staffPlaintext">Copy Code</button>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="acknowledge"><input type="hidden" name="proof" value="<?= legal_escape($display['proof']) ?>">
<label class="staff-check"><input type="checkbox" name="confirmed" value="1" required> I have securely saved this code.</label>
<p>Confirm within 10 minutes. Confirmation starts the 24-hour validity period.</p>
<button type="submit" class="prism-btn prism-btn-primary">Confirm Saved</button></form></div>
<?php elseif ($state==='pending'): ?><p>This code is not active and cannot be revealed again. Generate a replacement if the original confirmation page was lost.</p><?php endif; ?>
<p>Share the registration link and your separately saved code through an appropriate private channel.</p>
<p><a id="staffRegistrationLink" href="<?= legal_escape($registrationLink) ?>"><?= legal_escape($registrationLink) ?></a></p>
<button type="button" class="prism-btn prism-btn-secondary" data-staff-copy="staffRegistrationLink">Copy Registration Link</button>
<p id="staffCopyResult" role="status" aria-live="polite"></p>
<?php if (!$error && !isset($display['code']) && $state!=='acknowledged'): ?>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="generate">
<button type="submit" class="prism-btn prism-btn-primary"><?= $state==='pending'?'Generate Replacement':'Generate Staff Registration Code' ?></button></form><?php endif; ?>
<?php if (!$error && in_array($state,['pending','acknowledged'],true)): ?>
<form action="staff_registration_manage.php" method="post"><?= $hiddenFields ?><input type="hidden" name="action" value="invalidate">
<label class="staff-check"><input type="checkbox" name="confirmed" value="1" required> Invalidate this Staff Registration Code. It will no longer be usable for Admin registration.</label>
<button type="submit" class="prism-btn prism-btn-secondary">Invalidate Code</button></form><?php endif; ?>
</section>
