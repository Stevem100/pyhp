@echo off
setlocal EnableExtensions
rem ------------------------------------------------------------------
rem  PyHP launcher for Windows.
rem
rem  Usage:
rem    pyhp run script.pyh [args...]          run with the built-in interpreter
rem    pyhp compile script.pyh [args...]      transpile + run (fast path)
rem    pyhp build script.pyh -o out.php       transpile to standalone PHP
rem    pyhp repl                              interactive shell
rem
rem  PHP lookup order:
rem    1. PHP_BIN environment variable (php.exe path or its folder)
rem    2. bundled copy from install.ps1  (<this folder>\php\php.exe)
rem    3. php.exe on PATH
rem ------------------------------------------------------------------

set "PYHP_HOME=%~dp0"
set "PHP_EXE=%PHP_BIN%"

if defined PHP_EXE goto resolve_php
if exist "%PYHP_HOME%php\php.exe" set "PHP_EXE=%PYHP_HOME%php\php.exe"
if defined PHP_EXE goto check_php
goto find_php

:resolve_php
if exist "%PHP_EXE%\*" set "PHP_EXE=%PHP_EXE%\php.exe"
goto check_php

:find_php
where php >nul 2>nul
if errorlevel 1 goto no_php
set "PHP_EXE=php"
goto check_php

:check_php
if exist "%PHP_EXE%" goto run
echo [pyhp] PHP_BIN is set but php.exe was not found: "%PHP_EXE%" 1>&2
echo [pyhp] Point PHP_BIN at php.exe, e.g.  setx PHP_BIN "C:\php\php.exe" 1>&2
exit /b 1

:no_php
echo [pyhp] PHP 8.0+ was not found on this computer. 1>&2
echo [pyhp]   Easiest: run install.ps1 next to this file - it installs PHP for you. 1>&2
echo [pyhp]   Manual:  winget install PHP.PHP.8.3 1>&2
echo [pyhp]      (or download the zip from https://windows.php.net/download/ ) 1>&2
echo [pyhp]   Already have PHP? Point PyHP at it:  setx PHP_BIN "C:\path\to\php.exe" 1>&2
echo [pyhp] Full guide: see INSTALL-WINDOWS.md next to this file. 1>&2
exit /b 1

:run
"%PHP_EXE%" "%PYHP_HOME%bin\pyhp" %*
set "PYHP_EC=%errorlevel%"
rem If launched by double-click, keep the window open so output can be read.
echo "%cmdcmdline%" | find /i "%~f0" >nul 2>nul && pause
endlocal & exit /b %PYHP_EC%
