@echo off
setlocal EnableExtensions
rem ------------------------------------------------------------------
rem  PyHP uninstaller - double-click entry point.
rem  Removes the PATH entry and deletes the PyHP folder.
rem ------------------------------------------------------------------
set "HERE=%~dp0"

if exist "%HERE%uninstall.ps1" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%HERE%uninstall.ps1" %*
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "irm https://raw.githubusercontent.com/Stevem100/pyhp/main/uninstall.ps1 | iex"
)
set "EC=%errorlevel%"
echo "%cmdcmdline%" | find /i "%~f0" >nul 2>nul && pause
endlocal & exit /b %EC%
