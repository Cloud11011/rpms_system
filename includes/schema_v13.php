<?php
/** Additive canonical project extension. No legacy membership is inferred by DDL. */
require_once __DIR__.'/schema_v12.php';

function schema_v13_tables(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'research_projects' => "CREATE TABLE research_projects (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_identifier VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            research_title VARCHAR(500) NOT NULL,
            adviser_id INT NOT NULL,
            submission_holder_id INT NOT NULL UNIQUE,
            workflow_id INT NOT NULL UNIQUE,
            revision INT NOT NULL DEFAULT 0,
            completed_at DATETIME(6) NULL,
            completion_document_id VARCHAR(40) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT project_adviser_fk FOREIGN KEY (adviser_id) REFERENCES advisers(id) ON DELETE RESTRICT,
            CONSTRAINT project_holder_fk FOREIGN KEY (submission_holder_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT project_workflow_fk FOREIGN KEY (workflow_id) REFERENCES ierb_workflows(id) ON DELETE RESTRICT,
            CONSTRAINT project_final_fk FOREIGN KEY (completion_document_id) REFERENCES documents(id) ON DELETE RESTRICT
        )".$tail,
        'research_project_identifiers' => "CREATE TABLE research_project_identifiers (
            identifier VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            project_id INT NOT NULL,
            research_title VARCHAR(500) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT project_identifier_fk FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE RESTRICT
        )".$tail,
        'research_project_members' => "CREATE TABLE research_project_members (
            project_id INT NOT NULL,
            student_id INT NOT NULL,
            legacy_identity_json TEXT NOT NULL,
            stage_key VARCHAR(32) NOT NULL,
            workflow_revision INT NOT NULL,
            joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (project_id,student_id),
            UNIQUE KEY project_member_student (student_id),
            CONSTRAINT project_member_project_fk FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE RESTRICT,
            CONSTRAINT project_member_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT project_member_snapshot CHECK (JSON_VALID(legacy_identity_json)),
            CONSTRAINT project_member_stage CHECK (stage_key IN ('initial_submission','resubmission_amendment','approval_data_gathering','ierb_completed'))
        )".$tail,
        'research_project_events' => "CREATE TABLE research_project_events (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            revision INT NOT NULL,
            event_key VARCHAR(40) NOT NULL,
            actor_user_id INT NOT NULL,
            reason TEXT NOT NULL,
            evidence_json LONGTEXT NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY project_event_revision (project_id,revision),
            CONSTRAINT project_event_project_fk FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE RESTRICT,
            CONSTRAINT project_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT project_event_evidence CHECK (JSON_VALID(evidence_json))
        )".$tail,
        'research_project_member_history' => "CREATE TABLE research_project_member_history (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_event_id BIGINT NOT NULL,
            project_id INT NOT NULL,
            student_id INT NOT NULL,
            from_stage VARCHAR(32) NULL,
            to_stage VARCHAR(32) NOT NULL,
            workflow_revision INT NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY project_member_event (project_event_id,student_id),
            CONSTRAINT project_history_event_fk FOREIGN KEY (project_event_id) REFERENCES research_project_events(id) ON DELETE RESTRICT,
            CONSTRAINT project_history_member_fk FOREIGN KEY (project_id,student_id) REFERENCES research_project_members(project_id,student_id) ON DELETE RESTRICT
        )".$tail,
    ];
}

function schema_v13_definition(PDO $pdo, string $name): string
{
    if (!isset(schema_v13_tables()[$name])) throw new LogicException('Unknown project table.');
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1]);
}

function schema_v13_verify(PDO $pdo, bool $partial = false, array $extraTables = []): void
{
    $names = array_keys(schema_v13_tables());
    schema_v12_verify($pdo, false, array_merge($names,$extraTables));
    $contract = json_decode(file_get_contents(__DIR__.'/research_schema_v13.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = $pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
    foreach ($names as $name) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute([$name]);
        if (!$q->fetchColumn()) {
            if (!$partial || in_array($version,['13','14'],true)) throw new RuntimeException('Incomplete project schema.');
        } elseif (schema_v13_definition($pdo, $name) !== $contract[$name]) throw new RuntimeException('Project schema drift: '.$name);
    }
}

function migrate_schema_v13(PDO $pdo, string $mode = 'dry-run'): array
{
    if (PHP_SAPI !== 'cli' || getenv('PRISM_ALLOW_SCHEMA_V13_MIGRATION') !== '1'
        || getenv('PRISM_SCHEMA_V13_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Explicit CLI opt-in and database binding required.');
    if (!in_array($mode, ['dry-run','apply','rollback'], true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) throw new RuntimeException('Invalid migration preconditions.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn() !== 1) throw new RuntimeException('Schema maintenance is busy.');
    try {
        schema_v13_verify($pdo, true);
        $present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($mode === 'rollback') {
            foreach (array_keys(schema_v13_tables()) as $name) if (in_array($name, $present, true) && (int)$pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn()) throw new RuntimeException('Retained project data blocks rollback. Disable activation and retain the schema.');
            $pdo->exec("UPDATE schema_meta SET v='12' WHERE k='schema_version'");
            foreach (array_reverse(array_keys(schema_v13_tables())) as $name) if (in_array($name, $present, true)) $pdo->exec('DROP TABLE `'.$name.'`');
            schema_v12_verify($pdo);
        } elseif ($mode === 'apply') {
            foreach (schema_v13_tables() as $name => $ddl) if (!in_array($name, $present, true)) $pdo->exec($ddl);
            schema_v13_verify($pdo);
            $pdo->exec("UPDATE schema_meta SET v='13' WHERE k='schema_version'");
        }
        return ['ok'=>true,'mode'=>$mode,'to'=>$mode === 'rollback' ? '12' : '13','legacyRowsChanged'=>0,'membershipsInferred'=>0];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
