#Requires -Version 5.1
<#
.SYNOPSIS
  Folia Web 可选组件 — Windows 安装/首次启动

.DESCRIPTION
  需要 Docker Desktop（Linux 容器）与 Docker Compose v2。
  首次安装需要联网拉取镜像；脚本不会由网页自动执行。

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\folia\install.ps1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\folia\install.ps1 -SkipPull
#>
[CmdletBinding()]
param(
    [switch]$SkipPull
)

$ErrorActionPreference = 'Stop'
try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    $OutputEncoding = [System.Text.Encoding]::UTF8
} catch { }

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Text
    )
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $encoding = New-Object System.Text.UTF8Encoding -ArgumentList $false
    [System.IO.File]::WriteAllText($Path, $Text, $encoding)
}

function Get-FoliaToken {
    $bytes = New-Object byte[] 24
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-Compose {
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$WorkDir
    )
    Push-Location $WorkDir
    try {
        & docker compose --env-file .env -f compose.yaml @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose $($Arguments -join ' ') 执行失败（退出码 $LASTEXITCODE）。"
        }
    } finally {
        Pop-Location
    }
}

try {
    $Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $ReleaseFile = Join-Path $PSScriptRoot 'release.json'
    $release = $null
    if (Test-Path $ReleaseFile) {
        try {
            $release = Get-Content -Raw -Encoding UTF8 $ReleaseFile | ConvertFrom-Json
        } catch { }
    }

    function Get-ReleaseValue {
        param([string]$Name, [string]$Fallback)
        if ($release -and ($release.PSObject.Properties.Name -contains $Name) -and $release.$Name) {
            return [string]$release.$Name
        }
        return $Fallback
    }

    $FoliaHome = if ($env:FOLIA_HOME) { $env:FOLIA_HOME } else { Join-Path $Root 'folia-runtime' }
    $HttpPort = if ($env:FOLIA_HTTP_PORT) { [int]$env:FOLIA_HTTP_PORT } else { 18080 }
    $SyncPort = if ($env:FOLIA_SYNC_PORT) { [int]$env:FOLIA_SYNC_PORT } else { 13000 }
    $StackVersion = if ($env:FOLIA_STACK_VERSION) { $env:FOLIA_STACK_VERSION } else { Get-ReleaseValue 'stack_version' '1.2.1' }
    $SyncVersion = if ($env:FOLIA_SYNC_VERSION) { $env:FOLIA_SYNC_VERSION } else { Get-ReleaseValue 'sync_version' '1.0.0' }
    $AppVersion = Get-ReleaseValue 'app_version' '0.7.22'

    $ComposeFile = Join-Path $FoliaHome 'compose.yaml'
    $EnvFile = Join-Path $FoliaHome '.env'
    $StateFile = Join-Path $Root 'web\data\folia.json'

    Write-Host '=============================================='
    Write-Host ' Folia 可选组件安装（Windows）'
    Write-Host " 安装目录 : $FoliaHome"
    Write-Host " 版本     : $StackVersion (app $AppVersion)"
    Write-Host " Web 端口 : $HttpPort"
    Write-Host " Sync 端口: $SyncPort"
    Write-Host '=============================================='

    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw '未找到 Docker。请先安装 Docker Desktop，并确认 Docker Desktop 已启动。'
    }
    & docker info *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker 已安装，但守护进程没有运行，或当前用户没有权限访问 Docker。请先启动 Docker Desktop。'
    }
    & docker compose version *> $null
    if ($LASTEXITCODE -ne 0) {
        throw '未找到 Docker Compose v2。请更新 Docker Desktop。'
    }

    New-Item -ItemType Directory -Force -Path (Join-Path $FoliaHome 'data\sync') | Out-Null
    Copy-Item -Force (Join-Path $PSScriptRoot 'compose.yaml') $ComposeFile

    if (-not (Test-Path $EnvFile)) {
        $template = Get-Content -Raw -Encoding UTF8 (Join-Path $PSScriptRoot '.env.example')
        $token = Get-FoliaToken
        $template = $template -replace '(?m)^SYNC_TOKEN=.*$', ('SYNC_TOKEN=' + $token)
        $template = $template -replace '(?m)^FOLIA_STACK_VERSION=.*$', ('FOLIA_STACK_VERSION=' + $StackVersion)
        $template = $template -replace '(?m)^FOLIA_SYNC_VERSION=.*$', ('FOLIA_SYNC_VERSION=' + $SyncVersion)
        $template = $template -replace '(?m)^FOLIA_HTTP_PORT=.*$', ('FOLIA_HTTP_PORT=' + $HttpPort)
        $template = $template -replace '(?m)^FOLIA_SYNC_PORT=.*$', ('FOLIA_SYNC_PORT=' + $SyncPort)
        Write-Utf8NoBom -Path $EnvFile -Text $template
        Write-Host "已生成 $EnvFile（包含随机 Sync Token）"
    } else {
        Write-Host "使用已有配置：$EnvFile"
    }

    if (-not $SkipPull) {
        Write-Host '拉取 Folia 镜像（首次可能需要几分钟）...'
        Invoke-Compose -Arguments @('pull') -WorkDir $FoliaHome
    } else {
        Write-Host '跳过镜像拉取（-SkipPull）'
    }

    Write-Host '启动容器并等待健康检查...'
    try {
        Invoke-Compose -Arguments @('up', '-d', '--wait') -WorkDir $FoliaHome
    } catch {
        Push-Location $FoliaHome
        try {
            & docker compose --env-file .env -f compose.yaml logs --no-color --tail=120
        } catch { }
        Pop-Location
        throw
    }

    $healthOk = $false
    for ($i = 0; $i -lt 5; $i++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:$HttpPort/healthz") -TimeoutSec 8
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
                $healthOk = $true
                break
            }
        } catch {
            Start-Sleep -Seconds 2
        }
    }
    if (-not $healthOk) {
        throw "容器已启动，但网关健康检查失败。请查看：cd `"$FoliaHome`"; docker compose logs --tail=120"
    }

    $now = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $state = [ordered]@{
        port          = $HttpPort
        sync_port     = $SyncPort
        stack_version = $StackVersion
        app_version   = $AppVersion
        installed_at  = $now
        updated_at    = $now
    }
    Write-Utf8NoBom -Path $StateFile -Text ($state | ConvertTo-Json)

    Write-Host ''
    Write-Host 'Folia 已就绪。' -ForegroundColor Green
    Write-Host "  本机访问 : http://127.0.0.1:$HttpPort"
    Write-Host "  局域网   : http://<这台电脑的局域网IP>:$HttpPort"
    Write-Host "  状态文件 : $StateFile"
    Write-Host "  停止服务 : cd `"$FoliaHome`"; docker compose down"
    Write-Host "  查看日志 : cd `"$FoliaHome`"; docker compose logs -f"
    Write-Host ''
    Write-Host '现在可以回到 ListenWrite 主界面，点击「Folia 音乐」卡片。'
} catch {
    Write-Host ('错误：' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
