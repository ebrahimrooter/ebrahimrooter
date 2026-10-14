@echo off
rem Bank assistant - run on this Windows computer (no host needed).
rem Double-click it. The first time it downloads PHP for Windows into the "php" folder.
setlocal
cd /d "%~dp0"
set PORT=8080
set "PHPEXE=%~dp0php\php.exe"
chcp 65001 >nul

if not exist "%PHPEXE%" (
  echo.
  echo  First run: downloading PHP for Windows into the "php" folder ^(about 30 MB^)...
  powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; $z=Join-Path $env:TEMP 'bank-php.zip';" ^
    "foreach($u in @('https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip','https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip')){" ^
    " try { Write-Host ('  ' + $u); Invoke-WebRequest -Uri $u -OutFile $z -UseBasicParsing; Expand-Archive -Force -Path $z -DestinationPath '%~dp0php'; Remove-Item $z; exit 0 } catch { Write-Host ('  failed: ' + $_.Exception.Message) } }; exit 1"
)
if not exist "%PHPEXE%" (
  echo.
  echo  PHP could not be downloaded automatically.
  echo  1. Open https://windows.php.net/download/
  echo  2. Under the newest PHP 8.x, download the ZIP of "x64 Non Thread Safe"
  echo  3. Extract it into this folder:  %~dp0php
  echo     so that this file exists:      %PHPEXE%
  echo  4. Run start-windows.bat again.
  echo.
  pause
  exit /b 1
)

rem PHP needs the Microsoft Visual C++ runtime; most Windows have it, install it if not
"%PHPEXE%" -n -v >nul 2>&1
if errorlevel 1 (
  echo  Installing the Microsoft Visual C++ runtime that PHP needs...
  powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "[Net.ServicePointManager]::SecurityProtocol='Tls12'; $f=Join-Path $env:TEMP 'vc_redist.x64.exe'; Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $f -UseBasicParsing; Start-Process $f -ArgumentList '/install','/passive','/norestart' -Wait"
  "%PHPEXE%" -n -v >nul 2>&1
  if errorlevel 1 (
    echo  PHP still does not start. Install "Visual C++ Redistributable x64" from https://aka.ms/vs/17/release/vc_redist.x64.exe and try again.
    pause
    exit /b 1
  )
)

set PHPOPT=-n -d "extension_dir=%~dp0php\ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=curl -d extension=mbstring -d extension=openssl -d extension=sodium -d date.timezone=Asia/Tehran -d upload_max_filesize=20M -d post_max_size=25M -d display_errors=0 -d log_errors=1
set "VOICEPY=%~dp0voice\venv\Scripts\python.exe"

rem  start-windows.bat voice-test  : checks the local Persian voice (TTS -> STT round trip)
if /i "%~1"=="voice-test" (
  "%PHPEXE%" %PHPOPT% server\cron.php voice-test
  exit /b
)

"%PHPEXE%" %PHPOPT% server\setup.php %PORT%
if errorlevel 1 (
  pause
  exit /b 1
)

rem Local Persian voice (installed by voice\install-windows.ps1), in its own window
if exist "%VOICEPY%" (
  start "Bank assistant - voice STT-TTS" "%VOICEPY%" voice\voice_service.py serve
) else (
  echo  Server voice not installed (not needed: the phone apps speak Persian themselves).
)

rem Scheduled jobs + Bale bot in their own window
start "Bank assistant - jobs and Bale bot" "%PHPEXE%" %PHPOPT% server\cron.php daemon

start "" http://localhost:%PORT%/app/
echo  Server is running. Keep this window open; close it to stop.
echo  If Windows Firewall asks, allow "Private networks" so the phone and ESP32 can connect.
echo.
"%PHPEXE%" %PHPOPT% -S 0.0.0.0:%PORT% -t server server\router.php
pause
