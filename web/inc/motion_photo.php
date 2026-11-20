<?php
/**
 * Android Motion Photo 提取（不依赖 exiftool / GD）。
 *
 * 支持：
 *   - 旧版 XMP: GCamera:MicroVideoOffset="N"（N 为从文件末尾倒数）
 *   - 新版 XMP: Container:Directory + Item:Mime="video/mp4" + Item:Length="N"
 *   - 回退：扫描最后一个 ftyp box
 */

/**
 * 判断指定偏移是否像一个 MP4 起始（box size + ftyp）。
 */
function motionPhotoLooksLikeMp4(string $bytes, int $start): bool {
    if ($start < 0 || $start + 12 > strlen($bytes)) return false;
    return substr($bytes, $start + 4, 4) === 'ftyp';
}

/**
 * 找到内嵌视频的 [start, length]；找不到返回 null。
 *
 * @return array{start:int,length:int}|null
 */
function motionPhotoFindVideoRange(string $bytes): ?array {
    $len = strlen($bytes);
    if ($len < 16) return null;

    $offset = null;
    if (preg_match('/GCamera:MicroVideoOffset\s*=\s*["\']?(\d+)["\']?/i', $bytes, $m)) {
        $offset = (int)$m[1];
    } elseif (preg_match('/MicroVideoOffset\s*=\s*["\']?(\d+)["\']?/i', $bytes, $m)) {
        $offset = (int)$m[1];
    }
    if ($offset !== null && $offset > 0 && $offset < $len) {
        $start = $len - $offset;
        if (motionPhotoLooksLikeMp4($bytes, $start)) {
            return ['start' => $start, 'length' => $offset];
        }
    }

    // 新版 Google Motion Photo: Item:Length 与 video/mp4 在同一个 Description 里
    $patterns = [
        '/Item:Mime\s*=\s*["\']video\/mp4["\'][^>]*?Item:Length\s*=\s*["\']?(\d+)/i',
        '/Item:Length\s*=\s*["\']?(\d+)["\']?[^>]*?Item:Mime\s*=\s*["\']video\/mp4["\']/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $bytes, $m)) {
            $length = (int)$m[1];
            if ($length > 0 && $length < $len) {
                $start = $len - $length;
                if (motionPhotoLooksLikeMp4($bytes, $start)) {
                    return ['start' => $start, 'length' => $length];
                }
            }
        }
    }

    // 回退：最后一个 ftyp；MP4 起始在 ftyp 前 4 字节的 box size
    $pos = strrpos($bytes, 'ftyp');
    if ($pos !== false && $pos >= 4) {
        $start = $pos - 4;
        if (!motionPhotoLooksLikeMp4($bytes, $start)) return null;
        return ['start' => $start, 'length' => $len - $start];
    }
    return null;
}

/**
 * 提取内嵌视频到临时文件；失败返回 null。
 * 调用方负责删除返回的临时文件。
 */
function motionPhotoExtractVideo(string $path): ?string {
    if (!is_file($path)) return null;
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') return null;
    $range = motionPhotoFindVideoRange($bytes);
    if ($range === null) return null;
    $video = substr($bytes, $range['start'], $range['length']);
    if (strlen($video) < 12 || substr($video, 4, 4) !== 'ftyp') return null;
    $tmp = @tempnam(sys_get_temp_dir(), 'lw_live_');
    if ($tmp === false) return null;
    if (@file_put_contents($tmp, $video) === false) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}
