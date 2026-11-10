# Rebuild the TEST database from scratch: schema, seed, migrations, then synthetic data.
# Usage (PowerShell 7):  pwsh -File tests\setup_test_database.ps1 [-Database urban_escape_test]
# Only a database whose name ends in "_test" can be rebuilt, so the real database is never touched.
param([string]$Database = 'urban_escape_test')

$ErrorActionPreference = 'Stop'
if ($Database -notmatch '^[a-z0-9_]+_test$') { throw "Refusing: '$Database' is not a test database name ending in _test." }

$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$php = 'C:\xampp\php\php.exe'
$root = Split-Path $PSScriptRoot -Parent

function Import-Sql([string]$file) {
    # The project scripts select nairobi_property_db; drop those lines so they load into the test database.
    Get-Content (Join-Path $root $file) | Where-Object { $_ -notmatch '^\s*(CREATE DATABASE|USE )' } | & $mysql -u root $Database
    if ($LASTEXITCODE -ne 0) { throw "Import failed: $file" }
}

& $mysql -u root -e "DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
if ($LASTEXITCODE -ne 0) { throw 'Could not create the test database. Is MySQL running in XAMPP?' }
Import-Sql 'database\schema.sql'
Import-Sql 'database\seed.sql'
Get-ChildItem (Join-Path $root 'database\migrations') -Filter *.sql | Sort-Object Name | ForEach-Object { Import-Sql "database\migrations\$($_.Name)" }

$env:DB_NAME = $Database
& $php (Join-Path $root 'tests\fixtures\synthetic_data.php')
if ($LASTEXITCODE -ne 0) { throw 'Loading the synthetic data failed.' }
Write-Host "Test database $Database is ready."
