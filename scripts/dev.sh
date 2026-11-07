#!/usr/bin/env bash
# ============================================================
# 本地开发 / 内网测试启动脚本（Linux / macOS / Termux）
#
# 用法：
#   ./scripts/dev.sh              # 前台启动，Ctrl+C 停止
#   ./scripts/dev.sh start        # 后台启动
#   ./scripts/dev.sh stop         # 停止后台服务
#   ./scripts/dev.sh restart      # 重启后台服务
#   ./scripts/dev.sh status       # 查看运行状态
#   ./scripts/dev.sh log [行数]   # 查看日志；log -f 持续跟随
#   PORT=8080 ./scripts/dev.sh    # 指定端口
# ============================================================
set -euo pipefail

SOURCE="${BASH_SOURCE[0]}"
while [ -L "$SOURCE" ]; do
    DIR="$(cd -P "$(dirname "$SOURCE")" >/dev/null 2>&1 && pwd)"
    SOURCE="$(readlink "$SOURCE")"
    [[ "$SOURCE" != /* ]] && SOURCE="$DIR/$SOURCE"
done
SCRIPT_DIR="$(cd -P "$(dirname "$SOURCE")" >/dev/null 2>&1 && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WEB_DIR="$REPO_DIR/web"

PORT="${PORT:-8000}"
WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"
PID_FILE="${MOXIE_PID_FILE:-${TMPDIR:-/tmp}/moxie-web-${PORT}.pid}"
LOG_FILE="${MOXIE_LOG_FILE:-${TMPDIR:-/tmp}/moxie-web-${PORT}.log}"

# 没有 TMPDIR 或不可写时，回退到仓库内 .run/
if ! mkdir -p "$(dirname "$PID_FILE")" 2>/dev/null || [ ! -w "$(dirname "$PID_FILE")" ]; then
    mkdir -p "$REPO_DIR/.run"
    PID_FILE="$REPO_DIR/.run/moxie-web-${PORT}.pid"
    LOG_FILE="$REPO_DIR/.run/moxie-web-${PORT}.log"
fi

lan_ip=""
if command -v hostname >/dev/null 2>&1; then
    # 优先取 IPv4，避免拿到 IPv6 回环地址
    lan_ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -m1 -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$')" || true
fi
if [ -z "${lan_ip:-}" ] && command -v ip >/dev/null 2>&1; then
    lan_ip="$(ip route get 1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')" || true
fi
[ -z "${lan_ip:-}" ] && lan_ip="<本机内网IP>"

print_urls() {
    cat <<EOF
==============================================
 ListenWrite 本地服务
 本机访问 : http://127.0.0.1:${PORT}
 内网访问 : http://${lan_ip}:${PORT}
 日志     : ${LOG_FILE}
 状态     : moxie status
 停止     : moxie stop
==============================================
EOF
}

is_running() {
    [ -f "$PID_FILE" ] || return 1
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

port_in_use() {
    if (exec 3<>"/dev/tcp/127.0.0.1/${PORT}") 2>/dev/null; then
        exec 3>&-
        return 0
    fi
    return 1
}

http_ready() {
    local code
    code="$(curl -sS --max-time 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/" 2>/dev/null || true)"
    [ -n "$code" ] && [ "$code" != "000" ]
}

start() {
    if is_running; then
        echo "服务已在运行（PID $(cat "$PID_FILE")）"
        print_urls
        return 0
    fi
    rm -f "$PID_FILE"

    if port_in_use; then
        echo "端口 ${PORT} 已被其他程序占用。"
        echo "请先停止占用程序，或改用：PORT=8080 ./scripts/dev.sh start"
        return 1
    fi

    cd "$WEB_DIR"
    : > "$LOG_FILE"
    # 内置服务器默认单进程，设置 workers 支持多设备并发（PHP >= 7.4，仅 Unix）
    # -d 放宽上传限制以支持「超级导入」大 zip
    nohup env PHP_CLI_SERVER_WORKERS="$WORKERS" \
        php -d upload_max_filesize=300M -d post_max_size=310M \
            -d memory_limit=256M -d max_execution_time=120 \
            -S 0.0.0.0:"${PORT}" router.php >>"$LOG_FILE" 2>&1 &
    local pid=$!
    echo "$pid" > "$PID_FILE"

    local i
    for i in $(seq 1 50); do
        if http_ready; then
            echo "服务已启动（PID ${pid}）"
            print_urls
            return 0
        fi
        if ! kill -0 "$pid" 2>/dev/null; then
            echo "启动失败，PHP 进程已退出。日志："
            tail -n 40 "$LOG_FILE" 2>/dev/null || true
            rm -f "$PID_FILE"
            return 1
        fi
        sleep 0.2
    done

    echo "启动超时，服务可能仍在初始化。日志："
    tail -n 40 "$LOG_FILE" 2>/dev/null || true
    print_urls
    return 1
}

stop() {
    if ! is_running; then
        rm -f "$PID_FILE"
        echo "服务未运行。"
        return 0
    fi

    local pid
    pid="$(cat "$PID_FILE")"
    kill "$pid" 2>/dev/null || true
    local i
    for i in $(seq 1 25); do
        if ! kill -0 "$pid" 2>/dev/null; then
            break
        fi
        sleep 0.2
    done
    if kill -0 "$pid" 2>/dev/null; then
        kill -9 "$pid" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
    echo "服务已停止（PID ${pid}）。"
}

status() {
    if is_running; then
        echo "服务运行中（PID $(cat "$PID_FILE")）"
        print_urls
    else
        rm -f "$PID_FILE"
        echo "服务未运行。"
    fi
}

show_log() {
    if [ ! -f "$LOG_FILE" ]; then
        echo "暂无日志：${LOG_FILE}"
        return 0
    fi
    if [ "${1:-}" = "-f" ]; then
        tail -n 80 -f "$LOG_FILE"
    else
        tail -n "${1:-80}" "$LOG_FILE"
    fi
}

foreground() {
    cd "$WEB_DIR"
    print_urls
    echo "前台模式：按 Ctrl + C 停止"
    export PHP_CLI_SERVER_WORKERS="$WORKERS"
    exec php -d upload_max_filesize=300M -d post_max_size=310M \
        -d memory_limit=256M -d max_execution_time=120 \
        -S 0.0.0.0:"${PORT}" router.php
}

case "${1:-}" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        stop
        start
        ;;
    status)
        status
        ;;
    log)
        shift
        show_log "${1:-80}"
        ;;
    "" )
        foreground
        ;;
    -h|--help|help)
        sed -n '2,18p' "$0"
        ;;
    *)
        echo "未知命令：$1"
        echo "可用：start | stop | restart | status | log [-f|行数]"
        exit 1
        ;;
esac
