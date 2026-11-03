param([string]$ReviewName='tests/frontend-reconciliation/v14-current-runtime',[int]$DbPort=13986,[int]$AppPort=14986,[ValidateSet('15','16')][string]$Schema='15',[switch]$Visual,[switch]$FormsUiOnly)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../onboarding-tests/local-env.ps1') -ReviewName $ReviewName -DbPort $DbPort -AppPort $AppPort
$env:PRISM_TEST_PHASE='documents'
$env:PRISM_TEST_SCHEMA_VERSION=$Schema
$env:PRISM_TEST_V14_ACTIVE_ONLY='1'
# -Visual adds side-by-side ZIP captures after the business flow (visual-parity.cjs; compare with visual-diff.py).
if($Visual){$env:PRISM_TEST_VISUAL_PARITY='1'}else{Remove-Item Env:PRISM_TEST_VISUAL_PARITY -ErrorAction SilentlyContinue}
$env:PRISM_TEST_EVIDENCE_DIR=Join-Path $env:PRISM_TEST_REVIEW_DIR 'evidence'
foreach($name in @('PRISM_TEST_V14_DOCUMENTS','PRISM_TEST_PROJECT_GROUP','PRISM_TEST_BATCH4','PRISM_TEST_BATCH5','PRISM_TEST_BATCH6','PRISM_TEST_BATCH7R','PRISM_TEST_CLOSURE','PRISM_TEST_ONBOARDING','PRISM_TEST_FRONTEND_COMPATIBILITY','PRISM_TEST_FRONTEND_DASHBOARD')){
    Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue
}
# -FormsUiOnly runs only the retargeted Batch 6 forms/role/responsive module (ierb-ui-tests/integration.cjs).
if($FormsUiOnly){$env:PRISM_TEST_BATCH6='1';Remove-Item Env:PRISM_TEST_V14_ACTIVE_ONLY -ErrorAction SilentlyContinue}
& (Join-Path $PSScriptRoot '../ierb-group-tests/setup.ps1')
if($LASTEXITCODE -ne 0){throw 'Fresh synthetic fixture setup failed.'}
try{
    foreach($folder in @('sandbox/app/storage/documents','sandbox/app/storage/reports')){
        New-Item -ItemType Directory -Path (Join-Path $env:PRISM_TEST_REVIEW_DIR $folder) -Force | Out-Null
    }
    # Windows PowerShell prefixes piped native stdin with a BOM; feed a BOM-free file through cmd redirection instead.
    $prepare=Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox/prepare.json'
    [IO.File]::WriteAllText($prepare,'{"action":"prepare"}')
    cmd /s /c ('""'+$env:PRISM_TEST_PHP+'" "'+(Join-Path $PSScriptRoot 'v14-fixture.php')+'" < "'+$prepare+'""')
    if($LASTEXITCODE -ne 0){throw 'Synthetic V14 setup failed.'}
    if($Schema -eq '16'){
        $upgrade=Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox/schema16.json'
        [IO.File]::WriteAllText($upgrade,'{"action":"schema16"}')
        cmd /s /c ('""'+$env:PRISM_TEST_PHP+'" "'+(Join-Path $PSScriptRoot 'v14-fixture.php')+'" < "'+$upgrade+'""')
        if($LASTEXITCODE -ne 0){throw 'Synthetic schema 16 upgrade failed.'}
    }
    node (Join-Path $PSScriptRoot '../ierb-group-tests/run.cjs')
    if($LASTEXITCODE -ne 0){throw 'Current V14 regression failed; preserve and inspect the evidence.'}
}finally{
    & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-group-tests/shutdown.php')
}
