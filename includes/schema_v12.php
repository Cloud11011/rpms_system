<?php
/** Schema 12 is the additive V14 foundation; workflow version and schema version differ. */
require_once __DIR__.'/schema_v11.php';
require_once __DIR__.'/schema_collation.php';

/** Pinned DDL rendered for the legacy base collation (see schema_collation.php). */
function schema_v12_tables(string $collation = SCHEMA_PINNED_COLLATION): array
{
    return array_map(static fn($ddl) => schema_extension_ddl($ddl, $collation), [
        'ierb_workflows' => "CREATE TABLE ierb_workflows (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            stage_key VARCHAR(32) NOT NULL,
            revision INT NOT NULL DEFAULT 1,
            legacy_stage_snapshot VARCHAR(20) NOT NULL,
            enrollment_evidence TEXT NOT NULL,
            enrolled_by_user_id INT NOT NULL,
            enrolled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY ierb_workflow_student (student_id),
            CONSTRAINT ierb_workflow_student_fk FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_workflow_enroller_fk FOREIGN KEY (enrolled_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_workflow_stage CHECK (stage_key IN ('initial_submission','resubmission_amendment','approval_data_gathering','ierb_completed')),
            CONSTRAINT ierb_workflow_revision CHECK (revision > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'ierb_correction_cases' => "CREATE TABLE ierb_correction_cases (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_id INT NOT NULL,
            interrupted_stage VARCHAR(32) NOT NULL,
            reason TEXT NOT NULL,
            affected_documents_json TEXT NOT NULL,
            requires_amendment TINYINT NOT NULL,
            opened_by_user_id INT NOT NULL,
            opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            resolved_by_user_id INT NULL,
            resolved_at DATETIME(6) NULL,
            resolution_evidence_json TEXT NULL,
            active_workflow INT GENERATED ALWAYS AS (CASE WHEN resolved_at IS NULL THEN workflow_id ELSE NULL END) STORED,
            UNIQUE KEY ierb_one_open_case (active_workflow),
            KEY ierb_case_history (workflow_id,id),
            CONSTRAINT ierb_case_workflow_fk FOREIGN KEY (workflow_id) REFERENCES ierb_workflows(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_case_opener_fk FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_case_resolver_fk FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_case_stage CHECK (interrupted_stage IN ('initial_submission','approval_data_gathering')),
            CONSTRAINT ierb_case_amendment CHECK (requires_amendment IN (0,1)),
            CONSTRAINT ierb_case_documents CHECK (JSON_VALID(affected_documents_json)),
            CONSTRAINT ierb_case_resolution CHECK ((resolved_at IS NULL AND resolved_by_user_id IS NULL AND resolution_evidence_json IS NULL)
                OR (resolved_at IS NOT NULL AND resolved_by_user_id IS NOT NULL AND resolution_evidence_json IS NOT NULL AND JSON_VALID(resolution_evidence_json) AND resolved_at >= opened_at))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'ierb_workflow_events' => "CREATE TABLE ierb_workflow_events (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_id INT NOT NULL,
            revision INT NOT NULL,
            event_key VARCHAR(40) NOT NULL,
            from_stage VARCHAR(32) NOT NULL,
            to_stage VARCHAR(32) NOT NULL,
            correction_case_id INT NULL,
            actor_user_id INT NOT NULL,
            reason TEXT NOT NULL,
            evidence_json TEXT NOT NULL,
            request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            result_json TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY ierb_event_request (workflow_id,request_key),
            UNIQUE KEY ierb_event_revision (workflow_id,revision),
            CONSTRAINT ierb_event_workflow_fk FOREIGN KEY (workflow_id) REFERENCES ierb_workflows(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_event_case_fk FOREIGN KEY (correction_case_id) REFERENCES ierb_correction_cases(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT ierb_event_evidence CHECK (JSON_VALID(evidence_json)),
            CONSTRAINT ierb_event_result CHECK (JSON_VALID(result_json))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ]);
}

/** SHOW CREATE also covers CHECK expressions, collation and generated columns. */
function schema_v12_definition(PDO $pdo, string $table): string
{
    if (!array_key_exists($table, schema_v12_tables())) throw new LogicException('Unknown extension table.');
    $row = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $row[1]);
}

/** Validate exact base v11 plus any already-created canonical extension tables (DDL restart).
 * $acceptPinned admits empty tables created by the f944220 runners (always unicode_ci) for rollback only.
 */
function schema_v12_verify(PDO $pdo, bool $partial = false, array $projectTables = [], bool $acceptPinned = false): void
{
    require_once __DIR__.'/account_lifecycle.php';
    $base = json_decode(file_get_contents(schema_v11_manifest_file()), true, 512, JSON_THROW_ON_ERROR);
    $extension = json_decode(file_get_contents(__DIR__.'/ierb_schema_v12.json'), true, 512, JSON_THROW_ON_ERROR);
    $collation = schema_extension_collation($pdo);
    $actual = lifecycle_schema_inventory($pdo);
    if ($projectTables) {
        foreach (['tables','columns','indexes','foreign_keys'] as $kind) {
            $actual[$kind] = array_values(array_filter($actual[$kind], static fn($r) => !in_array($r['TABLE_NAME'], $projectTables, true)));
        }
        if (in_array($actual['schema_version'],['13','14','15'],true)) $actual['schema_version'] = '12';
    }
    $names = array_keys(schema_v12_tables());
    $present = array_values(array_intersect(array_column($actual['tables'], 'TABLE_NAME'), $names));
    // Partial DDL is legitimate only while the base is still v11. A published v12
    // missing a table is drift/data loss, never an invitation to silently recreate it.
    if ((!$partial || $actual['schema_version'] === '12') && count($present) !== count($names)) throw new RuntimeException('Incomplete schema 12 extension.');
    foreach ($present as $name) {
        $definition = schema_v12_definition($pdo, $name);
        if (!schema_extension_matches($pdo, $name, $definition, $extension[$name], $collation)
            && !($acceptPinned && schema_extension_matches($pdo, $name, $definition, $extension[$name], SCHEMA_PINNED_COLLATION))) {
            throw new RuntimeException('Unexpected schema 12 definition or collation: '.$name.' (legacy base '.$collation.').');
        }
    }
    foreach (['tables','columns','indexes','foreign_keys'] as $kind) {
        $actual[$kind] = array_values(array_filter($actual[$kind], static fn($row) => !in_array($row['TABLE_NAME'], $names, true)));
    }
    if (!in_array($actual['schema_version'], ['11','12'], true)) throw new RuntimeException('Canonical v11 or v12 is required.');
    $actual['schema_version'] = '11';
    foreach ($base as $kind => $value) if (json_encode($actual[$kind] ?? null, JSON_NUMERIC_CHECK) !== json_encode($value, JSON_NUMERIC_CHECK)) {
        throw new RuntimeException('The preserved v11 schema differs: '.$kind.'.');
    }
    // Retain every frozen constraint check, adapting only exact counts for the reviewed additive tables.
    $checks = schema_v10_plan()['verify'];
    foreach ($checks as &$check) {
        if ($projectTables && str_starts_with($check['message'], 'Exact v10 ')) $check['sql'] .= " AND TABLE_NAME NOT IN ('".implode("','", $projectTables)."')";
        if ($check['message'] === 'Exact v10 columns') $check['sql'] = str_replace('=240 ', '=241 ', $check['sql']);
        if (str_starts_with($check['message'], 'Exact v10 ')) {
            $check['sql'] .= " AND TABLE_NAME NOT IN ('ierb_workflows','ierb_correction_cases','ierb_workflow_events')";
        }
    }
    unset($check);
    schema_v10_check($pdo, $checks);
}

/** CLI-only, explicit database binding. Dry run is the default and never performs DDL. */
function migrate_schema_v12(PDO $pdo, string $mode = 'dry-run'): array
{
    if (PHP_SAPI !== 'cli' || getenv('PRISM_ALLOW_SCHEMA_V12_MIGRATION') !== '1'
        || getenv('PRISM_SCHEMA_V12_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Schema 12 requires explicit CLI authorization and exact database binding.');
    }
    if (!in_array($mode, ['dry-run','apply','rollback'], true)) throw new RuntimeException('Invalid migration mode.');
    if ($pdo->inTransaction() || (int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) throw new RuntimeException('Migration requires FK enforcement outside a transaction.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn() !== 1) throw new RuntimeException('Schema maintenance lock unavailable.');
    try {
        schema_v12_verify($pdo, true, [], $mode === 'rollback');
        $collation = schema_extension_collation($pdo);
        $version = (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
        $present = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ierb_workflows','ierb_correction_cases','ierb_workflow_events')")->fetchAll(PDO::FETCH_COLUMN);
        if ($mode === 'dry-run') return ['ok' => true, 'mode' => $mode, 'from' => $version, 'to' => '12', 'existingExtensionTables' => $present, 'extensionCollation' => $collation, 'legacyRowsChanged' => 0];
        if ($mode === 'rollback') {
            // Rollback is safe only BEFORE activation/data. Retain every live workflow and its evidence.
            foreach ($present as $name) if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn()) throw new RuntimeException('Rollback refused: retained V14 workflow data exists. Disable activation and retain the extension.');
            // Mark the compatible base before implicit-commit DROP; an interrupted rollback can be retried.
            $pdo->exec("UPDATE schema_meta SET v='11' WHERE k='schema_version'");
            foreach (array_reverse(array_keys(schema_v12_tables())) as $name) if (in_array($name, $present, true)) $pdo->exec('DROP TABLE `'.$name.'`');
            schema_v11_verify($pdo);
            return ['ok' => true, 'mode' => $mode, 'to' => '11', 'legacyRowsChanged' => 0];
        }
        foreach (schema_v12_tables($collation) as $name => $sql) if (!in_array($name, $present, true)) $pdo->exec($sql);
        schema_v12_verify($pdo);
        $pdo->exec("UPDATE schema_meta SET v='12' WHERE k='schema_version'");
        return ['ok' => true, 'mode' => $mode, 'to' => '12', 'extensionCollation' => $collation, 'legacyRowsChanged' => 0];
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}
