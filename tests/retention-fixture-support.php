<?php
/** Fixture journals are empty. Actual recovery/refusal tests use private MariaDB/storage. */
require_once __DIR__.'/../includes/account_lifecycle.php';
function stage_label(string $stage):string {
    $callback=($GLOBALS['retentionFixtureNamespace']??'').'\\stage_label';
    return is_callable($callback)?$callback($stage):$stage;
}
function retention_fixture_support(string $namespace,bool $sqlite=false): void {
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/',$namespace))throw new RuntimeException('Invalid fixture namespace');
    $GLOBALS['retentionFixtureNamespace']=$namespace;
    eval('namespace '.$namespace.'; use \\PDO; function purge_require_no_pending(PDO $pdo,string $type,int $id): void {
        if(!$pdo->inTransaction())throw new \\RuntimeException("Recovery guard requires locked transaction");
    }
    function retention_state(array $r):array{return \\retention_state($r);}
    function retention_projection(string $type,string $a):string{return \\retention_fixture_sql(\\retention_projection($type,$a));}
    function retention_list_scope(PDO $pdo,array $actor,string $type,array $query):array{return \\retention_list_scope($pdo,$actor,$type,$query);}
    function lifecycle_error_status(\\Throwable $e):int{return \\lifecycle_error_status($e);}
    ');
    if (!$sqlite)return;
    $pdo=$GLOBALS['pdo'];
    foreach(['students'=>['user_id'=>'INTEGER','retention_hold'=>'INTEGER DEFAULT 0','retention_hold_reason'=>'TEXT','purge_postponed_reason'=>'TEXT'],
        'advisers'=>['user_id'=>'INTEGER','archived_at'=>'TEXT','retention_hold'=>'INTEGER DEFAULT 0'],
        'reports'=>['owner_student_id'=>'INTEGER'], 'notifications'=>['recipient_type'=>'TEXT','recipient_id'=>'INTEGER','recipient_email'=>'TEXT','recipient_name'=>'TEXT','status'=>'TEXT'],
        'documents'=>['student_id'=>'INTEGER','is_current'=>'INTEGER','rpms_submitted_at'=>'TEXT'],
        'users'=>['id'=>'INTEGER','role'=>'TEXT','email'=>'TEXT'],
        'calendar_deadlines'=>['creator_user_id'=>'INTEGER','status'=>'TEXT','deadline_date'=>'TEXT']] as $table=>$columns) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' (retention_fixture_marker INTEGER)');
        $existing=array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($columns as $column=>$type)if(!in_array($column,$existing,true))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$type);
    }
    $pdo->sqliteCreateFunction('CURDATE',fn()=>date('Y-m-d'));
}
/** Only date syntax is adapted. Authorization/date boundaries use real MariaDB tests. */
function retention_fixture_sql(string $sql): string {
    $sql=preg_replace("/DATE_ADD\((\w+\.archived_at),INTERVAL (\d+) (DAY|MONTH)\)/", "datetime($1,'+$2 $3')",$sql);
    $sql=str_replace(",INTERVAL 3 DAY)",",'-3 DAY')",str_replace('DATE_SUB(','datetime(',$sql));
    return str_replace('NOW()',"datetime('now')",$sql);
}
