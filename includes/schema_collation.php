<?php
/** Extension tables (schemas 12-15) follow the collation of the legacy base they reference.
 * Pinned contracts are rendered with utf8mb4_unicode_ci; that token is the only variable part.
 * Legacy tables are never converted: a collation change can collide existing unique keys.
 */
const SCHEMA_PINNED_COLLATION = 'utf8mb4_unicode_ci';

/** Reviewed utf8mb4 collations. Others (for example a binary or pad-space variant) fail closed. */
function schema_extension_collations(): array
{
    return ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_uca1400_ai_ci'];
}

/** Legacy columns that extension SQL references by foreign key or compares in expressions
 * (for example COALESCE(project.title, student.title) in the IERB monitor). All must agree.
 */
function schema_extension_anchor_columns(): array
{
    return [['documents','id'], ['reports','id'], ['students','research_title'], ['students','research_group'],
        ['students','stage'], ['students','academic_year']];
}

function schema_extension_collation(PDO $pdo): string
{
    $q = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='documents' AND COLUMN_NAME='id') OR (TABLE_NAME='reports' AND COLUMN_NAME='id')
        OR (TABLE_NAME='students' AND COLUMN_NAME IN ('research_title','research_group','stage','academic_year')))
        ORDER BY TABLE_NAME,COLUMN_NAME");
    $found = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $found[$row['TABLE_NAME'].'.'.$row['COLUMN_NAME']] = $row;
    $collations = [];
    foreach (schema_extension_anchor_columns() as [$table, $column]) {
        $row = $found[$table.'.'.$column] ?? null;
        if (!$row) throw new SchemaCollationError('Legacy anchor column '.$table.'.'.$column.' is missing.');
        if ($row['CHARACTER_SET_NAME'] !== 'utf8mb4') throw new SchemaCollationError('Legacy anchor column '.$table.'.'.$column.' uses character set '.$row['CHARACTER_SET_NAME'].'; utf8mb4 is required.');
        $collations[$row['COLLATION_NAME']][] = $table.'.'.$column;
    }
    if (count($collations) !== 1) {
        $detail = [];
        foreach ($collations as $name => $columns) $detail[] = $name.' ('.implode(', ', $columns).')';
        throw new SchemaCollationError('Legacy anchor columns disagree on collation: '.implode('; ', $detail).'. A reviewed legacy collation alignment is required first.');
    }
    $collation = array_key_first($collations);
    if (!in_array($collation, schema_extension_collations(), true)) throw new SchemaCollationError('Legacy collation '.$collation.' has no reviewed extension contract.');
    return $collation;
}

/** Render pinned DDL for the legacy base. Only the table default token changes. */
function schema_extension_ddl(string $ddl, string $collation): string
{
    if (!in_array($collation, schema_extension_collations(), true)) throw new SchemaCollationError('Unreviewed extension collation.');
    $count = substr_count($ddl, 'COLLATE='.SCHEMA_PINNED_COLLATION);
    if ($count !== 1) throw new LogicException('Extension DDL must declare exactly one pinned table collation.');
    return str_replace('COLLATE='.SCHEMA_PINNED_COLLATION, 'COLLATE='.$collation, $ddl);
}

/** Servers differ in printing the table default (MariaDB 10.4 omits a charset's default collation;
 * 11.5+ always prints it). Compare the exact structural text with the table option removed, then
 * require the authoritative INFORMATION_SCHEMA collation. Nothing else is normalized.
 */
function schema_extension_matches(PDO $pdo, string $table, string $actual, string $contract, string $collation): bool
{
    $strip = static fn(string $sql): string => preg_replace('/ DEFAULT CHARSET=utf8mb4(?: COLLATE=utf8mb4_[a-z0-9_]+)?$/', ' DEFAULT CHARSET=utf8mb4', $sql, 1, $n) ?? '';
    $a = $strip($actual); $c = $strip($contract);
    if ($a === '' || $c === '' || $a !== $c) return false;
    $q = $pdo->prepare('SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return $q->fetchColumn() === $collation;
}

final class SchemaCollationError extends RuntimeException {}
