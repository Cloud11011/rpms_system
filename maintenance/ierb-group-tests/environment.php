<?php
/** TEST FIXTURE ONLY. One place for the disposable fixture's loopback port, legacy collation and output folder.
 * Defaults reproduce the original MariaDB 10.4 / utf8mb4_unicode_ci fixture; variants (for example the
 * production-like MariaDB 12.3.2 / utf8mb4_general_ci base) are selected with PRISM_TEST_* variables.
 */
if (PHP_SAPI !== 'cli' && !defined('PRISM_TEST_RUNTIME')) exit;
if (!defined('PRISM_TEST_DB_PORT')) {
    $port = getenv('PRISM_TEST_DB_PORT') ?: '13527';
    $appPort = getenv('PRISM_TEST_APP_PORT') ?: '14527';
    if (!ctype_digit($port) || !ctype_digit($appPort)) throw new RuntimeException('Invalid fixture port.');
    $collation = getenv('PRISM_TEST_DB_COLLATION') ?: 'utf8mb4_unicode_ci';
    if (!in_array($collation, ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_uca1400_ai_ci'], true)) throw new RuntimeException('Unsupported fixture collation.');
    define('PRISM_TEST_DB_PORT', (int)$port);
    define('PRISM_TEST_APP_PORT', (int)$appPort);
    define('PRISM_TEST_DB_COLLATION', $collation);
    define('PRISM_TEST_REVIEW_DIR', str_replace('\\', '/', getenv('PRISM_TEST_REVIEW_DIR') ?: dirname(__DIR__, 2).'/PRISM_V13_GROUP_ADVANCEMENT_REVIEW'));
}
