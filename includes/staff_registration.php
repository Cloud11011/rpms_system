<?php
/** Frozen-v10 Staff Code services. No bootstrap, migration, mail or notification transport. */
require_once __DIR__.'/account_onboarding.php';
require_once __DIR__.'/legal_policy.php';

class StaffRegistrationError extends RuntimeException {}
const STAFF_CODE_UNAVAILABLE = 'The Staff Registration Code is invalid, expired, or unavailable.';

/** Same Admin primary-key lock order as lifecycle and legal governance. */
function staff_lock_admins(PDO $pdo): array
{
    $ids=array_column(lifecycle_rows($pdo,"SELECT id FROM users WHERE role='admin' ORDER BY id"),'id');
    $rows=$ids?lifecycle_rows($pdo,'SELECT * FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE',$ids):[];
    return array_column($rows,null,'id');
}

function staff_row(PDO $pdo): ?array
{
    return lifecycle_rows($pdo,'SELECT *,expires_at>NOW() AS unexpired FROM staff_registration_code WHERE slot=1 FOR UPDATE')[0]??null;
}

function staff_audit(PDO $pdo, ?array $actor, string $action, array $row): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Staff audit requires a transaction.');
    $q=$pdo->prepare('INSERT INTO activity_logs(user_email,action,actor_name,actor_role,entity_type,entity_id,details) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$actor['email']??null,'staff_code_'.$action,$actor['full_name']??null,$actor['role']??null,
        'staff_registration_code',$row['generation_id'],'User ID '.(int)($actor['id']??0)]);
    if ($q->rowCount()!==1) throw new RuntimeException('Staff audit could not be persisted.');
}

function staff_normalize(PDO $pdo, ?array $row, array $admins): ?array
{
    if (!$row) return null;
    $state=null;
    if ($row['state']==='pending' && !legal_admin_eligible($admins[$row['creator_user_id']??0]??[])) $state='invalidated';
    if ($row['state']==='acknowledged' && (!$row['unexpired'] || !$row['verifier_hash'])) $state='expired';
    if ($state) {
        $pdo->prepare('UPDATE staff_registration_code SET state=?,verifier_hash=NULL WHERE slot=1')->execute([$state]);
        staff_audit($pdo,null,$state,$row); $row=staff_row($pdo);
    }
    return $row;
}

function staff_safe_row(?array $row): ?array
{
    return $row?array_intersect_key($row,array_flip(['generation_id','state','created_at','acknowledged_at','expires_at','consumed_at'])):null;
}

/** Called inside the existing Admin lifecycle transaction, after its Admin locks. */
function staff_pending_creator_departed(PDO $pdo, array $actor): void
{
    $row=staff_row($pdo);
    if ($row && $row['state']==='pending' && (int)$row['creator_user_id']===(int)$actor['id']) {
        $pdo->exec("UPDATE staff_registration_code SET state='invalidated',verifier_hash=NULL WHERE slot=1 AND state='pending'");
        staff_audit($pdo,$actor,'invalidated',$row);
    }
}

function staff_admin(array $admins, array $actor, PDO $pdo): array
{
    $user=$admins[$actor['id']??0]??[];
    if (!legal_admin_eligible($user) || !hash_equals((string)($actor['password_hash']??''),(string)($user['password_hash']??''))
        || legal_outstanding($pdo,(int)$user['id'])!==[]) throw new StaffRegistrationError('Your Admin security state changed. Reload and complete the current security requirements.');
    return $user;
}

function staff_overview(PDO $pdo, array $actor): ?array
{
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $admins=staff_lock_admins($pdo); staff_admin($admins,$actor,$pdo);
        $row=staff_normalize($pdo,staff_row($pdo),$admins); $pdo->commit(); return staff_safe_row($row);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** A session signing key is acknowledgement material only, never the Staff Code. */
function staff_proof(string $generation, int $uid, string $context, string $key): string
{
    $payload=bin2hex(json_encode([$generation,$uid,$context,time(),bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR));
    return $payload.'.'.hash_hmac('sha256',$payload,$key);
}

function staff_verify_proof(mixed $proof, string $generation, int $uid, string $context, string $key): bool
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key) || !preg_match('/\A[a-f0-9]{32}\z/',$context)
        || !is_string($proof) || strlen($proof)>1500 || !preg_match('/\A([a-f0-9]+)\.([a-f0-9]{64})\z/',$proof,$parts)
        || strlen($parts[1])%2 || !hash_equals(hash_hmac('sha256',$parts[1],$key),$parts[2])) return false;
    try { $data=json_decode(hex2bin($parts[1]),true,8,JSON_THROW_ON_ERROR); } catch (Throwable $e) { return false; }
    return is_array($data) && count($data)===5 && $data[0]===$generation && $data[1]===$uid && $data[2]===$context
        && is_int($data[3]) && $data[3]<=time() && $data[3]>=time()-600;
}

/** Only an explicit POST controller calls this. Expected generation prevents stale replacements. */
function staff_manage(PDO $pdo, array $actor, array $data, string $context, string $key): array
{
    $action=$data['action']??null;
    if (!in_array($action,['generate','acknowledge','invalidate'],true) || !is_string($data['generation_id']??null)) throw new StaffRegistrationError('Invalid Staff Registration action.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $admins=staff_lock_admins($pdo); $actor=staff_admin($admins,$actor,$pdo);
        $row=staff_normalize($pdo,staff_row($pdo),$admins);
        if (!hash_equals($row['generation_id']??'',$data['generation_id'])) throw new StaffRegistrationError('The code changed. Reload before continuing.');
        if ($action==='generate') {
            if ($row && $row['state']==='acknowledged') throw new StaffRegistrationError('Invalidate the active code before generating a replacement.');
            if ($row && $row['state']==='pending') staff_audit($pdo,$actor,'invalidated',$row);
            // 160 random bits, grouped hexadecimal; case is deliberately canonicalized on input.
            $code=implode('-',str_split(strtoupper(bin2hex(random_bytes(20))),5));
            $generation=bin2hex(random_bytes(16)); $hash=password_hash($code,PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO staff_registration_code(slot,generation_id,verifier_hash,state,creator_user_id) VALUES (1,?,?,'pending',?)
                ON DUPLICATE KEY UPDATE generation_id=VALUES(generation_id),verifier_hash=VALUES(verifier_hash),state='pending',creator_user_id=VALUES(creator_user_id),
                created_at=NOW(),acknowledged_at=NULL,expires_at=NULL,consumed_at=NULL,consumed_by_user_id=NULL")->execute([$generation,$hash,$actor['id']]);
            $row=staff_row($pdo); staff_audit($pdo,$actor,'generated',$row);
            $proof=staff_proof($generation,(int)$actor['id'],$context,$key);
            $pdo->commit(); return ['row'=>staff_safe_row($row),'code'=>$code,'proof'=>$proof];
        }
        if (!$row || !in_array($row['state'],['pending','acknowledged'],true)) throw new StaffRegistrationError('This code is no longer available for that action.');
        if (($data['confirmed']??null)!=='1') throw new StaffRegistrationError('Please explicitly confirm this action.');
        if ($action==='acknowledge') {
            if ($row['state']!=='pending' || (int)$row['creator_user_id']!==(int)$actor['id'] || !$row['verifier_hash']
                || !staff_verify_proof($data['proof']??null,$row['generation_id'],(int)$actor['id'],$context,$key)) {
                throw new StaffRegistrationError('Confirmation is unavailable. Generate a replacement if the original page was lost or timed out.');
            }
            $pdo->exec("UPDATE staff_registration_code SET state='acknowledged',acknowledged_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR) WHERE slot=1 AND state='pending'");
            staff_audit($pdo,$actor,'acknowledged',$row);
        } else {
            $pdo->exec("UPDATE staff_registration_code SET state='invalidated',verifier_hash=NULL WHERE slot=1");
            staff_audit($pdo,$actor,'invalidated',$row);
        }
        $row=staff_row($pdo); $pdo->commit(); return ['row'=>staff_safe_row($row)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof StaffRegistrationError) throw $e;
        throw new StaffRegistrationError('Staff Registration could not complete this action. Reload and try again.');
    }
}

function staff_code_input(mixed $input): ?string
{
    if (!is_string($input) || strlen($input)>100 || !preg_match('/\A[0-9a-fA-F]{5}(?:-[0-9a-fA-F]{5}){7}\z/',trim($input))) return null;
    return strtoupper(trim($input));
}

function staff_account_fields(array $data): array
{
    $result=[];
    foreach (['employee_id'=>100,'fullname'=>190,'email'=>190] as $field=>$max) {
        $v=$data[$field]??null;
        if (!is_string($v) || !mb_check_encoding($v,'UTF-8') || trim($v)==='' || mb_strlen($v)>$max || preg_match('/[\x00-\x1f\x7f]/',$v)) throw new StaffRegistrationError('Complete all fields using valid text within the length limits.');
        $result[$field]=trim($v);
    }
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/',$result['employee_id'])) throw new StaffRegistrationError('Employee ID must use letters, numbers, periods, underscores or hyphens.');
    $result['email']=strtolower($result['email']);
    if (!filter_var($result['email'],FILTER_VALIDATE_EMAIL) || !is_allowed_email_domain($result['email'])) throw new StaffRegistrationError('Use an institutional email address from '.allowed_email_domains_hint().'.');
    $password=$data['password']??null;
    if (!is_string($password) || !mb_check_encoding($password,'UTF-8') || !new_password_is_valid($password)) throw new StaffRegistrationError('Password must be at least 12 characters and at most 200 bytes.');
    if ($password!==($data['confirm_password']??null)) throw new StaffRegistrationError('Password and confirmation do not match.');
    $result['password_hash']=prism_password_hash($password); return $result;
}

/** User insert, mandatory audit, and conditional consumption share the singleton transaction. */
function staff_register(PDO $pdo, array $data): array
{
    $code=staff_code_input($data['registration_code']??null);
    if ($code===null) throw new StaffRegistrationError(STAFF_CODE_UNAVAILABLE);
    $fields=staff_account_fields($data);
    foreach (['employee_id','fullname','email'] as $field) {
        if (str_contains(strtoupper($fields[$field]),$code)) throw new StaffRegistrationError('Keep the Staff Registration Code only in its dedicated field.');
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $admins=staff_lock_admins($pdo); $row=staff_normalize($pdo,staff_row($pdo),$admins);
        if (!$row || $row['state']!=='acknowledged' || !$row['unexpired'] || !$row['verifier_hash'] || !password_verify($code,$row['verifier_hash'])) {
            // Persist safe lazy expiry / lost-creator invalidation even though registration fails.
            $pdo->commit(); throw new StaffRegistrationError(STAFF_CODE_UNAVAILABLE);
        }
        onboarding_email_available($pdo,$fields['email']);
        $id=$fields['employee_id'];
        if (lifecycle_rows($pdo,'SELECT id FROM users WHERE username=? OR ref_id=? LIMIT 1',[$id,$id])
            || lifecycle_rows($pdo,'SELECT id FROM students WHERE student_id=? LIMIT 1',[$id])
            || lifecycle_rows($pdo,'SELECT id FROM advisers WHERE employee_id=? LIMIT 1',[$id])) throw new StaffRegistrationError('That employee ID is already registered.');
        $pdo->prepare("INSERT INTO users(username,password_hash,role,status,full_name,email,ref_id,must_change_password) VALUES (?,?,'admin','Active',?,?,?,0)")
            ->execute([$id,$fields['password_hash'],$fields['fullname'],$fields['email'],$id]);
        $uid=(int)$pdo->lastInsertId();
        // NOW() is reevaluated after validation/insertion: a code expiring while this lock is held cannot win.
        $q=$pdo->prepare("UPDATE staff_registration_code SET state='consumed',consumed_at=NOW(),consumed_by_user_id=?,verifier_hash=NULL
            WHERE slot=1 AND generation_id=? AND state='acknowledged' AND expires_at>NOW() AND verifier_hash IS NOT NULL");
        $q->execute([$uid,$row['generation_id']]);
        if ($q->rowCount()!==1) throw new StaffRegistrationError(STAFF_CODE_UNAVAILABLE);
        $user=lifecycle_rows($pdo,'SELECT * FROM users WHERE id=?',[$uid])[0];
        staff_audit($pdo,$user,'consumed',$row); staff_audit($pdo,$user,'admin_registered',$row);
        $pdo->commit(); return $user;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof StaffRegistrationError) throw $e;
        if ($e instanceof AccountLifecycleConflict) throw new StaffRegistrationError('That email already has an account or profile.');
        // Never expose exception messages, SQL parameters, verifier, password or submitted code.
        throw new StaffRegistrationError('This account could not be created. Please review the account details and try again.');
    }
}
