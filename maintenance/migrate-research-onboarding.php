<?php
/** Review the proposed extension before explicitly applying it to any selected database. */
require_once __DIR__.'/../includes/migration_runner.php';
require_once __DIR__.'/../includes/schema_research_onboarding.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
prism_migration_run('migrate_research_onboarding', $argv[1] ?? 'dry-run');
