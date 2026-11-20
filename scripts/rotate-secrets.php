<?php
/**
 * 轮换 web/inc/config.php 的 app_secret 与管理员密码。
 *
 * 用法：php scripts/rotate-secrets.php
 * 输出：新的管理员密码（只显示一次），其余配置保持不变。
 */

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'config.php';

$defaults = [
    'app_secret' => '',
    'allowed_origins' => [],
    'trusted_proxies' => ['127.0.0.1', '::1'],
    'admin_password' => '',
    'admin_password_hash' => '',
    'admin_session_ttl' => 1800,
];

$config = [];
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) $config = $loaded;
}
$config = array_merge($defaults, $config);

$secret = bin2hex(random_bytes(32));
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 20; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$config['app_secret'] = $secret;
$config['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
$config['admin_password'] = '';
// 安全默认：只允许同源；需要跨域时再显式添加来源
$config['allowed_origins'] = [];

$php = "<?php\n"
    . "/**\n"
    . " * 本地配置（gitignored）。由 scripts/rotate-secrets.php 生成/更新。\n"
    . " */\n"
    . "return " . var_export($config, true) . ";\n";

$dir = dirname($configPath);
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建目录：$dir\n");
    exit(1);
}

if (file_put_contents($configPath, $php) === false) {
    fwrite(STDERR, "无法写入：$configPath\n");
    exit(1);
}
@chmod($configPath, 0600);

echo "已更新：$configPath\n";
echo "新的管理员密码（只显示这一次，请立即保存）：\n";
echo $password . "\n";
