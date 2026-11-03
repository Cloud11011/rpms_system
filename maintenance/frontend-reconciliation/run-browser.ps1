param([Parameter(Mandatory=$true)][string]$ReviewName,[Parameter(Mandatory=$true)][int]$DbPort,[Parameter(Mandatory=$true)][int]$AppPort)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../onboarding-tests/local-env.ps1') -ReviewName $ReviewName -DbPort $DbPort -AppPort $AppPort
$workspace=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$fixture=(Resolve-Path (Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox/mysql')).Path
if (!$fixture.StartsWith($workspace+'\tests\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Only a retained disposable workspace fixture may be resumed.' }
if (!(Test-Path -LiteralPath (Join-Path $env:PRISM_TEST_REVIEW_DIR 'isolated-schema-verification.json'))) { throw 'Prior isolated schema verification is required.' }
$env:PRISM_TEST_PHASE='documents'
$env:PRISM_TEST_SCHEMA_VERSION='15'
$env:PRISM_TEST_V14_ACTIVE_ONLY='1'
$env:PRISM_TEST_V14_BROWSER_ONLY='1'
$env:PRISM_TEST_EVIDENCE_DIR=Join-Path $env:PRISM_TEST_REVIEW_DIR 'browser-evidence'
$server=Join-Path $env:PRISM_TEST_MARIADB_BIN 'mariadbd.exe'
if (!(Test-Path -LiteralPath $server)) { $server=Join-Path $env:PRISM_TEST_MARIADB_BIN 'mysqld.exe' }
$process=Start-Process -FilePath $server -WorkingDirectory $fixture -WindowStyle Hidden -PassThru -ArgumentList @('--no-defaults',"--datadir=$fixture","--innodb-data-home-dir=$fixture","--innodb-log-group-home-dir=$fixture",'--skip-innodb-use-native-aio','--innodb-buffer-pool-size=64M',"--port=$DbPort",'--bind-address=127.0.0.1',"--log-error=$fixture/server.err")
$process.Id | Set-Content -LiteralPath (Join-Path $env:PRISM_TEST_REVIEW_DIR 'sandbox/mysql-process-id.txt')
try {
    node (Join-Path $PSScriptRoot '../ierb-group-tests/run.cjs')
    if ($LASTEXITCODE -ne 0) { throw 'Current V14 browser regression failed; inspect retained evidence.' }
} finally {
    & $env:PRISM_TEST_PHP (Join-Path $PSScriptRoot '../ierb-group-tests/shutdown.php')
}
