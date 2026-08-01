<?php
/**
 * 共享状态栏 — 所有页面引入
 *
 * 使用前设置:
 *   $backUrl    — 返回按钮的跳转 URL (string)
 *   $className  — 班级名称 (string)
 *   $pageTitle  — 页面标题 (string)
 *   $rightContent — 右侧操作区 HTML (string, 可选)
 *   $classId    — 班级 ID (string)
 */
$backUrl    ??= 'index.php';
$className  ??= '';
$pageTitle  ??= '';
$rightContent ??= '';
$classId    ??= '';
?>
<div class="status-bar">
  <div class="left">
    <button class="back-btn" onclick="showOkOverlayThen('<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>')" aria-label="返回">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <?php if ($className !== ''): ?>
      <span class="class-name"><?php echo htmlspecialchars($className); ?></span>
    <?php endif; ?>
  </div>
  <div class="title"><?php echo htmlspecialchars($pageTitle); ?></div>
  <div class="right"><?php echo $rightContent; ?></div>
</div>