<?php
/**
 * 图集公开 API
 * GET gallery_api.php?class_id=xxx[&page=1&per_page=10][&apikey=xxx]
 * 密钥存储于 data/settings.json 的 gallery_api_key_{classId} 字段。
 * 班级未设置密钥则免验证；设置了则需要 ?apikey=xxx 参数。
 * 基于 IP+日期 hash 轮换排序，避免同设备重复输出。
 */
require_once 'inc/db.php';

$classId = trim((string)($_GET['class_id'] ?? ''));
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
    $providedKey = trim((string)($_GET['apikey'] ?? ''));
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

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int)($_GET['per_page'] ?? 10)));

// 防同设备重复：基于 IP + 日期生成偏移量
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$dateKey = date('Y-m-d');
$seed = crc32($clientIp . $dateKey);
mt_srand($seed);

// 创建副本并随机打乱
$shuffled = $gallery;
for ($i = count($shuffled) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
}

$total = count($shuffled);
$offset = ($page - 1) * $perPage;
$items = array_slice($shuffled, $offset, $perPage);

// 构建返回数据
$result = [];
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

foreach ($items as $item) {
    $result[] = [
        'image_url' => $baseUrl . 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($item['image'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'uploaded_at' => (string)($item['uploaded_at'] ?? ''),
    ];
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300'); // 5分钟缓存
echo json_encode([
    'items' => $result,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'has_more' => ($offset + $perPage) < $total,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
