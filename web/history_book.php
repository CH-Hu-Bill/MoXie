<?php
/**
 * 班级史记 — Vlog 日记
 * 左侧月历 + 用户列表 / 右侧完整 Vlog 编辑器
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

$history = historySanitizeEntries(Database::getClassData($classId, 'history'));
$csrfToken = csrfToken();

// ========== save_entry ==========
if (isset($_POST['action']) && $_POST['action'] === 'save_entry') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $date = $_POST['date'] ?? '';
    if (!historyIsEditableDate($date)) { echo json_encode(['success' => false, 'error' => '只能保存今天的记录']); exit; }
    try { $content = historySanitizeHtml((string)($_POST['content'] ?? '')); }
    catch (LengthException $e) { http_response_code(413); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit; }
    catch (Exception $e) { http_response_code(400); echo json_encode(['success' => false, 'error' => '内容格式无效']); exit; }

    $rawTags = isset($_POST['tags']) ? json_decode((string)$_POST['tags'], true) : [];
    $entry = [
        'content'    => $content,
        'title'      => historySanitizeTitle($_POST['title'] ?? ''),
        'mood'       => historySanitizeMood($_POST['mood'] ?? historyDefaultMood()),
        'weather'    => historySanitizeWeather($_POST['weather'] ?? historyDefaultWeather()),
        'location'   => historySanitizeLocation($_POST['location'] ?? ''),
        'tags'       => historySanitizeTags(is_array($rawTags) ? $rawTags : []),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    Database::updateClassData($classId, 'history', function($latest) use ($date, $entry) {
        $latest[$date] = $entry;
        return $latest;
    });
    try { historyCleanupOrphanImages($classId, 'history'); } catch (Throwable $_) {}
    echo json_encode(['success' => true, 'entry' => $entry], JSON_UNESCAPED_UNICODE); exit;
}
?>
<?php $pageTitle = '班级史记'; require 'inc/head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.min.js"></script>
<style>
/* ======== 班级史记布局 (Hand-Drawn) ======== */
.app { flex:1; width:100%; max-width:1200px; margin:20px auto; display:flex; gap:24px; align-items:flex-start; padding:0 20px 24px; }
.timeline { width:360px; flex-shrink:0; position:sticky; top:68px; max-height:calc(100vh - 88px); overflow-y:auto; }
.timeline::-webkit-scrollbar { width:4px; }
.timeline::-webkit-scrollbar-thumb { background:var(--old-paper); border-radius:4px; }
.timeline-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.timeline-header h2 { font-size:18px; font-weight:700; }
.timeline-tabs { display:flex; gap:4px; margin-bottom:16px; background:var(--old-paper); border-radius:var(--wobbly-sm); padding:3px; }
.timeline-tab { flex:1; text-align:center; padding:7px 0; border-radius:var(--wobbly-sm); font-size:13px; cursor:pointer; transition:all .15s; color:#888; font-weight:500; border:none; background:none; font-family:var(--font-body); }
.timeline-tab.active { background:var(--white); color:var(--blue); font-weight:600; box-shadow:var(--shadow-sm); }
.month-selector { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:8px; }
.month-selector button { background:none; border:none; cursor:pointer; font-size:18px; color:var(--blue); padding:2px 8px; border-radius:var(--wobbly-sm); }
.month-selector button:hover { background:var(--old-paper); }
.month-selector .month-label { font-size:13px; font-weight:600; color:var(--pencil); font-family:var(--font-heading); }
.calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}
.calendar-grid .day-header{font-size:11px;color:#888;padding:6px 0 8px;font-weight:600;letter-spacing:.02em}
.calendar-grid .day-cell{aspect-ratio:1;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:var(--wobbly-sm);cursor:pointer;transition:background .15s,color .15s,box-shadow .15s,transform .12s;position:relative;color:var(--pencil);background:transparent;border:1.5px solid transparent;font-weight:500}
.calendar-grid .day-cell:not(.disabled):not(.other-month):hover{background:var(--old-paper);transform:scale(1.04)}
.calendar-grid .day-cell.today{border-color:var(--red);color:var(--red);font-weight:700}
.calendar-grid .day-cell.has-entry{background:var(--old-paper);color:var(--blue);font-weight:600}
.calendar-grid .day-cell.has-entry::after{content:'';position:absolute;bottom:5px;left:50%;transform:translateX(-50%);width:4px;height:4px;background:var(--blue);border-radius:50%;opacity:.9}
.calendar-grid .day-cell.has-entry.today{background:var(--paper);color:var(--red);border-color:var(--red)}
.calendar-grid .day-cell.has-entry.today::after{background:var(--red)}
.calendar-grid .day-cell.selected{background:var(--blue)!important;color:var(--white)!important;border-color:var(--blue)!important;font-weight:700;box-shadow:var(--shadow-md)}
.calendar-grid .day-cell.selected::after{background:var(--white)!important}
.calendar-grid .day-cell.selected.today{background:var(--red)!important;border-color:var(--red)!important;box-shadow:var(--shadow-md)}
.calendar-grid .day-cell.disabled{color:var(--old-paper);cursor:default;background:transparent;font-weight:400}
.calendar-grid .day-cell.disabled:hover{background:transparent;transform:none}
.calendar-grid .day-cell.other-month{color:var(--old-paper);cursor:default;background:transparent;font-weight:400}
.calendar-grid .day-cell.other-month:hover{background:transparent;transform:none}
.user-list-wrap{display:none;margin-top:14px;padding-top:12px;border-top:1px solid var(--old-paper)}
.user-list-wrap.show{display:block}
.user-list-title{font-size:12px;color:#888;margin-bottom:8px;font-weight:600;font-family:var(--font-heading)}
.user-card{padding:10px 12px;margin-bottom:6px;border-radius:var(--wobbly-sm);cursor:pointer;background:var(--paper);border:1.5px solid transparent;transition:all .15s}
.user-card:hover{border-color:var(--old-paper)}
.user-card.active{border-color:var(--blue);background:var(--old-paper)}
.user-card .name{font-size:14px;font-weight:600;color:var(--pencil)}
.user-card .sub{font-size:12px;color:#888;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-card .meta{font-size:12px;margin-top:4px;display:flex;gap:6px;align-items:center;color:#888}
.user-list-empty{font-size:12px;color:#888;padding:8px 0}
.editor-panel{flex:1;min-height:calc(100vh - 108px);display:flex;flex-direction:column}
.meta-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--old-paper);margin-bottom:14px}
.meta-item{display:flex;align-items:center;gap:6px;font-size:14px;color:var(--pencil)}
.meta-item .icon{font-size:16px}
.meta-item select,.meta-item input[type="text"]{border:1.5px solid var(--old-paper);border-radius:var(--wobbly-sm);padding:6px 10px;font-size:14px;outline:none;background:var(--paper);color:var(--pencil)}
.meta-item select:focus,.meta-item input:focus{border-color:var(--blue)}
.meta-item select:disabled,.meta-item input:disabled{opacity:.85;cursor:default;background:var(--old-paper)}
.meta-bar .badge{font-size:11px;padding:2px 10px;border-radius:var(--wobbly-sm);font-weight:600;border:1.5px solid var(--pencil)}
.meta-bar .badge-readonly{background:var(--post-it);color:var(--pencil)}
.meta-bar .badge-class{background:var(--old-paper);color:var(--blue)}
.meta-bar .badge-personal{background:var(--paper);color:var(--red);border-color:var(--red)}
.meta-bar .spacer{flex:1}
.title-input{font-size:26px;font-weight:700;border:none;outline:none;padding:4px 0 12px;color:var(--pencil);background:transparent;width:100%;font-family:var(--font-heading)}
.title-input::placeholder{color:var(--old-paper)}
.title-input:disabled{color:var(--pencil);opacity:1;cursor:default}
#editorWrapper{display:none;flex:1;flex-direction:column;min-height:0;position:relative}
#quillEditor{flex:1;min-height:0}
#quillEditor .ql-editor{font-size:15px;line-height:1.8;min-height:280px}
#quillEditor .ql-editor.ql-blank::before{color:#ccc;font-style:normal;font-size:15px;left:15px;right:15px;pointer-events:none}
#quillEditor .ql-toolbar{border-radius:var(--wobbly) var(--wobbly) 0 0;border-color:var(--old-paper)!important;background:var(--paper)}
#quillEditor .ql-container{border-radius:0 0 var(--wobbly) var(--wobbly);border-color:var(--old-paper)!important}
#quillEditor .ql-editor img{max-width:100%;border-radius:var(--wobbly-sm);display:block;margin:6px auto}
#quillEditor .ql-editor blockquote{border-left:3px solid var(--old-paper);padding-left:10px;margin:6px 0;color:var(--pencil)}
#quillEditor.readonly .ql-editor{background:var(--paper);color:var(--pencil)}
#quillEditor.readonly .ql-toolbar{display:none!important}
#quillEditor.readonly .ql-container{border-radius:var(--wobbly);border-color:var(--old-paper)!important}
#quillEditor.readonly .ql-editor::before,#quillEditor.readonly .ql-editor.ql-blank::before{display:none!important;content:none!important}
.editor-placeholder{flex:1;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:16px;text-align:center;padding:40px 20px;line-height:1.7}
.editor-placeholder[hidden],#editorWrapper[hidden]{display:none!important}
.tag-row{display:none;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}
.tag-row.show{display:flex}
.tag-input-area{display:flex;gap:6px;align-items:center}
.tag-input-area input{border:1.5px solid var(--old-paper);border-radius:var(--wobbly-sm);padding:4px 10px;font-size:13px;outline:none;width:120px;background:var(--paper)}
.tag-input-area input:focus{border-color:var(--blue)}
.tag-input-area input:disabled{background:var(--old-paper)}
.btn-add-tag{padding:4px 12px;border-radius:var(--wobbly-sm);border:1.5px solid var(--pencil);background:var(--post-it);color:var(--pencil);font-size:13px;cursor:pointer;font-family:var(--font-heading);box-shadow:var(--shadow-sm)}
.btn-add-tag:disabled{opacity:.5;cursor:default}
.tag-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:var(--wobbly-sm);background:var(--post-it);color:var(--pencil);font-size:12px;border:1.5px solid var(--pencil)}
.tag-chip button{border:none;background:none;color:var(--pencil);cursor:pointer;font-size:12px;padding:0 2px}
.tag-chip button:disabled{cursor:default;opacity:.4}
.bottom-bar{margin-top:14px;display:none;gap:10px;flex-wrap:wrap;align-items:center}
.bottom-bar.show{display:flex}
.unsaved-dot{display:none;width:auto;color:var(--red);font-size:12px;font-weight:600}
.hw-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:5000;align-items:center;justify-content:center}
.hw-modal.active{display:flex}
.hw-box{background:var(--white);border-radius:var(--wobbly);padding:20px;width:95%;max-width:650px;box-shadow:var(--shadow-lg);border:2px solid var(--pencil)}
.hw-box h4{text-align:center;margin-bottom:12px;color:var(--pencil);font-family:var(--font-heading)}
.hw-tools{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.hw-tools button{padding:6px 14px;border:1.5px solid var(--pencil);background:var(--white);border-radius:var(--wobbly-sm);cursor:pointer;font-size:12px;transition:all .12s;font-family:var(--font-heading)}
.hw-tools button:hover{border-color:var(--blue);color:var(--blue)}
.hw-tools button.active{background:var(--blue);color:var(--white);border-color:var(--blue)}
.hw-tools input[type=color]{width:32px;height:32px;border:1.5px solid var(--pencil);cursor:pointer;border-radius:var(--wobbly-sm)}
.hw-tools input[type=range]{width:80px}
.hw-canvas-wrap{border:2px solid var(--pencil);border-radius:var(--wobbly);overflow:hidden;background:var(--white)}
.hw-canvas-wrap canvas{display:block;width:100%;cursor:crosshair}
.hw-btns{display:flex;gap:8px;margin-top:12px;justify-content:flex-end}
.hw-btns button{padding:8px 20px;border:1.5px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:14px;cursor:pointer;font-weight:600;font-family:var(--font-heading);box-shadow:var(--shadow-sm)}
.hw-btns .hw-insert{background:var(--blue);color:var(--white)}
.hw-btns .hw-insert:hover{background:var(--pencil)}
.hw-btns .hw-cancel{background:var(--white);color:var(--pencil)}
.hw-btns .hw-cancel:hover{background:var(--old-paper)}
@media(max-width:800px){.app{flex-direction:column}.timeline{width:100%;position:static;max-height:none}}
</style>
</head>
<body>
<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<?php
$backUrl = 'main.php?id=' . $classId;
$className = $class['name'];
$pageTitle = '班级史记';
$rightContent = '';
require 'inc/header.php';
?>

<div class="app">
    <div class="card timeline" id="timeline">
        <div class="timeline-header">
            <h2>📔 班级史记</h2>
        </div>
        <div class="timeline-tabs" id="viewTabs">
            <button type="button" class="timeline-tab active" data-view="class" onclick="switchView('class')">班级史记</button>
            <button type="button" class="timeline-tab" data-view="personal" onclick="switchView('personal')">个人列传</button>
        </div>
        <div class="month-selector">
            <button type="button" onclick="changeMonth(-1)">◀</button>
            <span class="month-label" id="monthLabel"></span>
            <button type="button" onclick="changeMonth(1)">▶</button>
        </div>
        <div class="calendar-grid" id="calendarGrid">
            <div class="day-header">日</div><div class="day-header">一</div><div class="day-header">二</div><div class="day-header">三</div><div class="day-header">四</div><div class="day-header">五</div><div class="day-header">六</div>
        </div>
        <div class="user-list-wrap" id="personalUserList"></div>
    </div>

    <div class="card editor-panel">
        <div class="meta-bar" id="metaBar">
            <div class="meta-item">
                <span class="icon">📅</span>
                <span id="selectedDateLabel" style="font-weight:700;color:var(--pencil);">请选择日期</span>
            </div>
            <div class="meta-item" id="moodItem" style="display:none">
                <span class="icon">😊</span>
                <select id="entryMood">
                    <option value="😊">😊 开心</option>
                    <option value="🥰">🥰 幸福</option>
                    <option value="😌">😌 平静</option>
                    <option value="😢">😢 难过</option>
                    <option value="😤">😤 生气</option>
                    <option value="🤩">🤩 兴奋</option>
                    <option value="😴">😴 疲惫</option>
                </select>
            </div>
            <div class="meta-item" id="weatherItem" style="display:none">
                <span class="icon">🌤️</span>
                <select id="entryWeather">
                    <option value="☀️">☀️ 晴</option>
                    <option value="⛅">⛅ 多云</option>
                    <option value="☁️">☁️ 阴</option>
                    <option value="🌧️">🌧️ 雨</option>
                    <option value="⛈️">⛈️ 雷雨</option>
                    <option value="🌨️">🌨️ 雪</option>
                    <option value="🌬️">🌬️ 风</option>
                </select>
            </div>
            <div class="meta-item" id="locationItem" style="display:none">
                <span class="icon">📍</span>
                <input type="text" id="entryLocation" placeholder="地点" style="width:100px;" maxlength="40">
            </div>
            <span class="badge badge-readonly" id="readonlyBadge" style="display:none">只读</span>
            <span class="badge badge-class" id="badgeClass" style="display:none">班级史记</span>
            <span class="spacer"></span>
            <button type="button" class="btn btn-secondary btn-sm" id="handwriteBtn" onclick="openHandwrite()" style="display:none">✏️ 手写板</button>
        </div>

        <input type="text" class="title-input" id="entryTitle" placeholder="今天发生了什么有趣的事？" maxlength="80" style="display:none" disabled>

        <div class="editor-placeholder" id="editorPlaceholder">← 在左侧选择日期查看或编辑</div>
        <div id="editorWrapper">
            <div id="quillEditor"></div>
        </div>

        <div class="tag-row" id="tagRow">
            <div class="tag-input-area" id="tagInputArea">
                <input type="text" id="tagInput" placeholder="添加标签…" maxlength="20" onkeydown="if(event.key==='Enter'){event.preventDefault();addTag();}">
                <button type="button" class="btn-add-tag" id="addTagBtn" onclick="addTag()">+标签</button>
            </div>
            <div id="tagDisplay" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
        </div>

        <div class="bottom-bar" id="bottomBar">
            <span class="unsaved-dot" id="unsavedDot">● 未保存</span>
            <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveEntry()">💾 保存</button>
        </div>
    </div>
</div>

<div class="hw-modal" id="hwModal">
<div class="hw-box">
<h4>✏️ 手写板</h4>
<div class="hw-tools">
<button type="button" onclick="setPenColor('#000000')" id="clrBlack" class="active">黑</button>
<button type="button" onclick="setPenColor('#e53935')" id="clrRed">红</button>
<button type="button" onclick="setPenColor('#2d5da1')" id="clrBlue">蓝</button>
<input type="color" value="#000000" onchange="setPenColor(this.value)" title="选色">
<input type="range" min="1" max="10" value="3" id="penWidth" oninput="hwCtx.lineWidth=this.value" title="粗细">
<button type="button" onclick="clearHandwrite()">清屏</button>
<button type="button" onclick="undoStroke()">撤销</button>
<button type="button" onclick="redoStroke()">重做</button>
</div>
<div class="hw-canvas-wrap"><canvas id="hwCanvas" width="560" height="360"></canvas></div>
<div class="hw-btns">
<button type="button" class="hw-cancel" onclick="closeHandwrite()">取消</button>
<button type="button" class="hw-insert" onclick="insertHandwrite()">插入编辑器</button>
</div>
</div>
</div>

<script src="common.js?v=7"></script>
<script>
var classId = <?php echo json_encode($classId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var today = (function() { var n = new Date(); return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0'); })();
var historyData = <?php echo json_encode($history, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var selectedDate = null;
var currentView = 'class';
var selectedPersonalAuthor = null;
var hasUnsavedChanges = false;
var calYear = new Date().getFullYear();
var calMonth = new Date().getMonth() + 1;
var currentTags = [];
var quill = null;

// 个人列传：读取已授权用户的真实数据
var personalData = <?php
$personalDates = [];
foreach (Database::getAllUsers() as $uid => $user) {
    $consentMap = $user['consent_map'] ?? [];
    $allowed = isset($consentMap[$classId]) ? (bool)$consentMap[$classId] : (!empty($user['consent']) ? true : false);
    if (!$allowed || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', (string)$uid)) continue;
    $personalHistory = historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $uid));
    foreach ($personalHistory as $dateKey => $entry) {
        if (!isset($personalDates[$dateKey])) $personalDates[$dateKey] = [];
        $personalDates[$dateKey][] = [
            'author_uid' => $uid,
            'author_name' => (string)($user['name'] ?? $uid),
            'content' => $entry['content'],
            'title' => $entry['title'] ?? '',
            'mood' => $entry['mood'] ?? '😊',
            'weather' => $entry['weather'] ?? '☀️',
            'location' => $entry['location'] ?? '',
            'tags' => $entry['tags'] ?? [],
        ];
    }
}
echo json_encode($personalDates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function emptyEntry() {
    return {
        content: '',
        title: '',
        mood: '😊',
        weather: '☀️',
        location: '',
        tags: [],
        updated_at: ''
    };
}

function initQuill() {
    if (quill) return;
    quill = new Quill('#quillEditor', {
        theme: 'snow',
        placeholder: '记录今天的故事…',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'header': [1, 2, 3, false] }],
                [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                ['blockquote'],
                [{ 'align': [] }],
                [{ 'color': [] }, { 'background': [] }],
                ['link', 'image'],
                ['clean']
            ]
        }
    });
    quill.on('text-change', function() {
        if (canEditClass()) { hasUnsavedChanges = true; updateUnsavedDot(); }
        syncQuillBlank();
    });
    quill.getModule('toolbar').addHandler('image', function() { chooseAndUploadImage(); });
}

function syncQuillBlank() {
    if (!quill) return;
    var hasImg = !!quill.root.querySelector('img');
    var text = (quill.getText() || '').replace(/\u00a0/g, ' ').replace(/\n/g, '').trim();
    var empty = !text && !hasImg;
    quill.root.classList.toggle('ql-blank', empty);
    if (!empty) {
        // 强制清掉伪元素残留
        quill.root.removeAttribute('data-placeholder');
    }
}

function setQuillHtml(html) {
    initQuill();
    var safe = (html && String(html).trim()) ? String(html) : '';
    quill.setContents([]);
    if (safe) {
        quill.clipboard.dangerouslyPasteHTML(0, safe, 'silent');
    } else {
        quill.setText('', 'silent');
    }
    syncQuillBlank();
}

function canEditClass() {
    return currentView === 'class' && selectedDate === today;
}

function updateUnsavedDot() {
    document.getElementById('unsavedDot').style.display = (canEditClass() && hasUnsavedChanges) ? '' : 'none';
}

function markDirty() {
    if (canEditClass()) { hasUnsavedChanges = true; updateUnsavedDot(); }
}

function renderCalendar() {
    document.getElementById('monthLabel').textContent = calYear + '年 ' + calMonth + '月';
    var grid = document.getElementById('calendarGrid');
    grid.querySelectorAll('.day-cell').forEach(function(d) { d.remove(); });
    var fD = new Date(calYear, calMonth - 1, 1).getDay();
    var dim = new Date(calYear, calMonth, 0).getDate();
    var dip = new Date(calYear, calMonth - 1, 0).getDate();
    for (var i = fD - 1; i >= 0; i--) {
        var c = document.createElement('div');
        c.className = 'day-cell other-month';
        c.textContent = dip - i;
        grid.appendChild(c);
    }
    var src = (currentView === 'personal') ? personalData : historyData;
    for (var d = 1; d <= dim; d++) {
        var ds = calYear + '-' + String(calMonth).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        var cell = document.createElement('div');
        cell.className = 'day-cell';
        if (ds === today) cell.classList.add('today');
        var has = currentView === 'personal'
            ? (Array.isArray(src[ds]) && src[ds].length > 0)
            : !!src[ds];
        if (has) cell.classList.add('has-entry');
        if (ds === selectedDate) cell.classList.add('selected');
        // 仅：今天 或 有记录 的日期可选
        var ok = (ds === today) || has;
        if (!ok) cell.classList.add('disabled');
        cell.textContent = d;
        if (ok) {
            (function(dateStr) {
                cell.onclick = function() { selectDate(dateStr); };
            })(ds);
        }
        grid.appendChild(cell);
    }
    var t = fD + dim, r = t <= 35 ? 35 - t : 42 - t;
    for (var n = 1; n <= r; n++) {
        var oc = document.createElement('div');
        oc.className = 'day-cell other-month';
        oc.textContent = n;
        grid.appendChild(oc);
    }
    if (currentView !== 'personal') {
        var pl = document.getElementById('personalUserList');
        pl.classList.remove('show');
        pl.innerHTML = '';
    }
}

function setMetaEditable(editable) {
    ['entryMood', 'entryWeather', 'entryLocation', 'entryTitle', 'tagInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.disabled = !editable;
    });
    document.getElementById('addTagBtn').disabled = !editable;
    document.getElementById('tagInputArea').style.display = editable ? '' : 'none';
}

function showMetaFields(show) {
    ['moodItem', 'weatherItem', 'locationItem'].forEach(function(id) {
        document.getElementById(id).style.display = show ? '' : 'none';
    });
    document.getElementById('entryTitle').style.display = show ? '' : 'none';
    document.getElementById('tagRow').classList.toggle('show', show);
    document.getElementById('badgeClass').style.display = show ? '' : 'none';
}

function fillMeta(entry) {
    entry = entry || emptyEntry();
    document.getElementById('entryMood').value = entry.mood || '😊';
    document.getElementById('entryWeather').value = entry.weather || '☀️';
    document.getElementById('entryLocation').value = entry.location || '';
    document.getElementById('entryTitle').value = entry.title || '';
    currentTags = Array.isArray(entry.tags) ? entry.tags.slice() : [];
    renderTags();
}

function renderTags() {
    var editable = canEditClass();
    var box = document.getElementById('tagDisplay');
    box.innerHTML = '';
    currentTags.forEach(function(t) {
        var span = document.createElement('span');
        span.className = 'tag-chip';
        span.appendChild(document.createTextNode(t));
        if (editable) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '✕';
            btn.onclick = function() { removeTag(t); };
            span.appendChild(document.createTextNode(' '));
            span.appendChild(btn);
        }
        box.appendChild(span);
    });
}

function addTag() {
    if (!canEditClass()) return;
    var input = document.getElementById('tagInput');
    var tag = (input.value || '').trim();
    if (!tag || currentTags.indexOf(tag) >= 0) { input.value = ''; return; }
    if (currentTags.length >= 12) { showToast('最多 12 个标签'); return; }
    currentTags.push(tag);
    input.value = '';
    renderTags();
    markDirty();
}

function removeTag(tag) {
    if (!canEditClass()) return;
    currentTags = currentTags.filter(function(t) { return t !== tag; });
    renderTags();
    markDirty();
}

function selectDate(dateStr) {
    selectedDate = dateStr;
    selectedPersonalAuthor = null;
    hasUnsavedChanges = false;
    updateUnsavedDot();
    initQuill();

    document.getElementById('selectedDateLabel').textContent = dateStr;
    var ew = document.getElementById('editorWrapper');
    var ph = document.getElementById('editorPlaceholder');
    document.getElementById('bottomBar').classList.add('show');

    if (currentView === 'personal') {
        showMetaFields(true);
        setMetaEditable(false);
        document.getElementById('badgeClass').textContent = '个人列传';
        document.getElementById('badgeClass').className = 'badge badge-personal';
        document.getElementById('readonlyBadge').style.display = '';
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('handwriteBtn').style.display = 'none';
        document.getElementById('entryTitle').style.display = 'none';
        document.getElementById('tagRow').classList.remove('show');
        showMetaFields(false);
        document.getElementById('badgeClass').style.display = '';

        var pes = personalData[dateStr] || [];
        var listEl = document.getElementById('personalUserList');
        if (pes.length > 0) {
            var uidMap = {}, unique = [];
            pes.forEach(function(pe) {
                if (!uidMap[pe.author_uid]) { uidMap[pe.author_uid] = true; unique.push(pe); }
            });
            listEl.innerHTML = '<div class="user-list-title">📋 ' + dateStr + ' 的授权用户</div>'
                + unique.map(function(pe) {
                    var sub = (pe.title || '无标题');
                    return '<div class="user-card' + (selectedPersonalAuthor === pe.author_uid ? ' active' : '') + '" onclick="showPersonalEntry(\'' + pe.author_uid + '\')">'
                        + '<div class="name">👤 ' + escapeHtml(pe.author_name) + '</div>'
                        + '<div class="sub">' + escapeHtml(sub) + '</div>'
                        + '<div class="meta"><span>' + escapeHtml(pe.mood || '😊') + '</span><span>' + escapeHtml(pe.weather || '☀️') + '</span>'
                        + (pe.location ? '<span>📍' + escapeHtml(pe.location) + '</span>' : '') + '</div>'
                        + '</div>';
                }).join('');
            listEl.classList.add('show');
            ph.hidden = false;
            ph.style.display = '';
            ew.hidden = true;
            ew.style.display = 'none';
            ph.innerHTML = '👈 请在下方用户列表中选择一位查看其 Vlog';
            if (quill) {
                setQuillHtml('');
                quill.enable(false);
                document.getElementById('quillEditor').classList.add('readonly');
            }
        } else {
            listEl.innerHTML = '<div class="user-list-empty">当天没有授权用户的个人列传</div>';
            listEl.classList.add('show');
            ph.hidden = false;
            ph.style.display = '';
            ew.hidden = true;
            ew.style.display = 'none';
            ph.innerHTML = '当天没有个人列传';
            fillMeta(emptyEntry());
            if (quill) {
                setQuillHtml('');
                quill.enable(false);
                document.getElementById('quillEditor').classList.add('readonly');
            }
        }
    } else {
        document.getElementById('personalUserList').classList.remove('show');
        document.getElementById('personalUserList').innerHTML = '';
        var isT = (dateStr === today);
        var entry = historyData[dateStr] || emptyEntry();
        showMetaFields(true);
        setMetaEditable(isT);
        fillMeta(entry);

        ph.style.display = 'none';
        ph.hidden = true;
        ew.style.display = 'flex';
        ew.hidden = false;
        setQuillHtml(entry.content || '');

        if (isT) {
            quill.enable(true);
            document.getElementById('quillEditor').classList.remove('readonly');
            quill.root.dataset.placeholder = '记录今天的故事…';
        } else {
            quill.enable(false);
            document.getElementById('quillEditor').classList.add('readonly');
            quill.root.dataset.placeholder = '';
        }
        syncQuillBlank();

        document.getElementById('badgeClass').textContent = '班级史记';
        document.getElementById('badgeClass').className = 'badge badge-class';
        document.getElementById('readonlyBadge').style.display = isT ? 'none' : '';
        document.getElementById('saveBtn').style.display = isT ? '' : 'none';
        document.getElementById('handwriteBtn').style.display = isT ? '' : 'none';
        document.getElementById('tagInputArea').style.display = isT ? '' : 'none';
    }
    renderCalendar();
}

function showPersonalEntry(authorUid) {
    if (!selectedDate || !personalData[selectedDate]) return;
    selectedPersonalAuthor = authorUid;
    var ents = personalData[selectedDate].filter(function(pe) { return pe.author_uid === authorUid; });
    if (!ents.length) return;
    var pe = ents[0];

    // 刷新列表选中态
    var listEl = document.getElementById('personalUserList');
    listEl.querySelectorAll('.user-card').forEach(function(card) {
        card.classList.remove('active');
    });
    // 重渲列表以保持 active
    var pes = personalData[selectedDate] || [];
    var uidMap = {}, unique = [];
    pes.forEach(function(p) { if (!uidMap[p.author_uid]) { uidMap[p.author_uid] = true; unique.push(p); } });
    listEl.innerHTML = '<div class="user-list-title">📋 ' + selectedDate + ' 的授权用户</div>'
        + unique.map(function(p) {
            var sub = (p.title || '无标题');
            return '<div class="user-card' + (p.author_uid === authorUid ? ' active' : '') + '" onclick="showPersonalEntry(\'' + p.author_uid + '\')">'
                + '<div class="name">👤 ' + escapeHtml(p.author_name) + '</div>'
                + '<div class="sub">' + escapeHtml(sub) + '</div>'
                + '<div class="meta"><span>' + escapeHtml(p.mood || '😊') + '</span><span>' + escapeHtml(p.weather || '☀️') + '</span>'
                + (p.location ? '<span>📍' + escapeHtml(p.location) + '</span>' : '') + '</div>'
                + '</div>';
        }).join('');
    listEl.classList.add('show');

    initQuill();
    showMetaFields(true);
    setMetaEditable(false);
    fillMeta(pe);
    document.getElementById('entryTitle').style.display = '';
    document.getElementById('tagRow').classList.add('show');
    document.getElementById('tagInputArea').style.display = 'none';

    document.getElementById('editorPlaceholder').style.display = 'none';
    document.getElementById('editorPlaceholder').hidden = true;
    document.getElementById('editorWrapper').style.display = 'flex';
    document.getElementById('editorWrapper').hidden = false;
    setQuillHtml(pe.content || '');
    quill.enable(false);
    document.getElementById('quillEditor').classList.add('readonly');
    quill.root.dataset.placeholder = '';
    syncQuillBlank();
    document.getElementById('selectedDateLabel').textContent = selectedDate + ' — ' + pe.author_name;
    document.getElementById('badgeClass').textContent = '个人列传 · ' + pe.author_name;
    document.getElementById('badgeClass').className = 'badge badge-personal';
    document.getElementById('badgeClass').style.display = '';
    document.getElementById('readonlyBadge').style.display = '';
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('handwriteBtn').style.display = 'none';
    document.getElementById('bottomBar').classList.add('show');
}

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.timeline-tab').forEach(function(t) {
        t.classList.toggle('active', t.dataset.view === view);
    });
    selectedDate = null;
    selectedPersonalAuthor = null;
    hasUnsavedChanges = false;
    updateUnsavedDot();
    currentTags = [];
    document.getElementById('editorWrapper').style.display = 'none';
    document.getElementById('editorWrapper').hidden = true;
    document.getElementById('editorPlaceholder').style.display = '';
    document.getElementById('editorPlaceholder').hidden = false;
    document.getElementById('editorPlaceholder').textContent = '← 在左侧选择日期查看或编辑';
    document.getElementById('bottomBar').classList.remove('show');
    if (quill) {
        setQuillHtml('');
        document.getElementById('quillEditor').classList.remove('readonly');
    }
    document.getElementById('readonlyBadge').style.display = 'none';
    document.getElementById('selectedDateLabel').textContent = '请选择日期';
    showMetaFields(false);
    document.getElementById('entryTitle').style.display = 'none';
    document.getElementById('tagRow').classList.remove('show');
    var pl = document.getElementById('personalUserList');
    pl.classList.remove('show');
    pl.innerHTML = '';
    renderCalendar();
}

function changeMonth(delta) {
    calMonth += delta;
    if (calMonth > 12) { calMonth = 1; calYear++; }
    if (calMonth < 1) { calMonth = 12; calYear--; }
    renderCalendar();
}

async function saveEntry() {
    if (!canEditClass()) { showToast('只能编辑今天的班级史记'); return false; }
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = '...';
    var fd = new FormData();
    fd.append('action', 'save_entry');
    fd.append('date', selectedDate);
    fd.append('content', quill.root.innerHTML);
    fd.append('title', document.getElementById('entryTitle').value || '');
    fd.append('mood', document.getElementById('entryMood').value || '😊');
    fd.append('weather', document.getElementById('entryWeather').value || '☀️');
    fd.append('location', document.getElementById('entryLocation').value || '');
    fd.append('tags', JSON.stringify(currentTags));
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        var r = await (await fetch('history_book.php?id=' + encodeURIComponent(classId), { method: 'POST', body: fd })).json();
        if (r.success) {
            historyData[selectedDate] = r.entry || {
                content: r.content || quill.root.innerHTML,
                title: document.getElementById('entryTitle').value || '',
                mood: document.getElementById('entryMood').value,
                weather: document.getElementById('entryWeather').value,
                location: document.getElementById('entryLocation').value || '',
                tags: currentTags.slice()
            };
            hasUnsavedChanges = false;
            updateUnsavedDot();
            renderCalendar();
            showToast('✅ 已保存', 'success');
            return true;
        }
        showToast(r.error || '保存失败');
        return false;
    } catch (e) {
        showToast('网络异常');
        return false;
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 保存';
    }
}

function initEditor() {
    document.addEventListener('paste', handlePaste);
    ['entryMood', 'entryWeather', 'entryLocation', 'entryTitle'].forEach(function(id) {
        var el = document.getElementById(id);
        el.addEventListener('change', markDirty);
        el.addEventListener('input', markDirty);
    });
}

function chooseAndUploadImage() {
    if (!canEditClass()) { showToast('只能编辑今天的记录'); return; }
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp';
    input.onchange = async function() {
        if (!input.files || !input.files[0]) return;
        try {
            var url = await uploadHistoryImage(input.files[0]);
            var range = quill.getSelection(true);
            quill.insertEmbed(range.index, 'image', url);
            quill.setSelection(range.index + 1);
            hasUnsavedChanges = true;
            updateUnsavedDot();
        } catch (e) {
            showToast(e.message || '图片上传失败');
        }
    };
    input.click();
}

function handlePaste(e) {
    var cd = e.clipboardData || window.clipboardData, imgFile = null;
    if (cd && cd.items) {
        for (var i = 0; i < cd.items.length; i++) {
            var it = cd.items[i];
            if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                imgFile = it.getAsFile();
                break;
            }
        }
    }
    if (imgFile) {
        e.preventDefault();
        if (!canEditClass()) { showToast('只能编辑今天的记录'); return; }
        pasteUploadImage(imgFile);
    }
}

async function pasteUploadImage(file) {
    try {
        var url = await uploadHistoryImage(file);
        var range = quill.getSelection(true);
        quill.insertEmbed(range.index, 'image', url);
        quill.setSelection(range.index + 1);
        hasUnsavedChanges = true;
        updateUnsavedDot();
    } catch (e) {
        showToast(e.message || '图片上传失败');
    }
}

async function uploadHistoryImage(blob) {
    var fd = new FormData();
    fd.append('class_id', classId);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    fd.append('image', blob, blob.name || 'handwriting.png');
    var r = await (await fetch('upload.php', { method: 'POST', body: fd })).json();
    if (!r.success) throw new Error(r.error || '图片上传失败');
    return r.url;
}

var hwCanvas, hwCtx, hwStrokes = [], hwRedoStrokes = [], hwDrawing = false, hwCurrentStroke = [];
function openHandwrite() {
    if (!canEditClass()) { showToast('只能编辑今天的记录'); return; }
    document.getElementById('hwModal').classList.add('active');
    hwCanvas = document.getElementById('hwCanvas');
    hwCtx = hwCanvas.getContext('2d');
    hwCtx.lineWidth = parseInt(document.getElementById('penWidth').value, 10) || 3;
    hwCtx.lineCap = 'round';
    hwCtx.strokeStyle = '#000000';
    hwCtx.clearRect(0, 0, hwCanvas.width, hwCanvas.height);
    hwStrokes = [];
    hwRedoStrokes = [];
    hwCanvas.onmousedown = function(e) {
        hwDrawing = true;
        var p = getHwPos(e);
        hwCurrentStroke = [{ x: p.x, y: p.y }];
        hwCtx.beginPath();
        hwCtx.moveTo(p.x, p.y);
    };
    hwCanvas.onmousemove = function(e) {
        if (!hwDrawing) return;
        var p = getHwPos(e);
        hwCurrentStroke.push({ x: p.x, y: p.y });
        hwCtx.lineTo(p.x, p.y);
        hwCtx.stroke();
    };
    hwCanvas.onmouseup = hwEndStroke;
    hwCanvas.onmouseleave = hwEndStroke;
    hwCanvas.ontouchstart = function(e) {
        e.preventDefault();
        hwDrawing = true;
        var p = getHwTouch(e);
        hwCurrentStroke = [{ x: p.x, y: p.y }];
        hwCtx.beginPath();
        hwCtx.moveTo(p.x, p.y);
    };
    hwCanvas.ontouchmove = function(e) {
        e.preventDefault();
        if (!hwDrawing) return;
        var p = getHwTouch(e);
        hwCurrentStroke.push({ x: p.x, y: p.y });
        hwCtx.lineTo(p.x, p.y);
        hwCtx.stroke();
    };
    hwCanvas.ontouchend = hwEndStroke;
}
function hwEndStroke() {
    if (hwDrawing) {
        hwStrokes.push({ color: hwCtx.strokeStyle, width: hwCtx.lineWidth, points: hwCurrentStroke });
        hwRedoStrokes = [];
        hwDrawing = false;
    }
}
function getHwPos(e) {
    var r = hwCanvas.getBoundingClientRect();
    return { x: (e.clientX - r.left) * (hwCanvas.width / r.width), y: (e.clientY - r.top) * (hwCanvas.height / r.height) };
}
function getHwTouch(e) {
    var t = e.touches[0], r = hwCanvas.getBoundingClientRect();
    return { x: (t.clientX - r.left) * (hwCanvas.width / r.width), y: (t.clientY - r.top) * (hwCanvas.height / r.height) };
}
function setPenColor(c) {
    hwCtx.strokeStyle = c;
    document.querySelectorAll('.hw-tools button[id^=clr]').forEach(function(b) { b.classList.remove('active'); });
    var id = 'clr' + (c === '#000000' ? 'Black' : c === '#e53935' ? 'Red' : c === '#2d5da1' ? 'Blue' : '');
    if (id && document.getElementById(id)) document.getElementById(id).classList.add('active');
}
function undoStroke() { if (hwStrokes.length) hwRedoStrokes.push(hwStrokes.pop()); redrawHw(); }
function redoStroke() { if (hwRedoStrokes.length) hwStrokes.push(hwRedoStrokes.pop()); redrawHw(); }
function clearHandwrite() {
    hwCtx.clearRect(0, 0, hwCanvas.width, hwCanvas.height);
    hwStrokes = [];
    hwRedoStrokes = [];
}
function redrawHw() {
    hwCtx.clearRect(0, 0, hwCanvas.width, hwCanvas.height);
    hwStrokes.forEach(function(s) {
        hwCtx.beginPath();
        hwCtx.strokeStyle = s.color;
        hwCtx.lineWidth = s.width;
        if (s.points.length) {
            hwCtx.moveTo(s.points[0].x, s.points[0].y);
            s.points.forEach(function(p) { hwCtx.lineTo(p.x, p.y); });
            hwCtx.stroke();
        }
    });
}
function closeHandwrite() { document.getElementById('hwModal').classList.remove('active'); }
function insertHandwrite() {
    hwCanvas.toBlob(async function(blob) {
        if (!blob) { showToast('手写图片生成失败'); return; }
        try {
            var url = await uploadHistoryImage(blob);
            var range = quill.getSelection(true);
            quill.insertEmbed(range.index, 'image', url);
            quill.setSelection(range.index + 1);
            hwCtx.clearRect(0, 0, hwCanvas.width, hwCanvas.height);
            hwStrokes = [];
            hwRedoStrokes = [];
            closeHandwrite();
            hasUnsavedChanges = true;
            updateUnsavedDot();
        } catch (e) {
            showToast(e.message || '手写图片上传失败');
        }
    }, 'image/png');
}

initEditor();
renderCalendar();
</script>
</body>
</html>
