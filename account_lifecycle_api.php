<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
$user=api_require_login('admin');
if(($_SERVER['REQUEST_METHOD']??'')==='GET') {
    try {
        if (($_GET['action']??'')==='availability') json_out(['ok'=>true]+lifecycle_schema_availability(db()));
        if (($_GET['action']??'')==='cleanup_summary') json_out(retention_cleanup_summary(db(),$user));
        if (($_GET['action']??'')==='recovery_jobs') json_out(['ok'=>true,'jobs'=>purge_recovery_jobs(db())]);
        if (($_GET['action']??'')==='account_state') {
            $type=retention_type($_GET['accountType']??null); $id=retention_id($_GET['targetId']??null);
            $rows=retention_scope_rows(db(),$user,$type,[],[$id]);
            if (!$rows) throw new AccountLifecycleNotFound('Account record not found.');
            json_out(['ok'=>true,'lifecycle'=>retention_state($rows[0]),'assignedStudents'=>(int)($rows[0]['assigned_students']??0)]);
        }
        throw new AccountLifecycleValidation('Invalid lifecycle read action.');
    } catch(Throwable $error) {
        $status=lifecycle_error_status($error);
        json_out(['ok'=>false,'message'=>$status<500?$error->getMessage():'Lifecycle state could not be loaded.'],$status);
    }
}
require_post_same_origin();
try {
    $body=json_body(true);
    $result=match($body['action']??'') {
        'selection'=>retention_selection(db(),$user,$body),
        'bulk_preview'=>retention_bulk_preview(db(),$user,$body),
        'bulk_execute'=>retention_bulk_execute(db(),$user,$body),
        default=>lifecycle_execute(db(),$user,$body),
    };
    if (!empty($result['logout'])) {
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $params=session_get_cookie_params();
            setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],
                'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']?:'Lax']);
        }
        session_destroy();
    }
    json_out($result,!empty($result['committed']) && empty($result['complete'])?202:200);
} catch (Throwable $error) {
    $status=lifecycle_error_status($error);
    if($status>=500) error_log('PRISM account lifecycle failed ('.get_class($error).').');
    json_out(['ok'=>false,'message'=>$status<500?$error->getMessage():
        ($status===503?'Account lifecycle is temporarily unavailable. Please try again later.':'Account lifecycle could not be completed. Review account and recovery status before retrying.')],$status);
}
