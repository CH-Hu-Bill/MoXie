<?php
/**
 * 图集回收站（S5）。
 *
 * 删除不再立即销毁文件，而是把条目移进 `gallery_trash.json` 保留 7 天：
 *   - 期间可恢复（条目 + 文件都还活着）
 *   - 超过 7 天由 galleryTrashPurge() 真正删文件
 *
 * 文件仍留在 uploads/ 里（gallery.json 不再引用），因此恢复只是把条目挪回去，
 * 不需要搬动文件，速度快也不会产生半残状态。
 */
const GALLERY_TRASH_RETAIN_DAYS = 7;

/** @return array 回收站条目（含 deleted_at） */
function galleryTrashAll(string $classId): array {
    $list = Database::getClassData($classId, 'gallery_trash');
    return is_array($list) ? $list : [];
}

/** 把一条图集条目放进回收站 */
function galleryTrashAdd(string $classId, array $item): void {
    $item['deleted_at'] = date('Y-m-d H:i:s');
    Database::updateClassData($classId, 'gallery_trash', function ($list) use ($item) {
        if (!is_array($list)) $list = [];
        $list[] = $item;
        return $list;
    });
}

/** 从回收站移除一条记录（不删文件） */
function galleryTrashForget(string $classId, string $id): ?array {
    $found = null;
    Database::updateClassData($classId, 'gallery_trash', function ($list) use ($id, &$found) {
        if (!is_array($list)) return null;
        $keep = [];
        foreach ($list as $entry) {
            if (($entry['id'] ?? '') === $id) {
                $found = $entry;
                continue;
            }
            $keep[] = $entry;
        }
        return $found === null ? null : $keep;
    });
    return $found;
}

/** 真正删除一条图集条目的所有文件 */
function galleryTrashDeleteFiles(string $classId, array $item): void {
    $image = (string)($item['image'] ?? '');
    if ($image !== '') {
        Database::deleteUploadedImage($classId, $image);
        Database::deleteVideoThumb($classId, $image);
    }
    $live = (string)($item['live_video'] ?? '');
    if ($live !== '') {
        Database::deleteUploadedImage($classId, $live);
        Database::deleteVideoThumb($classId, $live);
    }
    $compressed = (string)($item['compressed_image'] ?? '');
    if ($compressed !== '') {
        Database::deleteUploadedImage($classId, $compressed);
        Database::deleteVideoThumb($classId, $compressed);
    }
}

/**
 * 清理超过保留期的回收站条目（含文件）。
 *
 * @return int 清理掉的条数
 */
function galleryTrashPurge(string $classId): int {
    $list = galleryTrashAll($classId);
    if (!$list) return 0;
    $deadline = time() - GALLERY_TRASH_RETAIN_DAYS * 86400;
    $keep = [];
    $removed = 0;
    foreach ($list as $entry) {
        $ts = strtotime((string)($entry['deleted_at'] ?? ''));
        if ($ts !== false && $ts > 0 && $ts < $deadline) {
            galleryTrashDeleteFiles($classId, $entry);
            $removed++;
            continue;
        }
        $keep[] = $entry;
    }
    if ($removed > 0) Database::saveClassData($classId, 'gallery_trash', $keep);
    return $removed;
}

/** 剩余保留天数（用于界面提示） */
function galleryTrashDaysLeft(array $entry): int {
    $ts = strtotime((string)($entry['deleted_at'] ?? ''));
    if ($ts === false || $ts <= 0) return GALLERY_TRASH_RETAIN_DAYS;
    $left = (int)ceil(($ts + GALLERY_TRASH_RETAIN_DAYS * 86400 - time()) / 86400);
    return max(0, $left);
}
