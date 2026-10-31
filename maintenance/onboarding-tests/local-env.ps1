# Local synthetic fixture defaults only. No deployed configuration is loaded.
param([string]$ReviewName='PRISM_ONBOARDING_LOCAL_EVIDENCE',[int]$DbPort=13847,[int]$AppPort=14847)
$env:PRISM_TEST_REVIEW_DIR=Join-Path (Resolve-Path (Join-Path $PSScriptRoot '../..')) $ReviewName
$env:PRISM_TEST_DB_PORT=[string]$DbPort
$env:PRISM_TEST_APP_PORT=[string]$AppPort
$env:PRISM_TEST_DB_COLLATION='utf8mb4_general_ci'
if (-not $env:PRISM_TEST_PHP) { $env:PRISM_TEST_PHP='C:/xampp/php/php.exe' }
if (-not $env:PRISM_TEST_PLAYWRIGHT) { $env:PRISM_TEST_PLAYWRIGHT='C:/PRISM_Workspaces/prism-v13-release-clean/PRISM_V13_UI_REMEDIATION_AUDIT/browser-tools/node_modules/playwright-core' }
if (-not $env:PRISM_TEST_CHROME) { $env:PRISM_TEST_CHROME='C:/Program Files/Google/Chrome/Application/chrome.exe' }
