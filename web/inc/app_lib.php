<?php
/**
 * APP API 的纯函数（可独立测试，不产生副作用）。
 */

/**
 * 区块指纹的统一哈希：只取前 16 位，够区分、传输也小。
 */
function appRevHash(array $parts): string {
    return substr(md5(implode("\n", $parts)), 0, 16);
}

/**
 * 知识库（words）指纹：任一字段变化都会改变。
 */
function appWordsRev(array $words): string {
    $sig = [];
    foreach ($words as $w) {
        $sig[] = ($w['id'] ?? '') . '|' . ($w['type'] ?? 'word') . '|' . ($w['word'] ?? '')
            . '|' . ($w['meaning'] ?? '') . '|' . ($w['pos'] ?? '') . '|' . ($w['title'] ?? '')
            . '|' . ($w['created_at'] ?? '');
    }
    return appRevHash($sig);
}

/**
 * 任务指纹：状态、日期、条目数量与条目 id 都会影响（条目增删改状态都算变化）。
 */
function appTasksRev(array $tasks): string {
    $sig = [];
    foreach ($tasks as $tid => $task) {
        $ids = array_map('strval', $task['word_ids'] ?? []);
        $sig[] = (string)($task['id'] ?? $tid) . '|' . ($task['status'] ?? '')
            . '|' . ($task['date'] ?? '') . '|' . ($task['label'] ?? '')
            . '|' . count($ids) . '|' . md5(implode(',', $ids));
    }
    sort($sig); // 顺序不影响指纹，避免 reorder 触发无意义刷新
    return appRevHash($sig);
}

/**
 * 错题指纹：按用户维度，只关心「哪些条目被标错」及标记时间。
 */
function appWrongRev(array $wrongMap): string {
    $keys = array_map('strval', array_keys($wrongMap));
    sort($keys);
    $sig = [];
    foreach ($keys as $k) {
        $info = $wrongMap[$k] ?? [];
        $sig[] = $k . '|' . (is_array($info) ? (string)($info['marked_at'] ?? '') : '');
    }
    return appRevHash($sig);
}

/**
 * 图集指纹：条目 id、文件、描述、上传时间、类型都参与。
 */
function appGalleryRev(array $gallery): string {
    $sig = [];
    foreach ($gallery as $it) {
        $sig[] = (string)($it['id'] ?? '') . '|' . ($it['image'] ?? '')
            . '|' . ($it['live_video'] ?? '') . '|' . ($it['type'] ?? '')
            . '|' . ($it['description'] ?? '') . '|' . ($it['uploaded_at'] ?? '');
    }
    return appRevHash($sig);
}

/**
 * 一次算出各区块指纹。APP 10 秒轮询只需比对这个结果，再决定刷新哪一块。
 *
 * @return array{words:string,tasks:string,wrong:string,gallery:string}
 */
function appDataRevs(array $words, array $tasks, array $wrongMap, array $gallery): array {
    return [
        'words' => appWordsRev($words),
        'tasks' => appTasksRev($tasks),
        'wrong' => appWrongRev($wrongMap),
        'gallery' => appGalleryRev($gallery),
    ];
}

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
 * 版本兼容门禁。
 *
 * @return array{has_update:bool,force_update:bool,server_too_old:bool}
 */
function appVersionGate(
    string $current,
    string $latest,
    string $minSupported,
    string $webVersion,
    string $minWebVersion
): array {
    return [
        'has_update' => version_compare($latest, $current, '>'),
        'force_update' => version_compare($current, $minSupported, '<'),
        'server_too_old' => version_compare($webVersion, $minWebVersion, '<'),
    ];
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
            'uploader_name' => (string)($item['uploader_name'] ?? ''),
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
        'uploader_name' => (string)($item['uploader_name'] ?? ''),
    ];
}
