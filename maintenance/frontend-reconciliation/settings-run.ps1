param([string]$ReviewName='tests/settings-run',[int]$DbPort=13987,[int]$AppPort=14987,
    [ValidateSet('settings-first','onboarding-first','rollback','sql','manifest','runner','worker15','worker16')][string]$Scenario='settings-first',
    [string]$Collation='')
# Fresh disposable schema15 fixture, one Settings/schema16 or notification-worker scenario, then shutdown. Existing datadirs are refused.
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../onboarding-tests/local-env.ps1') -ReviewName $ReviewName -DbPort $DbPort -AppPort $AppPort
if ($Collation) { $env:PRISM_TEST_DB_COLLATION=$Collation }
& (Join-Path $PSScriptRoot '../ierb-group-tests/setup.ps1')
if($LASTEXITCODE -ne 0){throw 'Fresh synthetic fixture setup failed.'}
try{
    foreach($folder in @('sandbox/app/storage/documents','sandbox/app/storage/reports')){
        New-Item -ItemType Directory -Path (Join-Path $env:PRISM_TEST_REVIEW_DIR $folder) -Force | Out-Null
    }
    # Windows PowerShell prefixes piped native stdin with a BOM; feed BOM-free files through cmd redirection instead.
    $stdin=Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox'
    [IO.File]::WriteAllText((Join-Path $stdin 'prepare.json'),'{"action":"prepare"}')
    [IO.File]::WriteAllText((Join-Path $stdin 'scenario.json'),('{"scenario":"'+$Scenario+'"}'))
    cmd /s /c ('""'+$env:PRISM_TEST_PHP+'" "'+(Join-Path $PSScriptRoot 'v14-fixture.php')+'" < "'+(Join-Path $stdin 'prepare.json')+'""') | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Synthetic V14 setup failed.'}
    # worker* scenarios exercise the notification cron worker with a simulated provider.
    $tests=if($Scenario -like 'worker*'){'notification-worker-tests.php'}else{'settings-tests.php'}
    $out=Join-Path $env:PRISM_TEST_REVIEW_DIR ($(if($Scenario -like 'worker*'){'worker-results.json'}else{'settings-results.json'}))
    cmd /s /c ('""'+$env:PRISM_TEST_PHP+'" "'+(Join-Path $PSScriptRoot $tests)+'" < "'+(Join-Path $stdin 'scenario.json')+'" > "'+$out+'""')
    $status=$LASTEXITCODE
    Get-Content -LiteralPath $out -Encoding UTF8
    if($status -ne 0){throw 'Settings scenario failed; preserve and inspect the evidence.'}
}finally{
    & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-group-tests/shutdown.php')
}
