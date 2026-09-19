<?php
/**
 * PHP 内置服务器路由脚本（仅用于本地运行/内网测试）
 *
 * 用法（在 web/ 目录）:
 *   php -S 0.0.0.0:8000 router.php
 *
 * PHP 内置服务器不会读取 .htaccess，因此必须由本脚本拦截
 * /data/、/inc/、/bin/、/vendor/ 以及 *.json / *.lock / 点文件等，
 * 防止内网设备直链下载运行数据或读取源码。
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode($uri ?: '/');
$trimmed = rtrim($path, '/');
$parts   = explode('/', ltrim($trimmed, '/'));
$first   = $parts[0] ?? '';
$base    = basename($trimmed);

$blockedDirs  = ['data', 'inc', 'bin', 'vendor'];
$blockedExt   = ['json', 'lock', 'log', 'md', 'sql', 'bak', 'ini', 'yml', 'yaml'];
$blockedFiles = ['router.php', 'composer.json', 'composer.lock', 'phpunit.xml'];

$deny = false;
if (in_array($first, $blockedDirs, true)) {
    $deny = true;
}
if ($base !== '' && $base[0] === '.') {              // 点文件（.htaccess/.git 等）
    $deny = true;
}
if ($base !== '' && preg_match('/\.([a-z0-9]+)$/i', $base, $m)
        && in_array(strtolower($m[1]), $blockedExt, true)) {
    $deny = true;
}
if (in_array($base, $blockedFiles, true)) {
    $deny = true;
}

if ($deny) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

// 字体/第三方库：长缓存（内容不变），避免每个页面重复下载 5MB+ 字体导致加载慢
if ($path !== '/' && is_file(__DIR__ . $path) && preg_match('#^/(fonts|lib)/#', $path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'map' => 'application/json', 'png' => 'image/png', 'svg' => 'image/svg+xml',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize(__DIR__ . $path));
    readfile(__DIR__ . $path);
    return true;
}

// 真实存在的文件交给内置服务器处理（PHP 会执行，静态文件正常输出）
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// 目录默认交给其 index.php
if ($path !== '/' && is_dir($file) && is_file($file . '/index.php')) {
    require $file . '/index.php';
    return true;
}

// 根路径默认 index.php
if ($path === '/' || $path === '') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
return true;
