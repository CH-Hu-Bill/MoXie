<?php
/**
 * 图集公开 API
 * GET gallery_api.php?class_id=xxx[&apikey=xxx]
 * 每次请求随机返回图集中一张图片及其描述；同一设备（IP）连续两次不会返回同一张，
 * 刷新后再请求会得到另一张。
 * 密钥存储于 data/settings.json 的 gallery_api_key_{classId} 字段，可在班级设置页配置；
 * 班级未设置密钥则免验证。
 */
require_once 'inc/db.php';

$classId = trim(reqGet('class_id'));
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => '无效的班级ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 班级是否存在
$classes = Database::getClasses();
if (!isset($classes[$classId])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => '班级不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// API 密钥校验：从 settings.json 读取，独立于班级密码，无需登录
$settings = Database::getSettings();
$apiKey = trim((string)($settings['gallery_api_key_' . $classId] ?? ''));
if ($apiKey !== '') {
    $providedKey = trim(reqGet('apikey'));
    if (!hash_equals($apiKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'API密钥无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$gallery = Database::getClassData($classId, 'gallery');
if (!is_array($gallery)) $gallery = [];

// 自愈：过滤丢失的图片，并清理 gallery.json
$valid = [];
$cleaned = false;
foreach ($gallery as $item) {
    $imgFile = $item['image'] ?? '';
    if ($imgFile !== '' && Database::getUploadedImagePath($classId, $imgFile) !== null) {
        $valid[] = $item;
    } else {
        $cleaned = true;
    }
}
if ($cleaned) {
    Database::saveClassData($classId, 'gallery', $valid);
    $gallery = $valid;
}

$total = count($gallery);
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store'); // 每次随机，禁止缓存

if ($total === 0) {
    echo json_encode([
        'image_url' => null,
        'type' => 'static',
        'description' => '',
        'uploaded_at' => '',
        'total' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 同设备不连续重复：按 IP+班级 记录上次返回的图片（用图片文件名为身份）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$pickKey = substr(sha1($classId . ':' . $clientIp), 0, 16);

$picked = null;
Database::update('gallery_random.json', function($data) use ($pickKey, $gallery, $total, &$picked) {
    if (!is_array($data)) $data = [];
    // 清理超过 30 天未更新的 key
    $now = time();
    foreach ($data as $k => $rec) {
        if (is_array($rec) && ($now - (int)($rec['_ts'] ?? 0)) > 2592000) unset($data[$k]);
    }
    $lastFile = isset($data[$pickKey]) && is_array($data[$pickKey]) ? (string)($data[$pickKey]['file'] ?? '') : '';
    // 随机选取；图集多于一张时排除上次那张
    $candidates = $gallery;
    if ($total > 1 && $lastFile !== '') {
        $filtered = array_values(array_filter($gallery, function($it) use ($lastFile) {
            return ($it['image'] ?? '') !== $lastFile;
        }));
        if (count($filtered) > 0) $candidates = $filtered;
    }
    $picked = $candidates[random_int(0, count($candidates) - 1)];
    $data[$pickKey] = ['file' => (string)($picked['image'] ?? ''), '_ts' => $now];
    return $data;
});

$pickedFile = (string)($picked['image'] ?? '');
$pickedExt = strtolower(pathinfo($pickedFile, PATHINFO_EXTENSION));

echo json_encode([
    'image_url' => $baseUrl . 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($pickedFile),
    'type' => $pickedExt === 'gif' ? 'gif' : ($pickedExt === 'mp4' ? 'mp4' : 'static'),
    'description' => (string)($picked['description'] ?? ''),
    'uploaded_at' => (string)($picked['uploaded_at'] ?? ''),
    'total' => $total,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
