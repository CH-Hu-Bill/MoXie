<?php
/**
 * ============================================================
 * 音乐播放器 — 班级入口地址
 * ============================================================
 *
 * 每个班级在「设置 → 音乐播放器地址」中配置一个 http/https 链接。
 * 主界面点击「音乐播放器」后，在新标签页打开转场页，再跳转到该地址。
 *
 * 未配置时不会使用任何默认播放器，而是提示班级去设置页配置。
 * 旧版本的 folia_url_{classId} 会自动作为迁移回退读取。
 * ============================================================
 */

require_once __DIR__ . '/db.php';

/** 校验并规范化音乐播放器地址；空字符串表示未配置。 */
function music_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (strlen($url) > 500) return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    return rtrim($url, '/');
}

/** 读取某班级配置的音乐播放器地址；未配置返回空字符串。 */
function music_get_class_url(string $classId): string
{
    $settings = Database::getSettings();
    $url = (string)($settings['music_url_' . $classId] ?? '');
    if (trim($url) === '') {
        // 兼容上一版已经写入的班级配置
        $url = (string)($settings['folia_url_' . $classId] ?? '');
    }
    return music_normalize_url($url);
}
