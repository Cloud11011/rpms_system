<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
$action=$_GET['action']??'';
$user=api_require_login(in_array($action,['me','complete'],true)?['student','adviser']:['admin','adviser']);
try {
    if ($action==='me') {
        $profile=onboarding_profile(db(),$user);
        if (!$profile) throw new AccountLifecycleConflict('Your profile link requires Admin review.');
        $assignment=null;
        if ($user['role']==='student' && research_onboarding_available(db())) {
            $q=db()->prepare('SELECT assignment_json,state FROM research_onboarding_assignments WHERE student_id=?'); $q->execute([$profile['id']]);
            if ($a=$q->fetch(PDO::FETCH_ASSOC)) $assignment=['state'=>$a['state'],'intent'=>json_decode($a['assignment_json'],true)];
        }
        json_out(['ok'=>true,'role'=>$user['role'],'email'=>$user['email'],'complete'=>$profile['profile_completed_at']!==null,'researchAssignment'=>$assignment]);
    }
    require_post_same_origin();
    if (!consume_auth_attempt('onboarding_write',(string)$user['id'],20,900)) json_out(['ok'=>false,'message'=>'Too many requests. Try again later.'],429);
    $data=json_body(true);
    $result=match($action) {
        'invite'=>onboarding_invite(db(),$user,$data),
        'resend'=>onboarding_resend(db(),$user,$data),
        'assign'=>onboarding_assign(db(),$user,$data),
        'complete'=>onboarding_finish(db(),$user,$data),
        default=>throw new AccountLifecycleBadRequest('Unknown invitation action.')
    };
    json_out($result);
} catch(Throwable $e) {
    $status=lifecycle_error_status($e);
    if($status>=500) log_api_error('onboarding','Request failed safely.');
    json_out(['ok'=>false,'message'=>$status<500?$e->getMessage():'The request could not be completed. Review the account and try again.'],$status);
}
