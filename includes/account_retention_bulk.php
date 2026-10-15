<?php
require_once __DIR__.'/pagination.php';
require_once __DIR__.'/record_filters.php';
require_once __DIR__.'/academic_catalog.php';
require_once __DIR__.'/research_groups.php';

function retention_filter_options(): array
{
    return ['lifecycle'=>[['value'=>'active','label'=>'Active'],['value'=>'archived','label'=>'Archived'],['value'=>'all','label'=>'All']],
        'retention'=>array_map(fn($v,$l)=>['value'=>$v,'label'=>$l],['grace','manual','approaching','cleanup','hold','postponed'],
            ['Within 7-day grace period','Eligible for manual purge','Approaching 6-month retention','Eligible for retention cleanup','Retention Hold','Purge postponed / Admin review required'])];
}

/** One trusted query builder for displayed list and bulk population; sorting is excluded. */
function retention_list_scope(PDO $pdo,array $actor,string $type,array $query): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only Admins may select lifecycle populations.');
    retention_type($type);
    $allowed=$type==='student'?['academicUnitKey','programKey','academicYear','yearLevel','group','adviserId','stage','status','course','protocol']:
        ['department','status','group'];
    $allowed=array_merge($allowed,['lifecycle','retention','profile','q']);
    $filters=[];
    foreach ($allowed as $key) {
        $v=$query[$key]??($key==='lifecycle'?'active':'');
        if (!is_string($v) || mb_strlen($v)>250) throw new AccountLifecycleValidation('Invalid lifecycle filter/search.');
        $filters[$key]=trim($v);
    }
    if ($type==='student') {
        [$scope,$params]=prism_student_scope($actor); $a='s';
        $options=prism_student_filter_options($pdo,$scope,$params);
        prism_apply_student_filters($scope,$params,$filters,$options);
        if ($filters['q']!=='') $scope.=' AND '.prism_search_clause(['s.full_name','s.student_id','s.email','s.research_title','s.research_group','f.full_name'],$filters['q'],$params);
    } else {
        $scope='FROM advisers a WHERE 1=1'; $params=[]; $a='a';
        $departments=$pdo->query('SELECT DISTINCT department FROM advisers ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
        $options=['department'=>array_map(fn($v)=>['value'=>trim((string)$v)===''?'__blank__':$v,'label'=>trim((string)$v)===''?'Not recorded':$v],$departments),
            'status'=>[['value'=>'Active','label'=>'Active'],['value'=>'Inactive','label'=>'Inactive']],
            'group'=>array_map(fn($v)=>['value'=>$v,'label'=>$v],research_group_options($pdo,$actor))];
        foreach (['department','status','group'] as $key) {
            $v=$filters[$key]; if ($v==='') continue;
            if (!in_array($v,array_column($options[$key],'value'),true)) throw new AccountLifecycleValidation('Invalid Adviser filter.');
            if ($key==='group') {
                $ids=[]; foreach (research_groups_by_adviser($pdo) as $aid=>$groups) if (in_array($v,$groups,true)) $ids[]=(int)$aid;
                $scope.=' AND a.id IN ('.($ids?implode(',',$ids):'0').')';
            } elseif ($v==='__blank__') $scope.=" AND (a.$key IS NULL OR TRIM(a.$key)='')";
            else { $scope.=" AND a.$key=:filter_$key"; $params[':filter_'.$key]=$v; }
        }
        if ($filters['q']!=='') {
            $search=prism_search_clause(['a.full_name','a.employee_id','a.email','a.department'],$filters['q'],$params);
            $groups=array_values(array_filter(research_group_options($pdo,$actor),fn($g)=>mb_strpos(mb_strtolower($g),mb_strtolower($filters['q']))!==false));
            if ($groups) {
                $keys=[]; foreach ($groups as $i=>$g) { $keys[]=':group'.$i; $params[':group'.$i]=$g; }
                $search.=' OR EXISTS (SELECT 1 FROM students s WHERE s.adviser_id=a.id AND s.archived_at IS NULL AND s.research_group IN ('.implode(',',$keys).'))';
            }
            $scope.=' AND ('.$search.')';
        }
    }
    $options+=retention_filter_options();
    $options['profile']=[['value'=>'pending','label'=>'Pending Profile'],['value'=>'complete','label'=>'Complete Profile']];
    if (!in_array($filters['profile'],['','pending','complete'],true)) throw new AccountLifecycleValidation('Invalid profile filter.');
    if ($filters['profile']!=='') $scope.=" AND $a.profile_completed_at IS ".($filters['profile']==='pending'?'NULL':'NOT NULL');
    if (!in_array($filters['lifecycle'],['','all','active','archived'],true)
        || !in_array($filters['retention'],array_merge([''],array_column($options['retention'],'value')),true)) {
        throw new AccountLifecycleValidation('Invalid retention filter.');
    }
    if ($filters['lifecycle']==='' || $filters['lifecycle']==='active') $scope.=" AND $a.archived_at IS NULL";
    if ($filters['lifecycle']==='archived') $scope.=" AND $a.archived_at IS NOT NULL";
    $open=retention_unresolved_sql($type,$a);
    $filter=match($filters['retention']) {
        'grace'=>"NOW()<DATE_ADD($a.archived_at,INTERVAL 7 DAY)",
        'manual'=>"NOW()>=DATE_ADD($a.archived_at,INTERVAL 7 DAY) AND $a.retention_hold=0 AND NOT $open",
        'approaching'=>"NOW()>=DATE_SUB(DATE_ADD($a.archived_at,INTERVAL 6 MONTH),INTERVAL 3 DAY) AND NOW()<DATE_ADD($a.archived_at,INTERVAL 6 MONTH)",
        'cleanup'=>"NOW()>=DATE_ADD($a.archived_at,INTERVAL 6 MONTH) AND $a.retention_hold=0 AND NOT $open",
        'hold'=>"$a.retention_hold=1",'postponed'=>"($open OR $a.purge_postponed_reason IS NOT NULL)", default=>''};
    if ($filter!=='') $scope.=" AND $a.archived_at IS NOT NULL AND ($filter)";
    return [$scope,$params,$options,$filters,$a];
}

function retention_scope_rows(PDO $pdo,array $actor,string $type,array $filters,?array $ids=null): array
{
    [$scope,$params,,,$a]=retention_list_scope($pdo,$actor,$type,$filters);
    if ($ids!==null) {
        if (!$ids) return [];
        $keys=[]; foreach ($ids as $i=>$id) { $key=':target'.$i; $keys[]=$key; $params[$key]=$id; }
        $scope.=" AND $a.id IN (".implode(',',$keys).')';
    }
    $count=lifecycle_rows($pdo,'SELECT COUNT(*) AS total '.$scope,$params)[0]['total'];
    if ((int)$count>10000) throw new AccountLifecycleValidation('Selection exceeds 10,000 accounts. Narrow the filters or select a smaller population.');
    $select="SELECT $a.*, ".retention_projection($type,$a);
    if ($type==='adviser') $select.=",(SELECT COUNT(*) FROM students ls WHERE ls.adviser_id=$a.id) AS assigned_students";
    return lifecycle_rows($pdo,$select.' '.$scope." ORDER BY $a.id",$params);
}

function retention_session_prune(): void
{
    $_SESSION['retention_selections']??=[]; $_SESSION['retention_previews']??=[];
    foreach (['retention_selections','retention_previews'] as $key) {
        foreach ($_SESSION[$key] as $token=>$item) if (($item['expires']??0)<time()) unset($_SESSION[$key][$token]);
        // Bound abandoned tokens without evicting an in-progress resumable batch.
        if (count($_SESSION[$key])>=12) {
            foreach ($_SESSION[$key] as $token=>$item) {
                if ($key==='retention_previews' && ($item['cursor']??0)>0 && ($item['cursor']??0)<count($item['ids']??[])) continue;
                if ($key==='retention_previews' && ($item['activeChunk']??null)!==null) continue;
                unset($_SESSION[$key][$token]);break;
            }
        }
    }
}

function retention_selection(PDO $pdo,array $actor,array $data): array
{
    $type=retention_type($data['accountType']??null);
    if (!is_array($data['filters']??null)) throw new AccountLifecycleValidation('Explicit filter/search scope is required.');
    // Reject client-supplied SQL, role/scope switches and invented filter keys.
    [$scope,$params,,$filters]=retention_list_scope($pdo,$actor,$type,$data['filters']);
    if (array_diff(array_keys($data['filters']),array_keys($filters))) throw new AccountLifecycleValidation('Unrecognized selection filter.');
    $rows=retention_scope_rows($pdo,$actor,$type,$filters); $ids=array_map('intval',array_column($rows,'id'));
    retention_session_prune(); $token=bin2hex(random_bytes(24));
    if (count($_SESSION['retention_selections'])>=12) throw new AccountLifecycleConflict('Too many selections. Let existing selections expire before creating another.');
    $_SESSION['retention_selections'][$token]=['actor'=>(int)$actor['id'],'type'=>$type,'filters'=>$filters,'ids'=>$ids,'expires'=>time()+900];
    return ['ok'=>true,'selectionToken'=>$token,'total'=>count($ids),'ids'=>$ids,'scope'=>$filters,'accountType'=>$type,'maxSelection'=>10000];
}

function retention_bulk_candidates(PDO $pdo,array $actor,array $data): array
{
    retention_session_prune(); $selection=$_SESSION['retention_selections'][$data['selectionToken']??'']??null;
    if (!$selection || $selection['actor']!==(int)$actor['id']) throw new AccountLifecycleConflict('Selection expired. Select the population again.');
    $ids=$selection['ids'];
    if (($data['selectionMode']??'')==='individual') {
        if (!is_array($data['ids']??null) || !$data['ids'] || count($data['ids'])>10000) throw new AccountLifecycleValidation('Select account IDs within the displayed scope.');
        $requested=array_map('retention_id',$data['ids']);
        if (count(array_unique($requested))!==count($requested) || array_diff($requested,$ids)) throw new AccountLifecycleForbidden('Duplicate or inaccessible/injected selection ID.');
        $ids=$requested; sort($ids,SORT_NUMERIC);
    } elseif (($data['selectionMode']??'')!=='all_matching') throw new AccountLifecycleValidation('Choose individual or all matching selection.');
    // Resolve only the snapshotted IDs still matching the original server-filtered scope.
    $rows=retention_scope_rows($pdo,$actor,$selection['type'],$selection['filters'],$ids);
    if (count($rows)!==count($ids)) throw new AccountLifecycleConflict('Population changed. Select and preview again.');
    return [$selection,$rows];
}

function retention_bulk_eligibility(array $row,string $action): string
{
    if ($action==='archive') return !empty($row['archived_at'])?'Already archived':'';
    if (in_array($action,['restore','hold','remove_hold'],true)) return empty($row['archived_at'])?'Archive first':'';
    $state=retention_state($row);
    if (!$state['manualEligible']) return $state['purgeBlockReason'];
    if ($action==='retention_cleanup' && !$state['cleanupEligible']) return 'Six-month retention period not complete';
    return '';
}

function retention_preview_data(array $rows,string $action,string $type): array
{
    $eligible=[]; $skipped=[]; $impact=0; $fingerprints=[]; $states=[];
    foreach ($rows as $r) {
        $reason=retention_bulk_eligibility($r,$action); $id=(int)$r['id'];
        $fingerprints[$id]=hash('sha256',json_encode([$id,retention_identifier($r,$type),$r['archived_at'],(int)$r['retention_hold'],(int)$r['unresolved_workflow'],(int)($r['assigned_students']??0),$r['full_name'],$r['email'],$r['profile_completed_at']],JSON_THROW_ON_ERROR));
        $states[$id]=['identifier'=>retention_identifier($r,$type),'fingerprint'=>$fingerprints[$id],
            'reason'=>$reason,'impact'=>$type==='adviser'&&$action==='archive'?(int)$r['assigned_students']:0];
        if ($reason!=='') $skipped[]=['id'=>$id,'identifier'=>retention_identifier($r,$type),'reason'=>$reason];
        else { $eligible[]=$id; if ($type==='adviser' && $action==='archive') $impact+=(int)$r['assigned_students']; }
    }
    return ['selected'=>count($rows),'eligible'=>count($eligible),'eligibleIds'=>$eligible,'skipped'=>$skipped,
        'unassignedStudents'=>$impact,'fingerprint'=>hash('sha256',json_encode([$fingerprints,$eligible],JSON_THROW_ON_ERROR)),
        'states'=>$states,'phrase'=>(in_array($action,['permanent_delete','retention_cleanup'],true)?'PURGE':'APPLY').' '.count($eligible).' ACCOUNTS'];
}

function retention_remaining_preview(array $job): array
{
    $fingerprints=[]; $eligible=[]; $ids=array_slice($job['ids'],$job['cursor']);
    foreach ($ids as $id) { $state=$job['preview']['states'][$id]; $fingerprints[$id]=$state['fingerprint']; if ($state['reason']==='') $eligible[]=$id; }
    return ['selected'=>count($ids),'eligible'=>count($eligible),'fingerprint'=>hash('sha256',json_encode([$fingerprints,$eligible],JSON_THROW_ON_ERROR))];
}

function retention_bulk_key(string $token,int $id): string { return hash('sha256',$token.':'.$id); }

/** Transactional audit marker closes the COMMIT → session-checkpoint crash window. */
function retention_bulk_committed(PDO $pdo,array $actor,string $token,array $job,int $id): ?array
{
    $key=retention_bulk_key($token,$id);
    $rows=lifecycle_rows($pdo,'SELECT action,before_value,details FROM activity_logs WHERE entity_type=? AND entity_id=?
        AND details LIKE ? ORDER BY id DESC LIMIT 1',[$job['type'],(string)$id,'Admin user ID '.(int)$actor['id'].'; bulk '.$key.'%']);
    if (!$rows) return null;
    $expected=$job['type'].'_'.match($job['action']){'archive'=>'archived','restore'=>'restored','hold'=>'retention_hold','remove_hold'=>'retention_hold_removed','permanent_delete'=>'permanently_deleted',default=>'retention_cleanup_purge'};
    if ($rows[0]['action']!==$expected) throw new AccountLifecycleConflict('Bulk recovery audit binding differs. Review before continuing.');
    $result=['id'=>$id,'identifier'=>$rows[0]['before_value'],'outcome'=>'completed','reason'=>'Committed outcome recovered from durable audit.'];
    if (preg_match('/; job ([a-f0-9]{32})$/',$rows[0]['details'],$match)) {
        $jobs=lifecycle_rows($pdo,'SELECT status FROM account_purge_jobs WHERE id=?',[$match[1]]);
        $journal=purge_journal_root().DIRECTORY_SEPARATOR.$match[1];
        if (!$jobs || $jobs[0]['status']!=='complete' || file_exists($journal) || is_link($journal)) $result['outcome']='recovery_required';
        $result['jobId']=$match[1];
    } elseif ($job['action']==='restore') $result['reason'].=' Request a fresh password reset link if the original setup link was lost.';
    return $result;
}

function retention_bulk_checkpoint(): void
{
    if (session_status()===PHP_SESSION_ACTIVE) {
        session_write_close();
        if (!session_start()) throw new AccountLifecycleUnavailable('Bulk progress checkpoint could not be reopened.');
    }
}

function retention_bulk_preview(PDO $pdo,array $actor,array $data): array
{
    $action=$data['bulkAction']??'';
    if (!in_array($action,['archive','restore','hold','remove_hold','permanent_delete','retention_cleanup'],true)) throw new AccountLifecycleValidation('Invalid bulk action. Bulk grace overrides are forbidden.');
    [$selection,$rows]=retention_bulk_candidates($pdo,$actor,$data);
    $preview=retention_preview_data($rows,$action,$selection['type']);
    if (count($_SESSION['retention_previews'])>=12) throw new AccountLifecycleConflict('Too many unfinished batches. Resume or let existing previews expire before selecting another population.');
    $token=bin2hex(random_bytes(24));
    $_SESSION['retention_previews'][$token]=['actor'=>(int)$actor['id'],'type'=>$selection['type'],'filters'=>$selection['filters'],
        'action'=>$action,'ids'=>array_map('intval',array_column($rows,'id')),'preview'=>$preview,'expires'=>time()+900,'cursor'=>0,'results'=>[],'chunks'=>[]];
    return ['ok'=>true,'previewToken'=>$token,'batchSize'=>25]+$preview;
}

function retention_bulk_execute(PDO $pdo,array $actor,array $data): array
{
    $token=$data['previewToken']??'';
    if (!is_string($token)) throw new AccountLifecycleValidation('Invalid preview token.');
    $lock='prism_bulk_'.substr(hash('sha256',$token),0,40);
    $q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) throw new AccountLifecycleConflict('This batch is already running. Retry the same cursor after it finishes.');
    try { return retention_bulk_execute_locked($pdo,$actor,$data); }
    finally { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]); }
}

function retention_bulk_execute_locked(PDO $pdo,array $actor,array $data): array
{
    $started=microtime(true);
    retention_session_prune(); $token=$data['previewToken']??'';
    if (!is_string($token) || !isset($_SESSION['retention_previews'][$token])) throw new AccountLifecycleConflict('Preview expired. Review and confirm again.');
    $job=&$_SESSION['retention_previews'][$token];
    if ($job['actor']!==(int)$actor['id']) throw new AccountLifecycleForbidden('Preview belongs to another Admin.');
    if (!is_int($data['cursor']??null) || $data['cursor']<0) throw new AccountLifecycleValidation('Invalid batch cursor.');
    if (!is_string($data['currentPassword']??null) || strlen($data['currentPassword'])>200 || ($data['confirmed']??false)!==true
        || ($data['confirmation']??'')!==$job['preview']['phrase']) throw new AccountLifecycleValidation('Current Admin password, server confirmation phrase and acknowledgement are required.');
    $pdo->beginTransaction(); try { retention_actor($pdo,$actor,$data['currentPassword']); $pdo->commit(); }
    catch(Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    $cursor=$data['cursor'];
    if (isset($job['chunks'][$cursor])) return $job['chunks'][$cursor]; // response-loss retry
    if ($cursor!==$job['cursor'] && $cursor!==($job['activeChunk']??null)) throw new AccountLifecycleConflict('Stale batch cursor. Resume at the reported next cursor.');
    $job['activeChunk']=$cursor;
    while ($job['cursor']<count($job['ids'])) {
        $id=$job['ids'][$job['cursor']];$recovered=retention_bulk_committed($pdo,$actor,$token,$job,$id);
        if (!$recovered) break;
        $job['results'][]=$recovered;++$job['cursor'];
    }
    $remaining=array_slice($job['ids'],$job['cursor']);
    $rows=retention_scope_rows($pdo,$actor,$job['type'],$job['filters'],$remaining);
    $fresh=retention_preview_data($rows,$job['action'],$job['type']);
    // Revalidate the remaining population; previous committed outcomes are excluded explicitly.
    $expected=retention_remaining_preview($job);
    if ($fresh['fingerprint']!==$expected['fingerprint'] || $fresh['eligible']!==$expected['eligible'] || $fresh['selected']!==$expected['selected']) {
        throw new AccountLifecycleConflict('Eligible population or Adviser assignment impact changed. Review a fresh preview before continuing.');
    }
    $byId=[]; foreach ($rows as $row) $byId[(int)$row['id']]=$row;
    $reason=$data['reason']??'';
    if (!is_string($reason) || mb_strlen($reason)>500) throw new AccountLifecycleValidation('Invalid Hold reason.');
    retention_bulk_checkpoint();$job=&$_SESSION['retention_previews'][$token];
    foreach (array_slice($remaining,0,max(0,25-($job['cursor']-$cursor))) as $id) {
        // Leave headroom for session/result persistence on shared-hosting request limits.
        if ($job['cursor']>$cursor && microtime(true)-$started>=10) break;
        $row=$byId[$id]; $identifier=retention_identifier($row,$job['type']);
        $skip=retention_bulk_eligibility($row,$job['action']);
        if ($skip!=='') $result=['id'=>$id,'identifier'=>$identifier,'outcome'=>'skipped','reason'=>$skip];
        else {
            try {
                // Reconstruct the server scope immediately before EACH target, never from client eligibility.
                $current=retention_scope_rows($pdo,$actor,$job['type'],$job['filters'],[$id]);
                if (!$current || ($blocked=retention_bulk_eligibility($current[0],$job['action']))!=='') throw new AccountLifecycleConflict($blocked??'Account left the authorized selection scope.');
                $input=['accountType'=>$job['type'],'targetId'=>$id,'action'=>$job['action'],'reason'=>$reason,'currentPassword'=>$data['currentPassword'],'confirmation'=>$identifier,'confirmed'=>true];
                if ($job['type']==='adviser' && $job['action']==='archive') $input['expectedAssignedStudents']=(int)$row['assigned_students'];
                $key=retention_bulk_key($token,$id);
                $response=in_array($job['action'],['permanent_delete','retention_cleanup'],true)?retention_purge($pdo,$actor,$input,$job['filters'],$key):retention_change($pdo,$actor,$input,$job['filters'],$key);
                $result=['id'=>$id,'identifier'=>$identifier,'outcome'=>$response['ok']?'completed':'recovery_required','reason'=>$response['message']];
                if (isset($response['jobId'])) $result['jobId']=$response['jobId'];
                if (isset($response['setupLink'])) $result['setupLink']=$response['setupLink'];
            } catch(Throwable $error) {
                $status=lifecycle_error_status($error);
                // A lost acknowledgement can follow COMMIT. Never label that outcome a failed deletion.
                try { $durable=retention_bulk_committed($pdo,$actor,$token,$job,$id); }
                catch(Throwable $unknown) { $durable=['id'=>$id,'identifier'=>$identifier,'outcome'=>'recovery_required','reason'=>'Commit outcome unavailable. Review account and recovery evidence before retrying.']; }
                if ($durable) $result=$durable;
                elseif (preg_match('/job ([a-f0-9]{32})/',$error->getMessage(),$match)) {
                    $result=['id'=>$id,'identifier'=>$identifier,'outcome'=>'recovery_required','reason'=>$error->getMessage(),'jobId'=>$match[1]];
                } else $result=['id'=>$id,'identifier'=>$identifier,'outcome'=>'skipped','reason'=>$status<500?$error->getMessage():'Account operation refused; retry after review.'];
            }
        }
        $job['results'][]=$result; ++$job['cursor'];
        $job['expires']=time()+900;
        retention_bulk_checkpoint();$job=&$_SESSION['retention_previews'][$token];
    }
    $next=array_slice($job['ids'],$job['cursor']);
    $nextRows=array_values(array_filter($rows,fn($row)=>in_array((int)$row['id'],$next,true)));
    $job['remainingPreview']=retention_preview_data($nextRows,$job['action'],$job['type']);
    $counts=array_count_values(array_column($job['results'],'outcome'));
    $response=['ok'=>true,'done'=>$job['cursor']>=count($job['ids']),'nextCursor'=>$job['cursor'],'selected'=>count($job['ids']),
        'completed'=>$counts['completed']??0,'skipped'=>$counts['skipped']??0,'recoveryRequired'=>$counts['recovery_required']??0,
        'results'=>array_slice($job['results'],$cursor),'batchSize'=>25];
    $job['chunks'][$cursor]=$response;
    $job['activeChunk']=null;
    return $response;
}

function retention_cleanup_summary(PDO $pdo,array $actor): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Admin access required.');
    $counts=[];
    foreach (['student','adviser'] as $type) {
        [$scope,$params]=retention_list_scope($pdo,$actor,$type,['lifecycle'=>'archived','retention'=>'cleanup']);
        $counts[$type]=(int)lifecycle_rows($pdo,'SELECT COUNT(*) AS total '.$scope,$params)[0]['total'];
    }
    return ['ok'=>true,'eligibleStudents'=>$counts['student'],'eligibleAdvisers'=>$counts['adviser'],'automaticDeletion'=>false];
}
