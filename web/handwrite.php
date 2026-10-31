<?php
/**
 * 手写板（嵌入班级史记弹窗）
 * 只允许已通过班级口令的设备访问。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';

$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

$htmlPath = __DIR__ . '/lib/handwrite/index.html';
$html = @file_get_contents($htmlPath);
if ($html === false) {
    http_response_code(500);
    echo '手写板资源缺失';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
