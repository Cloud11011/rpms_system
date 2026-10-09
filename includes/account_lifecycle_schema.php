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

function lifecycle_manifest_hash(): string
{
    return hash_file('sha256',__DIR__.'/account_lifecycle_schema.json');
}

function lifecycle_database_binding(PDO $pdo): array
{
    $row=$pdo->query('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    return array_map('strval',$row);
}

function lifecycle_compare_schema(PDO $pdo): void
{
    $expected=json_decode(file_get_contents(__DIR__.'/account_lifecycle_schema.json'),true,512,JSON_THROW_ON_ERROR);
    $actual=lifecycle_schema_inventory($pdo);
    foreach($expected as $key=>$value) if($key==='schema_version' ? ($actual[$key]??null)!==$value :
        json_encode($actual[$key]??null,JSON_NUMERIC_CHECK)!==json_encode($value,JSON_NUMERIC_CHECK)) {
        throw new AccountLifecycleConflict('Permanent deletion is unavailable: database '.$key.' differ from the reviewed manifest. Retain the account using Archive.');
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

function lifecycle_verification_evidence(PDO $pdo, string $mode): array
{
    $issued=time();
    return ['evidence_version'=>2,'verification_mode'=>$mode,'manifest_sha256'=>lifecycle_manifest_hash(),
        'database'=>lifecycle_database_binding($pdo),'schema_changes_excluded'=>true,
        'issued_at'=>$issued,'expires_at'=>$issued+86400];
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
    return lifecycle_verification_evidence($pdo,'globally_privileged')+['complete_visibility'=>true];
}

/** Hidden cross-schema references require an explicit operational assurance, not a metadata claim. */
function lifecycle_shared_hosting_verification(PDO $pdo, bool $externalDependenciesExcluded=false): array
{
    if(!$externalDependenciesExcluded) throw new AccountLifecycleConflict('Shared-hosting verification requires explicit exclusion of hidden external dependencies.');
    $visibility=lifecycle_shared_hosting_visibility($pdo);
    lifecycle_compare_schema($pdo);
    return lifecycle_verification_evidence($pdo,'schema_scoped_shared_hosting')+[
        'complete_visibility'=>false,'external_dependencies_excluded'=>true,
        'external_dependency_policy'=>'operator_excludes_hidden_cross_schema_references','visibility'=>$visibility];
}

function lifecycle_verify_schema(PDO $pdo): void
{
    $verification=defined('PRISM_HARD_DELETE_VERIFICATION')?PRISM_HARD_DELETE_VERIFICATION:[];
    if(!defined('PRISM_HARD_DELETE_SCHEMA_VERIFIED') || PRISM_HARD_DELETE_SCHEMA_VERIFIED!==true
        || !is_array($verification) || ($verification['evidence_version']??null)!==2
        || ($verification['schema_changes_excluded']??false)!==true
        || !is_string($verification['manifest_sha256']??null)
        || !hash_equals(lifecycle_manifest_hash(),$verification['manifest_sha256'])
        || ($verification['database']??null)!==lifecycle_database_binding($pdo)) {
        throw new AccountLifecycleConflict('Permanent deletion is disabled: valid deployment-bound schema verification is required. Retain the account using Archive.');
    }
    if(!is_int($verification['issued_at']??null) || !is_int($verification['expires_at']??null)
        || $verification['issued_at']>time()+60 || $verification['expires_at']!==$verification['issued_at']+86400
        || $verification['expires_at']<=time()) {
        throw new AccountLifecycleConflict('Permanent deletion is disabled: schema verification evidence has expired or has invalid dates. Repeat operator verification.');
    }
    $mode=$verification['verification_mode']??null;
    if($mode==='globally_privileged') {
        if(($verification['complete_visibility']??null)!==true || isset($verification['visibility'])
            || isset($verification['external_dependency_policy']) || isset($verification['external_dependencies_excluded'])) {
            throw new AccountLifecycleConflict('Global schema verification evidence is invalid.');
        }
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
            'message'=>'Schema verification is valid. Each deletion still requires all account safety checks.'];
    } catch(AccountLifecycleConflict $error) {
        return ['available'=>false,'message'=>$error->getMessage()];
    } catch(Throwable $error) {
        return ['available'=>false,'message'=>'Schema verification could not be checked. Permanent deletion is unavailable; retain the account using Archive.'];
    }
}
