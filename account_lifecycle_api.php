<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/account_lifecycle.php';
$user=api_require_login('admin');
require_post_same_origin();
try {
    $result=lifecycle_execute(db(),$user,json_body(true));
    if ($result['logout']) {
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $params=session_get_cookie_params();
            setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],
                'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']?:'Lax']);
        }
        session_destroy();
    }
    json_out($result);
} catch (Throwable $error) {
    $status=lifecycle_error_status($error);
    if($status>=500) error_log('PRISM account lifecycle failed ('.get_class($error).').');
    json_out(['ok'=>false,'message'=>$status<500?$error->getMessage():
        ($status===503?'Account lifecycle is temporarily unavailable. Please try again later.':'Account lifecycle could not be completed. No deletion was committed.')],$status);
}
