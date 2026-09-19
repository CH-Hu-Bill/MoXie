@echo off
setlocal
cd /d "%~dp0"
chcp 65001 >nul

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo 正在获取管理员权限...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update.ps1"
echo.
pause
