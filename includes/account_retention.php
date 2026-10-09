<?php
/** Student/Adviser retention only. Admin self-lifecycle stays in account_lifecycle.php. */
require_once __DIR__.'/account_purge_files.php';

function retention_type(mixed $type): string
{
    if (!in_array($type,['student','adviser'],true)) throw new AccountLifecycleValidation('Choose Student or Adviser lifecycle.');
    return $type;
}
function retention_id(mixed $id): int
{
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id) || (int)$id>2147483647) {
        throw new AccountLifecycleValidation('Invalid account ID.');
    }
    return (int)$id;
}
function retention_table(string $type): string { return retention_type($type)==='student'?'students':'advisers'; }

/** Actual open workflow, not historical activity. Used identically in lists and execution. */
function retention_unresolved_sql(string $type,string $alias): string
{
    if ($type==='student') return "(EXISTS (SELECT 1 FROM documents ld WHERE ld.student_id=$alias.id AND ld.is_current=1 AND ld.rpms_submitted_at IS NULL)
        OR EXISTS (SELECT 1 FROM notifications ln WHERE ln.recipient_type='student' AND (ln.recipient_id=$alias.id
            OR ((ln.recipient_id IS NULL OR ln.recipient_id<=0) AND (ln.recipient_email=$alias.email OR ln.recipient_name=$alias.full_name))) AND ln.status='Sending'))";
    return "(EXISTS (SELECT 1 FROM notifications ln WHERE ln.recipient_type='adviser' AND (ln.recipient_id=$alias.id
            OR ((ln.recipient_id IS NULL OR ln.recipient_id<=0) AND (ln.recipient_email=$alias.email OR ln.recipient_name=$alias.full_name))) AND ln.status='Sending')
        OR EXISTS (SELECT 1 FROM calendar_deadlines lc JOIN users lu ON lu.id=lc.creator_user_id
            WHERE lu.role='adviser' AND (lu.id=$alias.user_id OR lu.email=$alias.email)
            AND lc.status='Active' AND lc.deadline_date>=CURDATE()))";
}

function retention_projection(string $type,string $a): string
{
    $open=retention_unresolved_sql($type,$a);
    return "DATE_ADD($a.archived_at,INTERVAL 7 DAY) AS manual_purge_at,
        DATE_ADD($a.archived_at,INTERVAL 6 MONTH) AS retention_at,
        ($a.archived_at IS NOT NULL AND NOW()>=DATE_ADD($a.archived_at,INTERVAL 7 DAY)) AS grace_elapsed,
        ($a.archived_at IS NOT NULL AND NOW()>=DATE_ADD($a.archived_at,INTERVAL 6 MONTH)) AS retention_elapsed,
        ($a.archived_at IS NOT NULL AND NOW()>=DATE_SUB(DATE_ADD($a.archived_at,INTERVAL 6 MONTH),INTERVAL 3 DAY)
            AND NOW()<DATE_ADD($a.archived_at,INTERVAL 6 MONTH)) AS retention_warning,
        $open AS unresolved_workflow";
}

function retention_state(array $r): array
{
    $archived=!empty($r['archived_at']); $held=!empty($r['retention_hold']); $open=!empty($r['unresolved_workflow']);
    $reason=!$archived?'Archive first.':($held?'Retention Hold.':($open?'Purge postponed — Admin review required: unresolved workflow.':
        (empty($r['grace_elapsed'])?'Permanent deletion available on '.($r['manual_purge_at']??'a date established by the server').'.':'')));
    return ['archivedAt'=>$r['archived_at']??null,'manualPurgeAt'=>$r['manual_purge_at']??null,'retentionAt'=>$r['retention_at']??null,
        'retentionHold'=>$held,'holdReason'=>$r['retention_hold_reason']??null,'unresolvedWorkflow'=>$open,
        'approachingRetention'=>!empty($r['retention_warning']),
        'manualEligible'=>$archived&&!$held&&!$open&&!empty($r['grace_elapsed']),
        'cleanupEligible'=>$archived&&!$held&&!$open&&!empty($r['retention_elapsed']),
        'overrideAvailable'=>$archived&&!$held&&!$open&&empty($r['grace_elapsed']),
        'postponedReason'=>$open?'Unresolved workflow':($r['purge_postponed_reason']??null),'purgeBlockReason'=>$reason];
}

function retention_actor(PDO $pdo,array $actor,?string $password=null): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only Admins may manage account lifecycle.');
    $rows=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=? FOR UPDATE',[(int)$actor['id']]);
    $current=$rows[0]??null;
    if (!$current || $current['role']!=='admin' || strcasecmp($current['status'],'Active')!==0) {
        throw new AccountLifecycleForbidden('Your Admin session is no longer active.');
    }
    if ($password!==null && !password_verify($password,$current['password_hash'])) throw new AccountLifecycleValidation('Your current password is incorrect.');
    return $current;
}

/** Mandatory transactional audit. The purge entry contains no target name/email/profile. */
function retention_audit(PDO $pdo,array $actor,string $action,string $type,int $id,string $identifier,string $after,?string $reason=null,?string $bulkKey=null,?string $jobId=null): void
{
    $q=$pdo->prepare('INSERT INTO activity_logs (user_email,action,details,actor_name,actor_role,entity_type,entity_id,before_value,after_value,reason,is_override)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $details='Admin user ID '.(int)$actor['id'].($bulkKey!==null?'; bulk '.$bulkKey:'').($jobId!==null?'; job '.$jobId:'');
    $q->execute([$actor['email'],$action,$details,$actor['full_name'],'admin',$type,(string)$id,
        $identifier,$after,$reason,str_contains($action,'grace_period_override')?1:0]);
    if ($q->rowCount()!==1) throw new RuntimeException('Lifecycle audit was not persisted.');
}

function retention_locked_record(PDO $pdo,string $type,int $id): array
{
    $table=retention_table($type);
    // Parent lock before child projections: a locking/current read avoids an old RR snapshot.
    $rows=lifecycle_rows($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]);
    if (!$rows) throw new AccountLifecycleNotFound('Account record not found.');
    $state=lifecycle_rows($pdo,'SELECT '.retention_projection($type,'r')." FROM $table r WHERE r.id=?",[$id]);
    return $rows[0]+$state[0];
}

function retention_change(PDO $pdo,array $actor,array $data,?array $authorizedScope=null,?string $bulkKey=null): array
{
    $type=retention_type($data['accountType']??null); $id=retention_id($data['targetId']??null);
    $action=$data['action']??'';
    if (!in_array($action,['archive','restore','hold','remove_hold'],true)) throw new AccountLifecycleValidation('Invalid lifecycle action.');
    $reason=$data['reason']??'';
    if (!is_string($reason) || mb_strlen($reason)>500) throw new AccountLifecycleValidation('Reason must be 500 characters or fewer.');
    if ($pdo->inTransaction()) throw new LogicException('Lifecycle owns its transaction.');
    $engines=lifecycle_rows($pdo,"SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('students','advisers','users','password_resets','activity_logs')");
    if (count($engines)!==5 || array_filter($engines,fn($t)=>$t['ENGINE']!=='InnoDB')) throw new AccountLifecycleConflict('Lifecycle changes require transactional account, assignment, token and audit tables.');
    $pdo->beginTransaction();
    try {
        $actor=retention_actor($pdo,$actor); $r=retention_locked_record($pdo,$type,$id);
        if ($authorizedScope!==null && !retention_scope_rows($pdo,$actor,$type,$authorizedScope,[$id])) throw new AccountLifecycleConflict('Account left the selected filter scope.');
        purge_require_no_pending($pdo,$type,$id);
        $table=retention_table($type); $identifier=$r[$type==='student'?'student_id':'employee_id']; $impact=0;
        if ($action==='archive') {
            $login=lifecycle_resolve_login($pdo,$r,$type,true);
            if ($login) {
                $pdo->prepare('UPDATE users SET status="Inactive" WHERE id=?')->execute([$login['id']]);
                $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=?')->execute([$login['id']]);
            }
            if ($type==='adviser') {
                $assigned=lifecycle_rows($pdo,'SELECT id FROM students WHERE adviser_id=? ORDER BY id FOR UPDATE',[$id]);
                if (!is_int($data['expectedAssignedStudents']??null) || $data['expectedAssignedStudents']<0) {
                    throw new AccountLifecycleValidation('A valid assigned Student count from a fresh archive confirmation is required.');
                }
                if ($data['expectedAssignedStudents']!==count($assigned)) {
                    throw new AccountLifecycleConflict('Assigned Student count changed. Review a fresh archive confirmation.');
                }
                $q=$pdo->prepare('UPDATE students SET adviser_id=NULL WHERE adviser_id=?'); $q->execute([$id]); $impact=$q->rowCount();
            }
            if (empty($r['archived_at'])) {
                $pdo->prepare("UPDATE $table SET archived_at=NOW(),retention_hold=0,retention_hold_at=NULL,retention_hold_by=NULL,
                    retention_hold_reason=NULL,purge_postponed_at=NULL,purge_postponed_reason=NULL".($type==='adviser'?',status="Inactive"':'').' WHERE id=?')->execute([$id]);
                retention_audit($pdo,$actor,$type.'_archived',$type,$id,$identifier,'Archived; '.$impact.' Students unassigned',null,$bulkKey);
            } elseif ($type==='adviser') $pdo->prepare('UPDATE advisers SET status="Inactive" WHERE id=?')->execute([$id]);
        } else {
            if (empty($r['archived_at'])) throw new AccountLifecycleConflict('Only archived accounts can be restored or placed on Retention Hold.');
            if ($action==='restore') {
                $login=lifecycle_resolve_login($pdo,$r,$type,true);
                if (!$login) throw new AccountLifecycleConflict('Restore requires a uniquely linked login. Request Admin account repair.');
                // Rotate the credential hash of the same password via a new random salt is impossible without the password.
                // Require a fresh account setup after restoration; old sessions/tokens cannot become valid again.
                $pdo->prepare('UPDATE users SET status="Active",password_hash=?,must_change_password=1 WHERE id=?')
                    ->execute([password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$login['id']]);
                $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$login['id']]);
                $token=bin2hex(random_bytes(32));
                $pdo->prepare('INSERT INTO password_resets (user_id,token,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')
                    ->execute([$login['id'],'sha256:'.hash('sha256',$token)]);
                $pdo->prepare("UPDATE $table SET archived_at=NULL,retention_hold=0,retention_hold_at=NULL,retention_hold_by=NULL,
                    retention_hold_reason=NULL,purge_postponed_at=NULL,purge_postponed_reason=NULL,restored_at=NOW()".
                    ($type==='adviser'?',status="Active"':'').' WHERE id=?')->execute([$id]);
                retention_audit($pdo,$actor,$type.'_restored',$type,$id,$identifier,'Active; new account setup required',null,$bulkKey);
            } else {
                $held=$action==='hold';
                $pdo->prepare("UPDATE $table SET retention_hold=?,retention_hold_at=".($held?'NOW()':'NULL').',retention_hold_by=?,retention_hold_reason=? WHERE id=?')
                    ->execute([$held?1:0,$held?(int)$actor['id']:null,$held?(trim($reason)?:null):null,$id]);
                retention_audit($pdo,$actor,$type.($held?'_retention_hold':'_retention_hold_removed'),$type,$id,$identifier,$held?'Retention Hold':'Hold removed',trim($reason)?:null,$bulkKey);
            }
        }
        $pdo->commit();
        $result=['ok'=>true,'logout'=>false,'message'=>ucfirst($type).' '.match($action){'archive'=>'archived. '.$impact.' Students became Unassigned.',
            'restore'=>'restored. Provide the new password setup link to the account holder.','hold'=>'placed on Retention Hold.',default=>'Retention Hold removed.'},'unassignedStudents'=>$impact];
        if (isset($token)) {
            // Setup token is shown once to the authorized Admin; never stored plaintext in audit/job.
            $result['setupToken']=$token;
            $result['setupLink']=(defined('APP_BASE_URL')?rtrim(APP_BASE_URL,'/').'/':'').'reset_password.php?token='.rawurlencode($token);
        }
        return $result;
    } catch(Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function retention_require_eligible(array $record,string $method): void
{
    if (empty($record['archived_at']) || (isset($record['employee_id']) && strcasecmp($record['status'],'Inactive')!==0)) {
        throw new AccountLifecycleConflict('Archive first. Active accounts cannot be permanently purged.');
    }
    if (!empty($record['retention_hold'])) throw new AccountLifecycleConflict('Retention Hold blocks every purge method.');
    if (!empty($record['unresolved_workflow'])) throw new AccountLifecycleConflict('Purge postponed — Admin review required: unresolved workflow.');
    if ($method==='retention_cleanup' && empty($record['retention_elapsed'])) throw new AccountLifecycleConflict('Six calendar months have not elapsed since archive.');
    if ($method==='manual' && empty($record['grace_elapsed'])) throw new AccountLifecycleConflict('Permanent deletion available on '.$record['manual_purge_at'].'.');
    if ($method==='grace_period_override' && !empty($record['grace_elapsed'])) throw new AccountLifecycleConflict('The seven-day grace period has elapsed. Use normal permanent deletion.');
}

/** Lock delivery mutex BEFORE notification row locks, so workers cannot start during purge. */
function retention_notifications(PDO $pdo,array $r,string $type,array &$mutexes): array
{
    $rows=lifecycle_rows($pdo,'SELECT * FROM notifications WHERE recipient_type=? AND
        (recipient_id=? OR ((recipient_id IS NULL OR recipient_id<=0) AND (recipient_email=? OR recipient_name=?))) ORDER BY id',
        [$type,$r['id'],$r['email'],$r['full_name']]);
    foreach ($rows as $n) {
        if ($n['recipient_id']===null || (int)$n['recipient_id']<=0) {
            // Name-only legacy matching is not sufficient proof of exclusive ownership.
            if ($n['recipient_email']!==$r['email'] || ($n['recipient_name']!==null && $n['recipient_name']!=='' && $n['recipient_name']!==$r['full_name'])) {
                throw new AccountLifecycleConflict('Legacy notification ownership is ambiguous; Admin review required.');
            }
        }
        $name='prism_notification_'.$n['id']; $q=$pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$name]);
        if ((int)$q->fetchColumn()!==1) throw new AccountLifecycleConflict('Purge postponed — Admin review required: notification delivery is running.');
        $mutexes[]=$name;
    }
    if ($rows) {
        $ids=array_column($rows,'id'); $rows=lifecycle_rows($pdo,'SELECT * FROM notifications WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE',$ids);
        foreach ($rows as $n) if ($n['status']==='Sending') throw new AccountLifecycleConflict('Purge postponed — Admin review required: notification Sending lease.');
    }
    return $rows;
}

/** Preserve unknown institutional data; delete only reviewed ownership edges. */
function retention_purge_plan(PDO $pdo,array $r,string $type,?array $login,array &$mutexes): array
{
    $id=(int)$r['id']; $docs=[]; $reports=[]; $files=[];
    $notices=retention_notifications($pdo,$r,$type,$mutexes);
    if ($login) {
        foreach (['students','advisers'] as $table) {
            $other=lifecycle_rows($pdo,"SELECT id FROM $table WHERE user_id=?".($table===retention_table($type)?' AND id<>?':'').' FOR UPDATE',
                $table===retention_table($type)?[$login['id'],$id]:[$login['id']]);
            if ($other) throw new AccountLifecycleConflict('Another account references the login; Admin review required.');
        }
        $deadlines=lifecycle_rows($pdo,'SELECT * FROM calendar_deadlines WHERE creator_user_id=? ORDER BY id FOR UPDATE',[$login['id']]);
        foreach ($deadlines as $deadline) if ($deadline['status']==='Active' && $deadline['deadline_date']>=$pdo->query('SELECT CURDATE()')->fetchColumn()) {
            throw new AccountLifecycleConflict('Purge postponed — Admin review required: future Active official deadline.');
        }
    }
    if ($type==='student') {
        $count=(int)lifecycle_rows($pdo,'SELECT (SELECT COUNT(*) FROM documents WHERE student_id=?)+(SELECT COUNT(*) FROM reports WHERE owner_student_id=?) AS total',[$id,$id])[0]['total'];
        if ($count>2000) throw new AccountLifecycleConflict('Account exceeds the 2,000-file record limit; Admin review required.');
        $docs=lifecycle_rows($pdo,'SELECT * FROM documents WHERE student_id=? ORDER BY id FOR UPDATE',[$id]);
        // Only a colliding unlinked legacy document blocks this target, not every account globally.
        if (lifecycle_rows($pdo,'SELECT id FROM documents WHERE student_id IS NULL AND (student_name=? OR (uploaded_by_role="student" AND uploaded_by=?)) LIMIT 1 FOR UPDATE',[$r['full_name'],$r['full_name']])) {
            throw new AccountLifecycleConflict('Unlinked legacy document ownership is ambiguous; Admin review required.');
        }
        $docIds=array_column($docs,'id');
        if (lifecycle_rows($pdo,'SELECT d.id FROM documents d LEFT JOIN documents prev ON prev.id=d.supersedes_id
            WHERE d.student_id=? AND d.supersedes_id IS NOT NULL AND (prev.id IS NULL OR prev.student_id IS NULL OR prev.student_id<>?) LIMIT 1 FOR UPDATE',[$id,$id])
            || lifecycle_rows($pdo,'SELECT d.id FROM documents d JOIN documents owned ON owned.id=d.supersedes_id
                WHERE owned.student_id=? AND (d.student_id IS NULL OR d.student_id<>?) LIMIT 1 FOR UPDATE',[$id,$id])) {
            throw new AccountLifecycleConflict('Document version ownership crosses account boundaries; Admin review required.');
        }
        if (lifecycle_rows($pdo,'SELECT d.id FROM documents d JOIN documents other ON other.stored_name=d.stored_name AND other.id<>d.id WHERE d.student_id=? LIMIT 1 FOR UPDATE',[$id])) {
            throw new AccountLifecycleConflict('A document file is shared; Admin review required.');
        }
        foreach ($docs as $doc) $files[]=['kind'=>'documents','name'=>$doc['stored_name']];
        $reports=lifecycle_rows($pdo,'SELECT * FROM reports WHERE owner_student_id=? ORDER BY id FOR UPDATE',[$id]);
        if (lifecycle_rows($pdo,'SELECT r.id FROM reports r JOIN reports other ON other.filename=r.filename AND other.id<>r.id WHERE r.owner_student_id=? LIMIT 1 FOR UPDATE',[$id])) {
            throw new AccountLifecycleConflict('A report file is shared; Admin review required.');
        }
        foreach ($reports as $report) $files[]=['kind'=>'reports','name'=>$report['filename']];
        $ai=lifecycle_rows($pdo,'SELECT id,owner_student_id,owner_document_id FROM ai_outputs WHERE owner_student_id=? FOR UPDATE',[$id]);
        foreach (array_chunk($docIds,200) as $chunk) $ai=array_merge($ai,lifecycle_rows($pdo,'SELECT id,owner_student_id,owner_document_id FROM ai_outputs WHERE owner_document_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') FOR UPDATE',$chunk));
        foreach ($ai as $output) if (($output['owner_student_id']!==null && (int)$output['owner_student_id']!==$id)
            || ($output['owner_document_id']!==null && !in_array($output['owner_document_id'],$docIds,true))) {
            throw new AccountLifecycleConflict('AI ownership crosses account/document boundaries; Admin review required.');
        }
    }
    return ['documents'=>$docs,'reports'=>$reports,'notifications'=>$notices,'files'=>$files];
}

function retention_delete_ids(PDO $pdo,string $table,array $ids): void
{
    if (!in_array($table,['documents','reports','notifications'],true)) throw new LogicException('Unreviewed purge table.');
    foreach (array_chunk($ids,200) as $chunk) $pdo->prepare("DELETE FROM $table WHERE id IN (".implode(',',array_fill(0,count($chunk),'?')).')')->execute($chunk);
}

function retention_purge_mutate(PDO $pdo,array $actor,array $r,string $type,?array $login,array $plan,string $method,string $job,string $hash,?string $reason,?string $bulkKey=null): void
{
    $id=(int)$r['id']; $identifier=$r[$type==='student'?'student_id':'employee_id'];
    if ($type==='student') {
        $pdo->prepare('DELETE FROM ai_outputs WHERE owner_student_id=?')->execute([$id]);
        foreach (array_chunk(array_column($plan['documents'],'id'),200) as $ids) {
            $slots=implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare('DELETE FROM ai_outputs WHERE owner_document_id IN ('.$slots.')')->execute($ids);
            $pdo->prepare('DELETE FROM activity_logs WHERE entity_type="document" AND entity_id IN ('.$slots.')')->execute($ids);
        }
        foreach (array_chunk(array_column($plan['reports'],'id'),200) as $ids) $pdo->prepare('DELETE FROM activity_logs WHERE entity_type="report" AND entity_id IN ('.implode(',',array_fill(0,count($ids),'?')).')')->execute($ids);
        retention_delete_ids($pdo,'documents',array_column($plan['documents'],'id'));
        retention_delete_ids($pdo,'reports',array_column($plan['reports'],'id'));
        retention_delete_ids($pdo,'notifications',array_column($plan['notifications'],'id'));
        $pdo->prepare('DELETE FROM ierb_history WHERE student_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM calendar_deadline_recipients WHERE student_id=?')->execute([$id]);
        // Older identity values are deleted as part of the structured identity history.
        $emails=[$r['email']]; $identifiers=[$identifier];
        foreach (lifecycle_rows($pdo,'SELECT details,before_value FROM activity_logs WHERE entity_type="student" AND entity_id=? AND action="account_identity_field_changed"',[(string)$id]) as $field) {
            if ($field['details']==='email' && $field['before_value']) $emails[]=$field['before_value'];
            if ($field['details']==='identifier' && $field['before_value']) $identifiers[]=$field['before_value'];
        }
        $pdo->prepare('DELETE FROM activity_logs WHERE student_id=? OR (entity_type="student" AND entity_id=?)')->execute([$id,(string)$id]);
        foreach (array_unique($emails) as $email) {
            // A recycled historical email cannot identify another person's unstructured audit rows.
            $claims=lifecycle_rows($pdo,'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1',[$email,$login['id']??0]);
            if (!$claims) $pdo->prepare('DELETE FROM activity_logs WHERE user_email=?')->execute([$email]);
        }
        foreach (array_unique($identifiers) as $code) {
            if (!lifecycle_rows($pdo,'SELECT id FROM students WHERE student_id=? AND id<>? LIMIT 1',[$code,$id])) {
                $pdo->prepare('DELETE FROM activity_logs WHERE action="student_saved" AND details=?')->execute(['student_id='.$code]);
            }
        }
    } else {
        lifecycle_rows($pdo,'SELECT id FROM students WHERE adviser_id=? ORDER BY id FOR UPDATE',[$id]);
        $pdo->prepare('UPDATE students SET adviser_id=NULL WHERE adviser_id=?')->execute([$id]);
        foreach ($plan['notifications'] as $n) $pdo->prepare('UPDATE notifications SET recipient_id=NULL,recipient_type="historical_adviser",
            status=CASE WHEN status="Scheduled" THEN "Cancelled" ELSE status END WHERE id=?')->execute([$n['id']]);
        $pdo->prepare('UPDATE activity_logs SET entity_id=NULL WHERE entity_type="adviser" AND entity_id=?')->execute([(string)$id]);
    }
    if ($login) {
        $pdo->prepare('UPDATE calendar_deadlines SET creator_name=COALESCE(creator_name,?),creator_user_id=NULL WHERE creator_user_id=?')
            ->execute([$r['full_name'],$login['id']]);
        $pdo->prepare('UPDATE reports SET generated_by_user_id=NULL WHERE generated_by_user_id=?')->execute([$login['id']]);
        if ($type==='student') $pdo->prepare('DELETE FROM activity_logs WHERE entity_type="user" AND entity_id=?')->execute([(string)$login['id']]);
        else $pdo->prepare('UPDATE activity_logs SET entity_id=NULL WHERE entity_type="user" AND entity_id=?')->execute([(string)$login['id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$login['id']]);
    }
    $pdo->prepare('DELETE FROM '.retention_table($type).' WHERE id=?')->execute([$id]);
    if ($login) $pdo->prepare('DELETE FROM users WHERE id=? AND role=?')->execute([$login['id'],$type]);
    $action=$type.'_'.match($method){'manual'=>'permanently_deleted','grace_period_override'=>'grace_period_override_purge',default=>'retention_cleanup_purge'};
    retention_audit($pdo,$actor,$action,$type,$id,$identifier,$method,$reason,$bulkKey,$job);
    $pdo->prepare('INSERT INTO account_purge_jobs (id,account_type,account_id,identifier,actor_id,method,manifest_sha256) VALUES (?,?,?,?,?,?,?)')
        ->execute([$job,$type,$id,$identifier,$actor['id'],$method,$hash]);
}

function retention_purge(PDO $pdo,array $actor,array $data,?array $authorizedScope=null,?string $bulkKey=null): array
{
    $type=retention_type($data['accountType']??null); $id=retention_id($data['targetId']??null);
    $method=match($data['action']??''){ 'permanent_delete'=>'manual','grace_period_override'=>'grace_period_override','retention_cleanup'=>'retention_cleanup',default=>throw new AccountLifecycleValidation('Invalid purge method.')};
    if (!is_string($data['currentPassword']??null) || strlen($data['currentPassword'])>200
        || !is_string($data['confirmation']??null) || strlen($data['confirmation'])>100 || ($data['confirmed']??false)!==true) {
        throw new AccountLifecycleValidation('Current Admin password, exact identifier and irreversible acknowledgement are required.');
    }
    $reason=$data['reason']??null;
    if ($method==='grace_period_override' && (!is_string($reason) || mb_strlen(trim($reason))<5 || mb_strlen($reason)>500)) {
        throw new AccountLifecycleValidation('Override requires a reason (5–500 characters).');
    }
    $q=$pdo->query("SELECT GET_LOCK('prism_migrate',0)");
    if ((int)$q->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance or another purge is in progress. Retry after review.');
    $prepared=null; $commitAttempted=false; $committed=false; $mutexes=[];
    try {
        lifecycle_verify_schema($pdo);
        $pdo->beginTransaction(); $actor=retention_actor($pdo,$actor,$data['currentPassword']);
        $r=retention_locked_record($pdo,$type,$id);
        if ($authorizedScope!==null && !retention_scope_rows($pdo,$actor,$type,$authorizedScope,[$id])) throw new AccountLifecycleConflict('Account left the selected filter scope.');
        if ($data['confirmation']!==$r[$type==='student'?'student_id':'employee_id']) throw new AccountLifecycleValidation('Type the exact '.($type==='student'?'Student ID':'Employee ID').' to confirm.');
        purge_require_no_pending($pdo,$type,$id);
        retention_require_eligible($r,$method);
        $login=lifecycle_resolve_login($pdo,$r,$type,true);
        if ($login && (strcasecmp($login['status'],'Inactive')!==0 || $login['role']!==$type || $login['email']!==$r['email'])) {
            throw new AccountLifecycleConflict('Login ownership/archive status is inconsistent; Admin review required.');
        }
        $plan=retention_purge_plan($pdo,$r,$type,$login,$mutexes);
        $prepared=purge_files_prepare($pdo,$type,$id,$plan['files']);
        purge_files_stage($prepared);
        retention_purge_mutate($pdo,$actor,$r,$type,$login,$plan,$method,$prepared['job'],$prepared['hash'],$method==='grace_period_override'?trim($reason):null,$bulkKey);
        $commitAttempted=true; $pdo->commit(); $committed=true;
        try { purge_files_finalize($pdo,$prepared['job'],$prepared['manifest']); }
        catch(Throwable $error) {
            return ['ok'=>false,'committed'=>true,'complete'=>false,'jobId'=>$prepared['job'],'logout'=>false,
                'message'=>'Database purge committed; recovery is required before completion. Job '.$prepared['job'].'.'];
        }
        return ['ok'=>true,'committed'=>true,'complete'=>true,'jobId'=>$prepared['job'],'logout'=>false,'message'=>'Account permanently purged; applicable owned records and files removed.'];
    } catch(Throwable $error) {
        $restoredBeforeRollback=false; $restoreFailed=false;
        if ($prepared && !$commitAttempted && $pdo->inTransaction()) {
            try { purge_files_restore($prepared['job'],$prepared['manifest']); $restoredBeforeRollback=true; }
            catch(Throwable $restore) { $restoreFailed=true; }
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($restoreFailed) throw new AccountLifecycleConflict('Purge not completed; file recovery required. Job '.$prepared['job'].'.');
        if ($prepared && !$committed && !$restoredBeforeRollback) {
            // A lost COMMIT acknowledgement must NEVER trigger restoration without positive DB evidence.
            if ($commitAttempted) {
                try { $durable=lifecycle_rows($pdo,'SELECT id FROM account_purge_jobs WHERE id=?',[$prepared['job']]); }
                catch(Throwable $unknown) { throw new AccountLifecycleConflict('Commit outcome is uncertain. Recovery required for job '.$prepared['job'].'.'); }
                if ($durable) return ['ok'=>false,'committed'=>true,'complete'=>false,'jobId'=>$prepared['job'],'logout'=>false,'message'=>'Commit acknowledged through recovery evidence; file finalization required. Job '.$prepared['job'].'.'];
            }
            try { purge_files_restore($prepared['job'],$prepared['manifest']); }
            catch(Throwable $restore) { throw new AccountLifecycleConflict('Purge not completed; file recovery required. Job '.$prepared['job'].'.'); }
        }
        if ($error instanceof AccountLifecycleConflict && isset($r) && str_contains($error->getMessage(),'Admin review required')) {
            // Separate safe diagnostic transaction after rollback; no deletion/audit success claim.
            $pdo->prepare('UPDATE '.retention_table($type).' SET purge_postponed_at=NOW(),purge_postponed_reason=? WHERE id=? AND archived_at IS NOT NULL')
                ->execute([mb_substr($error->getMessage(),0,255),$id]);
        }
        if ($error instanceof PDOException && (int)($error->errorInfo[1]??0)===1451) throw new AccountLifecycleConflict('Unexpected database dependency prevents purge; request schema review.');
        throw $error;
    } finally {
        foreach (array_reverse($mutexes) as $name) { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$name]); }
        $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
    }
}

function retention_recover(PDO $pdo,array $actor,array $data): array
{
    $job=$data['jobId']??null;
    if (!is_string($job) || !preg_match('/\A[a-f0-9]{32}\z/',$job)) throw new AccountLifecycleValidation('Invalid recovery job ID.');
    if (($data['confirmation']??null)!=='RECOVER '.$job) throw new AccountLifecycleValidation('Type RECOVER '.$job.' exactly to confirm recovery.');
    if (!is_string($data['currentPassword']??null) || strlen($data['currentPassword'])>200 || ($data['confirmed']??false)!==true) {
        throw new AccountLifecycleValidation('Current Admin password and recovery acknowledgement are required.');
    }
    $manifest=purge_manifest_read($job);
    if (($manifest['database']??null)!==lifecycle_database_binding($pdo)) throw new AccountLifecycleConflict('Recovery database/server binding differs.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',0)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Purge recovery is busy.');
    try {
        // Recovery must recheck the reviewed schema too; do not finalize across unknown DB changes.
        lifecycle_verify_schema($pdo);
        $pdo->beginTransaction(); $actor=retention_actor($pdo,$actor,$data['currentPassword']);
        $rows=lifecycle_rows($pdo,'SELECT * FROM account_purge_jobs WHERE id=? FOR UPDATE',[$job]);
        if ($rows) {
            if ($rows[0]['account_type']!==$manifest['type'] || (int)$rows[0]['account_id']!==$manifest['id']) throw new AccountLifecycleConflict('Recovery target binding differs.');
            $pdo->commit(); purge_files_finalize($pdo,$job,$manifest);
        } else {
            $r=retention_locked_record($pdo,$manifest['type'],$manifest['id']);
            foreach ($manifest['files'] as $file) {
                $table=$file['kind']==='documents'?'documents':'reports'; $column=$file['kind']==='documents'?'stored_name':'filename';
                $owner=$file['kind']==='documents'?'student_id':'owner_student_id';
                $refs=lifecycle_rows($pdo,"SELECT id FROM $table WHERE $column=? AND $owner=? FOR UPDATE",[$file['name'],$manifest['id']]);
                if (count($refs)!==1) throw new AccountLifecycleConflict('Recovery file ownership cannot be re-established.');
            }
            purge_files_restore($job,$manifest);
            retention_audit($pdo,$actor,$manifest['type'].'_purge_rollback_recovered',$manifest['type'],$manifest['id'],
                $r[$manifest['type']==='student'?'student_id':'employee_id'],'Files restored');
            $pdo->commit();
        }
        return ['ok'=>true,'logout'=>false,'message'=>'Recovery completed.','jobId'=>$job];
    } catch(Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
