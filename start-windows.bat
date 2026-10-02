@echo off
rem Bank assistant - run on this Windows computer (no host needed).
rem Needs PHP for Windows extracted into the "php" folder next to this file.
setlocal
cd /d "%~dp0"
set PORT=8080
set "PHPEXE=%~dp0php\php.exe"
chcp 65001 >nul

if not exist "%PHPEXE%" (
  echo.
  echo  PHP not found.
  echo  1. Open https://windows.php.net/download/
  echo  2. Under the newest PHP 8.x, download the ZIP of "VS17 x64 Non Thread Safe"
  echo  3. Extract it into this folder:  %~dp0php
  echo     so that this file exists:      %PHPEXE%
  echo  4. Run start-windows.bat again.
  echo.
  pause
  exit /b 1
)

set PHPOPT=-n -d "extension_dir=%~dp0php\ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=curl -d extension=mbstring -d extension=openssl -d extension=sodium -d date.timezone=Asia/Tehran -d upload_max_filesize=20M -d post_max_size=25M -d display_errors=0 -d log_errors=1

"%PHPEXE%" %PHPOPT% server\setup.php %PORT%
if errorlevel 1 (
  pause
  exit /b 1
)

rem Scheduled jobs + Bale bot in their own window
start "Bank assistant - jobs and Bale bot" "%PHPEXE%" %PHPOPT% server\cron.php daemon

start "" http://localhost:%PORT%/app/
echo  Server is running. Keep this window open; close it to stop.
echo  If Windows Firewall asks, allow "Private networks" so the phone and ESP32 can connect.
echo.
"%PHPEXE%" %PHPOPT% -S 0.0.0.0:%PORT% -t server server\router.php
pause
