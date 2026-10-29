<?php
/** Shared CLI runner for schema migrations 12-15. Never loads config.php or config.local.php.
 * Diagnostics name a stable error class plus SQLSTATE/driver errno; DSNs and credentials are never printed.
 */
function prism_migration_connect(): PDO
{
    $dsn = getenv('PRISM_MIGRATION_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) throw new PrismMigrationInputError('An explicit MySQL migration DSN is required (PRISM_MIGRATION_DB_DSN).');
    return prism_migration_pdo($dsn, getenv('PRISM_MIGRATION_DB_USER') ?: '', getenv('PRISM_MIGRATION_DB_PASSWORD') ?: '', getenv('PRISM_MIGRATION_DB_SSL_CA') ?: null);
}

/** The one connection policy for the migration runners, the cron probe and the runtime preflight.
 * Remote MySQL: TLS with server-certificate verification. Verification is never disabled, and a session that
 * did not negotiate TLS although a CA was configured is refused. */
function prism_migration_pdo(string $dsn, string $user, string $password, ?string $ca, ?array $options = null): PDO
{
    if (!str_starts_with($dsn, 'mysql:')) throw new PrismMigrationInputError('An explicit MySQL DSN is required.');
    $options ??= [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($ca !== null && $ca !== '') {
        if (!is_file($ca) || !is_readable($ca)) throw new PrismMigrationInputError('The TLS CA setting (PRISM_MIGRATION_DB_SSL_CA or sslCa) must name a readable CA certificate file.');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    try { $pdo = new PDO($dsn, $user, $password, $options); }
    catch (PDOException $e) {
        // Connection messages can contain the user and host; report only the codes.
        throw new PrismMigrationConnectionError('connection_failed', 0, $e);
    }
    if (isset($options[PDO::MYSQL_ATTR_SSL_CA]) && prism_migration_tls_cipher($pdo) === null) throw new PrismMigrationConnectionError('tls_not_negotiated');
    return $pdo;
}

function prism_migration_tls_cipher(PDO $pdo): ?string
{
    $row = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
    return is_array($row) && (string)($row[1] ?? '') !== '' ? (string)$row[1] : null;
}

function prism_migration_diagnostic(Throwable $e): array
{
    $pdo = $e instanceof PDOException ? $e : ($e->getPrevious() instanceof PDOException ? $e->getPrevious() : null);
    $class = match (true) {
        $e instanceof PrismMigrationConnectionError => $e->getMessage() === 'tls_not_negotiated' ? 'tls_not_negotiated' : 'connection_failed',
        $e instanceof PrismMigrationInputError => 'invalid_invocation',
        $e instanceof SchemaCollationError => 'unsupported_legacy_collation',
        $e instanceof PDOException => 'database_error',
        default => 'precondition_refused',
    };
    $result = ['ok' => false, 'errorClass' => $class];
    if ($pdo) { $result['sqlstate'] = (string)($pdo->errorInfo[0] ?? $pdo->getCode()); $result['driverErrno'] = isset($pdo->errorInfo[1]) ? (int)$pdo->errorInfo[1] : null; }
    // Reviewed library messages describe contracts and preconditions, never secrets. Driver text is
    // reported only after connection (for example errno 150), and the DSN is never echoed.
    if (!$e instanceof PrismMigrationConnectionError) $result['message'] = $e instanceof PDOException ? preg_replace('/\s+/', ' ', $e->getMessage()) : $e->getMessage();
    return $result;
}

function prism_migration_run(callable $migrate, string $mode): never
{
    try {
        echo json_encode($migrate(prism_migration_connect(), $mode), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, json_encode(prism_migration_diagnostic($e), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
}

/** Comparable form of a path: forward slashes, one trailing slash, case-folded on Windows. */
function prism_path_key(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/').'/';
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

/** Private files never live inside the deployed application or inside a public_html web root: Hostinger serves both,
 * and its web server runs as the account, so mode 0600 alone does not keep a file there from being served. */
function prism_private_location(string $real, string $what): void
{
    $app = realpath(dirname(__DIR__));
    if ($app !== false && str_starts_with(prism_path_key($real), prism_path_key($app))) throw new PrismMigrationInputError('Keep '.$what.' outside the deployed application directory.');
    if (str_contains(prism_path_key($real), '/public_html/')) throw new PrismMigrationInputError('Keep '.$what.' outside public_html.');
}

/** POSIX: true when the stat entry belongs to this process's account. Fails closed: without the posix extension the
 * owner cannot be verified, so callers refuse. */
function prism_posix_owned(array $stat): bool
{
    return function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid();
}

/** Private operator output (deletion-verification evidence, cron receipts) lives in an existing directory that is
 * not a symbolic link, lies outside the deployed application and public_html and, on POSIX hosts, belongs to this
 * account with no group or other access (chmod 700). Returns the directory's canonical path. */
function prism_private_directory(string $dir): string
{
    $dir = rtrim($dir, '/\\');
    if ($dir === '' || !is_dir($dir)) throw new PrismMigrationInputError('The private output directory must exist.');
    if (is_link($dir)) throw new PrismMigrationInputError('The private output directory must not be a symbolic link.');
    $real = realpath($dir);
    if ($real === false || !is_writable($real)) throw new PrismMigrationInputError('The private output directory must be writable.');
    // A link anywhere in the path is refused (Windows PHP does not report junctions as links): the path must be canonical.
    if (prism_path_key($real) !== prism_path_key($dir)) throw new PrismMigrationInputError('Give the private output directory as its canonical absolute path, without links or relative parts.');
    prism_private_location($real, 'private output');
    if (DIRECTORY_SEPARATOR === '/') {
        clearstatcache(true, $real);
        $stat = stat($real);
        if ($stat === false || ($stat['mode'] & 0077) !== 0) throw new PrismMigrationInputError('The private output directory must not be accessible to group or others (chmod 700).');
        if (!prism_posix_owned($stat)) throw new PrismMigrationInputError('The private output directory must belong to this account (verified with the PHP posix extension).');
    }
    return $real;
}

/** The private cron configuration holds the database password. It is read only after every check below passes, and
 * each fails closed: a regular file named by its canonical absolute path (no link anywhere in the path), outside the
 * deployed application and public_html, in a folder that belongs to this account (or root) and that group and others
 * cannot write to; on POSIX hosts the file belongs to this account and grants group and others nothing (chmod 600 or
 * 400). The opened file is re-checked to be the file inspected. Windows has no POSIX owner or mode bits and NTFS ACLs
 * are not inspected, so there only the path rules apply and the caller is told the owner and mode were not verified.
 * Permissions are never changed here: a loose mode may already have exposed the password, so the operator corrects
 * it and decides whether to rotate the password. Returns [file contents, whether the POSIX owner and mode were verified]. */
function prism_private_config(string $path): array
{
    $posix = DIRECTORY_SEPARATOR === '/';
    $unsafe = static function (string $message): never { throw new PrismMigrationInputError($message); };
    $private = static function (array|false $stat) use ($unsafe): void {
        if ($stat === false) $unsafe('The private configuration file could not be inspected.');
        if (($stat['mode'] & 0077) !== 0) $unsafe('The private configuration file must not be accessible to group or others (chmod 600).');
        if (!prism_posix_owned($stat)) $unsafe('The private configuration file must belong to this account (verified with the PHP posix extension).');
    };
    if (is_link($path)) $unsafe('The private configuration file must not be a symbolic link.');
    $real = realpath($path);
    if ($real === false || !is_file($real)) $unsafe('The private configuration file must be a regular file.');
    if (prism_path_key($real) !== prism_path_key($path)) $unsafe('Give the private configuration file as its canonical absolute path, without links or relative parts.');
    prism_private_location($real, 'the private configuration file');
    if ($posix) {
        clearstatcache(true, $real);
        $folder = stat(dirname($real));
        if ($folder === false || ($folder['mode'] & 0022) !== 0) $unsafe('The folder holding the private configuration file must not be writable by group or others.');
        if (!prism_posix_owned($folder) && $folder['uid'] !== 0) $unsafe('The folder holding the private configuration file must belong to this account or root (verified with the PHP posix extension).');
        $private(lstat($real));
    }
    $handle = @fopen($real, 'rb');
    if ($handle === false) $unsafe('The private configuration file must be readable by this account.');
    try {
        clearstatcache(true, $real);
        $opened = fstat($handle); $named = lstat($real);
        if ($opened === false || $named === false || is_link($real) || ($opened['mode'] & 0170000) !== 0100000) $unsafe('The private configuration file changed while it was checked.');
        if ($posix) {
            if ($opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) $unsafe('The private configuration file changed while it was checked.');
            $private($opened);
        }
        $contents = stream_get_contents($handle);
    } finally {
        fclose($handle);
    }
    if (!is_string($contents)) $unsafe('The private configuration file could not be read.');
    return [$contents, $posix];
}

/** Validates a new private output file before any work is done. Returns [canonical directory, file name]. */
function prism_private_target(string $path): array
{
    $name = basename(str_replace('\\', '/', $path));
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $name)) throw new PrismMigrationInputError('Private output file names use only letters, digits, dot, underscore and hyphen.');
    $dir = prism_private_directory(dirname($path));
    $file = $dir.DIRECTORY_SEPARATOR.$name;
    if (file_exists($file) || is_link($file)) throw new PrismMigrationInputError('The private output file already exists; evidence and receipts are never overwritten.');
    return [$dir, $name];
}

/** Creates the file exclusively (never overwrites, never follows a planted link) with mode 0600 before any content
 * is written. A partial file is removed. Returns the file's path. */
function prism_private_write(string $path, string $contents): string
{
    [$dir, $name] = prism_private_target($path);
    $file = $dir.DIRECTORY_SEPARATOR.$name;
    $mask = umask(0077);
    try { $handle = @fopen($file, 'xb'); } finally { umask($mask); }
    if ($handle === false) throw new PrismMigrationInputError('The private output file already exists or could not be created; nothing was overwritten.');
    try {
        // POSIX O_EXCL never follows a link, but Windows PHP follows a dangling one: re-check before any content.
        clearstatcache(true, $file);
        $named = lstat($file);
        if ($named === false || is_link($file)) throw new RuntimeException('The private output file changed while it was created.');
        if (DIRECTORY_SEPARATOR === '/') {
            $opened = fstat($handle);
            if ($opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) throw new RuntimeException('The private output file changed while it was created.');
            if (!chmod($file, 0600) || (fstat($handle)['mode'] & 0777) !== 0600) throw new RuntimeException('The private output file could not be made private.');
        }
        if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) throw new RuntimeException('The private output file could not be written.');
    } catch (Throwable $e) {
        fclose($handle); @unlink($file);
        throw $e;
    }
    fclose($handle);
    return $file;
}

final class PrismMigrationInputError extends RuntimeException {}
final class PrismMigrationConnectionError extends RuntimeException {}
