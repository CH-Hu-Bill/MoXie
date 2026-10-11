<?php
/**
 * ============================================================
 * 内网地址探测（本地部署：向用户展示"内网主网址"）
 * ============================================================
 * 服务运行在用户自己的电脑上，手机/其它设备需通过局域网地址访问。
 * 本文件提供：探测本机局域网 IPv4 + 组装 http://<ip>:<port>。
 *
 * 判定顺序（尽量不误报）：
 *   1) 若本次请求经局域网 IP 访问（HTTP_HOST 为 IPv4 且非本地回环）→ 直接采用；
 *   2) UDP 连接探测默认出口网卡 IP（不真正发包，离线也可用）；
 *   3) 主机名解析 gethostbyname(gethostname())；
 *   4) 系统命令兜底（Windows ipconfig / Linux hostname -I）。
 * 全部失败则回退 127.0.0.1。
 */

if (!function_exists('lw_is_usable_lan_ip')) {
    /** 是否为可用的局域网 IPv4（排除回环 / 链路本地） */
    function lw_is_usable_lan_ip($ip) {
        if (!is_string($ip) || $ip === '') return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        if (strpos($ip, '127.') === 0) return false;
        if (strpos($ip, '169.254.') === 0) return false;
        if ($ip === '0.0.0.0') return false;
        return true;
    }
}

if (!function_exists('lw_lan_ip')) {
    function lw_lan_ip() {
        static $cached = null;
        if ($cached !== null) return $cached;

        // 1) 经局域网 IP 访问时，HTTP_HOST 是最可靠（一定可达）的地址
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        $h = trim(preg_replace('/:\d+$/', '', $host), '[]');
        if (lw_is_usable_lan_ip($h)) return $cached = $h;

        // 1.5) 跨请求文件缓存（避免每次页面加载都 shell 探测；TTL 10 分钟）
        $cacheFile = __DIR__ . '/../data/.lan_ip_cache.json';
        if (is_file($cacheFile)) {
            $c = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && isset($c['ip'], $c['ts'])
                && (time() - (int)$c['ts']) < 600 && lw_is_usable_lan_ip($c['ip'])) {
                return $cached = $c['ip'];
            }
        }

        // 2) 收集所有候选 IP，按网段打分择优（避免误选 Docker/WSL 等虚拟网卡）
        $cands = [];
        $add = function ($ip) use (&$cands) {
            $ip = trim((string)$ip, " \t\r\n");
            if (lw_is_usable_lan_ip($ip) && !in_array($ip, $cands, true)) $cands[] = $ip;
        };

        // 2a) Linux/macOS：hostname -I
        $out = @shell_exec('hostname -I 2>/dev/null');
        if (is_string($out) && $out !== '') {
            foreach (preg_split('/\s+/', trim($out)) as $c) $add($c);
        }
        // 2b) 主机名解析
        $add(@gethostbyname(@gethostname()));
        // 2c) Windows：ipconfig
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $out = @shell_exec('ipconfig');
            if (is_string($out) && $out !== '' && preg_match_all('/IPv4[^:：]*[:：]\s*([0-9.]+)/i', $out, $m)) {
                foreach ($m[1] as $c) $add($c);
            }
        }
        // 2d) UDP 连接探测默认出口网卡（不真正发包）
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 2);
        if ($sock) {
            $name = @stream_socket_get_name($sock, false);
            @fclose($sock);
            if ($name) $add(preg_replace('/:\d+$/', '', $name));
        }

        $result = '127.0.0.1';
        if ($cands) {
            // 打分：192.168 > 10 > 其它公网/私有 > 172.16-31（常见 Docker/WSL 网段）
            $score = function ($ip) {
                if (strpos($ip, '192.168.') === 0) return 100;
                if (strpos($ip, '10.') === 0) return 90;
                if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip)) return 30;
                return 60;
            };
            usort($cands, function ($a, $b) use ($score) { return $score($b) - $score($a); });
            $result = $cands[0];
        }

        @file_put_contents($cacheFile, json_encode(['ip' => $result, 'ts' => time()]));
        return $cached = $result;
    }
}

if (!function_exists('lw_lan_port')) {
    /** 当前服务端口（默认 8000，与一键脚本一致） */
    function lw_lan_port() {
        $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 0;
        return $port > 0 ? $port : 8000;
    }
}

if (!function_exists('lw_lan_url')) {
    /** 内网主网址，如 http://192.168.1.23:8000（80/443 端口省略） */
    function lw_lan_url() {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $port = lw_lan_port();
        $ip = lw_lan_ip();
        $suffix = (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) ? '' : (':' . $port);
        return $scheme . '://' . $ip . $suffix;
    }
}

if (!function_exists('lw_class_en_font')) {
    /**
     * 班级英文显示字体：'andika' 或 ''(默认)。
     * 读取 settings.json 的 font_{classId}。
     */
    function lw_class_en_font($classId) {
        $classId = (string)$classId;
        if ($classId === '' || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $classId)) return '';
        require_once __DIR__ . '/db.php';
        $settings = Database::getSettings();
        return (($settings['font_' . $classId] ?? '') === 'andika') ? 'andika' : '';
    }
}
