#Requires -Version 5.1
<#
============================================================
 ListenWrite 一键更新脚本（Windows）
 停止服务 -> git pull -> 重新走安装/启动流程
============================================================
#>
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Definition

Write-Host '==> 停止本地服务' -ForegroundColor Cyan
Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and ($_.CommandLine -like '*router.php*') } |
    ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force } catch {} }

Write-Host '==> 拉取最新代码' -ForegroundColor Cyan
if (Test-Path (Join-Path $Root '.git')) {
    git -C $Root pull --ff-only
} else {
    Write-Host '    非 git 仓库，跳过。请手动替换代码后重跑 install.bat。' -ForegroundColor Yellow
}

Write-Host '==> 重新执行安装/启动流程' -ForegroundColor Cyan
& (Join-Path $Root 'install.ps1') -Port 0
