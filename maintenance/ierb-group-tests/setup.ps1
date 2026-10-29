$ErrorActionPreference = 'Stop'
# Defaults reproduce the original MariaDB 10.4 / utf8mb4_unicode_ci fixture. Variants (B7-F11):
#   $env:PRISM_TEST_MARIADB_BIN = '<mariadb-12.3.2>\bin'; $env:PRISM_TEST_DB_PORT = '13627'
#   $env:PRISM_TEST_DB_COLLATION = 'utf8mb4_general_ci'; $env:PRISM_TEST_REVIEW_DIR = '<separate output folder>'
$review = if ($env:PRISM_TEST_REVIEW_DIR) { $env:PRISM_TEST_REVIEW_DIR } else { Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..\..')) 'PRISM_V13_GROUP_ADVANCEMENT_REVIEW' }
$port = if ($env:PRISM_TEST_DB_PORT) { [int]$env:PRISM_TEST_DB_PORT } else { 13527 }
$bin = if ($env:PRISM_TEST_MARIADB_BIN) { $env:PRISM_TEST_MARIADB_BIN } else { 'C:\xampp\mysql\bin' }
$php = if ($env:PRISM_TEST_PHP) { $env:PRISM_TEST_PHP } else { 'C:\xampp\php\php.exe' }
$install = if (Test-Path (Join-Path $bin 'mariadb-install-db.exe')) { Join-Path $bin 'mariadb-install-db.exe' } else { Join-Path $bin 'mysql_install_db.exe' }
$server = if (Test-Path (Join-Path $bin 'mariadbd.exe')) { Join-Path $bin 'mariadbd.exe' } else { Join-Path $bin 'mysqld.exe' }
$fixtureData = Join-Path $review 'sandbox\mysql'
if (Test-Path -LiteralPath $fixtureData) { throw 'The disposable fixture already exists. Refusing to overwrite it.' }
New-Item -ItemType Directory -Path $fixtureData -Force | Out-Null
& $install "--datadir=$fixtureData" --password=prism_synthetic_only "--port=$port"
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture initialization failed.' }
$fixtureProcess = Start-Process -FilePath $server -WorkingDirectory $fixtureData -WindowStyle Hidden -PassThru -ArgumentList @(
    '--no-defaults', "--datadir=$fixtureData", "--innodb-data-home-dir=$fixtureData", "--innodb-log-group-home-dir=$fixtureData",
    '--skip-innodb-use-native-aio', '--skip-innodb-buffer-pool-load-at-startup', '--innodb-buffer-pool-size=64M',
    "--port=$port", '--bind-address=127.0.0.1', "--log-error=$fixtureData\server.err"
)
$fixtureProcess.Id | Set-Content -LiteralPath (Join-Path $review 'sandbox\mysql-process-id.txt')
for ($attempt = 0; $attempt -lt 50; $attempt++) {
    $probe = New-Object System.Net.Sockets.TcpClient
    try { $probe.Connect('127.0.0.1',$port); break }
    catch { if ($attempt -eq 49) { throw 'Disposable fixture did not become ready.' }; Start-Sleep -Milliseconds 100 }
    finally { $probe.Dispose() }
}
& $php (Join-Path $PSScriptRoot 'initialize.php')
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture schema creation failed.' }
& $php (Join-Path $PSScriptRoot 'seed.php')
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture policy seed failed.' }
