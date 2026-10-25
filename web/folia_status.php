<?php
/**
 * Folia 服务状态 JSON（供 music.php 轮询/重新检测）
 * 只返回状态，不返回安装路径或敏感信息。
 */
require_once __DIR__ . '/inc/folia.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$state = folia_state();
$update = folia_update_available();
echo json_encode([
    'ok' => true,
    'installed' => (bool)$state['installed'],
    'running' => (bool)$state['running'],
    'url' => (string)$state['url'],
    'app_version' => (string)$state['app_version'],
    'stack_version' => (string)$state['stack_version'],
    'update_available' => (bool)$update['available'],
    'latest_version' => (string)$update['latest'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
