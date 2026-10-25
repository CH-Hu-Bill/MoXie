#!/usr/bin/env bash
# ============================================================
# Folia Web 可选组件 — 安装/首次启动
#
# 用法（在 ListenWrite 根目录）：
#   bash scripts/folia/install.sh
#   bash scripts/folia/install.sh --skip-pull
#
# 需要：Docker Engine + Docker Compose v2、curl、openssl
# 首次安装需要联网拉取镜像；安装脚本不会由网页自动执行。
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
RELEASE_FILE="$SCRIPT_DIR/release.json"

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

FOLIA_HOME="${FOLIA_HOME:-$ROOT/folia-runtime}"
HTTP_PORT="${FOLIA_HTTP_PORT:-18080}"
SYNC_PORT="${FOLIA_SYNC_PORT:-13000}"
STACK_VERSION="${FOLIA_STACK_VERSION:-$(json_value stack_version 1.2.1)}"
SYNC_VERSION="${FOLIA_SYNC_VERSION:-$(json_value sync_version 1.0.0)}"
APP_VERSION="$(json_value app_version 0.7.22)"
SKIP_PULL=0

for arg in "$@"; do
    case "$arg" in
        --skip-pull) SKIP_PULL=1 ;;
        -h|--help)
            sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "未知参数：$arg" >&2
            exit 2
            ;;
    esac
done

echo "=============================================="
echo " Folia 可选组件安装"
echo " 安装目录 : $FOLIA_HOME"
echo " 版本     : $STACK_VERSION (app $APP_VERSION)"
echo " Web 端口 : $HTTP_PORT"
echo " Sync 端口: $SYNC_PORT"
echo "=============================================="

if ! command -v docker >/dev/null 2>&1; then
    echo "未找到 Docker。请先安装 Docker Engine 和 Docker Compose v2。" >&2
    echo "Ubuntu: https://docs.docker.com/engine/install/ubuntu/" >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo "Docker 已安装，但守护进程没有运行，或当前用户没有权限访问 Docker。" >&2
    echo "请启动 Docker，并把当前用户加入 docker 组后重新登录。" >&2
    exit 1
fi

COMPOSE=()
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "未找到 Docker Compose v2。请安装 docker-compose-plugin。" >&2
    exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "未找到 curl，健康检查需要它。" >&2
    exit 1
fi

mkdir -p "$FOLIA_HOME/data/sync"
cp -f "$SCRIPT_DIR/compose.yaml" "$FOLIA_HOME/compose.yaml"

if [ ! -f "$FOLIA_HOME/.env" ]; then
    cp "$SCRIPT_DIR/.env.example" "$FOLIA_HOME/.env"
    if command -v openssl >/dev/null 2>&1; then
        TOKEN="$(openssl rand -hex 24)"
    else
        TOKEN="$(date +%s%N)-$RANDOM-$RANDOM"
    fi
    sed -i "s|^SYNC_TOKEN=.*|SYNC_TOKEN=$TOKEN|" "$FOLIA_HOME/.env"
    sed -i "s|^FOLIA_STACK_VERSION=.*|FOLIA_STACK_VERSION=$STACK_VERSION|" "$FOLIA_HOME/.env"
    sed -i "s|^FOLIA_SYNC_VERSION=.*|FOLIA_SYNC_VERSION=$SYNC_VERSION|" "$FOLIA_HOME/.env"
    sed -i "s|^FOLIA_HTTP_PORT=.*|FOLIA_HTTP_PORT=$HTTP_PORT|" "$FOLIA_HOME/.env"
    sed -i "s|^FOLIA_SYNC_PORT=.*|FOLIA_SYNC_PORT=$SYNC_PORT|" "$FOLIA_HOME/.env"
    chmod 600 "$FOLIA_HOME/.env"
    echo "已生成 $FOLIA_HOME/.env（包含随机 Sync Token）"
else
    echo "使用已有配置：$FOLIA_HOME/.env"
fi

cd "$FOLIA_HOME"

if [ "$SKIP_PULL" -eq 0 ]; then
    echo "拉取 Folia 镜像（首次可能需要几分钟）..."
    "${COMPOSE[@]}" --env-file .env -f compose.yaml pull
else
    echo "跳过镜像拉取（--skip-pull）"
fi

echo "启动容器并等待健康检查..."
if ! "${COMPOSE[@]}" --env-file .env -f compose.yaml up -d --wait; then
    echo "Folia 启动失败，最近的容器日志：" >&2
    "${COMPOSE[@]}" --env-file .env -f compose.yaml logs --no-color --tail=120 >&2 || true
    exit 1
fi

if ! curl -fsS --max-time 8 "http://127.0.0.1:$HTTP_PORT/healthz" >/dev/null; then
    echo "容器已启动，但网关健康检查失败。请查看：" >&2
    echo "  cd \"$FOLIA_HOME\" && docker compose logs --tail=120" >&2
    exit 1
fi

mkdir -p "$ROOT/web/data"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
TMP="$(mktemp)"
cat > "$TMP" <<JSON
{
  "port": $HTTP_PORT,
  "sync_port": $SYNC_PORT,
  "stack_version": "$STACK_VERSION",
  "app_version": "$APP_VERSION",
  "installed_at": "$NOW",
  "updated_at": "$NOW"
}
JSON
mv "$TMP" "$ROOT/web/data/folia.json"
chmod 644 "$ROOT/web/data/folia.json"

echo
echo "Folia 已就绪。"
echo "  本机访问 : http://127.0.0.1:$HTTP_PORT"
echo "  局域网   : http://<这台电脑的局域网IP>:$HTTP_PORT"
echo "  状态文件 : $ROOT/web/data/folia.json"
echo "  停止服务 : cd \"$FOLIA_HOME\" && docker compose down"
echo "  查看日志 : cd \"$FOLIA_HOME\" && docker compose logs -f"
echo
echo "现在可以回到 ListenWrite 主界面，点击「Folia 音乐」卡片。"
