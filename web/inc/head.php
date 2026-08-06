<?php
/**
 * 共享 HTML <head> — 所有页面引入
 *
 * 使用前设置:
 *   $pageTitle  — 页面标题 (string, 默认 'ListenWrite')
 *   $requireFonts — 是否加载 Google Fonts (bool, 默认 true)
 */
$pageTitle ??= 'ListenWrite';
$requireFonts ??= true;
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> - ListenWrite</title>
<link rel="icon" href="favicon.png" type="image/png">
<link rel="shortcut icon" href="favicon.png" type="image/png">
<?php if ($requireFonts): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=ZCOOL+KuaiLe&family=Ma+Shan+Zheng&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<?php endif; ?>
<link rel="stylesheet" href="common.css?v=6">