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
rem  Requires PHP 8.0+ on PATH, or set the PHP_BIN environment
rem  variable to the full path of php.exe, e.g.
rem      setx PHP_BIN "C:\php\php.exe"
rem ------------------------------------------------------------------

set "PYHP_HOME=%~dp0"
set "PHP_EXE=%PHP_BIN%"

if not defined PHP_EXE goto find_php
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
echo [pyhp]   1. Install PHP:  winget install PHP.PHP.8.3 1>&2
echo [pyhp]      (or download the zip from https://windows.php.net/download/ ) 1>&2
echo [pyhp]   2. Close and reopen the terminal, then run:  php -v 1>&2
echo [pyhp]   Already have PHP? Point PyHP at it:  setx PHP_BIN "C:\path\to\php.exe" 1>&2
echo [pyhp] Full guide: see INSTALL-WINDOWS.md next to this file. 1>&2
exit /b 1

:run
"%PHP_EXE%" "%PYHP_HOME%bin\pyhp" %*
set "PYHP_EC=%errorlevel%"
rem If launched by double-click, keep the window open so output can be read.
echo "%cmdcmdline%" | find /i "%~f0" >nul 2>nul && pause
endlocal & exit /b %PYHP_EC%
