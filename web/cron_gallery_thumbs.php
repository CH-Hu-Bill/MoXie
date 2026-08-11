<?php
/**
 * CLI 计划任务：为所有班级的 MP4 图集生成缺失的首帧缩略图。
 *
 * 背景：PHP-FPM 出于安全禁用了全部进程执行函数（proc_open/exec/shell_exec...），
 * 而 php-ffmpeg 依赖 Symfony Process 的 proc_open，因此无法在 FPM 内现场生成。
 * 但 CLI PHP 不受此限制 —— 本脚本由计划任务（每分钟）以 CLI 运行，
 * 扫描各班级 gallery.json 中的 MP4，缺缩略图则调用 ffmpeg 生成。
 *
 * 调用（计划任务，每 1 分钟）：
 *   php /www/wwwroot/moxie.billspace.top/cron_gallery_thumbs.php
 *
 * 特性：
 *   - /tmp 锁防止并发重叠（配合单次 50s 时间预算，避免与下一次触发撞车）
 *   - 只处理缺失项，已存在的跳过（幂等）
 *   - 生成失败不影响主流程，下次运行重试
 */
require_once __DIR__ . '/inc/db.php';

$lockPath = sys_get_temp_dir() . '/gallery_thumbs_cron.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock) { fwrite(STDERR, "cannot open lock\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "busy\n"); exit(0); }

$start = microtime(true);
$scanned = 0;
$generated = 0;
$failed = 0;

$classes = Database::getClasses();
foreach ($classes as $cid => $c) {
    if (microtime(true) - $start > 50) break; // 单次时间预算 50s
    $gallery = Database::getClassData($cid, 'gallery');
    if (!is_array($gallery)) continue;
    foreach ($gallery as $item) {
        $file = (string)($item['image'] ?? '');
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp4') continue;
        $scanned++;
        if (Database::getVideoThumbPath($cid, $file) !== null) continue; // 已存在
        if (Database::generateVideoThumb($cid, $file)) $generated++; else $failed++;
    }
}

flock($lock, LOCK_UN);
fclose($lock);
echo date('Y-m-d H:i:s') . " scanned={$scanned} generated={$generated} failed={$failed}\n";
