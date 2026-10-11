<?php
/** Fresh private fixture equals the frozen manifest. This is not an application migration. */
if (!isset($pdo,$datadir) || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($datadir)) exit(1);
require_once __DIR__.'/../includes/account_lifecycle.php';
$pdo->exec('CREATE DATABASE b7_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE b7_fixture'); $pdo->exec("SET time_zone='+08:00'"); date_default_timezone_set('Asia/Manila');
$manifest=json_decode(file_get_contents(__DIR__.'/../includes/account_lifecycle_schema.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($manifest['tables'] as $table) {
    $name=$table['TABLE_NAME']; $parts=[];
    foreach ($manifest['columns'] as $column) if ($column['TABLE_NAME']===$name) {
        $sql='`'.$column['COLUMN_NAME'].'` '.$column['COLUMN_TYPE'];
        if ($column['EXTRA']==='STORED GENERATED') $sql.=" GENERATED ALWAYS AS (CASE WHEN state IN ('draft','pending') THEN 1 ELSE NULL END) STORED";
        else {
            $sql.=$column['IS_NULLABLE']==='YES'?' NULL':' NOT NULL';
            if ($column['COLUMN_DEFAULT']!==null) $sql.=' DEFAULT '.$column['COLUMN_DEFAULT'];
            if ($column['EXTRA']!=='') $sql.=' '.$column['EXTRA'];
        }
        $parts[]=$sql;
    }
    $indexes=[];
    foreach ($manifest['indexes'] as $index) if ($index['TABLE_NAME']===$name) $indexes[$index['INDEX_NAME']][]=$index;
    foreach ($indexes as $key=>$columns) {
        usort($columns,fn($a,$b)=>$a['SEQ_IN_INDEX']<=>$b['SEQ_IN_INDEX']);
        $parts[]=($key==='PRIMARY'?'PRIMARY KEY':($columns[0]['NON_UNIQUE']?'KEY':'UNIQUE KEY').' `'.$key.'`')
            .' ('.implode(',',array_map(fn($c)=>'`'.$c['COLUMN_NAME'].'`'.($c['SUB_PART']?'('.$c['SUB_PART'].')':''),$columns)).')';
    }
    if ($name==='staff_registration_code') $parts[]='CONSTRAINT staff_code_singleton CHECK(slot=1)';
    $pdo->exec('CREATE TABLE `'.$name.'` ('.implode(',',$parts).') ENGINE=InnoDB');
}
$keys=[];
foreach ($manifest['foreign_keys'] as $fk) $keys[$fk['TABLE_NAME'].'.'.$fk['CONSTRAINT_NAME']][]=$fk;
foreach ($keys as $components) {
    $fk=$components[0];
    $pdo->exec('ALTER TABLE `'.$fk['TABLE_NAME'].'` ADD CONSTRAINT `'.$fk['CONSTRAINT_NAME'].'`
        FOREIGN KEY ('.implode(',',array_map(fn($c)=>'`'.$c['COLUMN_NAME'].'`',$components)).') REFERENCES `'.$fk['REFERENCED_TABLE_NAME'].'`
        ('.implode(',',array_map(fn($c)=>'`'.$c['REFERENCED_COLUMN_NAME'].'`',$components)).') ON DELETE '.$fk['DELETE_RULE'].' ON UPDATE '.$fk['UPDATE_RULE']);
}
$pdo->exec("INSERT INTO schema_meta(k,v) VALUES ('schema_version','10')");
$seed=json_decode(file_get_contents(__DIR__.'/../includes/schema_v10_seed.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($seed as $policy=>$value) {
    $pdo->prepare('INSERT INTO legal_policies(id) VALUES (?)')->execute([$policy]);
    $pdo->prepare("INSERT INTO legal_policy_versions(policy_id,version_number,title,content,content_sha256,state,change_summary,published_at)
        VALUES (?,1,?,?,?,'published','Approved frozen fixture seed',NOW())")->execute([$policy,$value['title'],$value['content'],$value['content_sha256']]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO legal_policy_approvals(version_id,decision,approval_mode) VALUES (?,'approved','bootstrap')")->execute([$id]);
    $pdo->prepare('UPDATE legal_policies SET current_version_id=? WHERE id=?')->execute([$id,$policy]);
}
lifecycle_compare_schema($pdo);
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',true);
define('PRISM_HARD_DELETE_VERIFICATION',lifecycle_privileged_verification($pdo));
define('STORAGE_DIR',$root.'/storage'); mkdir(STORAGE_DIR);
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
function stage_label(string $stage): string { return $stage; }
reset_migration_expect('10',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Private fixture version is v10');
