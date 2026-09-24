<?php
require __DIR__ . '/config.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
if ($action === 'save') {
    require_post_same_origin();
}

// Anyone logged in can READ the labels (they're shown throughout the UI),
// but only RPMS admin can rename what each stage means office-wide.
if ($action === 'list') {
    json_out(['ok' => true, 'labels' => stage_labels_map(), 'order' => STAGE_SEQUENCE]);
}

if ($action === 'save') {
    api_require_login('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $stageKey = trim((string)($data['stageKey'] ?? ''));
    $label = trim((string)($data['label'] ?? ''));

    if (!in_array($stageKey, STAGE_SEQUENCE, true)) {
        json_out(['ok' => false, 'message' => 'Unknown stage key.'], 422);
    }
    if ($label === '' || strlen($label) > 190) {
        json_out(['ok' => false, 'message' => 'Label must be 1-190 characters.'], 422);
    }

    $pdo->prepare('INSERT INTO stage_labels (stage_key, label) VALUES (:k, :l)
        ON DUPLICATE KEY UPDATE label = :l2')
        ->execute([':k' => $stageKey, ':l' => $label, ':l2' => $label]);

    log_activity($user['email'], 'stage_label_updated', "$stageKey => $label");
    json_out(['ok' => true, 'labels' => stage_labels_map()]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
