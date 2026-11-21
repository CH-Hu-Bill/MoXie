<?php
/**
 * 班级区块指纹（Web 端分区刷新用）。
 *
 * GET class_rev.php?id={classId}
 *
 * 只返回各区块指纹与计数，不返回业务数据；10 秒轮询成本是几次 JSON 文件读。
 * 需要班级已登录（与 words.php 一致的 Cookie 校验），未登录返回 401 JSON 而不是跳转。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/app_lib.php';

$classId = reqGet('id');
if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $classId)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$classes = Database::getClasses();
if (!isset($classes[$classId])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'not_found']);
    exit;
}

if (!isClassAuthenticated($classId, $classes[$classId])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'auth_required']);
    exit;
}

$words = (array)Database::getWords($classId);
$tasks = (array)Database::getTasks($classId);
$gallery = Database::getClassData($classId, 'gallery');

$counts = ['word' => 0, 'sentence' => 0, 'essay' => 0];
foreach ($words as $w) {
    $t = $w['type'] ?? 'word';
    if ($t !== 'sentence' && $t !== 'essay') $t = 'word';
    $counts[$t]++;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'rev' => [
        'words' => appWordsRev($words),
        'tasks' => appTasksRev($tasks),
        'gallery' => appGalleryRev(is_array($gallery) ? $gallery : []),
    ],
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE);
