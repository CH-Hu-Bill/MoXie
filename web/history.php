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
$classId = $_GET['id'] ?? '';
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];

// Check password protection
requireClassAuth($classId, $class);
$words = Database::getWords($classId);
$tasks = Database::getTasks($classId);
$settings = Database::getSettings();
$highlightId = $_GET['highlight'] ?? '';
$searchQ = trim($_GET['search'] ?? '');

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
$selectedTaskId = $_GET['task_id'] ?? null;
$selectedTask = null;
if ($selectedTaskId && isset($tasks[$selectedTaskId])) { $selectedTask = $tasks[$selectedTaskId]; }
$wordMap = [];
foreach ($words as $w) { $wordMap[$w['id']] = $w; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>默写记录 - <?php echo htmlspecialchars($class['name']); ?></title>
    <link rel="stylesheet" href="common.css">
    <style>
        body { height: 100vh; overflow: hidden; }
        .content { display: flex; overflow: hidden; max-width: none !important; padding: 0 !important; }
        .sidebar { width: 260px; background: #fafbfc; border-right: 1px solid #e8e8e8; overflow-y: auto; flex-shrink: 0; box-shadow: 2px 0 12px rgba(0,0,0,0.04); }
        .sidebar-header { padding: 14px 18px; border-bottom: 1px solid #e8e8e8; font-size: 13px; color: #888; font-weight: 600; letter-spacing: 0.5px; background: #fff; }
        .task-item { padding: 14px 18px; border-bottom: 1px solid #eef0f2; cursor: pointer; transition: all 0.15s; position: relative; }
        .task-item:hover { background: #f0f4f8; }
        .task-item.active { background: #eaf2fd; border-left: 3px solid #4a90d9; margin-left: 0; }
        .task-item .date { font-size: 14px; color: #2a2a2a; margin-bottom: 5px; font-weight: 500; }
        .task-item .date .label-tag { display: inline-block; font-size: 10px; color: #4a90d9; background: #e8f0fb; padding: 1px 8px; border-radius: 10px; margin-left: 6px; vertical-align: middle; font-weight: 500; }
        .task-item.cancelled .date { color: #b0b0b0; text-decoration: line-through; }
        .task-item .status { font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .task-item .status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .task-item .status.completed { color: #43a047; }
        .task-item .status.completed::before { background: #43a047; }
        .task-item .status.cancelled { color: #999; }
        .task-item .status.cancelled::before { background: #ccc; }
        .main-panel { flex: 1; overflow-y: auto; padding: 18px; }
        .task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #eee; }
        .task-header .date { font-size: 20px; font-weight: bold; color: #333; }
        .task-header .status { padding: 4px 12px; border-radius: 16px; font-size: 13px; }
        .task-header .status.completed { background: #e8f5e9; color: #43a047; }
        .task-header .status.cancelled { background: #f5f5f5; color: #999; }
        .word-card.cancelled { background: #f5f5f5; }
        .cancel-badge { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-15deg); background: rgba(0,0,0,0.4); color: #fff; padding: 8px 22px; font-size: 20px; border-radius: 6px; z-index: 10; pointer-events: none; }
        .recreate-btn { display: block; width: 100%; padding: 12px; background: #4a90d9; color: #fff; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; margin-top: 18px; transition: background 0.15s; }
        .recreate-btn:hover { background: #3a7bc8; }
        @media (max-width: 600px) {
            .content { flex-direction: column; }
            .sidebar { width: 100%; max-height: 140px; border-right: none; border-bottom: 1px solid #ddd; display: flex; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; flex-shrink: 0; box-shadow: none; }
            .sidebar-header { display: none; }
            .task-item { flex-shrink: 0; white-space: nowrap; padding: 10px 14px; border-bottom: none; border-right: 1px solid #f0f0f0; }
            .task-item:last-child { border-right: none; }
            .main-panel { flex: 1; overflow-y: auto; }
        }
    </style>
</head>
<body>
    <script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
    <div class="status-bar">
        <div class="left">
            <button class="back-btn" onclick="showOkOverlayThen('main.php?id=<?php echo $classId; ?>')"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
            <span style="font-size:15px;font-weight:bold;color:#333;"><?php echo htmlspecialchars($class['name']); ?></span>
        </div>
        <div class="title">默写记录</div>
        <div class="right"></div>
    </div>
    <div class="content">
        <?php if ($searchQ !== ''): ?>
        <div style="padding:10px 16px;background:#e8f0fb;color:#4a90d9;font-size:14px;border-radius:8px;margin-bottom:8px;">搜索 "<?php echo htmlspecialchars($searchQ); ?>" 的历史记录 <a href="history.php?id=<?php echo $classId; ?>" style="color:#e53935;text-decoration:none;margin-left:8px;">×清除</a></div>
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
                    <div class="date"><?php echo $selectedTask['date']; ?><?php if (!empty($selectedTask['label'])): ?> <span style="font-size:13px;color:#4a90d9;background:#e8f0fb;padding:2px 10px;border-radius:12px;"><?php echo htmlspecialchars($selectedTask['label']); ?></span><?php endif; ?></div>
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
                                <button class="speaker" onclick='speak(<?php echo json_encode($w['word']); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg></button>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($selectedTask['status'] === 'completed' || $selectedTask['status'] === 'cancelled'): ?>
                    <button class="recreate-btn" onclick="recreateTask()">用这些单词重新创建任务</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="common.js"></script>
    <script>var speakRepeat = <?php echo $settings['repeat_' . $classId] ?? $settings['default_repeat'] ?? 1; ?>;</script>
    <script>
        const classId = '<?php echo $classId; ?>';
        const selectedTaskWordIds = <?php echo $selectedTask ? json_encode($selectedTask['word_ids']) : '[]'; ?>;

        window.addEventListener('load', function() {
            initMarquee();
            const highlightId = <?php echo json_encode($highlightId); ?>;
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
