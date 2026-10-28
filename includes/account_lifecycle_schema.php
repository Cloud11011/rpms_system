<?php
/** A runtime snapshot is necessary but never constitutes operator approval by itself. */
function lifecycle_schema_inventory(PDO $pdo): array
{
    $queries = [
        'tables'=>'SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME',
        'columns'=>'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA,COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes'=>'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'foreign_keys'=>'SELECT k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
            WHERE (k.TABLE_SCHEMA=DATABASE() OR k.REFERENCED_TABLE_SCHEMA=DATABASE()) AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
        'triggers'=>'SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME',
    ];
    $result=[];
    foreach($queries as $key=>$sql) $result[$key]=lifecycle_rows($pdo,$sql);
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach($result['foreign_keys'] as &$fk) {
        // Preserve foreign schema identities. Only the actual PRISM schema is portable between deployments.
        foreach(['TABLE_SCHEMA','REFERENCED_TABLE_SCHEMA'] as $field) {
            $fk[$field]=$fk[$field]===$database?'{PRISM}':'{EXTERNAL}:'.$fk[$field];
        }
    }
    unset($fk);
    $result['schema_version']=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    return $result;
}

function lifecycle_manifest_file(?PDO $pdo = null): string
{
    if (defined('PRISM_LIFECYCLE_MANIFEST_FILE')) return PRISM_LIFECYCLE_MANIFEST_FILE;
    $version=$pdo?(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn():'10';
    // Schema 12 composes the frozen v11 inventory with its separately pinned extension definitions.
    return __DIR__.(in_array($version,['11','12','13','14','15'],true)?'/account_lifecycle_schema_v11.json':'/account_lifecycle_schema.json');
}

function lifecycle_manifest_hash(?PDO $pdo = null): string
{
    return hash_file('sha256',lifecycle_manifest_file($pdo));
}

function lifecycle_database_binding(PDO $pdo): array
{
    $row=$pdo->query('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    return array_map('strval',$row);
}

function lifecycle_compare_schema(PDO $pdo): void
{
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='15') {
        require_once __DIR__.'/schema_v15.php';
        try { schema_v15_verify($pdo); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Academic-year schema differs from the reviewed contract. Retain using Archive.',0,$e); }
        return;
    }
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='14') {
        require_once __DIR__.'/schema_v14.php';
        try { schema_v14_verify($pdo); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Summary schema differs from the reviewed contract. Retain using Archive.',0,$e); }
        return;
    }
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='13') {
        require_once __DIR__.'/schema_v13.php';
        try { schema_v13_verify($pdo); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Permanent deletion unavailable: project schema differs from its reviewed contract. Retain using Archive.',0,$e); }
        return;
    }
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='12') {
        require_once __DIR__.'/schema_v12.php';
        try { schema_v12_verify($pdo); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Permanent deletion is unavailable: schema 12 differs from its reviewed contract. Retain the account using Archive.',0,$e); }
        return;
    }
    $expected=json_decode(file_get_contents(lifecycle_manifest_file($pdo)),true,512,JSON_THROW_ON_ERROR);
    $actual=lifecycle_schema_inventory($pdo);
    foreach($expected as $key=>$value) if($key==='schema_version' ? ($actual[$key]??null)!==$value :
        json_encode($actual[$key]??null,JSON_NUMERIC_CHECK)!==json_encode($value,JSON_NUMERIC_CHECK)) {
        throw new AccountLifecycleConflict('Permanent deletion is unavailable: database '.$key.' differ from the reviewed manifest. Retain the account using Archive.');
    }
    if ($actual['schema_version']==='11') {
        require_once __DIR__.'/schema_v11.php';
        try { schema_v11_verify($pdo); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Permanent deletion is unavailable: v11 guidance constraints differ. Retain the account using Archive.',0,$e); }
    }
    if ($actual['schema_version']==='10') {
        require_once __DIR__.'/schema_v10.php';
        try { schema_v10_check($pdo,schema_v10_plan()['verify']); }
        catch (RuntimeException $e) { throw new AccountLifecycleConflict('Permanent deletion is unavailable: frozen v10 constraints differ. Retain the account using Archive.',0,$e); }
    }
}

/** Direct privileges only: inherited roles and table/column-only grants are not proof. */
function lifecycle_schema_privileges(array $grants, string $schema): array
{
    $select=false; $trigger=false;
    foreach($grants as $grant) {
        if(!preg_match('/^GRANT (.+) ON (\*|`(?:``|[^`])+`)\.\* TO /i',$grant,$match)) continue;
        $scope=$match[2];
        if($scope!=='*') {
            $pattern=str_replace('``','`',substr($scope,1,-1)); $regex='';
            for($i=0;$i<strlen($pattern);++$i) {
                if($pattern[$i]==='\\' && $i+1<strlen($pattern)) $regex.=preg_quote($pattern[++$i],'/');
                else $regex.=$pattern[$i]==='%'?'.*':($pattern[$i]==='_'?'.':preg_quote($pattern[$i],'/'));
            }
            if(!preg_match('/\A'.$regex.'\z/D',$schema)) continue;
        }
        $privileges=array_map('trim',explode(',',strtoupper($match[1])));
        $all=in_array('ALL PRIVILEGES',$privileges,true);
        $select=$select || $all || in_array('SELECT',$privileges,true);
        $trigger=$trigger || $all || in_array('TRIGGER',$privileges,true);
    }
    return ['select'=>$select,'trigger'=>$trigger];
}

/** Every visible application schema must be fully inspectable; hidden schemas remain unknown. */
function lifecycle_shared_hosting_visibility(PDO $pdo): array
{
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $schemas=$pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    $schemas=array_values(array_diff($schemas,['information_schema','performance_schema','mysql','sys']));
    sort($schemas,SORT_STRING);
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if(!in_array($database,$schemas,true)) throw new AccountLifecycleConflict('PRISM schema visibility is not established.');
    $privileges=[];
    foreach($schemas as $schema) {
        $privileges[$schema]=lifecycle_schema_privileges($grants,$schema);
        if($privileges[$schema]!==['select'=>true,'trigger'=>true]) {
            throw new AccountLifecycleConflict('Shared-hosting verification requires direct schema-wide SELECT and TRIGGER visibility for every visible application database (incomplete: '.$schema.').');
        }
    }
    $slots=implode(',',array_fill(0,count($schemas),'?'));
    $tables=lifecycle_rows($pdo,"SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA IN ($slots) ORDER BY TABLE_SCHEMA,TABLE_NAME",$schemas);
    // Query KCU independently too: the RC join must not silently discard unresolved metadata.
    $keys=lifecycle_rows($pdo,"SELECT TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_SCHEMA IN ($slots) OR REFERENCED_TABLE_SCHEMA=?)
        ORDER BY TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",array_merge($schemas,[$database]));
    $rules=lifecycle_rows($pdo,"SELECT CONSTRAINT_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA IN ($slots) ORDER BY CONSTRAINT_SCHEMA,TABLE_NAME,CONSTRAINT_NAME",$schemas);
    foreach($keys as $key) {
        $resolved=array_filter($rules,fn($rule)=>$rule['CONSTRAINT_SCHEMA']===$key['TABLE_SCHEMA'] && $rule['TABLE_NAME']===$key['TABLE_NAME'] && $rule['CONSTRAINT_NAME']===$key['CONSTRAINT_NAME']);
        if(count($resolved)!==1) throw new AccountLifecycleConflict('Visible foreign-key rules cannot be resolved completely.');
        if(($key['TABLE_SCHEMA']===$database || $key['REFERENCED_TABLE_SCHEMA']===$database)
            && $key['TABLE_SCHEMA']!==$key['REFERENCED_TABLE_SCHEMA']) {
            throw new AccountLifecycleConflict('Unexpected visible cross-schema foreign key references PRISM. Retain the account using Archive.');
        }
    }
    $triggers=lifecycle_rows($pdo,"SELECT TRIGGER_SCHEMA,TRIGGER_NAME,EVENT_OBJECT_TABLE FROM INFORMATION_SCHEMA.TRIGGERS
        WHERE TRIGGER_SCHEMA IN ($slots) ORDER BY TRIGGER_SCHEMA,TRIGGER_NAME",$schemas);
    // The manifest contains no triggers. External triggers might write PRISM through a definer.
    if($triggers) throw new AccountLifecycleConflict('Unexpected visible triggers prevent shared-hosting verification. Retain the account using Archive.');
    return ['visible_schemas'=>$schemas,'schema_privileges'=>$privileges,
        'visible_metadata_sha256'=>hash('sha256',json_encode([$tables,$keys,$rules,$triggers],JSON_THROW_ON_ERROR))];
}

/** A separate random 256-bit key identifies this installation; never emit it with evidence. */
function lifecycle_verification_key(): string
{
    $key=defined('PRISM_HARD_DELETE_VERIFICATION_KEY')?PRISM_HARD_DELETE_VERIFICATION_KEY:getenv('PRISM_SCHEMA_VERIFY_KEY');
    if(!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D',$key)) {
        throw new AccountLifecycleConflict('A deployment-specific verification key is required.');
    }
    return hex2bin($key);
}

/** Bind the verifier implementation and, for v10, its additional frozen constraint checks. */
function lifecycle_verification_contract_hash(?PDO $pdo = null): string
{
    $files=[__FILE__];
    if ($pdo && (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='12') {
        $files[]=__DIR__.'/schema_v12.php'; $files[]=__DIR__.'/ierb_schema_v12.json';
    }
    if ($pdo && (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='13') {
        $files[]=__DIR__.'/schema_v12.php'; $files[]=__DIR__.'/ierb_schema_v12.json';
        $files[]=__DIR__.'/schema_v13.php'; $files[]=__DIR__.'/research_schema_v13.json';
    }
    if ($pdo && in_array((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),['14','15'],true)) {
        foreach (['schema_v12.php','ierb_schema_v12.json','schema_v13.php','research_schema_v13.json','schema_v14.php','summary_schema_v14.json'] as $file) $files[]=__DIR__.'/'.$file;
    }
    if ($pdo && (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='15') {
        $files[]=__DIR__.'/schema_v15.php'; $files[]=__DIR__.'/academic_schema_v15.json';
    }
    $manifest=json_decode(file_get_contents(lifecycle_manifest_file($pdo)),true,512,JSON_THROW_ON_ERROR);
    if(in_array($manifest['schema_version']??null,['10','11'],true)) {
        $files[]=__DIR__.'/schema_v10.php'; $files[]=__DIR__.'/schema_v10_plan.json';
    }
    if (($manifest['schema_version']??null)==='11') $files[]=__DIR__.'/schema_v11.php';
    $hashes=[];
    foreach($files as $file) {
        $hash=hash_file('sha256',$file);
        if(!is_string($hash)) throw new AccountLifecycleConflict('Verification contract is unreadable.');
        $hashes[]=basename($file).':'.$hash;
    }
    return hash('sha256',implode("\n",$hashes));
}

function lifecycle_authenticate_evidence(array $evidence): array
{
    unset($evidence['authenticator']);
    $evidence['authenticator']=hash_hmac('sha256',json_encode($evidence,JSON_THROW_ON_ERROR),lifecycle_verification_key());
    return $evidence;
}

function lifecycle_verification_evidence(PDO $pdo, string $mode): array
{
    if((int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new AccountLifecycleConflict('Verification requires database foreign-key enforcement.');
    }
    return ['evidence_version'=>3,'validity_policy'=>'deployment_invariants','verification_mode'=>$mode,'manifest_sha256'=>lifecycle_manifest_hash($pdo),
        'verification_contract_sha256'=>lifecycle_verification_contract_hash($pdo),
        'database'=>lifecycle_database_binding($pdo),'schema_changes_excluded'=>true,
        'issued_at'=>time()];
}

/** CLI/operator use only. This mode retains the original global visibility prerequisite. */
function lifecycle_privileged_verification(PDO $pdo): array
{
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $globalSelect=false; $globalTrigger=false;
    foreach($grants as $grant) if(preg_match('/^GRANT (.+) ON \*\.\* TO /i',$grant,$match)) {
        $privileges=array_map('trim',explode(',',strtoupper($match[1])));
        $globalSelect=$globalSelect || in_array('ALL PRIVILEGES',$privileges,true) || in_array('SELECT',$privileges,true);
        $globalTrigger=$globalTrigger || in_array('ALL PRIVILEGES',$privileges,true) || in_array('TRIGGER',$privileges,true);
    }
    if(!$globalSelect || !$globalTrigger) throw new AccountLifecycleConflict('Complete server metadata visibility is not established. Permanent deletion must remain disabled.');
    lifecycle_compare_schema($pdo);
    lifecycle_shared_hosting_visibility($pdo);
    return lifecycle_authenticate_evidence(lifecycle_verification_evidence($pdo,'globally_privileged')+['complete_visibility'=>true]);
}

/** Hidden cross-schema references require an explicit operational assurance, not a metadata claim. */
function lifecycle_shared_hosting_verification(PDO $pdo, bool $externalDependenciesExcluded=false): array
{
    if(!$externalDependenciesExcluded) throw new AccountLifecycleConflict('Shared-hosting verification requires explicit exclusion of hidden external dependencies.');
    $visibility=lifecycle_shared_hosting_visibility($pdo);
    lifecycle_compare_schema($pdo);
    return lifecycle_authenticate_evidence(lifecycle_verification_evidence($pdo,'schema_scoped_shared_hosting')+[
        'complete_visibility'=>false,'external_dependencies_excluded'=>true,
        'external_dependency_policy'=>'operator_excludes_hidden_cross_schema_references','visibility'=>$visibility]);
}

function lifecycle_verify_schema(PDO $pdo): void
{
    try { lifecycle_validate_verification($pdo); }
    catch(Throwable $error) {
        // Protected operator diagnostics, never database bindings or evidence contents.
        error_log('PRISM deployment verification refused ('.get_class($error).'): '.$error->getMessage());
        throw new AccountLifecycleConflict('Permanent deletion is unavailable because deployment verification is required. Contact the system operator.',0,$error);
    }
}

function lifecycle_validate_verification(PDO $pdo): void
{
    $verification=defined('PRISM_HARD_DELETE_VERIFICATION')?PRISM_HARD_DELETE_VERIFICATION:[];
    if(!defined('PRISM_HARD_DELETE_SCHEMA_VERIFIED') || PRISM_HARD_DELETE_SCHEMA_VERIFIED!==true
        || !is_array($verification) || ($verification['evidence_version']??null)!==3) {
        throw new AccountLifecycleConflict('Persistent version-3 verification is required.');
    }
    $authenticator=$verification['authenticator']??null;
    if(!is_string($authenticator) || !preg_match('/\A[0-9a-f]{64}\z/D',$authenticator)
        || !hash_equals(lifecycle_authenticate_evidence($verification)['authenticator'],$authenticator)) {
        throw new AccountLifecycleConflict('Verification authentication failed.');
    }
    if(($verification['validity_policy']??null)!=='deployment_invariants'
        || array_key_exists('expires_at',$verification)
        || !is_int($verification['issued_at']??null) || $verification['issued_at']<1
        || ($verification['schema_changes_excluded']??false)!==true
        || !is_string($verification['manifest_sha256']??null)
        || !hash_equals(lifecycle_manifest_hash($pdo),$verification['manifest_sha256'])
        || !is_string($verification['verification_contract_sha256']??null)
        || !hash_equals(lifecycle_verification_contract_hash($pdo),$verification['verification_contract_sha256'])
        || ($verification['database']??null)!==lifecycle_database_binding($pdo)) {
        throw new AccountLifecycleConflict('Permanent deletion is disabled: valid deployment-bound schema verification is required. Retain the account using Archive.');
    }
    $mode=$verification['verification_mode']??null;
    $fields=['evidence_version','validity_policy','verification_mode','manifest_sha256','verification_contract_sha256',
        'database','schema_changes_excluded','issued_at','complete_visibility','authenticator'];
    if($mode==='schema_scoped_shared_hosting') {
        $fields=array_merge($fields,['external_dependencies_excluded','external_dependency_policy','visibility']);
    }
    $present=array_keys($verification); sort($present); sort($fields);
    if($present!==$fields) throw new AccountLifecycleConflict('Verification evidence fields are invalid.');
    if($mode==='globally_privileged') {
        if(($verification['complete_visibility']??null)!==true || isset($verification['visibility'])
            || isset($verification['external_dependency_policy']) || isset($verification['external_dependencies_excluded'])) {
            throw new AccountLifecycleConflict('Global schema verification evidence is invalid.');
        }
        // The web connection may be scoped even after a global operator review.
        // Direct visibility is still mandatory for every application schema it can see.
        lifecycle_shared_hosting_visibility($pdo);
    } elseif($mode==='schema_scoped_shared_hosting') {
        if(($verification['complete_visibility']??null)!==false || ($verification['external_dependencies_excluded']??false)!==true
            || ($verification['external_dependency_policy']??null)!=='operator_excludes_hidden_cross_schema_references'
            || ($verification['visibility']??null)!==lifecycle_shared_hosting_visibility($pdo)) {
            throw new AccountLifecycleConflict('Shared-hosting schema verification evidence is invalid or visible metadata has changed. Repeat operator verification.');
        }
    } else {
        throw new AccountLifecycleConflict('Permanent deletion is disabled: unrecognized schema verification mode.');
    }
    if((int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new AccountLifecycleConflict('Permanent deletion requires database foreign-key enforcement.');
    }
    lifecycle_compare_schema($pdo);
}

/** UI availability is only the deployment gate, never per-account deletion eligibility. */
function lifecycle_schema_availability(PDO $pdo): array
{
    try {
        lifecycle_verify_schema($pdo);
        return ['available'=>true,'verificationMode'=>PRISM_HARD_DELETE_VERIFICATION['verification_mode'],
            'message'=>'Permanent deletion available. Each account must still meet retention and eligibility requirements.'];
    } catch(AccountLifecycleConflict $error) {
        return ['available'=>false,'message'=>$error->getMessage()];
    } catch(Throwable $error) {
        return ['available'=>false,'message'=>'Schema verification could not be checked. Permanent deletion is unavailable; retain the account using Archive.'];
    }
}
