@echo off
REM Starts the Weaver-ID server and the card reader bridge.
REM   start-weaver-id.bat          this laptop only (safe default)
REM   start-weaver-id.bat phone    also reachable from a phone on the same Wi-Fi
setlocal
cd /d "%~dp0.."

set "HOST=127.0.0.1"
if /i "%~1"=="phone" set "HOST=0.0.0.0"

where php >nul 2>nul
if errorlevel 1 (
  echo PHP was not found. Install PHP or add it to PATH, then try again.
  pause
  exit /b 1
)

if not exist "backend\database\database.sqlite" (
  echo No database found. Run scripts\reset-demo.bat first.
  pause
  exit /b 1
)

where python >nul 2>nul
if errorlevel 1 (
  echo Python was not found. The Read from reader button will not work.
) else (
  start "Weaver-ID reader" /D "%CD%\tools" cmd /k python reader_bridge.py
)

start "Weaver-ID server" /D "%CD%\backend" cmd /k php artisan serve --host=%HOST% --port=8000

echo Waiting for the server...
timeout /t 4 /nobreak >nul
start "" "http://127.0.0.1:8000/login.html"

echo.
echo Weaver-ID is running. Close the two black windows to stop it.
if /i "%HOST%"=="0.0.0.0" (
  echo.
  echo PHONE MODE: anyone on this Wi-Fi can open the login page. Use it only on a network you trust.
  echo In the phone app, set the server to http://YOUR-LAPTOP-IP:8000 using one of these addresses:
  ipconfig | findstr /c:"IPv4"
  echo Test it in the phone's browser first: http://YOUR-LAPTOP-IP:8000/up
  echo If it does not load, run scripts\allow-phone-access.bat as administrator.
)
echo.
pause
