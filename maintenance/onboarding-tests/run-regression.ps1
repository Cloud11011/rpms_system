param([Parameter(Mandatory)][string]$MariaDbBin,[string]$ReviewName='PRISM_ONBOARDING_REGRESSION_FINAL',[int]$DbPort=13877,[int]$AppPort=14877)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'local-env.ps1') -ReviewName $ReviewName -DbPort $DbPort -AppPort $AppPort
$env:PRISM_TEST_MARIADB_BIN=$MariaDbBin
foreach($name in @('PRISM_TEST_SCHEMA_VERSION','PRISM_TEST_PHASE','PRISM_TEST_ONBOARDING','PRISM_TEST_ONBOARDING_UI_ONLY')){Remove-Item -LiteralPath ('Env:'+ $name) -ErrorAction SilentlyContinue}
& (Join-Path $PSScriptRoot '../ierb-group-tests/setup.ps1')
if($LASTEXITCODE -ne 0){throw 'Fixture setup failed.'}
try {
    foreach($phase in @('first','extended','last')){
        node (Join-Path $PSScriptRoot 'regression.cjs') $phase
        if($LASTEXITCODE -ne 0){throw ('Regression failed in '+$phase+'. Retain and inspect the evidence.')}
    }
} finally { & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-group-tests/shutdown.php') }
