"""Generate the manual schema 15 -> 16 SQL package from the reviewed PHP DDL.

DDL text is extracted from includes/schema_v16.php and includes/admin_settings_schema.php, never retyped.
Expected structural fingerprints were captured from a CLI-migrated disposable MariaDB 12.3.2 fixture and
re-checked by maintenance/frontend-reconciliation/settings-run.ps1 (scenarios settings-first and sql).
Usage: python -I maintenance/frontend-reconciliation/build-schema16-sql.py
"""
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'PRISM_V14_FRONTEND_RECONCILIATION' / 'schema-16'
PINNED = 'COLLATE=utf8mb4_unicode_ci'
COLLATIONS = ('utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_uca1400_ai_ci')

# Exact schema-15 table set (disposable fixture, tests/claude-settings-manifest-3) and optional onboarding extension.
BASE15 = sorted('''academic_years account_invitations account_purge_jobs activity_logs advisers ai_outputs
calendar_deadline_groups calendar_deadline_recipients calendar_deadlines document_summary_versions documents
ierb_correction_cases ierb_history ierb_report_snapshots ierb_workflow_events ierb_workflows legal_policies
legal_policy_approvals legal_policy_versions notification_campaigns notifications password_resets reports
research_project_events research_project_identifiers research_project_member_history research_project_members
research_projects schema_meta staff_registration_code stage_labels students user_policy_acceptances users'''.split())
ONBOARDING = ['research_onboarding_assignments', 'research_project_protocols']
ANCHORS = [('documents', 'id'), ('reports', 'id'), ('students', 'research_title'), ('students', 'research_group'),
           ('students', 'stage'), ('students', 'academic_year')]

# Structural fingerprints (columns, indexes, foreign keys, checks) independent of the derived collation.
EXPECTED = {
    'academic_year_settings':
        "InnoDB || year_label:varchar(9):NO:~:utf8mb4:,available_for_registration:tinyint(1):NO:1:-:,is_current:tinyint(1):NO:0:-: || PRIMARY:0:1:year_label || academic_year_settings_year_fk:year_label:academic_years:year_label:RESTRICT:RESTRICT || academic_year_settings_availability,academic_year_settings_current",
    'prism_settings':
        "InnoDB || setting_key:varchar(80):NO:~:utf8mb4:,setting_value:mediumtext:NO:~:utf8mb4:,updated_by_user_id:int(11):YES:NULL:-:,updated_at:datetime(6):NO:current_timestamp(6):-: || PRIMARY:0:1:setting_key,prism_settings_actor_fk:1:1:updated_by_user_id || prism_settings_actor_fk:updated_by_user_id:users:id:RESTRICT:SET NULL || ",
    'prism_deadline_reminder_dispatches':
        "InnoDB || deadline_id:int(11):NO:~:-:,student_id:int(11):NO:~:-:,deadline_date:date:NO:~:-:,interval_days:tinyint(3) unsigned:NO:~:-:,notification_id:int(11):YES:NULL:-:,queued_at:datetime(6):NO:current_timestamp(6):-: || PRIMARY:0:1:deadline_id,PRIMARY:0:2:student_id,PRIMARY:0:3:deadline_date,PRIMARY:0:4:interval_days,prism_deadline_reminder_notification_idx:1:1:notification_id,prism_deadline_reminder_student_fk:1:1:student_id || prism_deadline_reminder_deadline_fk:deadline_id:calendar_deadlines:id:RESTRICT:CASCADE,prism_deadline_reminder_notification_fk:notification_id:notifications:id:RESTRICT:SET NULL,prism_deadline_reminder_student_fk:student_id:students:id:RESTRICT:CASCADE || prism_deadline_reminder_interval",
    'academic_year_removals':
        "InnoDB || year_label:varchar(9):NO:~:utf8mb4:,removed_by_user_id:int(11):YES:NULL:-:,removed_at:datetime(6):NO:current_timestamp(6):-: || PRIMARY:0:1:year_label,academic_year_removal_actor_fk:1:1:removed_by_user_id || academic_year_removal_actor_fk:removed_by_user_id:users:id:RESTRICT:SET NULL,academic_year_removal_year_fk:year_label:academic_years:year_label:RESTRICT:RESTRICT || ",
}


def ddl(path, names):
    source = (ROOT / path).read_text(encoding='utf-8')
    found = {}
    for name in names:
        m = re.search(r"'" + name + r"'=>\"(CREATE TABLE .*?)\"", source, re.S)
        if not m:
            raise SystemExit('DDL not found: ' + name)
        text = m.group(1)
        if text.count(PINNED) != 1 or "'" in text or ';' in text or '`' in text:
            raise SystemExit('Unexpected DDL characters: ' + name)
        found[name] = text
    return found


TABLES = {**ddl('includes/schema_v16.php', ['academic_year_settings']),
          **ddl('includes/admin_settings_schema.php',
                ['prism_settings', 'prism_deadline_reminder_dispatches', 'academic_year_removals'])}
NEW = list(TABLES)


def q(items):
    return ','.join("'" + i + "'" for i in items)


def guard(condition, message):
    assert "'" not in message and '`' not in message
    return (f"SET @prism_sql := IF({condition}, 'DO 0', 'SELECT `PRISM ABORT: {message}`');\n"
            "PREPARE prism_step FROM @prism_sql;\nEXECUTE prism_step;\nDEALLOCATE PREPARE prism_step;\n")


def bin_list(items):
    return ','.join(sorted(items, key=lambda s: s.encode()))


def fingerprint(table):
    s = "TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "'"
    return f"""CONCAT_WS(' || ',
    COALESCE((SELECT ENGINE FROM information_schema.TABLES WHERE {s}),''),
    COALESCE((SELECT GROUP_CONCAT(CONCAT_WS(':',COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,'~'),COALESCE(CHARACTER_SET_NAME,'-'),EXTRA)
        ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.COLUMNS WHERE {s}),''),
    COALESCE((SELECT GROUP_CONCAT(CONCAT_WS(':',INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME)
        ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE {s}),''),
    COALESCE((SELECT GROUP_CONCAT(CONCAT_WS(':',r.CONSTRAINT_NAME,k.COLUMN_NAME,r.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE)
        ORDER BY BINARY r.CONSTRAINT_NAME,k.ORDINAL_POSITION SEPARATOR ',')
        FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE k
        ON k.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA AND k.TABLE_NAME=r.TABLE_NAME AND k.CONSTRAINT_NAME=r.CONSTRAINT_NAME
        WHERE r.CONSTRAINT_SCHEMA=DATABASE() AND r.TABLE_NAME='{table}'),''),
    COALESCE((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY BINARY CONSTRAINT_NAME SEPARATOR ',')
        FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{table}'),''))"""


def expected(table):
    value = EXPECTED[table]
    return "'UNSET'" if value is None else "'" + value + "'"


COMMON = f"""SET SESSION group_concat_max_len = 1048576;
SELECT GROUP_CONCAT(TABLE_NAME ORDER BY BINARY TABLE_NAME SEPARATOR ',') INTO @prism_tables
    FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
SELECT (SELECT v FROM schema_meta WHERE k='schema_version'),
       (SELECT v FROM schema_meta WHERE k='prism_settings_v1'),
       (SELECT v FROM schema_meta WHERE k='research_onboarding_v1')
    INTO @prism_version, @prism_settings_marker, @prism_onboarding_marker;
SELECT COUNT(*), COUNT(DISTINCT COLLATION_NAME), MIN(COLLATION_NAME), SUM(CHARACTER_SET_NAME='utf8mb4')
    INTO @prism_anchor_count, @prism_anchor_distinct, @prism_collation, @prism_anchor_utf8mb4
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ({' OR '.join(f"(TABLE_NAME='{t}' AND COLUMN_NAME='{c}')" for t, c in ANCHORS)});
SET @prism_collation_ok := (@prism_anchor_count = {len(ANCHORS)} AND @prism_anchor_distinct = 1 AND @prism_anchor_utf8mb4 = {len(ANCHORS)}
    AND @prism_collation IN ({q(COLLATIONS)}));
SET @prism_base15 := '{bin_list(BASE15)}';
SET @prism_base15_onboarding := '{bin_list(BASE15 + ONBOARDING)}';
SET @prism_base16 := '{bin_list(BASE15 + NEW)}';
SET @prism_base16_onboarding := '{bin_list(BASE15 + ONBOARDING + NEW)}';
SET @prism_onboarding_ok := (@prism_onboarding_marker IS NULL OR @prism_onboarding_marker = '1');
SET @prism_expect15 := IF(@prism_onboarding_marker = '1', @prism_base15_onboarding, @prism_base15);
SET @prism_expect16 := IF(@prism_onboarding_marker = '1', @prism_base16_onboarding, @prism_base16);
"""


def structure_ok():
    parts = []
    for t in NEW:
        parts.append(f"({fingerprint(t)}) = {expected(t)}")
        parts.append(f"(SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{t}') = @prism_collation")
    return 'SET @prism_structure_ok := (\n    ' + '\n    AND '.join(parts) + ');\n'


HEADER = """-- PRISM V14 Settings: {title}
-- Generated by maintenance/frontend-reconciliation/build-schema16-sql.py from the reviewed PHP DDL. Do not edit by hand.
-- Read PRISM_SCHEMA_16_MIGRATION_GUIDE.md first. Production execution requires separate written approval.
-- phpMyAdmin: select ONLY the PRISM database, open the SQL tab, paste this whole file, keep foreign-key checks ON, click Go.
-- Any line reporting "Unknown column 'PRISM ABORT: ...'" is a deliberate stop; read the message and do not retry blindly.
"""


def migrate():
    s = [HEADER.format(title='schema 15 -> 16 migration (Academic Year and Communication Settings)')]
    s.append("SET SESSION foreign_key_checks = 1;\nSET @prism_lock := GET_LOCK('prism_migrate', 5);\n")
    s.append(guard("@prism_lock = 1", "another schema or settings change is running - nothing was changed"))
    s.append(guard("DATABASE() IS NOT NULL", "select the PRISM database before running this script"))
    s.append(guard("VERSION() LIKE '%MariaDB%'", "the reviewed contracts require MariaDB - nothing was changed"))
    s.append(COMMON)
    s.append(guard("@prism_version = '15'", "schema_version must be 15 - nothing was changed"))
    s.append(guard("@prism_settings_marker IS NULL", "the Settings extension marker already exists - nothing was changed"))
    s.append(guard("@prism_onboarding_ok", "unknown research onboarding marker - nothing was changed"))
    s.append(guard("@prism_collation_ok", "legacy anchor collations are missing, mixed or unreviewed - nothing was changed"))
    s.append(guard("@prism_tables = @prism_expect15", "the table list differs from reviewed schema 15 - partial or unknown tables exist - nothing was changed"))
    s.append(guard("@@SESSION.foreign_key_checks = 1", "foreign key checks must stay enabled - nothing was changed"))
    s.append("\n-- All preconditions passed. Create the four additive tables with the derived legacy collation.\n")
    for t, text in TABLES.items():
        s.append(f"SET @prism_sql := REPLACE('{text}', '{PINNED}', CONCAT('COLLATE=', @prism_collation));\n"
                 "PREPARE prism_step FROM @prism_sql;\nEXECUTE prism_step;\nDEALLOCATE PREPARE prism_step;\n")
    s.append("\n-- Verify the created structure before writing any data or version stamp.\n")
    s.append(COMMON)
    s.append(structure_ok())
    s.append(guard("@prism_tables = @prism_expect16 AND @prism_structure_ok",
                   "created tables do not match the reviewed contract - version NOT stamped - run PRISM_SCHEMA_16_VERIFY.sql then PRISM_SCHEMA_16_ROLLBACK.sql"))
    s.append("""
-- Seed policy rows and stamp the version in one transaction. Student assignments are never changed.
START TRANSACTION;
INSERT IGNORE INTO academic_years(year_label,historical)
    SELECT DISTINCT academic_year,1 FROM students
    WHERE academic_year IS NOT NULL AND TRIM(academic_year)<>'' AND CHAR_LENGTH(academic_year)<=9;
INSERT IGNORE INTO academic_year_settings(year_label)
    SELECT year_label FROM academic_years
    WHERE year_label REGEXP '^[1-9][0-9]{3}-[1-9][0-9]{3}$'
    AND CAST(RIGHT(year_label,4) AS UNSIGNED)=CAST(LEFT(year_label,4) AS UNSIGNED)+1;
UPDATE schema_meta SET v='16' WHERE k='schema_version' AND v='15';
SET @prism_stamped := ROW_COUNT();
INSERT INTO schema_meta(k,v) VALUES ('prism_settings_v1','1');
""")
    s.append(guard("@prism_stamped = 1", "version stamp failed - the transaction is abandoned and rolls back on disconnect"))
    s.append("COMMIT;\nDO RELEASE_LOCK('prism_migrate');\n")
    s.append("SELECT 'result' AS check_name, 'MIGRATED_TO_16 - now run PRISM_SCHEMA_16_VERIFY.sql' AS value\n"
             "UNION ALL SELECT 'academic_year_settings rows', (SELECT COUNT(*) FROM academic_year_settings)\n"
             "UNION ALL SELECT 'collation', @prism_collation;\n")
    return ''.join(s)


def verify():
    s = [HEADER.format(title='schema 16 verification (read-only; safe to run at any time)')]
    s.append(COMMON)
    s.append(structure_ok())
    present = ' OR '.join(f"FIND_IN_SET('{t}',@prism_tables)" for t in NEW)
    s.append(f"SET @prism_new_present := ({present});\n")
    # Row-level checks run only on existing tables, through dynamic SQL.
    s.append("""SET @prism_current_count := NULL;
SET @prism_sql := IF(FIND_IN_SET('academic_year_settings',@prism_tables),
    'SELECT COUNT(*) INTO @prism_current_count FROM academic_year_settings WHERE is_current=1', 'DO 0');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;
SET @prism_overall := CASE
    WHEN @prism_version = '16' AND @prism_settings_marker = '1' AND @prism_onboarding_ok AND @prism_collation_ok
        AND @prism_tables = @prism_expect16 AND @prism_structure_ok AND @prism_current_count <= 1 THEN 'PASS'
    WHEN @prism_version = '15' AND @prism_settings_marker IS NULL AND @prism_onboarding_ok AND @prism_collation_ok
        AND @prism_tables = @prism_expect15 AND NOT @prism_new_present THEN 'NOT_MIGRATED'
    ELSE 'FAIL' END;
""")
    rows = [("'server'", "VERSION()"), ("'schema_version'", "@prism_version"),
            ("'prism_settings_v1'", "COALESCE(@prism_settings_marker,'(absent)')"),
            ("'research_onboarding_v1'", "COALESCE(@prism_onboarding_marker,'(absent)')"),
            ("'legacy collation'", "CONCAT(@prism_collation, IF(@prism_collation_ok,' OK',' NOT REVIEWED/MIXED'))"),
            ("'table list'", "IF(@prism_tables=@prism_expect16,'schema16 exact',IF(@prism_tables=@prism_expect15,'schema15 exact','DIFFERS'))"),
            ("'new table structure'", "IF(@prism_structure_ok,'OK',IF(@prism_new_present,'DIFFERS','absent'))"),
            ("'current academic years'", "COALESCE(@prism_current_count,'(table absent)')")]
    for t in NEW:
        rows.append((f"'fingerprint {t}'", fingerprint(t)))
    rows.append(("'overall'", '@prism_overall'))
    # MariaDB 10.4 rejects a UNION mixing information_schema utf8mb3 with utf8mb4 values; report everything as utf8mb4.
    rows = [(f'CONVERT({n} USING utf8mb4)', f'CONVERT({v} USING utf8mb4)') for n, v in rows]
    s.append('SELECT ' + rows[0][0] + ' AS check_name, ' + rows[0][1] + ' AS value\n'
             + ''.join(f'UNION ALL SELECT {n}, {v}\n' for n, v in rows[1:]) + ';\n')
    return ''.join(s)


def rollback():
    s = [HEADER.format(title='schema 16 -> 15 rollback (only while no Settings data or Settings audit history exists)')]
    s.append("SET SESSION foreign_key_checks = 1;\nSET @prism_lock := GET_LOCK('prism_migrate', 5);\n")
    s.append(guard("@prism_lock = 1", "another schema or settings change is running - nothing was changed"))
    s.append(guard("DATABASE() IS NOT NULL", "select the PRISM database before running this script"))
    s.append(guard("VERSION() LIKE '%MariaDB%'", "the reviewed contracts require MariaDB - nothing was changed"))
    s.append(COMMON)
    s.append(guard("@prism_onboarding_ok AND @prism_collation_ok", "unknown extension marker or unreviewed collation - nothing was changed"))
    s.append(guard("@prism_version = '16' OR (@prism_version = '15' AND @prism_settings_marker IS NULL)",
                   "schema_version is neither 16 nor an unstamped partial 15 install - nothing was changed"))
    # Every present table must belong to schema 16 (no unknown tables), and every base-15 table must remain.
    s.append(f"""SET @prism_known_ok := (SELECT COUNT(*) = 0 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND NOT FIND_IN_SET(TABLE_NAME, @prism_expect16));
SET @prism_base_ok := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND FIND_IN_SET(TABLE_NAME, @prism_expect15)) =
    (LENGTH(@prism_expect15) - LENGTH(REPLACE(@prism_expect15, ',', '')) + 1);
""")
    s.append(guard("@prism_known_ok AND @prism_base_ok", "unknown or missing tables - nothing was changed"))
    checks = [
        ('prism_settings', 'SELECT COUNT(*) INTO @prism_rows FROM prism_settings'),
        ('prism_deadline_reminder_dispatches', 'SELECT COUNT(*) INTO @prism_rows FROM prism_deadline_reminder_dispatches'),
        ('academic_year_removals', 'SELECT COUNT(*) INTO @prism_rows FROM academic_year_removals'),
        ('academic_year_settings', 'SELECT COUNT(*) INTO @prism_rows FROM academic_year_settings WHERE available_for_registration<>1 OR is_current<>0'),
    ]
    for t, query in checks:
        s.append(f"""SET @prism_rows := 0;
SET @prism_sql := IF(FIND_IN_SET('{t}',@prism_tables), '{query}', 'DO 0');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;
""")
        what = 'custom academic-year availability or current-year settings exist - restore defaults first' if t == 'academic_year_settings' \
            else t + ' holds retained data - export and keep it - rollback refused'
        s.append(guard("@prism_rows = 0", what))
    s.append(guard("NOT EXISTS (SELECT 1 FROM activity_logs WHERE action IN ('communication_setting_updated','academic_year_removed_from_registration'))",
                   "retained Settings audit history blocks rollback - disable the feature instead"))
    s.append("""
-- Return the version first (one transaction), then drop the now-unused empty tables.
START TRANSACTION;
DELETE FROM schema_meta WHERE k='prism_settings_v1';
UPDATE schema_meta SET v='15' WHERE k='schema_version';
COMMIT;
""")
    for t in reversed(NEW):
        s.append(f"DROP TABLE IF EXISTS {t};\n")
    s.append(COMMON)
    s.append(guard("@prism_version = '15' AND @prism_settings_marker IS NULL AND @prism_tables = @prism_expect15",
                   "rollback did not reach the exact schema 15 table list - run PRISM_SCHEMA_16_VERIFY.sql and stop"))
    s.append("DO RELEASE_LOCK('prism_migrate');\nSELECT 'result' AS check_name, 'ROLLED_BACK_TO_15 - run PRISM_SCHEMA_16_VERIFY.sql (expect NOT_MIGRATED)' AS value;\n")
    return ''.join(s)


OUT.mkdir(parents=True, exist_ok=True)
for name, body in [('PRISM_SCHEMA_15_TO_16.sql', migrate()), ('PRISM_SCHEMA_16_VERIFY.sql', verify()),
                   ('PRISM_SCHEMA_16_ROLLBACK.sql', rollback())]:
    (OUT / name).write_text(body, encoding='utf-8', newline='\n')
    print('wrote', OUT / name)
