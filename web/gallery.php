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

$gallery = Database::getClassData($classId, 'gallery');
if (!is_array($gallery)) $gallery = [];

// 自愈：过滤掉图片文件已丢失的条目，并清理 gallery.json
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
    Database::saveClassData($classId, 'gallery', $validGallery);
    $gallery = $validGallery;
}
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
    if (($image['size'] ?? 0) > Database::UPLOAD_MAX_BYTES) { echo json_encode(['success' => false, 'error' => '图片最大 12MB']); exit; }

    try {
        $filename = Database::saveUploadedImage($classId, $image['tmp_name']);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }

    $id = bin2hex(random_bytes(16));
    Database::updateClassData($classId, 'gallery', function($latest) use ($id, $filename, $desc) {
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
    Database::updateClassData($classId, 'gallery', function($latest) use ($id, $classId) {
        if (!is_array($latest)) return null;
        foreach ($latest as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                Database::deleteUploadedImage($classId, $item['image'] ?? '');
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
    <div class="card mb-4">
        <h3 style="font-family:var(--font-heading);margin-bottom:12px;color:var(--pencil);">📷 上传图片</h3>
        <div class="upload-form" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;"><input type="file" id="galleryImage" accept="image/jpeg,image/png,image/webp" class="input" style="border-style:dashed;"></div>
            <div style="flex:2;min-width:250px;"><input type="text" class="input" id="galleryDesc" placeholder="写一段关于这张图片的话…" maxlength="500"></div>
            <button class="btn btn-primary" onclick="uploadGallery()" id="uploadBtn">上传</button>
        </div>
    </div>

    <div class="card mb-4" style="font-size:13px;color:#888;">
💡 公开 API：<code class="tag">gallery_api.php?class_id=<?php echo htmlspecialchars($classId); ?></code>，在 settings.json 中设置 <code class="tag">gallery_api_key_<?php echo htmlspecialchars($classId); ?></code> 即可启用密钥保护，调用时加 <code class="tag">&apikey=你的密钥</code>
    </div>

    <div class="gallery-grid" id="galleryGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;"></div>
    <div class="empty-state" id="emptyState" style="display:none"><p>还没有图片，上传第一张吧 📷</p></div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:5000;align-items:center;justify-content:center;">
    <button class="btn" onclick="closeLightbox()" style="position:fixed;top:20px;right:20px;width:44px;height:44px;border-radius:50%;font-size:20px;">✕</button>
    <img id="lbImg" src="" alt="" style="max-width:92vw;max-height:80vh;border:3px solid var(--pencil);border-radius:var(--wobbly);box-shadow:var(--shadow-lg);">
    <div class="lb-desc" id="lbDesc" style="position:fixed;bottom:30px;left:50%;transform:translateX(-50%);color:var(--white);font-size:15px;text-align:center;max-width:600px;padding:12px 24px;background:rgba(0,0,0,0.5);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);"></div>
</div>

<script src="common.js?v=3"></script>
<script>
var classId = <?php echo json_encode($classId); ?>;
var galleryData = <?php echo json_encode($gallery, JSON_UNESCAPED_UNICODE); ?>;

function renderGallery() {
    var grid = document.getElementById('galleryGrid');
    var empty = document.getElementById('emptyState');
    if (!galleryData.length) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    grid.innerHTML = galleryData.map(function(item, idx) {
        var url = 'upload.php?class_id=' + classId + '&file=' + item.image;
        var dateText = formatDate(item.uploaded_at);
        return '<div class="card gallery-card rotate-' + (idx % 2 === 0 ? '1' : '-1') + '" onclick="openLightbox(\'' + url + '\', \'' + escapeHtml(item.description).replace(/'/g, "\\'") + '\')" style="overflow:hidden;cursor:pointer;padding:0;">'
            + '<div class="img-wrap" style="width:100%;aspect-ratio:4/3;overflow:hidden;background:#f0f0f0;"><img src="' + url + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"></div>'
            + '<div class="info" style="padding:14px 16px;">'
            + '<div class="desc" style="font-size:14px;line-height:1.6;color:var(--pencil);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">' + escapeHtml(item.description) + '</div>'
            + '<div class="meta" style="font-size:12px;color:#888;margin-top:8px;display:flex;justify-content:space-between;align-items:center;"><span class="date">📅 ' + dateText + '</span>'
            + '<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteGallery(\'' + item.id + '\')">🗑️</button>'
            + '</div></div></div>';
    }).join('');
}
function formatDate(s) {
    if (!s) return '';
    return s.length >= 16 ? s.substring(0, 16).replace(' ', ' ') : s.substring(0, 10);
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
            galleryData.unshift({id: r.id, image: '', description: desc, uploaded_at: new Date().toISOString().replace('T',' ').substring(0,19)});
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
