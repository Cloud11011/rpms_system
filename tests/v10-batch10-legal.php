<?php
/** Existing B6 governance on frozen native fixtures; no migration runner is loaded. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
$legalStart=$GLOBALS['nativeChecks'];
function b10_legal(int $uid,array $data): int { return legal_manage(db(),b10_actor($uid),$data); }
function b10_legal_refuse(callable $fn,int $status): void {
    try{$fn();}catch(LegalPolicyError $e){b10_expect($status,$e->status,'legal governance refusal');return;}throw new RuntimeException('Expected legal refusal.');
}
function b10_accept(int $uid): void { legal_accept(db(),b10_actor($uid),['privacy'=>true,'terms'=>true,'snapshot'=>legal_snapshot(legal_current(db()))]); }
function b10_candidate(string $policy,int $uid): int {
    $id=b10_legal($uid,['policy'=>$policy,'action'=>'create']);
    b10_legal($uid,['policy'=>$policy,'action'=>'save','versionId'=>$id,'title'=>'Fixture policy','content'=>"# Fixture policy\n\n<script>alert(1)</script>\n\nSafe literal source.",'summary'=>'Fixture governance revision']);
    b10_legal($uid,['policy'=>$policy,'action'=>'submit','versionId'=>$id]);return $id;
}
$old=legal_current($pdo);$snapshot=legal_snapshot($old);
$id=b10_candidate('privacy',1001);$approval=['policy'=>'privacy','action'=>'approve','versionId'=>$id,'password'=>'Batch10 fixture passphrase','reviewAcknowledged'=>true,'soleAcknowledged'=>true];
b10_legal_refuse(fn()=>b10_legal(1001,$approval),403);
b10_legal_refuse(fn()=>b10_legal(1006,array_replace($approval,['password'=>'wrong'])),422);
b10_legal_refuse(fn()=>b10_legal(1006,array_replace($approval,['reviewAcknowledged'=>false])),422);
b10_legal_refuse(fn()=>b10_legal(1001,['policy'=>'privacy','action'=>'save','versionId'=>$id,'title'=>'Mutated','content'=>'Changed','summary'=>'Changed']),409);
b10_legal(1006,$approval);b10_expect($id,(int)legal_current($pdo)['privacy']['id'],'different Admin publishes');
b10_expect($old['privacy']['content'],legal_rows($pdo,'SELECT * FROM legal_policy_versions WHERE id=?',[$old['privacy']['id']])[0]['content'],'old published content immutable');
b10_expect(['privacy'],array_keys(legal_outstanding($pdo,1003)),'exact changed version requires reacceptance');
b10_legal_refuse(fn()=>legal_accept($pdo,b10_actor(1003),['privacy'=>true,'terms'=>true,'snapshot'=>$snapshot]),409);
foreach([1001,1002,1003,1004,1005,1006] as $uid)b10_accept($uid);
b10_legal_refuse(fn()=>b10_legal(1006,$approval),409);
$rejected=b10_candidate('terms',1001);$before=legal_current($pdo);
b10_legal_refuse(fn()=>b10_legal(1001,['policy'=>'terms','action'=>'reject','versionId'=>$rejected,'reason'=>'Self']),403);
b10_legal_refuse(fn()=>b10_legal(1006,['policy'=>'terms','action'=>'reject','versionId'=>$rejected,'reason'=>'']),422);
b10_legal(1006,['policy'=>'terms','action'=>'reject','versionId'=>$rejected,'reason'=>'Clarify scope.']);b10_expect($before,legal_current($pdo),'rejection keeps published pointer');
b10_legal_refuse(fn()=>b10_legal(1001,['policy'=>'terms','action'=>'save','versionId'=>$rejected,'title'=>'Changed','content'=>'Changed']),409);
b10_expect([],legal_outstanding($pdo,1003),'rejection does not trigger acceptance');
$pdo->exec("UPDATE users SET status='Inactive' WHERE id=1006");$sole=b10_candidate('terms',1001);$soleApproval=array_replace($approval,['policy'=>'terms','versionId'=>$sole]);
b10_legal_refuse(fn()=>b10_legal(1001,array_replace($soleApproval,['soleAcknowledged'=>false])),422);
b10_legal(1001,$soleApproval);b10_expect('sole_admin',legal_rows($pdo,'SELECT approval_mode FROM legal_policy_approvals WHERE version_id=?',[$sole])[0]['approval_mode'],'sole Admin acknowledgment stored');
b10_expect(['terms'],array_keys(legal_outstanding($pdo,1003)),'sole publication needs reacceptance');
$pdo->exec("UPDATE users SET status='Active' WHERE id=1006");foreach([1001,1002,1003,1004,1005,1006] as $uid)b10_accept($uid);
lifecycle_compare_schema($pdo);echo 'PASS: '.($GLOBALS['nativeChecks']-$legalStart)." native B6 governance assertions: multi/sole Admin, draft, reject, publish, immutability and exact reacceptance.\n";
