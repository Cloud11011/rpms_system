<?php
require __DIR__ . '/config.php';
require_once __DIR__.'/includes/staff_registration.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: register.php'); exit; }
require_post_same_origin();
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
if (current_user()) { header('Location: index.php'); exit; }

function back_with_error(string $message): void
{
    $_SESSION['error']=$message;
    header('Location: register.php',true,303); exit;
}

if (!consume_auth_attempt('admin_registration_ip',(string)($_SERVER['REMOTE_ADDR']??'unknown'),8,900)) {
    back_with_error('Too many registration attempts. Please try again later.');
}
try { $user=staff_register(db(),$_POST); }
catch (StaffRegistrationError $e) { back_with_error($e->getMessage()); }
catch (Throwable $e) { back_with_error('Registration is temporarily unavailable. Please try again later.'); }

// Fresh authentication boundary matching login_process.php and the B5B credential binding.
session_regenerate_id(true);
$_SESSION=[];
$_SESSION['login_generation']=bin2hex(random_bytes(16));
$_SESSION['user_id']=$user['id'];
prism_publish_session_generation();
$_SESSION['last_activity_at']=time();
$_SESSION['credential_fingerprint']=hash('sha256',$user['password_hash']);
$_SESSION['user_name']=$user['full_name'];
$_SESSION['user_email']=$user['email'];
$_SESSION['user_role']='RPMS Administrator';
$_SESSION['account_type']='admin';
$_SESSION['username']=$user['username'];
$_SESSION['ref_id']=$user['ref_id'];
$_SESSION['must_change_password']=0;
// No acceptance rows: canonical router sends the new Admin through current legal consent.
header('Location: index.php',true,303); exit;
