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

// GET/HEAD 请求（查看图片/视频）无需鉴权，外部 API 可直接引用
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    $filename = reqGet('file');
    $path = Database::getUploadedImagePath($classId, $filename);
    if ($path === null) uploadJsonError('文件不存在', 404);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4'];
    $mime = $types[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    // 文件名含随机数，删除后 URL 即失效（不会命中旧缓存），可放心长缓存
    header('Cache-Control: private, max-age=31536000, immutable'); // 1 年 + immutable（文件名随机不可变）

    $fileSize = filesize($path);
    $start = 0;
    $end = $fileSize - 1;
    $status = 200;

    // MP4 支持 Range 请求：播放器 seek/流式加载必需（图片/ GIF 也可用，无害）
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $rStart = $m[1] === '' ? null : (int)$m[1];
            $rEnd = $m[2] === '' ? null : (int)$m[2];
            if ($rStart === null && $rEnd !== null) {
                // 后缀范围 bytes=-N：取文件末尾 N 字节
                $start = max(0, $fileSize - $rEnd);
                $end = $fileSize - 1;
            } else {
                if ($rStart !== null && $rStart >= $fileSize) {
                    header('HTTP/1.1 416 Range Not Satisfiable');
                    header('Content-Range: bytes */' . $fileSize);
                    exit;
                }
                $start = $rStart === null ? 0 : $rStart;
                $end = $rEnd === null ? $fileSize - 1 : min($rEnd, $fileSize - 1);
                if ($end < $start) $end = $start;
            }
            $status = 206;
        }
    }

    header('HTTP/1.1 ' . $status . ' ' . ($status === 206 ? 'Partial Content' : 'OK'));
    header('Content-Length: ' . ($end - $start + 1));
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    // HEAD 请求：只返回头部，不输出 body（播放器探测用）
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

    $fh = fopen($path, 'rb');
    if ($start > 0) fseek($fh, $start);
    $bytesLeft = $end - $start + 1;
    $chunk = 8192;
    while ($bytesLeft > 0 && !feof($fh)) {
        $read = min($chunk, $bytesLeft);
        $buf = fread($fh, $read);
        if ($buf === false || $buf === '') break;
        echo $buf;
        $bytesLeft -= strlen($buf);
    }
    fclose($fh);
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
if (($file['size'] ?? 0) <= 0) uploadJsonError('上传文件无效');
if (!is_uploaded_file($file['tmp_name'])) uploadJsonError('上传文件无效');
// 按类型分段限值（与 gallery.php / app_api.php 一致）：GIF 16MB / MP4 15MB / 图片 12MB
$isGif = false;
$isMp4 = false;
if (is_file($file['tmp_name'])) {
    $info = @getimagesize($file['tmp_name']);
    $isGif = is_array($info) && ($info[2] ?? 0) === IMAGETYPE_GIF;
    if (!$isGif) $isMp4 = Database::isMp4File($file['tmp_name']);
}
if ($isMp4) {
    if (($file['size'] ?? 0) > Database::MP4_MAX_BYTES) uploadJsonError('视频最大 15MB', 413);
} else {
    $maxBytes = $isGif ? Database::GIF_MAX_BYTES : Database::UPLOAD_MAX_BYTES;
    if (($file['size'] ?? 0) > $maxBytes) uploadJsonError($isGif ? 'GIF 动图最大 16MB' : '图片最大 12MB', 413);
}

try {
    $filename = Database::saveUploadedImage($classId, $file['tmp_name']);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    $code = (str_contains($msg, '像素') || str_contains($msg, '12MB') || str_contains($msg, '15MB') || str_contains($msg, '16MB') || str_contains($msg, '秒') || str_contains($msg, '分辨率')) ? 413 : 500;
    uploadJsonError($msg, $code);
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($filename)], JSON_UNESCAPED_UNICODE);
