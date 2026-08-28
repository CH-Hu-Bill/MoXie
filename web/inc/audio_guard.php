<?php
/**
 * 音频文件安全校验（全球发音功能专用）
 *
 * 只允许 M4A/AAC 容器（APP 端 Android 录音输出格式）：
 *   - magic bytes：offset 4 起 "ftyp"，brand 白名单（防伪装扩展名）
 *   - 时长解析：moov/mvhd 的 timescale+duration（版本 0/1 兼容），超限拒绝
 *   - 不转码、不重编码（服务器 FPM 无 ffmpeg）
 *
 * 用法：
 *   try { $info = AudioGuard::validateM4a($tmpPath, $maxBytes); } catch (RuntimeException $e) { ... }
 *   // $info = ['duration' => 1.8, 'size' => 23456]
 */
class AudioGuard
{
    /** 最大时长（秒）——单词发音 3 秒足够，10 秒为宽松上限 */
    const MAX_DURATION = 10.0;

    /** 允许的 ftyp brand（M4A/iTunes/ISO 系列） */
    const ALLOWED_BRANDS = ['M4A ', 'M4B ', 'isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'dash', 'avc1'];

    /**
     * 校验 M4A 文件，返回 ['duration' => float, 'size' => int]
     * @throws RuntimeException 不合法时给出面向用户的中文原因
     */
    public static function validateM4a(string $path, int $maxBytes): array
    {
        if (!is_file($path)) throw new RuntimeException('音频文件无效');
        $size = filesize($path);
        if ($size <= 0) throw new RuntimeException('音频文件无效');
        if ($size > $maxBytes) throw new RuntimeException('录音最大 ' . round($maxBytes / 1048576, 1) . 'MB');

        $fp = fopen($path, 'rb');
        if (!$fp) throw new RuntimeException('音频文件无效');

        try {
            // ---- 1. ftyp 头校验 ----
            $head = fread($fp, 64);
            if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
                throw new RuntimeException('仅支持 M4A/AAC 格式录音');
            }
            $brand = substr($head, 8, 4);
            if (!in_array($brand, self::ALLOWED_BRANDS, true)) {
                throw new RuntimeException('仅支持 M4A/AAC 格式录音');
            }

            // ---- 2. 解析 mvhd 时长（文件很小，直接全读扫 box）----
            rewind($fp);
            $data = fread($fp, $size);
        } finally {
            fclose($fp);
        }

        $duration = self::findMvhdDuration($data);
        if ($duration === null) throw new RuntimeException('录音文件损坏（无时长信息）');
        if ($duration <= 0.05) throw new RuntimeException('录音时长太短');
        if ($duration > self::MAX_DURATION) throw new RuntimeException('录音最长 ' . (int)self::MAX_DURATION . ' 秒');

        return ['duration' => round($duration, 2), 'size' => $size];
    }

    /** 在 ISO-BMFF 数据中定位 moov/mvhd，解析时长（秒）；找不到返回 null */
    private static function findMvhdDuration(string $data): ?float
    {
        $len = strlen($data);
        $pos = 0;
        while ($pos + 8 <= $len) {
            $box = unpack('Nsize/a4type', substr($data, $pos, 8));
            $boxSize = $box['size'];
            $boxType = $box['type'];
            if ($boxSize < 8) return null; // 非法结构
            if ($boxSize === 1) { // 64 位大尺寸
                if ($pos + 16 > $len) return null;
                $boxSize = unpack('J', substr($data, $pos + 8, 8))[1];
                if ($boxSize < 16) return null;
            }
            if ($boxSize > $len - $pos) return null;

            if ($boxType === 'moov') {
                return self::durationInMoov(substr($data, $pos + 8, $boxSize - 8));
            }
            $pos += $boxSize;
        }
        return null;
    }

    /** 在 moov 内容里找 mvhd 解析时长 */
    private static function durationInMoov(string $moov): ?float
    {
        $len = strlen($moov);
        $pos = 0;
        while ($pos + 8 <= $len) {
            $box = unpack('Nsize/a4type', substr($moov, $pos, 8));
            $boxSize = $box['size'];
            $boxType = $box['type'];
            if ($boxSize < 8) return null;
            if ($boxSize === 1) {
                if ($pos + 16 > $len) return null;
                $boxSize = unpack('J', substr($moov, $pos + 8, 8))[1];
                if ($boxSize < 16) return null;
            }
            if ($boxSize > $len - $pos) return null;

            if ($boxType === 'mvhd') {
                return self::parseMvhd(substr($moov, $pos + 8, $boxSize - 8));
            }
            $pos += $boxSize;
        }
        return null;
    }

    /** mvhd body: version(1)+flags(3)+ctime+mtime+timescale+duration */
    private static function parseMvhd(string $mvhd): ?float
    {
        if (strlen($mvhd) < 20) return null;
        $version = ord($mvhd[0]);
        if ($version === 1) {
            if (strlen($mvhd) < 32) return null;
            $timescale = unpack('N', substr($mvhd, 20, 4))[1];
            $duration = unpack('J', substr($mvhd, 24, 8))[1];
        } else {
            if (strlen($mvhd) < 20) return null;
            $timescale = unpack('N', substr($mvhd, 12, 4))[1];
            $duration = unpack('N', substr($mvhd, 16, 4))[1];
        }
        if ($timescale <= 0) return null;
        if ($duration >= 0xFFFFFFFF) $duration = 0xFFFFFFFF; // 防异常超大值
        return $duration / $timescale;
    }
}
