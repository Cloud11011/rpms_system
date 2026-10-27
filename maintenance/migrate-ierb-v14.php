<?php
/** No config/bootstrap: this operator runner never contacts a configured production database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/schema_v12.php';
try {
    $dsn = getenv('PRISM_MIGRATION_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) throw new RuntimeException('An explicit MySQL migration DSN is required.');
    $pdo = new PDO($dsn, getenv('PRISM_MIGRATION_DB_USER') ?: '', getenv('PRISM_MIGRATION_DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    echo json_encode(migrate_schema_v12($pdo, $argv[1] ?? 'dry-run'), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    // Avoid printing connection exceptions, DSNs or database credentials.
    fwrite(STDERR, 'IERB foundation migration stopped. Verify opt-in, exact database binding, canonical schema and empty rollback preconditions.'.PHP_EOL);
    exit(1);
}
