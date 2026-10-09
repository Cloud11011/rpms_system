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

/** CLI/operator use only. Ordinary schema-scoped web credentials are intentionally insufficient. */
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
    return ['manifest_sha256'=>lifecycle_manifest_hash(), 'database'=>lifecycle_database_binding($pdo),
        'complete_visibility'=>true, 'schema_changes_excluded'=>true];
}

function lifecycle_verify_schema(PDO $pdo): void
{
    $verification=defined('PRISM_HARD_DELETE_VERIFICATION')?PRISM_HARD_DELETE_VERIFICATION:[];
    if(!defined('PRISM_HARD_DELETE_SCHEMA_VERIFIED') || PRISM_HARD_DELETE_SCHEMA_VERIFIED!==true
        || !is_array($verification) || ($verification['complete_visibility']??false)!==true
        || ($verification['schema_changes_excluded']??false)!==true
        || !hash_equals(lifecycle_manifest_hash(),(string)($verification['manifest_sha256']??''))
        || ($verification['database']??null)!==lifecycle_database_binding($pdo)) {
        throw new AccountLifecycleConflict('Permanent deletion is disabled until this deployment has explicit complete schema verification. Retain the account using Archive.');
    }
    lifecycle_compare_schema($pdo);
}
