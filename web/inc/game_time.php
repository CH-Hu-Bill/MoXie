<?php
/**
 * 小游戏时长：按班级的每日上限与统计。
 *
 * 约定：
 *   - 设置键 game_limit_minutes_{classId}；0 或空 = 不限时
 *   - 数据文件 classes/{classId}/game_time.json
 *   - 日期按服务器本地时区（Asia/Shanghai），每天 0 点自然切换
 *   - 只记录我们自己托管的 HTML 游戏；不接任意外站 iframe
 */

require_once __DIR__ . '/db.php';

const GAME_TIME_MAX_MINUTES = 1440; // 管理员最多可设 24 小时
const GAME_TIME_KEEP_DAYS = 90;

function game_limit_minutes(string $classId): int
{
    $settings = Database::getSettings();
    $value = (int)($settings['game_limit_minutes_' . $classId] ?? 0);
    return max(0, min(GAME_TIME_MAX_MINUTES, $value));
}

/** null = 不限时；否则返回秒数 */
function game_limit_seconds(string $classId): ?int
{
    $minutes = game_limit_minutes($classId);
    return $minutes > 0 ? $minutes * 60 : null;
}

function game_today_key(): string
{
    return date('Y-m-d');
}

function game_today_data(string $classId): array
{
    $all = Database::getClassData($classId, 'game_time');
    $day = is_array($all) ? ($all[game_today_key()] ?? []) : [];
    return is_array($day) ? $day : [];
}

function game_today_seconds(string $classId): int
{
    $day = game_today_data($classId);
    if (isset($day['total_seconds'])) return max(0, (int)$day['total_seconds']);
    $sum = 0;
    foreach (($day['sessions'] ?? []) as $session) {
        if (is_array($session)) $sum += max(0, (int)($session['seconds'] ?? 0));
    }
    return $sum;
}

/** null = 不限时；否则返回剩余秒数（不会小于 0） */
function game_remaining_seconds(string $classId): ?int
{
    $limit = game_limit_seconds($classId);
    if ($limit === null) return null;
    return max(0, $limit - game_today_seconds($classId));
}

function game_format_seconds(?int $seconds): string
{
    if ($seconds === null) return '不限时';
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return $h . ' 小时 ' . $m . ' 分';
    if ($m > 0) return $m . ' 分 ' . $s . ' 秒';
    return $s . ' 秒';
}

/** 近 N 天 [date, seconds] 列表（含今天） */
function game_recent_stats(string $classId, int $days = 7): array
{
    $all = Database::getClassData($classId, 'game_time');
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $key = date('Y-m-d', strtotime('-' . $i . ' days'));
        $day = is_array($all) ? ($all[$key] ?? []) : [];
        $seconds = 0;
        if (is_array($day)) {
            if (isset($day['total_seconds'])) $seconds = max(0, (int)$day['total_seconds']);
            else foreach (($day['sessions'] ?? []) as $s) if (is_array($s)) $seconds += max(0, (int)($s['seconds'] ?? 0));
        }
        $out[] = ['date' => $key, 'seconds' => $seconds];
    }
    return $out;
}

/** 删除超过保留期的日期，避免文件无限增长 */
function game_prune_days(array &$data): void
{
    $cut = date('Y-m-d', strtotime('-' . GAME_TIME_KEEP_DAYS . ' days'));
    foreach (array_keys($data) as $key) {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string)$key) || (string)$key < $cut) {
            unset($data[$key]);
        }
    }
}
