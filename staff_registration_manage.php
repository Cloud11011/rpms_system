<?php
require __DIR__.'/config.php';
require_once __DIR__.'/includes/staff_registration.php';
$authUser=require_login('admin');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
$user_name=$authUser['full_name']; $user_email=$authUser['email'];
$user_role='RPMS Administrator'; $profile_img='assets/images/default-avatar.svg';
$prismCurrentPage='account.php';
$display=null; $message=''; $error='';
if (!isset($_SESSION['staff_mutation_token'])) $_SESSION['staff_mutation_token']=bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    require_post_same_origin(); require_session_generation(true);
    $submitted=$_POST['mutation_token']??null;
    if (!is_string($submitted) || !hash_equals($_SESSION['staff_mutation_token'],$submitted)) {
        http_response_code(409); $error='This form was already used or your page changed. Reload before continuing.';
    } else {
        // Consume before executing: POST refresh can never regenerate/re-display plaintext.
        $_SESSION['staff_mutation_token']=bin2hex(random_bytes(32));
        if (($_POST['action']??'')==='generate') $_SESSION['staff_ack_key']=bin2hex(random_bytes(32));
        try {
            $display=staff_manage(db(),$authUser,$_POST,prism_session_generation(),$_SESSION['staff_ack_key']??'');
            if (!isset($display['code'])) {
                unset($_SESSION['staff_ack_key']);
                $_SESSION['success']='Staff Registration Code '.(($_POST['action']??'')==='acknowledge'?'activated. Its 24-hour validity has started.':'invalidated.');
                header('Location: staff_registration_manage.php',true,303); exit;
            }
        } catch (StaffRegistrationError $e) { $error=$e->getMessage(); }
        catch (Throwable $e) { $error='Staff Registration is temporarily unavailable. Please try again later.'; }
    }
}
try { $staffState=$display['row']??staff_overview(db(),$authUser); }
catch (Throwable $e) { $staffState=null; $error='Staff Registration is temporarily unavailable. Please reload before continuing.'; }
$message=$_SESSION['success']??''; unset($_SESSION['success']);
$registrationLink=PRISM_PUBLIC_BASE_URL.'register.php';
?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff Registration | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" href="assets/images/prismicon.png">
<?php foreach (['dashboard','admin-management','prism-ui','dashboard-sidebar','prism-workspace','staff-registration','ceu-footer','batch11'] as $style): ?>
<link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/'.$style.'.css')) ?>"><?php endforeach; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="<?= legal_escape(asset_url('assets/js/dashboard-sidebar.js')) ?>" defer></script>
<script src="<?= legal_escape(asset_url('assets/js/staff-registration.js')) ?>" defer></script>
<?php prism_frontend_styles(); ?>
<link rel="stylesheet" href="<?= legal_escape(asset_url('assets/css/staff-registration.css')) ?>">
</head><body class="prism-workspace admin-shell"><div class="container"><aside class="sidebar prism-sidebar">
<?php require __DIR__.'/includes/prism-navigation.php'; ?></aside>
<main class="main-content management-page"><header class="topbar"><div><h1>Staff Registration</h1><p>Create a single-use code for one new administrator.</p></div><div class="top-controls"><button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
<p><a class="staff-back" href="account.php" aria-label="Back to Account &amp; Activity" title="Back to Account &amp; Activity"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></p>
<?php require __DIR__.'/includes/staff_registration_card.php'; ?>
<?php require __DIR__.'/includes/ceu_footer.php'; ?>
</main></div><script src="<?= legal_escape(asset_url('assets/js/prism-workspace.js')) ?>" defer></script>
<?php require __DIR__.'/includes/session_browser.php'; ?>
</body></html>
