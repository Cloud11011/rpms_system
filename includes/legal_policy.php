<?php
/** Versioned legal content and governance. Pure services: no config, session or connection bootstrap. */
require_once __DIR__.'/public_urls.php';

class LegalPolicyError extends RuntimeException
{
    public function __construct(string $message, public int $status=422) { parent::__construct($message); }
}

function legal_escape(mixed $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Structured text only: markup, URL schemes and event attributes are always literal escaped text. */
function legal_render(string $source): string
{
    $placeholders=require __DIR__.'/legal_placeholders.php';
    $source=strtr($source,array_filter($placeholders, 'is_string'));
    $html=''; $list=false;
    foreach (explode("\n",$source) as $line) {
        $line=trim($line);
        if (str_starts_with($line,'- ')) {
            if (!$list) { $html.='<ul>'; $list=true; }
            $html.='<li>'.legal_escape(substr($line,2)).'</li>'; continue;
        }
        if ($list) { $html.='</ul>'; $list=false; }
        if ($line==='') continue;
        if (str_starts_with($line,'## ')) $html.='<h3>'.legal_escape(substr($line,3)).'</h3>';
        elseif (str_starts_with($line,'# ')) $html.='<h2>'.legal_escape(substr($line,2)).'</h2>';
        else $html.='<p>'.legal_escape($line).'</p>';
    }
    return $html.($list?'</ul>':'');
}

function legal_rows(PDO $pdo, string $sql, array $params=[]): array
{
    $q=$pdo->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function legal_verify_content(array $version): void
{
    if (!hash_equals((string)$version['content_sha256'],hash('sha256',(string)$version['content']))) {
        throw new LegalPolicyError('The legal policy could not be verified. Please try again later.',503);
    }
}

function legal_current(PDO $pdo, bool $lock=false): array
{
    if ($lock) legal_rows($pdo,'SELECT id,current_version_id FROM legal_policies ORDER BY id FOR UPDATE');
    $rows=legal_rows($pdo,"SELECT v.* FROM legal_policies p JOIN legal_policy_versions v
        ON v.id=p.current_version_id AND v.policy_id=p.id
        JOIN legal_policy_approvals a ON a.version_id=v.id AND a.decision='approved'
        WHERE v.state='published' AND v.published_at IS NOT NULL ORDER BY p.id");
    $result=[];
    foreach ($rows as $v) { legal_verify_content($v); $result[$v['policy_id']]=$v; }
    if (array_keys($result)!==['privacy','terms']) throw new LegalPolicyError('Current legal policies are unavailable. Please try again later.',503);
    return $result;
}

function legal_snapshot(array $current): string
{
    return hash('sha256',implode('|',array_map(static fn($v)=>$v['id'].':'.$v['content_sha256'],$current)));
}

function legal_outstanding(PDO $pdo, int $userId, ?array $current=null): array
{
    $current??=legal_current($pdo);
    $accepted=array_column(legal_rows($pdo,'SELECT policy_version_id FROM user_policy_acceptances WHERE user_id=?',[$userId]),'policy_version_id');
    return array_filter($current,static fn($v)=>!in_array((int)$v['id'],array_map('intval',$accepted),true));
}

function legal_subject(PDO $pdo, array $subject): array
{
    $rows=legal_rows($pdo,'SELECT * FROM users WHERE id=? FOR UPDATE',[$subject['id']??0]);
    $user=$rows[0]??null;
    if (!$user || strcasecmp($user['status'],'Active')!==0 || !empty($user['must_change_password'])
        || !hash_equals((string)($subject['password_hash']??''),(string)$user['password_hash'])) {
        throw new LegalPolicyError('Your account security state changed. Sign in again before continuing.',403);
    }
    return $user;
}

function legal_accept(PDO $pdo, array $subject, array $data): void
{
    if (array_diff(array_keys($data),['privacy','terms','snapshot'])) throw new LegalPolicyError('Unexpected acceptance fields.');
    $pdo->beginTransaction();
    try {
        $subject=legal_subject($pdo,$subject);
        $current=legal_current($pdo,true);
        if (!is_string($data['snapshot']??null) || !hash_equals(legal_snapshot($current),$data['snapshot'])) {
            throw new LegalPolicyError('The policies changed while this page was open. Reload and review the current versions.',409);
        }
        $outstanding=legal_outstanding($pdo,(int)$subject['id'],$current);
        foreach ($outstanding as $policy=>$version) {
            if (($data[$policy]??false)!==true) throw new LegalPolicyError('Please explicitly acknowledge Privacy and agree to Terms where required.');
        }
        foreach ($outstanding as $version) {
            $pdo->prepare('INSERT INTO user_policy_acceptances(user_id,policy_version_id) VALUES (?,?)')->execute([$subject['id'],$version['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Exactly the Admin eligibility required by sensitive Admin operations, independent of legal completion. */
function legal_admin_eligible(array $user): bool
{
    return ($user['role']??'')==='admin' && strcasecmp((string)($user['status']??''),'Active')===0
        && empty($user['must_change_password']) && trim((string)($user['username']??''))!==''
        && trim((string)($user['full_name']??''))!=='' && filter_var($user['email']??'',FILTER_VALIDATE_EMAIL)!==false
        && !empty(password_get_info((string)($user['password_hash']??''))['algo']);
}

function legal_audit(PDO $pdo, array $actor, string $action, array $version): void
{
    // Mandatory transactional evidence. Never copy policy body, password or content hash into audit.
    $q=$pdo->prepare('INSERT INTO activity_logs(user_email,action,actor_name,actor_role,entity_type,entity_id,details)
        VALUES (?,?,?,?,?,?,?)');
    $q->execute([$actor['email'],$action,$actor['full_name'],'admin','legal_policy_version',(string)$version['id'],$version['policy_id']]);
    if ($q->rowCount()!==1) throw new RuntimeException('Legal governance audit was not persisted.');
}

function legal_text(mixed $value, string $label, int $limit): string
{
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || trim($value)===''
        || mb_strlen($value)>$limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) {
        throw new LegalPolicyError($label.' is required and must fit the stated length limit.');
    }
    return str_replace(["\r\n","\r"],"\n",$value);
}

function legal_manage(PDO $pdo, array $actor, array $data): int
{
    $action=$data['action']??''; $policy=$data['policy']??'';
    if (!in_array($action,['create','save','submit','approve','reject'],true) || !in_array($policy,['privacy','terms'],true)) throw new LegalPolicyError('Invalid legal management action.');
    $allowed=['action','policy','versionId','title','content','summary','password','reviewAcknowledged','soleAcknowledged','reason'];
    if (array_diff(array_keys($data),$allowed)) throw new LegalPolicyError('Unexpected legal management fields.');
    if ($pdo->inTransaction()) throw new LogicException('Legal governance owns its transaction.');
    // A full PRIMARY locking scan under SERIALIZABLE locks account insertion gaps as well as
    // existing rows. Registration, status/role changes, archive and purge cannot race the count.
    // Lock accounts first, then both policies in a fixed order (also used by acceptance).
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $pdo->beginTransaction();
    try {
        $users=legal_rows($pdo,'SELECT * FROM users FORCE INDEX(PRIMARY) ORDER BY id FOR UPDATE');
        $eligible=array_filter($users,'legal_admin_eligible'); $lockedActor=null;
        foreach ($eligible as $user) if ((int)$user['id']===(int)($actor['id']??0)) $lockedActor=$user;
        if (!$lockedActor || !hash_equals((string)($actor['password_hash']??''),$lockedActor['password_hash'])) throw new LegalPolicyError('Only an active eligible Admin may manage policies.',403);
        $actor=$lockedActor;
        $current=legal_current($pdo,true);
        if (legal_outstanding($pdo,(int)$actor['id'],$current)) throw new LegalPolicyError('Acknowledge the current policies before managing legal content.',403);
        if ($action==='create') {
            if (legal_rows($pdo,"SELECT id FROM legal_policy_versions WHERE policy_id=? AND state IN ('draft','pending')",[$policy])) throw new LegalPolicyError('This policy already has an open draft or pending review.',409);
            $q=$pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM legal_policy_versions WHERE policy_id=?'); $q->execute([$policy]); $number=(int)$q->fetchColumn();
            $base=$current[$policy];
            $pdo->prepare('INSERT INTO legal_policy_versions(policy_id,version_number,title,content,content_sha256,creator_user_id) VALUES (?,?,?,?,?,?)')->execute([$policy,$number,$base['title'],$base['content'],$base['content_sha256'],$actor['id']]);
            $id=(int)$pdo->lastInsertId(); $version=['id'=>$id,'policy_id'=>$policy];
        } else {
            $id=$data['versionId']??null;
            if ((!is_int($id)&&!is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$id)) throw new LegalPolicyError('Choose a valid policy version.');
            $versions=legal_rows($pdo,'SELECT * FROM legal_policy_versions WHERE id=? AND policy_id=? FOR UPDATE',[$id,$policy]); $version=$versions[0]??null;
            if (!$version) throw new LegalPolicyError('Policy version not found.',404);
            legal_verify_content($version);
            if (in_array($action,['save','submit'],true)) {
                if ($version['state']!=='draft') throw new LegalPolicyError('Only drafts can be edited or submitted.',409);
                if ($version['creator_user_id']!==null && (int)$version['creator_user_id']!==(int)$actor['id']) throw new LegalPolicyError('Only the draft creator may edit or submit this draft.',403);
                if ($action==='save') {
                    $title=legal_text($data['title']??null,'Title',190); $content=legal_text($data['content']??null,'Policy source',262144);
                    $summary=$data['summary']??'';
                    if (!is_string($summary) || mb_strlen($summary)>500 || preg_match('/[\x00-\x1f\x7f]/',$summary)) throw new LegalPolicyError('Change summary must be at most 500 characters.');
                    $pdo->prepare('UPDATE legal_policy_versions SET title=?,content=?,content_sha256=?,change_summary=?,creator_user_id=? WHERE id=? AND state=\'draft\'')->execute([$title,$content,hash('sha256',$content),trim($summary),$actor['id'],$id]);
                } else {
                    legal_text($version['change_summary'],'Change summary',500);
                    $pdo->prepare("UPDATE legal_policy_versions SET state='pending',submitted_at=NOW(),creator_user_id=? WHERE id=? AND state='draft'")->execute([$actor['id'],$id]);
                }
            } else {
                if ($version['state']!=='pending') throw new LegalPolicyError('Only a pending version can be reviewed.',409);
                $self=(int)$version['creator_user_id']===(int)$actor['id'];
                $sole=count($eligible)===1;
                if ($action==='reject') {
                    if ($self) throw new LegalPolicyError('A different active Admin must reject this candidate.',403);
                    $reason=legal_text($data['reason']??null,'Rejection reason',500);
                    $pdo->prepare("INSERT INTO legal_policy_approvals(version_id,reviewer_user_id,decision,approval_mode,reason) VALUES (?,?,'rejected','independent',?)")->execute([$id,$actor['id'],$reason]);
                    $pdo->prepare("UPDATE legal_policy_versions SET state='rejected' WHERE id=?")->execute([$id]);
                } else {
                    if (!$sole && $self) throw new LegalPolicyError('Another active Admin must approve your draft.',403);
                    if (($data['reviewAcknowledged']??false)!==true || ($sole && ($data['soleAcknowledged']??false)!==true)) throw new LegalPolicyError('Explicit review and applicable sole-Admin acknowledgments are required.');
                    if (!is_string($data['password']??null) || strlen($data['password'])>200 || !password_verify($data['password'],$actor['password_hash'])) throw new LegalPolicyError('Your current password is incorrect.');
                    $pdo->prepare("INSERT INTO legal_policy_approvals(version_id,reviewer_user_id,decision,approval_mode) VALUES (?,?,'approved',?)")->execute([$id,$actor['id'],$sole?'sole_admin':'independent']);
                    $pdo->prepare("UPDATE legal_policy_versions SET state='published',published_at=NOW() WHERE id=? AND state='pending'")->execute([$id]);
                    $pdo->prepare('UPDATE legal_policies SET current_version_id=? WHERE id=?')->execute([$id,$policy]);
                    $action=$sole?'sole_admin_published':'independent_published';
                }
            }
        }
        legal_audit($pdo,$actor,'legal_'.$action,$version);
        $pdo->commit(); return (int)$id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
