<?php
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/maintenance.php';

lwStartSession();
if (empty($_SESSION['admin_authed'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['stage' => 'forbidden', 'message' => '需要管理员登录']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(lwReadUpdateStatus(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
