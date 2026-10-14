@echo off
REM Wipes the local database and loads the clean demo data.
setlocal
cd /d "%~dp0..\backend"

echo This DELETES ALL DATA in the local database and loads the demo data.
set /p OK=Type YES to continue: 
if /i not "%OK%"=="YES" (
  echo Cancelled. Nothing was changed.
  pause
  exit /b 0
)

if not exist "database\database.sqlite" type nul > "database\database.sqlite"

php artisan migrate:fresh --seed --force
if errorlevel 1 (
  echo.
  echo Something went wrong. Read the message above.
  pause
  exit /b 1
)

echo.
echo Done. Write down the password printed above. It is different each time.
echo Demo tags for the app: 04DE0000000001 genuine, 04DE0000000002 revoked, 04DE0000000003 unregistered.
pause
