<?php
/** Explicit DSN only; never loads deployed application configuration. Diagnostics never include credentials. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/schema_v15.php';
require_once dirname(__DIR__).'/includes/migration_runner.php';
prism_migration_run('migrate_schema_v15', $argv[1] ?? 'dry-run');
