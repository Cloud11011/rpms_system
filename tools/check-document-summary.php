<?php
/** Run with the same PHP configuration as the hosting web runtime; never loads secrets/DB. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checks = ['PHP 8.1 or later' => version_compare(PHP_VERSION, '8.1', '>=')];
foreach (['zlib','iconv','mbstring','xml','dom','zip','curl'] as $extension) $checks['Extension ' . $extension] = extension_loaded($extension);
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$checks['Composer autoload'] = is_file($autoload);
if ($checks['Composer autoload']) {
    require $autoload;
    $checks['Locked smalot/pdfparser 2.12.5'] = class_exists(\Composer\InstalledVersions::class)
        && \Composer\InstalledVersions::isInstalled('smalot/pdfparser')
        && ltrim((string)\Composer\InstalledVersions::getPrettyVersion('smalot/pdfparser'), 'v') === '2.12.5';
}
$memory = ini_get('memory_limit');
$bytes = (int)$memory * (str_ends_with(strtolower($memory), 'g') ? 1073741824 : (str_ends_with(strtolower($memory), 'm') ? 1048576 : (str_ends_with(strtolower($memory), 'k') ? 1024 : 1)));
$checks['At least 128 MB available configuration (256 MB recommended)'] = $bytes === -1 || $bytes >= 128 * 1024 * 1024;
$time = (int)ini_get('max_execution_time');
$checks['Request time configuration at least 60 seconds'] = $time === 0 || $time >= 60;
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
echo "CLI checks do not establish web-runtime configuration or HTTP vendor protection. Verify those on hosting separately.\n";
exit(in_array(false, $checks, true) ? 1 : 0);
