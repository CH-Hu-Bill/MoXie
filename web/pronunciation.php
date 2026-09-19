<?php
/**
 * 全球发音 — 音频输出端点（只读）
 *
 * GET/HEAD: pronunciation.php?class_id={cid}&word_id={wid}&file={32hex}
 *
 * 安全模型与 upload.php 一致：文件名为随机 32 位 hex 不可枚举，
 * 输出端点无需鉴权（班级内容靠不可猜测的文件名保护）；路径全程白名单校验防穿越。
 * 仅输出 M4A（audio/mp4），支持 Range（流式播放必需）与 immutable 长缓存。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';

function pronError($message, $status = 404) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    pronError('方法不允许', 405);
}

$classId = reqGet('class_id');
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) pronError('班级参数无效', 400);
$classes = Database::getClasses();
if (!isset($classes[$classId])) pronError('班级不存在', 404);

$wordId = reqGet('word_id');
if (!is_string($wordId) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $wordId)) pronError('单词参数无效', 400);

$file = reqGet('file');
if (!is_string($file) || !preg_match('/\A[a-f0-9]{32}\.m4a\z/D', $file)) pronError('文件参数无效', 400);

$path = Database::getClassDir($classId) . '/pronunciations/' . $wordId . '/' . $file;
if (!is_file($path)) pronError('录音不存在', 404);

header('Content-Type: audio/mp4');
header('Content-Disposition: inline; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=31536000, immutable'); // 文件名随机不可变，可放心长缓存

$fileSize = filesize($path);
$start = 0;
$end = $fileSize - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $rStart = $m[1] === '' ? null : (int)$m[1];
        $rEnd = $m[2] === '' ? null : (int)$m[2];
        if ($rStart === null && $rEnd !== null) {
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

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

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
    if (connection_aborted()) break;
}
fclose($fh);
