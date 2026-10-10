-- PRISM v8 additive manual migration. DO NOT upload this file to a public server.
-- First export a complete backup in phpMyAdmin and verify a disposable copy.
-- Select the exact target database. These defaults REFUSE all mutations.
SET @PRISM_EXPECT_DB = 'u706882574_prismv8staging';
SET @PRISM_BACKUP_AND_STAGING_VERIFIED = 1;
SET @prism_apply = DATABASE() = @PRISM_EXPECT_DB
    AND @PRISM_BACKUP_AND_STAGING_VERIFIED = 1
    AND EXISTS (SELECT 1 FROM schema_meta WHERE k = 'schema_version' AND v IN ('7','8'));
SELECT DATABASE() AS selected_database, @prism_apply AS explicitly_authorized;
-- Require canonical v7 first. Compare these outputs with the deployment report.
SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN ('calendar_deadlines','calendar_deadline_groups');
SELECT COLUMN_TYPE,COLLATION_NAME,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_deadline_groups' AND COLUMN_NAME='research_group';

SET @prism_missing = NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='archived_at');
SET @prism_sql = IF(@prism_apply AND @prism_missing, 'ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL', 'SELECT ''REFUSED: verify the explicit database, backup and staging approval'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;

SET @prism_missing = NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='sending_started_at');
SET @prism_sql = IF(@prism_apply AND @prism_missing, 'ALTER TABLE `notifications` ADD COLUMN `sending_started_at` DATETIME NULL', 'SELECT ''REFUSED: verify the explicit database, backup and staging approval'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;

SET @prism_sql = IF(@prism_apply, 'CREATE TABLE IF NOT EXISTS calendar_deadline_recipients (
        deadline_id INT NOT NULL, student_id INT NOT NULL,
        PRIMARY KEY (deadline_id, student_id), INDEX deadline_recipient_student (student_id, deadline_id),
        FOREIGN KEY (deadline_id) REFERENCES calendar_deadlines(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'SELECT ''REFUSED: verify the explicit database, backup and staging approval'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;

SET @prism_sql = IF(@prism_apply, 'INSERT IGNORE INTO calendar_deadline_recipients (deadline_id, student_id)
        SELECT d.id, s.id FROM calendar_deadlines d JOIN notifications n
          ON n.recipient_type = ''student'' AND n.subject = ''New official deadline''
          AND LEFT(n.message, CHAR_LENGTH(CONCAT(''New official deadline (Deadline #'', d.id, '').'', CHAR(10), ''Title: '')))
              = CONCAT(''New official deadline (Deadline #'', d.id, '').'', CHAR(10), ''Title: '')
        JOIN students s ON s.id = n.recipient_id', 'SELECT ''REFUSED: verify the explicit database, backup and staging approval'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;

SET @prism_sql = IF(@prism_apply, 'INSERT IGNORE INTO calendar_deadline_recipients (deadline_id, student_id)
        SELECT d.id, s.id FROM calendar_deadlines d JOIN users u ON u.id = d.creator_user_id
        JOIN students s ON s.archived_at IS NULL LEFT JOIN advisers a ON a.id = s.adviser_id
        WHERE (u.role = ''admin'' OR (u.role = ''adviser'' AND a.email = u.email))
          AND (d.target_scope = ''all'' OR EXISTS (SELECT 1 FROM calendar_deadline_groups g
            WHERE g.deadline_id = d.id AND BINARY g.research_group = BINARY s.research_group))
          AND NOT EXISTS (SELECT 1 FROM calendar_deadline_recipients r WHERE r.deadline_id = d.id)', 'SELECT ''REFUSED: verify the explicit database, backup and staging approval'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;

-- Validate every result BEFORE advancing the schema version. Stop on any error.
SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='students' AND COLUMN_NAME='archived_at')
    OR (TABLE_NAME='notifications' AND COLUMN_NAME='sending_started_at'));
SHOW CREATE TABLE calendar_deadlines;
SHOW CREATE TABLE calendar_deadline_groups;
SET @prism_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_deadline_recipients'), 'SHOW CREATE TABLE calendar_deadline_recipients', 'SELECT ''Audience table absent; migration was not applied'' AS result');
PREPARE prism_step FROM @prism_sql;
EXECUTE prism_step;
DEALLOCATE PREPARE prism_step;
-- Both new columns: nullable DATETIME. Audience: InnoDB, PK(deadline_id,student_id),
-- index(student_id,deadline_id), FK deadline_id CASCADE; FK student_id RESTRICT.
-- Group: VARCHAR(190), utf8mb4_bin, PK(deadline_id,research_group),
-- index(research_group,deadline_id), FK deadline_id CASCADE.
-- Verify all pre-migration row counts and representative history/version/submission records.
-- Repeat the script to verify idempotence. Only then execute the following separately:
-- UPDATE schema_meta SET v='8' WHERE k='schema_version' AND v='7';
-- Do not advance the version after a partial/error result. Correct the specific issue and retry.
