$ErrorActionPreference = 'Stop'
$review = Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..\..')) 'PRISM_V13_GROUP_ADVANCEMENT_REVIEW'
$fixtureData = Join-Path $review 'sandbox\mysql'
if (Test-Path -LiteralPath $fixtureData) { throw 'The disposable fixture already exists. Refusing to overwrite it.' }
New-Item -ItemType Directory -Path $fixtureData -Force | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' "--datadir=$fixtureData" --password=prism_synthetic_only --port=13527
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture initialization failed.' }
$fixtureProcess = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -WorkingDirectory $fixtureData -WindowStyle Hidden -PassThru -ArgumentList @(
    '--no-defaults', "--datadir=$fixtureData", "--innodb-data-home-dir=$fixtureData", "--innodb-log-group-home-dir=$fixtureData",
    '--skip-innodb-use-native-aio', '--skip-innodb-buffer-pool-load-at-startup', '--innodb-buffer-pool-size=64M',
    '--port=13527', '--bind-address=127.0.0.1', "--log-error=$fixtureData\server.err"
)
$fixtureProcess.Id | Set-Content -LiteralPath (Join-Path $review 'sandbox\mysql-process-id.txt')
for ($attempt = 0; $attempt -lt 50; $attempt++) {
    $probe = New-Object System.Net.Sockets.TcpClient
    try { $probe.Connect('127.0.0.1',13527); break }
    catch { if ($attempt -eq 49) { throw 'Disposable fixture did not become ready.' }; Start-Sleep -Milliseconds 100 }
    finally { $probe.Dispose() }
}
& 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'initialize.php')
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture schema creation failed.' }
& 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'seed.php')
if ($LASTEXITCODE -ne 0) { throw 'Disposable fixture policy seed failed.' }
