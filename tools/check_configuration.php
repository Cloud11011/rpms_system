<?php
/** CLI deployment configuration check. Does not connect to DB, mail or AI. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config.php';

$issues = application_configuration_issues();
if (!is_writable(STORAGE_DIR)) $issues[] = 'Application storage must be writable for authentication throttling.';
if (in_array('--production', $argv, true) && APP_ENV !== 'production') {
    $issues[] = 'Production deployment requires APP_ENV=production.';
}
foreach (['pdo_mysql', 'mbstring', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) $issues[] = 'Required PHP extension missing: ' . $extension . '.';
}
if ($issues) {
    foreach ($issues as $issue) fwrite(STDERR, 'FAIL: ' . $issue . "\n");
    exit(1);
}
echo "PASS: Configuration format and required PHP extensions. No secret values were printed.\n";
echo "Database transactions, email delivery, storage permissions and AI still require integration tests.\n";
