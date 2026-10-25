<?php
/**
 * ============================================================
 * Folia 可选音乐服务 — 状态与地址
 * ============================================================
 *
 * Folia 是独立部署的可选组件，不在 ListenWrite 进程内运行。
 * 安装脚本会写入 data/folia.json；这里只负责读取配置和做健康检查。
 *
 * 配置字段（全部可选）：
 *   port         本机 Docker 网关端口，默认 18080
 *   url          对外访问地址；留空时按当前请求的 Host + port 生成
 *   health_url   服务端健康检查地址；留空时使用 127.0.0.1:port/healthz
 *   stack_version / app_version / installed_at / updated_at
 * ============================================================
 */

function folia_config_file(): string
{
    return __DIR__ . '/../data/folia.json';
}

/**
 * 读取 Folia 配置。文件不存在或损坏时返回未安装状态。
 *
 * @return array{installed:bool,url:string,health_url:string,port:int,sync_port:int,stack_version:string,app_version:string,installed_at:string,updated_at:string}
 */
function folia_config(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'installed' => false,
        'url' => '',
        'health_url' => '',
        'port' => 18080,
        'sync_port' => 13000,
        'stack_version' => '',
        'app_version' => '',
        'installed_at' => '',
        'updated_at' => '',
    ];

    $file = folia_config_file();
    if (!is_file($file)) {
        return $cached = $defaults;
    }

    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return $cached = $defaults;
    }

    $config = array_merge($defaults, $data);
    $port = (int)($config['port'] ?? 18080);
    if ($port < 1 || $port > 65535) {
        $port = 18080;
    }
    $syncPort = (int)($config['sync_port'] ?? 13000);
    if ($syncPort < 1 || $syncPort > 65535) {
        $syncPort = 13000;
    }

    $config['port'] = $port;
    $config['sync_port'] = $syncPort;
    $config['url'] = trim((string)($config['url'] ?? ''));
    $config['health_url'] = trim((string)($config['health_url'] ?? ''));
    $config['stack_version'] = (string)($config['stack_version'] ?? '');
    $config['app_version'] = (string)($config['app_version'] ?? '');
    $config['installed_at'] = (string)($config['installed_at'] ?? '');
    $config['updated_at'] = (string)($config['updated_at'] ?? '');
    $config['installed'] = $config['installed_at'] !== '';

    return $cached = $config;
}

/**
 * 当前请求访问到的服务器主机名（不含端口）。
 * 这样从手机/平板访问时，Folia 地址会自动变成同一个局域网 IP，而不是 127.0.0.1。
 */
function folia_request_host(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === '') {
        $host = '127.0.0.1';
    }
    return $host;
}

function folia_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * 浏览器应打开的 Folia 地址。
 */
function folia_public_url(): string
{
    $config = folia_config();
    if ($config['url'] !== '') {
        return rtrim($config['url'], '/');
    }

    $scheme = folia_is_https() ? 'https' : 'http';
    return $scheme . '://' . folia_request_host() . ':' . $config['port'];
}

/**
 * 服务端健康检查地址。默认走 127.0.0.1，避免局域网回环/防火墙干扰。
 */
function folia_health_url(): string
{
    $config = folia_config();
    if ($config['health_url'] !== '') {
        return $config['health_url'];
    }
    return 'http://127.0.0.1:' . $config['port'] . '/healthz';
}

/**
 * 轻量健康检查：只用于判断 Folia 是否已经启动。
 */
function folia_is_running(): bool
{
    if (!folia_config()['installed']) {
        return false;
    }

    $url = folia_health_url();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT_MS => 700,
            CURLOPT_TIMEOUT_MS => 1500,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ListenWrite-Folia-Status/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        return $errno === 0 && $code >= 200 && $code < 400;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 1.5,
            'ignore_errors' => true,
            'user_agent' => 'ListenWrite-Folia-Status/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return false;
    }
    $headers = $http_response_header ?? [];
    if (!isset($headers[0])) {
        return false;
    }
    if (preg_match('/\s(\d{3})\s/', (string)$headers[0], $m)) {
        $code = (int)$m[1];
        return $code >= 200 && $code < 400;
    }
    return true;
}

/**
 * @return array{installed:bool,running:bool,url:string,port:int,sync_port:int,stack_version:string,app_version:string,installed_at:string,updated_at:string}
 */
function folia_state(): array
{
    $config = folia_config();
    return [
        'installed' => $config['installed'],
        'running' => $config['installed'] ? folia_is_running() : false,
        'url' => $config['installed'] ? folia_public_url() : '',
        'port' => $config['port'],
        'sync_port' => $config['sync_port'],
        'stack_version' => $config['stack_version'],
        'app_version' => $config['app_version'],
        'installed_at' => $config['installed_at'],
        'updated_at' => $config['updated_at'],
    ];
}

/**
 * 读取我们自己的审核清单（scripts/folia/release.json）。
 * 网页端只认这份清单，不直接跟随上游 latest。
 */
function folia_release(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'approved' => false,
        'app_version' => '',
        'stack_version' => '',
        'notes' => '',
    ];
    $file = __DIR__ . '/../../scripts/folia/release.json';
    if (!is_file($file)) {
        return $cached = $defaults;
    }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        return $cached = $defaults;
    }
    return $cached = array_merge($defaults, $data);
}

/**
 * 是否存在“已经审核通过、且比本机新”的 Folia 版本。
 * 只读文件，不做网络请求；主界面用它显示“可更新”标记。
 *
 * @return array{available:bool,current:string,latest:string,approved:bool,notes:string}
 */
function folia_update_available(): array
{
    $config = folia_config();
    $release = folia_release();
    $current = (string)$config['stack_version'];
    $latest = (string)$release['stack_version'];
    $approved = ($release['approved'] ?? false) === true;
    $available = $config['installed']
        && $approved
        && $current !== ''
        && $latest !== ''
        && version_compare($latest, $current, '>');

    return [
        'available' => $available,
        'current' => $current,
        'latest' => $latest,
        'approved' => $approved,
        'notes' => (string)$release['notes'],
    ];
}

