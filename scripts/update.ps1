#Requires -Version 5.1
<#
============================================================
  ListenWrite 一键更新脚本（Windows）
  停止服务 -> 更新代码 -> 重新走安装/启动流程

  更新方式：
    - 是 git 仓库：git pull --ff-only
    - 不是 git 仓库（离线解压版）：自动下载 GitHub 最新源码 zip 覆盖代码
      （只覆盖代码；web\data\、web\inc\config.php、offline\、runtime\ 均保留）
============================================================
#>
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$Root = Split-Path -Parent $ScriptDir

Write-Host '==> 停止本地服务' -ForegroundColor Cyan
Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and ($_.CommandLine -like '*router.php*') } |
    ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force } catch {} }

function Update-FromGitHub {
    Write-Host '==> 非 git 仓库：从 GitHub 下载最新源码覆盖（保留数据）' -ForegroundColor Cyan
    $urls = @(
        'https://codeload.github.com/CH-Hu-Bill/MoXie/zip/refs/heads/main',
        'https://github.com/CH-Hu-Bill/MoXie/archive/refs/heads/main.zip'
    )
    $tmp = Join-Path $env:TEMP ('moxie-update-' + [guid]::NewGuid().ToString('N'))
    $zip = Join-Path $tmp 'src.zip'
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    $ok = $false
    foreach ($u in $urls) {
        try {
            Write-Host "    下载：$u"
            Invoke-WebRequest -Uri $u -OutFile $zip -UseBasicParsing -TimeoutSec 900
            if ((Test-Path $zip) -and ((Get-Item $zip).Length -gt 0)) { $ok = $true; break }
        } catch {
            Write-Host "    失败：$($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    if (-not $ok) {
        Write-Host '    [!!] 无法访问 GitHub，更新失败；继续使用当前代码（服务仍会重启）。' -ForegroundColor Yellow
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        return
    }
    $extract = Join-Path $tmp 'src'
    Expand-Archive -Path $zip -DestinationPath $extract -Force
    $inner = Get-ChildItem -Path $extract -Directory | Select-Object -First 1
    if (-not $inner) {
        Write-Host '    [!!] 压缩包内容异常，更新失败。' -ForegroundColor Yellow
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        return
    }
    $src = $inner.FullName
    if (Get-Command robocopy.exe -ErrorAction SilentlyContinue) {
        # /E 合并覆盖；不删除目标多余文件；/XF config.php 保护本地配置
        robocopy "$src" "$Root" /E /XF "config.php" /R:1 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    } else {
        Copy-Item -Path (Join-Path $src '*') -Destination $Root -Recurse -Force
    }
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host '    代码已更新（web\data、config.php、offline、runtime 均保留）' -ForegroundColor Green
}

if (Test-Path (Join-Path $Root '.git')) {
    Write-Host '==> 拉取最新代码（git pull）' -ForegroundColor Cyan
    git -C $Root pull --ff-only
} else {
    Update-FromGitHub
}

Write-Host '==> 重新执行安装/启动流程' -ForegroundColor Cyan
& (Join-Path $ScriptDir 'install.ps1') -Port 0
