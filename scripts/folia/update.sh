#!/usr/bin/env bash
# ============================================================
# Folia Web 可选组件 — 审核后更新
#
# 用法（在 ListenWrite 根目录）：
#   bash scripts/folia/update.sh          # 更新到 release.json 的版本
#   bash scripts/folia/update.sh --check  # 只显示当前/目标版本
#   bash scripts/folia/update.sh --force  # 忽略 approved 标记
#
# 更新会重启 Folia 容器，正在播放的音乐会中断。
# 更新前会备份 .env；失败时自动回滚到上一个版本。
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
RELEASE_FILE="$SCRIPT_DIR/release.json"
FOLIA_HOME="${FOLIA_HOME:-$ROOT/folia-runtime}"
STATE_FILE="$ROOT/web/data/folia.json"
CHECK_ONLY=0
FORCE=0

json_value() {
    local key="$1"
    local fallback="$2"
    if [ -f "$RELEASE_FILE" ]; then
        local value
        value="$(sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$RELEASE_FILE" | head -n 1)"
        if [ -n "$value" ]; then
            printf '%s' "$value"
            return
        fi
    fi
    printf '%s' "$fallback"
}

for arg in "$@"; do
    case "$arg" in
        --check) CHECK_ONLY=1 ;;
        --force) FORCE=1 ;;
        -h|--help)
            sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "未知参数：$arg" >&2
            exit 2
            ;;
    esac
done

TARGET_STACK="$(json_value stack_version 1.2.1)"
TARGET_SYNC="$(json_value sync_version 1.0.0)"
TARGET_APP="$(json_value app_version 0.7.22)"
APPROVED="$(sed -n 's/.*"approved"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' "$RELEASE_FILE" | head -n 1)"
CURRENT_STACK=""
CURRENT_SYNC=""
INSTALLED_AT=""

if [ -f "$STATE_FILE" ]; then
    CURRENT_STACK="$(sed -n 's/.*"stack_version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$STATE_FILE" | head -n 1)"
    CURRENT_SYNC="$(sed -n 's/.*"sync_port"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' "$STATE_FILE" | head -n 1)"
    INSTALLED_AT="$(sed -n 's/.*"installed_at"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$STATE_FILE" | head -n 1)"
fi

echo "当前 Folia : ${CURRENT_STACK:-未安装}"
echo "审核版本  : $TARGET_STACK (app $TARGET_APP)"
echo "approved  : ${APPROVED:-未标记}"

if [ -f "$STATE_FILE" ] && [ "$CURRENT_STACK" = "$TARGET_STACK" ]; then
    echo "已经是最新审核版本，无需更新。"
    exit 0
fi
if [ "$CHECK_ONLY" -eq 1 ]; then
    exit 0
fi
if [ -z "$CURRENT_STACK" ]; then
    echo "没有找到已安装状态。请先运行 scripts/folia/install.sh。" >&2
    exit 1
fi
if [ "$APPROVED" != "true" ] && [ "$FORCE" -eq 0 ]; then
    echo "release.json 未标记 approved=true；为避免未审核更新，已停止。" >&2
    echo "确认要更新可加 --force。" >&2
    exit 1
fi
if [ ! -f "$FOLIA_HOME/compose.yaml" ] || [ ! -f "$FOLIA_HOME/.env" ]; then
    echo "找不到 $FOLIA_HOME/compose.yaml 或 .env。" >&2
    exit 1
fi

COMPOSE=()
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "未找到 Docker Compose v2。" >&2
    exit 1
fi

cd "$FOLIA_HOME"
BACKUP=".env.before-$CURRENT_STACK-$(date +%Y%m%d%H%M%S)"
cp .env "$BACKUP"
echo "已备份配置：$FOLIA_HOME/$BACKUP"

restore_env() {
    cp "$BACKUP" .env
    sed -i "s|^FOLIA_STACK_VERSION=.*|FOLIA_STACK_VERSION=$CURRENT_STACK|" .env
}

echo "拉取 $TARGET_STACK 镜像..."
"${COMPOSE[@]}" --env-file .env -f compose.yaml pull

sed -i "s|^FOLIA_STACK_VERSION=.*|FOLIA_STACK_VERSION=$TARGET_STACK|" .env
sed -i "s|^FOLIA_SYNC_VERSION=.*|FOLIA_SYNC_VERSION=$TARGET_SYNC|" .env

echo "重启容器并等待健康检查..."
if ! "${COMPOSE[@]}" --env-file .env -f compose.yaml up -d --wait; then
    echo "更新失败，回滚到 $CURRENT_STACK..." >&2
    restore_env
    "${COMPOSE[@]}" --env-file .env -f compose.yaml pull || true
    "${COMPOSE[@]}" --env-file .env -f compose.yaml up -d --wait || true
    echo "已尝试回滚。请查看：cd \"$FOLIA_HOME\" && docker compose logs --tail=120" >&2
    exit 1
fi

HTTP_PORT="$(sed -n 's/^FOLIA_HTTP_PORT=//p' .env | head -n 1)"
HTTP_PORT="${HTTP_PORT:-18080}"
if ! curl -fsS --max-time 8 "http://127.0.0.1:$HTTP_PORT/healthz" >/dev/null; then
    echo "新版本健康检查失败，回滚到 $CURRENT_STACK..." >&2
    restore_env
    "${COMPOSE[@]}" --env-file .env -f compose.yaml pull || true
    "${COMPOSE[@]}" --env-file .env -f compose.yaml up -d --wait || true
    exit 1
fi

NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
TMP="$(mktemp)"
cat > "$TMP" <<JSON
{
  "port": ${HTTP_PORT},
  "sync_port": ${CURRENT_SYNC:-13000},
  "stack_version": "$TARGET_STACK",
  "app_version": "$TARGET_APP",
  "installed_at": "${INSTALLED_AT:-$NOW}",
  "updated_at": "$NOW"
}
JSON
mv "$TMP" "$STATE_FILE"
chmod 644 "$STATE_FILE"

echo "Folia 已更新到 $TARGET_STACK (app $TARGET_APP)。"
echo "旧配置备份保留在：$FOLIA_HOME/$BACKUP"
