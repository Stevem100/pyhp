<#
.SYNOPSIS
  PyHP installer for Windows — PHP syntax, Python brains, C muscles.

.DESCRIPTION
  One-command setup, no admin rights required:
    - installs PyHP to a fixed folder (default: C:\pyhp; change with -InstallDir)
    - reuses any PHP 8 already on the machine (PATH / PHP_BIN / bundled),
      otherwise downloads PHP 8.3 (NTS x64) into <InstallDir>\php
    - adds PyHP to your user PATH (opt out with -NoPath)
    - runs a smoke test to prove everything works

  From PowerShell (recommended):
    irm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 | iex

  From cmd.exe:
    curl -fsSL https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 -o "%TEMP%\pyhp-install.ps1" && powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\pyhp-install.ps1"

  Parameters (download the script first, then pass them):
    -InstallDir C:\pyhp    where to install (default C:\pyhp)
    -NoPath                do not modify PATH
    -SkipPhp               never check or download PHP (you manage PHP yourself)
    -PhpZip <url>          use a specific PHP zip instead of the 8.3 latest build

  Upgrading an older PyHP (0.1.x-0.3.x)? Just re-run this installer - it
  installs the new version into C:\pyhp, and from then on `pyhp update` keeps
  that folder current forever. If an old install folder is still winning the
  PATH race, run the uninstall.ps1 from THAT folder (or just delete the old
  folder) and reopen your terminal.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File .\install.ps1 -InstallDir C:\pyhp
#>
[CmdletBinding()]
param(
    [string]$InstallDir = 'C:\pyhp',
    [switch]$NoPath,
    [switch]$SkipPhp,
    [string]$PhpZip = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'   # IWR progress bars slow downloads ~10x
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

$RepoZip   = 'https://codeload.github.com/Stevem100/pyhp/zip/refs/heads/main'
$PhpLatest = 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip'

function Write-Step([string]$msg) { Write-Host "==> $msg" -ForegroundColor Cyan }
function Write-Ok([string]$msg)   { Write-Host "    $msg" -ForegroundColor Green }
function Write-Info([string]$msg) { Write-Host "    $msg" -ForegroundColor DarkGray }
function Die([string]$msg) {
    Write-Host ''
    Write-Host "INSTALL FAILED: $msg" -ForegroundColor Red
    Write-Host 'If this keeps happening, please open an issue:' -ForegroundColor Yellow
    Write-Host '  https://github.com/Stevem100/pyhp/issues' -ForegroundColor Yellow
    exit 1
}

Write-Host ''
Write-Host '  PyHP installer - PHP syntax, Python brains, C muscles.' -ForegroundColor Magenta
Write-Host "  Install target: $InstallDir"
Write-Host ''

# ------------------------------------------------------------------ 1. PyHP files
$srcRoot = $null
$tmpDir  = $null
if ($PSScriptRoot -and (Test-Path (Join-Path $PSScriptRoot 'bin\pyhp'))) {
    Write-Step 'Installing from the local copy of PyHP'
    $srcRoot = $PSScriptRoot
}
else {
    Write-Step 'Downloading PyHP from GitHub'
    $tmpDir = Join-Path ([IO.Path]::GetTempPath()) ('pyhp-install-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmpDir | Out-Null
    $zip = Join-Path $tmpDir 'pyhp.zip'
    try {
        Invoke-WebRequest -Uri $RepoZip -OutFile $zip -UseBasicParsing
        Expand-Archive -Path $zip -DestinationPath $tmpDir
    }
    catch { Die ("could not download/extract the PyHP repo zip - " + $_.Exception.Message) }
    $top = Get-ChildItem -Path $tmpDir -Directory |
        Where-Object { Test-Path (Join-Path $_.FullName 'bin\pyhp') } |
        Select-Object -First 1
    if (-not $top) { Die 'unexpected repo zip layout (no bin/pyhp found inside)' }
    $srcRoot = $top.FullName
}

Write-Step "Copying PyHP into $InstallDir"
try {
    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
    # probe write access before copying (C:\ root folders can be ACL-restricted)
    $probe = Join-Path $InstallDir '.pyhp-write-test'
    Set-Content -Path $probe -Value 'ok' -ErrorAction Stop
    Remove-Item $probe -Force
    Copy-Item -Path (Join-Path $srcRoot '*') -Destination $InstallDir -Recurse -Force
}
catch {
    if ($_.Exception.Message -match 'Access to the path|denied|UnauthorizedAccess') {
        Die ("cannot write to $InstallDir - access denied.`n" +
             "         Fixes: (a) run this PowerShell window as Administrator once,`n" +
             "         (b) create the folder yourself first and grant your user write access, or`n" +
             "         (c) pick a per-user folder instead: -InstallDir `"$env:LOCALAPPDATA\Programs\PyHP`"")
    }
    Die ("could not copy files - " + $_.Exception.Message)
}
Write-Ok 'PyHP core installed (bin, src, examples, tests, docs)'
if ($tmpDir) { Remove-Item $tmpDir -Recurse -Force -ErrorAction SilentlyContinue }

# ------------------------------------------------- 2. PHP: reuse or self-contain
$phpExe = $null
if (-not $SkipPhp) {
    $bundled = Join-Path $InstallDir 'php\php.exe'
    if (Test-Path $bundled) {
        Write-Step 'PHP is already bundled inside the PyHP folder - reusing it'
        $phpExe = $bundled
    }
    elseif (Get-Command php -ErrorAction SilentlyContinue) {
        Write-Step 'PHP found on PATH - reusing it (no download)'
        $phpExe = (Get-Command php).Source
    }
    elseif ($env:PHP_BIN -and (Test-Path $env:PHP_BIN)) {
        Write-Step 'PHP found via PHP_BIN - reusing it (no download)'
        $phpExe = $env:PHP_BIN
    }
    else {
        $url = $PhpLatest
        if ($PhpZip) { $url = $PhpZip }
        Write-Step 'No PHP found - downloading PHP 8.3 NTS x64 (about 34 MB, one time)'
        $phpTmp = Join-Path ([IO.Path]::GetTempPath()) ('pyhp-php-' + [Guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $phpTmp | Out-Null
        try {
            Invoke-WebRequest -Uri $url -OutFile (Join-Path $phpTmp 'php.zip') -UseBasicParsing
            Write-Info 'extracting...'
            Expand-Archive -Path (Join-Path $phpTmp 'php.zip') -DestinationPath (Join-Path $InstallDir 'php')
        }
        catch {
            Die ("could not download/extract PHP - " + $_.Exception.Message +
                 "   You can install PHP manually (see INSTALL-WINDOWS.md) and re-run.")
        }
        finally { Remove-Item $phpTmp -Recurse -Force -ErrorAction SilentlyContinue }

        if (Test-Path (Join-Path $InstallDir 'php\php.exe')) {
            $phpExe = Join-Path $InstallDir 'php\php.exe'
            Write-Ok 'PHP 8.3 installed inside the PyHP folder - fully self-contained'
        }
        else { Die 'php.exe was not present in the PHP zip after extraction' }
    }
}

# ------------------------------------------------------------------ 3. PATH
if (-not $NoPath) {
    Write-Step "Adding $InstallDir to your user PATH"
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    if (-not $userPath) { $userPath = '' }
    $parts = $userPath.Split(';') | Where-Object { $_ -ne '' }
    if ($parts -contains $InstallDir) {
        Write-Ok 'already on PATH'
    }
    else {
        $trimmed = $userPath.TrimEnd(';')
        $newPath = $InstallDir
        if ($trimmed) { $newPath = $trimmed + ';' + $InstallDir }
        [Environment]::SetEnvironmentVariable('Path', $newPath, 'User')
        Write-Ok 'added (new terminals will see the pyhp command)'
    }
    if (($env:Path -split ';') -notcontains $InstallDir) { $env:Path = $env:Path + ';' + $InstallDir }
}
else {
    Write-Step 'Skipping PATH modification (-NoPath)'
}

# ------------------------------------------------------------------ 4. smoke test
Write-Step 'Smoke test'
$pyhp  = Join-Path $InstallDir 'bin\pyhp'
$hello = Join-Path $InstallDir 'examples\01_hello.pyh'
$out   = $null
$code  = 1
try {
    if ($phpExe) {
        $ver = (& $phpExe -v 2>&1 | Select-Object -First 1)
        Write-Info ("PHP: " + $ver)
        $out  = (& $phpExe $pyhp run $hello 2>&1)
        $code = $LASTEXITCODE
    }
    else {
        $out  = (& (Join-Path $InstallDir 'pyhp.bat') run $hello 2>&1)
        $code = $LASTEXITCODE
    }
}
catch {
    Die ("PHP failed to start - " + $_.Exception.Message +
         "   The usual cause is a missing Visual C++ runtime. Install the VC++ 2015-2022 x64 redistributable (https://aka.ms/vs/17/release/vc_redist.x64.exe) and re-run.")
}
if ($code -ne 0) {
    Die ("smoke test exited with code $code. If php.exe crashed on startup, install the VC++ 2015-2022 x64 redistributable (https://aka.ms/vs/17/release/vc_redist.x64.exe) and re-run.")
}
$out | Select-Object -First 8 | ForEach-Object { Write-Host "    $_" }
Write-Ok 'smoke test passed'

# ------------------------------------------------------------------ done
Write-Host ''
Write-Host '  PyHP installed successfully!' -ForegroundColor Green
Write-Host ''
Write-Host "  Location : $InstallDir"
if ($phpExe) { Write-Host "  PHP      : $phpExe" }
Write-Host ''
Write-Host '  Try it in a NEW terminal window:'
Write-Host '    pyhp run examples\01_hello.pyh'
Write-Host '    pyhp repl'
Write-Host ''
Write-Host '  Docs: ' -NoNewline
Write-Host (Join-Path $InstallDir 'README.md') -NoNewline
Write-Host '   Uninstall: ' -NoNewline
Write-Host (Join-Path $InstallDir 'uninstall.bat')
Write-Host ''
exit 0
