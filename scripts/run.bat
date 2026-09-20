@echo off
setlocal
set "ROOT=%~dp0"
for %%I in ("%~dp0..") do set "ROOT=%%~fI\"
chcp 65001 >nul
set "PATH=%ROOT%runtime\php;%ROOT%runtime\ffmpeg\bin;%PATH%"
set "PORT=8000"
if exist "%ROOT%runtime\server-port.txt" for /f "usebackq delims=" %%p in ("%ROOT%runtime\server-port.txt") do set "PORT=%%p"

echo ==============================================
echo  ListenWrite 本地服务
echo  本机访问 : http://127.0.0.1:%PORT%
echo  内网访问 : 运行 ipconfig 查看 IPv4，例如 http://192.168.x.x:%PORT%
echo  停止     : Ctrl + C
echo ==============================================

cd /d "%ROOT%web"
"%ROOT%runtime\php\php.exe" -S 0.0.0.0:%PORT% router.php
