<?php
/**
 * 视频首帧缩略图接口 — 供 APP 端视频卡片 / Web 画廊卡片展示首帧预览图。
 *
 * GET video_thumb.php?class_id={classId}&file={mp4文件名}
 *
 * - 无需鉴权（与 upload.php 一致，文件名含随机数，URL 即凭证）
 * - 缩略图由 CLI 计划任务 cron_gallery_thumbs.php（每分钟）预先生成；
 *   本接口只负责读取/输出。注意 PHP-FPM 禁用了 exec/proc_open，无法在此现场调用 ffmpeg。
 * - 未生成时返回 404，调用方应优雅降级（卡片回退为视频/占位），下个周期自动补齐
 */
require_once 'inc/db.php';
require_once 'inc/security.php';

$classId = reqGet('class_id');
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) { http_response_code(404); exit; }
$filename = reqGet('file');

if (Database::getUploadedImagePath($classId, $filename) === null) { http_response_code(404); exit; }

$thumb = Database::getVideoThumbPath($classId, $filename);
if ($thumb === null) { http_response_code(404); exit; }

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
// 文件名含随机数，URL 不可变，可放心长缓存（APP 端 cached_network_image / display 复用）
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Length: ' . filesize($thumb));
readfile($thumb);
exit;
