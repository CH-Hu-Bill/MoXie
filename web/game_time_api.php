<?php
/**
 * 小游戏时长 API：开始 / 心跳 / 结束。
 * 只由 game.php 的同源请求调用。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/game_time.php';
$gamesCatalog = require __DIR__ . '/inc/game_catalog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$GAME_IDS = array_fill_keys(array_keys($gamesCatalog), true);

function gameJson($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$classId = reqPost('class_id');
if ($classId === '') gameJson(['success' => false, 'error' => '缺少班级参数'], 400);
$classes = Database::getClasses();
if (!isset($classes[$classId])) gameJson(['success' => false, 'error' => '班级不存在'], 404);
if (!isClassAuthenticated($classId, $classes[$classId])) {
    gameJson(['success' => false, 'error' => '班级登录已失效，请刷新页面'], 403);
}

$action = reqPost('action');
$gameId = reqPost('game');
if (!isset($GAME_IDS[$gameId])) gameJson(['success' => false, 'error' => '游戏不存在'], 404);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') gameJson(['success' => false, 'error' => '请求方式错误'], 405);
requireCsrf();

$limit = game_limit_seconds($classId);
$dayKey = game_today_key();
$now = time();

if ($action === 'start') {
    $allowed = false;
    $sessionToken = bin2hex(random_bytes(24));
    $sessionId = bin2hex(random_bytes(12));
    $result = Database::updateClassData($classId, 'game_time', function ($data) use ($dayKey, $gameId, $sessionId, $sessionToken, $now, $limit, &$allowed) {
        if (!is_array($data)) $data = [];
        game_prune_days($data);
        $day = $data[$dayKey] ?? [];
        if (!is_array($day)) $day = [];
        if (!isset($day['sessions']) || !is_array($day['sessions'])) $day['sessions'] = [];
        $total = isset($day['total_seconds']) ? max(0, (int)$day['total_seconds']) : 0;
        if ($limit !== null && $total >= $limit) {
            $allowed = false;
            return null;
        }
        $day['total_seconds'] = $total;
        $day['sessions'][$sessionId] = [
            'game' => $gameId,
            'token_hash' => hash('sha256', $sessionToken),
            'started_at' => $now,
            'last_seen' => $now,
            'seconds' => 0,
            'ended_at' => 0,
        ];
        $data[$dayKey] = $day;
        $allowed = true;
        return $data;
    });
    if ($result === false) gameJson(['success' => false, 'error' => '无法写入游戏时长数据'], 500);
    $remaining = $limit === null ? null : max(0, $limit - game_today_seconds($classId));
    if (!$allowed) {
        gameJson(['success' => true, 'allowed' => false, 'remaining_seconds' => 0, 'limit_seconds' => $limit]);
    }
    gameJson([
        'success' => true,
        'allowed' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'remaining_seconds' => $remaining,
        'limit_seconds' => $limit,
    ]);
}

if ($action === 'heartbeat' || $action === 'stop') {
    $sessionId = reqPost('session_id');
    $sessionToken = reqPost('session_token');
    if (!preg_match('/\A[a-f0-9]{24}\z/', $sessionId) || !preg_match('/\A[a-f0-9]{48}\z/', $sessionToken)) {
        gameJson(['success' => false, 'error' => '会话参数无效'], 400);
    }
    $found = false;
    $reached = false;
    $result = Database::updateClassData($classId, 'game_time', function ($data) use ($dayKey, $sessionId, $sessionToken, $now, $limit, $action, &$found, &$reached) {
        if (!is_array($data)) return null;
        $day = $data[$dayKey] ?? null;
        if (!is_array($day) || !isset($day['sessions'][$sessionId]) || !is_array($day['sessions'][$sessionId])) return null;
        $session = $day['sessions'][$sessionId];
        if (!hash_equals((string)($session['token_hash'] ?? ''), hash('sha256', $sessionToken))) return null;
        $found = true;
        if (!empty($session['ended_at'])) return null;
        $lastSeen = (int)($session['last_seen'] ?? $now);
        $delta = max(0, $now - $lastSeen);
        // 页面隐藏/休眠时心跳会停；恢复后不要把整段空白时间算进去。
        $delta = min($delta, 30);
        $total = isset($day['total_seconds']) ? max(0, (int)$day['total_seconds']) : 0;
        $total += $delta;
        $session['seconds'] = max(0, (int)($session['seconds'] ?? 0)) + $delta;
        $session['last_seen'] = $now;
        if ($action === 'stop') $session['ended_at'] = $now;
        $day['total_seconds'] = $total;
        $day['sessions'][$sessionId] = $session;
        if ($limit !== null && $total >= $limit) {
            $session['ended_at'] = $now;
            $day['sessions'][$sessionId] = $session;
            $reached = true;
        }
        $data[$dayKey] = $day;
        return $data;
    });
    if ($result === false) gameJson(['success' => false, 'error' => '无法写入游戏时长数据'], 500);
    if (!$found) gameJson(['success' => false, 'error' => '游戏会话不存在或已结束'], 400);
    $remaining = $limit === null ? null : max(0, $limit - game_today_seconds($classId));
    gameJson([
        'success' => true,
        'allowed' => !$reached && ($remaining === null || $remaining > 0),
        'remaining_seconds' => $remaining,
        'limit_seconds' => $limit,
        'time_up' => $reached || ($remaining !== null && $remaining <= 0),
    ]);
}

gameJson(['success' => false, 'error' => '未知操作'], 400);
