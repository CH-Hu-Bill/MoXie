<?php
/**
 * 图集视频压缩 worker（CLI）。
 *
 * 用法：
 *   php web/bin/compress_gallery.php
 *   php web/bin/compress_gallery.php --class=testclass1
 *
 * 建议用计划任务每分钟运行一次；上传端会在需要时尝试后台启动本脚本。
 * 只处理 compression_status=pending 的 MP4；处理完成写 done/failed。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/gallery_compress.php';

$classFilter = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--class=') === 0) {
        $classFilter = trim(substr($arg, 8), " \t\n\r\0\x0B\"'");
    }
}

$lockFile = __DIR__ . '/../data/.compress-gallery.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "compress_gallery: another worker is running\n");
    exit(0);
}

$classes = Database::getClasses();
$processed = 0;
$failed = 0;
foreach ($classes as $classId => $class) {
    if ($classFilter !== '' && (string)$classId !== $classFilter) continue;
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', (string)$classId)) continue;
    $gallery = Database::getClassData($classId, 'gallery');
    if (!is_array($gallery)) $gallery = [];
    foreach ($gallery as $item) {
        if (($item['compression_status'] ?? '') !== 'pending') continue;
        if (($item['type'] ?? '') !== 'video') continue;
        $id = (string)($item['id'] ?? '');
        if ($id === '') continue;

        // 标记 processing，避免另一个 worker 重复领取
        $claimed = false;
        Database::updateClassData($classId, 'gallery', function ($latest) use ($id, &$claimed) {
            if (!is_array($latest)) return null;
            foreach ($latest as $i => $row) {
                if (($row['id'] ?? '') === $id && ($row['compression_status'] ?? '') === 'pending') {
                    $latest[$i]['compression_status'] = 'processing';
                    $claimed = true;
                    return $latest;
                }
            }
            return null;
        });
        if (!$claimed) continue;

        try {
            $fields = galleryCompressVideo($classId, $item, (int)($item['compression_quality'] ?? 80));
            Database::updateClassData($classId, 'gallery', function ($latest) use ($id, $fields) {
                if (!is_array($latest)) return null;
                foreach ($latest as $i => $row) {
                    if (($row['id'] ?? '') === $id) {
                        foreach ($fields as $k => $v) $latest[$i][$k] = $v;
                        return $latest;
                    }
                }
                return null;
            });
            try { Database::generateVideoThumb($classId, (string)$fields['compressed_image']); } catch (Throwable $e) {}
            $processed++;
            echo "compressed {$classId}/{$id}\n";
        } catch (Throwable $e) {
            $failed++;
            $msg = $e->getMessage();
            Database::updateClassData($classId, 'gallery', function ($latest) use ($id, $msg) {
                if (!is_array($latest)) return null;
                foreach ($latest as $i => $row) {
                    if (($row['id'] ?? '') === $id) {
                        $latest[$i]['compression_status'] = 'failed';
                        $latest[$i]['compression_error'] = mb_substr($msg, 0, 300);
                        return $latest;
                    }
                }
                return null;
            });
            fwrite(STDERR, "failed {$classId}/{$id}: {$msg}\n");
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);
echo "done processed={$processed} failed={$failed}\n";
