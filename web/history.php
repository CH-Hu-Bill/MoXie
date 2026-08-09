<?php
/**
 * ============================================================
 * 历史记录页面
 * ============================================================
 *
 * 左侧: 历史任务列表 (按日期倒序，已完成/已取消)
 * 右侧: 选中任务的单词详情 + 重新创建按钮
 *
 * 重新创建任务: 将任务中的单词ID存入 localStorage → 跳转到 words.php
 * words.php 会自动勾选这些单词，用户可直接创建新任务
 *
 * URL 参数:
 *   ?id={classId}       — 班级ID (必填)
 *   ?task_id={taskId}   — 选中查看的任务 (可选)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
$csrfToken = csrfToken(); // CSRF令牌，供前端使用
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

$completedTasks = array_filter($tasks, function($t) use ($searchQ, $words) {
    if ($t['status'] !== 'completed' && $t['status'] !== 'cancelled') return false;
    if ($searchQ === '') return true;
    static $wmap = null; if ($wmap === null) { $wmap = []; foreach ($words as $w) $wmap[(string)$w['id']] = $w; }
    foreach (($t['word_ids'] ?? []) as $wid) {
        if (isset($wmap[$wid]) && (mb_stripos($wmap[$wid]['word'], $searchQ) !== false || mb_stripos($wmap[$wid]['meaning'], $searchQ) !== false)) return true;
    }
    return false;
});
usort($completedTasks, function($a, $b) { return $b['date'] <=> $a['date']; });
$selectedTaskId = reqGet('task_id');
$selectedTask = null;
if ($selectedTaskId && isset($tasks[$selectedTaskId])) { $selectedTask = $tasks[$selectedTaskId]; }
$wordMap = [];
foreach ($words as $w) { $wordMap[$w['id']] = $w; }
?>
<?php $pageTitle = '默写记录'; require 'inc/head.php'; ?>
<body>
    <script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
    <?php
    $backUrl = 'main.php?id=' . $classId;
    $className = $class['name'];
    $pageTitle = '默写记录';
    require 'inc/header.php';
    ?>
    <div class="content content-split">
        <?php if ($searchQ !== ''): ?>
        <div class="card-post-it mb-2" style="font-size:14px;">搜索 "<?php echo htmlspecialchars($searchQ); ?>" 的历史记录 <a href="history.php?id=<?php echo $classId; ?>" style="color:var(--red);text-decoration:none;margin-left:8px;">×清除</a></div>
        <?php endif; ?>
        <div class="sidebar">
            <div class="sidebar-header">历史记录 (<?php echo count($completedTasks); ?>条)</div>
            <?php if (empty($completedTasks)): ?>
                <div class="empty-state" style="padding:40px;"><div>暂无记录</div></div>
            <?php else: ?>
                <?php foreach ($completedTasks as $task): ?>
                    <div class="task-item <?php echo $task['status']; ?> <?php echo $selectedTaskId === $task['id'] ? 'active' : ''; ?>" onclick="showOkOverlayThen('history.php?id=<?php echo $classId; ?>&task_id=<?php echo $task['id']; ?>')">
                        <div class="date"><?php echo $task['date']; ?><?php if (!empty($task['label'])): ?> <span class="label-tag"><?php echo htmlspecialchars($task['label']); ?></span><?php endif; ?></div>
                        <div class="status <?php echo $task['status']; ?>"><?php echo $task['status'] === 'completed' ? '已完成' : '已取消'; ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="main-panel" id="mainPanel">
            <?php if (!$selectedTask): ?>
                <div class="empty-state"><div class="icon"><svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div><div>选择一个记录查看详情</div></div>
            <?php else: ?>
                <div class="task-header">
                    <div class="date"><?php echo $selectedTask['date']; ?><?php if (!empty($selectedTask['label'])): ?> <span class="tag"><?php echo htmlspecialchars($selectedTask['label']); ?></span><?php endif; ?></div>
                    <div class="status <?php echo $selectedTask['status']; ?>"><?php echo $selectedTask['status'] === 'completed' ? '已完成' : '已取消'; ?></div>
                </div>
                <div class="word-grid">
                    <?php foreach ($selectedTask['word_ids'] as $wid): ?>
                        <?php if (isset($wordMap[$wid])): $w = $wordMap[$wid]; ?>
                            <div class="word-card <?php echo $selectedTask['status']; ?>" data-id="<?php echo htmlspecialchars($w['id']); ?>">
                                <?php if ($selectedTask['status'] === 'cancelled'): ?>
                                    <div class="cancel-badge">已取消</div>
                                <?php endif; ?>
                                <div class="card-body">
                                    <div class="word" lang="en"><span><?php echo htmlspecialchars($w['word']); ?></span></div>
                                    <div class="meaning"><span><?php echo htmlspecialchars($w['meaning']); ?></span></div>
                                    <?php if ($w['pos']): ?>
                                        <div class="pos"><?php echo htmlspecialchars($w['pos']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <button class="speaker" onclick='speak(<?php echo htmlspecialchars(json_encode($w['word'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg></button>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($selectedTask['status'] === 'completed' || $selectedTask['status'] === 'cancelled'): ?>
                    <button class="btn btn-primary mt-4" style="width:100%;" onclick="recreateTask()">用这些单词重新创建任务</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="common.js?v=7"></script>
    <script>var speakRepeat = <?php echo $settings['repeat_' . $classId] ?? $settings['default_repeat'] ?? 1; ?>;</script>
    <script>
        const classId = '<?php echo $classId; ?>';
        const selectedTaskWordIds = <?php echo $selectedTask ? json_encode($selectedTask['word_ids'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '[]'; ?>;

        window.addEventListener('load', function() {
            initMarquee();
            const highlightId = <?php echo json_encode($highlightId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const card = document.querySelector('.word-card[data-id="' + CSS.escape(highlightId) + '"]');
            if (highlightId && card) { card.classList.add('follow-highlight'); card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });

        function recreateTask() {
            if (selectedTaskWordIds.length === 0) return;
            localStorage.setItem('recreate_word_ids', JSON.stringify(selectedTaskWordIds));
            showOkOverlayThen('words.php?id=' + classId);
        }
    </script>
</body>
</html>