<?php
/** Coordinated individual progress decisions. No group stage or schema writes. */
require_once __DIR__.'/ierb_progress.php';
require_once __DIR__.'/research_groups.php';

const IERB_GROUP_CONFIRM_SECONDS = 600;
const IERB_GROUP_CHANGED = 'Research group information changed after your preview. Refresh and review the group before continuing.';

function ierb_group_admin(array $actor): void
{
    if (($actor['role'] ?? '') !== 'admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may advance research groups.');
}

/** Preserve nulls, case and whitespace: display labels never identify membership. */
function ierb_group_identity(array $row): array
{
    return array_intersect_key($row, array_flip(['research_group','academic_unit_key','program_key','year_level','academic_year']));
}

function ierb_group_key(array $identity): string
{
    // Fixed column order, independent of a SELECT's projection order.
    $values=[];
    foreach (['research_group','academic_unit_key','program_key','year_level','academic_year'] as $field) $values[]=$identity[$field] ?? null;
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}

function ierb_group_label(array $identity): string
{
    $catalog=academic_catalog();
    return implode(' / ',[
        (string)$identity['research_group'],
        $catalog['units'][$identity['academic_unit_key'] ?? '']['label'] ?? ($identity['academic_unit_key'] ?: 'Graduate'),
        $catalog['programs'][$identity['program_key'] ?? '']['label'] ?? ($identity['program_key'] ?: 'Unspecified program'),
        $identity['year_level'] ?: 'Graduate year', $identity['academic_year'] ?: 'Unspecified academic year',
    ]);
}

/** Includes legacy, archived and incomplete associations so none disappear silently. */
function ierb_group_options(PDO $pdo): array
{
    $rows=$pdo->query('SELECT research_group,academic_unit_key,program_key,year_level,academic_year FROM students
        WHERE research_group IS NOT NULL AND research_group <> \'\' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $groups=[];
    foreach ($rows as $row) {
        $key=ierb_group_key($row);
        if (!isset($groups[$key])) $groups[$key]=['key'=>$key,'identity'=>$row,'label'=>ierb_group_label($row),
            'standard'=>research_group_is_standard($row),'memberCount'=>0];
        $groups[$key]['memberCount']++;
    }
    uasort($groups,static fn($a,$b)=>strnatcasecmp($a['label'],$b['label']));
    return array_values($groups);
}

function ierb_group_live_admin(PDO $pdo, array $actor): array
{
    ierb_group_admin($actor);
    $live=current_workflow_actor($pdo,$actor);
    if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible active RPMS Administrator is required.');
    return $live;
}

function ierb_group_list(PDO $pdo, array $actor): array
{
    ierb_group_admin($actor);
    $pdo->beginTransaction();
    try {
        ierb_group_live_admin($pdo,$actor);
        $groups=ierb_group_options($pdo);
        $pdo->commit();
        return ['ok'=>true,'groups'=>$groups];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function ierb_group_rows(PDO $pdo, array $identity): array
{
    $where=[]; $params=[];
    foreach (['research_group','academic_unit_key','program_key','year_level','academic_year'] as $field) {
        $where[]='BINARY '.$field.' <=> BINARY ?'; $params[]=$identity[$field] ?? null;
    }
    $q=$pdo->prepare('SELECT * FROM students WHERE '.implode(' AND ',$where).' ORDER BY id');
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** The existing eligibility gate also locks live login ownership and lifecycle state. */
function ierb_group_inspect(PDO $pdo, array $actor, array $identity): array
{
    $rows=ierb_group_rows($pdo,$identity); $members=[]; $fingerprints=[]; $reasons=[];
    if (!research_group_is_standard($identity)) $reasons[]='Choose a valid standardized research group. Legacy or inconsistent academic identifiers require record review.';
    if (count($rows)<2) $reasons[]='At least two eligible Students are required for Research Group Advancement.';
    // Bound work, history and notification intents in a single administrative transaction.
    if (count($rows)>250) throw new AccountLifecycleValidation('This group exceeds the 250-member transaction limit. Request record review.');
    foreach ($rows as $row) {
        $id=(int)$row['id']; $issues=[]; $login=[];
        if (!empty($row['archived_at'])) $issues[]='Student record is archived.';
        if (empty($row['profile_completed_at'])) $issues[]='Student profile is incomplete.';
        try {
            $row=current_workflow_student($pdo,$id,$actor);
            ierb_v14_guard_legacy_write($pdo,$id);
            if (ierb_group_key(ierb_group_identity($row))!==ierb_group_key($identity)) throw new AccountLifecycleConflict(IERB_GROUP_CHANGED);
            $login=lifecycle_resolve_login($pdo,$row,'student');
            if (!empty($login['must_change_password']) || legal_outstanding($pdo,(int)$login['id'])) {
                $issues[]='Student account setup or legal acceptance is incomplete.';
            }
            if (trim((string)$row['full_name'])==='' || trim((string)$row['student_id'])==='' || !filter_var($row['email'],FILTER_VALIDATE_EMAIL)) {
                $issues[]='Student profile identity is incomplete.';
            }
            // Required Adviser notice intent must be possible before offering confirmation.
            $assigned=student_with_adviser($pdo,$id);
            if (!empty($assigned['adviser_pk'])) {
                $recipient=['type'=>'adviser','id'=>(int)$assigned['adviser_pk'],'email'=>$assigned['adviser_email'],'name'=>$assigned['adviser_name']];
                try {
                    if (!notification_lock_recipients($pdo,[$recipient])) throw new NotificationLifecycleError('Assigned Adviser profile is unavailable.');
                    notification_recipient_user($pdo,'adviser',$recipient['id'],(string)$recipient['email']);
                } catch (NotificationLifecycleError $e) { $issues[]='The assigned Adviser notification account requires review before this group decision.'; }
            }
        } catch (AccountLifecycleConflict $e) { $issues[]=$e->getMessage(); }
        if (!in_array($row['stage'],STAGE_SEQUENCE,true)) $issues[]='The official stage is invalid.';
        if (!in_array($row['status'],['On Track','Pending','Delayed'],true)) $issues[]='The progress status is invalid.';
        $version=ierb_progress_version($pdo,$id);
        $members[]=['id'=>$id,'studentId'=>$row['student_id'],'name'=>$row['full_name'],'stage'=>$row['stage'],
            'stageLabel'=>stage_label($row['stage']),'status'=>$row['status'],'progressVersion'=>$version,
            'eligible'=>!$issues,'issues'=>$issues];
        // Private server snapshot: credentials and profile fields are never returned to the browser.
        $fingerprints[(string)$id]=hash('sha256',json_encode([$row,$login,$version],JSON_THROW_ON_ERROR));
    }
    ierb_group_live_admin($pdo,$actor);
    $stages=array_values(array_unique(array_column($members,'stage')));
    $current=count($stages)===1 ? $stages[0] : null;
    if (count($stages)>1) $reasons[]='Group members have different official IERB stages. Review their records before using Group Advancement.';
    if ($current==='Completed') $reasons[]='This research group has reached the final IERB stage.';
    if (array_filter($members,static fn($m)=>!$m['eligible'])) $reasons[]='Review the ineligible associated Student records before advancing the entire group.';
    $next=$current!==null && in_array($current,STAGE_SEQUENCE,true) ? next_stage($current) : null;
    return ['identity'=>$identity,'label'=>ierb_group_label($identity),'memberCount'=>count($members),'members'=>$members,
        'currentStage'=>$current,'currentStageLabel'=>$current===null ? null : stage_label($current),
        'nextStage'=>$next,'nextStageLabel'=>$next===null ? null : stage_label($next),
        'eligible'=>!$reasons,'reasons'=>$reasons,'fingerprints'=>$fingerprints];
}

function ierb_group_preview(PDO $pdo, array $actor, mixed $key): array
{
    ierb_group_admin($actor);
    if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D',$key)) throw new AccountLifecycleValidation('Choose a research group from the list.');
    // Each preview read sees committed history after any Student-lock wait.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        // Resolve the browser's opaque selector against authoritative stored identities.
        $groups=array_column(ierb_group_options($pdo),null,'key');
        if (!isset($groups[$key])) throw new AccountLifecycleConflict('This research group is unavailable. Refresh the group list.');
        $preview=ierb_group_inspect($pdo,$actor,$groups[$key]['identity']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $token=null; $expires=time()+IERB_GROUP_CONFIRM_SECONDS;
    if ($preview['eligible']) {
        foreach ($_SESSION['ierb_group_confirmations'] ?? [] as $old=>$item) if ($item['expires']<=time()) unset($_SESSION['ierb_group_confirmations'][$old]);
        $token=bin2hex(random_bytes(24));
        $_SESSION['ierb_group_confirmations'][$token]=['actor'=>(string)$actor['id'],'generation'=>prism_session_generation(),
            'expires'=>$expires,'identity'=>$preview['identity'],'fingerprints'=>$preview['fingerprints']];
        while (count($_SESSION['ierb_group_confirmations'])>8) array_shift($_SESSION['ierb_group_confirmations']);
    }
    unset($preview['fingerprints']);
    return ['ok'=>true]+$preview+['confirmationToken'=>$token,'expiresAt'=>$token===null ? null : $expires];
}

function ierb_group_confirmation(array $actor, mixed $token): array
{
    ierb_group_admin($actor);
    $item=is_string($token) && preg_match('/\A[a-f0-9]{48}\z/D',$token) ? ($_SESSION['ierb_group_confirmations'][$token] ?? null) : null;
    if (!$item || $item['actor']!==(string)$actor['id'] || !hash_equals($item['generation'],prism_session_generation()) || $item['expires']<=time()) {
        throw new AccountLifecycleConflict('This confirmation expired, was canceled, or was already used. Refresh and review the group.');
    }
    return $item;
}

function ierb_group_cancel(array $actor, mixed $token): array
{
    ierb_group_confirmation($actor,$token);
    unset($_SESSION['ierb_group_confirmations'][$token]);
    return ['ok'=>true];
}

/** Owns one all-or-none transaction. Single-student decisions keep their existing contract. */
function ierb_group_advance(PDO $pdo, array $actor, array $data): array
{
    ierb_group_admin($actor);
    if (array_diff(array_keys($data),['confirmationToken','reason','confirmed'])) throw new AccountLifecycleValidation('Only the reviewed confirmation and a reason may be submitted.');
    $item=ierb_group_confirmation($actor,$data['confirmationToken'] ?? null);
    $reason=is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
    if (($data['confirmed'] ?? null)!==true) throw new AccountLifecycleValidation('Explicit group confirmation is required.');
    if (!mb_check_encoding($reason,'UTF-8') || mb_strlen($reason)<5 || mb_strlen($reason)>500 || !preg_match('/[\p{L}\p{N}]/u',$reason)) {
        throw new AccountLifecycleValidation('Explain this decision in 5–500 characters.');
    }
    if ($pdo->inTransaction()) throw new LogicException('Research Group Advancement owns its transaction.');
    // Consume before attempting a write, including conflicts/failures. Never retry a decision automatically.
    unset($_SESSION['ierb_group_confirmations'][$data['confirmationToken']]);
    $wait=(int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $ids=[]; $correlation=bin2hex(random_bytes(12));
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        // v11 has no cohort/group index. An explicit PRIMARY next-key range lock is the
        // phantom fence: it also covers inserts and moves from other cohorts, even from
        // existing writers that do not acquire application advisory locks. Lock in id order
        // BEFORE live users/advisers, as in individual progress and lifecycle operations.
        $fence=$pdo->query('SELECT id FROM students FORCE INDEX (PRIMARY) ORDER BY id FOR UPDATE');
        while ($fence->fetchColumn()!==false) { /* drain locking read without retaining unrelated identities */ }
        $preview=ierb_group_inspect($pdo,$actor,$item['identity']);
        if (!$preview['eligible'] || $preview['fingerprints']!==$item['fingerprints']) throw new AccountLifecycleConflict(IERB_GROUP_CHANGED);
        $live=ierb_group_live_admin($pdo,$actor);
        $stage=$preview['nextStage']; $advisers=[];
        foreach ($preview['members'] as $member) {
            $id=$member['id']; $status=$member['status'];
            $q=$pdo->prepare('UPDATE students SET stage=?,updated_at=NOW() WHERE id=?');
            $q->execute([$stage,$id]);
            if ($q->rowCount()!==1) throw new RuntimeException('A member stage could not be persisted.');
            $context='Group advancement '.$correlation.'; '.$item['identity']['research_group'];
            $description='Stage: '.$member['stage'].' -> '.$stage.'; Status preserved: '.$status;
            record_history($pdo,$id,$stage,$status,$context.'. '.$description.'. Reason: '.$reason,null,
                document_attribution($live['full_name'],' (RPMS Admin)'));
            document_workflow_audit($pdo,$live,'ierb_group_stage_advanced',[
                'entity_type'=>'student','entity_id'=>$id,'student_id'=>$id,'reason'=>$reason,
                'before'=>$member['stage'].' / '.$status,'after'=>$stage.' / '.$status,
                'details'=>'Group advancement '.$correlation.'; '.$description.'; '.$item['identity']['research_group'],
            ]);
            $assigned=student_with_adviser($pdo,$id);
            $aid=(int)($assigned['adviser_pk'] ?? 0);
            // One accurate group notice per assigned Adviser; each Student has a distinct copy.
            $notice=$aid && !isset($advisers[$aid]) ? ['subject'=>'RPMS research group stage advancement',
                'message'=>$live['full_name'].' advanced research group '.$preview['label'].' ('.$preview['memberCount'].' Students) to '.stage_label($stage).' ('.$stage.'). Individual progress statuses were preserved. Reason: '.$reason] : null;
            $ids=array_merge($ids,workflow_queue_notices($pdo,$live,$id,
                ['subject'=>'PRISM IERB Progress Updated','message'=>'RPMS advanced your research group to the next official IERB stage: '.stage_label($stage).' ('.$stage.'). Your progress status remains '.$status.'. This progress decision does not grant institutional ethics approval.'], $notice));
            if ($aid) $advisers[$aid]=true;
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->exec('SET SESSION innodb_lock_wait_timeout='.$wait); }
    document_workflow_deliver($pdo,$ids);
    return ['ok'=>true,'stage'=>$stage,'stageLabel'=>stage_label($stage),'memberCount'=>$preview['memberCount'],
        'correlationId'=>$correlation,'message'=>'Research group advanced. Individual statuses were preserved and each Student decision was recorded.'];
}
