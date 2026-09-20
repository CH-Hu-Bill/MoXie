#Requires -Version 5.1
<#
============================================================
 ListenWrite 一键安装脚本（Windows）

 功能：
   1. 安装便携 PHP 8.2 到 runtime/php
   2. 安装便携 ffmpeg 到 runtime/ffmpeg
   3. 初始化 web/inc/config.php（随机密钥）
   4. 注册开机自启计划任务（登录时自动启动，隐藏窗口）
   5. 立即启动本地服务
   6. 可反复运行（幂等）；-Uninstall 卸载自启任务

 使用：双击 install.bat（会自动请求管理员权限）
============================================================
#>
[CmdletBinding()]
param(
    [int]$Port = 0,
    [switch]$Uninstall,
    [string]$FfmpegUrl = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root      = Split-Path -Parent $MyInvocation.MyCommand.Definition
$Runtime   = Join-Path $Root 'runtime'
$PhpDir    = Join-Path $Runtime 'php'
$FfmpegDir = Join-Path $Runtime 'ffmpeg'
$WebDir    = Join-Path $Root 'web'
$PortFile  = Join-Path $Runtime 'server-port.txt'
$TaskName  = 'ListenWrite Local Server'
$FwRuleName = 'ListenWrite Local Server'

function Step($m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "    [OK] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "    [!!] $m" -ForegroundColor Yellow }

function Get-Url($urls, $dest) {
    foreach ($u in $urls) {
        try {
            Write-Host "    下载：$u"
            Invoke-WebRequest -Uri $u -OutFile $dest -UseBasicParsing -TimeoutSec 900
            if ((Test-Path $dest) -and ((Get-Item $dest).Length -gt 0)) { return $true }
        } catch {
            Warn "下载失败：$($_.Exception.Message)"
            if (Test-Path $dest) { Remove-Item $dest -Force -ErrorAction SilentlyContinue }
        }
    }
    return $false
}

function Expand-Zip($zip, $dest) {
    if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $dest)
}

function Stop-Server {
    # 结束旧实例。WMI 的 CommandLine 有时取不到，故多路兜底：端口监听 / runtime\php 路径 / router.php 命令行。
    $ids = @()
    if ($Port -gt 0) {
        try {
            $ids += Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
                Select-Object -ExpandProperty OwningProcess
        } catch {}
    }
    try {
        $ids += Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
            Where-Object {
                ($_.ExecutablePath -and ($_.ExecutablePath -like "$PhpDir*")) -or
                ($_.CommandLine -and ($_.CommandLine -like '*router.php*'))
            } |
            Select-Object -ExpandProperty ProcessId
    } catch {}
    foreach ($procId in ($ids | Where-Object { $_ } | Select-Object -Unique)) {
        $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($p -and $p.ProcessName -eq 'php') {
            try { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue } catch {}
        }
    }
}

# ---------------- 卸载 ----------------
if ($Uninstall) {
    Step '移除开机自启任务'
    schtasks /Delete /TN "$TaskName" /F 2>$null | Out-Null
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Get-NetFirewallRule -DisplayName "$FwRuleName*" -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
    Stop-Server
    Ok '已移除计划任务、防火墙规则并停止服务（runtime/ 与班级数据保持不变）'
    return
}

# ---------------- 环境检查 ----------------
if (-not (Test-Path $WebDir)) {
    throw "未找到 web/ 目录。请把本脚本放在项目根目录（与 web/ 同级）后再运行。"
}

New-Item -ItemType Directory -Force -Path $Runtime | Out-Null

# ---------------- 端口 ----------------
if ($Port -le 0) {
    if (Test-Path $PortFile) {
        $Port = [int]((Get-Content $PortFile -Raw).Trim())
    } else {
        $Port = 8000
    }
}
Set-Content -Path $PortFile -Value $Port -Encoding ASCII
Ok "端口：$Port"

# ---------------- 防火墙（放行局域网，供手机 APP 访问）----------------
# 服务以隐藏窗口监听 0.0.0.0，若不预置规则，Windows 防火墙弹窗被忽略时手机会连不上。
Step '配置防火墙入站规则'
try {
    Get-NetFirewallRule -DisplayName "$FwRuleName*" -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
    New-NetFirewallRule -DisplayName "$FwRuleName (TCP $Port)" -Direction Inbound `
        -Action Allow -Protocol TCP -LocalPort $Port -Profile Any -ErrorAction Stop | Out-Null
    Ok "已放行入站 TCP $Port（局域网设备/手机 APP 可访问）"
} catch {
    Warn "防火墙规则设置失败（本机 127.0.0.1 仍可用，但手机可能连不上）：$($_.Exception.Message)"
}

# ---------------- 更新代码（若是 git 仓库）----------------
if (Test-Path (Join-Path $Root '.git')) {
    Step '更新代码（git pull）'
    try {
        git -C $Root pull --ff-only
        Ok '代码已更新'
    } catch {
        Warn "git pull 失败（继续使用当前代码）：$($_.Exception.Message)"
    }
}

# ---------------- PHP 8.2 ----------------
Step '准备 PHP 8.2'
$phpExe = Join-Path $PhpDir 'php.exe'
if (-not (Test-Path $phpExe)) {
    $phpZip = Join-Path $Runtime 'php.zip'
    $offlinePhp = Join-Path $Root 'offline\php.zip'
    if (Test-Path $offlinePhp) {
        Copy-Item $offlinePhp $phpZip -Force
        Ok '使用随包离线 PHP（无需联网）'
    } else {
        $phpUrls = @(
            'https://windows.php.net/downloads/releases/latest/php-8.2-nts-Win32-vs16-x64-latest.zip',
            'https://windows.php.net/downloads/releases/php-8.2.29-nts-Win32-vs16-x64.zip',
            'https://windows.php.net/downloads/releases/php-8.2.27-nts-Win32-vs16-x64.zip'
        )
        if (-not (Get-Url $phpUrls $phpZip)) {
            throw 'PHP 下载失败，请检查网络后重试（也可把 PHP 8.2 NTS x64 的 zip 放到 offline\php.zip 再运行）。'
        }
    }
    Expand-Zip $phpZip $PhpDir
    Remove-Item $phpZip -Force -ErrorAction SilentlyContinue
    Ok 'PHP 已解压到 runtime/php'
} else {
    Ok 'PHP 已存在，跳过下载'
}

$ini    = Join-Path $PhpDir 'php.ini'
$iniDev = Join-Path $PhpDir 'php.ini-development'
if (-not (Test-Path $ini) -and (Test-Path $iniDev)) {
    $lines = Get-Content $iniDev | ForEach-Object {
        $l = $_ -replace '^;\s*extension_dir\s*=.*', 'extension_dir = "ext"'
        $l = $l -replace '^;\s*extension=(curl|gd|mbstring|openssl|fileinfo|zip|exif)\b.*', 'extension=$1'
        $l
    }
    $lines += @(
        'upload_max_filesize = 300M',
        'post_max_size = 310M',
        'memory_limit = 256M',
        'max_execution_time = 120'
    )
    Set-Content -Path $ini -Value $lines -Encoding ASCII
    Ok '已生成 php.ini（启用 curl/gd/mbstring/openssl/zip 等扩展）'
}

# ---------------- ffmpeg ----------------
Step '准备 ffmpeg'
$ffmpegExe = Join-Path $FfmpegDir 'bin\ffmpeg.exe'
if (-not (Test-Path $ffmpegExe)) {
    $ffZip = Join-Path $Runtime 'ffmpeg.zip'
    $offlineFf = Join-Path $Root 'offline\ffmpeg.zip'
    $gotFf = $false
    if (Test-Path $offlineFf) {
        Copy-Item $offlineFf $ffZip -Force
        $gotFf = $true
        Ok '使用随包离线 ffmpeg（无需联网）'
    } else {
        $ffUrls = @()
        if ($FfmpegUrl -ne '') { $ffUrls += $FfmpegUrl }
        $ffUrls += @(
            'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip',
            'https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip'
        )
        $gotFf = Get-Url $ffUrls $ffZip
    }
    if (-not $gotFf) {
        Warn 'ffmpeg 获取失败；视频首帧缩略图功能将不可用（不影响其它功能，可稍后重跑本脚本）。'
    } else {
        $tmp = Join-Path $Runtime 'ffmpeg-tmp'
        Expand-Zip $ffZip $tmp
        Remove-Item $ffZip -Force -ErrorAction SilentlyContinue
        $found = Get-ChildItem -Path $tmp -Recurse -Filter 'ffmpeg.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($found) {
            $binDest = Join-Path $FfmpegDir 'bin'
            New-Item -ItemType Directory -Force -Path $binDest | Out-Null
            Copy-Item (Join-Path $found.DirectoryName '*') $binDest -Recurse -Force
            Ok 'ffmpeg 已安装到 runtime/ffmpeg/bin'
        } else {
            Warn '未在压缩包中找到 ffmpeg.exe'
        }
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
} else {
    Ok 'ffmpeg 已存在，跳过下载'
}

# ---------------- 配置 ----------------
Step '初始化配置'
$cfg = Join-Path $WebDir 'inc\config.php'
if (-not (Test-Path $cfg)) {
    $secret  = -join (1..64 | ForEach-Object { '{0:x}' -f (Get-Random -Minimum 0 -Maximum 16) })
    $adminPw = 'admin' + (Get-Random -Minimum 1000 -Maximum 9999)
    $tpl = Get-Content (Join-Path $WebDir 'inc\config.example.php') -Raw -Encoding UTF8
    $tpl = $tpl -replace "'replace-with-at-least-32-random-characters'", "'$secret'"
    $tpl = $tpl -replace "'change-this-password'", "'$adminPw'"
    # 必须写为「无 BOM 的 UTF-8」：BOM 会在 PHP 输出 header 前产生字节，破坏 JSON/重定向
    [System.IO.File]::WriteAllText($cfg, $tpl, (New-Object System.Text.UTF8Encoding($false)))
    Ok '已生成 web/inc/config.php'
    Write-Host "    管理员后台口令：$adminPw（请妥善保存）" -ForegroundColor Yellow
} else {
    Ok 'config.php 已存在，跳过'
}

# ---------------- 注册开机自启 ----------------
Step '注册开机自启（计划任务，登录时自动启动）'
$taskOk = $false
try {
    $vbs = Join-Path $Root 'run-hidden.vbs'
    if (-not (Test-Path $vbs)) { throw '缺少 run-hidden.vbs' }
    $action    = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$vbs`""
    $trigger   = New-ScheduledTaskTrigger -AtLogOn
    $settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
    $taskOk = $true
    Ok '已注册计划任务'
} catch {
    Warn "开机自启注册失败（通常是没有以管理员身份运行）：$($_.Exception.Message)"
}

# ---------------- 启动 ----------------
Step '启动本地服务'
Stop-Server
Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$vbs`"" | Out-Null
Start-Sleep -Seconds 2

$ips = @()
try {
    $ips = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        Select-Object -ExpandProperty IPAddress
} catch {}

Write-Host ''
Write-Host '==============================================' -ForegroundColor Green
Write-Host ' ListenWrite 已安装并启动' -ForegroundColor Green
Write-Host " 本机访问 : http://127.0.0.1:$Port"
foreach ($ip in $ips) { Write-Host " 内网访问 : http://${ip}:$Port" }
Write-Host ' 数据目录 : web\data\'
Write-Host ' 停止服务 : 任务管理器结束 php.exe，或运行 uninstall.bat'
Write-Host '==============================================' -ForegroundColor Green
if (-not $taskOk) {
    Write-Host ''
    Write-Host ' 注意：开机自启未注册。请右键 install.bat「以管理员身份运行」重新执行；' -ForegroundColor Yellow
    Write-Host '       本次可直接双击 run.bat 手动启动服务（其它功能不受影响）。' -ForegroundColor Yellow
}
Write-Host ''
