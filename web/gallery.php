<?php
/**
 * 班级图集 — 图片上传 + 画廊展示
 * 用户上传图片并配文，以画廊形式展示。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/history.php';
$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

$gallery = Database::getClassData($classId, 'gallery');
if (!is_array($gallery)) $gallery = [];

// 自愈：过滤掉图片文件已丢失的条目，并清理 gallery.json
// 清理在文件锁内进行（updateClassData），避免与 save_gallery 并发时用旧数组覆盖丢条目
$validGallery = [];
$cleaned = false;
foreach ($gallery as $item) {
    $imgFile = $item['image'] ?? '';
    if ($imgFile !== '' && Database::getUploadedImagePath($classId, $imgFile) !== null) {
        $validGallery[] = $item;
    } else {
        $cleaned = true;
    }
}
if ($cleaned) {
    Database::updateClassData($classId, 'gallery', function($latest) use ($classId) {
        if (!is_array($latest)) return null;
        $valid = [];
        foreach ($latest as $item) {
            $imgFile = $item['image'] ?? '';
            if ($imgFile !== '' && Database::getUploadedImagePath($classId, $imgFile) !== null) {
                $valid[] = $item;
            }
        }
        return $valid;
    });
    $gallery = $validGallery;
}
$csrfToken = csrfToken();

// ========== ?json=1 数据接口（供上传成功后局部刷新网格，不整页跳转） ==========
if (reqGet('json') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'items' => $gallery], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ========== save_gallery ==========
if (isset($_POST['action']) && $_POST['action'] === 'save_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $image = $_FILES['image'] ?? null;
    if (!$image || ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => '请选择图片']); exit;
    }
    $desc = trim(sanitizePlainText(reqPost('description')));
    if ($desc === '') { echo json_encode(['success' => false, 'error' => '请填写描述']); exit; }
    if (mb_strlen($desc) > 500) { echo json_encode(['success' => false, 'error' => '描述不能超过500字']); exit; }
    $isGif = false;
    $isMp4 = false;
    if (isset($image['tmp_name']) && is_file($image['tmp_name'])) {
        $info = @getimagesize($image['tmp_name']);
        $isGif = is_array($info) && ($info[2] ?? 0) === IMAGETYPE_GIF;
        if (!$isGif) $isMp4 = Database::isMp4File($image['tmp_name']);
    }
    if ($isMp4) {
        if (($image['size'] ?? 0) > Database::MP4_MAX_BYTES) { echo json_encode(['success' => false, 'error' => '视频最大 15MB']); exit; }
    } else {
        $maxBytes = $isGif ? Database::GIF_MAX_BYTES : Database::UPLOAD_MAX_BYTES;
        if (($image['size'] ?? 0) > $maxBytes) { echo json_encode(['success' => false, 'error' => $isGif ? 'GIF 动图最大 16MB' : '图片最大 12MB']); exit; }
    }

    try {
        $filename = Database::saveUploadedImage($classId, $image['tmp_name']);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }
    // 首帧缩略图由 CLI 计划任务 cron_gallery_thumbs.php 生成（FPM 禁用了 exec/proc_open，
    // 这里不再内联调用，避免在请求路径加载 composer/php-ffmpeg 引发偶发超时或 PHP 警告污染 JSON）

    $id = bin2hex(random_bytes(16));
    try {
        $result = Database::updateClassData($classId, 'gallery', function($latest) use ($id, $filename, $desc) {
            if (!is_array($latest)) $latest = [];
            array_unshift($latest, ['id' => $id, 'image' => $filename, 'description' => $desc, 'uploaded_at' => date('Y-m-d H:i:s')]);
            return $latest;
        });
        if ($result === false) throw new RuntimeException('数据保存失败');
    } catch (Throwable $e) {
        // 写库失败：回滚已落盘的文件与缩略图，避免孤儿文件泄漏
        Database::deleteUploadedImage($classId, $filename);
        Database::deleteVideoThumb($classId, $filename);
        echo json_encode(['success' => false, 'error' => '保存失败，请重试']); exit;
    }
    echo json_encode(['success' => true, 'id' => $id]); exit;
}

// ========== update_gallery ==========
if (isset($_POST['action']) && $_POST['action'] === 'update_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $id = reqPost('id');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { echo json_encode(['success' => false, 'error' => '无效ID']); exit; }
    $desc = sanitizePlainText(reqPost('description'));
    if ($desc === '') { echo json_encode(['success' => false, 'error' => '描述不能为空']); exit; }
    if (mb_strlen($desc) > 500) { echo json_encode(['success' => false, 'error' => '描述不能超过500字']); exit; }
    $updated = false;
    Database::updateClassData($classId, 'gallery', function($latest) use ($id, $desc, &$updated) {
        if (!is_array($latest)) return null;
        foreach ($latest as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                $latest[$i]['description'] = $desc;
                $updated = true;
                return $latest;
            }
        }
        return null;
    });
    echo json_encode(['success' => $updated]); exit;
}

// ========== delete_gallery ==========
if (isset($_POST['action']) && $_POST['action'] === 'delete_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $id = reqPost('id');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { echo json_encode(['success' => false, 'error' => '无效ID']); exit; }
    Database::updateClassData($classId, 'gallery', function($latest) use ($id, $classId) {
        if (!is_array($latest)) return null;
        foreach ($latest as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                Database::deleteUploadedImage($classId, $item['image'] ?? '');
                Database::deleteVideoThumb($classId, $item['image'] ?? '');
                array_splice($latest, $i, 1);
                return $latest;
            }
        }
        return null;
    });
    echo json_encode(['success' => true]); exit;
}
?>
<?php $pageTitle = '班级图集'; require 'inc/head.php'; ?>
<body>
<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<?php
$backUrl = 'main.php?id=' . $classId;
$className = $class['name'];
$pageTitle = '班级图集';
require 'inc/header.php';
?>

<div class="content">
    <div class="card mb-4" id="uploadCard">
        <h3 style="font-family:var(--font-heading);margin-bottom:12px;color:var(--pencil);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg> 上传图片 / 视频</h3>
        <div class="upload-form" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:12px;">
            <div class="upload-preview-wrap" id="uploadPreviewWrap" style="display:none;width:100%;justify-content:center;margin-bottom:6px;">
                <div style="position:relative;max-width:100%;overflow:hidden;">
                    <img id="uploadPreview" alt="" style="display:none;max-width:100%;max-height:180px;border-radius:var(--wobbly-sm);border:2px solid var(--pencil);box-shadow:var(--shadow-sm);">
                    <video id="uploadPreviewVideo" style="display:none;max-width:100%;max-height:180px;border-radius:var(--wobbly-sm);border:2px solid var(--pencil);box-shadow:var(--shadow-sm);" controls muted playsinline></video>
                    <span class="gif-badge" id="previewGifBadge" style="display:none;">GIF</span>
                    <span class="gif-badge mp4-badge" id="previewMp4Badge" style="display:none;">MP4</span>
                </div>
            </div>
            <div id="uploadHint" style="width:100%;text-align:center;font-size:13px;color:var(--red);min-height:18px;margin-bottom:4px;"></div>
            <label class="input upload-file-btn" for="galleryImage" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;border-style:dashed;justify-content:center;min-width:220px;flex:1;">
                <span id="uploadFileName"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg> 点击选择 / 拖拽 / Ctrl+V 粘贴（JPG/PNG/WebP/GIF/MP4）</span>
                <input type="file" id="galleryImage" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime" style="display:none;" onchange="previewUpload()">
            </label>
            <input type="text" class="input" id="galleryDesc" placeholder="写一段关于这张图片/视频的话…" maxlength="500" style="flex:2;min-width:250px;">
            <button class="btn btn-primary" onclick="uploadGallery()" id="uploadBtn">上传</button>
            <button class="btn" onclick="clearUpload()" id="clearBtn" style="display:none;">清除</button>
        </div>
    </div>

    <div class="card mb-4" style="font-size:13px;color:#888;">
<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> 公开 API：<code class="tag">gallery_api.php?class_id=<?php echo htmlspecialchars($classId); ?></code>，每次随机返回一张图集图片；可在<a href="settings.php?id=<?php echo htmlspecialchars($classId); ?>" style="color:var(--blue);">设置页</a>为本班启用 API 密钥保护，启用后调用需加 <code class="tag">&amp;apikey=你的密钥</code>
    </div>

    <div class="gallery-grid" id="galleryGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;"></div>
    <div class="empty-state" id="emptyState" style="display:none"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-8px;"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg><p>还没有图片，上传第一张吧</p></div>
</div>

<!-- Lightbox：左侧媒体 + 右侧描述整列。display 由 .lightbox/.lightbox.active 控制（勿内联 display:flex，否则常显关不掉）；固定定位/背景/层级必须内联补齐 -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()" style="position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:5000;align-items:center;justify-content:center;gap:26px;padding:5vh 3vw;box-sizing:border-box;">
    <button class="btn" onclick="closeLightbox()" style="position:fixed;top:18px;right:18px;width:44px;height:44px;border-radius:50%;font-size:20px;z-index:2;display:flex;align-items:center;justify-content:center;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    <div id="lbMedia" onclick="event.stopPropagation()" style="flex:1 1 58%;min-width:0;height:100%;display:flex;align-items:center;justify-content:center;position:relative;">
        <img id="lbImg" src="" alt="" style="display:none;max-width:100%;max-height:100%;border:3px solid var(--pencil);border-radius:var(--wobbly);box-shadow:var(--shadow-lg);object-fit:contain;">
        <video id="lbVideo" style="display:none;max-width:100%;max-height:100%;border:3px solid var(--pencil);border-radius:var(--wobbly);box-shadow:var(--shadow-lg);background:#000;" controls playsinline preload="auto"></video>
        <div id="lbLoadTip" style="display:none;position:absolute;top:14px;left:50%;transform:translateX(-50%);z-index:3;padding:6px 16px;background:rgba(0,0,0,.65);color:#fff;border:1.5px solid rgba(255,255,255,.5);border-radius:999px;font-size:13px;pointer-events:none;white-space:nowrap;">正在加载…</div>
    </div>
    <div id="lbDesc" onclick="event.stopPropagation()" style="flex:0 0 34%;max-width:34%;align-self:stretch;box-sizing:border-box;display:flex;flex-direction:column;min-width:0;padding:14px 18px;background:rgba(0,0,0,0.5);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);color:var(--white);font-size:15px;text-align:center;line-height:1.7;">
        <div style="font-size:12px;opacity:.65;padding-bottom:10px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> 描述</div>
        <div id="lbDescScroll" style="flex:1;min-height:0;overflow:hidden;"><span id="lbDescText"></span></div>
    </div>
</div>

<script src="common.js?v=9"></script>
<script>
var classId = <?php echo json_encode($classId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var galleryData = <?php echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
// 描述不内嵌到 HTML onclick 属性（含换行/过长会破坏 JS 导致卡片打不开），统一存映射表按 id 取
var galleryDescMap = {};

function renderGallery() {
    var grid = document.getElementById('galleryGrid');
    var empty = document.getElementById('emptyState');
    if (!galleryData.length) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    grid.innerHTML = galleryData.map(function(item, idx) {
        galleryDescMap[item.id] = item.description;
        var url = 'upload.php?class_id=' + classId + '&file=' + item.image;
        var dateText = formatDate(item.uploaded_at);
        var isGif = /\.gif$/i.test(item.image);
        var isMp4 = /\.mp4$/i.test(item.image);
        var media;
        // 占位层 z-index:2 位于媒体之上；媒体加载完成后由 hideGalleryPlaceholder 隐藏
        var placeholder = '<div class="gallery-placeholder" style="position:absolute;top:0;left:0;right:0;bottom:0;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#c9c2b6;font-size:28px;pointer-events:none;"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg><span style="font-size:11px;color:#bbb;margin-top:4px;">加载中…</span></div>';
        if (isMp4) {
            // 视频卡片：首帧缩略图（ffmpeg）+ 原生 poster 双保险。
            // - 首帧图先加载并显示（onload 隐藏占位）——先加载首帧，用户立刻看到预览；
            // - 视频缓冲期间由原生 poster 继续显示首帧（video 背景透明，绝不黑屏）；
            // - 占位只在真正开始播放时才隐藏（onplaying），缓冲慢时仍能看到"加载中…"；
            // - preload=metadata + IntersectionObserver 视口调度：进视口才 play() 下载/解码，
            //   不再首屏全量并发下载所有视频（多解码器并发是点开视频卡顿的根源）。
            var thumbUrl = 'video_thumb.php?class_id=' + classId + '&file=' + item.image;
            media = '<img class="video-poster" src="' + thumbUrl + '" alt="" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;z-index:1;" onload="hideGalleryPlaceholder(this)" onerror="markGalleryError(this)">'
                + '<video src="' + url + '" poster="' + thumbUrl + '" muted loop playsinline preload="metadata" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;pointer-events:none;background:transparent;z-index:1;" onplaying="hideGalleryPlaceholder(this)" onerror="markGalleryError(this)"></video>';
        } else {
            media = '<img src="' + url + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .35s ease;position:relative;z-index:1;" onload="this.style.opacity=1;hideGalleryPlaceholder(this)" onerror="this.style.opacity=0;markGalleryError(this)">';
        }
        return '<div class="card gallery-card rotate-' + (idx % 2 === 0 ? '1' : '-1') + '" onclick="openLightbox(\'' + url + '\', \'' + item.id + '\', ' + isMp4 + ', \'' + (isMp4 ? thumbUrl : '') + '\')" style="overflow:hidden;cursor:pointer;padding:0;">'
            + '<div class="img-wrap" style="width:100%;aspect-ratio:4/3;overflow:hidden;background:#f0f0f0;position:relative;">'
            + placeholder
            + media
            + (isGif ? '<span class="gif-badge">GIF</span>' : '')
            + (isMp4 ? '<span class="gif-badge mp4-badge">MP4</span>' : '')
            + '</div>'
            + '<div class="info" style="padding:14px 16px;">'
            + '<div class="desc" style="font-size:14px;line-height:1.6;color:var(--pencil);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">' + escapeHtml(item.description) + '</div>'
            + '<div class="meta" style="font-size:12px;color:#888;margin-top:8px;display:flex;justify-content:space-between;align-items:center;"><span class="date"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> ' + dateText + '</span>'
            + '<span style="display:flex;gap:6px;"><button class="btn btn-sm" onclick="event.stopPropagation();editGallery(\'' + item.id + '\')" style="padding:2px 10px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>'
            + '<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteGallery(\'' + item.id + '\')">🗑️</button></span>'
            + '</div></div></div>';
    }).join('');
    initGridVideos(); // 重渲染后重新挂视口调度（新 video 元素需要重新 observe）
}
function hideGalleryPlaceholder(el) {
    if (!el) return;
    var p = el.parentNode;
    if (!p) return;
    var ph = p.querySelector('.gallery-placeholder');
    if (ph) ph.style.display = 'none';
}
function markGalleryError(el) {
    // 媒体加载失败：占位从「加载中…」改为「媒体已失效」，避免占位永久显示
    if (!el) return;
    var p = el.parentNode;
    if (!p) return;
    var ph = p.querySelector('.gallery-placeholder');
    if (ph) {
        ph.innerHTML = '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg><span style="font-size:11px;color:#bbb;margin-top:4px;">媒体已失效</span>';
        ph.style.display = 'flex';
    }
}

// 网格视频视口调度：进入视口的视频才 play()（触发下载/解码），离开视口 pause()。
// 配合 preload=metadata，首屏不再同时全量下载所有视频（多解码器并发是卡顿根源）。
var gridVideoObserver = ('IntersectionObserver' in window) ? new IntersectionObserver(function(entries) {
    entries.forEach(function(en) {
        var v = en.target;
        if (en.isIntersecting) {
            try { var p = v.play(); if (p && p.catch) p.catch(function() {}); } catch (e) {}
        } else {
            try { v.pause(); } catch (e) {}
        }
    });
}, { rootMargin: '150px' }) : null;
function initGridVideos() {
    if (!gridVideoObserver) return;
    document.querySelectorAll('.gallery-card video').forEach(function(v) { gridVideoObserver.observe(v); });
}

function formatDate(s) {
    if (!s) return '';
    return s.length >= 16 ? s.substring(0, 16).replace(' ', ' ') : s.substring(0, 10);
}

var lbResumeVideos = [];  // 打开灯箱前正在播放的网格视频（关闭时只恢复这些）
var lbToken = 0;          // 世代号：快速切换灯箱内容时，旧媒体的回调不再生效
function openLightbox(url, id, isMp4, thumbUrl) {
    document.getElementById('lightbox').classList.add('active');
    // 打开详情：暂停网格里所有预览视频/GIF（多解码器并发是点开视频卡顿的根源），关闭后恢复
    document.body.classList.add('lb-open');
    lbResumeVideos = [];
    document.querySelectorAll('.gallery-card video').forEach(function(v) {
        if (!v.paused) lbResumeVideos.push(v);
        try { v.pause(); } catch (e) {}
    });
    var myToken = ++lbToken;
    var img = document.getElementById('lbImg');
    var video = document.getElementById('lbVideo');
    var descText = document.getElementById('lbDescText');
    descText.textContent = galleryDescMap[id] || '';
    // 描述过长：右列内来回自动滚动；用户鼠标悬停/触摸可暂停，离开 2 秒后恢复
    setTimeout(function() { lbDescScroll(); }, 0);
    // 描述框宽度按媒体宽高比自适应：竖图给描述更宽、横图更窄
    var lbLoadTip = document.getElementById('lbLoadTip');
    var lbHideLoad = function() { if (lbLoadTip) lbLoadTip.style.display = 'none'; };
    var sizeDescForMedia = function(w, h) {
        var d = document.getElementById('lbDesc');
        if (!d || !w || !h) return;
        var ratio = w / Math.max(1, h);
        var basis = 34;
        if (ratio < 0.75) basis = 42;      // 竖图/接近 9:16
        else if (ratio < 1.1) basis = 38;  // 接近方形
        else if (ratio > 1.8) basis = 30;  // 超宽横幅
        d.style.flex = '0 0 ' + basis + '%';
        d.style.maxWidth = basis + '%';
    };
    if (isMp4) {
        img.style.display = 'none';
        video.style.display = '';
        // 视频加载进度：服务器带宽小时正片下载慢，显示缓冲百分比避免"点开没反应"的错觉
        if (lbLoadTip) { lbLoadTip.style.display = 'flex'; lbLoadTip.textContent = '正在加载…'; }
        video.onprogress = function() {
            if (myToken !== lbToken) return;
            try {
                if (video.duration && video.buffered.length) {
                    var pct = Math.min(99, Math.round(video.buffered.end(video.buffered.length - 1) / video.duration * 100));
                    if (lbLoadTip) lbLoadTip.textContent = '正在加载 ' + pct + '%';
                }
            } catch (e) {}
        };
        video.oncanplay = lbHideLoad;
        video.onplaying = lbHideLoad;
        video.onloadedmetadata = function() {
            if (myToken !== lbToken) return; // 已切到其他项，忽略过期回调
            sizeDescForMedia(video.videoWidth, video.videoHeight);
            setTimeout(lbDescScroll, 0); // 布局变化后重测溢出
        };
        video.onerror = function() { if (myToken === lbToken) { lbHideLoad(); showToast('媒体已失效', 'error'); } };
        // 首帧图作 poster：缓冲/加载时显示预览，不黑屏
        video.poster = thumbUrl || '';
        video.src = url;
        video.muted = true;
        try { var pv = video.play(); if (pv && pv.catch) pv.catch(function() {}); } catch (e) {}
    } else {
        lbHideLoad();
        video.pause(); video.src = '';
        video.onerror = null; // 图片项不保留视频的 error 回调
        video.style.display = 'none';
        img.style.display = '';
        img.onload = function() {
            if (myToken !== lbToken) return;
            sizeDescForMedia(img.naturalWidth, img.naturalHeight);
            setTimeout(lbDescScroll, 0);
        };
        img.onerror = function() { if (myToken === lbToken) showToast('媒体已失效', 'error'); };
        img.src = url;
    }
}
function closeLightbox() {
    lbDescStop();
    document.getElementById('lightbox').classList.remove('active');
    // 关闭详情：只恢复打开前正在播放的网格视频（不再无条件全量 play()）
    document.body.classList.remove('lb-open');
    lbResumeVideos.forEach(function(v) {
        try { var p = v.play(); if (p && p.catch) p.catch(function() {}); } catch (e) {}
    });
    lbResumeVideos = [];
    // 释放灯箱视频解码资源（不释放则解码帧/缓冲常驻内存）
    var video = document.getElementById('lbVideo');
    video.pause();
    video.removeAttribute('src');
    video.load();
    var tip = document.getElementById('lbLoadTip');
    if (tip) tip.style.display = 'none';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });

/* 灯箱描述自动滚动（来回往返式，参考单词跑马灯）：缓慢滚到底 → 停留 → 缓慢滚回顶部 → 停留，循环。
   用 requestAnimationFrame 逐帧驱动，平滑无跳变；边缘渐变蒙版（CSS mask）隐藏截断。
   用户鼠标悬停/触摸暂停，离开 2 秒后恢复。 */
var _lbDescHandle = null;
function lbDescStop() {
    if (_lbDescHandle) { cancelAnimationFrame(_lbDescHandle.raf); _lbDescHandle = null; }
}
function lbDescScroll() {
    lbDescStop();
    var d = document.getElementById('lbDescScroll');
    if (!d) return;
    var max = d.scrollHeight - d.clientHeight;
    if (max <= 4) {
        d.scrollTop = 0;
        d.style.webkitMaskImage = '';
        d.style.maskImage = '';
        return;
    }
    // 溢出才加渐变蒙版（防硬截断；不溢出时保持清晰可读）
    d.style.webkitMaskImage = 'linear-gradient(180deg, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%)';
    d.style.maskImage = 'linear-gradient(180deg, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%)';
    var speed = 22;           // px/s，缓慢
    var pos = 0, dir = 1;
    var last = performance.now(), pauseUntil = 0;
    var st = {};
    var tick = function(now) {
        if (_lbDescHandle !== st) return; // 已被 stop 或重新滚动
        var dt = (now - last) / 1000; last = now;
        if (now < pauseUntil) { _lbDescHandle.raf = requestAnimationFrame(tick); return; }
        pos += dir * speed * dt;
        if (pos >= max) { pos = max; dir = -1; pauseUntil = now + 1000; }
        else if (pos <= 0) { pos = 0; dir = 1; pauseUntil = now + 1000; }
        d.scrollTop = pos;
        _lbDescHandle.raf = requestAnimationFrame(tick);
    };
    _lbDescHandle = st;
    _lbDescHandle.raf = requestAnimationFrame(tick);
}
(function() {
    var box = document.getElementById('lbDesc');
    if (!box) return;
    box.addEventListener('mouseenter', lbDescStop);
    box.addEventListener('mouseleave', function() { setTimeout(lbDescScroll, 2000); });
    box.addEventListener('touchstart', lbDescStop, { passive: true });
    box.addEventListener('touchend', function() { setTimeout(lbDescScroll, 2000); });
})();

function previewUpload() {
    var input = document.getElementById('galleryImage');
    var wrap = document.getElementById('uploadPreviewWrap');
    var img = document.getElementById('uploadPreview');
    var video = document.getElementById('uploadPreviewVideo');
    var gifBadge = document.getElementById('previewGifBadge');
    var mp4Badge = document.getElementById('previewMp4Badge');
    var nameEl = document.getElementById('uploadFileName');
    var clearBtn = document.getElementById('clearBtn');
    var btn = document.getElementById('uploadBtn');
    var hint = document.getElementById('uploadHint');
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    if (nameEl) nameEl.textContent = file.name;
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    var isGif = ext === 'gif';
    var isMp4 = ext === 'mp4' || ext === 'mov';
    if (gifBadge) gifBadge.style.display = isGif ? '' : 'none';
    if (mp4Badge) mp4Badge.style.display = isMp4 ? '' : 'none';
    img.style.display = 'none';
    video.style.display = 'none';
    if (video.src) { URL.revokeObjectURL(video.src); video.removeAttribute('src'); }
    btn.disabled = false;
    btn.textContent = '上传';
    if (hint) hint.textContent = '';

    // 格式预校验
    var imgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!imgExts.includes(ext) && !isMp4) {
        if (hint) hint.textContent = '不支持的文件格式：' + file.name + '（仅支持 JPG/PNG/WebP/GIF/MP4）';
        btn.disabled = true;
        btn.textContent = '无法上传';
        wrap.style.display = 'flex';
        if (clearBtn) clearBtn.style.display = '';
        return;
    }

    // 大小预校验
    var maxBytes = isMp4 ? 15728640 : (isGif ? 16777216 : 12582912);
    var maxLabel = isMp4 ? '15MB' : (isGif ? '16MB' : '12MB');
    if (file.size > maxBytes) {
        if (hint) hint.textContent = '文件过大：' + (file.size / 1024 / 1024).toFixed(1) + 'MB（' + maxLabel + ' 以内）';
        btn.disabled = true;
        btn.textContent = '无法上传';
        wrap.style.display = 'flex';
        if (clearBtn) clearBtn.style.display = '';
        return;
    }

    if (isMp4) {
        video.src = URL.createObjectURL(file);
        video.style.display = '';
        video.muted = true;
        video.load();
        // 视频时长预校验（≤30s）
        video.onloadedmetadata = function() {
            if (video.duration && video.duration > 30.5) {
                if (hint) hint.textContent = '视频时长 ' + Math.round(video.duration) + ' 秒，不能超过 30 秒';
                btn.disabled = true;
                btn.textContent = '无法上传';
            }
        };
    } else {
        var reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.style.display = '';
        };
        reader.readAsDataURL(file);
    }
    wrap.style.display = 'flex';
    if (clearBtn) clearBtn.style.display = '';
}

function clearUpload() {
    document.getElementById('galleryImage').value = '';
    document.getElementById('galleryDesc').value = '';
    var wrap = document.getElementById('uploadPreviewWrap');
    wrap.style.display = 'none';
    var img = document.getElementById('uploadPreview');
    var video = document.getElementById('uploadPreviewVideo');
    var hint = document.getElementById('uploadHint');
    if (hint) hint.textContent = '';
    img.style.display = 'none'; img.src = '';
    if (video.src) URL.revokeObjectURL(video.src);
    video.removeAttribute('src'); video.style.display = 'none';
    document.getElementById('uploadFileName').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg> 点击选择 / 拖拽 / Ctrl+V 粘贴（JPG/PNG/WebP/GIF/MP4）';
    document.getElementById('clearBtn').style.display = 'none';
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadBtn').textContent = '上传';
}

async function uploadGallery() {
    var fileInput = document.getElementById('galleryImage');
    var desc = document.getElementById('galleryDesc').value.trim();
    var hint = document.getElementById('uploadHint');
    if (hint) hint.textContent = '';
    if (!fileInput.files || !fileInput.files[0]) { showToast('请选择文件'); return; }
    if (!desc) { showToast('请填写描述'); return; }
    var file = fileInput.files[0];
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    var isMp4 = ext === 'mp4' || ext === 'mov';
    var isGif = ext === 'gif';
    var maxBytes = isMp4 ? 15728640 : (isGif ? 16777216 : 12582912);
    if (file.size > maxBytes) { showToast((isMp4 ? '视频最大 15MB' : isGif ? 'GIF 最大 16MB' : '图片最大 12MB')); return; }
    if (!isMp4 && !['jpg','jpeg','png','webp','gif'].includes(ext)) { showToast('不支持的文件格式'); return; }
    var btn = document.getElementById('uploadBtn'); btn.disabled = true; btn.textContent = '上传中…';
    var fd = new FormData();
    fd.append('action', 'save_gallery');
    fd.append('image', file);
    fd.append('description', desc);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    // 60s 超时，避免超大文件卡死无反馈
    var controller = new AbortController();
    var timer = setTimeout(function() { controller.abort(); }, 60000);
    try {
        var resp = await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd, signal: controller.signal });
        // 先取文本再解析：服务器若混入 PHP 警告/错误会破坏 JSON，给用户明确提示而非晦涩的"网络错误"
        var txt = await resp.text();
        var r;
        try { r = JSON.parse(txt); } catch (e) {
            var msg = '服务器响应异常' + (resp.ok ? '' : '（HTTP ' + resp.status + '）') + '，请重试';
            showToast(msg);
            if (hint) hint.textContent = '上传失败：' + msg;
            return;
        }
        if (r.success) {
            showToast('上传成功');
            clearUpload();
            // 局部刷新网格（不整页跳转，保留滚动位置）
            var j = await (await fetch('gallery.php?id=' + classId + '&json=1', { cache: 'no-store' })).json();
            if (j.success) { galleryData = j.items || []; renderGallery(); }
        } else {
            var em = r.error || '上传失败，请重试';
            showToast(em);
            if (hint) hint.textContent = '上传失败：' + em;
        }
    } catch(e) {
        var msg2 = e.name === 'AbortError' ? '上传超时（60 秒），请检查网络或换小一点的文件' : '网络错误，请重试';
        showToast(msg2);
        if (hint) hint.textContent = '上传失败：' + msg2;
    } finally { clearTimeout(timer); }
    btn.disabled = false; btn.textContent = '上传';
}

async function deleteGallery(id) {
    if (!confirm('确定删除这张图片吗？')) return;
    var fd = new FormData();
    fd.append('action', 'delete_gallery');
    fd.append('id', id);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        var r = await (await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd })).json();
        if (r.success) {
            galleryData = galleryData.filter(function(item) { return item.id !== id; });
            renderGallery();
        }
    } catch(e) { showToast('删除失败'); }
}

function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function editGallery(id) {
    var currentDesc = galleryDescMap[id] || '';
    var newDesc = prompt('修改图片描述：', currentDesc);
    if (newDesc === null) return;
    newDesc = newDesc.trim();
    if (!newDesc) { showToast('描述不能为空'); return; }
    if (newDesc.length > 500) { showToast('描述不能超过500字'); return; }
    var fd = new FormData();
    fd.append('action', 'update_gallery');
    fd.append('id', id);
    fd.append('description', newDesc);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    fetch('gallery.php?id=' + classId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (r.success) {
                galleryData = galleryData.map(function(item) {
                    return item.id === id ? Object.assign({}, item, { description: newDesc }) : item;
                });
// 上传区拖拽 / Ctrl+V 粘贴（绕开 Windows 触屏设备文件选择对话框卡死问题）
function setupUploadHelpers() {
    var input = document.getElementById('galleryImage');
    var dropZone = input.closest('.upload-file-btn');
    var setFile = function(f) {
        if (!f) return;
        try {
            var dt = new DataTransfer();
            dt.items.add(f);
            input.files = dt.files;
        } catch (err) {}
        previewUpload();
    };
    if (dropZone) {
        ['dragenter', 'dragover'].forEach(function(ev) {
            dropZone.addEventListener(ev, function(e) {
                e.preventDefault(); e.stopPropagation();
                dropZone.style.borderColor = 'var(--blue)';
                dropZone.style.background = 'rgba(45,93,161,.06)';
                dropZone.style.color = 'var(--blue)';
            });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
            dropZone.addEventListener(ev, function(e) {
                e.preventDefault(); e.stopPropagation();
                dropZone.style.borderColor = '';
                dropZone.style.background = '';
                dropZone.style.color = '';
            });
        });
        dropZone.addEventListener('drop', function(e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            setFile(f);
        });
    }
    document.addEventListener('paste', function(e) {
        var cd = e.clipboardData;
        if (!cd || !cd.items) return;
        for (var i = 0; i < cd.items.length; i++) {
            var it = cd.items[i];
            if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                var f = it.getAsFile();
                if (!f) return;
                e.preventDefault();
                setFile(f);
                return;
            }
        }
    });
}
setupUploadHelpers();

renderGallery();
                showToast('描述已更新');
            } else {
                showToast(r.error || '更新失败');
            }
        })
        .catch(function() { showToast('网络异常'); });
}

renderGallery();
</script>
</body>
</html>
