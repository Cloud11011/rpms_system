<?php
/** Admin self-lifecycle and controlled Student/Adviser retention dispatch. */
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/account_lifecycle_schema.php';
require_once __DIR__.'/account_retention.php';
require_once __DIR__.'/account_retention_bulk.php';

/** Must succeed on this transaction/connection; never use best-effort audit_log(). */
function lifecycle_audit(PDO $pdo, array $actor, string $action, string $type, int $id, string $identifier): void
{
    $q=$pdo->prepare('INSERT INTO activity_logs (user_email,action,details,actor_name,actor_role,entity_type,entity_id,before_value,after_value,created_at)
        VALUES (:email,:action,:details,:name,:role,:type,:id,:before,:after,NOW())');
    $q->execute([':email'=>$actor['email'],':action'=>$action,':details'=>'Actor user ID '.$actor['id'].'; account '.$identifier,
        ':name'=>$actor['full_name'],':role'=>$actor['role'],':type'=>$type,':id'=>(string)$id,
        ':before'=>$identifier,':after'=>$action==='admin_self_archived'?'Inactive':'Permanently deleted']);
    if ($q->rowCount() !== 1) throw new RuntimeException('Lifecycle audit was not persisted.');
}

function lifecycle_block_if(PDO $pdo, string $sql, array $params, string $reason): void
{
    if (lifecycle_rows($pdo,$sql,$params)) throw new AccountLifecycleConflict($reason.' Retain the account using Archive.');
}

/** Legacy login ownership is matched using every existing identity mechanism, then validated. */
function lifecycle_login(PDO $pdo, array $record, string $type, string $identifier): ?array
{
    $login=lifecycle_resolve_login($pdo,$record,$type,true);
    if (!$login) return null;
    if ($login['role']!==$type || $login['email']!==$record['email'] || $login['username']!==$identifier
        || (string)$login['ref_id']!==$identifier || (!empty($record['user_id']) && (int)$record['user_id']!==(int)$login['id'])
        || strcasecmp($login['status'],'Inactive')!==0) {
        throw new AccountLifecycleConflict('Login ownership or archive status is ambiguous. Retain the account using Archive.');
    }
    return $login;
}

function lifecycle_user_links(PDO $pdo, int $uid, string $type, int $id): void
{
    foreach (['students'=>'student','advisers'=>'adviser'] as $table=>$role) {
        lifecycle_block_if($pdo,"SELECT id FROM $table WHERE user_id=:uid AND (:role<>:type OR id<>:id) FOR UPDATE",
            [':uid'=>$uid,':role'=>$role,':type'=>$type,':id'=>$id],'Another account record references this login.');
    }
    lifecycle_block_if($pdo,'SELECT id FROM calendar_deadlines WHERE creator_user_id=:uid FOR UPDATE',[':uid'=>$uid],
        'Official deadlines depend on this account’s authorship and visibility.');
}

function lifecycle_execute(PDO $pdo, array $actor, array $data): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only Admins may manage account lifecycle.');
    $type=$data['accountType']??null; $action=$data['action']??null;
    if ($action==='recover') return retention_recover($pdo,$actor,$data);
    if (in_array($type,['student','adviser'],true)) {
        return in_array($action,['archive','restore','hold','remove_hold'],true)
            ? retention_change($pdo,$actor,$data) : retention_purge($pdo,$actor,$data);
    }
    if (!in_array($type,['student','adviser','admin'],true) || !in_array($action,['archive','permanent_delete'],true)
        || ($action==='archive' && $type!=='admin')) throw new AccountLifecycleValidation('Invalid lifecycle action.');
    $id=$data['targetId']??null;
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id) || (int)$id>2147483647) throw new AccountLifecycleValidation('Invalid account ID.');
    $id=(int)$id;
    if ($type==='admin' && $id!==(int)$actor['id']) throw new AccountLifecycleForbidden('Admins may archive or delete only their own Admin account.');
    if (!is_string($data['currentPassword']??null) || !is_string($data['confirmation']??null)
        || ($data['confirmed']??false)!==true) throw new AccountLifecycleValidation('Password and explicit confirmation are required.');
    if (strlen($data['currentPassword'])>200 || strlen($data['confirmation'])>100) throw new AccountLifecycleValidation('Invalid password or confirmation length.');
    $schemaLock=false;
    if ($action==='permanent_delete') {
        $schemaLock=(int)$pdo->query("SELECT GET_LOCK('prism_migrate',0)")->fetchColumn()===1;
        if(!$schemaLock) throw new AccountLifecycleConflict('Schema maintenance or another permanent deletion is in progress. Retain the account using Archive and retry after review.');
        try { lifecycle_verify_schema($pdo); }
        catch(Throwable $error) {
            $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
            throw $error;
        }
    }
    else {
        $engines=lifecycle_rows($pdo,"SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','password_resets','activity_logs') ORDER BY TABLE_NAME");
        if (count($engines)!==3 || array_filter($engines,fn($table)=>$table['ENGINE']!=='InnoDB')) {
            throw new AccountLifecycleConflict('Self-archive requires transactional account, token, and audit tables. Request a database review.');
        }
    }
    try {
        $pdo->beginTransaction();
        // One common lock order serializes all lifecycle writes and both last-admin decisions.
        // role has no reviewed index. Discover IDs without row locks, then lock only Admin PKs.
        // A locking role scan would lock unrelated logins and invert archive's entity/login order.
        $adminIds=array_column(lifecycle_rows($pdo,'SELECT id FROM users WHERE role="admin" ORDER BY id'),'id');
        $admins=$adminIds?lifecycle_rows($pdo,'SELECT * FROM users WHERE id IN ('.implode(',',array_fill(0,count($adminIds),'?')).') ORDER BY id FOR UPDATE',$adminIds):[];
        $lockedActor=null; $otherActive=0;
        foreach ($admins as $admin) {
            if($admin['role']!=='admin') continue;
            if ((int)$admin['id']===(int)$actor['id']) $lockedActor=$admin;
            elseif (strcasecmp($admin['status'],'Active')===0) ++$otherActive;
        }
        if (!$lockedActor || strcasecmp($lockedActor['status'],'Active')!==0) throw new AccountLifecycleForbidden('Your Admin session is no longer active.');
        if (!password_verify($data['currentPassword'],$lockedActor['password_hash'])) throw new AccountLifecycleValidation('Your current password is incorrect.');
        $actor=$lockedActor;
        if ($type==='admin') {
            if ($otherActive<1) throw new AccountLifecycleConflict('Another active Admin must remain. The last active Admin cannot archive or delete themselves.');
            $expected=$action==='archive'?'ARCHIVE':'DELETE';
            if ($data['confirmation']!==$expected) throw new AccountLifecycleValidation('Type '.$expected.' exactly to confirm.');
            if ($action==='archive') {
                $pdo->prepare('UPDATE users SET status="Inactive" WHERE id=:id')->execute([':id'=>$id]);
                $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=:id')->execute([':id'=>$id]);
                lifecycle_audit($pdo,$actor,'admin_self_archived','user',$id,$actor['username']);
            } else {
                lifecycle_user_links($pdo,$id,'admin',$id);
                // Reports retain their original author ID and human attribution. No institutional rows are nulled.
                lifecycle_audit($pdo,$actor,'admin_self_deleted','user',$id,$actor['username']);
                $pdo->prepare('DELETE FROM password_resets WHERE user_id=:id')->execute([':id'=>$id]);
                $pdo->prepare('DELETE FROM users WHERE id=:id AND role="admin"')->execute([':id'=>$id]);
            }
            $remaining=lifecycle_rows($pdo,'SELECT id,role,status FROM users WHERE id IN ('.implode(',',array_fill(0,count($adminIds),'?')).') ORDER BY id FOR UPDATE',$adminIds);
            if (!array_filter($remaining,fn($row)=>(int)$row['id']!==$id && $row['role']==='admin' && strcasecmp($row['status'],'Active')===0)) {
                throw new AccountLifecycleConflict('Another active Admin must remain. The operation was rolled back.');
            }
        }
        $pdo->commit();
        return ['ok'=>true,'message'=>$action==='archive'?'Your Admin account was archived.':'Account permanently deleted. Audit evidence and institutional history retained.',
            'logout'=>$type==='admin','redirect'=>$type==='admin'?'login.php':null];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if($error instanceof PDOException && (int)($error->errorInfo[1]??0)===1451) {
            throw new AccountLifecycleConflict('A database foreign-key dependency prevents permanent deletion. Retain the account using Archive.',0,$error);
        }
        throw $error;
    } finally {
        if($schemaLock) {
            try { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
            catch(PDOException $error) { error_log('PRISM lifecycle schema coordination release failed.'); }
        }
    }
}
