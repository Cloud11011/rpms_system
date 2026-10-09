<?php
/** Genuine v8 fixture, deterministic legacy backfill and injected final-v9 failure phases. */
$onboardingMigrationStart=$GLOBALS['checks'];
class OnboardingMigrationPDO extends PDO {
    public ?string $fail=null;
    private function checkpoint(string $sql): void { if($this->fail!==null && str_contains($sql,$this->fail)) throw new RuntimeException('Synthetic migration failure'); }
    public function exec(string $statement): int|false { $this->checkpoint($statement);return parent::exec($statement); }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false { $this->checkpoint($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args); }
    public function prepare(string $query,array $options=[]): PDOStatement|false { $this->checkpoint($query);return parent::prepare($query,$options); }
}
$migrationDb=new OnboardingMigrationPDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
reset_migration_expect(realpath($datadir),realpath($migrationDb->query('SELECT @@datadir')->fetchColumn()),'Migration fixture belongs to verified private datadir');
foreach(['column'=>'ADD COLUMN `retention_hold`','table'=>'CREATE TABLE IF NOT EXISTS account_invitations','index'=>'ADD INDEX `student_profile_completion`',
    'FK'=>'CONSTRAINT invitation_user','backfill'=>'UPDATE students p JOIN users','verification'=>'SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES',
    'stamp'=>"REPLACE INTO schema_meta"] as $phase=>$fail) {
    $schema='onboarding_migration_'.$phase;$migrationDb->exec('CREATE DATABASE '.$schema);$migrationDb->exec('USE '.$schema);
    $GLOBALS['allowV9']=false;PrismResetMigrationSQL\migrate($migrationDb);$GLOBALS['allowV9']=true;
    $migrationDb->exec("INSERT INTO users (id,username,password_hash,role,full_name,email,ref_id) VALUES
        (1,'VS','synthetic','student','Valid Student','valid-s@example.invalid','VS'),
        (2,'VA','synthetic','adviser','Valid Adviser','valid-a@example.invalid','VA'),
        (3,'BAD','synthetic','student','Mismatch','malformed@example.invalid','BAD'),
        (5,'AMB-S','synthetic','student','Ambiguous Student','amb-s@example.invalid','AMB-S'),
        (6,'AMB-A','synthetic','adviser','Ambiguous Adviser','amb-a@example.invalid','AMB-A')");
    $migrationDb->exec("INSERT INTO students(id,student_id,full_name,email,user_id,created_at) VALUES
        (1,'VS','Valid Student','valid-s@example.invalid',1,'2025-01-02 03:04:05'),
        (3,'OTHER','Different','malformed@example.invalid',3,'2025-01-02 03:04:05'),
        (4,'ORPHAN','Orphan','orphan@example.invalid',NULL,'2025-01-02 03:04:05'),
        (5,'AMB-S','Ambiguous Student','amb-s@example.invalid',5,'2025-01-02 03:04:05'),
        (7,'AMB-S2','Competing Student','amb-s2@example.invalid',5,'2025-01-02 03:04:05')");
    $migrationDb->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id,status,created_at) VALUES (2,'VA','Valid Adviser','valid-a@example.invalid',2,'Inactive','2025-02-03 04:05:06')");
    $migrationDb->exec("INSERT INTO advisers(id,employee_id,full_name,email,user_id,status) VALUES (6,'AMB-A','Ambiguous Adviser','amb-a@example.invalid',6,'Active'),(8,'AMB-A2','Competing Adviser','amb-a2@example.invalid',6,'Active')");
    $migrationDb->fail=$fail;
    $error=null;try{PrismResetMigrationSQL\migrate($migrationDb);}catch(Throwable $e){$error=$e;}
    reset_migration_expect('Synthetic migration failure',$error?->getMessage(),'Injected '.$phase.' failure reached');
    reset_migration_expect('8',$migrationDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Failed '.$phase.' cannot stamp final v9');
    reset_migration_expect(false,$migrationDb->inTransaction(),'Failed migration leaves no transaction');
    $migrationDb->fail=null;PrismResetMigrationSQL\migrate($migrationDb);
    reset_migration_expect('9',$migrationDb->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'Retry completes exact final v9');
    reset_migration_expect('2025-01-02 03:04:05',$migrationDb->query('SELECT profile_completed_at FROM students WHERE id=1')->fetchColumn(),'Coherent existing Student remains complete');
    reset_migration_expect('2025-02-03 04:05:06',$migrationDb->query('SELECT profile_completed_at FROM advisers WHERE id=2')->fetchColumn(),'Coherent archived Adviser remains complete');
    reset_migration_expect(null,$migrationDb->query('SELECT profile_completed_at FROM students WHERE id=3')->fetchColumn(),'Malformed legacy identity remains blocked');
    reset_migration_expect(null,$migrationDb->query('SELECT profile_completed_at FROM students WHERE id=4')->fetchColumn(),'Orphan legacy identity remains blocked');
    reset_migration_expect(0,(int)$migrationDb->query('SELECT COUNT(*) FROM students WHERE id IN (5,7) AND profile_completed_at IS NOT NULL')->fetchColumn(),'Competing Student login links remain blocked');
    reset_migration_expect(0,(int)$migrationDb->query('SELECT COUNT(*) FROM advisers WHERE id IN (6,8) AND profile_completed_at IS NOT NULL')->fetchColumn(),'Competing Adviser login links remain blocked');
    reset_migration_expect(0,(int)$migrationDb->query('SELECT COUNT(*) FROM account_invitations')->fetchColumn(),'No invitations or mail for legacy backfill');
    $before=lifecycle_schema_inventory($migrationDb);PrismResetMigrationSQL\migrate($migrationDb);
    reset_migration_expect($before,lifecycle_schema_inventory($migrationDb),'Final migration rerun preserves schema');
    $migrationDb->exec('DROP DATABASE '.$schema);
}
$migrationDb=null;
echo 'PASS: final-v9 migration/backfill/failure assertions '.($GLOBALS['checks']-$onboardingMigrationStart).".\n";
