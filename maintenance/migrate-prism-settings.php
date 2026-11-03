<?php
/** Explicit DSN only; never loads deployed application configuration. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/admin_settings_schema.php';
require_once dirname(__DIR__).'/includes/migration_runner.php';
prism_migration_run('migrate_prism_admin_settings', $argv[1] ?? 'dry-run');
