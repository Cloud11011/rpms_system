<?php
/**
 * CLI-only isolated schema v6 tests. Extract migration declarations only.
 * No config bootstrap, private config, database connection, or real migration runs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$checks = 0;
function migration_expect(mixed $expected, mixed $actual, string $message): void
{
    ++$GLOBALS['checks'];
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}
function migration_failure(callable $run, string $contains, string $message): void
{
    try { $run(); } catch (RuntimeException $error) {
        migration_expect(true, str_contains($error->getMessage(), $contains), $message);
        return;
    }
    throw new RuntimeException($message . ': expected failure was not raised.');
}

$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/config.php'));
$start = strpos($source, 'const SCHEMA_VERSION = ');
$end = strpos($source, '\nfunction seed(');
if ($end === false) $end = strpos($source, "\nfunction seed(");
if ($start === false || $end === false || $end <= $start) throw new RuntimeException('Cannot isolate migration declarations.');
$declarations = substr($source, $start, $end - $start);
if (preg_match('/\b(?:require|include)(?:_once)?\s*\(?\s*[\'"$]/', $declarations)) {
    throw new RuntimeException('Unexpected include in migration fixture.');
}
$GLOBALS['migrationFlag'] = false;
foreach (['PrismAcademicMigrationCLI' => 'cli', 'PrismAcademicMigrationWeb' => 'fpm-fcgi'] as $namespace => $sapi) {
    eval('namespace ' . $namespace . '; use \\PDO; use \\RuntimeException; const PHP_SAPI = '
        . var_export($sapi, true) . '; function getenv(string $name): string|false {'
        . 'if ($name !== "PRISM_ALLOW_SCHEMA_V6_MIGRATION") throw new RuntimeException("Unexpected environment read");'
        . 'return $GLOBALS["migrationFlag"]; } ' . $declarations);
}

class MigrationFixturePDO extends PDO
{
    public array $tables = [];
    public array $columns = [];
    public array $events = [];
    public array $rows = [];
    public array $resetId = ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int(11)',
        'IS_NULLABLE' => 'NO', 'COLUMN_KEY' => 'PRI', 'EXTRA' => 'auto_increment', 'COLUMN_COMMENT' => ''];
    public int $resetPrimaryColumns = 1;
    public array $resetRows = [];
    public array $resetReferences = [];
    public bool $ignoreResetAlter = false;
    public array $integerIds = [];
    public array $idPrimaryCounts = [];
    public array $ignoreIdAlters = [];
    public array $fkStates = [];
    public array $fkColumnOverrides = [];
    public array $orphans = [];
    public array $ignoreFkAlters = [];
    public bool $foreignKeyChecks = true;
    public bool $parentIndexValid = true;
    public bool $childIndexValid = true;
    public function idMetadata(string $table): array
    {
        return $table === 'password_resets' ? $this->resetId : ($this->integerIds[$table] ?? [
            'COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int(11)', 'IS_NULLABLE' => 'NO',
            'COLUMN_KEY' => 'PRI', 'EXTRA' => 'auto_increment', 'COLUMN_COMMENT' => '',
        ]);
    }
    public ?string $version;
    public string $course;
    public string $sqlMode = '';
    public string $showCreateOverride = '';
    public bool $legacy;
    public bool $lockAvailable = true;
    public bool $locked = false;
    public int $lockReleases = 0;
    public bool $concurrentWinner = false;
    public ?string $failOnce = null;
    public function __construct(?string $version = '4', string $course = '`course` varchar(100) DEFAULT NULL')
    {
        $this->version = $version;
        $this->legacy = $version !== null;
        $this->course = $course;
        if ($this->legacy) {
            $this->tables = ['schema_meta' => true, 'students' => true];
            $this->columns['students']['course'] = $course;
            $this->rows = [
                ['id' => 1, 'course' => null, 'full_name' => 'Original One'],
                ['id' => 2, 'course' => '', 'full_name' => 'Original Two'],
                ['id' => 3, 'course' => 'Legacy free-text degree', 'full_name' => 'Original Three'],
            ];
        }
    }
    public function hasColumn(string $table, string $column): bool
    {
        if (isset($this->columns[$table][$column])) return true;
        return $this->legacy && !in_array($column, ['academic_unit_key', 'program_key', 'year_level', 'academic_year'], true);
    }
    public function record(string $sql): void
    {
        $this->events[] = $sql;
        if ($this->failOnce !== null && str_contains($sql, $this->failOnce)) {
            $this->failOnce = null;
            throw new RuntimeException('Injected migration failure');
        }
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record($query);
        if (str_contains($query, 'INFORMATION_SCHEMA.COLUMNS') && str_contains($query, "TABLE_NAME = 'password_resets'")) {
            return new MigrationFixtureStatement($this, $query, [$this->resetId ?: false]);
        }
        if (str_contains($query, 'SELECT COLUMN_NAME, COLUMN_TYPE') && preg_match("/TABLE_NAME = '([a-z_]+)'/", $query, $m)) {
            return new MigrationFixtureStatement($this, $query, [$this->idMetadata($m[1]) ?: false]);
        }
        if (str_contains($query, 'INFORMATION_SCHEMA.STATISTICS') && preg_match("/TABLE_NAME = '([a-z_]+)'/", $query, $m)) {
            return new MigrationFixtureStatement($this, $query, [$m[1] === 'password_resets'
                ? $this->resetPrimaryColumns : ($this->idPrimaryCounts[$m[1]] ?? 1)]);
        }
        if ($query === 'SELECT @@SESSION.foreign_key_checks') {
            return new MigrationFixtureStatement($this, $query, [$this->foreignKeyChecks ? 1 : 0]);
        }
        if (preg_match('/SELECT 1 FROM `([a-z_]+)` child.*child\.`([a-z_]+)`/s', $query, $m)) {
            return new MigrationFixtureStatement($this, $query, [!empty($this->orphans[$m[1] . '.' . $m[2]]) ? 1 : false]);
        }
        if (str_contains($query, 'INFORMATION_SCHEMA.TABLES')) {
            return new MigrationFixtureStatement($this, $query, [isset($this->tables['schema_meta']) ? 1 : 0]);
        }
        if (str_contains($query, 'SELECT v FROM schema_meta')) {
            if (!isset($this->tables['schema_meta'])) throw new RuntimeException('Read missing metadata table');
            return new MigrationFixtureStatement($this, $query, [$this->version ?? false]);
        }
        if (str_contains($query, 'GET_LOCK(')) {
            if ($this->lockAvailable) $this->locked = true;
            if ($this->concurrentWinner) $this->version = '6';
            return new MigrationFixtureStatement($this, $query, [$this->lockAvailable ? 1 : 0]);
        }
        if (str_contains($query, 'RELEASE_LOCK(')) {
            if (!$this->locked) throw new RuntimeException('Releasing unowned migration lock');
            $this->locked = false;
            ++$this->lockReleases;
            return new MigrationFixtureStatement($this, $query, [1]);
        }
        if ($query === 'SHOW CREATE TABLE `students`') {
            if (!isset($this->tables['students'])) throw new RuntimeException('Missing students table');
            $ddl = $this->showCreateOverride ?: "CREATE TABLE `students` (\n  `id` int NOT NULL,\n  "
                . $this->course . ",\n  `full_name` varchar(190),\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
            return new MigrationFixtureStatement($this, $query, ['students', $ddl]);
        }
        if ($query === 'SELECT @@SESSION.sql_mode') return new MigrationFixtureStatement($this, $query, [$this->sqlMode]);
        throw new RuntimeException('Unexpected fixture query: ' . $query);
    }
    public function exec(string $statement): int|false
    {
        $this->record($statement);
        if (!$this->locked) throw new RuntimeException('DDL or migration write without advisory lock');
        if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+([a-z_]+)/i', trim($statement), $match)) {
            $table = $match[1];
            if (isset($this->tables[$table])) return 0;
            $this->tables[$table] = true;
            preg_match_all('/^\s+([a-z_]+)\s+(.+?)(?:,)?$/m', $statement, $fields, PREG_SET_ORDER);
            foreach ($fields as $field) $this->columns[$table][$field[1]] = $field[2];
            if ($table === 'students') {
                if (!preg_match('/\bcourse\s+(VARCHAR\(\d+\))/i', $statement, $course)) throw new RuntimeException('Fresh course definition missing');
                $this->course = '`course` ' . $course[1] . ' DEFAULT NULL';
            }
            return 0;
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` ADD COLUMN `([^`]+)` (.+)$/s', $statement, $match)) {
            [, $table, $column, $definition] = $match;
            if ($this->hasColumn($table, $column)) throw new RuntimeException('Duplicate column in retry: ' . $column);
            $this->columns[$table][$column] = $definition;
            if ($table === 'students') foreach ($this->rows as &$row) $row[$column] = null;
            return 0;
        }
        if (str_starts_with($statement, 'ALTER TABLE `password_resets` MODIFY COLUMN `id` ')) {
            if (!str_contains($this->sqlMode, 'NO_AUTO_VALUE_ON_ZERO')) throw new RuntimeException('Zero reset IDs could be renumbered');
            $expected = 'ALTER TABLE `password_resets` MODIFY COLUMN `id` ' . $this->resetId['COLUMN_TYPE']
                . ' NOT NULL AUTO_INCREMENT COMMENT ' . $this->quote($this->resetId['COLUMN_COMMENT']);
            if ($statement !== $expected) throw new RuntimeException('Unexpected reset key rewrite');
            if (!$this->ignoreResetAlter) $this->resetId['EXTRA'] = 'auto_increment';
            return 0;
        }
        if (preg_match('/^ALTER TABLE `([a-z_]+)` MODIFY COLUMN `id` /', $statement, $m)) {
            if (!str_contains($this->sqlMode, 'NO_AUTO_VALUE_ON_ZERO')) throw new RuntimeException('Zero keys could be renumbered');
            $id = $this->idMetadata($m[1]);
            if ($statement !== 'ALTER TABLE `' . $m[1] . '` MODIFY COLUMN `id` '
                . $id['COLUMN_TYPE'] . ' NOT NULL AUTO_INCREMENT COMMENT ' . $this->quote($id['COLUMN_COMMENT'])) {
                throw new RuntimeException('Unexpected ID rewrite');
            }
            if (empty($this->ignoreIdAlters[$m[1]])) {
                $id['EXTRA'] = 'auto_increment';
                $this->integerIds[$m[1]] = $id;
            }
            return 0;
        }
        if (preg_match('/^ALTER TABLE `([a-z_]+)` ADD FOREIGN KEY \\(`([a-z_]+)`\\) REFERENCES `([a-z_]+)` \\(`id`\\) ON DELETE (CASCADE|SET NULL)$/', $statement, $m)) {
            $key = $m[1] . '.' . $m[2];
            if (($this->fkStates[$key] ?? 'correct') !== 'missing') throw new RuntimeException('Duplicate/unexpected FK addition');
            if (empty($this->ignoreFkAlters[$key])) $this->fkStates[$key] = 'correct';
            return 0;
        }
        if (str_starts_with($statement, 'ALTER TABLE `students` MODIFY COLUMN ')) {
            $this->course = substr($statement, strlen('ALTER TABLE `students` MODIFY COLUMN '));
            $this->columns['students']['course'] = $this->course;
            return 0;
        }
        // Preserve the pre-existing one-time formal-submission migration on fresh fixtures.
        if (preg_match('/^UPDATE documents\s+SET rpms_submitted_at/', $statement)) return 0;
        throw new RuntimeException('Unexpected fixture mutation: ' . $statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MigrationFixtureStatement($this, $query);
    }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    { return "'" . str_replace("'", "''", $string) . "'"; }
    public function beginTransaction(): bool { throw new RuntimeException('DDL cannot rely on transaction rollback'); }
    public function rollBack(): bool { throw new RuntimeException('DDL cannot rely on transaction rollback'); }
    public function commit(): bool { throw new RuntimeException('DDL cannot rely on transaction rollback'); }
}
class MigrationFixtureStatement extends PDOStatement
{
    public function __construct(private MigrationFixturePDO $pdo, private string $sql, private array $values = []) {}
    public function execute(?array $params = null): bool
    {
        $this->pdo->record($this->sql);
        if ($this->sql === 'SET SESSION sql_mode = :mode') {
            if (!$this->pdo->locked) throw new RuntimeException('Session mode changed outside migration lock');
            $this->pdo->sqlMode = $params[':mode'];
            return true;
        }
        if (str_contains($this->sql, 'SELECT c.DATA_TYPE')) {
            $key = $params[':table'] . '.' . $params[':column'];
            $this->values = [array_replace(['DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int(11)', 'IS_NULLABLE' => 'YES',
                'EXTRA' => $params[':column'] === 'id' ? 'auto_increment' : '', 'ENGINE' => 'InnoDB'],
                $this->pdo->fkColumnOverrides[$key] ?? [])];
            return true;
        }
        if (str_contains($this->sql, 'SELECT COLUMN_NAME, SEQ_IN_INDEX')) {
            $this->values = $this->pdo->parentIndexValid
                ? [['COLUMN_NAME' => 'id', 'SEQ_IN_INDEX' => 1, 'INDEX_TYPE' => 'BTREE']] : [];
            return true;
        }
        if (str_contains($this->sql, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE')) {
            $this->values = [];
            foreach (PrismAcademicMigrationCLI\migration_required_foreign_keys() as [$table, $column, $parent, $delete]) {
                if ($table !== $params[':table']) continue;
                $state = $this->pdo->fkStates[$table . '.' . $column] ?? 'correct';
                if ($state === 'missing') continue;
                $row = ['CONSTRAINT_NAME' => 'arbitrary_' . $column, 'COLUMN_NAME' => $column,
                    'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => $parent,
                    'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => $delete,
                    'UPDATE_RULE' => 'RESTRICT', 'CURRENT_SCHEMA' => 'fixture'];
                if (is_array($state)) $row = array_replace($row, $state);
                $this->values[] = $row;
                if ($state === 'composite') $this->values[] = array_replace($row, ['COLUMN_NAME' => 'other_column']);
            }
            return true;
        }
        if (str_contains($this->sql, 'INFORMATION_SCHEMA.STATISTICS')) {
            $this->values = [$this->pdo->childIndexValid ? 1 : 0];
            return true;
        }
        if (str_contains($this->sql, 'INFORMATION_SCHEMA.COLUMNS')) {
            $this->values = [$this->pdo->hasColumn($params[':t'], $params[':c']) ? 1 : 0];
            return true;
        }
        if (str_starts_with($this->sql, 'REPLACE INTO schema_meta')) {
            if (!$this->pdo->locked) throw new RuntimeException('Version write without lock');
            foreach ((int)$this->pdo->version < 5 ? ['academic_unit_key', 'program_key', 'year_level', 'academic_year'] : [] as $column) {
                if (!isset($this->pdo->columns['students'][$column])) throw new RuntimeException('Version stamp before required academic DDL');
            }
            $this->pdo->version = $params[':v'];
            return true;
        }
        if (str_starts_with($this->sql, 'INSERT IGNORE INTO stage_labels')) return true;
        throw new RuntimeException('Unexpected fixture prepared query: ' . $this->sql);
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    { return $this->values[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->values; }
    public function fetchColumn(int $column = 0): mixed { return $this->values[$column] ?? false; }
}
function migration_writes(MigrationFixturePDO $pdo): array
{
    return array_values(array_filter($pdo->events, fn($sql) => (bool)preg_match('/^\s*(CREATE|ALTER|UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql)));
}
function academic_alters(MigrationFixturePDO $pdo): array
{
    return array_values(array_filter($pdo->events, fn($sql) => str_starts_with($sql, 'ALTER TABLE `students`')));
}
function check_academic_columns(MigrationFixturePDO $pdo, string $label): void
{
    foreach (['academic_unit_key' => 64, 'program_key' => 80, 'year_level' => 40, 'academic_year' => 9] as $column => $width) {
        migration_expect('VARCHAR(' . $width . ') NULL DEFAULT NULL', $pdo->columns['students'][$column] ?? null, $label . ': ' . $column);
    }
}

migration_expect(6, PrismAcademicMigrationCLI\SCHEMA_VERSION, 'The migration version is v6');
foreach ([null, '4', '5'] as $version) {
    foreach ([['PrismAcademicMigrationCLI\\migrate', false], ['PrismAcademicMigrationWeb\\migrate', false],
              ['PrismAcademicMigrationWeb\\migrate', '1'], ['PrismAcademicMigrationCLI\\migrate', 'true']] as [$run, $flag]) {
        $pdo = new MigrationFixturePDO($version);
        $GLOBALS['migrationFlag'] = $flag;
        migration_failure(fn() => $run($pdo), 'migration is pending', 'Pending migration requires approved CLI context');
        migration_expect([], migration_writes($pdo), 'Refused migration executes zero DDL or writes');
        migration_expect($version, $pdo->version, 'Refused migration leaves version alone');
        migration_expect(false, $pdo->locked, 'Refused migration never acquires migration lock');
    }
}
foreach (['PrismAcademicMigrationCLI\\migrate', 'PrismAcademicMigrationWeb\\migrate'] as $run) {
    $GLOBALS['migrationFlag'] = false;
    $pdo = new MigrationFixturePDO('6');
    $run($pdo);
    migration_expect([], migration_writes($pdo), 'Existing v6 is usable without migration approval');
    migration_expect(0, $pdo->lockReleases, 'Existing v6 does not acquire migration lock');
}
$GLOBALS['migrationFlag'] = '1';
$run = 'PrismAcademicMigrationCLI\\migrate';
foreach ([null, '4'] as $version) {
    $pdo = new MigrationFixturePDO($version);
    $beforeRows = $pdo->rows;
    $run($pdo);
    migration_expect('6', $pdo->version, 'Fresh/v4 migration advances only after completion');
    migration_expect('`course` VARCHAR(255) DEFAULT NULL', $pdo->course, 'Fresh/v4 course holds full program labels');
    check_academic_columns($pdo, 'Fresh/v4 schema');
    migration_expect(1, $pdo->lockReleases, 'Migration releases advisory lock');
    migration_expect(false, $pdo->locked, 'Migration leaves no held advisory lock');
    migration_expect(array_column($beforeRows, 'course'), array_column($pdo->rows, 'course'), 'Legacy null/empty/free-text course values remain unchanged');
    migration_expect(array_column($beforeRows, 'full_name'), array_column($pdo->rows, 'full_name'), 'Unrelated student values remain unchanged');
    foreach ($pdo->rows as $row) foreach (['academic_unit_key', 'program_key', 'year_level', 'academic_year'] as $column) {
        migration_expect(null, $row[$column], 'Existing rows receive NULL academic values');
    }
    $events = count($pdo->events);
    $run($pdo);
    migration_expect([], array_values(array_filter(array_slice($pdo->events, $events), fn($sql) => (bool)preg_match('/^\s*(CREATE|ALTER|UPDATE|INSERT|REPLACE)\b/i', $sql))), 'Repeated v6 migration is a read-only no-op');
}
foreach (['varchar(255)', 'varchar(512)', 'text', 'mediumtext', 'longtext', 'char(255)'] as $type) {
    $definition = '`course` ' . $type . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Keep this\'';
    $pdo = new MigrationFixturePDO('4', $definition);
    $pdo->rows[] = ['id' => 4, 'course' => str_repeat('Long legacy degree ', 12), 'full_name' => 'Keep Long'];
    $oldRows = $pdo->rows;
    $run($pdo);
    migration_expect($definition, $pdo->course, 'Adequate ' . $type . ' is never shrunk or redefined');
    migration_expect(4, count(academic_alters($pdo)), 'Only new academic columns added for ' . $type);
    migration_expect(array_column($oldRows, 'course'), array_column($pdo->rows, 'course'), 'Long legacy course retained for ' . $type);
}
foreach ([
    ['`course` varchar(100) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT \'Old, (degree)\' COMMENT \'Keep attributes\'', ''],
    ['`course` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL COMMENT \'Line one\nline two\'', ''],
    ['`course` varchar(190) NOT NULL DEFAULT \'Dean\\\'s, degree\' COMMENT \'Default has an escaped quote\'', ''],
    ['`course` varchar(100) DEFAULT \'Dean\'\'s degree\' COMMENT \'Line one' . "\n" . 'line two, (unchanged)\'', 'NO_BACKSLASH_ESCAPES'],
    ['`course` varchar(100) DEFAULT \'C:\\\\degree\\\' COMMENT \'Preserve ending slash\'', 'NO_BACKSLASH_ESCAPES'],
    ['"course" varchar(100) DEFAULT \'Mixed quoting\'', 'ANSI_QUOTES'],
    ['course varchar(100) DEFAULT NULL', ''],
] as [$definition, $sqlMode]) {
    $pdo = new MigrationFixturePDO('4', $definition);
    $pdo->sqlMode = $sqlMode;
    $expected = preg_replace('/(?:var)?char\(\d+\)/i', 'VARCHAR(255)', $definition, 1);
    $run($pdo);
    migration_expect($expected, $pdo->course, 'Widening preserves complete defaults, nullability, charset, collation and comments');
    migration_expect('6', $pdo->version, 'Attribute-preserving widening completes');
}
$pdo = new MigrationFixturePDO('4');
$pdo->failOnce = 'ADD COLUMN `year_level`';
$originalCourses = array_column($pdo->rows, 'course');
migration_failure(fn() => $run($pdo), 'Injected migration failure', 'Partial academic DDL can fail');
migration_expect('4', $pdo->version, 'Partial DDL never stamps v6');
migration_expect(true, isset($pdo->columns['students']['academic_unit_key'], $pdo->columns['students']['program_key']), 'Completed DDL survives failure without pretending rollback');
migration_expect(false, isset($pdo->columns['students']['year_level']), 'Failed DDL is not considered completed');
migration_expect(1, $pdo->lockReleases, 'Partial failure releases migration lock');
migration_expect($originalCourses, array_column($pdo->rows, 'course'), 'Partial failure preserves legacy values');
$before = count($pdo->events);
$run($pdo);
migration_expect('6', $pdo->version, 'Partial migration retries successfully');
check_academic_columns($pdo, 'Retried schema');
$retryAlters = array_values(array_filter(array_slice($pdo->events, $before), fn($sql) => str_starts_with($sql, 'ALTER TABLE `students`')));
migration_expect(2, count($retryAlters), 'Retry adds only two unfinished academic columns');
migration_expect(2, $pdo->lockReleases, 'Successful retry also releases lock');
foreach (['MODIFY COLUMN', 'REPLACE INTO schema_meta'] as $failure) {
    $pdo = new MigrationFixturePDO('4');
    $pdo->failOnce = $failure;
    migration_failure(fn() => $run($pdo), 'Injected migration failure', 'Widen/version-write failure propagates');
    migration_expect('4', $pdo->version, 'Failed widening/version-write preserves previous version');
    migration_expect(1, $pdo->lockReleases, 'Failure releases advisory lock');
    $run($pdo);
    migration_expect('6', $pdo->version, 'Widen/version-write failure safely retries');
}
$pdo = new MigrationFixturePDO('4');
$pdo->lockAvailable = false;
migration_failure(fn() => $run($pdo), 'migration lock', 'Migration lock timeout fails clearly');
migration_expect([], migration_writes($pdo), 'Migration lock timeout performs no DDL');
migration_expect('4', $pdo->version, 'Lock timeout does not advance schema');
migration_expect(0, $pdo->lockReleases, 'Unowned lock is not released');
$pdo = new MigrationFixturePDO('4');
$pdo->concurrentWinner = true;
$run($pdo);
migration_expect([], academic_alters($pdo), 'Locked recheck skips ALTER after another migrator wins');
migration_expect(1, $pdo->lockReleases, 'Concurrent-winner no-op releases acquired lock');
foreach (['`course` int DEFAULT NULL', '`course` enum(\'Old\',\'New\') DEFAULT NULL', '`course` tinytext DEFAULT NULL'] as $definition) {
    $pdo = new MigrationFixturePDO('4', $definition);
    migration_failure(fn() => $run($pdo), 'Unsupported students.course type', 'Unexpected course types require manual review');
    migration_expect('4', $pdo->version, 'Unsupported course type leaves version unchanged');
    migration_expect($definition, $pdo->course, 'Unsupported course type is never rewritten');
    migration_expect(1, $pdo->lockReleases, 'Unsupported course type failure releases lock');
}
foreach ([
    'CREATE TABLE `students` (`id` int, `course` varchar(100) DEFAULT \'unterminated)',
    'CREATE TABLE `students` (`id` int)',
    'CREATE TABLE `students` (`course` varchar(100), `course` varchar(100))',
] as $ddl) {
    $pdo = new MigrationFixturePDO('4');
    $pdo->showCreateOverride = $ddl;
    migration_failure(fn() => $run($pdo), 'students', 'Missing/malformed/ambiguous metadata fails safely');
    migration_expect('4', $pdo->version, 'Malformed metadata never advances version');
    migration_expect([], academic_alters($pdo), 'Malformed metadata does not redefine course or add fields');
}
// Reset-key compatibility is part of v4 -> v6, never a background v6 repair.
function reset_alters(MigrationFixturePDO $pdo): array
{
    return array_values(array_filter($pdo->events, fn($sql) => str_starts_with($sql, 'ALTER TABLE `password_resets`')));
}
foreach ([null, '4', '6'] as $version) {
    $pdo = new MigrationFixturePDO($version);
    $run($pdo);
    migration_expect([], reset_alters($pdo), 'Fresh/correct/v6 reset keys need no repair');
    migration_expect('auto_increment', $pdo->resetId['EXTRA'], 'Fresh/correct reset key retains auto increment');
}
foreach (['', 'STRICT_TRANS_TABLES', 'NO_AUTO_VALUE_ON_ZERO,NO_BACKSLASH_ESCAPES'] as $mode) {
    foreach (['int(11)', 'bigint(20) unsigned', 'smallint(5) unsigned zerofill'] as $type) {
        $pdo = new MigrationFixturePDO('4');
        $pdo->tables['password_resets'] = true;
        $pdo->resetId['EXTRA'] = '';
        $pdo->resetId['COLUMN_TYPE'] = $type;
        $pdo->resetId['COLUMN_COMMENT'] = "Legacy key's comment";
        $pdo->sqlMode = $mode;
        $pdo->resetRows = [
            ['id' => 0, 'user_id' => 7, 'token' => 'fixture-zero', 'expires_at' => '2026-01-02 03:04:05',
                'used' => 0, 'created_at' => '2026-01-01 03:04:05'],
            ['id' => 42, 'user_id' => 8, 'token' => 'fixture-used', 'expires_at' => '2025-01-02 03:04:05',
                'used' => 1, 'created_at' => '2025-01-01 03:04:05'],
        ];
        $pdo->resetReferences = [0, 42];
        $rows = $pdo->resetRows;
        $run($pdo);
        migration_expect('6', $pdo->version, 'Legacy reset-key migration completes');
        migration_expect('auto_increment', $pdo->resetId['EXTRA'], 'Metadata confirms repaired auto increment');
        migration_expect($type, $pdo->resetId['COLUMN_TYPE'], 'Legacy integer size and signedness remain');
        migration_expect('PRI', $pdo->resetId['COLUMN_KEY'], 'Primary key is retained');
        migration_expect(1, count(reset_alters($pdo)), 'Only one reset-key ALTER');
        migration_expect($rows, $pdo->resetRows, 'All existing reset values preserved, including zero key');
        migration_expect([0, 42], $pdo->resetReferences, 'References to reset keys preserved');
        migration_expect($mode, $pdo->sqlMode, 'Session SQL mode restored');
        migration_expect(3, count(array_filter($pdo->events, fn($sql) => str_contains($sql, "SELECT COLUMN_NAME") && str_contains($sql, "TABLE_NAME = 'password_resets'"))),
            'Reset metadata inspected again after ALTER');
        $run($pdo);
        migration_expect(1, count(reset_alters($pdo)), 'Second migration is an idempotent no-op');
    }
}
foreach (['ALTER TABLE `password_resets`', 'ADD COLUMN `academic_year`', 'REPLACE INTO schema_meta'] as $failure) {
    $pdo = new MigrationFixturePDO('4');
    $pdo->resetId['EXTRA'] = '';
    $pdo->failOnce = $failure;
    $pdo->sqlMode = 'STRICT_TRANS_TABLES';
    migration_failure(fn() => $run($pdo), 'Injected migration failure', 'Reset/partial migration failure propagates');
    migration_expect('4', $pdo->version, 'Failure never advances v6 version');
    migration_expect('STRICT_TRANS_TABLES', $pdo->sqlMode, 'SQL mode restored on failure');
    migration_expect(1, $pdo->lockReleases, 'Failure releases migration lock');
    $before = count(reset_alters($pdo));
    $run($pdo);
    migration_expect('6', $pdo->version, 'Reset/partial migration safely retries');
    migration_expect('auto_increment', $pdo->resetId['EXTRA'], 'Retry leaves a working reset key');
    migration_expect($before + ($failure === 'ALTER TABLE `password_resets`' ? 1 : 0),
        count(reset_alters($pdo)), 'Retry repairs only an unfinished reset-key step');
}
foreach ([
    ['COLUMN_TYPE' => 'varchar(11)'], ['COLUMN_KEY' => 'UNI'], ['IS_NULLABLE' => 'YES'],
    ['EXTRA' => 'VIRTUAL GENERATED'], ['COLUMN_TYPE' => 'int(11); DROP TABLE users'],
] as $invalid) {
    $pdo = new MigrationFixturePDO('4');
    $pdo->resetId = array_replace($pdo->resetId, $invalid);
    migration_failure(fn() => $run($pdo), 'Unsupported password_resets.id', 'Unsafe metadata stops migration');
    migration_expect([], reset_alters($pdo), 'Unsafe metadata is never used in ALTER');
    migration_expect('4', $pdo->version, 'Unsafe metadata prevents v6 stamp');
}
$pdo = new MigrationFixturePDO('4');
$pdo->resetPrimaryColumns = 2;
migration_failure(fn() => $run($pdo), 'Unsupported password_resets.id', 'Composite primary key requires review');
migration_expect([], reset_alters($pdo), 'Composite key is never replaced');
$pdo = new MigrationFixturePDO('4');
$pdo->resetId = [];
migration_failure(fn() => $run($pdo), 'Missing password_resets.id', 'Missing key fails closed');
$pdo = new MigrationFixturePDO('4');
$pdo->resetId['EXTRA'] = '';
$pdo->ignoreResetAlter = true;
migration_failure(fn() => $run($pdo), 'repair could not be verified', 'Post-DDL metadata verification is required');
migration_expect('4', $pdo->version, 'Unverified repair never stamps v6');
migration_expect('', $pdo->sqlMode, 'Unverified repair restores SQL mode');
$pdo = new MigrationFixturePDO('6');
$pdo->resetId['EXTRA'] = '';
$run($pdo);
migration_expect([], migration_writes($pdo), 'Already-stamped v6 remains read-only even for legacy reset key');
migration_expect('', $pdo->resetId['EXTRA'], 'Existing migrated test DB is not silently repaired');

$cli = file_get_contents(dirname(__DIR__) . '/tools/migrate-academic.php');
$guard = strpos($cli, "$" . "argc !== 2");
$configRequire = strpos($cli, "require dirname(__DIR__) . '/config.php'");
migration_expect(true, $guard !== false && $configRequire !== false && $guard < $configRequire, 'CLI apply guard precedes application bootstrap');
migration_expect(true, str_contains($cli, "getenv('PRISM_ALLOW_SCHEMA_V6_MIGRATION') !== '1'"), 'CLI requires explicit environment opt-in');
migration_expect(true, str_contains($cli, "$" . "argv[1] !== '--apply'"), 'CLI requires explicit --apply option');
migration_expect(true, str_contains($cli, "if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }"), 'CLI rejects direct HTTP execution before bootstrap');
echo 'PASS: ' . $checks . " isolated academic migration checks. No live database or application bootstrap was used.\n";
