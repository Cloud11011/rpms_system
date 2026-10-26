<?php
/** TEST FIXTURE ONLY. Full present v11 manifest, never historical migration contracts. */
if (PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__,2);
$review=$root.'/PRISM_V13_GROUP_ADVANCEMENT_REVIEW';
$pdo=new PDO('mysql:host=127.0.0.1;port=13527;charset=utf8mb4','root','prism_synthetic_only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$datadir=str_replace('\\','/',(string)$pdo->query('SELECT @@datadir')->fetchColumn());
if (!str_contains(strtolower($datadir),strtolower(str_replace('\\','/',$review).'/sandbox/mysql/')))
    throw new RuntimeException('Refusing a database outside the disposable audit datadir.');
$database='prism_group_synthetic_v11';
if ((int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$database'")->fetchColumn())
    throw new RuntimeException('Fixture already exists; refusing to overwrite it.');
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$database`"); $pdo->exec("SET time_zone='+08:00'");
$m=json_decode(file_get_contents($root.'/includes/account_lifecycle_schema_v11.json'),true,512,JSON_THROW_ON_ERROR);
$identifier=static fn($v)=>'`'.str_replace('`','``',$v).'`';
foreach ($m['tables'] as $table) {
    $name=$table['TABLE_NAME']; $definitions=[];
    foreach ($m['columns'] as $column) if ($column['TABLE_NAME']===$name) {
        $definition=$identifier($column['COLUMN_NAME']).' '.$column['COLUMN_TYPE'];
        if ($column['EXTRA']==='STORED GENERATED') {
            if ($name!=='legal_policy_versions' || $column['COLUMN_NAME']!=='open_candidate') throw new RuntimeException('Unknown generated fixture column.');
            $definition.=" GENERATED ALWAYS AS (CASE WHEN state IN ('draft','pending') THEN 1 ELSE NULL END) STORED";
        } else {
            $definition.=$column['IS_NULLABLE']==='NO'?' NOT NULL':' NULL';
            if ($column['COLUMN_DEFAULT']!==null) $definition.=' DEFAULT '.$column['COLUMN_DEFAULT'];
            if ($column['EXTRA']!=='') $definition.=' '.$column['EXTRA'];
        }
        $definitions[]=$definition;
    }
    $indexes=[];
    foreach ($m['indexes'] as $index) if ($index['TABLE_NAME']===$name) $indexes[$index['INDEX_NAME']][]=$index;
    foreach ($indexes as $key=>$parts) {
        $columns=array_map(static fn($p)=>$identifier($p['COLUMN_NAME']).($p['SUB_PART']?'('.$p['SUB_PART'].')':''),$parts);
        $definitions[]=($key==='PRIMARY'?'PRIMARY KEY':(!$parts[0]['NON_UNIQUE']?'UNIQUE KEY ':'KEY ').$identifier($key)).' ('.implode(',',$columns).')';
    }
    if ($name==='staff_registration_code') $definitions[]='CONSTRAINT staff_code_singleton CHECK (slot=1)';
    $pdo->exec('CREATE TABLE '.$identifier($name).' ('.implode(',',$definitions).') ENGINE=InnoDB');
}
$foreign=[];
foreach ($m['foreign_keys'] as $fk) $foreign[$fk['TABLE_NAME']][$fk['CONSTRAINT_NAME']][]=$fk;
foreach ($foreign as $table=>$constraints) foreach ($constraints as $key=>$parts) {
    $f=$parts[0];
    $pdo->exec('ALTER TABLE '.$identifier($table).' ADD CONSTRAINT '.$identifier($key).' FOREIGN KEY ('
        .implode(',',array_map(static fn($p)=>$identifier($p['COLUMN_NAME']),$parts)).') REFERENCES '.$identifier($f['REFERENCED_TABLE_NAME']).' ('
        .implode(',',array_map(static fn($p)=>$identifier($p['REFERENCED_COLUMN_NAME']),$parts)).') ON UPDATE '.$f['UPDATE_RULE'].' ON DELETE '.$f['DELETE_RULE']);
}
$pdo->exec("INSERT INTO schema_meta(k,v) VALUES ('schema_version','11')");
require_once $root.'/includes/account_lifecycle.php';
lifecycle_compare_schema($pdo); // Exact manifest and additional frozen-v10 structure, BEFORE accounts/test data.
$schemaEvidence=['database'=>$database,'server'=>'127.0.0.1:13527','version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'schema_version'=>'11','table_count'=>count($m['tables']),'exact_manifest_and_v11_structure'=>'PASS',
    'source'=>'Full current v11 manifest plus present generated-column and singleton definitions; synthetic fixture, not a migration or deployed-schema snapshot.'];
file_put_contents($review.'/isolated-schema-verification.json',json_encode($schemaEvidence,JSON_PRETTY_PRINT));
$password=password_hash('SyntheticPass123!',PASSWORD_ARGON2ID);
foreach ([1=>'admin',2=>'adviser',3=>'adviser',4=>'student',5=>'student'] as $id=>$role) {
    $email=($role==='admin'?'admin':$role.$id).'@example.test'; $code=($role==='student'?'S':'E').$id;
    $pdo->prepare("INSERT INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) VALUES (?,?,?,?,?,?,?,'Active','2026-01-01')")
        ->execute([$id,$code,'Synthetic '.ucfirst($role).' '.$id,$email,$role,$code,$password]);
}
foreach ([2,3] as $id) $pdo->prepare("INSERT INTO advisers(id,user_id,employee_id,full_name,email,status,profile_completed_at,created_at)
    VALUES (?,?,?,?,?,'Active','2026-01-01','2026-01-01')")->execute([$id,$id,'E'.$id,'Synthetic Adviser '.$id,'adviser'.$id.'@example.test']);
foreach ([4=>2,5=>3] as $id=>$adviser) $pdo->prepare("INSERT INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,
    research_group,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at)
    VALUES (?,?,?,?,?,?,'Stage 1','Pending',?,'Bachelor of Science in Computer Science','amt','bs_computer_science','4th Year','2026-2027','2026-01-01','2026-01-01')")
    ->execute([$id,$id,'S'.$id,'Synthetic Student '.$id,'student'.$id.'@example.test',$adviser,'2026-2027-4CS-'.$id]);
echo json_encode($schemaEvidence,JSON_PRETTY_PRINT).PHP_EOL;
