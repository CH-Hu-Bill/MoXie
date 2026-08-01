<?php

require_once 'inc/db.php';
require_once 'inc/security.php';

const UPLOAD_MAX_BYTES = 12582912;
const UPLOAD_MAX_PIXELS = 25000000;
const UPLOAD_MAX_EDGE = 1600;

function uploadJsonError($message, $status = 400) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$classId = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['class_id'] ?? '') : ($_GET['class_id'] ?? ''));
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) uploadJsonError('班级参数无效', 400);
$classes = Database::getClasses();
if (!isset($classes[$classId])) uploadJsonError('班级不存在', 404);

$classDirectory = Database::getUploadsDirectory($classId);

// GET 请求（查看图片）无需鉴权，外部 API 可直接引用
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filename = (string) ($_GET['file'] ?? '');
    if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/D', $filename, $match)) uploadJsonError('文件参数无效', 400);
    $path = $classDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) uploadJsonError('图片不存在', 404);
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[$match[1]]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
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
if (($file['size'] ?? 0) <= 0 || $file['size'] > UPLOAD_MAX_BYTES) uploadJsonError('图片最大允许 12MB', 413);
if (!is_uploaded_file($file['tmp_name'])) uploadJsonError('上传文件无效');

$info = @getimagesize($file['tmp_name']);
$allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
if (!$info || !isset($allowed[$info[2]])) uploadJsonError('仅支持 JPEG、PNG 或 WebP 图片');
$width = (int) $info[0];
$height = (int) $info[1];
if ($width < 1 || $height < 1 || $width > intdiv(UPLOAD_MAX_PIXELS, $height)) uploadJsonError('图片像素过大，最多 2500 万像素', 413);
if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) uploadJsonError('服务器未启用 GD，无法安全处理图片，请联系管理员', 503);

$loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
if (!function_exists($loaders[$info[2]])) uploadJsonError('服务器 GD 不支持该图片格式', 503);
$source = @$loaders[$info[2]]($file['tmp_name']);
if (!$source) uploadJsonError('图片内容损坏或无法解码');
$scale = min(1, UPLOAD_MAX_EDGE / max($width, $height));
$targetWidth = max(1, (int) round($width * $scale));
$targetHeight = max(1, (int) round($height * $scale));
$target = imagecreatetruecolor($targetWidth, $targetHeight);
if (!$target) { imagedestroy($source); uploadJsonError('图片处理失败', 500); }
if ($info[2] !== IMAGETYPE_JPEG) {
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefill($target, 0, 0, $transparent);
}
if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
    imagedestroy($source); imagedestroy($target); uploadJsonError('图片缩放失败', 500);
}

if (!is_dir($classDirectory) && !mkdir($classDirectory, 0750, true) && !is_dir($classDirectory)) {
    imagedestroy($source); imagedestroy($target); uploadJsonError('上传目录不可用', 500);
}
$extension = $allowed[$info[2]];
$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$path = $classDirectory . DIRECTORY_SEPARATOR . $filename;
$writers = [IMAGETYPE_JPEG => function($image, $path) { return imagejpeg($image, $path, 82); }, IMAGETYPE_PNG => function($image, $path) { return imagepng($image, $path, 7); }, IMAGETYPE_WEBP => function($image, $path) { return imagewebp($image, $path, 82); }];
$saved = $writers[$info[2]]($target, $path);
imagedestroy($source);
imagedestroy($target);
if (!$saved) { @unlink($path); uploadJsonError('图片保存失败', 500); }
@chmod($path, 0640);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($filename)], JSON_UNESCAPED_UNICODE);
