<?php
/**
 * 图集视频压缩（服务端 ffmpeg）。
 *
 * 设计：
 *   - 图片在浏览器/APP 本地压缩，服务端不重复处理；
 *   - 视频上传后按用户选择的强度转成 H.264 + AAC（兼容性优先）；
 *   - 建议大小以内同步压缩，超大视频标记 pending 交给 CLI worker；
 *   - 原始文件保留，compressed_image 单独存放，便于前后对比。
 */

require_once __DIR__ . '/db.php';

const GALLERY_COMPRESS_SYNC_MAX_BYTES = 104857600; // 100MB：同步压缩上限

function galleryCompressQuality($value): int {
    $q = (int)$value;
    return max(60, min(95, $q ?: 80));
}

/** @return array{crf:int,edge:int,abr:string} */
function galleryCompressProfile(int $quality): array {
    if ($quality >= 88) return ['crf' => 20, 'edge' => 2560, 'abr' => '160k'];
    if ($quality >= 78) return ['crf' => 26, 'edge' => 1920, 'abr' => '128k'];
    return ['crf' => 32, 'edge' => 1280, 'abr' => '96k'];
}

/**
 * 压缩一个图集视频；返回需要合并进 gallery item 的字段。
 *
 * @throws RuntimeException
 */
function galleryCompressVideo(string $classId, array $item, int $quality): array {
    $sourceName = (string)($item['image'] ?? '');
    $source = Database::getUploadedImagePath($classId, $sourceName);
    if ($source === null) throw new RuntimeException('原视频文件不存在');

    $ffmpeg = Database::ffmpegBinary();
    if ($ffmpeg === null) throw new RuntimeException('服务器未找到 ffmpeg，无法压缩');

    $dir = Database::getUploadsDirectory($classId);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('上传目录不可用');
    }
    $outName = bin2hex(random_bytes(16)) . '.mp4';
    $outPath = $dir . DIRECTORY_SEPARATOR . $outName;
    $profile = galleryCompressProfile(galleryCompressQuality($quality));
    $vf = "scale='min({$profile['edge']},iw)':-2";
    $cmd = escapeshellarg($ffmpeg)
        . ' -y -i ' . escapeshellarg($source)
        . ' -c:v libx264 -preset medium -crf ' . (int)$profile['crf']
        . ' -vf ' . escapeshellarg($vf)
        . ' -c:a aac -b:a ' . $profile['abr']
        . ' -movflags +faststart '
        . escapeshellarg($outPath);

    [$ok, $log] = galleryRunCommand($cmd, 300);
    if (!$ok || !is_file($outPath) || filesize($outPath) <= 0) {
        @unlink($outPath);
        $tail = trim(substr((string)$log, -600));
        throw new RuntimeException('视频压缩失败' . ($tail !== '' ? '：' . $tail : ''));
    }
    @chmod($outPath, 0640);
    return [
        'compressed_image' => $outName,
        'compression_status' => 'done',
        'compression_quality' => galleryCompressQuality($quality),
        'compressed_size' => (int)filesize($outPath),
        'compressed_at' => date('Y-m-d H:i:s'),
        'compression_error' => '',
    ];
}

/**
 * 运行外部命令并等待完成（带超时，输出重定向到临时日志）。
 *
 * @return array{0:bool,1:string} [是否成功, 输出]
 */
function galleryRunCommand(string $cmd, int $timeoutSec = 300): array {
    if (!function_exists('proc_open')) return [false, 'proc_open 被禁用'];
    $log = tempnam(sys_get_temp_dir(), 'lwffmpeg');
    if ($log === false) return [false, '无法创建临时日志'];
    $descriptors = [
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($log);
        return [false, '无法启动 ffmpeg'];
    }
    $start = time();
    $exit = -1;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exit = (int)($status['exitcode'] ?? -1);
            break;
        }
        if ((time() - $start) > $timeoutSec) {
            @proc_terminate($proc, 9);
            $exit = -1;
            break;
        }
        usleep(200000);
    }
    $close = @proc_close($proc);
    if ($exit < 0) $exit = (int)$close;
    $output = (string)@file_get_contents($log);
    @unlink($log);
    return [$exit === 0, $output];
}

/** 在后台启动 CLI worker（FPM 禁用 proc_open 时返回 false，前端会保留 pending 状态）。 */
function galleryCompressKick(string $classId): bool {
    if (!function_exists('proc_open')) return false;
    $script = realpath(__DIR__ . '/../bin/compress_gallery.php');
    if ($script === false) return false;
    $php = getenv('LISTENWRITE_PHP_CLI');
    if ($php === false || $php === '') {
        $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        $php = is_file($candidate) ? $candidate : PHP_BINARY;
    }
    if ($php === '' || !is_file($php)) return false;
    $classArg = '--class=' . escapeshellarg($classId);
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $classArg
            . ' > NUL 2>&1';
    } else {
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $classArg
            . ' > /dev/null 2>&1 &';
    }
    $proc = @proc_open($cmd, [], $pipes);
    if (!is_resource($proc)) return false;
    @proc_close($proc);
    return true;
}
