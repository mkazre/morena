# Builds an upload-ready zip of the Laravel app for cPanel (no SSH/Composer needed on the server).
# Usage (from the project root, in PowerShell):   .\deploy\build-release.ps1
# Output: deploy\releases\morena-crm-YYYYMMDD-HHmm.zip
#
# The zip deliberately EXCLUDES: .env (secrets), the local SQLite database, demo/uploaded files in storage,
# logs, tests and dev tooling. vendor/ is rebuilt with production dependencies only.

$ErrorActionPreference = 'Stop'
$root    = Split-Path -Parent $PSScriptRoot
$src     = Join-Path $root 'backend'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmm'
$stage   = Join-Path $env:TEMP "morena-release-$stamp"
$outDir  = Join-Path $PSScriptRoot 'releases'
$zip     = Join-Path $outDir "morena-crm-$stamp.zip"

New-Item -ItemType Directory -Force $outDir | Out-Null
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }

Write-Host "Copying app to $stage ..."
# /XD = skip folders, /XF = skip files. Exit codes < 8 are success for robocopy.
robocopy $src $stage /E /NFL /NDL /NJH /NJS /NP `
  /XD vendor node_modules tests .git .idea .vscode `
      (Join-Path $src 'storage\logs') (Join-Path $src 'storage\app') (Join-Path $src 'storage\framework') `
      (Join-Path $src 'public\storage') (Join-Path $src 'bootstrap\cache') `
  /XF .env .env.backup .env.testing *.sqlite *.sqlite-journal .phpunit.result.cache phpunit.xml | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }

# Recreate the empty writable folders Laravel expects.
foreach ($d in 'storage\app\public', 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions',
               'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
  New-Item -ItemType Directory -Force (Join-Path $stage $d) | Out-Null
  Set-Content -Path (Join-Path $stage "$d\.gitignore") -Value "*`n!.gitignore" -Encoding ascii
}

Write-Host 'Installing production dependencies (composer --no-dev) ...'
Push-Location $stage
cmd /c "composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts 2>&1"
if ($LASTEXITCODE -ne 0) { Pop-Location; throw 'composer install failed' }
cmd /c "composer dump-autoload --optimize --no-dev --no-scripts 2>&1" | Out-Null
Pop-Location

Write-Host "Zipping to $zip ..."
if (Test-Path $zip) { Remove-Item -Force $zip }
# Windows' bundled bsdtar writes real zips and keeps dotfiles such as public/.htaccess.
cmd /c "tar -a -c -f `"$zip`" -C `"$stage`" . 2>&1"
if ($LASTEXITCODE -ne 0) { throw 'zip failed' }

Remove-Item -Recurse -Force $stage
$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host "Done: $zip ($mb MB)"
