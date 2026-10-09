<?php
/** Account ownership and lifecycle provenance. No bootstrap, secrets, or schema writes. */
final class AccountLifecycleConflict extends RuntimeException {}
final class AccountLifecycleForbidden extends RuntimeException {}
final class AccountLifecycleValidation extends RuntimeException {}
final class AccountLifecycleNotFound extends RuntimeException {}
final class AccountLifecycleBadRequest extends RuntimeException {}
final class AccountLifecycleUnavailable extends RuntimeException {}

function lifecycle_decode_body(string $raw): array
{
    try {
        $object=json_decode($raw,false,512,JSON_THROW_ON_ERROR);
        if(!$object instanceof stdClass) throw new AccountLifecycleBadRequest('A JSON object is required.');
        return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    } catch(JsonException $error) {
        throw new AccountLifecycleBadRequest('Malformed JSON request.');
    }
}

function lifecycle_error_status(Throwable $error): int
{
    return match(true) {
        $error instanceof AccountLifecycleBadRequest=>400,
        $error instanceof AccountLifecycleForbidden=>403,
        $error instanceof AccountLifecycleNotFound=>404,
        $error instanceof AccountLifecycleConflict=>409,
        $error instanceof AccountLifecycleValidation=>422,
        $error instanceof AccountLifecycleUnavailable=>503,
        $error instanceof PDOException && (str_starts_with((string)$error->getCode(),'08')
            || in_array((int)($error->errorInfo[1]??0),[1205,1213,2002,2003,2006,2013],true))=>503,
        default=>500,
    };
}

function lifecycle_rows(PDO $pdo, string $sql, array $params = []): array
{
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Caller holds the entity lock. Candidates are locked before ownership is decided. */
function lifecycle_resolve_login(PDO $pdo, array $record, string $type, bool $allowAbsent = false): ?array
{
    $identifier = (string)$record[$type === 'student' ? 'student_id' : 'employee_id'];
    $provenance = lifecycle_rows($pdo, 'SELECT details FROM activity_logs WHERE entity_type=:type AND entity_id=:id
        AND action IN ("account_identity_created","account_identity_changed") ORDER BY id DESC LIMIT 1',
        [':type'=>$type, ':id'=>(string)$record['id']]);
    $known = $provenance ? json_decode($provenance[0]['details'], true) : [];
    $knownUid = (int)($known['login_after'] ?? $known['login_id'] ?? 0);
    $candidates = lifecycle_rows($pdo, 'SELECT id FROM users WHERE email=:email OR username=:identifier OR ref_id=:ref
        OR id=:uid OR id=:known ORDER BY id', [':email'=>$record['email'], ':identifier'=>$identifier,
        ':ref'=>$identifier, ':uid'=>$record['user_id'] ?? 0, ':known'=>$knownUid]);
    $ids=array_column($candidates,'id');
    $rows=$ids ? lifecycle_rows($pdo,'SELECT * FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE',$ids):[];
    if (!$rows && $allowAbsent && empty($record['user_id']) && !$knownUid) return null;
    if (count($rows) !== 1) throw new AccountLifecycleConflict('Login ownership is ambiguous. Request Admin review; retain the record using Archive.');
    $login = $rows[0];
    $explicit = !empty($record['user_id']) && (int)$record['user_id'] === (int)$login['id'];
    $attested = $knownUid && $knownUid === (int)$login['id'];
    $identityMatch = $login['username'] === $identifier || (string)$login['ref_id'] === $identifier;
    if ($login['role'] !== $type || (!empty($record['user_id']) && !$explicit) || ($knownUid && !$attested)
        || (!$explicit && !$attested && !$identityMatch)) {
        throw new AccountLifecycleConflict('Login identity conflicts with this record. Request Admin review; retain the account using Archive.');
    }
    foreach (['students'=>'student', 'advisers'=>'adviser'] as $table=>$role) {
        $field = $role === 'student' ? 'student_id' : 'employee_id';
        $other = lifecycle_rows($pdo, "SELECT id FROM $table WHERE (user_id=:uid OR (email=:email AND :same=1)
            OR ($field=:username AND :same2=1) OR ($field=:ref AND :same3=1)) AND (:role<>:type OR id<>:id) ORDER BY id",
            [':uid'=>$login['id'], ':email'=>$login['email'], ':same'=>(int)($role===$type), ':username'=>$login['username'],
            ':same2'=>(int)($role===$type), ':ref'=>$login['ref_id'], ':same3'=>(int)($role===$type), ':role'=>$role, ':type'=>$type, ':id'=>$record['id']]);
        if ($other) throw new AccountLifecycleConflict('Another record claims this login. Request Admin review; retain the account using Archive.');
    }
    return $login;
}

function lifecycle_identity_capture(PDO $pdo, array $record, string $type): array
{
    $login = lifecycle_resolve_login($pdo, $record, $type, true);
    return ['entity_id'=>(string)$record['id'], 'identifier'=>(string)$record[$type==='student'?'student_id':'employee_id'],
        'full_name'=>(string)$record['full_name'], 'email'=>(string)$record['email'],
        'user_id'=>empty($record['user_id']) ? null : (int)$record['user_id'], 'login_id'=>$login ? (int)$login['id'] : null];
}

function lifecycle_identity_hash(array $identity): string
{
    return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Both identity mutation and this mandatory evidence must use the caller's transaction. */
function lifecycle_identity_persist(PDO $pdo, array $actor, string $type, ?array $before, array $after): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Identity provenance requires a transaction.');
    $changed = $before === null ? [] : array_keys(array_filter($after, fn($value,$key)=>$value!==$before[$key], ARRAY_FILTER_USE_BOTH));
    if ($before !== null && !$changed) return;
    $details = $before === null ? ['login_id'=>$after['login_id']] :
        ['fields'=>$changed, 'login_before'=>$before['login_id'], 'login_after'=>$after['login_id']];
    $q = $pdo->prepare('INSERT INTO activity_logs (user_email,action,details,actor_name,actor_role,entity_type,entity_id,before_value,after_value,created_at)
        VALUES (:email,:action,:details,:name,:role,:type,:id,:before,:after,NOW())');
    $base = [':email'=>$actor['email'], ':name'=>$actor['full_name'], ':role'=>$actor['role'], ':type'=>$type, ':id'=>$after['entity_id']];
    $q->execute($base + [':action'=>$before===null?'account_identity_created':'account_identity_changed',
        ':details'=>json_encode($details,JSON_THROW_ON_ERROR), ':before'=>$before===null?null:lifecycle_identity_hash($before), ':after'=>lifecycle_identity_hash($after)]);
    if ($q->rowCount() !== 1) throw new RuntimeException('Identity provenance was not persisted.');
    foreach ($changed as $field) {
        $q->execute($base + [':action'=>'account_identity_field_changed', ':details'=>$field,
            ':before'=>$before[$field]===null?null:(string)$before[$field], ':after'=>$after[$field]===null?null:(string)$after[$field]]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Identity provenance was not persisted.');
    }
}

/** No retrospective baseline: unproven legacy identities and every identity edit remain archive-only. */
function lifecycle_require_identity_provenance(PDO $pdo, array $record, string $type, ?array $login): void
{
    $rows = lifecycle_rows($pdo, 'SELECT * FROM activity_logs WHERE entity_type=:type AND entity_id=:id
        AND action IN ("account_identity_created","account_identity_changed","account_identity_field_changed") ORDER BY id FOR UPDATE',
        [':type'=>$type, ':id'=>(string)$record['id']]);
    $identity = ['entity_id'=>(string)$record['id'], 'identifier'=>(string)$record[$type==='student'?'student_id':'employee_id'],
        'full_name'=>(string)$record['full_name'], 'email'=>(string)$record['email'],
        'user_id'=>empty($record['user_id'])?null:(int)$record['user_id'], 'login_id'=>$login?(int)$login['id']:null];
    if (count($rows)!==1 || $rows[0]['action']!=='account_identity_created'
        || !hash_equals(lifecycle_identity_hash($identity),(string)$rows[0]['after_value'])) {
        throw new AccountLifecycleConflict('Continuous unchanged identity provenance cannot be established. Retain the account using Archive.');
    }
    if ($login && lifecycle_rows($pdo,'SELECT id FROM activity_logs WHERE entity_type="user" AND entity_id=:uid
        AND action="profile_updated" FOR UPDATE',[':uid'=>(string)$login['id']])) {
        throw new AccountLifecycleConflict('Historical login identity changed. Retain the account using Archive.');
    }
}

function lifecycle_archive_login(PDO $pdo, array $record, string $type): void
{
    $login = lifecycle_resolve_login($pdo,$record,$type);
    $pdo->prepare('UPDATE users SET status="Inactive" WHERE id=:id AND role=:role')->execute([':id'=>$login['id'], ':role'=>$type]);
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=:id')->execute([':id'=>$login['id']]);
}
