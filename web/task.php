<?php
/**
 * ============================================================
 * 默写任务页面 (重构版)
 * ============================================================
 *
 * 两个视图:
 *   1. 任务列表视图 (默认) — 按日期分组展示所有待办任务
 *   2. 任务执行视图 (?task_id=xxx) — 单词卡片 show/hide/dict
 *
 * 任务列表:
 *   - 按日期分组，同日期任务以编辑图标区分
 *   - 今天的日期标题带蓝色"今天"标签
 *   - 所有任务均可点击进入，无锁定限制
 *
 * 操作按钮 (执行视图中状态栏右侧单个按钮):
 *   短按 → 完成 → OK蒙版 → 回列表
 *   长按 (≥800ms) → 按钮绿色渐变红色 + 脉动 → 释放后取消 → OK蒙版 → 回列表
 *
 * 听写暂停: 立即暂停，恢复时从当前单词重新播放
 *
 * URL 参数:
 *   ?id={classId}       — 班级ID (必填)
 *   ?task_id={taskId}   — 进入执行视图 (可选)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
$csrfToken = csrfToken(); // CSRF令牌，供前端POST使用
$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];

// Check password protection
requireClassAuth($classId, $class);
$words = Database::getWords($classId);
$tasks = Database::getTasks($classId);
$settings = Database::getSettings();
$highlightId = reqGet('highlight');
$searchQ = trim(reqGet('search'));

// Auto-cancel expired tasks
$tasks = Database::autoCancelExpiredTasks($classId);
$today = date('Y-m-d');

// ---- Pending tasks for list ----
$pendingTasks = array_filter($tasks, function($t) use ($searchQ, $words) {
    if ($t['status'] !== 'pending') return false;
    if ($searchQ === '') return true;
    $wmap = []; foreach ($words as $w) $wmap[(string)$w['id']] = $w;
    foreach (($t['word_ids'] ?? []) as $wid) {
        if (isset($wmap[$wid]) && (mb_stripos($wmap[$wid]['word'], $searchQ) !== false || mb_stripos($wmap[$wid]['meaning'], $searchQ) !== false)) return true;
    }
    return false;
});
$pendingTasks = array_values($pendingTasks);
usort($pendingTasks, function($a, $b) {
    if ($a['date'] !== $b['date']) return $a['date'] <=> $b['date'];
    return strnatcmp($a['label'] ?? '', $b['label'] ?? '');
});

// ---- Execution view ----
$selectedTask = null;
$taskId = reqGet('task_id');
if ($taskId !== '' && isset($tasks[$taskId]) && $tasks[$taskId]['status'] === 'pending') {
    $selectedTask = $tasks[$taskId];
}

// ---- POST handlers ----
if (isset($_POST['action'])) {
    requireCsrf(); // CSRF校验
    header('Content-Type: application/json');
    $action = $_POST['action'];
    if ($action === 'complete_task') {
        $tid = reqPost('task_id');
        $currentTaskId = reqGet('task_id');
        $changed = false;
        if ($tid !== '' && hash_equals((string)$currentTaskId, (string)$tid)) {
            Database::updateClassData($classId, 'tasks', function($latestTasks) use ($tid, &$changed) {
                if (!isset($latestTasks[$tid]) || ($latestTasks[$tid]['status'] ?? '') !== 'pending') return null;
                $latestTasks[$tid]['status'] = 'completed';
                $changed = true;
                return $latestTasks;
            });
        }
        if ($changed) {
            Database::update('settings.json', function($latestSettings) use ($classId, $tid) { $latestSettings['last_task_id_' . $classId] = $tid; return $latestSettings; });
            echo json_encode(['success' => true]); exit;
        }
        echo json_encode(['success' => false, 'error' => '任务不存在或已完成']); exit;
    }
    if ($action === 'cancel_task') {
        $tid = reqPost('task_id');
        $currentTaskId = reqGet('task_id');
        $changed = false;
        if ($tid !== '' && hash_equals((string)$currentTaskId, (string)$tid)) {
            Database::updateClassData($classId, 'tasks', function($latestTasks) use ($tid, &$changed) {
                if (!isset($latestTasks[$tid]) || ($latestTasks[$tid]['status'] ?? '') !== 'pending') return null;
                $latestTasks[$tid]['status'] = 'cancelled';
                $changed = true;
                return $latestTasks;
            });
        }
        if ($changed) {
            echo json_encode(['success' => true]); exit;
        }
        echo json_encode(['success' => false, 'error' => '任务不存在或已完成']); exit;
    }
    echo json_encode(['success' => false, 'error' => '未知操作']); exit;
}

$wordMap = [];
foreach ($words as $w) { $wordMap[$w['id']] = $w; }
?>
<?php $pageTitle = '默写任务'; require 'inc/head.php'; ?>

<body>
<script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
<?php
$backUrl = 'main.php?id=' . $classId;
$className = $class['name'];
$pageTitle = '默写任务';
$rightContent = '';
if ($selectedTask):
    $rightContent = '<button id="completeBtn" class="btn btn-sm" style="background:var(--blue);color:var(--white);" onclick="completeTask()" title="标记完成">完成</button>'
        . '<button id="cancelBtn" class="btn btn-danger btn-sm" onclick="cancelTask()" title="取消任务">取消</button>'
        . '<button class="btn btn-sm" style="background:var(--blue);color:var(--white);" id="followBtn" onclick="showTaskFollow()" title="跟读单词"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:3px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>跟读</button>'
        . '<div style="display:flex;background:var(--old-paper);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);overflow:hidden;">'
        . '<button id="showModeBtn" class="btn btn-sm mode-btn active" onclick="setMode(\'show\')">展示</button>'
        . '<button id="hideModeBtn" class="btn btn-sm mode-btn" onclick="setMode(\'hide\')">默写</button>'
        . '<button id="dictModeBtn" class="btn btn-sm mode-btn" onclick="setMode(\'dict\')">听写</button>'
        . '</div>';
endif;
require 'inc/header.php';
?>

<!-- ============== TASK LIST VIEW (default) ============== -->
<?php if (!$selectedTask): ?>
<div class="content" id="listView">
    <?php if ($searchQ !== ''): ?>
    <div class="card" style="max-width:800px;margin:8px auto;color:var(--blue);background:var(--old-paper);">搜索 "<?php echo htmlspecialchars($searchQ); ?>" 的待办任务 <a href="task.php?id=<?php echo $classId; ?>" style="color:var(--red);text-decoration:none;margin-left:8px;">×清除</a></div>
    <?php endif; ?>
    <?php if (empty($pendingTasks)): ?>
        <div class="empty-state" style="padding:80px 20px;">
            <div class="icon"><svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
            <p style="font-size:15px;color:#999;">暂无待办默写任务</p>
            <p style="font-size:13px;color:#bbb;">去单词库创建任务吧</p>
            <button class="btn btn-primary" style="display:block;width:200px;margin:18px auto 0;" onclick="showOkOverlayThen('words.php?id=<?php echo $classId; ?>')">前往单词库</button>
        </div>
    <?php else: ?>
    <div class="task-list">
        <?php
        $lastDate = null;
        foreach ($pendingTasks as $t):
            $isToday = ($t['date'] === $today);
            if ($t['date'] !== $lastDate):
                if ($lastDate !== null) echo '</div>';
                $lastDate = $t['date'];
        ?>
        <div class="task-date-group">
            <div class="task-date-header">
                <?php echo $t['date']; ?>
                <?php if ($isToday): ?><span class="today-pill">今天</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="task-list-item card" style="display:flex;align-items:center;gap:12px;cursor:pointer;margin:4px 0;" onclick="startTask('<?php echo $t['id']; ?>')">
            <div class="tl-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <div class="tl-info">
                <div class="tl-label"><?php echo htmlspecialchars($t['label'] ?? '任务'); ?></div>
                <div class="tl-meta"><?php echo count($t['word_ids'] ?? []); ?> 个单词</div>
            </div>
            <span class="tl-arrow">›</span>
        </div>
        <?php endforeach; ?>
        <?php if ($lastDate !== null) echo '</div>'; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ============== TASK EXECUTION VIEW ============== -->
<div class="content" id="execView">
    <div class="task-date" style="text-align:center;font-size:18px;color:var(--pencil);margin-bottom:16px;font-weight:bold;">
        <?php echo $selectedTask['date']; ?>
        <?php if (!empty($selectedTask['label'])): ?>
            <span class="tag" style="margin-left:6px;color:var(--blue);"><?php echo htmlspecialchars($selectedTask['label']); ?></span>
        <?php endif; ?>
    </div>
    <div class="word-grid" id="wordGrid">
        <?php foreach ($selectedTask['word_ids'] as $wid): ?>
            <?php if (isset($wordMap[$wid])): $w = $wordMap[$wid]; ?>
                <div class="word-card" data-id="<?php echo htmlspecialchars($w['id']); ?>" data-word="<?php echo htmlspecialchars($w['word']); ?>">
                    <div class="card-body">
                        <div class="word" lang="en"><span><?php echo htmlspecialchars($w['word']); ?></span></div>
                        <div class="meaning"><span><?php echo htmlspecialchars($w['meaning']); ?></span></div>
                        <div class="divider" style="width:45%;height:1.5px;background:#ddd;margin:10px 0;"></div>
                        <?php if ($w['pos']): ?>
                            <div class="pos"><?php echo htmlspecialchars($w['pos']); ?></div>
                        <?php endif; ?>
                    </div>
                    <button class="speaker" onclick='speak(<?php echo htmlspecialchars(json_encode($w['word'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg></button>
                    <button class="speaker pron-btn" title="全球发音" onclick='event.stopPropagation();showPronList(<?php echo htmlspecialchars(json_encode($w['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($w['word'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg></button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Dictation UI (hidden by default) -->
    <div id="dictationUI" style="display:none;">
        <div class="dict-container" id="dictContainer"></div>
    </div>


</div>

<!-- Dictation Complete Modal -->
<div class="modal" id="dictCompleteModal">
    <div class="modal-content">
        <div class="modal-title">听写完成</div>
        <p style="text-align:center;color:#666;margin:16px 0;">是否设置为已完成？</p>
        <div class="modal-btns">
            <button type="button" class="cancel" onclick="dictFinish(false)">否</button>
            <button type="button" class="submit" onclick="dictFinish(true)">是</button>
        </div>
    </div>
</div>

<!-- Task Follow-along Modal -->
<div class="modal" id="taskFollowModal">
    <div class="modal-content" style="max-width:380px;">
        <div class="modal-title">跟读设置</div>
        <div class="form-group">
            <label>每个单词朗读次数</label>
            <input type="number" id="taskFollowRepeat" value="<?php echo $settings['follow_repeat_' . $classId] ?? $settings['follow_repeat'] ?? 1; ?>" min="1" max="5" step="1" style="width:100%;">
        </div>
        <div class="form-group">
            <label>缓冲时间（-0.5 到 5 秒）：停顿 = 音频时长 + 此值（负数提前，最短 0 秒）</label>
            <input type="number" id="taskFollowBuffer" value="<?php echo $settings['follow_buffer_' . $classId] ?? $settings['follow_buffer'] ?? 0.5; ?>" min="-0.5" max="5" step="0.5" style="width:100%;">
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="taskFollowShuffle" style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;">
                <span>随机乱序（只打乱跟读顺序，不改单词位置）</span>
            </label>
        </div>
        <div class="modal-btns">
            <button type="button" class="cancel" onclick="closeTaskFollowModal()">取消</button>
            <button type="button" class="submit" onclick="startTaskFollow()">开始跟读</button>
        </div>
    </div>
</div>

<!-- Task Follow-along Player (auto-collapses to bubble after 3s) -->
<div id="taskFollowPlayer" style="display:none;position:fixed;bottom:80px;left:16px;right:16px;z-index:700;max-width:500px;margin:0 auto;">
    <div class="card" style="padding:14px 18px;box-shadow:var(--shadow-md);">
<div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:11px;color:#999;margin-bottom:2px;">跟读中 <span id="taskFollowProgress">0/0</span></div>
                    <div id="taskFollowWord" style="font-size:20px;font-weight:bold;color:var(--blue);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">--</div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <button id="taskFollowPauseBtn" onclick="toggleTaskFollowPause()" class="btn btn-danger btn-sm" style="background:var(--red);">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:3px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停
                    </button>
                    <button onclick="stopTaskFollow()" class="btn btn-danger btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="vertical-align:-2px;margin-right:3px;"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>停止
                    </button>
            </div>
        </div>
    </div>
</div>
<!-- Task Follow-along Bubble (collapsed state) -->
<div id="taskFollowBubble" onclick="expandTaskFollowBubble()" style="display:none;position:fixed;bottom:24px;left:20px;width:52px;height:52px;background:var(--blue);border-radius:var(--wobbly);z-index:702;cursor:pointer;box-shadow:var(--shadow-md);animation:followBubblePulse 2s ease-in-out infinite;align-items:center;justify-content:center;border:2px solid var(--pencil);">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>
</div>

<?php endif; ?>

<div class="toast" id="toast"></div>

<form id="completeForm" style="display:none;">
    <input type="hidden" name="action" value="complete_task">
    <input type="hidden" name="task_id" id="completeTaskId" value="<?php echo $selectedTask['id'] ?? ''; ?>">
</form>

<script src="common.js?v=10"></script>
<script>var speakRepeat = <?php echo $settings['repeat_' . $classId] ?? $settings['default_repeat'] ?? 1; ?>;</script>
<?php if ($selectedTask): ?>
<script>
    const classId = '<?php echo $classId; ?>';
    let currentMode = 'show';

    // ---------- Init ----------
    window.addEventListener('load', function() {
        initMarquee();
        const highlightId = <?php echo json_encode($highlightId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const card = document.querySelector('.word-card[data-id="' + CSS.escape(highlightId) + '"]');
        if (highlightId && card) { card.classList.add('follow-highlight'); card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });



    function setMode(mode) {
        currentMode = mode;
        document.getElementById('showModeBtn').classList.toggle('active', mode === 'show');
        document.getElementById('hideModeBtn').classList.toggle('active', mode === 'hide');
        document.getElementById('dictModeBtn').classList.toggle('active', mode === 'dict');

        const wordGrid = document.getElementById('wordGrid');
        const dictUI = document.getElementById('dictationUI');
        const taskDateEl = document.querySelector('.task-date');
        const followBtn = document.getElementById('followBtn');

        stopDictAudio();
        stopTaskFollow();
        dictState.running = false;
        dictState.paused = false;
        clearInterval(dictState.countdownTimer);
        dictState.countdownTimer = null;

        if (mode === 'dict') {
            if (wordGrid) wordGrid.style.display = 'none';
            if (taskDateEl) taskDateEl.style.display = 'none';
            if (followBtn) followBtn.style.display = 'none';
            
            dictUI.style.display = 'block';
            dictState.words = [];
            for (const wid of taskWordIds) { if (wordMapData[wid]) dictState.words.push(wordMapData[wid].word); }
            renderDictPrepare();
        } else {
            if (wordGrid) wordGrid.style.display = '';
            if (taskDateEl) taskDateEl.style.display = '';
            if (followBtn) followBtn.style.display = (mode === 'show') ? '' : 'none';
            
            dictUI.style.display = 'none';
            wordGrid.querySelectorAll('.word-card').forEach(card => card.classList.toggle('hide-word', mode === 'hide'));
        }

        // 听写模式下隐藏顶栏 完成/取消 按钮（听写有自己的完成流程），其余模式显示
        const completeBtn = document.getElementById('completeBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const showActionBtns = mode !== 'dict';
        if (completeBtn) completeBtn.style.display = showActionBtns ? '' : 'none';
        if (cancelBtn) cancelBtn.style.display = showActionBtns ? '' : 'none';

        // 默写模式下隐藏单词卡发音键（避免听音得到提示）
        wordGrid.querySelectorAll('.word-card .speaker').forEach(btn => {
            btn.style.display = (mode === 'hide') ? 'none' : '';
        });
    }

    async function completeTask() {
        const fd = new FormData(); fd.append('action', 'complete_task'); fd.append('task_id', document.getElementById('completeTaskId').value); fd.append('csrf_token', CSRF_TOKEN);
        try {
            const d = await (await fetch('task.php?id=' + classId + '&task_id=' + encodeURIComponent(document.getElementById('completeTaskId').value), { method: 'POST', body: fd })).json();
            if (d.success) showOkOverlayThen('task.php?id=' + classId);
            else if (d.error) showToast(d.error, 'error');
        } catch(e) { showToast('网络异常', 'error'); }
    }

    async function cancelTask() {
        const fd = new FormData(); fd.append('action', 'cancel_task'); fd.append('task_id', document.getElementById('completeTaskId').value); fd.append('csrf_token', CSRF_TOKEN);
        try {
            const d = await (await fetch('task.php?id=' + classId + '&task_id=' + encodeURIComponent(document.getElementById('completeTaskId').value), { method: 'POST', body: fd })).json();
            if (d.success) showOkOverlayThen('task.php?id=' + classId);
            else if (d.error) showToast(d.error, 'error');
        } catch(e) { showToast('网络异常', 'error'); }
    }

    // ==================== 听写状态机 ====================
    const taskWordIds = <?php echo json_encode($selectedTask['word_ids'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const wordMapData = <?php echo json_encode($wordMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const settings = <?php echo json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let dictState = {
        words: [],
        currentIndex: 0,
        // 听写参数按班级读取: 班级键 -> default_* 全局默认 -> 固定默认值
        volume: settings['volume_' + classId] ?? settings.default_volume ?? 80,
        interval: settings['interval_' + classId] ?? settings.default_interval ?? 5,
        repeat: settings['repeat_' + classId] ?? settings.default_repeat ?? 1,
        repeatInterval: settings['repeat_interval_' + classId] ?? settings.default_repeat_interval ?? 1,
        running: false,
        paused: false,
        countdownTimer: null,
        repeatTimer: null,
        currentAudio: null,
        repeatCount: 0,
    };

    function stopDictAudio() { if (dictState.currentAudio) { dictState.currentAudio.pause(); dictState.currentAudio = null; } }

    function renderDictPrepare() {
        stopDictAudio();
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-prepare">' +
            '<div class="dict-icon-wrap"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg></div>' +
            '<h3>听写准备</h3>' +
            '<p style="color:#888;margin-bottom:28px;">共 <b style="color:var(--blue);">' + dictState.words.length + '</b> 个单词</p>' +
            '<button class="main-btn" onclick="dictStartPrepare()">开始准备</button>' +
            '<button class="main-btn secondary-btn" onclick="dictSkipPrepare()" style="margin-top:0;">跳过准备</button>' +
            '</div>';
    }

    function dictStartPrepare() { dictStep1(); }
    function dictSkipPrepare() {
        dictState.volume = settings['volume_' + classId] ?? settings.default_volume ?? 80;
        dictState.interval = settings['interval_' + classId] ?? settings.default_interval ?? 5;
        dictState.repeat = settings['repeat_' + classId] ?? settings.default_repeat ?? 1;
        dictState.repeatInterval = settings['repeat_interval_' + classId] ?? settings.default_repeat_interval ?? 1;
        dictStepReady();
    }

    function dictStep1() {
        stopDictAudio();
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-step">' +
            '<div class="step-num">1</div>' +
            '<h3>试音与调整音量</h3>' +
            '<p class="hint">点击播放试音，调节音量对比效果</p>' +
            '<div class="test-sound-row">' +
                '<button id="dictTestBtn" onclick="dictToggleTestSound()" class="dict-test-btn">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>' +
                '</button>' +
                '<input type="range" id="dictVolRange" min="0" max="100" value="' + dictState.volume + '" oninput="dictUpdateTestVolume(this.value)">' +
                '<span id="dictVolVal" class="vol-pct" style="min-width:40px;text-align:center;">' + dictState.volume + '%</span>' +
            '</div>' +
            '<div class="step-btns"><button class="primary" onclick="dictStep2()">下一步</button></div>' +
            '</div>';
    }

    function dictUpdateTestVolume(val) {
        dictState.volume = parseInt(val) || 80;
        document.getElementById('dictVolVal').textContent = dictState.volume + '%';
        if (dictState.currentAudio && !dictState.currentAudio.paused) {
            dictState.currentAudio.volume = dictState.volume / 100;
        }
    }

    function dictToggleTestSound() {
        const btn = document.getElementById('dictTestBtn');
        if (!btn) return;
        if (dictState.currentAudio && !dictState.currentAudio.paused) {
            // Stop current playback
            dictState.currentAudio.pause();
            dictState.currentAudio = null;
            btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
            btn.style.background = 'var(--pencil)';
        } else {
            // Start playback
            stopDictAudio();
            const audio = new Audio('shiyin.mp3');
            dictState.currentAudio = audio;
            audio.volume = dictState.volume / 100;
            btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
            btn.style.background = 'var(--red)';
            audio.play().then(function() {
                audio.onended = function() {
                    dictState.currentAudio = null;
                    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
                    btn.style.background = 'var(--pencil)';
                };
            }).catch(function() {
                dictState.currentAudio = null;
                btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
                btn.style.background = 'var(--pencil)';
                showToast('试音文件未找到', 'error');
            });
        }
    }

    function dictStep2() {
        stopDictAudio();
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-step">' +
            '<div class="step-num">2</div>' +
            '<h3>设置时间间隔</h3>' +
            '<p class="hint">设置每个单词之间的等待时间 (1-20秒)</p>' +
            '<div class="interval-box"><input type="number" id="dictInterval" value="' + dictState.interval + '" min="1" max="20" step="0.5" onchange="dictState.interval=parseFloat(this.value)||5"><span class="interval-unit">秒</span></div>' +
            '<div class="step-btns"><button class="secondary" onclick="dictStep1()">上一步</button><button class="primary" onclick="dictStep3()">下一步</button></div>' +
            '</div>';
    }

    // NEW: Step 3 - 朗读次数
    function dictStep3() {
        stopDictAudio();
        dictState.interval = parseFloat(document.getElementById('dictInterval').value) || 5;
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-step">' +
            '<div class="step-num">3</div>' +
            '<h3>设置朗读次数</h3>' +
            '<p class="hint">每个单词的朗读次数</p>' +
            '<div class="interval-box"><input type="number" id="dictRepeat" value="' + dictState.repeat + '" min="1" max="5" step="1" onchange="dictState.repeat=parseInt(this.value)||1"><span class="interval-unit">次</span></div>' +
            '<div class="step-btns"><button class="secondary" onclick="dictStep2()">上一步</button><button class="primary" onclick="dictState.repeat=parseInt(document.getElementById(\'dictRepeat\').value)||1;if(dictState.repeat>1)dictStep4();else{dictState.repeatInterval=1;dictStepReady();}">下一步</button></div>' +
            '</div>';
    }

    // Step 4 - 朗读间隔
    function dictStep4() {
        stopDictAudio();
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-step">' +
            '<div class="step-num">4</div>' +
            '<h3>设置朗读间隔</h3>' +
            '<p class="hint">同一单词重复朗读之间的等待时间</p>' +
            '<div class="interval-box"><input type="number" id="dictRepeatInt" value="' + dictState.repeatInterval + '" min="0.5" max="5" step="0.5" onchange="dictState.repeatInterval=parseFloat(this.value)||1"><span class="interval-unit">秒</span></div>' +
            '<div class="step-btns"><button class="secondary" onclick="dictStep3()">上一步</button><button class="primary" onclick="dictState.repeatInterval=parseFloat(document.getElementById(\'dictRepeatInt\').value)||1;dictStepReady()">下一步</button></div>' +
            '</div>';
    }

    function dictStepReady() {
        stopDictAudio();
        var repeatInfo = (dictState.repeat > 1) ? '<span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:2px;"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' + dictState.repeat + ' 次</span><span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:2px;"><line x1="12" y1="22" x2="12" y2="2"/><polyline points="15 5 9 9 9 13"/></svg>' + dictState.repeatInterval + ' 秒</span>' : '<span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:2px;"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' + dictState.repeat + ' 次</span>';
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-ready">' +
            '<div class="ready-circle"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
            '<h3>一切就绪</h3>' +
            '<div class="ready-info"><span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:2px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>' + dictState.volume + '%</span><span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' + dictState.interval + ' 秒</span>' + repeatInfo + '</div>' +
            '<button class="main-btn" onclick="dictStart()">开始听写</button>' +
            '</div>';
    }

    function dictStart() {
        stopDictAudio();
        fetch('settings.php?id=' + classId, {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_settings&default_volume=' + dictState.volume + '&default_interval=' + dictState.interval + '&default_repeat=' + dictState.repeat + '&default_repeat_interval=' + dictState.repeatInterval + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        }).catch(() => {});
        dictState.currentIndex = 0;
        dictState.paused = false;
        dictState.running = true;
        renderDictRunning();
        playCurrentWord();
    }

    function renderDictRunning() {
        const c = document.getElementById('dictContainer');
        c.innerHTML = '<div class="dict-running">' +
            '<div class="dict-progress"><div class="big-num" id="dictProgressText">1 / ' + dictState.words.length + '</div><div class="label">正在播放...</div></div>' +
            '<div class="dict-countdown" id="dictCountdown" style="display:none;">还有 <span class="sec" id="dictSec">' + dictState.interval + '</span> 秒播放下一个</div>' +
            '<button class="dict-pause-btn" id="dictPauseBtn" onclick="dictPause()"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停</button>' +
            '</div>';
    }

    // Play current word (start from repeat 0)
    function playCurrentWord() {
        if (!dictState.running || dictState.paused) return;
        if (dictState.currentIndex >= dictState.words.length) {
            dictState.running = false;
            document.getElementById('dictCompleteModal').classList.add('active');
            return;
        }
        clearInterval(dictState.countdownTimer);
        clearTimeout(dictState.repeatTimer);
        dictState.countdownTimer = null;
        dictState.repeatTimer = null;
        dictState.repeatCount = 0;
        const wi = dictState.currentIndex;
        document.getElementById('dictProgressText').textContent = (wi + 1) + ' / ' + dictState.words.length;
        document.getElementById('dictCountdown').style.display = 'none';
        playWordRepeat();
    }

    function playWordRepeat() {
        if (!dictState.running || dictState.paused) return;
        const word = dictState.words[dictState.currentIndex];
        stopDictAudio();
        const audio = new Audio('https://dict.youdao.com/dictvoice?audio=' + encodeURIComponent(word) + '&type=1');
        dictState.currentAudio = audio;
        audio.volume = dictState.volume / 100;
        // 失败守卫：onerror 与 play() 失败可能双双触发，同一失败只推进一次
        let failed = false;
        const failAdvance = function(msg) {
            if (failed) return;
            failed = true;
            dictState.currentAudio = null;
            if (!dictState.running || dictState.paused) return;
            showToast(msg + '：' + word, 'error');
            dictState.repeatCount = dictState.repeat; // 跳过剩余重复
            dictState.currentIndex++;
            if (dictState.currentIndex >= dictState.words.length) {
                dictState.running = false;
                setTimeout(function() { document.getElementById('dictCompleteModal').classList.add('active'); }, dictState.interval * 1000);
            } else {
                startCountdown();
            }
        };
        // 音频加载失败处理 — 提示并跳过当前词继续下一个
        audio.onerror = function() { failAdvance('音频加载失败，已跳过'); };
        audio.play().then(function() {
            // 播放成功，清除 onerror 避免误触发
            audio.onerror = null;
        }).catch(function() { failAdvance('音频播放失败，已跳过'); });
        audio.onended = () => {
            dictState.currentAudio = null;
            if (!dictState.running || dictState.paused) return;
            dictState.repeatCount++;
            if (dictState.repeatCount < dictState.repeat) {
                dictState.repeatTimer = setTimeout(() => playWordRepeat(), dictState.repeatInterval * 1000);
            } else {
                dictState.currentIndex++;
                if (dictState.currentIndex >= dictState.words.length) {
                    dictState.running = false;
                    setTimeout(() => { document.getElementById('dictCompleteModal').classList.add('active'); }, dictState.interval * 1000);
                } else {
                    startCountdown();
                }
            }
        };
    }

    function startCountdown(remaining) {
        if (remaining === undefined) remaining = dictState.interval;
        const cdDiv = document.getElementById('dictCountdown');
        if (cdDiv) cdDiv.style.display = 'block';
        const secSpan = document.getElementById('dictSec');
        if (secSpan) secSpan.textContent = remaining;
        clearInterval(dictState.countdownTimer);
        dictState.countdownTimer = setInterval(() => {
            remaining--;
            if (secSpan) secSpan.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(dictState.countdownTimer);
                dictState.countdownTimer = null;
                if (cdDiv) cdDiv.style.display = 'none';
                playCurrentWord();
            }
        }, 1000);
    }

    function dictPause() {
        dictState.paused = true;
        stopDictAudio();
        if (dictState.countdownTimer) { clearInterval(dictState.countdownTimer); dictState.countdownTimer = null; }
        if (dictState.repeatTimer) { clearTimeout(dictState.repeatTimer); dictState.repeatTimer = null; }
        const btn = document.getElementById('dictPauseBtn');
        if (btn) {
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>继续';
            btn.className = 'dict-pause-btn resume';
            btn.onclick = dictResume;
        }
        showToast('已暂停', '');
    }

    function dictResume() {
        dictState.paused = false;
        const btn = document.getElementById('dictPauseBtn');
        if (btn) {
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停';
            btn.className = 'dict-pause-btn';
            btn.onclick = dictPause;
        }
        playCurrentWord();
    }

    async function dictFinish(markCompleted) {
        document.getElementById('dictCompleteModal').classList.remove('active');
        if (markCompleted) {
            const taskId = document.getElementById('completeTaskId').value;
            const fd = new FormData(); fd.append('action', 'complete_task'); fd.append('task_id', taskId); fd.append('csrf_token', CSRF_TOKEN);
            await fetch('task.php?id=' + classId + '&task_id=' + encodeURIComponent(taskId), { method: 'POST', body: fd });
            showOkOverlayThen('task.php?id=' + classId);
        } else {
            // 用户选择不完成 → 回到默写模式；顶栏 完成/取消 按钮在默写模式下自动重新显示，可后续手动完成
            setMode('hide');
        }
    }

    // ==================== Task Follow-along ====================
    let taskFollowCtrl = null, taskFollowPaused = false, taskFollowCollapsed = false, taskFollowCollapseTimer = null, taskFollowSessionSeq = 0;
    const taskWords = [];
    for (const wid of taskWordIds) { if (wordMapData[wid]) taskWords.push(wordMapData[wid].word); }

    function showTaskFollow() { document.getElementById('taskFollowModal').classList.add('active'); }
    function closeTaskFollowModal() {
        // 只关弹窗，不停止正在进行的跟读
        closeModal('taskFollowModal');
    }

    function expandTaskFollowBubble() {
        taskFollowCollapsed = false;
        clearTimeout(taskFollowCollapseTimer);
        document.getElementById('taskFollowBubble').style.display = 'none';
        document.getElementById('taskFollowPlayer').style.display = 'block';
        taskFollowCollapseTimer = setTimeout(collapseTaskFollowToBubble, 3000);
    }
    function collapseTaskFollowToBubble() {
        if (!taskFollowCtrl || taskFollowPaused) return;
        taskFollowCollapsed = true;
        clearTimeout(taskFollowCollapseTimer);
        document.getElementById('taskFollowPlayer').style.display = 'none';
        document.getElementById('taskFollowBubble').style.display = 'flex';
    }

    function startTaskFollow() {
        // isNaN 判空，允许合法 0 值（缓冲 0 有效）
        const repeatRaw = parseInt(document.getElementById('taskFollowRepeat').value, 10);
        const repeat = isNaN(repeatRaw) || repeatRaw < 1 ? 1 : Math.min(5, repeatRaw);
        const bufferRaw = parseFloat(document.getElementById('taskFollowBuffer').value);
        const buffer = isNaN(bufferRaw) ? 0.5 : Math.max(-0.5, Math.min(5, bufferRaw));
        const shuffle = document.getElementById('taskFollowShuffle').checked;
        closeModal('taskFollowModal');
        if (taskWords.length === 0) { showToast('没有单词', 'error'); return; }
        const playWords = shuffle ? shuffleArray(taskWords.slice()) : taskWords;   // 乱序只作用于播放顺序

        // 高亮定位：小写单词 -> 卡片元素 映射（大小写安全、无选择器注入风险）
        const cardMap = new Map();
        document.querySelectorAll('.word-card[data-word]').forEach(function(c) {
            const k = (c.getAttribute('data-word') || '').toLowerCase();
            if (k && !cardMap.has(k)) cardMap.set(k, c);
        });

        // Save follow settings
        fetch('settings.php?id=' + classId, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=save_settings&follow_repeat=' + repeat + '&follow_buffer=' + buffer + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN) }).catch(function(){});

        taskFollowSessionSeq++;
        const mySeq = taskFollowSessionSeq;
        document.getElementById('taskFollowPlayer').style.display = 'block';
        document.getElementById('taskFollowBubble').style.display = 'none';
        taskFollowCollapsed = false;
        clearTimeout(taskFollowCollapseTimer);
        document.getElementById('taskFollowProgress').textContent = '0/' + playWords.length;
        document.getElementById('taskFollowWord').textContent = '准备中...';
        taskFollowPaused = false;
        document.getElementById('taskFollowPauseBtn').innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:3px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停';

        taskFollowCtrl = startFollowAlong(playWords, { repeat: repeat, buffer: buffer, volume: <?php echo $settings['volume_' . $classId] ?? $settings['default_volume'] ?? 80; ?> }, function(info) {
            if (info.done) {
                clearTimeout(taskFollowCollapseTimer);
                taskFollowCtrl = null;
                taskFollowCollapsed = false;
                document.querySelectorAll('.word-card.follow-highlight').forEach(function(c) { c.classList.remove('follow-highlight'); });
                if (info.stopped) {
                    document.getElementById('taskFollowPlayer').style.display = 'none';
                    document.getElementById('taskFollowBubble').style.display = 'none';
                } else {
                    // 显示完成态 N/N 片刻后再收尾
                    document.getElementById('taskFollowProgress').textContent = playWords.length + '/' + playWords.length;
                    document.getElementById('taskFollowWord').textContent = '完成';
                    showToast('跟读完成', 'success');
                    setTimeout(function() {
                        if (mySeq === taskFollowSessionSeq) {
                            document.getElementById('taskFollowPlayer').style.display = 'none';
                            document.getElementById('taskFollowBubble').style.display = 'none';
                        }
                    }, 800);
                }
            } else {
                // Always update display text
                document.getElementById('taskFollowWord').textContent = info.word;
                document.getElementById('taskFollowProgress').textContent = info.index + '/' + info.total;
                // Highlight and scroll to current word card
                document.querySelectorAll('.word-card.follow-highlight').forEach(function(c) { c.classList.remove('follow-highlight'); });
                var card = cardMap.get(info.word.toLowerCase());
                if (card) {
                    card.classList.add('follow-highlight');
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                // Only show the bar if not already collapsed by user scroll
                if (!taskFollowCollapsed) {
                    document.getElementById('taskFollowPlayer').style.display = 'block';
                    document.getElementById('taskFollowBubble').style.display = 'none';
                    clearTimeout(taskFollowCollapseTimer);
                    taskFollowCollapseTimer = setTimeout(collapseTaskFollowToBubble, 3000);
                }
            }
        });
        // Set initial collapse timer
        taskFollowCollapseTimer = setTimeout(collapseTaskFollowToBubble, 3000);
    }

    function toggleTaskFollowPause() {
        if (!taskFollowCtrl) return;
        const btn = document.getElementById('taskFollowPauseBtn');
        if (taskFollowPaused) {
            taskFollowCtrl.resume(); taskFollowPaused = false;
            taskFollowCollapsed = false;
            clearTimeout(taskFollowCollapseTimer);
            document.getElementById('taskFollowPlayer').style.display = 'block';
            document.getElementById('taskFollowBubble').style.display = 'none';
            taskFollowCollapseTimer = setTimeout(collapseTaskFollowToBubble, 3000);
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:3px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停';
            btn.style.background = '#ff9800';
        } else {
            taskFollowCtrl.pause(); taskFollowPaused = true;
            clearTimeout(taskFollowCollapseTimer);
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:3px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>继续';
            btn.style.background = 'var(--blue)';
        }
    }

    function stopTaskFollow() {
        if (taskFollowCtrl) { taskFollowCtrl.stop(); taskFollowCtrl = null; }
        clearTimeout(taskFollowCollapseTimer);
        taskFollowCollapsed = false;
        document.getElementById('taskFollowPlayer').style.display = 'none';
        document.getElementById('taskFollowBubble').style.display = 'none';
        taskFollowPaused = false;
    }
</script>
<?php else: ?>
<script>
    const classId = '<?php echo $classId; ?>';
    function startTask(taskId) { showOkOverlayThen('task.php?id=' + classId + '&task_id=' + taskId); }
</script>
<?php endif; ?>
</body>
</html>
