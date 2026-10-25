#Requires -Version 5.1
<#
.SYNOPSIS
  Folia Web 可选组件 — Windows 审核后更新

.DESCRIPTION
  更新会重启 Folia 容器，正在播放的音乐会中断。
  更新前备份 .env；失败时自动回滚到上一个版本。

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\folia\update.ps1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\folia\update.ps1 -Check
#>
[CmdletBinding()]
param(
    [switch]$Check,
    [switch]$Force
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
    $FoliaHome = if ($env:FOLIA_HOME) { $env:FOLIA_HOME } else { Join-Path $Root 'folia-runtime' }
    $EnvFile = Join-Path $FoliaHome '.env'
    $StateFile = Join-Path $Root 'web\data\folia.json'

    if (-not (Test-Path $ReleaseFile)) {
        throw "找不到审核清单：$ReleaseFile"
    }
    $release = Get-Content -Raw -Encoding UTF8 $ReleaseFile | ConvertFrom-Json
    $targetStack = [string]$release.stack_version
    $targetSync = [string]$release.sync_version
    $targetApp = [string]$release.app_version
    $approved = ($release.approved -eq $true)

    if (-not (Test-Path $StateFile)) {
        throw '没有找到已安装状态。请先运行 scripts\folia\install.ps1。'
    }
    $state = Get-Content -Raw -Encoding UTF8 $StateFile | ConvertFrom-Json
    $currentStack = [string]$state.stack_version
    $syncPort = [int]$state.sync_port
    $installedAt = [string]$state.installed_at

    Write-Host "当前 Folia : $currentStack"
    Write-Host "审核版本  : $targetStack (app $targetApp)"
    Write-Host "approved  : $approved"

    if ($currentStack -eq $targetStack) {
        Write-Host '已经是最新审核版本，无需更新。'
        exit 0
    }
    if ($Check) {
        exit 0
    }
    if (-not $approved -and -not $Force) {
        throw "release.json 未标记 approved=true；为避免未审核更新，已停止。确认要更新请加 -Force。"
    }
    if (-not (Test-Path $EnvFile)) {
        throw "找不到 $EnvFile"
    }

    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw '未找到 Docker。请先启动 Docker Desktop。'
    }
    & docker compose version *> $null
    if ($LASTEXITCODE -ne 0) {
        throw '未找到 Docker Compose v2。'
    }

    $backup = Join-Path $FoliaHome ('.env.before-' + $currentStack + '-' + (Get-Date -Format 'yyyyMMddHHmmss'))
    Copy-Item -Force $EnvFile $backup
    Write-Host "已备份配置：$backup"

    $envText = Get-Content -Raw -Encoding UTF8 $EnvFile
    $envText = $envText -replace '(?m)^FOLIA_STACK_VERSION=.*$', ('FOLIA_STACK_VERSION=' + $targetStack)
    $envText = $envText -replace '(?m)^FOLIA_SYNC_VERSION=.*$', ('FOLIA_SYNC_VERSION=' + $targetSync)
    Write-Utf8NoBom -Path $EnvFile -Text $envText

    Write-Host "拉取 $targetStack 镜像..."
    Invoke-Compose -Arguments @('pull') -WorkDir $FoliaHome

    Write-Host '重启容器并等待健康检查...'
    $updated = $false
    try {
        Invoke-Compose -Arguments @('up', '-d', '--wait') -WorkDir $FoliaHome
        $response = Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:" + [string]$state.port + '/healthz') -TimeoutSec 8
        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
            $updated = $true
        }
    } catch {
        $updated = $false
    }

    if (-not $updated) {
        Write-Host "更新失败，回滚到 $currentStack..." -ForegroundColor Yellow
        Copy-Item -Force $backup $EnvFile
        try {
            Invoke-Compose -Arguments @('pull') -WorkDir $FoliaHome
            Invoke-Compose -Arguments @('up', '-d', '--wait') -WorkDir $FoliaHome
        } catch { }
        throw "已尝试回滚。请查看：cd `"$FoliaHome`"; docker compose logs --tail=120"
    }

    $now = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $newState = [ordered]@{
        port          = [int]$state.port
        sync_port     = $syncPort
        stack_version = $targetStack
        app_version   = $targetApp
        installed_at  = $(if ($installedAt) { $installedAt } else { $now })
        updated_at    = $now
    }
    Write-Utf8NoBom -Path $StateFile -Text ($newState | ConvertTo-Json)

    Write-Host "Folia 已更新到 $targetStack (app $targetApp)。" -ForegroundColor Green
    Write-Host "旧配置备份保留在：$backup"
} catch {
    Write-Host ('错误：' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
