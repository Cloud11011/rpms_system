<?php
/** Conservative lifecycle operations. No configuration bootstrap or physical file deletion. */
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/account_lifecycle_schema.php';

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

function lifecycle_student_dependencies(PDO $pdo, array $record): void
{
    $id=(int)$record['id']; $name=$record['full_name']; $identifier=$record['student_id'];
    if (empty($record['archived_at'])) throw new AccountLifecycleConflict('Archive Student first. Permanent deletion cannot operate on an active Student.');
    if ($record['stage']!=='Stage 1' || $record['status']!=='On Track' || !empty($record['protocol_code'])
        || !empty($record['is_principal_investigator']) || !empty($record['last_submission_date'])
        || trim((string)$record['requirements'])!=='') {
        throw new AccountLifecycleConflict('The Student has protected research progress. Retain the account using Archive.');
    }
    lifecycle_block_if($pdo,'SELECT id FROM documents WHERE student_id=:id OR student_name=:name OR uploaded_by=:uploader FOR UPDATE',
        [':id'=>$id,':name'=>$name,':uploader'=>$name],'Uploaded documents or historical versions depend on this Student.');
    lifecycle_block_if($pdo,'SELECT id FROM documents WHERE student_id IS NULL LIMIT 1 FOR UPDATE',[],
        'Unlinked legacy document ownership cannot be excluded safely.');
    lifecycle_block_if($pdo,'SELECT deadline_id FROM calendar_deadline_recipients WHERE student_id=:id FOR UPDATE',[':id'=>$id],
        'Frozen official deadline recipients depend on this Student.');
    // All notifications to this identity are conservatively protected, including pending delivery.
    lifecycle_block_if($pdo,'SELECT id FROM notifications WHERE (recipient_type="student" AND recipient_id=:id) OR recipient_email=:email OR recipient_name=:name FOR UPDATE',
        [':id'=>$id,':email'=>$record['email'],':name'=>$name],'Protected notifications reference this Student.');
    lifecycle_block_if($pdo,'SELECT id FROM notifications WHERE recipient_type="student" AND recipient_id IS NULL LIMIT 1 FOR UPDATE',[],
        'Legacy notification ownership cannot be excluded safely.');
    $history=lifecycle_rows($pdo,'SELECT * FROM ierb_history WHERE student_id=:id ORDER BY id FOR UPDATE',[':id'=>$id]);
    if (count($history)>1) throw new AccountLifecycleConflict('Substantive IERB history exists. Retain the account using Archive.');
    foreach ($history as $h) {
        if ($h['note']!=='Record created by RPMS.' || $h['stage']!=='Stage 1' || $h['status']!=='On Track'
            || trim((string)$h['requirements'])!=='' || !empty($h['submission_date']) || trim((string)$h['actor'])===''
            || $h['created_at']!==$record['created_at']) {
            throw new AccountLifecycleConflict('IERB history is not the recognized automatic creation entry. Retain the account using Archive.');
        }
    }
    // Preserve routine account setup/archive evidence, but reject workflow evidence or ambiguous legacy references.
    $logs=lifecycle_rows($pdo,'SELECT * FROM activity_logs WHERE student_id=:id OR (entity_type="student" AND entity_id=:entity)
        OR LOCATE(:identifier,COALESCE(details,""))>0 OR user_email=:email OR actor_name=:name ORDER BY id FOR UPDATE',
        [':id'=>$id,':entity'=>(string)$id,':identifier'=>$identifier,':email'=>$record['email'],':name'=>$name]);
    $saveCount=0;
    foreach ($logs as $log) {
        if (!in_array($log['action'],['account_identity_created','student_saved','student_archived','login','login_failed','logout','password_changed','profile_updated','account_setup_sent','password_reset_requested','password_reset_completed'],true)) {
            throw new AccountLifecycleConflict('Protected workflow or ambiguous audit evidence references this Student. Retain the account using Archive.');
        }
        if ($log['action']==='profile_updated') throw new AccountLifecycleConflict('Historical Student identity changed. Retain the account using Archive.');
        if ($log['action']==='student_saved' && ++$saveCount>1) throw new AccountLifecycleConflict('Historical Student edits cannot be attributed safely. Retain the account using Archive.');
    }
    lifecycle_block_if($pdo,'SELECT al.id FROM activity_logs al WHERE al.action="student_saved" AND al.student_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM students s WHERE al.details=CONCAT("student_id=",s.student_id)) LIMIT 1 FOR UPDATE',[],
        'Legacy Student edits contain an identity that can no longer be resolved.');
    // No report membership table exists: even an otherwise empty account cannot be proven absent from old snapshots.
    lifecycle_block_if($pdo,'SELECT id FROM reports LIMIT 1 FOR UPDATE',[],'Report snapshots exist and Student membership cannot be established safely.');
    lifecycle_block_if($pdo,'SELECT id FROM ai_outputs LIMIT 1 FOR UPDATE',[],'Legacy AI references cannot be excluded safely.');
}

function lifecycle_adviser_dependencies(PDO $pdo, array $record): void
{
    if (strcasecmp($record['status'],'Inactive')!==0) throw new AccountLifecycleConflict('Archive Adviser first. Retain the account using Archive.');
    lifecycle_block_if($pdo,'SELECT id FROM students WHERE adviser_id=:id FOR UPDATE',[':id'=>$record['id']],
        'Current or archived Student assignments depend on this Adviser.');
    $name=$record['full_name'];
    lifecycle_block_if($pdo,'SELECT id FROM documents WHERE uploaded_by=:u OR reviewed_by=:r OR rpms_submitted_by=:s OR override_by=:o
        OR LOCATE(:decorated,COALESCE(reviewed_by,""))>0 OR LOCATE(:submitted,COALESCE(rpms_submitted_by,""))>0 FOR UPDATE',
        [':u'=>$name,':r'=>$name,':s'=>$name,':o'=>$name,':decorated'=>$name,':submitted'=>$name],
        'Historical document attribution depends on this Adviser.');
    lifecycle_block_if($pdo,'SELECT id FROM ierb_history WHERE LOCATE(:name,COALESCE(actor,""))>0 FOR UPDATE',[':name'=>$name],
        'Historical IERB attribution depends on this Adviser.');
    lifecycle_block_if($pdo,'SELECT id FROM notifications WHERE (recipient_type="adviser" AND recipient_id=:id)
        OR recipient_email=:email OR recipient_name=:name OR created_by=:creator FOR UPDATE',
        [':id'=>$record['id'],':email'=>$record['email'],':name'=>$name,':creator'=>$name],'Notification attribution depends on this Adviser.');
    lifecycle_block_if($pdo,'SELECT n.id FROM notifications n WHERE n.recipient_type="adviser" AND (n.recipient_id IS NULL OR n.recipient_id<=0)
        AND ((COALESCE(n.recipient_email,"")="" AND COALESCE(n.recipient_name,"")="") OR
            (SELECT COUNT(*) FROM advisers a WHERE
                (COALESCE(n.recipient_email,"")="" OR a.email=n.recipient_email) AND
                (COALESCE(n.recipient_name,"")="" OR a.full_name=n.recipient_name))<>1) LIMIT 1 FOR UPDATE',[],
        'Legacy Adviser notification ownership cannot be excluded safely.');
    $logs=lifecycle_rows($pdo,'SELECT * FROM activity_logs WHERE user_email=:email OR actor_name=:name OR (entity_type="adviser" AND entity_id=:id) ORDER BY id FOR UPDATE',
        [':email'=>$record['email'],':name'=>$name,':id'=>(string)$record['id']]);
    $archiveEvidence=false;
    foreach ($logs as $log) {
        if (in_array($log['action'],['adviser_deactivated','adviser_archived'],true) && $log['entity_type']==='adviser'
            && (string)$log['entity_id']===(string)$record['id'] && $log['after_value']==='Inactive') $archiveEvidence=true;
        if (!in_array($log['action'],['account_identity_created','adviser_saved','adviser_deactivated','adviser_archived','login','login_failed','logout','password_changed'],true)
            || ($log['action']==='adviser_saved' && $log['before_value']!=='New record')) {
            throw new AccountLifecycleConflict('Historical or ambiguous audit attribution depends on this Adviser. Retain the account using Archive.');
        }
    }
    if (!$archiveEvidence) throw new AccountLifecycleConflict('Archive Adviser first; the prior archive action must be recorded. Retain the account using Archive.');
    lifecycle_block_if($pdo,'SELECT id FROM reports LIMIT 1 FOR UPDATE',[],'Report snapshots exist and historical Adviser attribution cannot be excluded.');
    lifecycle_block_if($pdo,'SELECT id FROM ai_outputs LIMIT 1 FOR UPDATE',[],'Legacy AI attribution cannot be excluded safely.');
}

function lifecycle_execute(PDO $pdo, array $actor, array $data): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only Admins may manage account lifecycle.');
    $type=$data['accountType']??null; $action=$data['action']??null;
    if (!in_array($type,['student','adviser','admin'],true) || !in_array($action,['archive','permanent_delete'],true)
        || ($action==='archive' && $type!=='admin')) throw new AccountLifecycleValidation('Invalid lifecycle action.');
    $id=$data['targetId']??null;
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id) || (int)$id>2147483647) throw new AccountLifecycleValidation('Invalid account ID.');
    $id=(int)$id;
    if ($type==='admin' && $id!==(int)$actor['id']) throw new AccountLifecycleForbidden('Admins may archive or delete only their own Admin account.');
    if (!is_string($data['currentPassword']??null) || !is_string($data['confirmation']??null)
        || ($data['confirmed']??false)!==true) throw new AccountLifecycleValidation('Password and explicit confirmation are required.');
    if (strlen($data['currentPassword'])>200 || strlen($data['confirmation'])>100) throw new AccountLifecycleValidation('Invalid password or confirmation length.');
    if ($action==='permanent_delete' && $type!=='admin' && ($data['testRecord']??false)!==true) throw new AccountLifecycleValidation('Confirm that this is an unused test or demo record.');
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
        } else {
            $table=$type==='student'?'students':'advisers';
            $records=lifecycle_rows($pdo,"SELECT * FROM $table WHERE id=:id FOR UPDATE",[':id'=>$id]);
            if (!$records) throw new AccountLifecycleNotFound('Account record not found.');
            $record=$records[0]; $identifier=$record[$type==='student'?'student_id':'employee_id'];
            if ($data['confirmation']!==$identifier) throw new AccountLifecycleValidation('Type the exact '.($type==='student'?'Student ID':'Employee ID').' to confirm.');
            $login=lifecycle_login($pdo,$record,$type,$identifier);
            lifecycle_require_identity_provenance($pdo,$record,$type,$login);
            if ($type==='student') lifecycle_student_dependencies($pdo,$record); else lifecycle_adviser_dependencies($pdo,$record);
            if ($login) lifecycle_user_links($pdo,(int)$login['id'],$type,$id);
            lifecycle_audit($pdo,$actor,$type.'_permanently_deleted',$type,$id,$identifier);
            if ($type==='student') $pdo->prepare('DELETE FROM ierb_history WHERE student_id=:id')->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM $table WHERE id=:id")->execute([':id'=>$id]);
            if ($login) {
                $pdo->prepare('DELETE FROM password_resets WHERE user_id=:id')->execute([':id'=>$login['id']]);
                $pdo->prepare('DELETE FROM users WHERE id=:id AND role=:role')->execute([':id'=>$login['id'],':role'=>$type]);
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
