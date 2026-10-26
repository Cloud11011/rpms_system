<?php
require __DIR__.'/bootstrap.php';
$pdo=db();
$pdo->exec("UPDATE students SET program_key='bsit',course='BS in Information Technology',research_group=CONCAT('AMT-BSIT-Y4-2627-G0',id)");
foreach (['privacy','terms'] as $policy) {
    $content='Synthetic '.$policy.' policy used solely for automated tests. No institutional approval is represented.';
    $pdo->prepare('INSERT INTO legal_policies(id) VALUES (?)')->execute([$policy]);
    $pdo->prepare("INSERT INTO legal_policy_versions(policy_id,version_number,title,content,state,content_sha256,creator_user_id,published_at)
        VALUES (?,1,?,?,'published',?,1,NOW())")->execute([$policy,'Synthetic '.$policy,$content,hash('sha256',$content)]);
    $version=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO legal_policy_approvals(version_id,reviewer_user_id,decision,approval_mode,reason)
        VALUES (?,1,'approved','bootstrap','Synthetic test-only approval; no institutional approval claim')")->execute([$version]);
    $pdo->prepare('UPDATE legal_policies SET current_version_id=? WHERE id=?')->execute([$version,$policy]);
    foreach ([1,2,3,4,5] as $userId) $pdo->prepare('INSERT INTO user_policy_acceptances(user_id,policy_version_id) VALUES (?,?)')->execute([$userId,$version]);
}
echo "Synthetic policy fixtures and valid academic groups prepared.\n";
