<?php

require_once 'inc/db.php';
require_once 'inc/security.php';

function uploadJsonError($message, $status = 400) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$classId = $_SERVER['REQUEST_METHOD'] === 'POST' ? reqPost('class_id') : reqGet('class_id');
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) uploadJsonError('班级参数无效', 400);
$classes = Database::getClasses();
if (!isset($classes[$classId])) uploadJsonError('班级不存在', 404);

// GET 请求（查看图片）无需鉴权，外部 API 可直接引用
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filename = reqGet('file');
    $path = Database::getUploadedImagePath($classId, $filename);
    if ($path === null) uploadJsonError('图片不存在', 404);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    // 文件名含随机数，删除后 URL 即失效（不会命中旧缓存），可放心长缓存
    header('Cache-Control: private, max-age=2592000'); // 30 天
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') uploadJsonError('请求方法不支持', 405);
// POST 请求（上传图片）需要班级鉴权
if (!isClassAuthenticated($classId, $classes[$classId])) uploadJsonError('班级鉴权已失效，请重新进入班级', 403);
requireCsrf();
if (!isset($_FILES['image']) || !is_array($_FILES['image'])) uploadJsonError('请选择图片');
$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? '图片超过服务器上传限制' : '图片上传失败';
    uploadJsonError($message, 400);
}
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > Database::UPLOAD_MAX_BYTES) uploadJsonError('图片最大允许 12MB', 413);
if (!is_uploaded_file($file['tmp_name'])) uploadJsonError('上传文件无效');

try {
    $filename = Database::saveUploadedImage($classId, $file['tmp_name']);
} catch (RuntimeException $e) {
    $code = str_contains($e->getMessage(), '像素') || str_contains($e->getMessage(), '12MB') ? 413 : 500;
    uploadJsonError($e->getMessage(), $code);
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($filename)], JSON_UNESCAPED_UNICODE);
