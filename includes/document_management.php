<?php
/** Repository filters and bounded, confirmed document deletion. No schema or report changes. */
const DOCUMENT_SELECTION_LIMIT = 1000;
const DOCUMENT_SELECTION_SECONDS = 600;

class DocumentManagementError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

function document_management_text(array $query, string $key, int $limit = 255): string
{
    $value = $query[$key] ?? '';
    if (!is_string($value) && !is_int($value)) throw new DocumentManagementError('Invalid ' . $key . ' filter.');
    $value = trim((string)$value);
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) throw new DocumentManagementError('Invalid ' . $key . ' filter.');
    return $value;
}

function document_management_select(): string
{
    return 'SELECT d.*, s.course, s.protocol_code, s.adviser_id, s.archived_at, da.full_name AS adviser_name';
}

function document_management_from(): string
{
    return 'FROM documents d LEFT JOIN students s ON s.id=d.student_id LEFT JOIN advisers da ON da.id=s.adviser_id';
}

/** Same dataset and pagination as the existing repository; SQL ordering is strictly allowlisted. */
function document_list_query(array $user, ?int $viewerAdviserId, array $query): array
{
    $scope = document_management_from() . ' WHERE 1=1'; $params = [];
    if ($user['role'] === 'student') { $scope .= ' AND s.email=:viewer'; $params[':viewer'] = $user['email']; }
    elseif ($user['role'] === 'adviser') {
        $scope .= $viewerAdviserId !== null ? ' AND s.adviser_id=:viewerAdviser AND s.archived_at IS NULL' : ' AND 1=0';
        if ($viewerAdviserId !== null) $params[':viewerAdviser'] = $viewerAdviserId;
    }
    if (document_management_text($query, 'includeOld') !== '1') $scope .= ' AND d.is_current=1';
    $stateSql = "CASE WHEN d.is_current=0 THEN 'Superseded' WHEN " . document_returned_sql()
        . " THEN 'Returned for Revision' WHEN d.review_status='Approved' THEN CASE WHEN d.rpms_submitted_at IS NOT NULL AND CAST(d.rpms_submitted_at AS CHAR)<>'' THEN 'Submitted to RPMS' ELSE 'Ready for Formal RPMS Submission' END WHEN d.review_status IN ('Denied','Resubmission Requested') THEN 'Needs Revision' ELSE 'Pending Adviser Review' END";
    if (document_management_text($query, 'actionable') === '1') $scope .= " AND d.is_current=1 AND ($stateSql) IN ('Needs Revision','Returned for Revision','Ready for Formal RPMS Submission')";
    $baseScope = $scope; $baseParams = $params;
    $searchTerm = document_management_text($query, 'q');
    if ($searchTerm !== '') {
        $search = prism_search_clause(['d.original_name','d.student_name','s.protocol_code','d.document_type','d.uploaded_by','d.stage'], $searchTerm, $params);
        foreach (STAGE_SEQUENCE as $i => $stage) if (mb_stripos(stage_label($stage), $searchTerm) !== false) {
            $search .= ' OR d.stage=:labelStage' . $i; $params[':labelStage' . $i] = $stage;
        }
        $scope .= ' AND (' . $search . ')';
    }
    foreach (['review'=>'d.review_status','stage'=>'d.stage','type'=>'d.document_type','course'=>'s.course','year'=>'SUBSTR(d.uploaded_at,1,4)','state'=>$stateSql] as $key => $column) {
        $value = document_management_text($query, $key);
        if ($value === '') continue;
        if ($key === 'stage' && !in_array($value, STAGE_SEQUENCE, true)) throw new DocumentManagementError('Choose an official IERB stage.');
        if ($key === 'year' && !preg_match('/\A[1-9][0-9]{3}\z/', $value)) throw new DocumentManagementError('Choose a valid upload year.');
        $scope .= " AND ($column)=:$key"; $params[":$key"] = $value;
    }
    if (document_management_text($query, 'stateGroup') === 'approved') $scope .= " AND ($stateSql) IN ('Ready for Formal RPMS Submission','Submitted to RPMS')";
    $from = document_management_text($query, 'fromDate', 10); $to = document_management_text($query, 'toDate', 10);
    $adviser = document_management_text($query, 'adviser', 20);
    if (($from !== '' || $to !== '' || $adviser !== '') && $user['role'] !== 'admin') throw new DocumentManagementError('Advanced document filters require an RPMS Administrator.', 403);
    foreach ([$from,$to] as $value) if ($value !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value || (int)$parsed->format('Y') < 1000) throw new DocumentManagementError('Enter valid upload dates in YYYY-MM-DD format.');
    }
    if ($from !== '' && $to !== '' && $from > $to) throw new DocumentManagementError('From Date must be on or before To Date.');
    if ($from !== '') { $scope .= ' AND d.uploaded_at>=:fromDate'; $params[':fromDate'] = $from . ' 00:00:00'; }
    if ($to !== '') { $scope .= ' AND d.uploaded_at<=:toDate'; $params[':toDate'] = $to . ' 23:59:59'; }
    if ($adviser === 'unassigned') $scope .= ' AND s.adviser_id IS NULL';
    elseif ($adviser !== '') {
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/', $adviser)) throw new DocumentManagementError('Choose a valid assigned Adviser.');
        $scope .= ' AND s.adviser_id=:assignedAdviser'; $params[':assignedAdviser'] = (int)$adviser;
    }
    $orders = ['newest'=>'d.uploaded_at DESC, d.id DESC','oldest'=>'d.uploaded_at ASC, d.id ASC',
        'name-asc'=>'d.original_name ASC, d.id ASC','name-desc'=>'d.original_name DESC, d.id ASC','student-asc'=>'d.student_name ASC, d.id ASC'];
    $stageOrder = "CASE d.stage WHEN 'Stage 1' THEN 1 WHEN 'Stage 2' THEN 2 WHEN 'Stage 3' THEN 3 WHEN 'Stage 4' THEN 4 WHEN 'Stage 5' THEN 5 WHEN 'Completed' THEN 6 ELSE 7 END";
    $advancedOrders = ['student-desc'=>'d.student_name DESC, d.id ASC','type-asc'=>'d.document_type ASC, d.id ASC',
        'adviser-asc'=>'da.full_name ASC, d.id ASC','stage-asc'=>$stageOrder . ' ASC, d.id ASC',
        'stage-desc'=>$stageOrder . ' DESC, d.id ASC','review'=>'d.review_status ASC, d.id ASC','reviewed-newest'=>'d.reviewed_at DESC, d.id ASC'];
    $sort = document_management_text($query, 'sort', 40);
    if (isset($advancedOrders[$sort]) && $user['role'] !== 'admin') throw new DocumentManagementError('Advanced document sorting requires an RPMS Administrator.', 403);
    return ['select'=>document_management_select(),'scope'=>$scope,'params'=>$params,'order'=>($orders + $advancedOrders)[$sort] ?? $orders['newest'],
        'stateSql'=>$stateSql,'baseScope'=>$baseScope,'baseParams'=>$baseParams];
}

function document_list_options(PDO $pdo, array $spec, array $user): array
{
    $q = $pdo->prepare('SELECT ' . $spec['stateSql'] . ' AS state, COUNT(*) AS c ' . $spec['baseScope'] . ' GROUP BY ' . $spec['stateSql']);
    $q->execute($spec['baseParams']); $counts = array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC),'c','state'));
    $options = [];
    foreach (['types'=>'d.document_type','courses'=>'s.course','years'=>'SUBSTR(d.uploaded_at,1,4)','reviews'=>'d.review_status'] as $key=>$column) {
        $q = $pdo->prepare('SELECT DISTINCT ' . $column . ' AS value ' . $spec['baseScope'] . ' ORDER BY value');
        $q->execute($spec['baseParams']); $options[$key] = array_values(array_filter($q->fetchAll(PDO::FETCH_COLUMN), static fn($v)=>$v !== null && $v !== ''));
    }
    if ($user['role'] === 'admin') {
        $options['advisers'] = $pdo->query('SELECT id, full_name AS name FROM advisers ORDER BY full_name,id')->fetchAll(PDO::FETCH_ASSOC);
        $options['stages'] = array_map(static fn($key)=>['key'=>$key,'label'=>stage_label($key)], STAGE_SEQUENCE);
    }
    return ['counts'=>$counts,'filterOptions'=>$options];
}

/** The browser receives only this digest, never internal paths or full raw database records. */
function document_selection_fingerprint(array $document): string
{
    $fields = ['id','student_id','student_name','uploaded_by','uploaded_by_role','original_name','stored_name','mime','size',
        'document_type','stage','notes','review_status','review_remarks','reviewed_by','reviewed_at','ai_summary','uploaded_at',
        'version_no','is_current','supersedes_id','rpms_submitted_at','rpms_submitted_by','admin_override','override_reason','override_by','override_at',
        'adviser_id','archived_at','course','protocol_code','rpms_returned_at','rpms_returned_by','rpms_return_reason'];
    $values=[]; foreach ($fields as $field) $values[$field] = isset($document[$field]) ? (string)$document[$field] : null;
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function document_management_admin(array $actor): void
{
    if (($actor['role'] ?? '') !== 'admin') throw new DocumentManagementError('Only RPMS Administrators may manage document selections or bulk deletion.', 403);
}

function document_management_ids(mixed $ids, bool $allowEmpty = false): array
{
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > DOCUMENT_SELECTION_LIMIT) throw new DocumentManagementError('Select at most ' . DOCUMENT_SELECTION_LIMIT . ' documents in one batch.');
    foreach ($ids as $id) if (!is_string($id) || !preg_match('/\A[a-f0-9]{24}\z/', $id)) throw new DocumentManagementError('The document selection is malformed.');
    $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING);
    if (!$allowEmpty && !$ids) throw new DocumentManagementError('Select at least one document.');
    return $ids;
}

function document_management_store(array $actor, string $kind, array $data): string
{
    foreach ($_SESSION['document_selections'] ?? [] as $key=>$item) if (($item['expires'] ?? 0) < time()) unset($_SESSION['document_selections'][$key]);
    $token = bin2hex(random_bytes(24));
    $_SESSION['document_selections'][$token] = $data + ['kind'=>$kind,'actor'=>(string)$actor['id'],
        'generation'=>prism_session_generation(),'expires'=>time()+DOCUMENT_SELECTION_SECONDS];
    while (count($_SESSION['document_selections']) > 8) array_shift($_SESSION['document_selections']);
    return $token;
}

function document_management_snapshot(array $actor, mixed $token, string $kind): array
{
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{48}\z/', $token)) throw new DocumentManagementError('The selection expired. Select the documents again.', 409);
    $snapshot = $_SESSION['document_selections'][$token] ?? null;
    if (!$snapshot || $snapshot['kind'] !== $kind || $snapshot['actor'] !== (string)$actor['id']
        || !hash_equals($snapshot['generation'], prism_session_generation()) || $snapshot['expires'] < time()) {
        throw new DocumentManagementError('The selection expired or your session changed. Select the documents again.', 409);
    }
    return $snapshot;
}

function document_management_rows(PDO $pdo, array $ids, bool $lock = false): array
{
    if (!$ids) return [];
    $q=$pdo->prepare(document_management_select() . ' ' . document_management_from() . ' WHERE d.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY d.id' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute($ids); $rows=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $row=document_with_return($pdo,$row,$lock); $rows[$row['id']]=$row; }
    return $rows;
}

function document_selection_snapshot(PDO $pdo, array $actor, array $payload): array
{
    document_management_admin($actor);
    if (!is_array($payload['filters'] ?? null)) throw new DocumentManagementError('Supply the current document filters.');
    $spec=document_list_query($actor,null,$payload['filters']);
    $q=$pdo->prepare($spec['select'] . ' ' . $spec['scope'] . ' ORDER BY ' . $spec['order'] . ' LIMIT ' . (DOCUMENT_SELECTION_LIMIT+1));
    $q->execute($spec['params']); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>DOCUMENT_SELECTION_LIMIT) throw new DocumentManagementError('More than ' . DOCUMENT_SELECTION_LIMIT . ' documents match. Narrow the filters before selecting all.');
    if (!$rows) throw new DocumentManagementError('No documents match these filters.');
    $versions=[]; foreach ($rows as $row) { $row=document_with_return($pdo,$row); $versions[$row['id']]=document_selection_fingerprint($row); }
    $token=document_management_store($actor,'selection',['versions'=>$versions]);
    return ['selectionToken'=>$token,'count'=>count($versions),'ids'=>array_keys($versions),'expiresIn'=>DOCUMENT_SELECTION_SECONDS];
}

/** Lock Students first in deterministic order; all existing account eligibility checks stay active. */
function document_management_lock(PDO $pdo, array $actor, array $versions): array
{
    $rows=document_management_rows($pdo,array_keys($versions));
    $studentIds=array_values(array_unique(array_filter(array_map(static fn($row)=>(int)$row['student_id'],$rows)))); sort($studentIds,SORT_NUMERIC);
    foreach ($studentIds as $sid) lifecycle_rows($pdo,'SELECT id FROM students WHERE id=? FOR UPDATE',[$sid]);
    $live=current_workflow_actor($pdo,$actor);
    if (!legal_admin_eligible($live)) throw new DocumentManagementError('An eligible active RPMS Administrator is required.',403);
    $unavailable=[];
    foreach ($studentIds as $sid) {
        try { current_workflow_student($pdo,$sid,$actor); }
        catch (AccountLifecycleConflict|AccountLifecycleForbidden $e) { $unavailable[$sid]=$e->getMessage(); }
    }
    $rows=document_management_rows($pdo,array_keys($versions),true); $ineligible=[];
    foreach ($versions as $id=>$fingerprint) {
        $row=$rows[$id]??null;
        $reason = !$row ? 'Document was deleted after selection.' : (!hash_equals($fingerprint,document_selection_fingerprint($row)) ? 'Document changed after selection.' : null);
        if ($row && !$reason) $reason=empty($row['student_id']) ? 'Document has no eligible Student record.' : ($unavailable[(int)$row['student_id']]??null);
        if ($row && !$reason) {
            try { ierb_v14_guard_document_delete($pdo,$row); }
            catch (DocumentWorkflowError $e) { $reason=$e->getMessage(); }
        }
        if ($reason) $ineligible[]=['id'=>$id,'message'=>$reason];
    }
    return ['rows'=>$rows,'ineligible'=>$ineligible];
}

function document_management_chain_key(array $document): string
{
    return hash('sha256',json_encode([(int)$document['student_id'],$document['stage'],$document['document_type']],JSON_THROW_ON_ERROR));
}

/** Validate a single linear chain; never silently repair previously corrupt records. */
function document_management_chain(PDO $pdo, array $document): array
{
    $q=$pdo->prepare(document_management_select() . ' ' . document_management_from()
        . ' WHERE d.student_id=? AND d.stage=? AND d.document_type=? ORDER BY d.version_no,d.id FOR UPDATE');
    $q->execute([$document['student_id'],$document['stage'],$document['document_type']]); $rows=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $row=document_with_return($pdo,$row,true); $rows[$row['id']]=$row; }
    $currents=array_filter($rows,static fn($row)=>(int)$row['is_current']===1);
    if (count($currents)!==1) throw new DocumentManagementError('A selected document has conflicting current versions. Request RPMS record review.',409);
    $current=reset($currents); $seen=[]; $node=$current;
    while ($node) {
        if (isset($seen[$node['id']])) throw new DocumentManagementError('A selected document version chain contains a cycle.',409);
        $seen[$node['id']]=true; $prev=$node['supersedes_id'];
        if (!$prev) break;
        if (!isset($rows[$prev]) || (int)$rows[$prev]['version_no'] >= (int)$node['version_no']) throw new DocumentManagementError('A selected document version chain has an invalid predecessor.',409);
        $node=$rows[$prev];
    }
    if (count($seen)!==count($rows)) throw new DocumentManagementError('A selected document version chain is disconnected. Request RPMS record review.',409);
    $ids=array_keys($rows);
    $links=lifecycle_rows($pdo,'SELECT id FROM documents WHERE supersedes_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY id FOR UPDATE',$ids);
    foreach ($links as $link) if (!isset($rows[$link['id']])) throw new DocumentManagementError('A selected document has an invalid cross-document version link.',409);
    $digests=[]; foreach ($rows as $id=>$row) $digests[$id]=document_selection_fingerprint($row);
    return ['rows'=>$rows,'current'=>$current['id'],'fingerprint'=>hash('sha256',json_encode($digests,JSON_THROW_ON_ERROR))];
}

function document_bulk_preview(PDO $pdo, array $actor, array $payload): array
{
    document_management_admin($actor);
    if (isset($payload['selectionToken'])) {
        $snapshot=document_management_snapshot($actor,$payload['selectionToken'],'selection');
        $excluded=document_management_ids($payload['excludedIds']??[],true);
        foreach ($excluded as $id) if (!isset($snapshot['versions'][$id])) throw new DocumentManagementError('A deselected document is outside the selection.');
        $versions=array_diff_key($snapshot['versions'],array_fill_keys($excluded,true));
    } else {
        $ids=document_management_ids($payload['ids']??null); $versions=[]; $expected=$payload['expectedVersions']??null;
        if (!is_array($expected)) throw new DocumentManagementError('Refresh and select the documents again.',409);
        foreach ($ids as $id) {
            if (!is_string($expected[$id]??null) || !preg_match('/\A[a-f0-9]{64}\z/',$expected[$id])) throw new DocumentManagementError('Refresh and select the documents again.',409);
            $versions[$id]=$expected[$id];
        }
    }
    if (!$versions) throw new DocumentManagementError('Select at least one document.');
    $pdo->beginTransaction();
    try {
        $locked=document_management_lock($pdo,$actor,$versions); $rows=$locked['rows']; $ineligible=$locked['ineligible']; $chains=[];
        foreach ($rows as $id=>$row) {
            if (empty($row['student_id'])) continue;
            $key=document_management_chain_key($row); if (isset($chains[$key])) continue;
            try { $chains[$key]=document_management_chain($pdo,$row)['fingerprint']; }
            catch (DocumentManagementError $e) { $ineligible[]=['id'=>$id,'message'=>$e->getMessage()]; }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $current=count(array_filter($rows,static fn($r)=>(int)$r['is_current']===1));
    $result=['count'=>count($versions),'currentVersions'=>$current,'historicalVersions'=>count($rows)-$current,
        'submitted'=>count(array_filter($rows,'document_is_locked')),'ineligible'=>$ineligible,'canDelete'=>!$ineligible,'expiresIn'=>DOCUMENT_SELECTION_SECONDS];
    // Exact review list of the server-resolved targets (Admin-only endpoint); values are rendered as text.
    $result['records']=[];
    foreach (array_keys($versions) as $id) {
        $row=$rows[$id]??null;
        $result['records'][]=$row ? ['id'=>$id,'originalName'=>(string)$row['original_name'],'documentType'=>(string)$row['document_type'],
            'versionNo'=>(int)($row['version_no']??1),'isCurrent'=>(int)$row['is_current']===1,'locked'=>document_is_locked($row),
            'uploadedBy'=>trim((string)($row['uploaded_by']??''))!=='' ? (string)$row['uploaded_by'] : null,'uploaderRole'=>$row['uploaded_by_role']??null,
            'studentName'=>$row['student_name']??null] : ['id'=>$id,'missing'=>true];
    }
    if (!$ineligible) $result['confirmationToken']=document_management_store($actor,'confirmation',['versions'=>$versions,'chains'=>$chains]);
    return $result;
}

/** Remove only private files no surviving database document references. Never run before commit. */
function document_management_cleanup(PDO $pdo, array $deleted): array
{
    $failed=[]; $root=realpath(DOCS_DIR);
    foreach ($deleted as $row) {
        $id=$row['id']; $name=(string)$row['stored_name'];
        try {
            if ($name==='' || basename($name)!==$name || preg_match('~[\\\\/\x00]~',$name) || $root===false) throw new RuntimeException('Private storage boundary unavailable.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM documents WHERE stored_name=?'); $q->execute([$name]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('File is still referenced by a surviving document.');
            $candidate=$root . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($candidate) && !is_link($candidate)) continue;
            if (is_link($candidate)) throw new RuntimeException('Symbolic-link storage requires administrator reconciliation.');
            $resolved=realpath($candidate); $prefix=$root . DIRECTORY_SEPARATOR;
            $inside=$resolved!==false && (DIRECTORY_SEPARATOR==='\\' ? strncasecmp($resolved,$prefix,strlen($prefix))===0 : strncmp($resolved,$prefix,strlen($prefix))===0);
            if (!$inside || !is_file($resolved) || !@unlink($candidate)) throw new RuntimeException('Private file cleanup failed.');
        } catch (Throwable $e) { $failed[]=$id; log_api_error('document_file_cleanup','Committed bulk deletion needs file cleanup for document ' . $id . '.'); }
    }
    return $failed;
}

/** One bounded transaction; a failure preserves every selected row and every private file. */
function document_bulk_delete(PDO $pdo, array $actor, array $payload): array
{
    document_management_admin($actor);
    if (($payload['confirmed']??null)!==true) throw new DocumentManagementError('Explicit permanent-deletion confirmation is required.');
    $reason=$payload['reason']??null;
    if (!is_string($reason) || !mb_check_encoding($reason,'UTF-8') || mb_strlen($reason)>5000 || !override_reason_valid($reason)) {
        throw new DocumentManagementError(override_reason_message() . ' Use no more than 5,000 characters.');
    }
    $token=$payload['confirmationToken']??null; $snapshot=document_management_snapshot($actor,$token,'confirmation');
    // A confirmed destructive attempt cannot be replayed, including an uncertain COMMIT outcome.
    unset($_SESSION['document_selections'][$token]); $commitAttempted=false;
    $pdo->beginTransaction();
    try {
        $locked=document_management_lock($pdo,$actor,$snapshot['versions']);
        if ($locked['ineligible']) throw new DocumentManagementError('The selected documents changed or are no longer eligible. Nothing was deleted; review a new selection.',409);
        $rows=$locked['rows']; $chains=[];
        foreach ($rows as $row) {
            $key=document_management_chain_key($row); if (isset($chains[$key])) continue;
            $chain=document_management_chain($pdo,$row);
            if (!isset($snapshot['chains'][$key]) || !hash_equals($snapshot['chains'][$key],$chain['fingerprint'])) throw new DocumentManagementError('A document version chain changed after confirmation. Nothing was deleted.',409);
            $chains[$key]=$chain;
        }
        foreach ($rows as $id=>$row) {
            $ctx=['entity_type'=>'document','entity_id'=>$id,'student_id'=>$row['student_id'],'before'=>document_workflow_state($row),
                'after'=>'Permanently deleted','reason'=>trim($reason),'override'=>document_is_locked($row),
                'details'=>'Permanently deleted "' . $row['original_name'] . '" v' . (int)$row['version_no'] . ' in confirmed bulk selection (' . count($rows) . ' records).'];
            if (document_is_locked($row)) audit_override_atomic($pdo,$actor,'document_deleted',$ctx);
            else document_workflow_audit($pdo,$actor,'document_deleted',$ctx);
        }
        foreach ($chains as $chain) {
            $survivors=array_diff_key($chain['rows'],$rows); $newCurrent=$chain['current'];
            while (isset($rows[$newCurrent])) { $newCurrent=$chain['rows'][$newCurrent]['supersedes_id']; if (!$newCurrent) break; }
            // Release current flags before promoting a surviving predecessor.
            foreach ($chain['rows'] as $id=>$row) if ((int)$row['is_current']===1) $pdo->prepare('UPDATE documents SET is_current=0 WHERE id=?')->execute([$id]);
            foreach ($survivors as $id=>$row) {
                $prev=$row['supersedes_id']; while ($prev && isset($rows[$prev])) $prev=$chain['rows'][$prev]['supersedes_id'];
                $pdo->prepare('UPDATE documents SET supersedes_id=?,is_current=? WHERE id=?')->execute([$prev ?: null,(int)($id===$newCurrent),$id]);
            }
        }
        foreach ($rows as $id=>$row) {
            $q=$pdo->prepare('DELETE FROM documents WHERE id=?'); $q->execute([$id]);
            if ($q->rowCount()!==1) throw new DocumentManagementError('A selected document changed during deletion.',409);
        }
        $commitAttempted=true; $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ignored) {} }
        if ($commitAttempted) throw new DocumentManagementError('The deletion outcome could not be confirmed. Refresh the repository before any further action; private files were retained for reconciliation.',503);
        throw $e;
    }
    $failed=document_management_cleanup($pdo,$rows);
    return ['deleted'=>count($rows),'physicalDeletionComplete'=>!$failed,'cleanupFailures'=>$failed,
        'message'=>count($rows) . ' document records permanently deleted.' . ($failed ? ' ' . count($failed) . ' private files need administrator cleanup; physical deletion is incomplete.' : ' Private file cleanup completed.')];
}
