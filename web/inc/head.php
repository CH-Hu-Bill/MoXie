<?php
/**
 * 共享 HTML <head> — 所有页面引入
 *
 * 使用前设置:
 *   $pageTitle  — 页面标题 (string, 默认 'ListenWrite')
 *   $requireFonts — 是否加载 Google Fonts (bool, 默认 true)
 *
 * 同时负责：
 *   - 按班级设置决定英文显示字体（默认 / Andika 教学字体）→ <html class="font-en-andika">
 *   - 暴露内网主网址 window.LAN_URL（引导弹窗 / 设置页使用）
 */
$pageTitle ??= 'ListenWrite';
$requireFonts ??= true;
require_once __DIR__ . '/net.php';

// 当前班级：优先 URL 的 id，其次 cookie（由 index.php 写入）
$__cid = (isset($_GET['id']) && is_string($_GET['id'])) ? $_GET['id'] : '';
if ($__cid === '' && isset($_COOKIE['current_class_id']) && is_string($_COOKIE['current_class_id'])) {
    $__cid = $_COOKIE['current_class_id'];
}
$__enFont = ($__cid !== '' && lw_class_en_font($__cid) === 'andika') ? ' class="font-en-andika"' : '';
?><!DOCTYPE html>
<html lang="zh-CN"<?php echo $__enFont; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> - ListenWrite</title>
<link rel="icon" href="favicon.png" type="image/png">
<link rel="shortcut icon" href="favicon.png" type="image/png">
<script>window.LAN_URL = <?php echo json_encode(lw_lan_url()); ?>;</script>
<?php if ($requireFonts): ?>
<!-- 字体已自托管于 web/fonts/，通过 common.css 的 @font-face 加载（无需外部 CDN） -->
<?php endif; ?>
<link rel="stylesheet" href="common.css?v=18">
