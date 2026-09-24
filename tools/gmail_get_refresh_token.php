<?php
/**
 * ONE-TIME helper: obtains the Gmail refresh token PRISM needs (GMAIL_REFRESH_TOKEN).
 *
 * Run it on your OWN computer only (XAMPP at http://localhost/...). It refuses any other
 * visitor, and you should NOT upload the tools/ folder to your live server.
 *
 * Prerequisite: GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET are set in config.local.php.
 */
require __DIR__ . '/../config.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This helper only runs on the local machine.');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirectUri = 'http://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '');
$page = function (string $body) {
    echo '<!doctype html><meta charset="utf-8"><title>Gmail token helper</title>'
       . '<body style="font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;line-height:1.5">' . $body;
    exit;
};

if (GMAIL_CLIENT_ID === '' || GMAIL_CLIENT_SECRET === '') {
    $page('<h2>Missing settings</h2><p>Set <code>GMAIL_CLIENT_ID</code> and <code>GMAIL_CLIENT_SECRET</code> in '
        . '<code>config.local.php</code>, then reload this page.</p>');
}

// Step 2: Google sent us back with a code (or an error).
if (isset($_GET['error'])) {
    $page('<h2>Google returned an error</h2><p><code>' . h((string)$_GET['error']) . '</code></p>'
        . '<p><a href="' . h($redirectUri) . '">Start over</a></p>');
}
if (isset($_GET['code'])) {
    if (!hash_equals((string)($_SESSION['gmail_oauth_state'] ?? ''), (string)($_GET['state'] ?? ''))) {
        $page('<h2>Security check failed</h2><p>The state value did not match. <a href="' . h($redirectUri) . '">Start over</a>.</p>');
    }
    unset($_SESSION['gmail_oauth_state']);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $_GET['code'],
            'client_id' => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        $page('<h2>Could not reach Google</h2><p><code>' . h($err) . '</code></p>'
            . '<p>If this mentions an SSL certificate, see the "SSL certificate problem" note in the setup guide.</p>');
    }
    $j = json_decode($resp, true) ?: [];
    if (empty($j['refresh_token'])) {
        $page('<h2>No refresh token returned</h2><pre>' . h(substr($resp, 0, 600)) . '</pre>'
            . '<p>Google only issues one on a fresh consent. Remove PRISM from '
            . '<a href="https://myaccount.google.com/permissions">your Google account permissions</a> and '
            . '<a href="' . h($redirectUri) . '">start over</a>.</p>');
    }
    $page('<h2>Success</h2><p>Add this line to <code>config.local.php</code>, then close this tab:</p>'
        . '<pre style="background:#f4f4f4;padding:12px;overflow:auto">define(\'GMAIL_REFRESH_TOKEN\', \''
        . h($j['refresh_token']) . '\');</pre>'
        . '<p><strong>Treat it like a password.</strong> It is not saved anywhere by this page.</p>');
}

// Step 1: send the user to Google's consent screen.
if (isset($_GET['start'])) {
    $_SESSION['gmail_oauth_state'] = bin2hex(random_bytes(16));
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GMAIL_CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/gmail.send',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $_SESSION['gmail_oauth_state'],
    ]));
    exit;
}

$page('<h2>Gmail refresh-token helper</h2>'
    . '<p>1. In Google Cloud Console, add this exact address under the OAuth client\'s '
    . '<em>Authorized redirect URIs</em>:</p><pre style="background:#f4f4f4;padding:12px">' . h($redirectUri) . '</pre>'
    . '<p>2. Then <a href="?start=1"><strong>Authorize with Google</strong></a> using the mailbox that should send PRISM email '
    . '(it must match <code>GMAIL_SENDER_EMAIL</code>).</p>');
