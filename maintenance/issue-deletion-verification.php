<?php
/** Operator CLI (cleanup blocker B4): issue PRISM_HARD_DELETE_VERIFICATION evidence for exactly one database.
 * Never reachable over HTTP and never loads config.php. It calls only the reviewed issuers in
 * includes/account_lifecycle_schema.php, then validates the result with the application's own validator.
 * The operator copies the evidence into the protected config.local.php next to the SAME key:
 *   define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', true); define('PRISM_HARD_DELETE_VERIFICATION', [...]);
 *
 *   PRISM_MIGRATION_DB_DSN='mysql:host=...;dbname=...;charset=utf8mb4' PRISM_MIGRATION_DB_USER=... PRISM_MIGRATION_DB_PASSWORD=... \
 *   PRISM_SCHEMA_VERIFY_KEY=<the 64-hex PRISM_HARD_DELETE_VERIFICATION_KEY of that deployment> \
 *     php maintenance/issue-deletion-verification.php --expect-db=<database> --mode=shared-hosting \
 *       --external-dependencies-excluded --out=<new file in a private directory (chmod 700) outside the deployed directory>
 *
 * The evidence is written only to --out: created exclusively, mode 0600 before any content, never through a link and
 * never over an existing file. Stdout carries a host-free summary only; the evidence and the key are never printed.
 * Re-issue after any schema change (the evidence binds the manifest and contract files) and for each database
 * (it binds DATABASE(), @@hostname, @@port, @@server_id and VERSION()).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/migration_runner.php';
$o = getopt('', ['expect-db:', 'mode:', 'external-dependencies-excluded', 'out:']);
$key = getenv('PRISM_SCHEMA_VERIFY_KEY');
$refuse = static function (string $message): never { fwrite(STDERR, json_encode(['ok' => false, 'errorClass' => 'invalid_invocation', 'message' => $message]).PHP_EOL); exit(1); };
if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key)) $refuse('PRISM_SCHEMA_VERIFY_KEY must be the deployment\'s 64-character lowercase hex key.');
if (!isset($o['expect-db']) || !preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', (string)$o['expect-db'])) $refuse('--expect-db=<database name> is required.');
$mode = $o['mode'] ?? '';
if (!in_array($mode, ['shared-hosting', 'privileged'], true)) $refuse('--mode must be shared-hosting or privileged.');
if ($mode === 'shared-hosting' && !isset($o['external-dependencies-excluded'])) {
    $refuse('Shared-hosting evidence needs the operator\'s attestation --external-dependencies-excluded (no other schema references PRISM tables).');
}
$out = $o['out'] ?? null;
if (!is_string($out) || $out === '') $refuse('--out=<new file in a private directory outside the deployed application> is required; evidence is never printed.');
// Refuse an unsafe destination before touching the database.
try { prism_private_target($out); } catch (PrismMigrationInputError $e) { $refuse($e->getMessage()); }
// The reviewed issuer and validator read this constant; it is defined only in this process.
define('PRISM_HARD_DELETE_VERIFICATION_KEY', $key);
require_once dirname(__DIR__).'/includes/account_identity.php';
require_once dirname(__DIR__).'/includes/account_lifecycle_schema.php';
$locked = false; $code = 1; $pdo = null;
try {
    $pdo = prism_migration_connect();
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$o['expect-db']) throw new PrismMigrationInputError('Connected database does not match --expect-db.');
    // Same mutex as migrations and permanent deletion: never issue evidence while the schema may be changing.
    $locked = (int)$pdo->query("SELECT GET_LOCK('prism_migrate',0)")->fetchColumn() === 1;
    if (!$locked) throw new PrismMigrationInputError('Schema maintenance or a permanent deletion is in progress; retry later.');
    $evidence = $mode === 'privileged' ? lifecycle_privileged_verification($pdo) : lifecycle_shared_hosting_verification($pdo, true);
    // Self-check with the application's own validator before anything is handed to the operator.
    define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', true);
    define('PRISM_HARD_DELETE_VERIFICATION', $evidence);
    lifecycle_validate_verification($pdo);
    // The evidence names the server host; it goes only to the private file.
    $file = prism_private_write($out, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['ok' => true, 'database' => (string)$o['expect-db'], 'schemaVersion' => (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),
        'mode' => $evidence['verification_mode'], 'issuedAt' => date(DATE_ATOM, $evidence['issued_at']), 'selfCheck' => 'PASS', 'evidenceFile' => basename($file),
        'next' => 'Place the evidence in config.local.php with PRISM_HARD_DELETE_SCHEMA_VERIFIED = true, beside the same key; re-issue after any schema change.'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    $code = 0;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(prism_migration_diagnostic($e), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}
if ($locked) $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
exit($code);
