<?php
require_once __DIR__ . '/inc/maintenance.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(lwReadUpdateStatus(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
