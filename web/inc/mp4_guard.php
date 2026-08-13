<?php
/**
 * ============================================================
 * MP4 视频安全校验器（纯 PHP，无扩展依赖）
 * ============================================================
 *
 * 服务器无 ffmpeg/ffprobe，这里通过解析 ISO-BMFF (MP4) 的 box 结构
 * 来校验视频：
 *   1. ftyp box 魔数与 major brand 校验，拒绝伪装扩展名；
 *   2. 定位 moov → mvhd，解析 timescale/duration 得到总时长；
 *   3. 可选：从 moov → trak → tkhd 解析画面宽高（供前端比例适配）；
 *   4. 流式 fseek 读取，不整读大文件，防畸形文件拖垮解析器。
 *
 * 只做"校验 + 读取元数据"，不转码、不压缩（服务器无 ffmpeg，
 * 压缩由用户端导出时完成）。
 *
 * 用法:
 *   $info = Mp4Guard::validate($tmpPath, 30, 15728640);
 *   // $info = ['width'=>W, 'height'=>H, 'duration'=>秒, 'brand'=>'mp42']
 *   // 或抛出 RuntimeException
 * ============================================================
 */

class Mp4Guard {

    /** 最大 box 大小容错（单个 box 声明超过此值视为畸形） */
    const MAX_BOX_BYTES = 200 * 1024 * 1024;
    /** 最大视频分辨率单边（像素，防超大分辨率视频拖垮 ffmpeg 首帧解码内存） */
    const MAX_DIMENSION = 4096;

    /**
     * 校验 MP4 文件并返回元数据。
     *
     * @param string $tmpPath 文件路径
     * @param int    $maxSeconds 允许的最大时长（秒）
     * @param int    $maxBytes   允许的最大文件大小
     * @return array{width:int,height:int,duration:float,brand:string}
     * @throws RuntimeException 校验失败时抛出（含可读中文消息）
     */
    public static function validate($tmpPath, $maxSeconds = 30, $maxBytes = 15728640) {
        $size = @filesize($tmpPath);
        if ($size === false || $size <= 0) throw new RuntimeException('无法读取视频文件');
        if ($size > $maxBytes) throw new RuntimeException('视频最大 15MB');

        $fp = @fopen($tmpPath, 'rb');
        if (!$fp) throw new RuntimeException('无法读取视频文件');
        try {
            // ---- 1. 顶层 box 遍历，找 ftyp / moov ----
            $offset = 0;
            $brand = '';
            $moovOffset = null;
            while ($offset + 8 <= $size) {
                fseek($fp, $offset);
                $header = fread($fp, 8);
                if (strlen($header) !== 8) break;
                $boxSize = unpack('N', substr($header, 0, 4))[1];
                $boxType = substr($header, 4, 4);

                if ($boxSize === 1) {
                    // 64-bit 大 box
                    $ext = fread($fp, 8);
                    if (strlen($ext) !== 8) break;
                    $hi = unpack('N', substr($ext, 0, 4))[1];
                    $lo = unpack('N', substr($ext, 4, 4))[1];
                    $boxSize = ($hi * 4294967296) + $lo;
                } elseif ($boxSize === 0) {
                    // box 延伸到文件末尾
                    $boxSize = $size - $offset;
                }

                if ($boxSize < 8 || $boxSize > self::MAX_BOX_BYTES) {
                    throw new RuntimeException('MP4 文件结构无效');
                }

                if ($boxType === 'ftyp') {
                    $brand = self::readFtypBrand($fp, $boxSize);
                } elseif ($boxType === 'moov') {
                    $moovOffset = $offset + 8;
                    break;
                }

                $offset += $boxSize;
            }

            if ($brand === '') throw new RuntimeException('不是有效的 MP4 视频（缺少 ftyp）');
            $allowedBrands = ['isom', 'mp42', 'avc1', 'M4V ', 'mp41', 'iso2', 'iso4', 'iso5', 'iso6', 'dash', 'M4A ', 'qt  '];
            if (!in_array($brand, $allowedBrands, true)) {
                throw new RuntimeException('不支持的 MP4 编码（major brand: ' . trim($brand) . '）');
            }

            if ($moovOffset === null) {
                throw new RuntimeException('MP4 文件损坏（缺少 moov 元数据）');
            }

            // ---- 2. 解析 moov → mvhd 拿时长，→ trak → tkhd 拿尺寸 ----
            $info = self::parseMoov($fp, $moovOffset, $size);
            if ($info === null) throw new RuntimeException('MP4 文件损坏（缺少 mvhd）');

            $duration = $info['duration'];
            if ($duration <= 0) throw new RuntimeException('MP4 文件损坏（时长无效）');
            if ($duration > $maxSeconds) {
                throw new RuntimeException('视频时长不能超过 ' . $maxSeconds . ' 秒（当前 ' . round($duration, 1) . ' 秒）');
            }

            // 分辨率上限：缩略图 cron 会调用 ffmpeg 解码首帧，超大分辨率会耗尽服务器内存
            $width = (int)$info['width'];
            $height = (int)$info['height'];
            if ($width < 1 || $height < 1) {
                throw new RuntimeException('MP4 文件损坏（缺少有效的画面尺寸信息）');
            }
            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                throw new RuntimeException('视频分辨率过大（' . $width . '×' . $height . '），最大 ' . self::MAX_DIMENSION . '×' . self::MAX_DIMENSION);
            }

            return [
                'width' => $info['width'],
                'height' => $info['height'],
                'duration' => $duration,
                'brand' => $brand,
            ];
        } finally {
            fclose($fp);
        }
    }

    /** 读取 ftyp 的 major brand（4 字节，读到则返回，无需继续读完整 box） */
    private static function readFtypBrand($fp, $boxSize) {
        $current = ftell($fp);
        $data = fread($fp, 4);
        if (strlen($data) !== 4) return '';
        // 恢复位置（外层继续按 box 跳转）
        fseek($fp, $current);
        return $data;
    }

    /**
     * 解析 moov box：找 mvhd（时长）+ 第一个 trak 的 tkhd（尺寸）。
     * @return array{duration:float,width:int,height:int}|null
     */
    private static function parseMoov($fp, $moovDataOffset, $fileSize) {
        // moovDataOffset 指向 moov 的内容区（跳过 8 字节 header）
        // 先确定 moov 总大小（从 offset-8 读 header）
        fseek($fp, $moovDataOffset - 8);
        $moovHeader = fread($fp, 8);
        if (strlen($moovHeader) !== 8) return null;
        $moovSize = unpack('N', substr($moovHeader, 0, 4))[1];
        if ($moovSize === 1) { $ext = fread($fp, 8); if (strlen($ext) === 8) { $hi = unpack('N', substr($ext,0,4))[1]; $lo = unpack('N', substr($ext,4,4))[1]; $moovSize = $hi * 4294967296 + $lo; } }
        if ($moovSize === 0) $moovSize = $fileSize - ($moovDataOffset - 8);
        $moovEnd = ($moovDataOffset - 8) + $moovSize;
        if ($moovEnd > $fileSize) $moovEnd = $fileSize;

        $result = ['duration' => 0.0, 'width' => 0, 'height' => 0];
        $mvhdFound = false;

        $offset = $moovDataOffset;
        while ($offset + 8 <= $moovEnd) {
            fseek($fp, $offset);
            $header = fread($fp, 8);
            if (strlen($header) !== 8) break;
            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $boxType = substr($header, 4, 4);
            if ($boxSize === 1) { $ext = fread($fp, 8); if (strlen($ext) === 8) { $hi = unpack('N', substr($ext,0,4))[1]; $lo = unpack('N', substr($ext,4,4))[1]; $boxSize = $hi * 4294967296 + $lo; } }
            elseif ($boxSize === 0) $boxSize = $moovEnd - $offset;
            if ($boxSize < 8 || $boxSize > self::MAX_BOX_BYTES) return null;

            if ($boxType === 'mvhd' && !$mvhdFound) {
                // 只取第一个 mvhd：播放器/ffmpeg 均取首个，伪造的第二个"1s mvhd"不能绕过时长校验
                $d = self::parseMvhd($fp, $offset + 8, $boxSize);
                if ($d !== null) {
                    $result['duration'] = $d['duration'];
                    $mvhdFound = true;
                }
            } elseif ($boxType === 'trak') {
                $d = self::parseTrak($fp, $offset + 8, $boxSize);
                if ($d !== null) {
                    if ($result['width'] === 0) { $result['width'] = $d['width']; $result['height'] = $d['height']; }
                }
            }

            $offset += $boxSize;
        }

        return $mvhdFound ? $result : null;
    }

    /**
     * 解析 mvhd：version 0 → timescale(4) duration(4)；version 1 → timescale(4) duration(8)。
     * 返回秒数。
     */
    private static function parseMvhd($fp, $dataOffset, $boxSize) {
        fseek($fp, $dataOffset);
        $head = fread($fp, 4);
        if (strlen($head) !== 4) return null;
        $version = ord($head[0]);
        if ($version === 0) {
            // content: version+flags(4) creation(4) mod(4) timescale(4) duration(4)
            $rest = fread($fp, 16);
            if (strlen($rest) !== 16) return null;
            $timescale = unpack('N', substr($rest, 8, 4))[1];
            $duration = unpack('N', substr($rest, 12, 4))[1];
        } elseif ($version === 1) {
            // content: version+flags(4) creation(8) mod(8) timescale(4) duration(8)
            $rest = fread($fp, 28);
            if (strlen($rest) !== 28) return null;
            $timescale = unpack('N', substr($rest, 16, 4))[1];
            $hi = unpack('N', substr($rest, 20, 4))[1];
            $lo = unpack('N', substr($rest, 24, 4))[1];
            $duration = ($hi * 4294967296) + $lo;
        } else {
            return null;
        }
        if (!$timescale || $timescale <= 0) return null;
        return ['duration' => $duration / $timescale];
    }

    /** 解析 trak：找 tkhd 读画面宽高（version 0/1） */
    private static function parseTrak($fp, $dataOffset, $boxSize) {
        $end = $dataOffset + $boxSize - 8;
        $offset = $dataOffset;
        while ($offset + 8 <= $end) {
            fseek($fp, $offset);
            $header = fread($fp, 8);
            if (strlen($header) !== 8) break;
            $sz = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            if ($sz === 1) { $ext = fread($fp, 8); if (strlen($ext) === 8) { $hi = unpack('N', substr($ext,0,4))[1]; $lo = unpack('N', substr($ext,4,4))[1]; $sz = $hi * 4294967296 + $lo; } }
            elseif ($sz === 0) $sz = $end - $offset;
            if ($sz < 8 || $sz > self::MAX_BOX_BYTES) return null;

            if ($type === 'mdia') {
                $w = self::parseMdia($fp, $offset + 8, $sz);
                if ($w !== null) return $w;
            } elseif ($type === 'tkhd') {
                $d = self::parseTkhd($fp, $offset + 8, $sz);
                if ($d !== null) return $d;
            }
            $offset += $sz;
        }
        return null;
    }

    private static function parseMdia($fp, $dataOffset, $boxSize) {
        $end = $dataOffset + $boxSize - 8;
        $offset = $dataOffset;
        while ($offset + 8 <= $end) {
            fseek($fp, $offset);
            $header = fread($fp, 8);
            if (strlen($header) !== 8) break;
            $sz = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            if ($sz === 1) { $ext = fread($fp, 8); if (strlen($ext) === 8) { $hi = unpack('N', substr($ext,0,4))[1]; $lo = unpack('N', substr($ext,4,4))[1]; $sz = $hi * 4294967296 + $lo; } }
            elseif ($sz === 0) $sz = $end - $offset;
            if ($sz < 8 || $sz > self::MAX_BOX_BYTES) return null;
            if ($type === 'minf') {
                // minf → stbl → stsd：取视频尺寸信息（复杂路径，用 tkhd 更简单）
                // 这里 minf 内无宽高，直接跳过
            }
            $offset += $sz;
        }
        return null;
    }

    /** 解析 tkhd：version 0 → ... width(4) height(4) 在固定偏移；version 1 偏移不同 */
    private static function parseTkhd($fp, $dataOffset, $boxSize) {
        fseek($fp, $dataOffset);
        $head = fread($fp, 4);
        if (strlen($head) !== 4) return null;
        $version = ord($head[0]);
        // 仅支持 version 0/1；未知版本不解析（避免读出垃圾宽高，由调用方按 0 尺寸拒绝）
        if ($version !== 0 && $version !== 1) return null;
        // tkhd 结构: version(1) flags(3) creation(4/8) mod(4/8) trackId(4) reserved(4) duration(4/8)
        //           reserved(8) layer(2) altGroup(2) volume(2) reserved(2) matrix(36) width(4) height(4)
        if ($version === 0) {
            // version+flags(4) creation(4) mod(4) trackID(4) reserved(4) duration(4) reserved2(8) layer(2) altGroup(2) volume(2) reserved(2) matrix(36)
            $base = $dataOffset + 24 + 8 + 8 + 36; // 76
            fseek($fp, $base);
        } else {
            $base = $dataOffset + 4 + 8 + 8 + 4 + 4 + 8 + 8 + 2 + 2 + 2 + 2 + 36; // 88
            fseek($fp, $base);
        }
        $data = fread($fp, 8);
        if (strlen($data) !== 8) return null;
        $hi = unpack('N', substr($data, 0, 4))[1];
        $lo = unpack('N', substr($data, 4, 4))[1];
        // 宽高以 16.16 定点数存储
        return ['width' => $hi >> 16, 'height' => $lo >> 16];
    }
}
