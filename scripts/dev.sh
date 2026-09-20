#!/usr/bin/env bash
# ============================================================
# 本地开发 / 内网测试启动脚本（Linux / macOS）
# 仅在开发机使用；最终用户使用 Windows 一键脚本。
#
# 用法：
#   ./dev.sh              # 默认端口 8000
#   PORT=8080 ./dev.sh    # 指定端口
# ============================================================
set -euo pipefail

cd "$(dirname "$0")/../web"

PORT="${PORT:-8000}"
WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"

lan_ip=""
if command -v hostname >/dev/null 2>&1; then
    # 优先取 IPv4，避免拿到 IPv6 回环地址
    lan_ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -m1 -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$')" || true
fi
if [ -z "${lan_ip:-}" ] && command -v ip >/dev/null 2>&1; then
    lan_ip="$(ip route get 1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')" || true
fi
[ -z "${lan_ip:-}" ] && lan_ip="<本机内网IP>"

echo "=============================================="
echo " ListenWrite 本地服务"
echo " 本机访问 : http://127.0.0.1:${PORT}"
echo " 内网访问 : http://${lan_ip}:${PORT}"
echo " 停止     : Ctrl + C"
echo "=============================================="

# 内置服务器默认单进程，设置 WORKERS 支持多设备并发（PHP >= 7.4，仅 Unix）
# -d 放宽上传限制以支持「超级导入」大 zip
export PHP_CLI_SERVER_WORKERS="${WORKERS}"
exec php -d upload_max_filesize=300M -d post_max_size=310M -d memory_limit=256M -d max_execution_time=120 \
    -S 0.0.0.0:"${PORT}" router.php
