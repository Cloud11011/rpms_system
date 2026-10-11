<?php
require __DIR__.'/config.php';
$authUser=require_login('admin');
$error='';
$_SESSION['legal_csrf']??=bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    require_post_same_origin(); require_session_generation(true);
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['legal_csrf'],$_POST['csrf'])) throw new LegalPolicyError('This form expired. Reload and try again.',403);
        $data=$_POST; unset($data['csrf'],$data['prism_generation']);
        $data['reviewAcknowledged']=($data['reviewAcknowledged']??'')==='1';
        $data['soleAcknowledged']=($data['soleAcknowledged']??'')==='1';
        $id=legal_manage(db(),$authUser,$data);
        $_SESSION['legal_notice']='Policy action completed. Publication requires users to review the new version.';
        header('Location: admin_legal_policies.php?version='.$id); exit;
    } catch (LegalPolicyError $e) { http_response_code($e->status); $error=$e->getMessage(); }
}
$current=legal_current(db());
$history=legal_rows(db(),'SELECT v.*,a.decision,a.approval_mode,a.reason AS review_reason,a.reviewed_at FROM legal_policy_versions v LEFT JOIN legal_policy_approvals a ON a.version_id=v.id ORDER BY v.policy_id,v.version_number DESC');
$selected=null; foreach ($history as $version) if ((string)$version['id']===($_GET['version']??'')) { legal_verify_content($version); $selected=$version; }
$activeAdmins=count(array_filter(legal_rows(db(),"SELECT * FROM users WHERE role='admin'"),'legal_admin_eligible'));
$notice=$_SESSION['legal_notice']??''; unset($_SESSION['legal_notice']);
function legal_form_fields(string $policy, string $action, ?int $version=null): void {
    echo '<input type="hidden" name="csrf" value="'.legal_escape($_SESSION['legal_csrf']).'"><input type="hidden" name="prism_generation" value="'.legal_escape(prism_session_generation()).'"><input type="hidden" name="policy" value="'.legal_escape($policy).'"><input type="hidden" name="action" value="'.legal_escape($action).'">';
    if ($version!==null) echo '<input type="hidden" name="versionId" value="'.$version.'">';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Legal Policies | PRISM</title><link rel="icon" href="assets/images/prismicon.png"><link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/public-legal.css')) ?>"><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script></head>
<body><a class="legal-skip" href="#legalManagement">Skip to legal management</a><div class="legal-shell legal-management"><header class="legal-header"><a class="legal-brand" href="dashboard.php"><img src="assets/images/prismlogo1.png" alt="PRISM"></a><a href="dashboard.php">Dashboard</a><a href="admin_legal_policies.php">Legal Policies</a><a href="logout.php">Log out</a><button id="legalTheme" type="button">Theme</button></header>
<main id="legalManagement" tabindex="-1"><h1>Legal Policies</h1><p>Published versions remain immutable. One different active Admin may approve a pending candidate. A sole eligible Admin may publish with the additional acknowledgment.</p>
<?php if ($error): ?><p class="legal-notice" role="alert"><?= legal_escape($error) ?></p><?php endif; ?>
<?php if ($notice): ?><p class="legal-notice" role="status"><?= legal_escape($notice) ?></p><?php endif; ?>
<?php foreach ($current as $policy=>$version): $open=array_values(array_filter($history,static fn($v)=>$v['policy_id']===$policy && in_array($v['state'],['draft','pending'],true))); ?>
<section class="legal-card"><h2><?= legal_escape($version['title']) ?></h2><p>Current published version <?= (int)$version['version_number'] ?> · <?= legal_escape($version['published_at']) ?></p><p><?= legal_escape($version['change_summary']) ?></p><p><a href="<?= $policy ?>.php" target="_blank" rel="noopener">View Current</a> · <a href="#history-<?= $policy ?>">Version History</a></p>
<?php if ($open): ?><p>Open candidate: version <?= (int)$open[0]['version_number'] ?> — <?= legal_escape($open[0]['state']) ?>. <a href="?version=<?= (int)$open[0]['id'] ?>">View Draft / Pending Review</a></p><?php else: ?><form method="post"><?php legal_form_fields($policy,'create'); ?><button type="submit">Create Draft</button></form><?php endif; ?></section>
<?php endforeach; ?>
<?php if ($selected): $policy=$selected['policy_id']; $id=(int)$selected['id']; $owned=(int)$selected['creator_user_id']===(int)$authUser['id']; ?>
<section class="legal-card" id="versionDetails"><h2><?= legal_escape($selected['title']) ?> — version <?= (int)$selected['version_number'] ?></h2><p>Status: <?= legal_escape($selected['state']) ?>. <?= legal_escape($selected['change_summary']) ?></p>
<?php if ($selected['state']==='draft' && ($owned || $selected['creator_user_id']===null)): ?>
<form method="post"><?php legal_form_fields($policy,'save',$id); ?><label for="policyTitle">Title (190 characters maximum)</label><input id="policyTitle" name="title" maxlength="190" value="<?= legal_escape($selected['title']) ?>" required><label for="policySummary">Change summary (required before submission, 500 characters maximum)</label><input id="policySummary" name="summary" maxlength="500" value="<?= legal_escape($selected['change_summary']) ?>"><label for="policyContent">Policy source (262,144 characters maximum)</label><p id="sourceHelp">Use # for headings, ## for sections and - for list items. Markup is displayed as plain text. Save to update the preview below.</p><textarea id="policyContent" name="content" rows="24" maxlength="262144" aria-describedby="sourceHelp" required><?= legal_escape($selected['content']) ?></textarea><button type="submit">Save Draft &amp; Preview</button></form>
<form method="post"><?php legal_form_fields($policy,'submit',$id); ?><p>Submission freezes this text for review. A change summary is required.</p><button type="submit">Submit for Approval</button></form>
<?php elseif ($selected['state']==='pending'): ?>
<?php if ($activeAdmins===1 || !$owned): ?>
<?php if ($activeAdmins===1): ?><p class="legal-notice">You are currently the only active RPMS Administrator. This policy may be published without approval from another administrator.</p><?php endif; ?>
<form method="post"><?php legal_form_fields($policy,'approve',$id); ?><label for="reviewPassword">Your current password</label><input id="reviewPassword" type="password" name="password" autocomplete="current-password" maxlength="200" required><label class="legal-checkbox"><input type="checkbox" name="reviewAcknowledged" value="1" required><span>I have reviewed the proposed policy and understand that publishing it may require PRISM users to acknowledge or agree to the new version.</span></label>
<?php if ($activeAdmins===1): ?><label class="legal-checkbox"><input type="checkbox" name="soleAcknowledged" value="1" required><span>No second eligible Admin is available. I acknowledge that publication replaces the current policy and users may need to acknowledge or agree to the new version.</span></label><?php endif; ?><button type="submit">Approve &amp; Publish</button></form>
<?php else: ?><p>A different active Admin must approve your candidate.</p><?php endif; ?>
<?php if (!$owned): ?><form method="post"><?php legal_form_fields($policy,'reject',$id); ?><label for="reviewReason">Rejection reason (required)</label><textarea id="reviewReason" name="reason" rows="3" maxlength="500" required></textarea><button type="submit">Reject Candidate</button></form><?php endif; ?>
<?php endif; ?>
<?php if ($selected['decision']): ?><p>Review decision: <?= legal_escape($selected['decision']) ?> · <?= legal_escape($selected['approval_mode']) ?> · <?= legal_escape($selected['reviewed_at']) ?></p><?php if ($selected['review_reason']): ?><p><?= legal_escape($selected['review_reason']) ?></p><?php endif; ?><?php endif; ?>
<h2><?= $selected['state']==='draft'?'Saved draft preview':'Version text' ?></h2><article class="legal-policy-text"><?= legal_render($selected['content']) ?></article></section>
<?php endif; ?>
<?php foreach (['privacy'=>'Privacy Policy','terms'=>'Terms of Service'] as $policy=>$label): ?><section id="history-<?= $policy ?>"><h2><?= $label ?> history</h2><ul class="legal-history"><?php foreach ($history as $v): if ($v['policy_id']!==$policy) continue; ?><li><a href="?version=<?= (int)$v['id'] ?>">Version <?= (int)$v['version_number'] ?></a> — <?= (int)$current[$policy]['id']===(int)$v['id']?'current published':legal_escape($v['state']) ?><?= $v['published_at']?' · '.legal_escape($v['published_at']):'' ?><p><?= legal_escape($v['change_summary']) ?></p></li><?php endforeach; ?></ul></section><?php endforeach; ?>
</main></div><?php require __DIR__.'/includes/session_browser.php'; ?><script>document.getElementById('legalTheme').addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark-theme');try{localStorage.setItem('prismTheme',dark?'dark':'light')}catch(_){}});</script></body></html>
