<?php
// Refuse before bootstrap: GET has no session, cookie or audit side effects.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); exit;
}
require __DIR__ . '/config.php';
require_post_same_origin();
require_session_generation(true);

$user = current_user();
if ($user) {
    log_activity($user['email'], 'logout', '', (int)$user['id']);
}

$_SESSION = [];
setcookie('prism_generation', '', ['expires' => time() - 42000, 'path' => '/',
    'secure' => request_uses_https(), 'httponly' => false, 'samesite' => 'Lax']);
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => ($params['samesite'] ?? '') ?: 'Lax',
    ]);
}
session_destroy();

header('Location: login.php');
exit;
