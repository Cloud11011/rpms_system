param([Parameter(Mandatory)][string]$MariaDbBin,[string]$ReviewName='PRISM_ONBOARDING_FINAL_EVIDENCE',[int]$DbPort=13867,[int]$AppPort=14867)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'local-env.ps1') -ReviewName $ReviewName -DbPort $DbPort -AppPort $AppPort
$env:PRISM_TEST_MARIADB_BIN=$MariaDbBin
& (Join-Path $PSScriptRoot '../ierb-group-tests/setup.ps1')
if($LASTEXITCODE -ne 0){throw 'Fixture setup failed.'}
try {
    & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-v14-tests/cases.php') apply-for-regression
    if($LASTEXITCODE -ne 0){throw 'Synthetic foundation migration failed.'}
    $installed='{"action":"install"}' | & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-monitor-tests/fixture.php') | ConvertFrom-Json
    if(!$installed.ok){throw 'Synthetic schema 15 installation failed.'}
    $env:PRISM_TEST_SCHEMA_VERSION='15';$env:PRISM_TEST_PHASE='documents';$env:PRISM_TEST_ONBOARDING='1'
    Remove-Item Env:PRISM_TEST_ONBOARDING_UI_ONLY -ErrorAction SilentlyContinue
    node (Join-Path $PSScriptRoot '../ierb-group-tests/run.cjs')
    if($LASTEXITCODE -ne 0){throw 'Focused tests failed. Retain and inspect the evidence.'}
} finally {
    $flag=Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox/v14-operations.flag'
    if(Test-Path -LiteralPath $flag){Remove-Item -LiteralPath $flag}
    & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-group-tests/shutdown.php')
}
