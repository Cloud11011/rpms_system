<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser', 'student']);

$documentId = is_string($_GET['id'] ?? null) ? trim($_GET['id']) : '';
if ($documentId === '' || strlen($documentId) > 128) {
    http_response_code(400);
    exit('Invalid document reference.');
}
$pdo = db();
$query = 'SELECT d.mime FROM documents d LEFT JOIN students s ON s.id=d.student_id WHERE d.id=:id';
$params = [':id' => $documentId];
if ($user['role'] === 'student') {
    $query .= ' AND LOWER(s.email)=LOWER(:email)'; $params[':email'] = $user['email'];
} elseif ($user['role'] === 'adviser') {
    $query .= ' AND s.archived_at IS NULL AND s.adviser_id IN (SELECT id FROM advisers WHERE email=:email)';
    $params[':email'] = $user['email'];
}
$statement = $pdo->prepare($query); $statement->execute($params); $mime = $statement->fetchColumn();
if ($mime === false) { http_response_code(404); exit('Document unavailable.'); }
$fileUrl = 'documents_api.php?action=file&id=' . rawurlencode($documentId);
if (!in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
    header('Location: ' . $fileUrl . '&download=1'); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <title>Document Preview | PRISM</title>
    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <style>
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; }
        body { overflow: hidden; background: #e9ebf2; }
        html.dark-theme body { background: #10111e; color-scheme: dark; }
        iframe { display: block; width: 100%; height: 100%; border: 0; background: inherit; }
    </style>
</head>
<body>
    <iframe title="Submitted document" src="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
</body>
</html>
