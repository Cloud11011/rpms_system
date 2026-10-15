<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/workflow.php';
$user = api_require_login(['admin', 'adviser', 'student']);
$pdo = db();
$action = $_GET['action'] ?? 'me';
if (in_array($action, ['update_profile', 'change_password'], true)) {
    require_post_same_origin();
    require_session_generation(true);
}
$data = json_body();

if ($action === 'me') {
    json_out(['ok' => true, 'user' => [
        'name' => $user['full_name'], 'email' => $user['email'], 'role' => ucfirst($user['role']),
        'refId' => $user['ref_id'],
    ]]);
}

if ($action === 'update_profile') {
    if (!is_string($data['name']??null)) json_out(['ok'=>false,'message'=>'Provide a valid profile name.'],422);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['ok' => false, 'message' => 'Name cannot be empty.'], 422);
    }
    if (mb_strlen($name) > 190) {
        json_out(['ok' => false, 'message' => 'Name must be 190 characters or fewer.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $liveActor=current_workflow_actor($pdo,$user);
        if(in_array($user['role'],['student','adviser'],true)) {
            $identityTable=$user['role']==='student'?'students':'advisers';
            $identityField=$user['role']==='student'?'student_id':'employee_id';
            $identityRows=lifecycle_rows($pdo,"SELECT * FROM $identityTable WHERE user_id=:uid OR email=:email OR $identityField=:ref ORDER BY id FOR UPDATE",
                [':uid'=>$user['id'],':email'=>$user['email'],':ref'=>$user['ref_id']]);
            if(count($identityRows)!==1) throw new AccountLifecycleConflict('Profile ownership requires Admin review.');
            $identityRecord=$identityRows[0];
            $identityBefore=lifecycle_identity_capture($pdo,$identityRecord,$user['role']);
            if($identityBefore['login_id']!==(int)$user['id']) throw new AccountLifecycleConflict('Profile ownership requires Admin review.');
        }
        $pdo->prepare('UPDATE users SET full_name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $user['id']]);
        if(isset($identityRecord)) {
            $pdo->prepare("UPDATE $identityTable SET full_name=:name WHERE id=:id")->execute([':name'=>$name,':id'=>$identityRecord['id']]);
            $identityRecord['full_name']=$name;
            lifecycle_identity_persist($pdo,$user,$user['role'],$identityBefore,lifecycle_identity_capture($pdo,$identityRecord,$user['role']));
        }
        document_workflow_audit($pdo,$liveActor,'profile_updated',['entity_type'=>'user','entity_id'=>$user['id'],'student_id'=>null,'before'=>$liveActor['full_name'],'after'=>$name]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden) json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        json_out(['ok' => false, 'message' => 'Profile could not be updated. Please try again.'], 500);
    }
    $_SESSION['user_name'] = $name;
    json_out(['ok' => true]);
}

if ($action === 'change_password') {
    if (!is_string($data['currentPassword']??null) || !is_string($data['newPassword']??null)) json_out(['ok'=>false,'message'=>'Provide valid password fields.'],422);
    $current = (string)($data['currentPassword'] ?? '');
    $new = (string)($data['newPassword'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        json_out(['ok' => false, 'message' => 'Your current password is incorrect.'], 422);
    }
    if (!new_password_is_valid($new)) {
        json_out(['ok' => false, 'message' => 'New password must contain at least 12 characters and at most 200 bytes; a passphrase is welcome.'], 422);
    }
    if (strlen($new) > 200) {
        json_out(['ok' => false, 'message' => 'New password is too long.'], 422);
    }
    if ($new === $current || (!(password_get_info($user['password_hash'])['algoName'] === 'bcrypt' && strlen($current) >= 72)
        && password_verify($new, $user['password_hash']))) {
        json_out(['ok' => false, 'message' => 'Choose a new password that is different from your current password.'], 422);
    }
    $newHash = prism_password_hash($new);
    $pdo->beginTransaction();
    try {
        $liveActor=current_workflow_actor($pdo,$user,true);
        $update = $pdo->prepare('UPDATE users SET password_hash = :p, must_change_password = 0
            WHERE id = :id AND password_hash = :previous');
        $update->execute([':p' => $newHash, ':id' => $user['id'], ':previous' => $user['password_hash']]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            json_out(['ok' => false, 'message' => 'Your password changed in another session. Please sign in again.'], 409);
        }
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = :id AND used = 0')
            ->execute([':id' => $user['id']]);
        document_workflow_audit($pdo,$liveActor,'password_changed',['entity_type'=>'user','entity_id'=>$user['id'],'student_id'=>null,'details'=>'Account password changed.']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleForbidden) json_out(['ok'=>false,'message'=>$e->getMessage()],lifecycle_error_status($e));
        json_out(['ok' => false, 'message' => 'Password could not be changed. Please try again.'], 500);
    }
    if (!session_regenerate_id(true)) {
        $_SESSION=[]; session_destroy();
        json_out(['ok'=>false,'message'=>'Your password changed. Please sign in again.'],401);
    }
    $_SESSION['credential_fingerprint'] = hash('sha256', $newHash);
    $_SESSION['must_change_password'] = 0;
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
