<?php
/**
 * ============================================================
 * 功能主界面
 * ============================================================
 *
 * 显示班级名称、功能卡片网格（单词库/默写任务/默写记录/周末大礼包/设置）、
 * 统计数据（单词库总数/已默写单词数）、随机英文名言。
 *
 * 周末大礼包逻辑:
 *   每周一自动随机决定本周末是否需要加练 (存储到 settings.json)。
 *   周末根据随机结果和本周完成单词数显示四种状态。
 *
 * URL 参数:
 *   ?id={classId} — 班级ID (必填)
 *   ?ok=1         — 显示 OK 过渡蒙版 (从任务页返回时)
 *
 * 数据依赖:
 *   data/classes.json, data/classes/{classId}/words.json, data/classes/{classId}/tasks.json, data/settings.json
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

setSecureCookie('current_class_id', $classId, time() + 86400 * 365);
$words = Database::getWords($classId);
$tasks = Database::getTasks($classId);
$totalWords = count($words);
$completedTasks = array_filter($tasks, function($t) { return $t['status'] === 'completed'; });
$uniqueWordsCompleted = [];
foreach ($completedTasks as $task) {
    if (isset($task['word_ids'])) { $uniqueWordsCompleted = array_merge($uniqueWordsCompleted, $task['word_ids']); }
}
$uniqueWordCount = count(array_unique($uniqueWordsCompleted));
$quotes = [
    "The only way to do great work is to love what you do. - Steve Jobs",
    "Believe you can and you're halfway there. - Theodore Roosevelt",
    "Success is not final, failure is not fatal: it is the courage to continue that counts. - Winston Churchill",
    "The future belongs to those who believe in the beauty of their dreams. - Eleanor Roosevelt",
    "Don't watch the clock; do what it does. Keep going. - Sam Levenson",
    "You are never too old to set another goal or to dream a new dream. - C.S. Lewis",
    "The secret of getting ahead is getting started. - Mark Twain",
    "It does not matter how slowly you go as long as you do not stop. - Confucius",
    "Everything you've ever wanted is on the other side of fear. - George Addair",
    "Success usually comes to those who are too busy to be looking for it. - Henry David Thoreau"
];
$randomQuote = $quotes[array_rand($quotes)];

// Weekend task availability
$todayDow = (int)date('N'); // 1=Mon ... 7=Sun
$isWeekend = ($todayDow >= 6);
$currentWeek = date('o-W'); // ISO week

// Calculate available words from this week's completed tasks
$mondayThisWeek = date('Y-m-d', strtotime('monday this week'));
$sundayThisWeek = date('Y-m-d', strtotime('sunday this week'));
$weekWordIds = [];
foreach ($tasks as $t) {
    if (($t['status'] ?? '') === 'completed' && ($t['date'] ?? '') >= $mondayThisWeek && ($t['date'] ?? '') <= $sundayThisWeek) {
        if (isset($t['word_ids'])) $weekWordIds = array_merge($weekWordIds, $t['word_ids']);
    }
}
$weekUniqueWords = array_values(array_unique($weekWordIds));
$weekWordCount = count($weekUniqueWords);

// Determine weekend card state
$weekendState = 'weekdays'; // default: Mon-Fri
$weekendClickable = false;
$weekendCompleted = false;
$weekendTaskId = null;

// Find current weekend task and its status
foreach ($tasks as $tid => $task) {
    if (($task['weekend_week'] ?? '') === $currentWeek) {
        $weekendTaskId = $tid;
        if (($task['status'] ?? '') === 'completed') {
            $weekendCompleted = true;
        }
        break;
    }
}

if ($isWeekend) {
    if ($weekendCompleted) {
        $weekendState = 'completed';
        $weekendClickable = true;
    } elseif ($weekWordCount > 0) {
        $weekendState = 'active';
        $weekendClickable = true;
    } else {
        $weekendState = 'lazy';
    }
}

// Handle weekend task creation
if (isset($_POST['action']) && $_POST['action'] === 'create_weekend_task') {
    requireCsrf(); // CSRF校验
    header('Content-Type: application/json');
    if (!$weekendClickable) { echo json_encode(['success' => false, 'error' => '不可用']); exit; }
    $pick = $weekUniqueWords;
    shuffle($pick);
    $pick = array_slice(array_values(array_unique($pick)), 0, 20);
    $weekendTaskId = null;
    $updatedTasks = Database::updateClassData($classId, 'tasks', function($latestTasks) use ($currentWeek, $pick, &$weekendTaskId) {
        foreach ($latestTasks as $tid => $task) {
            if (($task['weekend_week'] ?? '') === $currentWeek) {
                $weekendTaskId = $tid;
                return null;
            }
        }
        $weekendTaskId = uniqid();
        $latestTasks[$weekendTaskId] = [
            'id' => $weekendTaskId,
            'date' => date('Y-m-d', strtotime('saturday this week')),
            'label' => '周末大礼包',
            'weekend_week' => $currentWeek,
            'word_ids' => $pick,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $latestTasks;
    });
    if ($updatedTasks === false || !$weekendTaskId) { echo json_encode(['success' => false, 'error' => '创建失败']); exit; }
    Database::update('settings.json', function($latestSettings) use ($classId, $currentWeek, $weekendTaskId) {
        $latestSettings['last_task_id_' . $classId] = $weekendTaskId;
        $latestSettings['weekend_task_created_' . $classId] = $currentWeek;
        return $latestSettings;
    });
    echo json_encode(['success' => true, 'task_id' => $weekendTaskId]); exit;
}

// Handle search
if (isset($_POST['action']) && $_POST['action'] === 'search_all') {
    requireCsrf(); // CSRF校验
    header('Content-Type: application/json');
    $query = trim(reqPost('query'));
    if (!$query) { echo json_encode(['success' => false, 'error' => '请输入搜索词']); exit; }
    $queryLower = mb_strtolower($query);
    $wordsOffset = max(0, (int)($_POST['words_offset'] ?? 0));

    // 1) 词库匹配 (最多10条)
    $wordResults = [];
    foreach ($words as $w) {
        if (strpos(mb_strtolower($w['word']), $queryLower) !== false ||
            strpos(mb_strtolower($w['meaning']), $queryLower) !== false) {
            $wordResults[] = ['id' => $w['id'], 'word' => $w['word'], 'meaning' => $w['meaning'], 'pos' => $w['pos'] ?? ''];
        }
    }

    // 2) 进行中任务匹配 (最多10条)
    $pendingResults = [];
    $wordMap = [];
    foreach ($words as $w) { $wordMap[$w['id']] = $w; }
    foreach ($tasks as $tid => $t) {
        if (($t['status'] ?? '') !== 'pending') continue;
        $matched = [];
        foreach (($t['word_ids'] ?? []) as $wid) {
            if (isset($wordMap[$wid])) {
                $mw = $wordMap[$wid];
                if (strpos(mb_strtolower($mw['word']), $queryLower) !== false ||
                    strpos(mb_strtolower($mw['meaning']), $queryLower) !== false) {
                    $matched[] = ['id' => $mw['id'], 'word' => $mw['word'], 'meaning' => $mw['meaning'], 'pos' => $mw['pos'] ?? ''];
                }
            }
        }
        if (!empty($matched)) {
            $pendingResults[] = ['id' => $tid, 'date' => $t['date'], 'label' => $t['label'] ?? '', 'matched_words' => $matched];
        }
    }

    // 3) 历史任务匹配 (最多10条)
    $historyResults = [];
    $historyTasks = array_filter($tasks, function($t) { return in_array($t['status'] ?? '', ['completed', 'cancelled']); });
    uasort($historyTasks, function($a, $b) { return $b['date'] <=> $a['date']; });
    foreach ($historyTasks as $tid => $t) {
        $matched = [];
        foreach (($t['word_ids'] ?? []) as $wid) {
            if (isset($wordMap[$wid])) {
                $mw = $wordMap[$wid];
                if (strpos(mb_strtolower($mw['word']), $queryLower) !== false ||
                    strpos(mb_strtolower($mw['meaning']), $queryLower) !== false) {
                    $matched[] = ['id' => $mw['id'], 'word' => $mw['word'], 'meaning' => $mw['meaning'], 'pos' => $mw['pos'] ?? ''];
                }
            }
        }
        if (!empty($matched)) {
            $historyResults[] = ['id' => $tid, 'date' => $t['date'], 'label' => $t['label'] ?? '', 'status' => $t['status'], 'matched_words' => $matched];
        }
    }

    echo json_encode(['success' => true, 'data' => [
        // words 支持 words_offset 分页：搜索下拉"查看更多单词"逐批就地加载（不再跳转单词库）
        'words' => ['items' => array_slice($wordResults, $wordsOffset, 10), 'total' => count($wordResults), 'has_more' => ($wordsOffset + 10) < count($wordResults)],
        'pending_tasks' => ['items' => array_slice($pendingResults, 0, 10), 'total' => count($pendingResults), 'has_more' => count($pendingResults) > 10],
        'history_tasks' => ['items' => array_slice($historyResults, 0, 10), 'total' => count($historyResults), 'has_more' => count($historyResults) > 10],
    ]]); exit;
}

// Handle quote translation
if (isset($_POST['action']) && $_POST['action'] === 'translate_quote') {
    requireCsrf(); // CSRF校验
    header('Content-Type: application/json');
    $quote = trim(reqPost('quote'));
    if (!$quote) { echo json_encode(['success' => false, 'error' => '无内容']); exit; }
    require_once 'inc/api.php';
    $result = DeepSeekAPI::call([
        ['role' => 'system', 'content' => '将英文名言及作者名翻译成中文，保持原文风格。只返回中文译文（含作者名翻译），不要任何解释。'],
        ['role' => 'user', 'content' => $quote],
    ], 256, 15);
    if (!$result['success']) { echo json_encode($result); exit; }
    echo json_encode(['success' => true, 'translation' => trim($result['content'])]);
    exit;
}
$pageTitle = '功能主页';
require 'inc/head.php';
?>
<body>
<script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
<?php
$backUrl = 'index.php?switch=1';
$className = $class['name'];
$rightContent = '<button class="btn btn-sm" onclick="showOkOverlayThen(\'index.php?switch=1\')">切换班级</button>';
require 'inc/header.php';
?>
<div class="content">
    <div style="text-align:center;margin-bottom:32px;">
        <h2 style="font-family:var(--font-heading);font-size:28px;color:var(--pencil);margin-bottom:8px;">欢迎来到<?php echo htmlspecialchars($class['name']); ?></h2>
        <p style="color:#888;font-size:15px;">选择下方功能开始学习</p>
    </div>

    <div style="max-width:600px;margin:0 auto 28px;position:relative;z-index:2001;">
        <div style="display:flex;gap:8px;position:relative;z-index:2001;">
            <input type="text" id="searchInput" class="input" placeholder="搜索单词或释义..." onkeydown="if(event.key==='Enter')doSearch()" onfocus="onSearchFocus()" style="flex:1;">
            <button class="btn btn-primary" onmousedown="searchSuppressDismiss=true" onclick="doSearch()">搜索</button>
        </div>
        <div class="search-results" id="searchResults"></div>
    </div>

    <div class="menu-grid">
        <div class="card menu-card" onclick="showOkOverlayThen('words.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>
            <div class="title">单词库</div>
            <div class="desc">管理班级单词</div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('task.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
            <div class="title">默写任务</div>
            <div class="desc">进行单词默写</div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('history.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="title">默写记录</div>
            <div class="desc">查看历史记录</div>
        </div>
        <div class="card menu-card <?php echo $weekendCompleted ? 'weekend-completed' : ($weekendClickable ? 'weekend-active' : 'disabled'); ?>" id="weekendCard" onclick="handleWeekendClick()">
            <div class="icon">
                <?php if ($weekendCompleted): ?>
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php else: ?>
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--old-paper)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>
                <?php endif; ?>
            </div>
            <div class="title">周末大礼包</div>
            <div class="desc" id="weekendDesc">
                <?php
                if ($weekendCompleted) { echo '已完成'; }
                else switch($weekendState) {
                    case 'weekdays': echo '还没开放'; break;
                    case 'active': echo '给我加练'; break;
                    case 'lazy': echo '真懒'; break;
                }
                ?>
            </div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('settings.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg></div>
            <div class="title">设置</div>
            <div class="desc">听写参数配置</div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('history_book.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="14" y2="11"/><path d="M12 14v3"/></svg></div>
            <div class="title">班级史记</div>
            <div class="desc">记录班级日常</div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('gallery.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
            <div class="title">班级图集</div>
            <div class="desc">图片记录与画廊</div>
        </div>
        <div class="card menu-card" onclick="showOkOverlayThen('display.php?id=<?php echo $classId; ?>')">
            <div class="icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></div>
            <div class="title">展示大屏</div>
            <div class="desc">壁纸投屏模式</div>
        </div>
    </div>

    <div class="card" style="padding:28px;">
        <div class="stats">
            <div style="text-align:center;">
                <div class="stat-value"><?php echo $totalWords; ?></div>
                <div class="stat-label">单词库总数</div>
            </div>
            <div style="text-align:center;">
                <div class="stat-value"><?php echo $uniqueWordCount; ?></div>
                <div class="stat-label">已默写单词数</div>
            </div>
        </div>
        <div style="height:2px;background:var(--pencil);opacity:0.15;margin:20px 0;"></div>
        <div style="overflow:hidden;">
            <div class="quote" id="quoteText" style="text-align:center;font-style:italic;color:#888;font-size:15px;line-height:1.7;overflow-wrap:break-word;word-break:break-word;">"<?php echo htmlspecialchars($randomQuote); ?>" <button class="btn btn-sm" id="translateBtn" onclick="translateQuote()" style="vertical-align:middle;margin-left:8px;white-space:nowrap;">AI 翻译</button></div>
            <div class="quote-translation" id="quoteTranslation" style="display:none;margin-top:10px;font-style:italic;color:var(--pencil);font-size:14px;text-align:center;overflow-wrap:break-word;"></div>
        </div>
    </div>
</div>

    <script src="common.js?v=9"></script>
    <script>
        const weekendClickable = <?php echo $weekendClickable ? 'true' : 'false'; ?>;
        const weekendCompleted = <?php echo $weekendCompleted ? 'true' : 'false'; ?>;
        const weekendTaskId = <?php echo $weekendTaskId ? json_encode($weekendTaskId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
        const classId = '<?php echo $classId; ?>';

        async function handleWeekendClick() {
            if (!weekendClickable) {
                const state = '<?php echo $weekendState; ?>';
                if (state === 'weekdays') showToast('周末才开放，周一到周五请耐心等待', '');
                return;
            }
            // 已完成：直接跳转查看
            if (weekendCompleted && weekendTaskId) {
                showOkOverlayThen('task.php?id=' + classId + '&task_id=' + weekendTaskId);
                return;
            }
            const card = document.getElementById('weekendCard');
            card.style.pointerEvents = 'none';
            card.style.opacity = '0.7';
            const fd = new FormData();
            fd.append('action', 'create_weekend_task');
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('main.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    showOkOverlayThen('task.php?id=' + classId + '&task_id=' + d.task_id);
                }
            } catch(e) { showToast('网络异常，请重试', 'error'); }
            card.style.pointerEvents = '';
            card.style.opacity = '';
        }

        // ========== 搜索聚焦 ==========
        var searchSuppressDismiss = false;
        function onSearchFocus() {
            document.getElementById('searchFocusOverlay').classList.add('active');
            var q = document.getElementById('searchInput').value.trim();
            if (q) { doSearch(); }
        }
        function dismissSearch() {
            document.getElementById('searchFocusOverlay').classList.remove('active');
            document.getElementById('searchResults').classList.remove('active');
        }
        // Search input blur - dismiss on next tick (allow click-through on results)
        // 但点击"搜索"按钮会触发 blur → 禁止 dismiss，避免搜索闪一下就被收起
        document.getElementById('searchInput').addEventListener('blur', function() {
            setTimeout(function() {
                if (searchSuppressDismiss) { searchSuppressDismiss = false; return; }
                dismissSearch();
            }, 200);
        });

        // ========== 全局搜索 ==========
        // 单词组就地分页状态：点击"查看更多单词"逐批追加后 10 条（每条可点击定位），
        // 不再整页跳转单词库（此前 doSearch 的查看更多 → words.php 是用户抱怨的问题）
        var srQuery = '';
        var srWordsOffset = 0;
        var srWordsTotal = 0;

        function srWordItemHtml(w) {
            return '<div class="sr-item" onclick="showOkOverlayThen(\'words.php?id='+classId+'&highlight='+encodeURIComponent(w.id)+'\')"><span><span class="word">'+escHtml2(w.word)+'</span><span class="meaning">'+escHtml2(w.meaning)+'</span></span><span class="arrow">›</span></div>';
        }

        function updateSrWordsMore() {
            const remaining = srWordsTotal - srWordsOffset;
            const more = document.getElementById('srWordsMore');
            if (remaining <= 0) { if (more) more.remove(); return; }
            const group = document.getElementById('srWordsList');
            if (!group) return;
            if (more) {
                more.textContent = '查看更多单词（还有 ' + remaining + ' 条）→';
            } else {
                const m = document.createElement('div');
                m.className = 'sr-more';
                m.id = 'srWordsMore';
                m.textContent = '查看更多单词（还有 ' + remaining + ' 条）→';
                m.onclick = loadMoreWords;
                group.parentNode.appendChild(m);
            }
        }

        async function loadMoreWords() {
            if (!srQuery) return;
            const more = document.getElementById('srWordsMore');
            if (more) { more.style.pointerEvents = 'none'; more.textContent = '加载中…'; }
            const fd = new FormData();
            fd.append('action', 'search_all');
            fd.append('query', srQuery);
            fd.append('words_offset', String(srWordsOffset));
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('main.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    const words = d.data.words;
                    srWordsOffset += (words.items || []).length;
                    srWordsTotal = words.total;
                    const list = document.getElementById('srWordsList');
                    (words.items || []).forEach(w => { if (list) list.insertAdjacentHTML('beforeend', srWordItemHtml(w)); });
                    updateSrWordsMore();
                }
            } catch (e) { /* 静默失败，按钮恢复可点击 */ }
            if (more) { more.style.pointerEvents = ''; updateSrWordsMore(); }
        }

        async function doSearch() {
            const q = document.getElementById('searchInput').value.trim();
            const res = document.getElementById('searchResults');
            const overlay = document.getElementById('searchFocusOverlay');
            if (!q) { res.classList.remove('active'); overlay.classList.remove('active'); return; }
            res.innerHTML = '<div class="sr-empty"><span class="spin"></span> 搜索中...</div>';
            res.classList.add('active');
            overlay.classList.add('active');
            const fd = new FormData();
            fd.append('action', 'search_all');
            fd.append('query', q);
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('main.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (!d.success) { res.innerHTML = '<div class="sr-empty">搜索失败</div>'; res.classList.add('active'); return; }
                const data = d.data;
                srQuery = q; srWordsOffset = data.words.items.length; srWordsTotal = data.words.total;
                let html = '';
                html += '<div class="sr-group"><div class="sr-group-title">📖 单词库 (' + data.words.total + '条)</div>';
                if (data.words.total === 0) html += '<div class="sr-empty" style="padding:8px;">无匹配结果</div>';
                else {
                    html += '<div id="srWordsList">' + data.words.items.map(srWordItemHtml).join('') + '</div>';
                    const wRemaining = data.words.total - data.words.items.length;
                    if (wRemaining > 0) html += '<div class="sr-more" id="srWordsMore" onclick="loadMoreWords()">查看更多单词（还有 ' + wRemaining + ' 条）→</div>';
                }
                html += '</div>';
                html += '<div class="sr-group"><div class="sr-group-title">📝 进行中任务 (' + data.pending_tasks.total + '条)</div>';
                if (data.pending_tasks.total === 0) html += '<div class="sr-empty" style="padding:8px;">无匹配结果</div>';
                else {
                    data.pending_tasks.items.forEach(t => {
                        html += '<div class="sr-item" onclick="showOkOverlayThen(\'task.php?id='+classId+'&task_id='+encodeURIComponent(t.id)+'&highlight='+encodeURIComponent(t.matched_words[0].id)+'\')"><span><span class="word">'+escHtml2(t.date)+' '+escHtml2(t.label||'')+'</span><span class="meaning">'+t.matched_words.length+'个词匹配</span></span><span class="arrow">›</span></div>';
                    });
                    if (data.pending_tasks.has_more) html += '<div class="sr-more" onclick="showOkOverlayThen(\'task.php?id='+classId+'&search='+encodeURIComponent(q)+'\')">查看更多任务 →</div>';
                }
                html += '</div>';
                html += '<div class="sr-group"><div class="sr-group-title">📋 默写历史 (' + data.history_tasks.total + '条)</div>';
                if (data.history_tasks.total === 0) html += '<div class="sr-empty" style="padding:8px;">无匹配结果</div>';
                else {
                    data.history_tasks.items.forEach(t => {
                        html += '<div class="sr-item" onclick="showOkOverlayThen(\'history.php?id='+classId+'&task_id='+encodeURIComponent(t.id)+'&highlight='+encodeURIComponent(t.matched_words[0].id)+'\')"><span><span class="word">'+escHtml2(t.date)+' '+escHtml2(t.label||'')+'</span><span class="meaning">'+t.matched_words.length+'个词匹配</span></span><span class="arrow">›</span></div>';
                    });
                    if (data.history_tasks.has_more) html += '<div class="sr-more" onclick="showOkOverlayThen(\'history.php?id='+classId+'&search='+encodeURIComponent(q)+'\')">查看更多历史 →</div>';
                }
                html += '</div>';
                if (data.words.total === 0 && data.pending_tasks.total === 0 && data.history_tasks.total === 0) {
                    html = '<div class="sr-empty">未找到匹配"'+escHtml2(q)+'"的结果</div>';
                }
                res.innerHTML = html;
            } catch(e) { res.innerHTML = '<div class="sr-empty">搜索异常，请重试</div>'; }
        }
        function escHtml2(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        // ========== 名言翻译 ==========
        async function translateQuote() {
            const btn = document.getElementById('translateBtn');
            const transDiv = document.getElementById('quoteTranslation');
            if (transDiv.style.display !== 'none') { transDiv.style.display = 'none'; btn.textContent = 'AI 翻译'; return; }
            btn.disabled = true; btn.textContent = '翻译中...';
            const quote = document.getElementById('quoteText').childNodes[0].textContent.replace(/^"|"$/g, '');
            const fd = new FormData();
            fd.append('action', 'translate_quote');
            fd.append('quote', quote);
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('main.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    transDiv.textContent = d.translation;
                    transDiv.style.display = 'block';
                    btn.textContent = '隐藏翻译';
                } else {
                    btn.textContent = '翻译失败';
                }
            } catch(e) { btn.textContent = '翻译失败'; }
            btn.disabled = false;
        }

        // ========== 首次使用引导 ==========
        (function() {
            if (localStorage.getItem('guide_done')) return;
            const steps = [
                { title: '欢迎使用 ListenWrite', desc: '这是一个班级单词学习工具，帮助您高效管理单词、进行默写练习。' },
                { title: '📖 单词库', desc: '管理班级所有单词。可以添加、编辑、批量导入单词，AI 智能补全释义。' },
                { title: '✏️ 默写任务', desc: '选择单词创建默写任务，支持听写模式。完成任务后可在历史记录中查看。' },
                { title: '📋 默写记录', desc: '查看所有已完成和已取消的任务。可以重新创建任务或查看单词详情。' },
                { title: '🔍 搜索功能', desc: '在上方搜索框输入单词或释义，可以快速查找词库、任务和历史中的内容。' },
                { title: '开始学习吧！', desc: '点击任意功能卡片即可开始。随时可在设置页面重新查看本说明。' }
            ];
            let stepIdx = 0;
            const overlay = document.createElement('div');
            overlay.id = 'guideOverlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px)';
            const box = document.createElement('div');
            box.style.cssText = 'background:#fff;border-radius:18px;padding:32px 28px;width:90%;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,.2);text-align:center;position:relative';
            const skip = document.createElement('button');
            skip.textContent = '跳过';
            skip.style.cssText = 'position:absolute;top:12px;right:16px;background:none;border:none;color:#999;font-size:13px;cursor:pointer';
            skip.onclick = function() { overlay.remove(); try { localStorage.setItem('guide_done','1'); } catch(e) {} };
            box.appendChild(skip);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            function render() {
                const s = steps[stepIdx];
                box.innerHTML = '';
                box.appendChild(skip);
                box.insertAdjacentHTML('beforeend', '<div style="font-size:40px;margin-bottom:14px">' + (stepIdx===0?'📚':stepIdx===1?'📖':stepIdx===2?'✏️':stepIdx===3?'📋':stepIdx===4?'🔍':'🎉') + '</div>');
                box.insertAdjacentHTML('beforeend', '<h2 style="font-size:22px;color:#333;margin-bottom:10px">' + s.title + '</h2>');
                box.insertAdjacentHTML('beforeend', '<p style="color:#666;font-size:14px;line-height:1.7;margin-bottom:24px">' + s.desc + '</p>');
                box.insertAdjacentHTML('beforeend', '<div style="display:flex;gap:8px;justify-content:center;margin-bottom:10px">' + steps.map(function(_,i){return '<span style="width:8px;height:8px;border-radius:50%;background:'+(i===stepIdx?'var(--blue)':'#ddd')+'"></span>';}).join('') + '</div>');
                const btnRow = document.createElement('div');
                btnRow.style.cssText = 'display:flex;gap:10px';
                if (stepIdx > 0) {
                    const prev = document.createElement('button');
                    prev.textContent = '上一步';
                    prev.style.cssText = 'flex:1;padding:12px;background:#f0f2f5;color:#666;border:none;border-radius:10px;font-size:15px;cursor:pointer;font-weight:600';
                    prev.onclick = function() { stepIdx--; render(); };
                    btnRow.appendChild(prev);
                }
                const next = document.createElement('button');
                next.textContent = stepIdx < steps.length - 1 ? '下一步' : '开始使用';
                next.style.cssText = 'flex:1;padding:12px;background:var(--pencil);color:#fff;border:none;border-radius:var(--wobbly-sm);font-size:15px;cursor:pointer;font-weight:600;box-shadow:var(--shadow-md)';
                next.onclick = function() {
                    if (stepIdx < steps.length - 1) { stepIdx++; render(); }
                    else { overlay.remove(); localStorage.setItem('guide_done','1'); }
                };
                btnRow.appendChild(next);
                box.appendChild(btnRow);
            }
            render();
        })();
    </script>
    <div class="search-focus-overlay" id="searchFocusOverlay" onclick="dismissSearch()"></div>
</body>
</html>
