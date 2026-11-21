<?php
/**
 * 班级图集 — 图片上传 + 画廊展示
 * 用户上传图片并配文，以画廊形式展示。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/history.php';
require_once 'inc/motion_photo.php';
require_once 'inc/gallery_trash.php';
$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

// 每次进页面顺手清掉回收站里过期的条目（成本是读一次小 json）
try { galleryTrashPurge($classId); } catch (Throwable $e) {}

$gallery = Database::getClassData($classId, 'gallery');
if (!is_array($gallery)) $gallery = [];

// 自愈：过滤掉图片文件已丢失的条目，并清理 gallery.json
// 清理在文件锁内进行（updateClassData），避免与 save_gallery 并发时用旧数组覆盖丢条目
$validGallery = [];
$cleaned = false;
foreach ($gallery as $item) {
    $imgFile = $item['image'] ?? '';
    if ($imgFile !== '' && Database::getUploadedImagePath($classId, $imgFile) !== null) {
        $live = (string)($item['live_video'] ?? '');
        if ($live !== '' && Database::getUploadedImagePath($classId, $live) === null) {
            unset($item['live_video']);
            $item['type'] = 'image';
            $cleaned = true;
        }
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
                $live = (string)($item['live_video'] ?? '');
                if ($live !== '' && Database::getUploadedImagePath($classId, $live) === null) {
                    unset($item['live_video']);
                    $item['type'] = 'image';
                }
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
    $isMultiUpload = reqPost('multi') === '1';
    if ($desc === '' && !$isMultiUpload) { echo json_encode(['success' => false, 'error' => '单文件上传请填写描述']); exit; }
    if (mb_strlen($desc) > 500) { echo json_encode(['success' => false, 'error' => '描述不能超过500字']); exit; }
    $isGif = false;
    $isMp4 = false;
    if (isset($image['tmp_name']) && is_file($image['tmp_name'])) {
        $info = @getimagesize($image['tmp_name']);
        $isGif = is_array($info) && ($info[2] ?? 0) === IMAGETYPE_GIF;
        if (!$isGif) $isMp4 = Database::isMp4File($image['tmp_name']);
    }
    if ($isMp4) {
        if (($image['size'] ?? 0) > Database::MP4_MAX_BYTES) { echo json_encode(['success' => false, 'error' => '视频最大 ' . round(Database::MP4_MAX_BYTES / 1048576) . 'MB']); exit; }
    } else {
        $maxBytes = $isGif ? Database::GIF_MAX_BYTES : Database::UPLOAD_MAX_BYTES;
        if (($image['size'] ?? 0) > $maxBytes) { echo json_encode(['success' => false, 'error' => $isGif ? 'GIF 动图最大 16MB' : '图片最大 12MB']); exit; }
    }

    $liveTmp = null;
    $liveWarning = '';
    if (!$isGif && !$isMp4 && isset($image['tmp_name']) && is_file($image['tmp_name'])) {
        $liveTmp = motionPhotoExtractVideo($image['tmp_name']);
    }

    try {
        $filename = Database::saveUploadedImage($classId, $image['tmp_name']);
    } catch (RuntimeException $e) {
        if ($liveTmp) @unlink($liveTmp);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }

    $liveFile = null;
    if ($liveTmp) {
        try {
            $liveFile = Database::saveMp4Image($classId, $liveTmp);
        } catch (Throwable $e) {
            $liveWarning = '实况视频保存失败，已按普通图片上传';
        } finally {
            @unlink($liveTmp);
        }
    }
    // 首帧缩略图由 CLI 计划任务 cron_gallery_thumbs.php 生成（FPM 禁用了 exec/proc_open，
    // 这里不再内联调用，避免在请求路径加载 composer/php-ffmpeg 引发偶发超时或 PHP 警告污染 JSON）

    $id = bin2hex(random_bytes(16));
    try {
        $result = Database::updateClassData($classId, 'gallery', function($latest) use ($id, $filename, $desc, $liveFile, $isMp4) {
            if (!is_array($latest)) $latest = [];
            $item = [
                'id' => $id,
                'image' => $filename,
                'description' => $desc,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'type' => $liveFile ? 'live' : ($isMp4 ? 'video' : 'image'),
                'uploader_name' => '网页',
            ];
            if ($liveFile) $item['live_video'] = $liveFile;
            array_unshift($latest, $item);
            return $latest;
        });
        if ($result === false) throw new RuntimeException('数据保存失败');
    } catch (Throwable $e) {
        // 写库失败：回滚已落盘的文件与缩略图，避免孤儿文件泄漏
        Database::deleteUploadedImage($classId, $filename);
        Database::deleteVideoThumb($classId, $filename);
        if ($liveFile) {
            Database::deleteUploadedImage($classId, $liveFile);
            Database::deleteVideoThumb($classId, $liveFile);
        }
        echo json_encode(['success' => false, 'error' => '保存失败，请重试']); exit;
    }
    echo json_encode(['success' => true, 'id' => $id, 'live' => $liveFile !== null, 'live_warning' => $liveWarning ?: null]); exit;
}

// ========== 分片上传（S5）：大视频按 8MB 切片传，最后服务端拼装校验 ==========
const LW_CHUNK_BYTES = 8388608;          // 单片 8MB
const LW_CHUNK_MAX_AGE = 3 * 3600;       // 3 小时没传完的分片自动清掉

function galleryChunkRoot(string $classId): string {
    return Database::getClassDir($classId) . DIRECTORY_SEPARATOR . 'chunks';
}
function galleryChunkDir(string $classId, string $uploadId): string {
    return galleryChunkRoot($classId) . DIRECTORY_SEPARATOR . $uploadId;
}
/** 清理过期的分片目录 */
function galleryChunkCleanup(string $classId, ?string $only = null): void {
    $root = galleryChunkRoot($classId);
    if (!is_dir($root)) return;
    $targets = $only !== null ? [$root . DIRECTORY_SEPARATOR . $only] : (glob($root . DIRECTORY_SEPARATOR . '*') ?: []);
    foreach ($targets as $dir) {
        if (!is_dir($dir)) continue;
        if ($only === null && (time() - (int)@filemtime($dir)) < LW_CHUNK_MAX_AGE) continue;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
    @rmdir($root);
}

if (isset($_POST['action']) && $_POST['action'] === 'upload_chunk') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $uid = (string)reqPost('upload_id');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $uid)) { echo json_encode(['success' => false, 'error' => '分片参数无效']); exit; }
    $index = (int)reqPost('index');
    $total = (int)reqPost('total');
    if ($index < 0 || $total < 1 || $total > 8192 || $index >= $total) { echo json_encode(['success' => false, 'error' => '分片参数无效']); exit; }
    $chunk = $_FILES['chunk'] ?? null;
    if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'error' => '分片接收失败']); exit; }
    if (($chunk['size'] ?? 0) > LW_CHUNK_BYTES + 1048576) { echo json_encode(['success' => false, 'error' => '分片过大']); exit; }
    $dir = galleryChunkDir($classId, $uid);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) { echo json_encode(['success' => false, 'error' => '临时目录不可用']); exit; }
    if (!@move_uploaded_file($chunk['tmp_name'], $dir . DIRECTORY_SEPARATOR . $index . '.part')) {
        echo json_encode(['success' => false, 'error' => '分片保存失败']); exit;
    }
    echo json_encode(['success' => true, 'index' => $index]); exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'upload_finish') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $uid = (string)reqPost('upload_id');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $uid)) { echo json_encode(['success' => false, 'error' => '分片参数无效']); exit; }
    $total = (int)reqPost('total');
    if ($total < 1 || $total > 8192) { echo json_encode(['success' => false, 'error' => '分片参数无效']); exit; }
    $desc = trim(sanitizePlainText(reqPost('description')));
    $isMultiUpload = reqPost('multi') === '1';
    if ($desc === '' && !$isMultiUpload) { echo json_encode(['success' => false, 'error' => '单文件上传请填写描述']); exit; }
    if (mb_strlen($desc) > 500) { echo json_encode(['success' => false, 'error' => '描述不能超过500字']); exit; }

    $dir = galleryChunkDir($classId, $uid);
    if (!is_dir($dir)) { echo json_encode(['success' => false, 'error' => '分片已过期，请重新上传']); exit; }
    $size = 0;
    for ($i = 0; $i < $total; $i++) {
        $part = $dir . DIRECTORY_SEPARATOR . $i . '.part';
        if (!is_file($part)) { echo json_encode(['success' => false, 'error' => '分片缺失（第 ' . ($i + 1) . ' 片），请重新上传']); exit; }
        $size += (int)@filesize($part);
    }
    if ($size > Database::MP4_MAX_BYTES) {
        galleryChunkCleanup($classId, $uid);
        echo json_encode(['success' => false, 'error' => '视频最大 ' . round(Database::MP4_MAX_BYTES / 1048576) . 'MB']); exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'lwchunk');
    $out = @fopen($tmp, 'wb');
    if (!$out) { echo json_encode(['success' => false, 'error' => '临时文件不可用']); exit; }
    for ($i = 0; $i < $total; $i++) {
        $in = @fopen($dir . DIRECTORY_SEPARATOR . $i . '.part', 'rb');
        if ($in) { stream_copy_to_stream($in, $out); fclose($in); }
    }
    fclose($out);

    try {
        $filename = Database::saveMp4Image($classId, $tmp);
    } catch (RuntimeException $e) {
        @unlink($tmp);
        galleryChunkCleanup($classId, $uid);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    } finally {
        @unlink($tmp);
    }
    galleryChunkCleanup($classId, $uid);

    $id = bin2hex(random_bytes(16));
    try {
        $result = Database::updateClassData($classId, 'gallery', function($latest) use ($id, $filename, $desc) {
            if (!is_array($latest)) $latest = [];
            array_unshift($latest, [
                'id' => $id,
                'image' => $filename,
                'description' => $desc,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'type' => 'video',
                'uploader_name' => '网页',
            ]);
            return $latest;
        });
        if ($result === false) throw new RuntimeException('数据保存失败');
    } catch (Throwable $e) {
        Database::deleteUploadedImage($classId, $filename);
        echo json_encode(['success' => false, 'error' => '保存失败，请重试']); exit;
    }
    echo json_encode(['success' => true, 'id' => $id, 'chunked' => true]); exit;
}

// GET 请求顺手清理 3 小时前没传完的分片目录
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    try { galleryChunkCleanup($classId); } catch (Throwable $e) {}
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

// ========== delete_gallery（软删除：进回收站，保留 7 天可恢复）==========
if (isset($_POST['action']) && $_POST['action'] === 'delete_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $id = reqPost('id');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { echo json_encode(['success' => false, 'error' => '无效ID']); exit; }
    $removed = null;
    Database::updateClassData($classId, 'gallery', function($latest) use ($id, &$removed) {
        if (!is_array($latest)) return null;
        foreach ($latest as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                $removed = $item;
                array_splice($latest, $i, 1);
                return $latest;
            }
        }
        return null;
    });
    if ($removed !== null) {
        galleryTrashAdd($classId, $removed);
        galleryTrashPurge($classId);
    }
    echo json_encode(['success' => true, 'trashed' => $removed !== null]); exit;
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
    <!-- 上传弹窗（S6）：右下角 + 打开，支持单文件/多文件 -->
    <div id="uploadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:6200;align-items:center;justify-content:center;padding:20px;">
        <div class="card" style="width:100%;max-width:680px;max-height:86vh;overflow:auto;padding:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">
                <h3 style="font-family:var(--font-heading);font-size:17px;margin:0;">上传到图集</h3>
                <button type="button" class="btn btn-sm" onclick="closeUpload()">关闭</button>
            </div>
            <label class="input upload-file-btn" for="galleryImage" style="display:flex;align-items:center;gap:8px;cursor:pointer;border-style:dashed;justify-content:center;padding:14px;">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg> 点击选择 / 拖拽 / Ctrl+V 粘贴（可多选）</span>
                <input type="file" id="galleryImage" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime" multiple style="display:none;" onchange="onFilesPicked()">
            </label>
            <div id="uploadPreviewList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-top:12px;"></div>
            <input type="text" class="input" id="galleryDesc" placeholder="写一段关于这张图片/视频的话…" maxlength="500" style="margin-top:12px;">
            <div id="uploadDescHint" style="font-size:12px;color:#999;margin-top:6px;"></div>
            <div id="uploadHint" style="font-size:13px;color:var(--red);min-height:18px;margin-top:6px;"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                <button type="button" class="btn" onclick="closeUpload()">取消</button>
                <button type="button" class="btn btn-primary" onclick="uploadGallery()" id="uploadBtn">上传</button>
            </div>
        </div>
    </div>

    <button type="button" id="uploadFab" onclick="openUpload()" title="上传图片 / 视频"
            style="position:fixed;right:22px;bottom:22px;width:58px;height:58px;border-radius:50%;background:var(--blue);color:#fff;border:2.5px solid var(--pencil);box-shadow:4px 4px 0 var(--pencil);font-size:30px;line-height:1;cursor:pointer;z-index:6000;display:flex;align-items:center;justify-content:center;">+</button>

    <div class="card mb-4" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="search" class="input" id="galSearch" placeholder="搜索描述…" oninput="renderGallery()" style="flex:2;min-width:200px;">
        <select class="input" id="galSort" onchange="renderGallery()" style="flex:1;min-width:140px;">
            <option value="desc">最新在前</option>
            <option value="asc">最早在前</option>
        </select>
        <span id="galCount" style="font-size:12px;color:#888;white-space:nowrap;"></span>
    </div>

    <div class="gallery-grid" id="galleryGrid" style="display:block;columns:280px;column-gap:20px;"></div>
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

<script src="common.js?v=15"></script>
<script>
var classId = <?php echo json_encode($classId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
// 上传上限统一从服务端常量取，避免前后端两套数字飘移
var LW_LIMITS = <?php echo json_encode([
    'mp4' => Database::MP4_MAX_BYTES,
    'chunk' => LW_CHUNK_BYTES,
    'gif' => Database::GIF_MAX_BYTES,
    'image' => Database::UPLOAD_MAX_BYTES,
]); ?>;
var LW_LIMIT_LABEL = {
    mp4: Math.round(LW_LIMITS.mp4 / 1048576) + 'MB',
    gif: Math.round(LW_LIMITS.gif / 1048576) + 'MB',
    image: Math.round(LW_LIMITS.image / 1048576) + 'MB'
};
var galleryData = <?php echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
// 描述不内嵌到 HTML onclick 属性（含换行/过长会破坏 JS 导致卡片打不开），统一存映射表按 id 取
var galleryDescMap = {};

function renderGallery() {
    var grid = document.getElementById('galleryGrid');
    var empty = document.getElementById('emptyState');
    var searchEl = document.getElementById('galSearch');
    var sortEl = document.getElementById('galSort');
    var countEl = document.getElementById('galCount');
    var q = (searchEl && searchEl.value || '').trim().toLowerCase();
    var order = (sortEl && sortEl.value) || 'desc';

    // 描述搜索 + 时间排序（纯前端，不改动服务端顺序）
    var items = galleryData.slice();
    if (q) {
        items = items.filter(function(it) {
            return ((it.description || '') + ' ' + (it.image || '')).toLowerCase().indexOf(q) >= 0;
        });
    }
    items.sort(function(a, b) {
        var ta = a.uploaded_at || '', tb = b.uploaded_at || '';
        return order === 'asc' ? (ta < tb ? -1 : ta > tb ? 1 : 0) : (ta > tb ? -1 : ta < tb ? 1 : 0);
    });
    if (countEl) countEl.textContent = q ? (items.length + ' / ' + galleryData.length + ' 条') : (galleryData.length + ' 条');

    if (!items.length) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        empty.querySelector('p').textContent = q ? '没有匹配的图片/视频' : '还没有图片，上传第一张吧';
        return;
    }
    empty.style.display = 'none';
    grid.innerHTML = items.map(function(item, idx) {
        galleryDescMap[item.id] = item.description;
        var url = 'upload.php?class_id=' + classId + '&file=' + item.image;
        var dateText = formatDate(item.uploaded_at);
        var isGif = /\.gif$/i.test(item.image);
        var isMp4 = /\.mp4$/i.test(item.image);
        var isLive = item.type === 'live' && !!item.live_video;
        var liveUrl = isLive ? ('upload.php?class_id=' + classId + '&file=' + item.live_video) : '';
        var detailUrl = isLive ? liveUrl : url;
        var detailIsMp4 = isMp4 || isLive;
        var detailThumb = isMp4 ? ('video_thumb.php?class_id=' + classId + '&file=' + item.image) : (isLive ? url : '');
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
            media = '<img class="video-poster" src="' + thumbUrl + '" alt="" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;z-index:1;" onload="hideGalleryPlaceholder(this)" onerror="this.hidden=true">'
                + '<video src="' + url + '" poster="' + thumbUrl + '" muted loop playsinline preload="metadata" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;pointer-events:none;background:transparent;z-index:1;" onplaying="hideGalleryPlaceholder(this)" onerror="galleryVideoError(this)"></video>';
        } else if (isLive) {
            // 实况卡片：静态图打底 + 实况视频叠在上层，进视口静音循环播放（与 MP4 卡片共用同一套调度）。
            // - 视频未出首帧前 opacity:0，仍显示静态图，不会黑屏；
            // - 播放失败（onerror）直接隐藏视频层，退回静态图；
            // - pointer-events:none，点击仍然整卡打开灯箱。
            media = '<img src="' + url + '" alt="" loading="lazy" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;z-index:1;" onload="hideGalleryPlaceholder(this)" onerror="markGalleryError(this)">'
                + '<video src="' + liveUrl + '" muted loop playsinline preload="none" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;object-fit:cover;pointer-events:none;background:transparent;z-index:1;opacity:0;transition:opacity .25s ease;" onplaying="this.style.opacity=1;hideGalleryPlaceholder(this)" onerror="this.hidden=true"></video>';
        } else {
            media = '<img src="' + url + '" alt="" loading="lazy" style="display:block;width:100%;height:auto;opacity:0;transition:opacity .35s ease;position:relative;z-index:1;" onload="this.style.opacity=1;hideGalleryPlaceholder(this)" onerror="this.style.opacity=0;markGalleryError(this)">';
        }
        return '<div class="card gallery-card rotate-' + (idx % 2 === 0 ? '1' : '-1') + '" onclick="openLightbox(\'' + detailUrl + '\', \'' + item.id + '\', ' + detailIsMp4 + ', \'' + detailThumb + '\')" style="overflow:hidden;cursor:pointer;padding:0;break-inside:avoid;margin-bottom:20px;">'
            + '<div class="img-wrap" style="width:100%;aspect-ratio:' + (detailIsMp4 ? '4/3' : 'auto') + ';min-height:180px;overflow:hidden;background:#f0f0f0;position:relative;">'
            + placeholder
            + media
            + (isGif ? '<span class="gif-badge">GIF</span>' : '')
            + (isMp4 ? '<span class="gif-badge mp4-badge">MP4</span>' : '')
            + (isLive ? '<span class="gif-badge" style="background:var(--blue);">实况</span>' : '')
            + '</div>'
            + '<div class="info" style="padding:14px 16px;">'
            + '<div class="desc" style="font-size:14px;line-height:1.6;color:var(--pencil);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">' + (item.description ? escapeHtml(item.description) : '<span style="color:#bbb;">未填写描述</span>') + '</div>'
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

// 视频 onerror 可能是瞬时失败（缺首帧缩略图、刚进视口还没缓冲够时更常见）：
// 延迟判定，期间只要能播就忽略，避免正常视频先闪一段“媒体已失效”。
function galleryVideoError(el) {
    if (!el) return;
    setTimeout(function() {
        try {
            if (el.readyState >= 2 || el.currentTime > 0) return;
        } catch (e) {}
        markGalleryError(el);
    }, 1200);
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

// ── 上传（S6）：右下角 + 打开弹窗，支持单文件 / 多文件 ──
var pendingFiles = [];

function openUpload() {
    document.getElementById('uploadModal').style.display = 'flex';
    renderPending();
}
function closeUpload() {
    document.getElementById('uploadModal').style.display = 'none';
    clearPending();
}
function clearPending() {
    pendingFiles.forEach(function (p) { if (p.url) URL.revokeObjectURL(p.url); });
    pendingFiles = [];
    var input = document.getElementById('galleryImage');
    if (input) input.value = '';
    var desc = document.getElementById('galleryDesc');
    if (desc) desc.value = '';
    var hint = document.getElementById('uploadHint');
    if (hint) hint.textContent = '';
    renderPending();
}
function addFiles(list) {
    var added = 0;
    Array.prototype.forEach.call(list || [], function (f) {
        if (!f) return;
        var isVideo = f.type && f.type.indexOf('video/') === 0;
        pendingFiles.push({ file: f, url: isVideo ? '' : URL.createObjectURL(f) });
        added++;
    });
    if (!added) return;
    document.getElementById('uploadModal').style.display = 'flex';
    renderPending();
}
function onFilesPicked() {
    var input = document.getElementById('galleryImage');
    addFiles(input.files);
    input.value = '';
}
function removePending(i) {
    var p = pendingFiles[i];
    if (!p) return;
    if (p.url) URL.revokeObjectURL(p.url);
    pendingFiles.splice(i, 1);
    renderPending();
}
function renderPending() {
    var box = document.getElementById('uploadPreviewList');
    if (!box) return;
    var descEl = document.getElementById('galleryDesc');
    var descHint = document.getElementById('uploadDescHint');
    if (pendingFiles.length === 1) {
        descEl.placeholder = '写一段关于这张图片/视频的话…（必填）';
        descHint.textContent = '单文件上传需要填写描述';
    } else if (pendingFiles.length > 1) {
        descEl.placeholder = '多文件上传可不填描述（留空显示“未填写描述”）';
        descHint.textContent = '已选 ' + pendingFiles.length + ' 个文件';
    } else {
        descEl.placeholder = '写一段关于这张图片/视频的话…';
        descHint.textContent = '';
    }
    box.innerHTML = pendingFiles.map(function (p, i) {
        var f = p.file;
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        var isMp4 = ext === 'mp4' || ext === 'mov';
        var maxBytes = isMp4 ? LW_LIMITS.mp4 : (ext === 'gif' ? LW_LIMITS.gif : LW_LIMITS.image);
        var tooBig = f.size > maxBytes;
        var media = isMp4
            ? '<div style="width:100%;height:88px;display:flex;align-items:center;justify-content:center;background:#f0f0f0;font-size:12px;color:#888;">🎬 视频</div>'
            : '<img src="' + p.url + '" alt="" style="width:100%;height:88px;object-fit:cover;">';
        return '<div style="position:relative;border:2px solid ' + (tooBig ? 'var(--red)' : 'var(--pencil)') + ';border-radius:8px;overflow:hidden;background:#fff;">'
            + media
            + '<button type="button" onclick="removePending(' + i + ')" title="移除" style="position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(0,0,0,.55);color:#fff;cursor:pointer;line-height:1;">×</button>'
            + '<div style="padding:4px 6px;font-size:11px;line-height:1.3;">'
            + '<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</div>'
            + '<div style="color:' + (tooBig ? 'var(--red)' : '#999') + ';">' + (f.size / 1048576).toFixed(1) + 'MB' + (tooBig ? ' 超出上限' : '') + '</div>'
            + '</div></div>';
    }).join('');
}

function uploadOneFile(file, desc, multi) {
    return new Promise(function (resolve, reject) {
        var fd = new FormData();
        fd.append('action', 'save_gallery');
        fd.append('image', file);
        fd.append('description', desc);
        fd.append('multi', multi);
        fd.append('csrf_token', document.getElementById('csrfToken').value);
        var controller = new AbortController();
        var timeoutMs = file.size > 30 * 1048576 ? 300000 : 60000;
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        fetch('gallery.php?id=' + classId, { method: 'POST', body: fd, signal: controller.signal })
            .then(function (resp) { return resp.text(); })
            .then(function (txt) {
                clearTimeout(timer);
                var r;
                try { r = JSON.parse(txt); } catch (e) { reject(new Error('服务器响应异常')); return; }
                if (!r.success) { reject(new Error(r.error || '上传失败')); return; }
                resolve(r);
            })
            .catch(function (e) {
                clearTimeout(timer);
                reject(new Error(e.name === 'AbortError' ? '上传超时' : '网络错误'));
            });
    });
}

async function uploadGallery() {
    var hint = document.getElementById('uploadHint');
    var btn = document.getElementById('uploadBtn');
    if (hint) hint.textContent = '';
    if (!pendingFiles.length) { showToast('请选择文件'); return; }
    var desc = document.getElementById('galleryDesc').value.trim();
    if (pendingFiles.length === 1 && !desc) { showToast('单文件上传请填写描述'); return; }

    for (var i = 0; i < pendingFiles.length; i++) {
        var f = pendingFiles[i].file;
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        var isMp4 = ext === 'mp4' || ext === 'mov';
        var isGif = ext === 'gif';
        if (!isMp4 && !isGif && ['jpg', 'jpeg', 'png', 'webp'].indexOf(ext) < 0) {
            showToast('不支持的文件格式：' + f.name);
            return;
        }
        var maxBytes = isMp4 ? LW_LIMITS.mp4 : (isGif ? LW_LIMITS.gif : LW_LIMITS.image);
        if (f.size > maxBytes) { showToast('「' + f.name + '」超过大小上限'); return; }
    }

    var multi = pendingFiles.length > 1 ? '1' : '0';
    var okCount = 0, failCount = 0;
    btn.disabled = true;
    try {
        for (var j = 0; j < pendingFiles.length; j++) {
            var file = pendingFiles[j].file;
            btn.textContent = '上传中 ' + (j + 1) + '/' + pendingFiles.length;
            var e2 = (file.name.split('.').pop() || '').toLowerCase();
            var mp42 = e2 === 'mp4' || e2 === 'mov';
            try {
                if (mp42 && file.size > 16 * 1048576) {
                    await uploadChunkedGallery(file, desc, multi);
                } else {
                    await uploadOneFile(file, desc, multi);
                }
                okCount++;
            } catch (err) {
                failCount++;
                if (hint) hint.textContent = '「' + file.name + '」失败：' + (err.message || '网络错误');
            }
        }
    } finally {
        btn.disabled = false;
        btn.textContent = '上传';
    }
    if (okCount > 0) {
        showToast('上传完成 ' + okCount + ' 个' + (failCount ? '，失败 ' + failCount + ' 个' : ''));
        closeUpload();
        await refreshGalleryGrid();
    }
}

// 分片上传：8MB 一片，最后服务端拼装 + MP4 校验；支持到 1GB
async function uploadChunkedGallery(file, desc, multi) {
    var hint = document.getElementById('uploadHint');
    var csrf = document.getElementById('csrfToken').value;
    var CHUNK = LW_LIMITS.chunk || 8388608;
    var total = Math.ceil(file.size / CHUNK);
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var uid = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    for (var i = 0; i < total; i++) {
        var blob = file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK));
        var fd = new FormData();
        fd.append('action', 'upload_chunk');
        fd.append('upload_id', uid);
        fd.append('index', String(i));
        fd.append('total', String(total));
        fd.append('chunk', blob, 'part');
        fd.append('csrf_token', csrf);
        var resp = await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd });
        var txt = await resp.text();
        var r = null;
        try { r = JSON.parse(txt); } catch (e) { throw new Error('服务器响应异常（HTTP ' + resp.status + '）'); }
        if (!r.success) throw new Error(r.error || ('第 ' + (i + 1) + ' 片上传失败'));
        if (hint) hint.textContent = file.name + '：' + Math.round((i + 1) / total * 100) + '%（' + (i + 1) + '/' + total + ' 片）';
    }
    if (hint) hint.textContent = file.name + '：正在拼装与校验…';
    var fd2 = new FormData();
    fd2.append('action', 'upload_finish');
    fd2.append('upload_id', uid);
    fd2.append('total', String(total));
    fd2.append('description', desc);
    fd2.append('multi', multi || '0');
    fd2.append('csrf_token', csrf);
    var r2 = await (await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd2 })).json();
    if (!r2.success) throw new Error(r2.error || '拼装失败');
    if (hint) hint.textContent = '';
}

async function deleteGallery(id) {
    if (!await lwConfirm('删除后会在回收站保留 7 天，期间可以恢复。', { title: '删除这一项？', okText: '删除', danger: true })) return;
    var fd = new FormData();
    fd.append('action', 'delete_gallery');
    fd.append('id', id);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        var r = await (await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd })).json();
        if (r.success) {
            galleryData = galleryData.filter(function(item) { return item.id !== id; });
            renderGallery();
            showToast('已移入回收站，7 天内可恢复');
        }
    } catch(e) { showToast('删除失败'); }
}

// 回收站移到管理员后台（班级侧不提供入口）
async function refreshGalleryGrid() {
    try {
        var j = await (await fetch('gallery.php?id=' + classId + '&json=1', { cache: 'no-store' })).json();
        if (j.success) { galleryData = j.items || []; renderGallery(); }
    } catch (e) {}
}

function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function editGallery(id) {
    var currentDesc = galleryDescMap[id] || '';
    var newDesc = await lwPrompt('修改图片描述', currentDesc, { title: '修改描述', okText: '保存', maxlength: 500 });
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
                renderGallery();
                showToast('描述已更新');
            } else {
                showToast(r.error || '更新失败');
            }
        })
        .catch(function() { showToast('网络异常'); });
}

// 上传区拖拽 / Ctrl+V 粘贴（绕开 Windows 触屏设备文件选择对话框卡死问题）
function setupUploadHelpers() {
    var input = document.getElementById('galleryImage');
    var dropZone = input.closest('.upload-file-btn');
    var setFile = function(f) {
        if (f) addFiles([f]);
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
            e.preventDefault();
            addFiles(e.dataTransfer && e.dataTransfer.files);
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
</script>
</body>
</html>
