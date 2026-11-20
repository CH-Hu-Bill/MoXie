<?php
/**
 * APP API 的纯函数（可独立测试，不产生副作用）。
 */

/**
 * 给知识库条目补充 is_wrong 标记。
 *
 * @param array $words 已序列化的条目
 * @param array $wrongMap 错题 map (key=wordId)
 * @return array
 */
function appApplyWrongFlags(array $words, array $wrongMap): array {
    foreach ($words as $i => $w) {
        $words[$i]['is_wrong'] = isset($wrongMap[(string)($w['id'] ?? '')]);
    }
    return $words;
}

/**
 * 图集条目统一序列化（静态图 / GIF / 普通视频 / 实况）。
 *
 * @param array $item gallery.json 条目
 * @param string $classId
 * @param string $base 站点根地址（不带末尾斜杠）
 * @return array
 */
function appGalleryItemOut(array $item, string $classId, string $base): array {
    $imgFile = (string)($item['image'] ?? '');
    $liveFile = (string)($item['live_video'] ?? '');
    $ext = strtolower(pathinfo($imgFile, PATHINFO_EXTENSION));
    $mediaUrl = function (string $file) use ($base, $classId): string {
        return $base . '/upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($file);
    };

    $isLive = $liveFile !== '' && ($item['type'] ?? '') !== 'video';
    if ($isLive) {
        return [
            'id' => (string)($item['id'] ?? ''),
            'image_url' => $mediaUrl($imgFile),
            'type' => 'live',
            'description' => (string)($item['description'] ?? ''),
            'uploaded_at' => (string)($item['uploaded_at'] ?? ''),
            'thumb_url' => $mediaUrl($imgFile),
            'live_video_url' => $mediaUrl($liveFile),
        ];
    }

    $isMp4 = $ext === 'mp4';
    return [
        'id' => (string)($item['id'] ?? ''),
        'image_url' => $mediaUrl($imgFile),
        'type' => $ext === 'gif' ? 'gif' : ($isMp4 ? 'mp4' : 'static'),
        'description' => (string)($item['description'] ?? ''),
        'uploaded_at' => (string)($item['uploaded_at'] ?? ''),
        'thumb_url' => $isMp4 ? ($base . '/video_thumb.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($imgFile)) : null,
        'live_video_url' => null,
    ];
}
