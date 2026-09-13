@echo off
REM ============================================================
REM  Code ^& AI - local dev server launcher
REM  Double-click to start; close this window (or Ctrl+C) to stop.
REM ============================================================

title Code ^& AI - Dev Server (http://localhost:8000)
cd /d "%~dp0"

echo.
echo   Starting dev server on http://localhost:8000
echo   Keep this window OPEN while testing. Close it to stop.
echo.

REM If port 8000 is already in use (server already running?), use 8080.
netstat -ano | findstr ":8000" | findstr "LISTENING" >nul 2>&1
if %errorlevel%==0 (
    echo   Port 8000 busy - using http://localhost:8080 instead.
    echo.
    start "" "http://localhost:8080"
    php -S 127.0.0.1:8080 -t .
) else (
    start "" "http://localhost:8000"
    php -S 127.0.0.1:8000 -t .
)

pause
