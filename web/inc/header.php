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

// 加载当前有效公告（仅在已选班级时）
$webAnnouncement = null;
if ($classId !== '') {
    try {
        $allAnnouncements = Database::getAnnouncements();
        $now = date('Y-m-d H:i:s');
        foreach ($allAnnouncements as $ann) {
            if ($ann['start_time'] > $now || $ann['end_time'] < $now) continue;
            if (!in_array('all', $ann['target_classes'] ?? []) && !in_array($classId, $ann['target_classes'] ?? [])) continue;
            if (!in_array('web', $ann['target_platforms'] ?? [])) continue;
            $webAnnouncement = $ann;
            break;
        }
    } catch (Throwable $e) {}
}
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
  <div class="title" id="pageTitle" style="<?php echo $webAnnouncement ? 'display:none;' : ''; ?>"><?php echo htmlspecialchars($pageTitle); ?></div>
  <?php if ($webAnnouncement): ?>
  <div class="announcement" id="announcementMarquee" data-ann-id="<?php echo htmlspecialchars($webAnnouncement['id']); ?>">
    <span class="announcement-text"><?php echo htmlspecialchars($webAnnouncement['content']); ?></span>
    <?php if (!empty($webAnnouncement['allow_close'])): ?>
    <button class="announcement-close" onclick="dismissAnnouncement('<?php echo htmlspecialchars($webAnnouncement['id']); ?>')" aria-label="关闭公告">×</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="right"><?php echo $rightContent; ?></div>
</div>
<script>
(function() {
    var annEl = document.getElementById('announcementMarquee');
    if (annEl) {
        var annId = annEl.getAttribute('data-ann-id');
        if (annId && localStorage.getItem('ann_dismissed_' + annId)) {
            annEl.style.display = 'none';
            var pt = document.getElementById('pageTitle');
            if (pt) pt.style.display = '';
        } else {
            // 检测溢出并启用滚动
            var textEl = annEl.querySelector('.announcement-text');
            if (textEl) {
                var over = textEl.scrollWidth - textEl.clientWidth;
                if (over > 4) {
                    textEl.style.setProperty('--mx', '-' + (over + 10) + 'px');
                    textEl.style.setProperty('--md', Math.max(3, over / 35) + 's');
                    textEl.classList.add('scrollable');
                }
            }
        }
    }
})();
function dismissAnnouncement(id) {
    try { localStorage.setItem('ann_dismissed_' + id, '1'); } catch(e) {}
    var el = document.getElementById('announcementMarquee');
    if (el) el.style.display = 'none';
    var pt = document.getElementById('pageTitle');
    if (pt) pt.style.display = '';
}
</script>