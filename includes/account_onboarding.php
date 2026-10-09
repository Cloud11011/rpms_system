<?php
/** Shared invitation identity, setup and first-set completion. No configuration/bootstrap. */
require_once __DIR__.'/account_identity.php';
require_once __DIR__.'/academic_catalog.php';

function onboarding_profile(PDO $pdo, array $user, bool $lock = false): ?array
{
    if (!in_array($user['role'] ?? '', ['student','adviser'], true)) return null;
    $table=$user['role']==='student'?'students':'advisers';
    $field=$user['role']==='student'?'student_id':'employee_id';
    $rows=lifecycle_rows($pdo,"SELECT * FROM $table WHERE user_id=? OR email=? OR $field=? ORDER BY id".($lock?' FOR UPDATE':''),
        [$user['id'],$user['email'],$user['ref_id']??null]);
    if (count($rows)!==1 || $rows[0]['email']!==$user['email']
        || (!empty($rows[0]['user_id']) && (int)$rows[0]['user_id']!==(int)$user['id'])) return null;
    return $rows[0];
}

function onboarding_complete(PDO $pdo, array $user): bool
{
    if (($user['role']??'')==='admin') return true;
    $profile=onboarding_profile($pdo,$user);
    return $profile && $profile['profile_completed_at']!==null && $profile['archived_at']===null
        && ($user['role']!=='adviser' || $profile['status']==='Active');
}

/** Reject unexpected input rather than trusting browser field ownership. */
function onboarding_allow_fields(array $data, array $allowed): void
{
    if (array_diff(array_keys($data),$allowed)) throw new AccountLifecycleValidation('This request includes fields you cannot change.');
}

function onboarding_text(mixed $value, string $label, int $max, bool $required=true): string
{
    if (!is_string($value)) throw new AccountLifecycleValidation($label.' must be text.');
    $value=trim($value);
    if (($required && $value==='') || mb_strlen($value)>$max || preg_match('/[\x00-\x1F\x7F]/u',$value)) {
        throw new AccountLifecycleValidation($label.' must be '.($required?'1':'0').'–'.$max.' characters without control characters.');
    }
    return $value;
}

/** Also used by retained complete-record creation and registration paths. */
function onboarding_email_available(PDO $pdo, string $email): void
{
    foreach (['users','students','advisers'] as $table) {
        if (lifecycle_rows($pdo,"SELECT id FROM $table WHERE email=? LIMIT 1",[$email])) {
            throw new AccountLifecycleConflict('This email already has an account or profile. Review the existing record; use Resend for Pending profiles or Restore for archived accounts. No new account was created.');
        }
    }
}

function onboarding_id_available(PDO $pdo, string $identifier, string $role, int $uid, int $profileId): void
{
    $table=$role==='student'?'students':'advisers'; $field=$role==='student'?'student_id':'employee_id';
    if (lifecycle_rows($pdo,"SELECT id FROM $table WHERE $field=? AND id<>? LIMIT 1",[$identifier,$profileId])
        || lifecycle_rows($pdo,'SELECT id FROM users WHERE (username=? OR ref_id=?) AND id<>? LIMIT 1',[$identifier,$identifier,$uid])) {
        throw new AccountLifecycleConflict('That institutional ID is already claimed, including retained archived identities. Ask Admin to review it.');
    }
}

function onboarding_audit(PDO $pdo, array $actor, string $action, string $role, int $id): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Onboarding audit must be transactional.');
    $q=$pdo->prepare('INSERT INTO activity_logs (user_email,action,details,actor_name,actor_role,entity_type,entity_id)
        VALUES (?,?,?,?,?,?,?)');
    $q->execute([$actor['email'],$action,'Account user ID '.(int)$actor['id'],$actor['full_name']??null,$actor['role'],$role,(string)$id]);
    if ($q->rowCount()!==1) throw new RuntimeException('Onboarding audit was not persisted.');
}

/** Revalidate the authenticated inviter on the same transaction, including completion. */
function onboarding_inviter(PDO $pdo, array $actor, string $role): array
{
    if (!in_array($role,['student','adviser'],true) || !in_array($actor['role']??'', ['admin','adviser'],true)
        || ($actor['role']==='adviser' && $role!=='student')) throw new AccountLifecycleForbidden('You cannot invite this account type.');
    $adviser=null;
    if ($actor['role']==='adviser') {
        $adviser=onboarding_profile($pdo,$actor,true);
        if (!$adviser || $adviser['profile_completed_at']===null || $adviser['archived_at']!==null || $adviser['status']!=='Active') {
            throw new AccountLifecycleForbidden('Complete your active Adviser profile before inviting Students.');
        }
    }
    $rows=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=? FOR UPDATE',[$actor['id']??0]);
    if (!$rows || $rows[0]['role']!==$actor['role'] || $rows[0]['status']!=='Active'
        || !empty($rows[0]['must_change_password']) || $rows[0]['email']!==$actor['email']) {
        throw new AccountLifecycleForbidden('Your invitation authority is no longer active.');
    }
    return [$rows[0],$adviser];
}

function onboarding_active_adviser(PDO $pdo, mixed $id): ?int
{
    if (!$pdo->inTransaction()) throw new LogicException('Adviser assignment validation requires a transaction.');
    if ($id===null || $id==='') return null;
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id) || (int)$id>2147483647) {
        throw new AccountLifecycleValidation('Choose a valid active Adviser.');
    }
    $rows=lifecycle_rows($pdo,'SELECT * FROM advisers WHERE id=? FOR UPDATE',[(int)$id]);
    if (!$rows || $rows[0]['status']!=='Active' || $rows[0]['archived_at']!==null || $rows[0]['profile_completed_at']===null) {
        throw new AccountLifecycleConflict('Selected Adviser is not active and complete. Choose Unassigned or another Adviser.');
    }
    $login=lifecycle_resolve_login($pdo,$rows[0],'adviser');
    if ($login['status']!=='Active' || $login['email']!==$rows[0]['email']
        || $login['username']!==$rows[0]['employee_id'] || $login['ref_id']!==$rows[0]['employee_id']
        || $login['full_name']!==$rows[0]['full_name']) {
        throw new AccountLifecycleConflict('Selected Adviser does not have a valid active login. Choose Unassigned or request Admin review.');
    }
    return (int)$id;
}

/** User lock precedes this lock in reset issuance/consumption and invitation acceptance. */
function onboarding_requires_invitation_setup(PDO $pdo,int $uid,bool $lock=false): bool
{
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Invitation setup check requires a transaction.');
    $q=$pdo->prepare('SELECT user_id FROM account_invitations WHERE user_id=:u AND accepted_at IS NULL'.($lock?' FOR UPDATE':''));
    $q->execute([':u'=>$uid]);
    return $q->fetchColumn()!==false;
}

/** Commit before email: provider failure can never cause duplicate creation. */
function onboarding_invite(PDO $pdo, array $actor, array $data): array
{
    $role=$data['accountType']??null;
    if (!is_string($role)) throw new AccountLifecycleValidation('Choose Student or Adviser.');
    onboarding_allow_fields($data,$actor['role']==='admin' && $role==='student'
        ?['action','accountType','email','adviserId']:['action','accountType','email']);
    $email=strtolower(onboarding_text($data['email']??null,'Email',190));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !is_allowed_email_domain($email)) {
        throw new AccountLifecycleValidation('Use a valid institutional email address.');
    }
    $token=bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        [$actor,$ownAdviser]=onboarding_inviter($pdo,$actor,$role);
        $assignment=$role==='student'?onboarding_active_adviser($pdo,$ownAdviser?(int)$ownAdviser['id']:($data['adviserId']??null)):null;
        onboarding_email_available($pdo,$email);
        $pdo->prepare('INSERT INTO users (username,password_hash,role,full_name,email) VALUES (NULL,?,?,NULL,?)')
            ->execute([password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$role,$email]);
        $uid=(int)$pdo->lastInsertId();
        if ($role==='student') $pdo->prepare('INSERT INTO students (student_id,full_name,email,user_id,adviser_id) VALUES (NULL,NULL,?,?,?)')->execute([$email,$uid,$assignment]);
        else $pdo->prepare('INSERT INTO advisers (employee_id,full_name,email,user_id) VALUES (NULL,NULL,?,?)')->execute([$email,$uid]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO account_invitations (user_id,invited_by_user_id,token_hash,expires_at,last_sent_at)
            VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR),NOW())')->execute([$uid,$actor['id'],hash('sha256',$token)]);
        $record=onboarding_profile($pdo,['id'=>$uid,'role'=>$role,'email'=>$email,'ref_id'=>null],true);
        lifecycle_identity_persist($pdo,$actor,$role,null,lifecycle_identity_capture($pdo,$record,$role));
        onboarding_audit($pdo,$actor,'account_invited',$role,$id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) throw new AccountLifecycleConflict('This identity was claimed concurrently. Review the existing Pending/complete record.');
        throw $e;
    }
    return ['ok'=>true,'id'=>$id,'profileStatus'=>'Pending']+onboarding_deliver($pdo,$uid,$email,$token);
}

function onboarding_deliver(PDO $pdo, int $uid, string $email, string $token): array
{
    $delivered=false;
    try {
        if (app_base_url_is_valid()) {
            // Fragments are never sent in HTTP requests or ordinary access logs.
            $link=rtrim(APP_BASE_URL,'/').'/account_setup.php#'.rawurlencode($token);
            $result=send_notification_email($email,'Set up your PRISM account',
                "You have been invited to PRISM. Set your password with this one-time link, valid for one hour:\n\n".$link.
                "\n\nThen sign in with your invited email and complete your profile. If unexpected, contact RPMS.",true);
            $delivered=($result['ok']??false)===true;
        }
    } catch (Throwable $e) { /* No provider payload or token may be logged. */ }
    if (!$delivered) log_api_error('account_invitation','Setup delivery failed for user ID '.$uid.'. Pending identity retained.');
    return ['emailSent'=>$delivered,'message'=>$delivered?'Invitation sent. Profile is Pending.':'Pending profile saved. Setup email could not be delivered; review email configuration and use Resend Invitation.'];
}

/** Profile before login before credential locks: same order as lifecycle/completion. */
function onboarding_resend(PDO $pdo, array $actor, array $data): array
{
    onboarding_allow_fields($data,['action','accountType','targetId']);
    $role=$data['accountType']??'';
    if (!in_array($role,['student','adviser'],true)) throw new AccountLifecycleValidation('Choose Student or Adviser.');
    $id=$data['targetId']??null;
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id)) throw new AccountLifecycleValidation('Invalid account.');
    $table=$role==='student'?'students':'advisers'; $token=bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        [$actor,$ownAdviser]=onboarding_inviter($pdo,$actor,$role);
        $rows=lifecycle_rows($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]);
        if (!$rows) throw new AccountLifecycleNotFound('Pending profile not found.');
        $r=$rows[0];
        if ($ownAdviser && (int)$r['adviser_id']!==(int)$ownAdviser['id']) throw new AccountLifecycleForbidden('This Student is outside your assignment scope.');
        if ($r['archived_at']!==null || $r['profile_completed_at']!==null) throw new AccountLifecycleConflict('Resend requires an active Pending profile.');
        $login=lifecycle_resolve_login($pdo,$r,$role);
        if ($login['status']!=='Active' || $login['email']!==$r['email']) throw new AccountLifecycleConflict('Account ownership requires Admin review.');
        $inv=lifecycle_rows($pdo,'SELECT *,last_sent_at>DATE_SUB(NOW(),INTERVAL 60 SECOND) AS cooling FROM account_invitations WHERE user_id=? FOR UPDATE',[$login['id']]);
        if (!$inv || $inv[0]['accepted_at']!==null) throw new AccountLifecycleConflict('Account setup was already accepted or requires Admin repair. Use password recovery if needed.');
        if ($inv[0]['cooling']) throw new AccountLifecycleConflict('Wait at least 60 seconds between invitation emails.');
        $pdo->prepare('UPDATE account_invitations SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR),last_sent_at=NOW() WHERE user_id=?')
            ->execute([hash('sha256',$token),$login['id']]);
        onboarding_audit($pdo,$actor,'account_invitation_resent',$role,(int)$id);
        $pdo->commit();
    } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['ok'=>true]+onboarding_deliver($pdo,(int)$login['id'],$r['email'],$token);
}

/** Pending Student assignment is staff-owned and independent of identity completion. */
function onboarding_assign(PDO $pdo,array $actor,array $data): array
{
    onboarding_allow_fields($data,['targetId','adviserId']);
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only Admin may reassign a Pending Student.');
    $id=$data['targetId']??null;
    if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id)) throw new AccountLifecycleValidation('Invalid Student.');
    $pdo->beginTransaction();
    try {
        [$actor]=onboarding_inviter($pdo,$actor,'student');
        $adviser=onboarding_active_adviser($pdo,$data['adviserId']??null);
        $rows=lifecycle_rows($pdo,'SELECT * FROM students WHERE id=? FOR UPDATE',[$id]);
        if (!$rows || $rows[0]['archived_at']!==null || $rows[0]['profile_completed_at']!==null) throw new AccountLifecycleConflict('Refresh the record. Reassignment here requires an active Pending Student.');
        if(function_exists('purge_require_no_pending')) purge_require_no_pending($pdo,'student',(int)$id);
        $pdo->prepare('UPDATE students SET adviser_id=? WHERE id=?')->execute([$adviser,$id]);
        onboarding_audit($pdo,$actor,'pending_student_reassigned','student',(int)$id);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['ok'=>true,'message'=>'Pending Student assignment updated.'];
}

function onboarding_accept(PDO $pdo, array $data): void
{
    onboarding_allow_fields($data,['token','password','confirmPassword']);
    $token=$data['token']??null; $password=$data['password']??null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new AccountLifecycleValidation('Setup link is invalid or expired.');
    if (!is_string($password) || !new_password_is_valid($password) || $password!==($data['confirmPassword']??null)) {
        throw new AccountLifecycleValidation('Use matching passwords of 12–200 characters.');
    }
    // Discovery never grants authority; all rows and the token are checked again under locks.
    $found=lifecycle_rows($pdo,'SELECT u.* FROM account_invitations i JOIN users u ON u.id=i.user_id WHERE i.token_hash=?',[hash('sha256',$token)]);
    if (count($found)!==1) throw new AccountLifecycleValidation('Setup link is invalid or expired.');
    $pdo->beginTransaction();
    try {
        $r=onboarding_profile($pdo,$found[0],true);
        if (!$r || $r['archived_at']!==null || $r['profile_completed_at']!==null) throw new AccountLifecycleConflict('Setup is unavailable for this profile.');
        $login=lifecycle_resolve_login($pdo,$r,$found[0]['role']);
        if ($login['status']!=='Active' || $login['email']!==$r['email']) throw new AccountLifecycleConflict('Setup account is unavailable.');
        $inv=lifecycle_rows($pdo,'SELECT *,expires_at>NOW() AS valid FROM account_invitations WHERE user_id=? FOR UPDATE',[$login['id']]);
        if (!$inv || $inv[0]['accepted_at']!==null || !$inv[0]['valid'] || !is_string($inv[0]['token_hash'])
            || !hash_equals($inv[0]['token_hash'],hash('sha256',$token))) throw new AccountLifecycleValidation('Setup link is invalid or expired.');
        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$login['id']]);
        $pdo->prepare('UPDATE account_invitations SET token_hash=NULL,expires_at=NULL,accepted_at=NOW() WHERE user_id=?')->execute([$login['id']]);
        $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=?')->execute([$login['id']]);
        onboarding_audit($pdo,$login,'account_setup_accepted',$login['role'],(int)$r['id']);
        $pdo->commit();
    } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function onboarding_finish(PDO $pdo, array $user, array $data): array
{
    $role=$user['role']??'';
    if (!in_array($role,['student','adviser'],true)) throw new AccountLifecycleForbidden('Only your own Student/Adviser profile can be completed.');
    onboarding_allow_fields($data,$role==='student'
        ?['action','name','studentId','academicUnitKey','programKey','yearLevel','academicYear','research']
        :['action','name','employeeId','department']);
    $name=onboarding_text($data['name']??null,'Full name',190);
    $identifier=onboarding_text($data[$role==='student'?'studentId':'employeeId']??null,'Institutional ID',100);
    $pdo->beginTransaction();
    try {
        $r=onboarding_profile($pdo,$user,true);
        if (!$r) throw new AccountLifecycleConflict('Profile ownership requires Admin review.');
        if ($r['archived_at']!==null || $r['profile_completed_at']!==null) throw new AccountLifecycleConflict('This profile is archived or already complete.');
        $login=lifecycle_resolve_login($pdo,$r,$role);
        if ((int)$login['id']!==(int)$user['id'] || $login['status']!=='Active' || $login['email']!==$user['email']
            || $login['password_hash']!==$user['password_hash'] || !empty($login['must_change_password'])) {
            throw new AccountLifecycleForbidden('Account changed. Sign in again before completion.');
        }
        if (function_exists('purge_require_no_pending')) purge_require_no_pending($pdo,$role,(int)$r['id']);
        $inv=lifecycle_rows($pdo,'SELECT * FROM account_invitations WHERE user_id=? FOR UPDATE',[$user['id']]);
        if (!$inv || $inv[0]['accepted_at']===null) throw new AccountLifecycleConflict('Secure invitation setup must be accepted first; legacy profiles require Admin repair.');
        onboarding_id_available($pdo,$identifier,$role,(int)$user['id'],(int)$r['id']);
        $before=lifecycle_identity_capture($pdo,$r,$role);
        if ($role==='student') {
            try { $academic=academic_validate($data); } catch(InvalidArgumentException $e) { throw new AccountLifecycleValidation($e->getMessage()); }
            $title=onboarding_text($data['research']??'','Research title',255,false);
            $pdo->prepare('UPDATE students SET student_id=?,full_name=?,course=?,academic_unit_key=?,program_key=?,year_level=?,academic_year=?,research_title=?,profile_completed_at=NOW() WHERE id=?')
                ->execute([$identifier,$name,$academic['course'],$academic['academic_unit_key'],$academic['program_key'],$academic['year_level'],$academic['academic_year'],$title?:null,$r['id']]);
        } else {
            $department=onboarding_text($data['department']??null,'Department',190);
            if (!in_array($department,array_column(academic_catalog()['units'],'label'),true)) throw new AccountLifecycleValidation('Choose a department from the academic catalog.');
            $pdo->prepare('UPDATE advisers SET employee_id=?,full_name=?,department=?,profile_completed_at=NOW() WHERE id=?')->execute([$identifier,$name,$department,$r['id']]);
        }
        $pdo->prepare('UPDATE users SET username=?,ref_id=?,full_name=? WHERE id=?')->execute([$identifier,$identifier,$name,$user['id']]);
        $after=onboarding_profile($pdo,$user,true);
        lifecycle_identity_persist($pdo,$login,$role,$before,lifecycle_identity_capture($pdo,$after,$role));
        onboarding_audit($pdo,$login,'account_profile_completed',$role,(int)$r['id']);
        $pdo->commit();
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) throw new AccountLifecycleConflict('That institutional ID was claimed concurrently. Use your own unique ID or request Admin review.');
        throw $e;
    }
    return ['ok'=>true,'redirect'=>$role==='student'?'student.php':'ierbprog.php'];
}
