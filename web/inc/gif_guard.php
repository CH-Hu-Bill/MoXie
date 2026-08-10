<?php
/**
 * ============================================================
 * GIF 安全校验器
 * ============================================================
 *
 * GIF 多帧动图是"解压炸弹"（decompression bomb）的典型载体：
 * 单文件很小，但解码时每帧都占用 宽×高×4 字节内存，
 * 帧数×单帧像素可能远超预期，导致服务端 GD/解码器内存与 CPU 被打满。
 *
 * 本模块流式解析 GIF 二进制结构，在不依赖 GD 的情况下：
 *   1. 校验文件头 magic bytes（GIF87a / GIF89a），拒绝伪造扩展名；
 *   2. 读取逻辑屏幕尺寸；
 *   3. 遍历 Graphic Control Extension + Image Descriptor 统计帧数；
 *   4. 计算 单帧像素 × 帧数，超过上限即拒绝。
 *
 * 纯 PHP 实现，无扩展依赖，块读取带长度上限，防解析器自身被畸形文件利用。
 *
 * 用法:
 *   $guard = GifGuard::validate($tmpPath);
 *   // $guard = ['frames' => N, 'width' => W, 'height' => H]
 *   // 或抛出 RuntimeException
 * ============================================================
 */

class GifGuard {

    /** 单块最大读取长度（Graphic Control Extension / Image Descriptor 等），防畸形超大块 */
    const MAX_BLOCK_BYTES = 4096;

    /**
     * 校验 GIF 文件并返回尺寸/帧数信息。
     *
     * @param string $tmpPath 待校验文件路径
     * @param int    $maxFrames  允许的最大帧数
     * @param int    $maxPixFrames 允许的 单帧像素 × 帧数 上限
     * @return array{width:int,height:int,frames:int}
     * @throws RuntimeException 校验失败时抛出（含可读中文消息）
     */
    public static function validate($tmpPath, $maxFrames = 300, $maxPixFrames = 80000000) {
        $fp = @fopen($tmpPath, 'rb');
        if (!$fp) throw new RuntimeException('无法读取上传文件');

        try {
            // ---- 1. 文件头 magic bytes ----
            $head = fread($fp, 6);
            if (strlen($head) !== 6) throw new RuntimeException('GIF 文件损坏');
            if ($head !== 'GIF87a' && $head !== 'GIF89a') throw new RuntimeException('仅支持 GIF 动图（GIF87a/GIF89a）');

            // ---- 2. 逻辑屏幕描述符 ----
            $ld = fread($fp, 7);
            if (strlen($ld) !== 7) throw new RuntimeException('GIF 文件损坏');
            $width  = ord($ld[0]) | (ord($ld[1]) << 8);
            $height = ord($ld[2]) | (ord($ld[3]) << 8);
            if ($width < 1 || $height < 1) throw new RuntimeException('GIF 尺寸无效');
            // 单帧像素上限由调用方（db.php）通过 maxPixFrames 整体控制，此处先做单帧合理性预检
            if ($width > 8000 || $height > 8000) throw new RuntimeException('GIF 尺寸过大');

            $flags = ord($ld[4]);
            $pixelAspect = ord($ld[6]);

            // ---- 3. 跳过全局色表 ----
            if ($flags & 0x80) {
                $gctSize = 2 << ($flags & 0x07); // 2^(n+1)
                $skip = $gctSize * 3;
                if (fseek($fp, $skip, SEEK_CUR) !== 0) throw new RuntimeException('GIF 文件损坏');
            }

            // ---- 4. 遍历块统计帧数 ----
            $frames = 0;
            $seenImageDescriptor = false;
            while (!feof($fp)) {
                $b = fread($fp, 1);
                if ($b === false || $b === '') break;
                $byte = ord($b);

                if ($byte === 0x3B) { // 文件结束符
                    break;
                }

                if ($byte === 0x2C) { // Image Descriptor（一帧）
                    $frames++;
                    $seenImageDescriptor = true;
                    if ($frames > $maxFrames) {
                        throw new RuntimeException('GIF 帧数过多，最多 ' . $maxFrames . ' 帧');
                    }
                    // 读取局部图像描述符（9字节：分隔符已读，剩左/顶/宽/高/标志 9-1=... 实际为 9 字节）
                    $id = fread($fp, 9);
                    if (strlen($id) !== 9) throw new RuntimeException('GIF 文件损坏');
                    $iw = ord($id[4]) | (ord($id[5]) << 8);
                    $ih = ord($id[6]) | (ord($id[7]) << 8);
                    if ($iw < 1 || $ih < 1) throw new RuntimeException('GIF 帧尺寸无效');
                    if ($iw > 8000 || $ih > 8000) throw new RuntimeException('GIF 帧尺寸过大');
                    // 局部色表
                    if (ord($id[8]) & 0x80) {
                        $lctSize = 2 << (ord($id[8]) & 0x07);
                        if (fseek($fp, $lctSize * 3, SEEK_CUR) !== 0) throw new RuntimeException('GIF 文件损坏');
                    }
                    // 跳过该帧的 LZW 压缩数据：1 字节 min code size + 连续子块（0x00 结尾）
                    $minCode = fread($fp, 1);
                    if ($minCode === false || $minCode === '' || ord($minCode) < 2) {
                        throw new RuntimeException('GIF 文件损坏');
                    }
                    self::skipLzwData($fp);
                } elseif ($byte === 0x21) { // Extension
                    $label = fread($fp, 1);
                    if ($label === false || $label === '') throw new RuntimeException('GIF 文件损坏');
                    self::skipExtension($fp, ord($label));
                } else {
                    // 非法块类型
                    throw new RuntimeException('GIF 文件结构无效');
                }
            }

            // 至少要有 1 帧（静态单帧 GIF 也允许，但本功能面向动图，仍接受单帧）
            if ($frames < 1) throw new RuntimeException('GIF 不含有效帧');

            // ---- 5. 像素 × 帧 炸弹防护 ----
            if (($width * $height) > 0 && $frames > 0) {
                $total = $width * $height * $frames;
                if ($total > $maxPixFrames) {
                    throw new RuntimeException('GIF 体积过大（' . $width . '×' . $height . '×' . $frames . ' 帧），超出安全上限');
                }
            }

            return ['width' => $width, 'height' => $height, 'frames' => $frames];
        } finally {
            fclose($fp);
        }
    }

    /** 跳过 LZW 压缩数据子块（0x00 结尾） */
    private static function skipLzwData($fp) {
        $blockSize = fread($fp, 1);
        while ($blockSize !== false && $blockSize !== '' && ord($blockSize) !== 0) {
            $len = ord($blockSize);
            if ($len > self::MAX_BLOCK_BYTES) throw new RuntimeException('GIF 数据块过大');
            if (fseek($fp, $len, SEEK_CUR) !== 0) throw new RuntimeException('GIF 文件损坏');
            $blockSize = fread($fp, 1);
        }
    }

    /** 跳过扩展块（Graphic Control / Comment / Plain Text / Application） */
    private static function skipExtension($fp, $label) {
        if ($label === 0xF9) { // Graphic Control Extension：0x04(size) + 4字节数据 + 0x00(terminator)
            $d = fread($fp, 6);
            if (strlen($d) !== 6) throw new RuntimeException('GIF 文件损坏');
            return;
        }
        // 其他扩展：子块序列（0x00 结尾）
        $blockSize = fread($fp, 1);
        while ($blockSize !== false && $blockSize !== '' && ord($blockSize) !== 0) {
            $len = ord($blockSize);
            if ($len > self::MAX_BLOCK_BYTES) throw new RuntimeException('GIF 数据块过大');
            if (fseek($fp, $len, SEEK_CUR) !== 0) throw new RuntimeException('GIF 文件损坏');
            $blockSize = fread($fp, 1);
        }
    }
}
