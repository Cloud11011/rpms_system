<?php
require_once __DIR__.'/schema_collation.php';
require_once __DIR__.'/schema_v16.php';

function prism_admin_settings_schema_tables(string $collation=SCHEMA_PINNED_COLLATION): array
{
    return array_map(static fn($ddl)=>schema_extension_ddl($ddl,$collation),[
        'prism_settings'=>"CREATE TABLE prism_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_by_user_id INT NULL,
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT prism_settings_actor_fk FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'prism_deadline_reminder_dispatches'=>"CREATE TABLE prism_deadline_reminder_dispatches (
            deadline_id INT NOT NULL,
            student_id INT NOT NULL,
            deadline_date DATE NOT NULL,
            interval_days TINYINT UNSIGNED NOT NULL,
            notification_id INT NULL,
            queued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (deadline_id,student_id,deadline_date,interval_days),
            KEY prism_deadline_reminder_notification_idx (notification_id),
            CONSTRAINT prism_deadline_reminder_deadline_fk FOREIGN KEY (deadline_id) REFERENCES calendar_deadlines(id) ON DELETE CASCADE,
            CONSTRAINT prism_deadline_reminder_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT prism_deadline_reminder_notification_fk FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL,
            CONSTRAINT prism_deadline_reminder_interval CHECK (interval_days BETWEEN 1 AND 30)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'academic_year_removals'=>"CREATE TABLE academic_year_removals (
            year_label VARCHAR(9) NOT NULL PRIMARY KEY,
            removed_by_user_id INT NULL,
            removed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT academic_year_removal_year_fk FOREIGN KEY (year_label) REFERENCES academic_years(year_label) ON DELETE RESTRICT,
            CONSTRAINT academic_year_removal_actor_fk FOREIGN KEY (removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ]);
}

function prism_admin_settings_schema_verify(PDO $pdo,bool $partial=false): array
{
    $present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $contract=json_decode(file_get_contents(__DIR__.'/admin_settings_schema.json'),true,512,JSON_THROW_ON_ERROR);
    $collation=schema_extension_collation($pdo);
    $result=[];
    foreach (prism_admin_settings_schema_tables(schema_extension_collation($pdo)) as $table=>$ddl) {
        if (!in_array($table,$present,true)) {
            if (!$partial) throw new RuntimeException('Settings configuration schema is incomplete: '.$table.'.');
            $result[$table]=false;
            continue;
        }
        $actual=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if (!isset($contract[$table]) || !schema_extension_matches($pdo,$table,$actual,$contract[$table],$collation)) {
            throw new RuntimeException('Settings configuration schema drift: '.$table.'.');
        }
        $result[$table]=true;
    }
    return $result;
}

function migrate_prism_admin_settings(PDO $pdo,string $mode='dry-run'): array
{
    if (PHP_SAPI!=='cli' || getenv('PRISM_ALLOW_SETTINGS_MIGRATION')!=='1'
        || getenv('PRISM_SETTINGS_EXPECT_DB')!==$pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Explicit CLI authorization and database binding are required.');
    }
    if (!in_array($mode,['dry-run','apply','rollback'],true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1) {
        throw new RuntimeException('Invalid settings migration preconditions.');
    }
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()!=='16') {
        throw new RuntimeException('The reviewed schema v16 academic-year and notification foundation is required.');
    }
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new RuntimeException('Schema maintenance busy.');
    try {
        schema_v16_verify($pdo,false,array_keys(prism_admin_settings_schema_tables()));
        $present=prism_admin_settings_schema_verify($pdo,true);
        if ($mode==='apply') {
            foreach (prism_admin_settings_schema_tables(schema_extension_collation($pdo)) as $table=>$ddl) {
                if (!$present[$table]) $pdo->exec($ddl);
            }
            $present=prism_admin_settings_schema_verify($pdo);
            $pdo->exec("INSERT INTO schema_meta(k,v) VALUES ('prism_settings_v1','1') ON DUPLICATE KEY UPDATE v='1'");
            schema_v16_verify($pdo);
        } elseif ($mode==='rollback') {
            if (!$present['prism_settings'] && !$present['prism_deadline_reminder_dispatches']) {
                throw new RuntimeException('Settings configuration migration is not installed.');
            }
            foreach (['prism_settings','prism_deadline_reminder_dispatches','academic_year_removals'] as $table) {
                if ($present[$table] && (int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()>0) {
                    throw new RuntimeException('Settings migration rollback is blocked while configuration or reminder history exists. Export and retain that data before retrying.');
                }
            }
            if ($pdo->query("SELECT 1 FROM activity_logs WHERE action='communication_setting_updated' OR action='academic_year_removed_from_registration' LIMIT 1")->fetchColumn()) {
                throw new RuntimeException('Retained Settings audit history blocks rollback.');
            }
            $pdo->exec("DELETE FROM schema_meta WHERE k='prism_settings_v1'");
            if ($present['academic_year_removals']) $pdo->exec('DROP TABLE academic_year_removals');
            if ($present['prism_deadline_reminder_dispatches']) $pdo->exec('DROP TABLE prism_deadline_reminder_dispatches');
            if ($present['prism_settings']) $pdo->exec('DROP TABLE prism_settings');
            $present=['prism_settings'=>false,'prism_deadline_reminder_dispatches'=>false,'academic_year_removals'=>false];
            schema_v16_verify($pdo);
        }
        return ['ok'=>true,'mode'=>$mode,'tables'=>$present,'migrationApplied'=>$mode==='apply'];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
