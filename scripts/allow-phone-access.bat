@echo off
REM Lets a phone on the same Wi-Fi reach the server on port 8000. Run as administrator.
REM Windows must call the Wi-Fi network "Private". Remove the rule later with:
REM   netsh advfirewall firewall delete rule name="Weaver-ID dev server"
net session >nul 2>&1
if errorlevel 1 (
  echo Right-click this file and choose Run as administrator.
  pause
  exit /b 1
)

netsh advfirewall firewall delete rule name="Weaver-ID dev server" >nul 2>&1
netsh advfirewall firewall add rule name="Weaver-ID dev server" dir=in action=allow protocol=TCP localport=8000 profile=private
echo.
echo Done for Private networks only. Start the server with: scripts\start-weaver-id.bat phone
pause
