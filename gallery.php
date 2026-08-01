<?php
/**
 * 班级图集 — 图片上传 + 画廊展示
 * 用户上传图片并配文，以画廊形式展示。
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/history.php';
$classId = $_GET['id'] ?? '';
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

$galleryFile = 'gallery_' . $classId . '.json';
$gallery = Database::read($galleryFile);
if (!is_array($gallery)) $gallery = [];
$csrfToken = csrfToken();

// ========== save_gallery ==========
if (isset($_POST['action']) && $_POST['action'] === 'save_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $image = $_FILES['image'] ?? null;
    if (!$image || ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => '请选择图片']); exit;
    }
    $desc = trim((string)($_POST['description'] ?? ''));
    if ($desc === '') { echo json_encode(['success' => false, 'error' => '请填写描述']); exit; }
    if (mb_strlen($desc) > 500) { echo json_encode(['success' => false, 'error' => '描述不能超过500字']); exit; }

    // 复用 upload.php 的上传逻辑 — 直接在这里处理上传
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $info = @getimagesize($image['tmp_name']);
    if (!$info || !isset($allowed[$info[2]])) { echo json_encode(['success' => false, 'error' => '仅支持 JPEG、PNG、WebP']); exit; }
    if (($image['size'] ?? 0) > 12582912) { echo json_encode(['success' => false, 'error' => '图片最大 12MB']); exit; }

    $uploadDir = Database::getUploadsDirectory($classId);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) { echo json_encode(['success' => false, 'error' => '上传目录不可用']); exit; }

    $ext = $allowed[$info[2]];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    // 缩放图片（最大边 1600px）
    $src = null; $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (function_exists($loaders[$info[2]])) {
        $src = @$loaders[$info[2]]($image['tmp_name']);
    }
    if ($src) {
        $w = imagesx($src); $h = imagesy($src);
        $scale = min(1, 1600 / max($w, $h));
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($info[2] !== IMAGETYPE_JPEG) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $writers = [IMAGETYPE_JPEG => 'imagejpeg', IMAGETYPE_PNG => 'imagepng', IMAGETYPE_WEBP => 'imagewebp'];
        $qualities = [IMAGETYPE_JPEG => 82, IMAGETYPE_PNG => 7, IMAGETYPE_WEBP => 82];
        $writers[$info[2]]($dst, $path, $qualities[$info[2]]);
        imagedestroy($src); imagedestroy($dst);
    } else {
        if (!move_uploaded_file($image['tmp_name'], $path)) { echo json_encode(['success' => false, 'error' => '保存失败']); exit; }
    }

    $id = bin2hex(random_bytes(16));
    Database::update($galleryFile, function($latest) use ($id, $filename, $desc) {
        if (!is_array($latest)) $latest = [];
        array_unshift($latest, ['id' => $id, 'image' => $filename, 'description' => $desc, 'uploaded_at' => date('Y-m-d H:i:s')]);
        return $latest;
    });
    echo json_encode(['success' => true, 'id' => $id]); exit;
}

// ========== delete_gallery ==========
if (isset($_POST['action']) && $_POST['action'] === 'delete_gallery') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { echo json_encode(['success' => false, 'error' => '无效ID']); exit; }
    Database::update($galleryFile, function($latest) use ($id, $classId) {
        if (!is_array($latest)) return null;
        foreach ($latest as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                $path = Database::getUploadsDirectory($classId) . DIRECTORY_SEPARATOR . ($item['image'] ?? '');
                if (is_file($path)) @unlink($path);
                array_splice($latest, $i, 1);
                return $latest;
            }
        }
        return null;
    });
    echo json_encode(['success' => true]); exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>班级图集 - <?php echo htmlspecialchars($class['name']); ?></title>
<link rel="stylesheet" href="common.css">
<style>
:root { --bg: #f5f0eb; --card: #fff; --primary: #5b7fff; --text: #3d3d3d; --muted: #9c9c9c; --border: #e8e3dc; --accent: #ff6b6b; }
* { margin:0; padding:0; box-sizing:border-box; }
body { background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; font-family:inherit; }
.status-bar { display:flex; align-items:center; justify-content:space-between; padding:8px 20px; background:var(--card); border-bottom:1px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,0.04); position:sticky; top:0; z-index:100; }
.status-bar .left { display:flex; align-items:center; gap:10px; }
.back-btn { background:none; border:none; cursor:pointer; padding:4px; color:#666; border-radius:8px; display:flex; align-items:center; }
.back-btn:hover { background:#f0f0f0; }
.main-content { flex:1; max-width:1100px; margin:24px auto; padding:0 20px; width:100%; }

/* Upload area */
.upload-card { background:var(--card); border-radius:16px; padding:24px; margin-bottom:24px; box-shadow:0 2px 16px rgba(0,0,0,0.05); }
.upload-card h3 { font-size:16px; margin-bottom:16px; color:var(--text); }
.upload-form { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.upload-form .file-area { flex:1; min-width:200px; }
.upload-form input[type="file"] { width:100%; padding:10px; border:2px dashed var(--border); border-radius:10px; cursor:pointer; font-size:13px; background:#fafaf8; }
.upload-form .desc-area { flex:2; min-width:250px; }
.upload-form input[type="text"] { width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:10px; font-size:14px; outline:none; background:#fafaf8; }
.upload-form input[type="text"]:focus { border-color:var(--primary); }
.upload-form button { padding:10px 24px; background:var(--primary); color:#fff; border:none; border-radius:10px; font-size:14px; cursor:pointer; font-weight:600; white-space:nowrap; transition:all .15s; }
.upload-form button:hover { background:#4a6ae0; }
.upload-form button:disabled { opacity:.6; pointer-events:none; }

/* Gallery grid */
.gallery-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:20px; }
.gallery-card { background:var(--card); border-radius:14px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.05); transition:all .2s; cursor:pointer; }
.gallery-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,0.1); }
.gallery-card .img-wrap { width:100%; aspect-ratio:4/3; overflow:hidden; background:#f0f0f0; }
.gallery-card .img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .3s; }
.gallery-card:hover .img-wrap img { transform:scale(1.05); }
.gallery-card .info { padding:14px 16px; }
.gallery-card .desc { font-size:14px; line-height:1.6; color:var(--text); display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
.gallery-card .meta { font-size:12px; color:var(--muted); margin-top:8px; display:flex; justify-content:space-between; align-items:center; }
.gallery-card .btn-del { font-size:11px; color:#ccc; border:none; background:none; cursor:pointer; padding:2px 6px; border-radius:4px; }
.gallery-card .btn-del:hover { color:var(--accent); background:#fff0f0; }

/* Lightbox */
.lightbox { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:5000; align-items:center; justify-content:center; }
.lightbox.active { display:flex; }
.lightbox img { max-width:92vw; max-height:80vh; border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,0.3); }
.lightbox .lb-desc { position:fixed; bottom:30px; left:50%; transform:translateX(-50%); color:#fff; font-size:15px; text-align:center; max-width:600px; padding:12px 24px; background:rgba(0,0,0,0.5); border-radius:10px; }
.lightbox .lb-close { position:fixed; top:20px; right:20px; color:#fff; font-size:32px; cursor:pointer; width:44px; height:44px; display:flex; align-items:center; justify-content:center; border-radius:50%; background:rgba(255,255,255,0.15); border:none; }
.lightbox .lb-close:hover { background:rgba(255,255,255,0.3); }

@media(max-width:600px) { .upload-form { flex-direction:column; } .gallery-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<div class="status-bar">
    <div class="left">
        <button class="back-btn" onclick="showOkOverlayThen('main.php?id=<?php echo rawurlencode($classId); ?>')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <span style="font-size:15px;font-weight:bold;color:#333;"><?php echo htmlspecialchars($class['name']); ?></span>
    </div>
    <div style="font-size:17px;font-weight:bold;">📷 班级图集</div>
    <div></div>
</div>

<div class="main-content">
    <div class="upload-card">
        <h3>📷 上传图片</h3>
        <div class="upload-form">
            <div class="file-area"><input type="file" id="galleryImage" accept="image/jpeg,image/png,image/webp"></div>
            <div class="desc-area"><input type="text" id="galleryDesc" placeholder="写一段关于这张图片的话…" maxlength="500"></div>
            <button onclick="uploadGallery()" id="uploadBtn">上传</button>
        </div>
    </div>

    <div style="background:var(--card);border-radius:14px;padding:14px 20px;margin-bottom:20px;font-size:13px;color:var(--muted);box-shadow:0 2px 12px rgba(0,0,0,0.04);">
💡 公开 API：<code style="background:#f0f4ff;padding:2px 8px;border-radius:4px;font-size:12px;">gallery_api.php?class_id=<?php echo htmlspecialchars($classId); ?></code>，在 settings.json 中设置 <code>gallery_api_key_<?php echo htmlspecialchars($classId); ?></code> 即可启用密钥保护，调用时加 <code>&apikey=你的密钥</code>
</div>

<div class="gallery-grid" id="galleryGrid"></div>
    <div class="empty-state" id="emptyState" style="display:none"><p>还没有图片，上传第一张吧 📷</p></div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lb-close" onclick="closeLightbox()">✕</button>
    <img id="lbImg" src="" alt="">
    <div class="lb-desc" id="lbDesc"></div>
</div>

<script src="common.js"></script>
<script>
var classId = <?php echo json_encode($classId); ?>;
var galleryData = <?php echo json_encode($gallery, JSON_UNESCAPED_UNICODE); ?>;

function renderGallery() {
    var grid = document.getElementById('galleryGrid');
    var empty = document.getElementById('emptyState');
    if (!galleryData.length) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    grid.innerHTML = galleryData.map(function(item) {
        var url = 'upload.php?class_id=' + classId + '&file=' + item.image;
        return '<div class="gallery-card" onclick="openLightbox(\'' + url + '\', \'' + escapeHtml(item.description).replace(/'/g, "\\'") + '\')">'
            + '<div class="img-wrap"><img src="' + url + '" alt="" loading="lazy"></div>'
            + '<div class="info">'
            + '<div class="desc">' + escapeHtml(item.description) + '</div>'
            + '<div class="meta"><span>' + (item.uploaded_at || '').substring(0, 10) + '</span>'
            + '<button class="btn-del" onclick="event.stopPropagation();deleteGallery(\'' + item.id + '\')">🗑️</button>'
            + '</div></div></div>';
    }).join('');
}

function openLightbox(url, desc) {
    document.getElementById('lightbox').classList.add('active');
    document.getElementById('lbImg').src = url;
    document.getElementById('lbDesc').textContent = desc;
}
function closeLightbox() { document.getElementById('lightbox').classList.remove('active'); }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });

async function uploadGallery() {
    var fileInput = document.getElementById('galleryImage');
    var desc = document.getElementById('galleryDesc').value.trim();
    if (!fileInput.files || !fileInput.files[0]) { showToast('请选择图片'); return; }
    if (!desc) { showToast('请填写描述'); return; }
    var btn = document.getElementById('uploadBtn'); btn.disabled = true; btn.textContent = '...';
    var fd = new FormData();
    fd.append('action', 'save_gallery');
    fd.append('image', fileInput.files[0]);
    fd.append('description', desc);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        var r = await (await fetch('gallery.php?id=' + classId, { method: 'POST', body: fd })).json();
        if (r.success) {
            galleryData.unshift({id: r.id, image: r.id ? '' : '', description: desc, uploaded_at: new Date().toISOString()});
            // Refresh from server
            location.reload();
        } else { showToast(r.error || '上传失败'); }
    } catch(e) { showToast('网络异常'); }
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

renderGallery();
</script>
</body>
</html>
