<?php
/**
 * Real v6 DDL cases, run only inside the private server owned by the reset test harness.
 * Run: php tests/password-reset-migration-mysql.php --isolated-v6
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!isset($pdo) || !$pdo instanceof PDO || !function_exists('reset_migration_expect')) {
    throw new RuntimeException('Use the isolated-server harness; no standalone database connection is accepted.');
}
require_once __DIR__ . '/../includes/academic_catalog.php'; // Pure catalog, no bootstrap/configuration.
function v6_sql_expect(mixed $expected, mixed $actual, string $message): void
{
    reset_migration_expect($expected, $actual, $message);
}
function v6_sql_failure(callable $run, string $expected): void
{
    try { $run(); } catch (RuntimeException $error) {
        v6_sql_expect(true, str_contains($error->getMessage(), $expected), 'Expected failure: ' . $expected);
        return;
    }
    throw new RuntimeException('Missing expected failure: ' . $expected);
}
function v6_sql_drop_fk(PDO $pdo, string $table, string $column): void
{
    $stmt = $pdo->prepare("SELECT DISTINCT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $stmt->execute([$table, $column]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $pdo->exec('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . str_replace('`', '``', $name) . '`');
    }
}
function v6_sql_fixture(PDO $pdo, string $name, bool $legacy, bool $rows, bool $retainFks = false): void
{
    if (!preg_match('/^v6_test_[a-z0-9_]+$/', $name)) throw new RuntimeException('Invalid fixture database name');
    $pdo->exec("CREATE DATABASE `$name`");
    $pdo->exec("USE `$name`");
    // Only the extracted pre-v6 declarations build this synthetic baseline.
    PrismResetMigrationSQL\migrate_schema($pdo);
    $pdo->exec('CREATE TABLE schema_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(40) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO schema_meta VALUES ('schema_version','5')");
    if ($legacy) {
        if (!$retainFks) {
            foreach (PrismResetMigrationSQL\migration_required_foreign_keys() as [$table, $column]) {
                if ($table !== 'advisers') v6_sql_drop_fk($pdo, $table, $column);
            }
        }
        foreach (['students', 'ierb_history', 'notifications'] as $table) {
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN id INT NOT NULL COMMENT 'Keep original key'");
        }
    }
    if ($rows) {
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
        try {
            $pdo->exec("INSERT INTO users (id,username,password_hash,role,full_name,email) VALUES
                (1,'fixture_owner','not-a-login-hash','adviser','Fixture Owner','owner@example.invalid')");
            $pdo->exec("INSERT INTO advisers (id,employee_id,full_name,email,user_id) VALUES
                (1,'FIX-ADV','Fixture Owner','owner@example.invalid',1)");
            $pdo->exec("INSERT INTO students (id,student_id,full_name,email,course,adviser_id,user_id) VALUES
                (0,'LEGACY-ZERO','Legacy Student','legacy@example.invalid',' Original free-text course ',1,1)");
            $pdo->exec("INSERT INTO ierb_history (id,student_id,stage,status,note) VALUES
                (0,0,'Stage 1','On Track','Original note')");
            $pdo->exec("INSERT INTO notifications (id,recipient_type,recipient_id,recipient_email,message) VALUES
                (0,'student',0,'legacy@example.invalid','Original notification')");
            $pdo->exec("INSERT INTO documents (id,student_id,original_name,stored_name) VALUES
                ('fixture-document',0,'original.pdf','fixture-original.pdf')");
            $pdo->exec("INSERT INTO password_resets (user_id,token,expires_at) VALUES
                (1,'synthetic-reset','2027-01-01 00:00:00')");
        } finally {
            $pdo->prepare('SET SESSION sql_mode=?')->execute([$mode]);
        }
    }
}
function v6_sql_snapshot(PDO $pdo): array
{
    $result = [];
    foreach (['users', 'advisers', 'students', 'ierb_history', 'notifications', 'documents', 'password_resets'] as $table) {
        $result[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll();
    }
    return $result;
}
function v6_sql_schema(PDO $pdo): array
{
    $result = [];
    foreach (array_keys(v6_sql_snapshot($pdo)) as $table) {
        $result[$table] = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    }
    return $result;
}
function v6_sql_verify(PDO $pdo): void
{
    v6_sql_expect('6', $pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(), 'V6 stamp exists');
    foreach (['students', 'ierb_history', 'notifications', 'password_resets'] as $table) {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME,COLUMN_KEY,EXTRA FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='id'");
        $stmt->execute([$table]);
        v6_sql_expect(['COLUMN_NAME'=>'id','COLUMN_KEY'=>'PRI','EXTRA'=>'auto_increment'], $stmt->fetch(), "$table generated key verified");
    }
    foreach (PrismResetMigrationSQL\migration_required_foreign_keys() as [$table, $column, $parent, $delete]) {
        $stmt = $pdo->prepare("SELECT k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
            WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=?");
        $stmt->execute([$table,$column]);
        v6_sql_expect([['REFERENCED_TABLE_NAME'=>$parent,'REFERENCED_COLUMN_NAME'=>'id','DELETE_RULE'=>$delete]],
            $stmt->fetchAll(), "$table.$column expected FK exists exactly once");
    }
}
function v6_sql_insert_statement(string $file, string $table, bool $last = false): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $file);
    if (!preg_match_all("/prepare\\('(?<sql>INSERT INTO " . $table . " \\([^']+)'\\)/s", $source, $matches)) {
        throw new RuntimeException('Cannot extract reviewed INSERT: ' . $file . '/' . $table);
    }
    return $last ? end($matches['sql']) : $matches['sql'][0];
}
function v6_sql_application_inserts(PDO $pdo): void
{
    $academic = academic_validate(['academicUnitKey'=>'amt','programKey'=>'bsit','yearLevel'=>'2nd Year','academicYear'=>'2026-2027']);
    $studentSql = v6_sql_insert_statement('students_api.php','students');
    $historySql = v6_sql_insert_statement('students_api.php','ierb_history',true);
    $userSql = v6_sql_insert_statement('students_api.php','users');
    $notificationSql = v6_sql_insert_statement('notifications_api.php','notifications');
    $ids = ['students'=>[], 'ierb_history'=>[], 'notifications'=>[]];
    foreach ([1,2] as $index) {
        $sid='NEW-'.$index;
        $email='new'.$index.'@example.invalid';
        $pdo->beginTransaction();
        try {
            $pdo->prepare($studentSql)->execute([':sid'=>$sid, ':name'=>'New Student', ':email'=>$email,
                ':research'=>'Fixture research', ':grp'=>'Fixture group', ':course'=>$academic['course'],
                ':academic_unit'=>$academic['academic_unit_key'], ':program'=>$academic['program_key'],
                ':year_level'=>$academic['year_level'], ':academic_year'=>$academic['academic_year'],
                ':adv'=>null, ':stage'=>'Stage 1', ':status'=>'On Track', ':req'=>'', ':pcode'=>null, ':pi'=>0]);
            $id=(int)$pdo->lastInsertId(); $ids['students'][]=$id;
            $pdo->prepare($userSql)->execute([':u'=>$sid,':p'=>'synthetic-not-a-login-hash',':n'=>'New Student',':e'=>$email,':ref'=>$sid]);
            $userId=(int)$pdo->lastInsertId();
            $pdo->prepare($historySql)->execute([':sid'=>$id,':stage'=>'Stage 1',':status'=>'On Track',
                ':note'=>'Record created by RPMS.',':req'=>'',':actor'=>'Fixture Admin']);
            $ids['ierb_history'][]=(int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        v6_sql_expect(true, PrismResetMigrationSQL\send_account_setup_email($pdo,$userId,$email,'New Student')['ok'],
            'New student account setup token issued');
        $pdo->prepare($notificationSql)->execute([':rt'=>'student',':rid'=>$id,':re'=>$email,':rn'=>'New Student',
            ':subj'=>'Fixture',':msg'=>'Fixture notification',':type'=>'Status Update',':status'=>'Sent',
            ':delivery'=>'fixture',':sched'=>null,':sent'=>'2026-01-01 00:00:00',':by'=>'Fixture Admin']);
        $ids['notifications'][]=(int)$pdo->lastInsertId();
        v6_sql_expect($id,(int)$pdo->query('SELECT student_id FROM ierb_history WHERE id='.end($ids['ierb_history']))->fetchColumn(),
            'New history belongs to generated student ID');
    }
    foreach ($ids as $table=>$values) {
        v6_sql_expect(2,count(array_unique($values)),"$table repeated inserts have distinct IDs");
        v6_sql_expect(true,min($values)>0,"$table new IDs are positive");
    }
}
class V6FaultPDO extends PDO
{
    public ?string $failOnce = null;
    public ?string $skipOnce = null;
    public array $alters = [];
    public function __construct(private PDO $inner) {}
    public function exec(string $sql): int|false
    {
        if (str_starts_with($sql,'ALTER TABLE')) $this->alters[]=$sql;
        if ($this->failOnce !== null && str_contains($sql,$this->failOnce)) {
            $this->failOnce=null; throw new RuntimeException('Injected real-DDL failure');
        }
        if ($this->skipOnce !== null && str_contains($sql,$this->skipOnce)) { $this->skipOnce=null; return 0; }
        return $this->inner->exec($sql);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false
    { return $fetchMode === null ? $this->inner->query($query) : $this->inner->query($query,$fetchMode,...$args); }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if ($this->failOnce !== null && str_contains($query,$this->failOnce)) {
            $this->failOnce=null; throw new RuntimeException('Injected real-DDL failure');
        }
        return $this->inner->prepare($query,$options);
    }
    public function quote(string $string,int $type=PDO::PARAM_STR): string|false { return $this->inner->quote($string,$type); }
}
foreach (['correct','empty','zero','zero_existing_fks','wide_zero'] as $case) {
    v6_sql_fixture($pdo,'v6_test_'.$case,$case!=='correct',$case!=='empty',$case==='zero_existing_fks');
    if ($case==='wide_zero') {
        $pdo->exec('ALTER TABLE students MODIFY id BIGINT UNSIGNED NOT NULL COMMENT \'Wide original key\'');
        $pdo->exec('ALTER TABLE ierb_history MODIFY id BIGINT UNSIGNED NOT NULL, MODIFY student_id BIGINT UNSIGNED NOT NULL');
        $pdo->exec('ALTER TABLE documents MODIFY student_id BIGINT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE notifications MODIFY id BIGINT UNSIGNED NOT NULL');
    }
    $before=v6_sql_snapshot($pdo); $schema=v6_sql_schema($pdo);
    $tracked=new V6FaultPDO($pdo);
    PrismResetMigrationSQL\migrate($tracked);
    v6_sql_verify($pdo);
    v6_sql_expect($before,v6_sql_snapshot($pdo),"$case every original value survives migration");
    if ($case==='correct') v6_sql_expect([],$tracked->alters,'Correct schema requires zero ALTERs');
    if ($case!=='empty') {
        v6_sql_expect(0,(int)$pdo->query("SELECT id FROM students WHERE student_id='LEGACY-ZERO'")->fetchColumn(),'Student zero ID retained');
        v6_sql_expect(['id'=>0,'student_id'=>0],array_map('intval',$pdo->query('SELECT id,student_id FROM ierb_history WHERE id=0')->fetch()),
            'History zero ID still points to student zero');
        v6_sql_expect(0,(int)$pdo->query("SELECT id FROM notifications WHERE message='Original notification'")->fetchColumn(),'Notification zero ID retained');
    }
    $after=v6_sql_schema($pdo); $alters=$tracked->alters;
    PrismResetMigrationSQL\migrate($tracked);
    v6_sql_expect($after,v6_sql_schema($pdo),"$case second migration preserves schema");
    v6_sql_expect($alters,$tracked->alters,"$case second migration performs no ALTER");
    v6_sql_application_inserts($pdo);
    // Verify actual delete semantics, using only synthetic data after preservation assertions.
    if ($case!=='empty') {
        $pdo->exec('DELETE FROM users WHERE id=1');
        v6_sql_expect(null,$pdo->query('SELECT user_id FROM advisers WHERE id=1')->fetchColumn(),'Adviser user SET NULL');
        v6_sql_expect(null,$pdo->query('SELECT user_id FROM students WHERE id=0')->fetchColumn(),'Student user SET NULL');
        v6_sql_expect(0,(int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE token='synthetic-reset'")->fetchColumn(),'Reset user CASCADE');
        $pdo->exec('DELETE FROM advisers WHERE id=1');
        v6_sql_expect(null,$pdo->query('SELECT adviser_id FROM students WHERE id=0')->fetchColumn(),'Student adviser SET NULL');
        $pdo->exec('DELETE FROM students WHERE id=0');
        v6_sql_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM ierb_history WHERE id=0')->fetchColumn(),'History student CASCADE');
        v6_sql_expect(null,$pdo->query("SELECT student_id FROM documents WHERE id='fixture-document'")->fetchColumn(),'Document student SET NULL');
    }
    echo "PASS: v6 $case actual DDL, preservation, creation, relationships and rerun.\n";
}
foreach (PrismResetMigrationSQL\migration_required_foreign_keys() as $index=>[$table,$column,$parent,$delete]) {
    foreach (['orphan','wrong_delete','wrong_parent'] as $problem) {
        v6_sql_fixture($pdo,"v6_test_bad_{$index}_$problem",true,true);
        v6_sql_drop_fk($pdo,$table,$column);
        if ($problem==='orphan') {
            $pdo->exec("UPDATE `$table` SET `$column`=987654");
        } elseif ($problem==='wrong_delete') {
            $pdo->exec("ALTER TABLE `$table` ADD FOREIGN KEY (`$column`) REFERENCES `$parent`(id) ON DELETE RESTRICT");
        } else {
            $pdo->exec('CREATE TABLE wrong_parent (id INT PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO wrong_parent VALUES (0),(1)');
            $pdo->exec("ALTER TABLE `$table` ADD FOREIGN KEY (`$column`) REFERENCES wrong_parent(id) ON DELETE $delete");
        }
        $before=v6_sql_snapshot($pdo);
        v6_sql_failure(fn()=>PrismResetMigrationSQL\migrate($pdo),$problem==='orphan'?'Orphan rows':'Conflicting foreign key');
        v6_sql_expect('5',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Unsafe relationship never stamps v6');
        v6_sql_expect($before,v6_sql_snapshot($pdo),'Unsafe relationship does not rewrite/delete data');
    }
}
foreach (['id_alter','id_verify','fk_alter','fk_verify','stamp'] as $faultCase) {
    v6_sql_fixture($pdo,'v6_test_retry_'.$faultCase,true,true);
    $before=v6_sql_snapshot($pdo);
    $fault=new V6FaultPDO($pdo);
    $match=match($faultCase) {
        'id_alter','id_verify'=>'ALTER TABLE `students` MODIFY',
        'fk_alter','fk_verify'=>'ADD FOREIGN KEY',
        'stamp'=>'REPLACE INTO schema_meta',
    };
    if (str_ends_with($faultCase,'verify')) $fault->skipOnce=$match; else $fault->failOnce=$match;
    $mode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    v6_sql_failure(fn()=>PrismResetMigrationSQL\migrate($fault),
        str_ends_with($faultCase,'verify')?'could not be verified':'Injected real-DDL failure');
    v6_sql_expect('5',$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Failure retains v5');
    v6_sql_expect($mode,$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(),'Failure restores SQL mode');
    v6_sql_expect(1,(int)$pdo->query("SELECT IS_FREE_LOCK('prism_migrate')")->fetchColumn(),'Failure releases advisory lock');
    v6_sql_expect($before,v6_sql_snapshot($pdo),'Failure preserves all data');
    PrismResetMigrationSQL\migrate($fault);
    v6_sql_verify($pdo);
    v6_sql_expect($before,v6_sql_snapshot($pdo),'Retry preserves all data');
    v6_sql_application_inserts($pdo);
    echo "PASS: v6 $faultCase failure/retry on actual MariaDB.\n";
}
v6_sql_expect(0,$GLOBALS['setupErrors'],'No setup failures');
echo 'PASS: ' . $checks . " real MariaDB v6 checks; synthetic data only, no application database or real mail.\n";
