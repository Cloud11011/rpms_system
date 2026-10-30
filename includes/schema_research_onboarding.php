<?php
/** Proposed, separately reviewed extension of schema 15. Never installed by HTTP. */
require_once __DIR__.'/schema_v15.php';

function research_onboarding_tables(string $collation = SCHEMA_PINNED_COLLATION): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return array_map(static fn($ddl) => schema_extension_ddl($ddl, $collation), [
        'research_onboarding_assignments' => "CREATE TABLE research_onboarding_assignments (
            student_id INT NOT NULL PRIMARY KEY,
            approved_by_user_id INT NOT NULL,
            assignment_json LONGTEXT NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'pending',
            project_id INT NULL,
            revision INT NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT onboarding_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT onboarding_admin_fk FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT onboarding_project_fk FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE RESTRICT,
            CONSTRAINT onboarding_assignment_json CHECK (JSON_VALID(assignment_json)),
            CONSTRAINT onboarding_assignment_state CHECK (state IN ('pending','review','activated','cancelled'))
        )".$tail,
        'research_project_protocols' => "CREATE TABLE research_project_protocols (
            project_id INT NOT NULL PRIMARY KEY,
            protocol_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_general_ci NULL UNIQUE,
            CONSTRAINT protocol_project_fk FOREIGN KEY (project_id) REFERENCES research_projects(id) ON DELETE RESTRICT
        )".$tail,
    ]);
}

function research_onboarding_schema_verify(PDO $pdo, bool $partial = false): void
{
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()!=='15') throw new RuntimeException('Research onboarding requires the reviewed schema 15 base.');
    schema_v15_verify($pdo, false, array_keys(research_onboarding_tables()));
    $contract = json_decode(file_get_contents(__DIR__.'/research_onboarding_schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (research_onboarding_tables() as $name => $_) {
        if (!in_array($name, $present, true)) {
            if (!$partial) throw new RuntimeException('Incomplete research onboarding extension.');
            continue;
        }
        $ddl = $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
        if (!schema_extension_matches($pdo, $name, $ddl, $contract[$name], schema_extension_collation($pdo))) {
            throw new RuntimeException('Research onboarding schema drift: '.$name);
        }
    }
}

function migrate_research_onboarding(PDO $pdo, string $mode = 'dry-run'): array
{
    if (PHP_SAPI !== 'cli' || getenv('PRISM_ALLOW_RESEARCH_ONBOARDING_MIGRATION') !== '1'
        || getenv('PRISM_RESEARCH_ONBOARDING_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Explicit CLI authorization and database binding required.');
    }
    if (!in_array($mode, ['dry-run','apply','rollback'], true) || $pdo->inTransaction()
        || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) throw new RuntimeException('Invalid migration preconditions.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn() !== 1) throw new RuntimeException('Schema maintenance busy.');
    try {
        research_onboarding_schema_verify($pdo, true);
        $present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('research_project_protocols',$present,true) && $pdo->query("SELECT 1 FROM students s JOIN research_project_members m ON m.student_id=s.id WHERE s.protocol_code IS NOT NULL AND TRIM(s.protocol_code)<>'' LIMIT 1")->fetchColumn()) {
            throw new RuntimeException('Existing canonical Student protocol codes require a separately reviewed import mapping before this extension. No code was inferred or changed.');
        }
        if ($mode === 'rollback') {
            // Approval writes share-lock this marker in their account transaction. Take it
            // exclusively BEFORE current retention reads, so a waiting rollback cannot
            // drop a newly committed assignment after an earlier empty snapshot.
            $pdo->beginTransaction();
            try {
                $pdo->query("SELECT v FROM schema_meta WHERE k='research_onboarding_v1' FOR UPDATE")->fetchColumn();
                foreach (research_onboarding_tables() as $name => $_) if (in_array($name, $present, true)
                    && $pdo->query('SELECT 1 FROM `'.$name.'` LIMIT 1 FOR UPDATE')->fetchColumn()) throw new RuntimeException('Retained onboarding or protocol data blocks rollback.');
                $pdo->exec("DELETE FROM schema_meta WHERE k='research_onboarding_v1'");
                $pdo->commit();
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
            foreach (array_reverse(array_keys(research_onboarding_tables())) as $name) if (in_array($name, $present, true)) $pdo->exec('DROP TABLE `'.$name.'`');
            schema_v15_verify($pdo);
        } elseif ($mode === 'apply') {
            foreach (research_onboarding_tables(schema_extension_collation($pdo)) as $name => $ddl) if (!in_array($name, $present, true)) $pdo->exec($ddl);
            research_onboarding_schema_verify($pdo);
            $pdo->exec("INSERT INTO schema_meta(k,v) VALUES ('research_onboarding_v1','1') ON DUPLICATE KEY UPDATE v='1'");
        }
        return ['ok'=>true,'mode'=>$mode,'baseSchema'=>'15','extension'=>'research_onboarding_v1','existingRowsChanged'=>0];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
