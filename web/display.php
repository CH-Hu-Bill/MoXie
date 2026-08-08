<?php
/**
 * ============================================================
 * 壁纸展示大屏页（用于 Lively Wallpaper / 希沃大屏）
 * ============================================================
 *
 * 全屏壁纸展示：顶部公告跑马灯 + 今日默写单词大字海报 + 班级图集轮播。
 * 严格遵循 风格.md（暖纸底 / 手绘圆角 / 硬阴影）。
 *
 * 班级鉴权状态机:
 *   - 无 current_class_id cookie / 班级被删除  → 班级选择页
 *   - cookie 存在但口令已重置/过期/被篡改       → 该班口令弹窗
 *   - 班级无口令                              → 直接进入
 *   - 正常                                   → 渲染壁纸内容
 *
 * URL 参数:
 *   ?id={classId} — 指定班级直达
 *   ?json=1       — 数据接口（供 60s 轮询，需同鉴权）
 *
 * 数据依赖:
 *   data/classes.json, data/classes/{id}/{tasks,words,gallery}.json,
 *   data/announcements.json, data/settings.json(display_bottom_margin)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';

function displayBannerAnnouncement($classId) {
    try {
        $all = Database::getAnnouncements();
        $now = time();
        foreach ($all as $ann) {
            $s = strtotime((string)($ann['start_time'] ?? ''));
            $e = strtotime((string)($ann['end_time'] ?? ''));
            if ($s === false || $e === false || $s > $now || $e < $now) continue;
            if (!in_array('all', $ann['target_classes'] ?? []) && !in_array($classId, $ann['target_classes'] ?? [])) continue;
            if (!in_array('web', $ann['target_platforms'] ?? [])) continue;
            if (($ann['mode'] ?? 'banner') === 'fullscreen') continue;
            return $ann;
        }
    } catch (Throwable $e) {}
    return null;
}

function displayTodayWords($classId) {
    try {
        $tasks = Database::getTasks($classId);
        $today = date('Y-m-d');
        // 取今天最早的 pending 任务（created_at 排序），只展示该任务的单词
        $candidates = [];
        foreach ($tasks as $t) {
            if (($t['status'] ?? '') === 'pending' && ($t['date'] ?? '') === $today) {
                $candidates[] = $t;
            }
        }
        if (empty($candidates)) return [];
        usort($candidates, function($a, $b) {
            $ta = $a['created_at'] ?? ($a['id'] ?? '');
            $tb = $b['created_at'] ?? ($b['id'] ?? '');
            return strcmp((string)$ta, (string)$tb);
        });
        $ids = $candidates[0]['word_ids'] ?? [];
        if (empty($ids)) return [];
        $ids = array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 20);
        $byId = [];
        foreach (Database::getWords($classId) as $w) $byId[(string)$w['id']] = $w;
        $out = [];
        foreach ($ids as $wid) {
            if (!isset($byId[$wid])) continue;
            $out[] = [
                'word' => (string)$byId[$wid]['word'],
                'meaning' => (string)($byId[$wid]['meaning'] ?? ''),
                'pos' => (string)($byId[$wid]['pos'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function displayGallery($classId) {
    try {
        $items = Database::getClassData($classId, 'gallery');
        $out = [];
        foreach ($items as $it) {
            if (empty($it['image'])) continue;
            $out[] = [
                'url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode((string)$it['image']),
                'description' => (string)($it['description'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

$classes = Database::getClasses();
$settings = Database::getSettings();
$bottomMargin = max(0, (int)($settings['display_bottom_margin'] ?? 48));

// 口令验证（复用 index.php 逻辑）
if (isset($_POST['action']) && $_POST['action'] === 'verify_class_password') {
    header('Content-Type: application/json');
    requireCsrf();
    $cid = (string)($_POST['class_id'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    if (!isset($classes[$cid])) { echo json_encode(['success' => false, 'error' => '班级不存在']); exit; }
    $hash = $classes[$cid]['password_hash'] ?? null;
    if (!$hash || password_verify($pw, $hash)) {
        setClassAuthCookie($cid, $classes[$cid]['auth_version'] ?? 1);
        setSecureCookie('current_class_id', $cid, time() + 86400 * 365);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => '口令错误']);
    }
    exit;
}

// 确定当前班级
$targetId = trim((string)($_GET['id'] ?? ''));
$cookieId = (string)($_COOKIE['current_class_id'] ?? '');
$classId = '';
if ($targetId !== '' && isset($classes[$targetId])) {
    $classId = $targetId;
} elseif ($cookieId !== '' && isset($classes[$cookieId])) {
    $classId = $cookieId;
}

// ?json=1 数据接口（轮询）
if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    try {
        if ($classId === '' || !isset($classes[$classId])) {
            echo json_encode(['ok' => false, 'code' => 'class_not_found']);
            exit;
        }
        if (!isClassAuthenticated($classId, $classes[$classId])) {
            echo json_encode(['ok' => false, 'code' => 'need_auth']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'class_name' => (string)($classes[$classId]['name'] ?? ''),
            'announcement' => displayBannerAnnouncement($classId),
            'words' => displayTodayWords($classId),
            'gallery' => displayGallery($classId),
            'bottom_margin' => $bottomMargin,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 状态机判定
$view = 'selection';
$class = null;
if ($classId !== '' && isset($classes[$classId])) {
    $class = $classes[$classId];
    if (isClassAuthenticated($classId, $class)) {
        $view = 'content';
        setSecureCookie('current_class_id', $classId, time() + 86400 * 365);
    } else {
        $view = 'password';
    }
}

// 各视图数据
$className = $view === 'content' ? (string)($class['name'] ?? '') : '';
$banner = $view === 'content' ? displayBannerAnnouncement($classId) : null;
$words = $view === 'content' ? displayTodayWords($classId) : [];
$gallery = $view === 'content' ? displayGallery($classId) : [];

// 班级列表（选择页 + 口令弹窗共用）
$publicClasses = [];
foreach ($classes as $id => $c) {
    $publicClasses[$id] = [
        'id' => $id,
        'name' => (string)($c['name'] ?? ''),
        'has_password' => !empty($c['password_hash']),
        'authenticated' => isClassAuthenticated($id, $c),
    ];
}
$csrfToken = csrfToken();
$pageTitle = '展示大屏';
require 'inc/head.php';
?>
<link rel="stylesheet" href="display.css?v=1">
</head>
<body>
<script>
var CSRF_TOKEN='<?php echo $csrfToken; ?>';
var DISPLAY_VIEW='<?php echo $view; ?>';
var DISPLAY_CID='<?php echo $classId !== '' ? htmlspecialchars($classId, ENT_QUOTES, 'UTF-8') : ''; ?>';
var DISPLAY_CLASSES=<?php echo json_encode($publicClasses, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var DISPLAY_GALLERY=<?php echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var DISPLAY_WORDS=<?php echo json_encode($words, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<div class="display-root" id="displayRoot" data-view="<?php echo $view; ?>"
     style="--bottom-margin:<?php echo (int)$bottomMargin; ?>px">

  <!-- ===== 视图: 班级选择 ===== -->
  <div class="d-view d-select" data-view-name="selection">
    <div class="d-select-card">
      <h1 class="d-select-title">展示大屏</h1>
      <p class="d-select-sub">选择班级进入今日默写壁纸</p>
      <div class="d-class-list" id="classList">
        <?php if (empty($publicClasses)): ?>
          <div class="empty-state">还没有班级</div>
        <?php else: ?>
          <?php foreach ($publicClasses as $cid => $c): ?>
            <div class="card d-class-item" data-cid="<?php echo htmlspecialchars($cid, ENT_QUOTES, 'UTF-8'); ?>"
                 onclick="selectClass('<?php echo htmlspecialchars($cid, ENT_QUOTES, 'UTF-8'); ?>')">
              <span class="d-class-name"><?php echo htmlspecialchars($c['name']); ?></span>
              <span class="d-class-arrow">→</span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===== 视图: 壁纸内容 ===== -->
  <div class="d-view" data-view-name="content">
    <div class="d-divider" id="divider" title="拖动调整可用区域" aria-hidden="true"></div>
    <div class="d-content" id="contentWrap">
      <div class="d-topbar">
        <div class="d-marquee" id="dMarquee" style="color:<?php echo $banner ? htmlspecialchars((string)($banner['color'] ?? '#ff4d4d'), ENT_QUOTES, 'UTF-8') : 'var(--old-paper)'; ?>"
             data-content="<?php echo htmlspecialchars((string)($banner['content'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="d-marquee-track" id="dMarqueeTrack">
            <?php if ($banner): ?>
              <span class="d-marquee-text"><?php echo htmlspecialchars((string)$banner['content']); ?></span>
            <?php else: ?>
              <span class="d-marquee-text">今日无公告</span>
            <?php endif; ?>
          </div>
        </div>
        <span class="d-classname" id="dClassName"><?php echo htmlspecialchars($className); ?></span>
        <button class="d-gear" id="dGear" onclick="openSelect()" title="切换班级" aria-label="切换班级">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        </button>
      </div>

      <div class="d-words" id="dWords">
        <div class="d-words-header">
          <span class="d-words-title">今日默写</span>
          <span class="d-words-date"><?php echo date('Y-m-d'); ?></span>
        </div>
        <?php if (empty($words)): ?>
          <div class="d-placeholder">今日暂无默写任务 ✍️</div>
        <?php else: ?>
          <div class="d-word-grid">
            <?php foreach ($words as $w): ?>
              <div class="d-word">
                <div class="d-word-main"><span class="d-word-scroll"><?php echo htmlspecialchars($w['word']); ?></span><?php if ($w['pos'] !== ''): ?><span class="d-word-pos"><?php echo htmlspecialchars($w['pos']); ?></span><?php endif; ?></div>
                <?php if ($w['meaning'] !== ''): ?><div class="d-word-mean"><span class="d-word-scroll"><?php echo htmlspecialchars($w['meaning']); ?></span></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="d-gallery" id="dGallery">
        <div class="d-gallery-img" id="dGalleryImg">
          <?php if (!empty($gallery)): ?>
            <img id="dGalleryPic" alt="" src="<?php echo htmlspecialchars($gallery[0]['url'], ENT_QUOTES, 'UTF-8'); ?>">
          <?php else: ?>
            <div class="d-placeholder d-placeholder-sm">暂无图集</div>
          <?php endif; ?>
        </div>
        <div class="d-gallery-desc" id="dGalleryDesc">
          <?php if (!empty($gallery) && $gallery[0]['description'] !== ''): ?>
            <?php echo htmlspecialchars($gallery[0]['description']); ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 口令弹窗 -->
<div class="d-modal" id="pwModal">
  <div class="d-modal-content">
    <div class="modal-title">班级口令</div>
    <p style="text-align:center;color:var(--pencil);margin-bottom:12px;opacity:0.7;" id="pwClassName"></p>
    <input type="password" id="pwInput" class="input" placeholder="请输入班级口令" autocomplete="current-password"
           onkeydown="if(event.key==='Enter')submitPassword()" style="margin-bottom:8px;">
    <div style="color:var(--red);font-size:13px;text-align:center;margin-top:8px;min-height:18px;" id="pwError"></div>
    <div class="modal-btns">
      <button type="button" class="cancel" onclick="closePw()">取消</button>
      <button type="button" class="submit" onclick="submitPassword()">确认</button>
    </div>
  </div>
</div>

<script>
(function() {
    var d = document.documentElement;
    // 需要验证口令时自动弹窗（口令已重置 / cookie 失效）
    if (DISPLAY_VIEW === 'password') {
        setTimeout(function() {
            var cid = <?php echo json_encode($classId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            showPw(cid, true);
        }, 400);
    }
})();
</script>
<script src="common.js?v=7"></script>
<script src="display.js?v=2"></script>
</body>
</html>
