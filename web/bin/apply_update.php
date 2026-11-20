<?php
/**
 * 手动/离线更新入口：
 *   php web/bin/apply_update.php --zip=path/to/ListenWrite-web-2.0.2.zip --version=2.0.2 [--sha=<64hex>]
 *
 * 与后台一键更新共用同一套备份/迁移/替换/回滚逻辑。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/update_apply.php';

$zip = '';
$version = '';
$sha = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--zip=') === 0) $zip = substr($arg, 6);
    if (strpos($arg, '--version=') === 0) $version = substr($arg, 10);
    if (strpos($arg, '--sha=') === 0) $sha = substr($arg, 6);
}
if ($zip === '' || $version === '') {
    fwrite(STDERR, "用法：php web/bin/apply_update.php --zip=<zip> --version=<x.y.z> [--sha=<64hex>]\n");
    exit(1);
}
if (!preg_match('/\A\d+\.\d+\.\d+\z/', $version)) {
    fwrite(STDERR, "版本号格式错误\n");
    exit(1);
}
if (!is_file($zip)) {
    fwrite(STDERR, "更新包不存在：$zip\n");
    exit(1);
}
if ($sha === '') $sha = (string)hash_file('sha256', $zip);

$result = lwApplyWebUpdate([
    'version' => $version,
    'local_zip' => $zip,
    'local_sha' => $sha,
]);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
