<#
.SYNOPSIS
  Removes PyHP from this computer (user PATH entry + installed files).

.DESCRIPTION
  Default install dir is this script's own folder; if that does not look
  like a PyHP folder, the default C:\phpl is used.

    powershell -NoProfile -ExecutionPolicy Bypass -File .\uninstall.ps1
    uninstall.bat                 (same, double-click friendly)
    .\uninstall.ps1 -InstallDir C:\pyhp   (custom location)
    .\uninstall.ps1 -KeepFiles            (only clean PATH)
#>
[CmdletBinding()]
param(
    [string]$InstallDir = '',
    [switch]$KeepFiles
)

$ErrorActionPreference = 'Stop'

if (-not $InstallDir) {
    if ($PSScriptRoot -and (Test-Path (Join-Path $PSScriptRoot 'bin\pyhp'))) {
        $InstallDir = $PSScriptRoot
    }
    else {
        $InstallDir = 'C:\phpl'
    }
}

Write-Host ''
Write-Host "  Uninstalling PyHP from: $InstallDir" -ForegroundColor Magenta

# 1. user PATH cleanup
$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
if ($userPath) {
    $keep = $userPath.Split(';') |
        Where-Object { $_ -and ($_.TrimEnd('\') -ine $InstallDir.TrimEnd('\')) }
    if ($keep.Count -ne ($userPath.Split(';') | Where-Object { $_ }).Count) {
        [Environment]::SetEnvironmentVariable('Path', ($keep -join ';'), 'User')
        Write-Host '  removed from user PATH' -ForegroundColor Green
    }
    else {
        Write-Host '  not present on user PATH' -ForegroundColor DarkGray
    }
}

# 2. files
if ($KeepFiles) {
    Write-Host '  keeping files (-KeepFiles)' -ForegroundColor DarkGray
}
elseif (Test-Path $InstallDir) {
    Remove-Item -Path $InstallDir -Recurse -Force
    Write-Host "  deleted $InstallDir" -ForegroundColor Green
}
else {
    Write-Host "  $InstallDir does not exist" -ForegroundColor DarkGray
}

Write-Host '  Done. Close and reopen any open terminals.'
Write-Host ''
