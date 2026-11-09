@echo off
setlocal EnableExtensions
rem ------------------------------------------------------------------
rem  PyHP installer - double-click entry point.
rem  Runs install.ps1 (PowerShell 5.1+) from this same folder.
rem  You can also pass parameters, e.g.:  install.bat -NoPath
rem ------------------------------------------------------------------
set "HERE=%~dp0"

if not exist "%HERE%install.ps1" (
    echo [pyhp] install.ps1 was not found next to this file. 1>&2
    echo [pyhp] Download the full PyHP zip from https://github.com/Stevem100/pyhp 1>&2
    echo [pyhp] or run the PowerShell one-liner: 1>&2
    echo [pyhp]   irm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 ^| iex 1>&2
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%HERE%install.ps1" %*
set "EC=%errorlevel%"
rem If launched by double-click, keep the window open so output can be read.
echo "%cmdcmdline%" | find /i "%~f0" >nul 2>nul && pause
endlocal & exit /b %EC%
