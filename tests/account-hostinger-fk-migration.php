<?php
/** Genuine-v8 FK compatibility tests; only the runner's verified disposable server. */
if(PHP_SAPI!=='cli' || !isset($pdo,$datadir,$port,$password) || !function_exists('reset_migration_expect')) exit(1);
$hostingerStart=$GLOBALS['checks'];
class HostingerFkMigrationPDO extends PDO {
    public ?string $failBefore=null;
    public ?string $failAfter=null;
    public array $normalizations=[];
    private function checkpoint(string $sql): void {
        if($this->failBefore!==null && str_contains($sql,$this->failBefore)) throw new RuntimeException('Synthetic normalization failure');
    }
    public function exec(string $sql): int|false {
        $this->checkpoint($sql);
        if(str_starts_with($sql,'ALTER TABLE') && str_contains($sql,'DROP FOREIGN KEY') && str_contains($sql,'ADD CONSTRAINT')) {
            $this->normalizations[]=['checks'=>(int)parent::query('SELECT @@SESSION.foreign_key_checks')->fetchColumn(),
                'lock'=>(int)parent::query("SELECT COALESCE(IS_USED_LOCK('prism_migrate')=CONNECTION_ID(),0)")->fetchColumn()];
        }
        $result=parent::exec($sql);
        if($this->failAfter!==null && str_contains($sql,$this->failAfter)) throw new RuntimeException('Synthetic normalization failure');
        return $result;
    }
    public function prepare(string $sql,array $options=[]): PDOStatement|false { $this->checkpoint($sql);return parent::prepare($sql,$options); }
    public function query(string $sql,?int $mode=null,mixed ...$args): PDOStatement|false {
        $this->checkpoint($sql);return $mode===null?parent::query($sql):parent::query($sql,$mode,...$args);
    }
}
function hostinger_fk_manual_run(PDO $db,string $script): void {
    $script=preg_replace('/^--.*$/m','',$script);
    $script=str_replace(['DELIMITER $$','DELIMITER ;'],'',$script);
    foreach(explode('$$',$script) as $statement) if(trim($statement)!=='') $db->exec(trim($statement));
}
function hostinger_fk_fixture(HostingerFkMigrationPDO $db,string $schema,array $legacy): array {
    static $v8=null;
    $db->exec('CREATE DATABASE `'.$schema.'`');$db->exec('USE `'.$schema.'`');
    $GLOBALS['allowV9']=false;
    if($v8===null) {
        PrismResetMigrationSQL\migrate($db);$v8=[];
        // Cache genuine-v8 DDL/data, creating parents first for every independent case.
        foreach(['schema_meta','users','advisers','students','password_resets','documents','ierb_history','notifications',
            'reports','ai_outputs','activity_logs','stage_labels','calendar_deadlines','calendar_deadline_groups','calendar_deadline_recipients'] as $t) {
            $create=$db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
            $v8[$t]=[$create,$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)];
        }
    } else foreach($v8 as $t=>[$create,$rows]) {
        $db->exec($create);
        foreach($rows as $row) {
            $columns='`'.implode('`,`',array_keys($row)).'`';
            $db->prepare('INSERT INTO `'.$t.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        }
    }
    $GLOBALS['allowV9']=true;
    reset_migration_expect('8',$db->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Genuine v8 fixture');
    $db->exec("INSERT INTO users(id,username,password_hash,role,full_name,email,ref_id) VALUES
        (1,'HOST-S','synthetic','student','Hostinger Student','host-s@example.invalid','HOST-S'),
        (2,'HOST-A','synthetic','adviser','Hostinger Adviser','host-a@example.invalid','HOST-A')");
    $db->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id) VALUES (1,'HOST-A','Hostinger Adviser','host-a@example.invalid',2)");
    $db->exec("INSERT INTO students(id,student_id,full_name,email,user_id,adviser_id,research_title,created_at)
        VALUES (1,'HOST-S','Hostinger Student','host-s@example.invalid',1,1,'Preserved legacy research','2025-01-02 03:04:05')");
    $db->exec("INSERT INTO calendar_deadlines(id,creator_user_id,title,deadline_date,target_scope) VALUES (1,1,'Preserved deadline','2026-12-10','groups')");
    $db->exec("INSERT INTO calendar_deadline_groups(deadline_id,research_group) VALUES (1,'Preserved Group')");
    $db->exec('INSERT INTO calendar_deadline_recipients(deadline_id,student_id) VALUES (1,1)');
    // 12.1 generates numeric names for unnamed FKs. Model the supplied historical-v8
    // inventory explicitly, including its six unchanged canonical relationships.
    $expected=json_decode(file_get_contents(__DIR__.'/../tools/schema-v9-contract.json'),true,512,JSON_THROW_ON_ERROR)['foreign_keys'];
    foreach($expected as $fk) {
        if($fk['TABLE_NAME']==='account_invitations')continue;
        $t=$fk['TABLE_NAME'];$c=$fk['COLUMN_NAME'];$name=$fk['CONSTRAINT_NAME'];
        $aliases=[['calendar_deadlines','creator_user_id','1'],['calendar_deadline_groups','deadline_id','1'],
            ['calendar_deadline_recipients','deadline_id','fk_deadline_recipient_deadline'],['calendar_deadline_recipients','student_id','fk_deadline_recipient_student']];
        foreach($legacy as $i) { $m=$aliases[$i];if($m[0]===$t && $m[1]===$c)$name=$m[2]; }
        $q=$db->prepare('SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
        $q->execute([$t,$c]);$old=(string)$q->fetchColumn();
        $parent=$fk['REFERENCED_TABLE_NAME'];$target=$fk['REFERENCED_COLUMN_NAME'];$u=$fk['UPDATE_RULE'];$d=$fk['DELETE_RULE'];
        if($old!==$name)$db->exec("ALTER TABLE `$t` DROP FOREIGN KEY `$old`, ADD CONSTRAINT `$name` FOREIGN KEY (`$c`) REFERENCES `$parent` (`$target`) ON UPDATE $u ON DELETE $d");
    }
    // Reproduce historical v8 supporting-index names as well. New 12.1 implicit
    // FK indexes use constraint names, unlike the older server that created v8.
    $expectedIndexes=[];
    foreach(json_decode(file_get_contents(__DIR__.'/../tools/schema-v9-contract.json'),true)['indexes'] as $index) {
        $key=$index['TABLE_NAME'].':'.$index['INDEX_NAME'];$expectedIndexes[$key][]=$index;
    }
    $actualIndexes=[];
    foreach(lifecycle_schema_inventory($db)['indexes'] as $index)$actualIndexes[$index['TABLE_NAME'].':'.$index['INDEX_NAME']][]=$index;
    foreach($actualIndexes as $key=>$entries) {
        if(isset($expectedIndexes[$key]))continue;
        foreach($expectedIndexes as $canonicalKey=>$expectedEntries) {
            $shape=function(array $items):array { foreach($items as &$item)unset($item['INDEX_NAME']);unset($item);return $items; };
            if($shape($entries)!==$shape($expectedEntries))continue;
            $t=$entries[0]['TABLE_NAME'];$oldIndex=$entries[0]['INDEX_NAME'];$newIndex=$expectedEntries[0]['INDEX_NAME'];
            $db->exec("ALTER TABLE `$t` RENAME INDEX `$oldIndex` TO `$newIndex`");break;
        }
    }
    $snapshot=[];
    foreach($db->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if($table!=='schema_meta') $snapshot[$table]=$db->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    $db->normalizations=[];return $snapshot;
}
function hostinger_fk_semantics(PDO $db): array {
    $fks=lifecycle_schema_inventory($db)['foreign_keys'];$result=[];
    foreach($fks as $fk) {if($fk['TABLE_NAME']==='account_invitations')continue;unset($fk['CONSTRAINT_NAME']);$result[]=$fk;}
    usort($result,fn($a,$b)=>[$a['TABLE_NAME'],$a['COLUMN_NAME']]<=>[$b['TABLE_NAME'],$b['COLUMN_NAME']]);return $result;
}
function hostinger_fk_preserved(PDO $db,array $before,bool $backfilled=true): void {
    foreach($before as $table=>$rows) {
        $after=$db->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        reset_migration_expect(count($rows),count($after),'Preserved '.$table.' row count');
        foreach($rows as $i=>$row) foreach($row as $field=>$value) {
            // Existing identity/creator-name backfills fire these ON UPDATE timestamps.
            if($backfilled && in_array($table,['students','advisers','calendar_deadlines'],true) && $field==='updated_at')reset_migration_expect(true,$after[$i][$field]>=$value,'Existing backfill timestamp remains monotonic');
            else reset_migration_expect($value,$after[$i][$field],'Preserved '.$table.'.'.$field);
        }
    }
}
function hostinger_fk_run(HostingerFkMigrationPDO $db,string $driver,string $schema,?string $phase=null): void {
    if($driver==='php') {
        if($phase==='before')$db->failBefore='ALTER TABLE `calendar_deadlines` DROP FOREIGN KEY';
        if($phase==='after')$db->failAfter='ALTER TABLE `calendar_deadlines` DROP FOREIGN KEY';
        if($phase==='backfill')$db->failBefore='UPDATE students p JOIN users';
        if($phase==='stamp')$db->failBefore='REPLACE INTO schema_meta';
        PrismResetMigrationSQL\migrate($db);return;
    }
    $script=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../tools/schema-v9-retention-manual.sql'));
    $script=str_replace(["'REPLACE_WITH_EXACT_DATABASE_NAME'",'SET @PRISM_V9_BACKUP_AND_STAGING_VERIFIED = 0'],["'$schema'",'SET @PRISM_V9_BACKUP_AND_STAGING_VERIFIED = 1'],$script);
    $ddl="ALTER TABLE `calendar_deadlines` DROP FOREIGN KEY `1`, ADD CONSTRAINT `calendar_deadlines_ibfk_1`\n            FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON UPDATE RESTRICT ON DELETE SET NULL;";
    $signal="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic normalization failure';";
    if(in_array($phase,['before','after'],true) && substr_count($script,$ddl)!==1)throw new RuntimeException('Synthetic failure injection target missing');
    if($phase==='before')$script=str_replace($ddl,$signal.' '.$ddl,$script);
    if($phase==='after')$script=str_replace($ddl,$ddl.' '.$signal,$script);
    if($phase==='backfill')$script=str_replace('    UPDATE students p JOIN users','    '.$signal.' UPDATE students p JOIN users',$script);
    if($phase==='stamp')$script=str_replace('    REPLACE INTO schema_meta','    '.$signal.' REPLACE INTO schema_meta',$script);
    if($phase==='guard') {
        $db->exec("SET @fixture_fk_guards=''");
        $observe="SET @fixture_fk_guards=CONCAT(@fixture_fk_guards,IF(@@SESSION.foreign_key_checks=1,1,0),COALESCE(IS_USED_LOCK('prism_migrate')=CONNECTION_ID(),0));";
        foreach(PrismResetMigrationSQL\migration_v9_legacy_foreign_keys() as $m) {
            $needle='        ALTER TABLE `'.$m[0].'` DROP FOREIGN KEY `'.$m[2].'`';
            $script=str_replace($needle,'        '.$observe."\n".$needle,$script);
        }
    }
    hostinger_fk_manual_run($db,$script);
}
function hostinger_fk_clean(HostingerFkMigrationPDO $db,string $schema): void {
    $db->failBefore=$db->failAfter=null;
    $db->exec('USE mysql');$db->exec('DROP DATABASE `'.$schema.'`');
}
$hostingerDb=new HostingerFkMigrationPDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
reset_migration_expect(realpath($datadir),realpath($hostingerDb->query('SELECT @@datadir')->fetchColumn()),'FK fixture private datadir ownership');
$version=(string)$hostingerDb->query('SELECT VERSION()')->fetchColumn();$perTable=version_compare($version,'12.1','>=');
$seq=0;$maps=PrismResetMigrationSQL\migration_v9_legacy_foreign_keys();
$guardOnly=$argv[1]==='--hostinger-fk-guards';
$retryOnly=$argv[1]==='--hostinger-fk-retries' || $guardOnly;
foreach($guardOnly?['manual']:['php','manual'] as $driver) {
    $driverStart=$GLOBALS['checks'];
    // 10.4 still exercises each literal name 1, separately; 12.1 runs the exact combined fixture.
    foreach($retryOnly?[]:($perTable?[[0,1,2,3]]:[[0,2,3],[1,2,3]]) as $legacy) {
        $GLOBALS['hostingerFkContext']=$driver.' positive '.implode(',',$legacy);
        $schema='hostinger_fk_'.(++$seq);$before=hostinger_fk_fixture($hostingerDb,$schema,$legacy);$semantics=hostinger_fk_semantics($hostingerDb);
        foreach($legacy as $i) {
            $names=array_column(array_filter(lifecycle_schema_inventory($hostingerDb)['foreign_keys'],fn($fk)=>$fk['TABLE_NAME']===$maps[$i][0]&&$fk['COLUMN_NAME']===$maps[$i][1]),'CONSTRAINT_NAME');
            reset_migration_expect([$maps[$i][2]],$names,'Exact legacy fixture name');
        }
        hostinger_fk_run($hostingerDb,$driver,$schema);lifecycle_compare_schema($hostingerDb);
        reset_migration_expect('9',$hostingerDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Hostinger v8 advances to exact final v9');
        reset_migration_expect(12,count(lifecycle_schema_inventory($hostingerDb)['foreign_keys']),'Exactly twelve FK components');
        reset_migration_expect($semantics,hostinger_fk_semantics($hostingerDb),'All source/target schemas/tables/columns/actions preserved');
        hostinger_fk_preserved($hostingerDb,$before);
        reset_migration_expect('2025-01-02 03:04:05',$hostingerDb->query('SELECT profile_completed_at FROM students WHERE id=1')->fetchColumn(),'Legacy identity backfilled before successful stamp');
        $inventory=lifecycle_schema_inventory($hostingerDb);$data=$hostingerDb->query('SELECT * FROM students')->fetchAll();
        hostinger_fk_run($hostingerDb,$driver,$schema);
        reset_migration_expect($inventory,lifecycle_schema_inventory($hostingerDb),'Completed migration idempotent');
        reset_migration_expect($data,$hostingerDb->query('SELECT * FROM students')->fetchAll(),'Completed retry preserves backfill');
        reset_migration_expect(1,(int)$hostingerDb->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn(),'Successful migration retains FK checks');
        foreach($hostingerDb->normalizations as $state)reset_migration_expect(['checks'=>1,'lock'=>1],$state,'PHP normalization holds lock/enforcement');
        hostinger_fk_clean($hostingerDb,$schema);
    }
    foreach($retryOnly?[]:['target_table','target_column','update_rule','delete_rule','unexpected_name','duplicate','missing','source_column','referenced_schema','composite','canonical_target'] as $fault)foreach($maps as $i=>$m) {
        $GLOBALS['hostingerFkContext']=$driver.' '.$fault.' '.$i;
        [$t,$c,$old,$canonical,$parent,$target,$u,$d]=$m;
        $schema='hostinger_fk_'.(++$seq);hostinger_fk_fixture($hostingerDb,$schema,[$i]);
        if($fault==='duplicate')$hostingerDb->exec("ALTER TABLE `$t` ADD CONSTRAINT `$canonical` FOREIGN KEY (`$c`) REFERENCES `$parent` (`$target`) ON UPDATE $u ON DELETE $d");
        else {
            $hostingerDb->exec("ALTER TABLE `$t` DROP FOREIGN KEY `$old`");
            if($fault==='target_table')$parent=$parent==='users'?'students':'users';
            if($fault==='canonical_target') { $old=$canonical;$parent=$parent==='users'?'students':'users'; }
            if($fault==='target_column') {
                $hostingerDb->exec("ALTER TABLE `$parent` ADD COLUMN legacy_target INT NULL, ADD UNIQUE INDEX fixture_target (legacy_target)");
                $hostingerDb->exec("UPDATE `$parent` SET legacy_target=id");$target='legacy_target';
            }
            if($fault==='update_rule')$u='CASCADE';
            if($fault==='delete_rule')$d=$d==='CASCADE'?'RESTRICT':'CASCADE';
            if($fault==='unexpected_name')$old='unexpected_hostinger_fk';
            if($fault==='source_column') { $hostingerDb->exec("ALTER TABLE `$t` ADD COLUMN legacy_source INT NULL");$c='legacy_source'; }
            if($fault==='referenced_schema') {
                $hostingerDb->exec('CREATE DATABASE `'.$schema.'_external`');
                $hostingerDb->exec('CREATE TABLE `'.$schema.'_external`.`fixture_parent` (id INT PRIMARY KEY) ENGINE=InnoDB');
                $hostingerDb->exec('INSERT INTO `'.$schema.'_external`.`fixture_parent` VALUES (1)');
                $parent=$schema.'_external`.`fixture_parent';
            }
            if($fault==='composite') {
                $hostingerDb->exec("ALTER TABLE `$parent` ADD COLUMN legacy_target INT NULL, ADD UNIQUE INDEX fixture_target (id,legacy_target)");
                $hostingerDb->exec("ALTER TABLE `$t` ADD COLUMN legacy_source INT NULL");$c.='`,`legacy_source';$target.='`,`legacy_target';
            }
            if($fault!=='missing')$hostingerDb->exec("ALTER TABLE `$t` ADD CONSTRAINT `$old` FOREIGN KEY (`$c`) REFERENCES `$parent` (`$target`) ON UPDATE $u ON DELETE $d");
        }
        $fks=lifecycle_schema_inventory($hostingerDb)['foreign_keys'];$error=null;
        try{hostinger_fk_run($hostingerDb,$driver,$schema);}catch(Throwable $e){$error=$e;}
        reset_migration_expect(true,$error!==null && str_contains($error->getMessage(),$driver==='php'?'Incompatible v9 legacy foreign key':'Incompatible v9 legacy FK'),$driver.' refuses '.$fault.' mapping '.$i);
        reset_migration_expect('8',$hostingerDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Bad FK leaves version 8');
        reset_migration_expect($fks,lifecycle_schema_inventory($hostingerDb)['foreign_keys'],'All-FK preflight prevents any normalization on semantic failure');
        reset_migration_expect(1,(int)$hostingerDb->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn(),'Rejected FK retains enforcement');
        reset_migration_expect(null,$hostingerDb->query("SELECT IS_USED_LOCK('prism_migrate')")->fetchColumn(),'Rejected migration releases advisory lock');
        hostinger_fk_clean($hostingerDb,$schema);
        if($fault==='referenced_schema')$hostingerDb->exec('DROP DATABASE `'.$schema.'_external`');
        if($i===3)echo 'PASS: Hostinger '.$driver.' rejects '.$fault.' for all four mappings.' . "\n";
    }
    foreach($guardOnly?[]:['before','after','backfill','stamp','partial','validation'] as $phase) {
        $GLOBALS['hostingerFkContext']=$driver.' '.$phase;
        $schema='hostinger_fk_'.(++$seq);$before=hostinger_fk_fixture($hostingerDb,$schema,$perTable?[0,1,2,3]:[0,2,3]);
        if($phase==='partial') {
            // Simulate the previous script's additive-DDL failure at final FK validation, before backfill/stamp.
            $hostingerDb->query("SELECT GET_LOCK('prism_migrate',30)");PrismResetMigrationSQL\migration_v9_normalize_foreign_keys($hostingerDb);$hostingerDb->query("SELECT RELEASE_LOCK('prism_migrate')");
            $hostingerDb->failBefore='UPDATE students p JOIN users';
            try{PrismResetMigrationSQL\migrate($hostingerDb);}catch(RuntimeException $e){reset_migration_expect('Synthetic normalization failure',$e->getMessage(),'Synthetic additive failure reached');}
            $hostingerDb->failBefore=null;
            foreach($perTable?[0,1,2,3]:[0,2,3] as $i) {
                [$t,$c,$old,$canonical,$p,$target,$u,$d]=$maps[$i];
                $hostingerDb->exec("ALTER TABLE `$t` DROP FOREIGN KEY `$canonical`, ADD CONSTRAINT `$old` FOREIGN KEY (`$c`) REFERENCES `$p` (`$target`) ON UPDATE $u ON DELETE $d");
            }
            reset_migration_expect(1,(int)$hostingerDb->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_invitations'")->fetchColumn(),'Partial attempt already added invitation table');
            reset_migration_expect(null,$hostingerDb->query('SELECT profile_completed_at FROM students WHERE id=1')->fetchColumn(),'Partial DDL precedes identity backfill');
            reset_migration_expect('8',$hostingerDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Partial additive attempt still version 8');
        } else {
            if($phase==='validation')$hostingerDb->exec('ALTER TABLE users ADD COLUMN unexpected_final_column INT NULL');
            $error=null;try{hostinger_fk_run($hostingerDb,$driver,$schema,$phase);}catch(Throwable $e){$error=$e;}
            reset_migration_expect(true,$error!==null && str_contains($error->getMessage(),$phase==='validation'?($driver==='php'?'inventory mismatch':'Unreviewed final v9 column inventory'):'Synthetic normalization failure'),'Injected '.$driver.' '.$phase.' failure reached');
            reset_migration_expect('8',$hostingerDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Injected failure cannot stamp v9');
            reset_migration_expect(false,$hostingerDb->inTransaction(),'Failure leaves no open transaction');
            reset_migration_expect(null,$hostingerDb->query("SELECT IS_USED_LOCK('prism_migrate')")->fetchColumn(),'Failure releases migration advisory lock');
            if(in_array($phase,['before','after'],true))hostinger_fk_preserved($hostingerDb,$before,false);
            if($phase==='after')reset_migration_expect('calendar_deadlines_ibfk_1',$hostingerDb->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_deadlines' AND COLUMN_NAME='creator_user_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn(),'Completed first ALTER survives injected failure');
            if($phase==='validation')$hostingerDb->exec('ALTER TABLE users DROP COLUMN unexpected_final_column');
        }
        $hostingerDb->failBefore=$hostingerDb->failAfter=null;hostinger_fk_run($hostingerDb,$driver,$schema);
        lifecycle_compare_schema($hostingerDb);reset_migration_expect('9',$hostingerDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Partial/failed attempt retries to canonical v9');
        hostinger_fk_preserved($hostingerDb,$before);
        reset_migration_expect(1,(int)$hostingerDb->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn(),'Failure/retry never disables enforcement');
        hostinger_fk_clean($hostingerDb,$schema);
    }
    if($driver==='manual') {
        $GLOBALS['hostingerFkContext']='manual guard observations';
        $schema='hostinger_fk_'.(++$seq);$legacy=$perTable?[0,1,2,3]:[0,2,3];
        hostinger_fk_fixture($hostingerDb,$schema,$legacy);hostinger_fk_run($hostingerDb,'manual',$schema,'guard');
        $observed=(string)$hostingerDb->query('SELECT @fixture_fk_guards')->fetchColumn();
        reset_migration_expect(str_repeat('11',count($legacy)),$observed,'Every manual normalization holds advisory lock and FK enforcement; observed '.json_encode($observed));
        lifecycle_compare_schema($hostingerDb);
        hostinger_fk_run($hostingerDb,'manual',$schema,'guard');
        reset_migration_expect('',(string)$hostingerDb->query('SELECT @fixture_fk_guards')->fetchColumn(),'Canonical manual rerun performs no normalization ALTER');
        hostinger_fk_clean($hostingerDb,$schema);
    }
    echo 'PASS: Hostinger FK '.$driver.' migration assertions '.($GLOBALS['checks']-$driverStart).".\n";
}
$hostingerDb=null;
unset($GLOBALS['hostingerFkContext']);
echo 'PASS: Hostinger FK migration assertions '.($GLOBALS['checks']-$hostingerStart).'; exact combined two-table `1` fixture '.($perTable?'YES':'requires separate MariaDB 12.1+ run').".\n";
