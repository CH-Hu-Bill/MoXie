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
// banner（顶部横幅）与 fullscreen（超级霸屏）各取一条，可共存
$webAnnouncement = null;
$webFullscreen = null;
if ($classId !== '') {
    try {
        $allAnnouncements = Database::getAnnouncements();
        $nowTs = time();
        foreach ($allAnnouncements as $ann) {
            $annStartTs = strtotime((string)($ann['start_time'] ?? ''));
            $annEndTs = strtotime((string)($ann['end_time'] ?? ''));
            if ($annStartTs === false || $annEndTs === false || $annStartTs > $nowTs || $annEndTs < $nowTs) continue;
            if (!in_array('all', $ann['target_classes'] ?? []) && !in_array($classId, $ann['target_classes'] ?? [])) continue;
            if (!in_array('web', $ann['target_platforms'] ?? [])) continue;
            $mode = $ann['mode'] ?? 'banner';
            if ($mode === 'fullscreen') {
                if ($webFullscreen === null) $webFullscreen = $ann;
            } elseif ($webAnnouncement === null) {
                $webAnnouncement = $ann;
            }
            if ($webAnnouncement !== null && $webFullscreen !== null) break;
        }
    } catch (Throwable $e) {}
}
$annColor = $webAnnouncement ? htmlspecialchars((string)($webAnnouncement['color'] ?? '#ff4d4d'), ENT_QUOTES, 'UTF-8') : '';
// 超级霸屏数据注入页面（供加载蒙版 / 新页霸屏层使用）
$fsColor = $webFullscreen ? htmlspecialchars((string)($webFullscreen['color'] ?? '#ff4d4d'), ENT_QUOTES, 'UTF-8') : '#ff4d4d';
$fsContent = $webFullscreen ? htmlspecialchars((string)$webFullscreen['content'], ENT_QUOTES, 'UTF-8') : '';
$fsSeconds = $webFullscreen ? max(1, min(5, (int)($webFullscreen['fullscreen_seconds'] ?? 1))) : 1;
?>
<div class="status-bar">
  <div class="left">
    <button class="back-btn" onclick="showOkOverlayThen('<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>')" aria-label="返回">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <?php if ($className !== ''): ?>
      <span class="class-name"><?php echo htmlspecialchars($className); ?></span>
    <?php endif; ?>
    <?php if ($webAnnouncement && $pageTitle !== ''): ?>
      <span class="page-name" id="statusPageName"><?php echo htmlspecialchars($pageTitle); ?></span>
    <?php endif; ?>
  </div>
  <div class="title" id="pageTitle" style="<?php echo $webAnnouncement ? 'display:none;' : ''; ?>"><?php echo htmlspecialchars($pageTitle); ?></div>
  <?php if ($webAnnouncement): ?>
  <div class="announcement" id="announcementMarquee" data-ann-id="<?php echo htmlspecialchars($webAnnouncement['id']); ?>" style="color:<?php echo $annColor; ?>">
    <div class="announcement-track" id="announcementTrack">
      <span class="announcement-text"><?php echo htmlspecialchars($webAnnouncement['content']); ?></span>
    </div>
    <?php if (!empty($webAnnouncement['allow_close'])): ?>
    <button class="announcement-close" onclick="dismissAnnouncement('<?php echo htmlspecialchars($webAnnouncement['id']); ?>')" aria-label="关闭公告">×</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="right"><?php echo $rightContent; ?></div>
</div>
<script>
(function() {
    function restoreNoBanner() {
        var ann = document.getElementById('announcementMarquee');
        if (ann) ann.style.display = 'none';
        var pn = document.getElementById('statusPageName');
        if (pn) pn.style.display = 'none';
        var pt = document.getElementById('pageTitle');
        if (pt) pt.style.display = '';
    }
    function setupMarquee() {
        var ann = document.getElementById('announcementMarquee');
        if (!ann) return;
        var annId = ann.getAttribute('data-ann-id');
        if (annId && localStorage.getItem('ann_dismissed_' + annId)) {
            restoreNoBanner();
            return;
        }
        var track = document.getElementById('announcementTrack');
        var textEl = ann.querySelector('.announcement-text');
        if (!track || !textEl) return;
        if (track.querySelector('.announcement-marquee')) return; // 已构建
        var avail = track.clientWidth;
        var tw = textEl.scrollWidth;
        if (tw > avail + 4) {
            // 无缝跑马灯：两份文本 + translateX(-50%) 恰好移动一个副本，无需像素测量
            var textHtml = textEl.outerHTML;
            track.innerHTML = '<div class="announcement-marquee">' + textHtml + textHtml + '</div>';
            var marquee = track.querySelector('.announcement-marquee');
            marquee.style.setProperty('--dur', Math.max(5, (avail + tw) / 40) + 's');
            track.classList.add('track-scroll');
        }
    }
    // 必须等布局完成后再测量，否则 clientWidth/scrollWidth 为 0 导致跑马灯不生效
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupMarquee);
    } else {
        setupMarquee();
    }
    window.addEventListener('load', setupMarquee);
    setTimeout(setupMarquee, 200);
    window.dismissAnnouncement = function(id) {
        try { localStorage.setItem('ann_dismissed_' + id, '1'); } catch(e) {}
        restoreNoBanner();
    };
})();
</script>
<script>
// 超级霸屏公告数据（active 且平台含 web），供加载蒙版/新页霸屏层使用
window.__FS_ANN = <?php
if ($webFullscreen) {
    echo json_encode([
        'content' => (string)$webFullscreen['content'],
        'color' => $fsColor,
        'seconds' => $fsSeconds,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} else {
    echo 'null';
}
?>;
</script>