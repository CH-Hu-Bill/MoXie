<?php
/**
 * ============================================================
 * 单词库页面
 * ============================================================
 *
 * 功能:
 *   - 单词卡片网格展示 (含编号、发音、编辑、已完成标记)
 *   - 选中单词 → 创建默写任务 (最多20个，可选择日期和标签)
 *   - 单个添加/编辑/删除单词
 *   - AI 批量导入 (粘贴单词列表 → DeepSeek 自动补全释义词性 → 预览确认)
 *   - CSV 导入/导出
 *
 * POST action 列表:
 *   add_word, update_word, delete_word         — 单词 CRUD
 *   create_task, check_task_date               — 任务创建
 *   batch_ai_preview, ai_single_word, batch_import — AI 批量流程
 *   import_csv, export_csv                     — CSV 操作
 *
 * 注意:
 *   - 所有操作后通过 location.href 刷新页面 (add/edit/delete/import)
 *   - AI 批量导入完成后不刷新页面，原地更新单词列表
 *   - 选中状态通过 localStorage 在刷新间保留
 *   - 单词卡片主题点击可切换选中 (不影响发音/编辑按钮)
 *
 * URL 参数:
 *   ?id={classId} — 班级ID (必填)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/api.php';
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
$searchQ = trim(reqGet('search'));
$lastTaskId = $settings['last_task_id_' . $classId] ?? null;
$lastWordIndex = -1;
$lastWordId = null;
if ($lastTaskId && isset($tasks[$lastTaskId])) {
    $lastTask = $tasks[$lastTaskId];
    if (!empty($lastTask['word_ids'])) {
        $lastWordId = end($lastTask['word_ids']);
        foreach ($words as $idx => $w) {
            if ($w['id'] === $lastWordId) { $lastWordIndex = $idx; break; }
        }
    }
}
$pendingTasks = array_filter($tasks, function($t) { return $t['status'] === 'pending'; });
$completedTasks = array_filter($tasks, function($t) { return $t['status'] === 'completed'; });
$wordPendingInfo = [];
foreach ($pendingTasks as $task) { foreach ($task['word_ids'] ?? [] as $wid) { $wordPendingInfo[$wid] = $task['date']; } }
$wordCompletedInfo = [];
foreach ($completedTasks as $task) { foreach ($task['word_ids'] ?? [] as $wid) { $wordCompletedInfo[$wid] = true; } }
if (isset($_POST['action'])) {
    // 全球发音 — 拉取某单词的发音列表（班级口令会话内，只读）
    if ($_POST['action'] === 'pron_list') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        $pronWordId = $_POST['word_id'] ?? '';
        if (!is_string($pronWordId) || !preg_match('/\A[a-f0-9]{8,32}\z/D', $pronWordId)) {
            echo json_encode(['success' => false, 'error' => '参数无效']); exit;
        }
        $pron = Database::getClassData($classId, 'pronunciations');
        $pronItems = [];
        $pronWordData = (is_array($pron) && isset($pron[$pronWordId]) && is_array($pron[$pronWordId])) ? $pron[$pronWordId] : [];
        foreach ($pronWordData as $pronUid => $p) {
            if (!is_array($p) || empty($p['file'])) continue;
            if (!is_file(Database::getClassDir($classId) . '/pronunciations/' . $pronWordId . '/' . $p['file'])) continue;
            $pronItems[] = [
                'name' => (string)($p['name'] ?? '同学'),
                'duration' => (float)($p['duration'] ?? 0),
                'uploaded_at' => (string)($p['uploaded_at'] ?? ''),
                'url' => 'pronunciation.php?class_id=' . rawurlencode($classId) . '&word_id=' . rawurlencode($pronWordId) . '&file=' . rawurlencode($p['file']),
            ];
        }
        echo json_encode(['success' => true, 'items' => $pronItems], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_POST['action'] === 'add_word') {
        requireCsrf(); // CSRF校验
        $word = sanitizePlainText(reqPost('word')); $meaning = sanitizePlainText(reqPost('meaning')); $pos = sanitizePlainText(reqPost('pos'));
        if (!$word || !$meaning) {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => '单词和释义不能为空']); exit;
        }
        if (mb_strlen($word) > 100 || mb_strlen($meaning) > 500 || mb_strlen($pos) > 50) {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => '输入内容过长']); exit;
        }
        $exists = false;
        foreach ($words as $w) { if (strtolower($w['word']) === strtolower($word)) { $exists = true; break; } }
        $response = ['success' => false, 'exists' => $exists, 'word' => $word];
        if (!$exists) {
            $newId = uniqid(); $words[] = ['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'created_at' => date('Y-m-d')];
            Database::saveWords($classId, $words);
            $response['success'] = true;
            $response['new_id'] = $newId;
        }
        header('Content-Type: application/json'); echo json_encode($response); exit;
    }
    if ($_POST['action'] === 'update_word') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $wordId = reqPost('word_id'); $word = sanitizePlainText(reqPost('word')); $meaning = sanitizePlainText(reqPost('meaning')); $pos = sanitizePlainText(reqPost('pos'));
        if (!$word || !$meaning) {
            echo json_encode(['success' => false, 'error' => '单词和释义不能为空']); exit;
        }
        if (mb_strlen($word) > 100 || mb_strlen($meaning) > 500 || mb_strlen($pos) > 50) {
            echo json_encode(['success' => false, 'error' => '输入内容过长']); exit;
        }
        $found = false;
        foreach ($words as $idx => $w) {
            if ($w['id'] === $wordId) {
                $found = true;
                $exists = false;
                foreach ($words as $w2) { if ($w2['id'] !== $wordId && strtolower($w2['word']) === strtolower($word)) { $exists = true; break; } }
                if (!$exists) { $words[$idx] = ['id' => $wordId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'created_at' => $w['created_at']]; Database::saveWords($classId, $words); echo json_encode(['success' => true]); } else { echo json_encode(['success' => false, 'exists' => true]); }
                break;
            }
        }
        if (!$found) { echo json_encode(['success' => false, 'error' => '单词不存在']); }
        exit;
    }
    if ($_POST['action'] === 'delete_word') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $wordId = reqPost('word_id');
        $found = false;
        foreach ($words as $idx => $w) { if ($w['id'] === $wordId) { $found = true; array_splice($words, $idx, 1); Database::saveWords($classId, $words); echo json_encode(['success' => true]); break; } }
        if (!$found) { echo json_encode(['success' => false, 'error' => '单词不存在']); }
        exit;
    }
    if ($_POST['action'] === 'create_task') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $selectedIdsRaw = reqPost('selected_ids', '[]'); $selectedIds = json_decode($selectedIdsRaw, true) ?? []; $taskDate = reqPost('task_date', date('Y-m-d'));
        $taskLabel = trim(reqPost('task_label'));
        $overwrite = reqPost('overwrite') === '1';
        if (!empty($selectedIds) && count($selectedIds) <= 20) {
            // Auto-generate label if empty
            if ($taskLabel === '') {
                $sameDayCount = 0;
                foreach ($tasks as $t) { if ($t['date'] === $taskDate) $sameDayCount++; }
                $taskLabel = '任务' . ($sameDayCount + 1);
            }
            // Check duplicate label on same date
            foreach ($tasks as $t) {
                if ($t['date'] === $taskDate && ($t['label'] ?? '') === $taskLabel) {
                    echo json_encode(['success' => false, 'error' => '该日期已有相同标签的任务，请换个名字']); exit;
                }
            }
            $newTaskId = uniqid();
            $tasks[$newTaskId] = ['id' => $newTaskId, 'date' => $taskDate, 'label' => $taskLabel, 'word_ids' => $selectedIds, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')];
            Database::saveTasks($classId, $tasks);
            $settings['last_task_id_' . $classId] = $newTaskId;
            Database::saveSettings($settings);
            echo json_encode(['success' => true, 'task_id' => $newTaskId]); exit;
        } else { echo json_encode(['success' => false, 'error' => '最多选择20个单词']); }
        exit;
    }
    if ($_POST['action'] === 'export_csv') {
        requireCsrf(); // CSRF校验
        header('Content-Type: text/csv; charset=UTF-8');
        $safeName = str_replace(['"', "\r", "\n", '\\', '/'], '', $class['name']);
        header('Content-Disposition: attachment; filename="' . $safeName . '_单词导出.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        $csvSafe = function($v) { $v = trim((string)$v); if ($v !== '' && preg_match('/^[=+\-@]/', $v)) $v = "'" . $v; return $v; };
        foreach ($words as $w) {
            fputcsv($output, [$csvSafe($w['word']), $csvSafe($w['meaning']), $csvSafe($w['pos'] ?? '')]);
        }
        fclose($output);
        exit;
    }
    if ($_POST['action'] === 'batch_ai_preview') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $rawText = trim(reqPost('words_text'));
        if ($rawText === '') { echo json_encode(['success' => false, 'error' => '请输入单词']); exit; }
        $rawLines = preg_split('/[\r\n]+/', $rawText);
        $inputWords = [];
        foreach ($rawLines as $line) {
            $w = trim($line);
            if ($w !== '') $inputWords[] = $w;
        }
        if (empty($inputWords)) { echo json_encode(['success' => false, 'error' => '未识别到有效单词']); exit; }
        if (count($inputWords) > 100) { echo json_encode(['success' => false, 'error' => '单次最多输入100个单词']); exit; }

        $wordListStr = implode("\n", $inputWords);
        $prompt = <<<PROMPT
你是一个英语词典助手。请为以下每个英文单词/短语提供中文释义和词性。

要求：
1. 释义简洁准确，不要过长
2. 词性使用标准英文缩写：n. / vt. / vi. / adj. / adv. / prep. / conj. / v. 等。多词性用斜杠分隔，如 v./n.
3. 对于无法确认或可能拼写错误的单词，标记 uncertain 为 true，但仍给出最可能的释义

请严格按照以下JSON格式返回（只返回JSON，不要其他文字）：
```json
[
  {"word": "原单词", "meaning": "中文释义", "pos": "词性", "uncertain": false},
  ...
]
```

单词列表：
$wordListStr
PROMPT;

        $apiResult = DeepSeekAPI::call([
            ['role' => 'system', 'content' => '你是一个专业的英语词典助手，请严格按照JSON格式返回结果。'],
            ['role' => 'user', 'content' => $prompt],
        ]);
        if (!$apiResult['success']) {
            echo json_encode($apiResult); exit;
        }
        $parsed = json_decode($apiResult['content'], true);
        if (!is_array($parsed)) {
            echo json_encode(['success' => false, 'error' => 'AI返回格式解析失败，请重试', 'raw' => substr($apiResult['content'], 0, 500)]); exit;
        }

        $preview = [];
        foreach ($parsed as $item) {
            $w = trim($item['word'] ?? '');
            $existingIdx = -1;
            foreach ($words as $idx => $ew) {
                if (strtolower($ew['word']) === strtolower($w)) { $existingIdx = $idx; break; }
            }
            $preview[] = [
                'word' => $w,
                'meaning' => trim($item['meaning'] ?? ''),
                'pos' => trim($item['pos'] ?? ''),
                'uncertain' => !empty($item['uncertain']),
                'exists' => $existingIdx >= 0,
                'existing_index' => $existingIdx >= 0 ? $existingIdx + 1 : 0,
            ];
        }
        echo json_encode(['success' => true, 'preview' => $preview]); exit;
    }
    if ($_POST['action'] === 'ai_single_word') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $word = trim(reqPost('word'));
        if ($word === '') { echo json_encode(['success' => false, 'error' => '请输入单词']); exit; }

        $prompt = <<<PROMPT
你是一个英语词典助手。请为以下英文单词提供中文释义和词性。

要求：
1. 释义简洁准确
2. 词性使用标准英文缩写：n. / vt. / vi. / adj. / adv. 等
3. 如果无法确认或可能拼写错误，标记 uncertain 为 true

请严格返回JSON：
{"word": "$word", "meaning": "中文释义", "pos": "词性", "uncertain": false}
PROMPT;

        $apiResult = DeepSeekAPI::call([
            ['role' => 'system', 'content' => '你是一个专业的英语词典助手，请严格按JSON格式返回。'],
            ['role' => 'user', 'content' => $prompt],
        ], 512, 30);
        if (!$apiResult['success']) {
            echo json_encode($apiResult); exit;
        }
        $parsed = json_decode($apiResult['content'], true);
        if (!is_array($parsed)) { echo json_encode(['success' => false, 'error' => 'AI返回格式解析失败']); exit; }

        $existingIdx = -1;
        foreach ($words as $idx => $ew) {
            if (strtolower($ew['word']) === strtolower(trim($parsed['word'] ?? ''))) { $existingIdx = $idx; break; }
        }
        echo json_encode([
            'success' => true,
            'word' => trim($parsed['word'] ?? $word),
            'meaning' => trim($parsed['meaning'] ?? ''),
            'pos' => trim($parsed['pos'] ?? ''),
            'uncertain' => !empty($parsed['uncertain']),
            'exists' => $existingIdx >= 0,
        ]); exit;
    }
    if ($_POST['action'] === 'batch_import') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        $importData = json_decode(reqPost('import_data', '[]'), true);
        if (empty($importData) || !is_array($importData)) { echo json_encode(['success' => false, 'error' => '无效的导入数据']); exit; }
        $imported = 0; $skipped = 0; $newWords = [];
        foreach ($importData as $item) {
            $w = sanitizePlainText($item['word'] ?? '');
            $m = sanitizePlainText($item['meaning'] ?? '');
            $p = sanitizePlainText($item['pos'] ?? '');
            if (!$w || !$m) continue;
            $exists = false;
            foreach ($words as $ew) { if (strtolower($ew['word']) === strtolower($w)) { $exists = true; break; } }
            if ($exists) { $skipped++; continue; }
            $newWord = ['id' => uniqid(), 'word' => $w, 'meaning' => $m, 'pos' => $p, 'created_at' => date('Y-m-d')];
            $words[] = $newWord;
            $newWords[] = $newWord;
            $imported++;
        }
        Database::saveWords($classId, $words);
        echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'new_words' => $newWords]); exit;
    }
    if ($_POST['action'] === 'import_csv') {
        requireCsrf(); // CSRF校验
        header('Content-Type: application/json');
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'error' => '文件上传失败']); exit; }
        $file = $_FILES['csv_file']['tmp_name']; $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($lines)) { $lines[0] = ltrim($lines[0], "\xEF\xBB\xBF"); }
        $imported = 0; $skipped = 0; $skippedList = [];
        foreach ($lines as $line) {
            $parts = str_getcsv($line); if (count($parts) < 2) continue;
            $word = sanitizePlainText($parts[0]); $meaning = sanitizePlainText($parts[1]); $pos = isset($parts[2]) ? sanitizePlainText($parts[2]) : '';
            if (!$word || !$meaning) continue;
            $existsIdx = -1;
            foreach ($words as $idx => $w) { if (strtolower($w['word']) === strtolower($word)) { $existsIdx = $idx + 1; break; } }
            if ($existsIdx > 0) { $skipped++; $skippedList[] = ['word' => $word, 'index' => $existsIdx]; }
            else { $newId = uniqid(); $words[] = ['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'created_at' => date('Y-m-d')]; $imported++; }
        }
        Database::saveWords($classId, $words); echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'skipped_list' => $skippedList]); exit;
    }
    exit;
}
?>
<?php $pageTitle = '单词库'; require 'inc/head.php'; ?>
<style>
    body { height: 100vh; overflow: hidden; }
    .word-card { cursor: pointer; }
    .word-card.selected { border-color: var(--blue); background: #e8f0fb; box-shadow: 0 0 0 2px rgba(45,93,161,0.25); }
    .word-card.highlight { animation: hl 1.5s ease-out; }
    @keyframes hl { 0%,20%,40% { background: var(--post-it); } 100% { background: var(--white); } }
    /* 持久定位高亮：进入页面自动定位到最后任务单词，保持到用户操作 */
    .word-card.locate-highlight { border-color: var(--red); background: var(--post-it); box-shadow: 0 0 0 3px rgba(255,77,77,0.4); }
    /* 滚动性能说明：曾用 content-visibility:auto 跳过视口外渲染，但它会让视口外卡片按
       占位高度(236px)布局，getBoundingClientRect 产生累计误差（越靠后越滑过头），且定位时
       需强制全量真实布局 → 4-5s 卡顿、永久失去优化、部分机器定位失效。已移除，
       改用常规布局，getBoundingClientRect 始终精确。
       滚动渲染仍保留 contain: layout paint（隔离布局/绘制，浏览器可跳过视口外卡片的
       绘制合成），既防滚动卡顿又【不影响】高度测量/定位。 */
    .word-card { contain: layout paint; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .word-card .corner-tl { position: absolute; top: 8px; left: 8px; display: flex; align-items: center; gap: 4px; z-index: 2; }
    .word-card .number { background: var(--old-paper); color: #777; font-size: 11px; padding: 2px 8px; border: 1.5px solid var(--pencil); border-radius: var(--wobbly-sm); font-family: var(--font-heading); }
    .word-card .pending-mark { width: 9px; height: 9px; background: #ff9800; border-radius: 50%; border: 1px solid var(--pencil); }
    .word-card .corner-tr { position: absolute; top: 8px; right: 8px; z-index: 2; }
    .word-card .checkbox { width: 22px; height: 22px; border: 2px solid var(--pencil); border-radius: var(--wobbly-sm); display: flex; align-items: center; justify-content: center; font-size: 13px; background: var(--white); transition: all 0.15s; position: relative; }
    .word-card .checkbox::after { content: ''; position: absolute; top: -11px; left: -11px; width: 44px; height: 44px; }
    .word-card.selected .checkbox { background: var(--blue); border-color: var(--blue); color: var(--white); }
    .word-card .corner-bl { position: absolute; bottom: 8px; left: 8px; z-index: 2; }
    .word-card .corner-br { position: absolute; bottom: 8px; right: 8px; display: flex; align-items: center; gap: 4px; z-index: 2; }
    .word-card .completed-mark { width: 20px; height: 20px; background: var(--blue); border-radius: 50%; border: 1.5px solid var(--pencil); display: flex; align-items: center; justify-content: center; color: var(--white); font-size: 11px; flex-shrink: 0; }
    .word-card .completed-mark svg { display: block; }
    .word-card .edit-btn { width: 24px; height: 24px; background: rgba(0,0,0,0.05); border: 1.5px solid var(--pencil); border-radius: var(--wobbly-sm); cursor: pointer; font-size: 11px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.15s; }
    .word-card:hover .edit-btn { opacity: 0.7; }
    @media (hover: none) { .word-card .edit-btn { opacity: 0.5; } }
    .toolbar { display: flex; gap: 10px; align-items: center; flex-shrink: 0; width: 100%; padding: 8px 16px; border-bottom: 2px solid var(--pencil); }
    .selection-info { margin-left: auto; font-family: var(--font-heading); font-size: 13px; font-weight: 700; color: var(--blue); }
    .fab { position: fixed; bottom: 24px; right: 24px; width: 52px; height: 52px; border: 2px solid var(--pencil); border-radius: var(--wobbly); font-size: 26px; cursor: pointer; z-index: 500; display: flex; align-items: center; justify-content: center; background: var(--pencil); color: var(--white); box-shadow: var(--shadow-md); transition: transform 0.1s, box-shadow 0.1s; }
    .fab:active { transform: translate(3px, 3px); box-shadow: none; }
    .csv-hint { background: var(--paper); border: 2px solid var(--pencil); border-radius: var(--wobbly-sm); padding: 12px; margin-bottom: 12px; font-size: 12px; color: var(--pencil); line-height: 1.6; }
    .csv-hint code { background: var(--old-paper); padding: 1px 6px; border-radius: var(--wobbly-sm); font-size: 12px; color: var(--pencil); font-family: var(--font-mono); }
    .file-input-wrapper { position: relative; overflow: hidden; display: inline-block; width: 100%; }
    .file-input-wrapper input[type="file"] { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
    .file-input-label { display: block; padding: 10px; background: var(--white); border: 2px dashed var(--pencil); border-radius: var(--wobbly-sm); text-align: center; color: var(--pencil); cursor: pointer; font-size: 13px; font-family: var(--font-heading); }
    .preview-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1.5px solid var(--old-paper); font-size: 13px; }
    .preview-row.duplicate { background: var(--old-paper); color: #bbb; text-decoration: line-through; }
    .preview-row.duplicate input, .preview-row.duplicate .prev-word { pointer-events: none; color: #bbb !important; }
    .preview-row.uncertain .prev-word { color: var(--red); font-weight: bold; }
    .prev-col { flex-shrink: 0; }
    .prev-word { width: 100px; font-weight: bold; color: var(--pencil); word-break: break-all; }
    .prev-meaning { flex: 1; min-width: 80px; }
    .prev-pos { width: 80px; }
    .prev-actions { width: 50px; text-align: right; }
    .mini-btn { width: 24px; height: 24px; border: 1.5px solid var(--pencil); border-radius: var(--wobbly-sm); cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; margin-left: 2px; }
    .mini-btn.warn { background: var(--post-it); color: var(--pencil); }
    .mini-btn.del { background: #ffebee; color: var(--red); border-color: var(--red); }
    .mini-btn.ai-fill { background: #e8f0fb; color: var(--blue); font-size: 13px; width: auto; padding: 0 6px; }
    .mini-btn.ai-fill:hover { background: #d0e3f7; }
    .prev-col.prev-word { position: relative; display: flex; align-items: center; gap: 4px; width: 120px; }
    .prev-col.prev-word input { width: 80px; flex-shrink: 1; }
    @media (max-width: 500px) {
        .toolbar { flex-wrap: wrap; gap: 6px; padding: 8px 10px; }
        .selection-info { margin-left: 0; width: 100%; text-align: right; }
    }
/* ===== 全球发音弹窗 ===== */
.pron-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:5000;align-items:center;justify-content:center}
.pron-modal.active{display:flex}
.pron-box{background:var(--white);border-radius:var(--wobbly);padding:18px;width:92%;max-width:400px;max-height:75vh;display:flex;flex-direction:column;box-shadow:var(--shadow-lg);border:2px solid var(--pencil)}
.pron-box h4{text-align:center;margin-bottom:12px;color:var(--pencil);font-family:var(--font-heading);font-size:16px;word-break:break-all}
.pron-list{overflow-y:auto;min-height:80px;flex:1}
.pron-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border:2px solid var(--pencil);border-radius:var(--wobbly-sm);margin-bottom:8px;cursor:pointer;box-shadow:2px 2px 0 var(--pencil);transition:transform .1s,box-shadow .1s;background:var(--white)}
.pron-item:hover{transform:translate(-1px,-1px);box-shadow:3px 3px 0 var(--pencil)}
.pron-item.playing{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:2px 2px 0 var(--blue)}
.pron-item.playing .pron-info i{color:rgba(255,255,255,.8)}
.pron-avatar{flex:0 0 34px;width:34px;height:34px;border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:15px;border:2px solid var(--pencil)}
.pron-item:nth-child(even) .pron-avatar{background:var(--red)}
.pron-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pron-info b{font-size:14px;color:inherit;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pron-info i{font-style:normal;font-size:11px;color:#999}
.pron-play{flex:0 0 22px;color:var(--blue)}
.pron-item.playing .pron-play{color:#fff}
.pron-empty{padding:26px 10px;text-align:center;color:#999;font-size:14px;line-height:1.8}
.pron-close{margin-top:10px;align-self:center;padding:8px 28px;border:2px solid var(--pencil);border-radius:var(--wobbly-sm);background:var(--white);cursor:pointer;font-family:var(--font-heading);box-shadow:2px 2px 0 var(--pencil)}
.pron-close:hover{border-color:var(--blue);color:var(--blue)}
</style>
</head>
<body>
    <script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
    <?php
    $backUrl = 'main.php?id=' . $classId;
    $className = $class['name'];
    $pageTitle = '单词库';
    $rightContent = '<button class="btn btn-sm btn-primary" onclick="showFollowModal()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>跟读</button>';
    require 'inc/header.php';
    ?>
    <div class="toolbar">
        <button class="btn btn-sm btn-secondary" onclick="showImportModal()">导入CSV</button>
        <button class="btn btn-sm btn-secondary" onclick="exportCsv()">导出CSV</button>
        <button class="btn btn-sm btn-primary" onclick="createTask()">创建默写任务</button>
        <span class="selection-info" id="selectionInfo">已选 <span id="selectedCount">0</span> 个</span>
    </div>
    <div class="content" id="contentWrap">
        <?php if (empty($words)): ?>
            <div class="empty-state"><div class="icon"><svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div><div>单词库为空，点击下方按钮添加单词</div></div>
        <?php else: ?>
            <div class="word-grid" id="wordGrid">
                <?php foreach ($words as $idx => $w): ?>
                    <div class="word-card" data-id="<?php echo $w['id']; ?>" data-index="<?php echo $idx; ?>" data-word-db="<?php echo htmlspecialchars($w['word']); ?>" data-meaning-db="<?php echo htmlspecialchars($w['meaning']); ?>" data-pos-db="<?php echo htmlspecialchars($w['pos'] ?? ''); ?>">
                        <div class="corner-tl">
                            <span class="number"><?php echo $idx + 1; ?></span>
                            <?php if (isset($wordPendingInfo[$w['id']])): ?>
                                <span class="pending-mark" title="即将于<?php echo $wordPendingInfo[$w['id']]; ?>默写"></span>
                            <?php endif; ?>
                        </div>
                        <div class="corner-tr">
                            <span class="checkbox" onclick="toggleSelect(event, '<?php echo $w['id']; ?>')"></span>
                        </div>
                        <div class="card-body">
                            <div class="word" lang="en"><span><?php echo htmlspecialchars($w['word']); ?></span></div>
                            <div class="meaning"><span><?php echo htmlspecialchars($w['meaning']); ?></span></div>
                            <?php if ($w['pos']): ?>
                                <div class="pos"><?php echo htmlspecialchars($w['pos']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="corner-bl">
                            <button class="speaker" onclick='event.stopPropagation();speak(<?php echo htmlspecialchars(json_encode($w['word'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg></button>
                            <button class="pron-btn speaker" title="全球发音" onclick='event.stopPropagation();showPronList(<?php echo htmlspecialchars(json_encode($w['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($w['word'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg></button>
                        </div>
                        <div class="corner-br">
                            <?php if (isset($wordCompletedInfo[$w['id']])): ?>
                                <span class="completed-mark" title="已默写"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                            <?php endif; ?>
                            <button class="edit-btn" onclick="event.stopPropagation();showEditModal('<?php echo $w['id']; ?>')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <button class="fab" onclick="showBatchModal()" title="批量添加单词"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
    <div class="toast" id="toast"></div>

    <!-- 全球发音弹窗 -->
    <div class="pron-modal" id="pronModal">
        <div class="pron-box">
            <h4 id="pronTitle">全球发音</h4>
            <div class="pron-list" id="pronList"></div>
            <button type="button" class="pron-close" onclick="closePronModal()">关闭</button>
        </div>
    </div>

    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-title">添加单词</div>
            <form id="addForm">
                <div class="form-group"><label>单词</label><input type="text" name="word" required autofocus></div>
                <div class="form-group"><label>释义</label><input type="text" name="meaning" required></div>
                <div class="form-group"><label>词性（选填）</label><input type="text" name="pos" placeholder="如 n. v. adj."></div>
                <div class="modal-btns"><button type="button" class="cancel" onclick="closeModal('addModal')">取消</button><button type="submit" class="submit">添加</button></div>
            </form>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-title">编辑单词</div>
            <form id="editForm">
                <input type="hidden" name="word_id" id="editWordId">
                <div class="form-group"><label>单词</label><input type="text" name="word" id="editWord" required></div>
                <div class="form-group"><label>释义</label><input type="text" name="meaning" id="editMeaning" required></div>
                <div class="form-group"><label>词性</label><input type="text" name="pos" id="editPos" placeholder="如 n. v. adj."></div>
                <div class="modal-btns"><button type="button" class="delete" onclick="deleteWord()">删除</button><button type="button" class="cancel" onclick="closeModal('editModal')">取消</button><button type="submit" class="submit">保存</button></div>
            </form>
        </div>
    </div>

    <div class="modal" id="importModal">
        <div class="modal-content">
            <div class="modal-title">导入CSV</div>
            <div class="csv-hint">
                请上传 <code>.csv</code> 格式文件，每行一条单词，格式为：<br>
                <code>单词,释义,词性</code><br>
                示例：<code>apple,苹果,n.</code><br>
                词性可省略，如：<code>apple,苹果</code>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="form-group">
                    <div class="file-input-wrapper">
                        <div class="file-input-label" id="fileLabel">点击选择CSV文件</div>
                        <input type="file" name="csv_file" accept=".csv" onchange="document.getElementById('fileLabel').textContent = this.files[0]?.name || '点击选择CSV文件'">
                    </div>
                </div>
                <div class="modal-btns"><button type="button" class="cancel" onclick="closeModal('importModal')">取消</button><button type="button" class="submit" onclick="importCsv()">导入</button></div>
            </form>
        </div>
    </div>

    <div class="modal" id="taskDateModal">
        <div class="modal-content">
            <div class="modal-title">创建默写任务</div>
            <div class="form-group">
                <label>日期</label>
                <input type="date" id="taskDate" value="<?php echo date('Y-m-d'); ?>" class="input">
            </div>
            <div class="form-group">
                <label>任务标签（可选，如"第1次""上午"等）</label>
                <input type="text" id="taskLabel" placeholder="留空自动生成编号" class="input">
            </div>
            <div class="modal-btns"><button type="button" class="cancel" onclick="closeModal('taskDateModal')">取消</button><button type="button" class="submit" onclick="confirmCreateTask()">确认创建</button></div>
        </div>
    </div>

    <!-- Batch AI Import Modal -->
    <div class="modal" id="batchModal">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-title">批量添加单词</div>
            <div class="form-group">
                <label>每行一个单词，可粘贴大量单词</label>
                <textarea id="batchTextarea" rows="10" placeholder="apple&#10;book&#10;computer&#10;..." class="textarea"></textarea>
            </div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="closeModal('batchModal')">取消</button>
                <button type="button" class="submit" id="batchSubmitBtn" onclick="submitBatchPreview()">AI 智能补全</button>
            </div>
            <div style="text-align:center;margin-top:8px;">
                <a href="javascript:void(0)" onclick="closeModal('batchModal');showAddModal();" style="color:#999;font-size:13px;">逐个添加单词</a>
            </div>
        </div>
    </div>

    <!-- Batch Preview Modal -->
    <div class="modal" id="previewModal" style="z-index:1100;">
        <div class="modal-content" style="max-width:600px;max-height:85vh;">
            <div class="modal-title">预览与确认 (<span id="previewCount">0</span> 个单词)</div>
            <div id="previewList" style="max-height:50vh;overflow-y:auto;margin-bottom:12px;"></div>
            <div style="color:#999;font-size:12px;margin-bottom:8px;">
                <span style="color:var(--red);">■</span> 不确定的单词 &nbsp;
                <span style="color:#bbb;text-decoration:line-through;">灰色删除线</span> 已存在 &nbsp;
                <span>✨</span> 修改英文后点击可AI补全
            </div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="closeModal('previewModal')">取消</button>
                <button type="button" class="submit" onclick="confirmBatchImport()">确认导入</button>
            </div>
        </div>
    </div>


    <!-- Follow-along Modal -->
    <div class="modal" id="followModal">
        <div class="modal-content" style="max-width:420px;">
            <div class="modal-title">跟读设置</div>
            <div class="form-group">
                <label>单词范围</label>
                <select id="followScope" class="input">
                    <option value="all">全部单词</option>
                    <option value="selected" id="followSelectedOpt">已选单词</option>
                </select>
            </div>
            <div class="form-group">
                <label>每个单词朗读次数</label>
                <input type="number" id="followRepeat" value="<?php echo $settings['follow_repeat_' . $classId] ?? $settings['follow_repeat'] ?? 1; ?>" min="1" max="5" step="1" class="input">
            </div>
            <div class="form-group">
                <label>缓冲时间（-0.5 到 5 秒）：停顿 = 音频时长 + 此值（负数提前，最短 0 秒）</label>
                <input type="number" id="followBuffer" value="<?php echo $settings['follow_buffer_' . $classId] ?? $settings['follow_buffer'] ?? 0.5; ?>" min="-0.5" max="5" step="0.5" class="input">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="followShuffle" style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;">
                    <span>随机乱序（只打乱跟读顺序，不改单词库位置）</span>
                </label>
            </div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="closeFollowModal()">取消</button>
                <button type="button" class="submit" onclick="startFollow()">开始跟读</button>
            </div>
        </div>
    </div>

    <!-- Follow-along Player Overlay (auto-collapses to bubble after 3s) -->
    <div id="followPlayer" style="display:none;position:fixed;bottom:100px;left:16px;right:16px;z-index:700;max-width:500px;margin:0 auto;">
        <div style="background:var(--white);border:2px solid var(--blue);border-radius:var(--wobbly);padding:14px 18px;box-shadow:var(--shadow-md);">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:11px;color:#999;margin-bottom:2px;">跟读中 <span id="followProgress">0/0</span></div>
                    <div id="followWordDisplay" style="font-size:20px;font-weight:bold;color:var(--blue);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">准备中...</div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <button id="followPauseBtn" onclick="toggleFollowPause()" class="btn btn-sm" style="background:#ff9800;color:var(--white);border-color:#ff9800;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停
                    </button>
                    <button onclick="stopFollow()" class="btn btn-sm btn-danger">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>停止
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Follow-along Bubble (collapsed state, bottom-left corner) -->
    <div id="followBubble" onclick="expandFollowBubble()" style="display:none;position:fixed;bottom:24px;left:20px;width:52px;height:52px;background:var(--pencil);border-radius:var(--wobbly);z-index:702;cursor:pointer;box-shadow:var(--shadow-md);animation:followBubblePulse 2s ease-in-out infinite;align-items:center;justify-content:center;border:2px solid var(--pencil);">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--white)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>
    </div>

    <form id="taskForm" style="display:none;">
        <input type="hidden" name="action" value="create_task">
        <input type="hidden" name="selected_ids" id="selectedIds">
        <input type="hidden" name="task_date" id="taskDateInput">
        <input type="hidden" name="task_label" id="taskLabelInput">
        <input type="hidden" name="overwrite" id="overwriteInput" value="0">
    </form>
    <input type="hidden" id="globalCsrfToken" value="<?php echo $csrfToken; ?>">

    <script src="common.js?v=9"></script>
    <script>var speakRepeat = <?php echo $settings['repeat_' . $classId] ?? $settings['default_repeat'] ?? 1; ?>;</script>
    <script>
        const classId = '<?php echo $classId; ?>';

        // ===== 全球发音 =====
        var pronAudio = null;
        function showPronList(wordId, wordText) {
            var fd = new FormData();
            fd.append('action', 'pron_list');
            fd.append('word_id', wordId);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch(location.pathname + '?id=' + encodeURIComponent(classId), { method: 'POST', body: fd, cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (!r.success) { showToast(r.error || '加载失败'); return; }
                    openPronModal(wordText, r.items || []);
                })
                .catch(function() { showToast('网络异常'); });
        }
        function openPronModal(wordText, items) {
            document.getElementById('pronTitle').textContent = (wordText || '') + ' · 全球发音';
            var listEl = document.getElementById('pronList');
            if (!items.length) {
                listEl.innerHTML = '<div class="pron-empty">还没有人录过这个词<br><span style="font-size:12px;">打开 APP，在单词卡上长按地球按钮即可录制</span></div>';
            } else {
                listEl.innerHTML = '';
                items.forEach(function(p) {
                    var item = document.createElement('div');
                    item.className = 'pron-item';
                    item.setAttribute('data-url', p.url);
                    var avatar = document.createElement('span');
                    avatar.className = 'pron-avatar';
                    avatar.textContent = (p.name || '同').charAt(0).toUpperCase();
                    var info = document.createElement('span');
                    info.className = 'pron-info';
                    var b = document.createElement('b'); b.textContent = p.name || '同学';
                    var i = document.createElement('i');
                    i.textContent = (p.duration || 0).toFixed(1) + 's · ' + (p.uploaded_at || '').slice(5, 16);
                    info.appendChild(b); info.appendChild(i);
                    var play = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    play.setAttribute('viewBox', '0 0 24 24'); play.setAttribute('width', '20'); play.setAttribute('height', '20');
                    play.setAttribute('fill', 'currentColor'); play.setAttribute('stroke', 'currentColor');
                    play.setAttribute('stroke-width', '2'); play.setAttribute('stroke-linecap', 'round');
                    play.setAttribute('stroke-linejoin', 'round'); play.setAttribute('class', 'pron-play');
                    var poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                    poly.setAttribute('points', '5 3 19 12 5 21 5 3');
                    play.appendChild(poly);
                    item.appendChild(avatar); item.appendChild(info); item.appendChild(play);
                    item.addEventListener('click', function() { playPron(item); });
                    listEl.appendChild(item);
                });
            }
            document.getElementById('pronModal').classList.add('active');
        }
        function playPron(el) {
            var url = el.getAttribute('data-url');
            if (!url) return;
            if (pronAudio) { try { pronAudio.pause(); } catch (e) {} }
            document.querySelectorAll('.pron-item.playing').forEach(function(x) { x.classList.remove('playing'); });
            pronAudio = new Audio(url);
            el.classList.add('playing');
            pronAudio.play().catch(function() { el.classList.remove('playing'); showToast('播放失败'); });
            pronAudio.onended = function() { el.classList.remove('playing'); };
            pronAudio.onerror = function() { el.classList.remove('playing'); showToast('录音加载失败'); };
        }
        function closePronModal() {
            document.getElementById('pronModal').classList.remove('active');
            if (pronAudio) { try { pronAudio.pause(); } catch (e) {} }
            document.querySelectorAll('.pron-item.playing').forEach(function(x) { x.classList.remove('playing'); });
        }
        document.getElementById('pronModal').addEventListener('click', function(e) { if (e.target === this) closePronModal(); });

        // wordsArray is kept in sync with server state for edit/delete lookups
        let wordsArray = <?php echo json_encode($words, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const searchQuery = <?php echo json_encode($searchQ, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let selectedIds = new Set();
        let previewData = [];

        function toggleSelect(event, id) {
            event.stopPropagation();
            if (followController) { showToast('请先结束跟读后再选择单词', ''); return; }
            const card = document.querySelector('.word-card[data-id="' + id + '"]');
            if (selectedIds.has(id)) { selectedIds.delete(id); if (card) card.classList.remove('selected'); }
            else { if (selectedIds.size >= 20) { showToast('最多只能选择20个单词', 'error'); return; } selectedIds.add(id); if (card) card.classList.add('selected'); }
            document.getElementById('selectedCount').textContent = selectedIds.size;
        }

        function showAddModal() { document.getElementById('addForm').reset(); document.getElementById('addModal').classList.add('active'); }

        /**
         * 将单词卡片滚动到可视区域居中。
         * 显式滚动 .content 容器（页面 body 为 overflow:hidden，scrollIntoView 可能作用到错误滚动器导致无效果/卡顿）。
         * persistent=true 时加持久定位高亮（locate-highlight），直到用户操作清除；
         * 否则为短暂高亮（highlight，1.5s 后自动消失）。
         */
        function scrollCardToCenter(card, persistent) {
            if (!card) return;
            var content = document.getElementById('contentWrap');
            var scroller = content && content.scrollHeight > content.clientHeight ? content : document.scrollingElement;
            // 已移除 content-visibility:auto → getBoundingClientRect 恒准确，无需强制布局。
            // 用 scroller 自身的坐标系计算居中目标（直接拿 card 的视口 top 计算会忽略
            // scroller 顶部偏移(header/工具栏)，导致多滚一段 → 滑过头）。
            var rect = card.getBoundingClientRect();
            var sRect = scroller.getBoundingClientRect();
            var target = scroller.scrollTop + (rect.top - sRect.top) - scroller.clientHeight / 2 + rect.height / 2;
            target = Math.max(0, Math.min(target, scroller.scrollHeight - scroller.clientHeight));
            var dist = Math.abs(target - scroller.scrollTop);
            try {
                if (dist > scroller.clientHeight * 1.5) {
                    // 远距离定位：长距离 smooth 滚动中间会短暂空白（浏览器跟不上渲染）。
                    // 先瞬时跳到目标上方一屏，再平滑滚完最后一段，视觉上几乎无感且不闪白。
                    scroller.scrollTop = Math.max(0, target - scroller.clientHeight);
                    scroller.scrollTo({ top: target, behavior: 'smooth' });
                } else {
                    scroller.scrollTo({ top: target, behavior: 'smooth' });
                }
            } catch (e) {
                scroller.scrollTop = target;
            }
            if (persistent) {
                clearLocateHighlight();
                card.classList.add('locate-highlight');
            } else {
                card.classList.add('highlight');
                setTimeout(function() { card.classList.remove('highlight'); }, 1500);
            }
        }

        // 清除持久定位高亮（用户操作时调用）
        function clearLocateHighlight() {
            document.querySelectorAll('.word-card.locate-highlight').forEach(function(c) { c.classList.remove('locate-highlight'); });
        }
        // 用户主动操作（滚动/点击/触摸/按键）→ 清除定位高亮。
        // 程序 smooth 滚动不会触发这些手势，因此不会误清（不再用 scroll 兜底，
        // 否则程序滚动结束后 scroll 事件会清掉高亮，违背"除非用户操作否则保留"）。
        ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function(evt) {
            document.addEventListener(evt, function() { clearLocateHighlight(); }, { passive: true });
        });
        document.addEventListener('click', function() { clearLocateHighlight(); }, { passive: true });

        function showBatchModal() { document.getElementById('batchTextarea').value = ''; document.getElementById('batchModal').classList.add('active'); }

        // ==================== AI Batch Import ====================
        async function submitBatchPreview() {
            const text = document.getElementById('batchTextarea').value.trim();
            if (!text) { showToast('请输入单词', 'error'); return; }
            const btn = document.getElementById('batchSubmitBtn');
            btn.disabled = true; btn.innerHTML = '<span class="spinner" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.6s linear infinite;vertical-align:middle;margin-right:6px;"></span>AI 处理中...';
            const fd = new FormData();
            fd.append('action', 'batch_ai_preview');
            fd.append('words_text', text);
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    previewData = d.preview;
                    renderPreview();
                    closeModal('batchModal');
                    document.getElementById('previewModal').classList.add('active');
                } else { showToast(d.error || '请求失败', 'error'); }
            } catch (e) { showToast('网络错误', 'error'); }
            btn.disabled = false; btn.textContent = 'AI 智能补全';
        }

        function renderPreview() {
            document.getElementById('previewCount').textContent = previewData.length;
            let html = '';
            previewData.forEach((item, idx) => {
                const isDuplicate = item.exists;
                const isUncertain = item.uncertain;
                let rowClass = 'preview-row';
                if (isDuplicate) rowClass += ' duplicate';
                if (isUncertain) rowClass += ' uncertain';
                html += '<div class="' + rowClass + '" id="prevRow' + idx + '">';
                // Word: editable input + AI button
                html += '<div class="prev-col prev-word">';
                if (!isDuplicate) {
                    html += '<input type="text" id="prevWord' + idx + '" value="' + escHtml(item.word) + '" onchange="previewData[' + idx + '].word=this.value" style="width:100%;padding:4px 6px;border:1.5px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:13px;' + (isUncertain ? 'border-color:var(--red);' : '') + '">';
                    html += '<button class="mini-btn ai-fill" id="aiBtn' + idx + '" onclick="aiFillWord(' + idx + ')" title="AI 补全释义和词性">✨</button>';
                } else {
                    html += '<span style="color:#999;">' + escHtml(item.word) + '</span>';
                }
                html += '</div>';
                html += '<div class="prev-col prev-meaning">';
                if (!isDuplicate) {
                    html += '<input type="text" id="prevMeaning' + idx + '" value="' + escHtml(item.meaning) + '" onchange="previewData[' + idx + '].meaning=this.value" style="width:100%;padding:4px 6px;border:1.5px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:13px;">';
                } else {
                    html += '<span style="color:#999;">' + escHtml(item.meaning) + '</span>';
                }
                html += '</div>';
                html += '<div class="prev-col prev-pos">';
                if (!isDuplicate) {
                    html += '<input type="text" id="prevPos' + idx + '" value="' + escHtml(item.pos) + '" onchange="previewData[' + idx + '].pos=this.value" style="width:100%;padding:4px 6px;border:1.5px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:13px;">';
                } else {
                    html += '<span style="color:#999;">' + escHtml(item.pos) + '</span>';
                }
                html += '</div>';
                html += '<div class="prev-col prev-actions">';
                if (!isDuplicate) {
                    html += '<button class="mini-btn del" onclick="removePreviewItem(' + idx + ')">x</button>';
                }
                html += '</div>';
                html += '</div>';
            });
            document.getElementById('previewList').innerHTML = html;
        }

        function removePreviewItem(idx) { previewData.splice(idx, 1); renderPreview(); }

        async function aiFillWord(idx) {
            const wordInput = document.getElementById('prevWord' + idx);
            const btn = document.getElementById('aiBtn' + idx);
            if (!wordInput || !btn) return;
            const newWord = wordInput.value.trim();
            if (!newWord) { showToast('请输入英文单词', 'error'); return; }
            // Update previewData word first
            previewData[idx].word = newWord;
            btn.disabled = true;
            btn.textContent = '⏳';
            const fd = new FormData();
            fd.append('action', 'ai_single_word');
            fd.append('word', newWord);
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    previewData[idx].meaning = d.meaning;
                    previewData[idx].pos = d.pos;
                    previewData[idx].uncertain = d.uncertain;
                    previewData[idx].exists = d.exists;
                    // Update the meaning and pos input fields in-place
                    const meaningInput = document.getElementById('prevMeaning' + idx);
                    const posInput = document.getElementById('prevPos' + idx);
                    if (meaningInput) meaningInput.value = d.meaning;
                    if (posInput) posInput.value = d.pos;
                    // Update word input border color based on uncertain flag
                    if (d.uncertain) {
                        wordInput.style.borderColor = 'var(--red)';
                    } else {
                        wordInput.style.borderColor = 'var(--blue)';
                    }
                    if (d.exists) {
                        showToast('该单词已存在于单词库中', '');
                    }
                } else { showToast(d.error || 'AI 请求失败', 'error'); }
            } catch (e) { showToast('网络错误', 'error'); }
            btn.disabled = false;
            btn.textContent = '✨';
        }

        async function confirmBatchImport() {
            // Sync latest input values back to previewData before filtering
            previewData.forEach((item, idx) => {
                if (item.exists) return;
                const w = document.getElementById('prevWord' + idx);
                const m = document.getElementById('prevMeaning' + idx);
                const p = document.getElementById('prevPos' + idx);
                if (w) item.word = w.value.trim();
                if (m) item.meaning = m.value.trim();
                if (p) item.pos = p.value.trim();
            });
            const toImport = previewData.filter(item => !item.exists && item.word);
            if (toImport.length === 0) { showToast('没有可导入的新单词', 'error'); return; }
            const fd = new FormData();
            fd.append('action', 'batch_import');
            fd.append('import_data', JSON.stringify(toImport.map(item => ({ word: item.word, meaning: item.meaning, pos: item.pos }))));
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
                if (d.success) {
                    // Append new words to local array and re-render grid IN-PLACE (no page refresh)
                    if (d.new_words && d.new_words.length > 0) {
                        wordsArray = wordsArray.concat(d.new_words);
                        renderWordGrid();
                        initMarquee();
                    }
                    showToast('成功导入 ' + d.imported + ' 个单词' + (d.skipped > 0 ? '，跳过 ' + d.skipped + ' 个' : ''), 'success');
                    closeModal('previewModal');
                } else { showToast(d.error || '导入失败', 'error'); }
            } catch (e) { showToast('网络错误', 'error'); }
        }

        function renderWordGrid() {
            const grid = document.getElementById('wordGrid');
            if (!grid) {
                // Empty state → create grid
                const cw = document.getElementById('contentWrap');
                cw.innerHTML = '<div class="word-grid" id="wordGrid"></div>';
                return renderWordGrid();
            }
            let html = '';
            const list = searchQuery ? wordsArray.filter(w => {
                const q = searchQuery.toLowerCase();
                return (w.word||'').toLowerCase().includes(q) || (w.meaning||'').toLowerCase().includes(q);
            }) : wordsArray;
            if (searchQuery) {
                html += '<div style="padding:10px 14px;background:var(--white);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);margin-bottom:10px;font-size:14px;color:var(--blue);">搜索 "' + escHtml(searchQuery) + '" 匹配 ' + list.length + ' 个单词 <a href="words.php?id=' + classId + '" style="color:var(--red);margin-left:8px;text-decoration:none;">×清除</a></div>';
            }
            list.forEach((w, idx) => {
                const wid = w['id'];
                const word = escHtml(w['word'] || '');
                const meaning = escHtml(w['meaning'] || '');
                const pos = escHtml(w['pos'] || '');
                const pendingDate = <?php echo json_encode($wordPendingInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>[wid] || '';
                const completed = <?php echo json_encode($wordCompletedInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>[wid] || false;
                const selClass = selectedIds.has(wid) ? ' selected' : '';
                html += '<div class="word-card' + selClass + '" data-id="' + wid + '" data-index="' + idx + '"' +
                    ' data-word-db="' + word + '" data-meaning-db="' + meaning + '" data-pos-db="' + pos + '">' +
                    '<div class="corner-tl">' +
                        '<span class="number">' + (idx + 1) + '</span>' +
                        (pendingDate ? '<span class="pending-mark" title="即将于' + pendingDate + '默写"></span>' : '') +
                    '</div>' +
                    '<div class="corner-tr">' +
                        '<span class="checkbox" onclick="toggleSelect(event, \'' + wid + '\')"></span>' +
                    '</div>' +
                    '<div class="card-body">' +
                        '<div class="word" lang="en"><span>' + word + '</span></div>' +
                        '<div class="meaning"><span>' + meaning + '</span></div>' +
                        (pos ? '<div class="pos">' + pos + '</div>' : '') +
                    '</div>' +
                    '<div class="corner-bl">' +
                        '<button class="speaker" onclick=\'event.stopPropagation();speak(' + escHtml(JSON.stringify(w['word'] || '')) + ')\'>' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>' +
                        '</button>' +
                        '<button class="pron-btn speaker" title="全球发音" onclick=\'event.stopPropagation();showPronList(' + JSON.stringify(wid) + ',' + escHtml(JSON.stringify(w['word'] || '')) + ')\'>' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="corner-br">' +
                        (completed ? '<span class="completed-mark" title="已默写"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>' : '') +
                        '<button class="edit-btn" onclick="event.stopPropagation();showEditModal(\'' + wid + '\')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></button>' +
                    '</div>' +
                '</div>';
            });
            grid.innerHTML = html;
            // Re-bind card body click listeners
            grid.querySelectorAll('.word-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.closest('.speaker') || e.target.closest('.pron-btn') || e.target.closest('.edit-btn') || e.target.closest('.checkbox')) return;
                    const cb = this.querySelector('.checkbox'); if (cb) cb.click();
                });
            });
            // Update selected count display
            document.getElementById('selectedCount').textContent = selectedIds.size;
        }

        // ==================== CSV Export ====================
        function exportCsv() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'words.php?id=' + classId;
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'action'; input.value = 'export_csv';
            form.appendChild(input);
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden'; csrfInput.name = 'csrf_token'; csrfInput.value = CSRF_TOKEN;
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // ==================== Edit / Delete ====================
        function showEditModal(id) {
            const w = wordsArray.find(w => w.id === id);
            if (w) {
                document.getElementById('editWordId').value = id;
                document.getElementById('editWord').value = w.word;
                document.getElementById('editMeaning').value = w.meaning;
                document.getElementById('editPos').value = w.pos || '';
                document.getElementById('editModal').classList.add('active');
            }
        }

        function showImportModal() {
            document.getElementById('importForm').reset();
            document.getElementById('fileLabel').textContent = '点击选择CSV文件';
            document.getElementById('importModal').classList.add('active');
        }

        // ==================== Add Word ====================
        document.getElementById('addForm').onsubmit = async function(e) {
            e.preventDefault(); const fd = new FormData(this); fd.append('action', 'add_word'); fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
            if (d.error) { showToast(d.error, 'error'); return; }
            if (d.exists) {
                showToast('单词已存在：' + d.word, 'error');
                closeModal('addModal');
                const card = document.querySelector('.word-card[data-word-db="' + d.word.toLowerCase() + '"]');
                if (card) scrollCardToCenter(card, false);
            } else if (d.success) {
                showToast('添加成功', 'success'); closeModal('addModal');
                showOkOverlayThen('words.php?id=' + classId);
            }
        };

        // ==================== Edit Word ====================
        document.getElementById('editForm').onsubmit = async function(e) {
            e.preventDefault(); const fd = new FormData(this); fd.append('action', 'update_word'); fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
            if (d.error) { showToast(d.error, 'error'); return; }
            if (d.exists) showToast('单词已存在', 'error'); else if (d.success) { showToast('保存成功', 'success'); closeModal('editModal'); showOkOverlayThen('words.php?id=' + classId); }
        };

        // ==================== Delete Word ====================
        async function deleteWord() {
            if (!(await customConfirm('确定要删除这个单词吗？', '删除确认'))) return;
            const fd = new FormData(); fd.append('action', 'delete_word');
            fd.append('word_id', document.getElementById('editWordId').value);
            fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
            if (d.error) { showToast(d.error, 'error'); return; }
            if (d.success) { showToast('删除成功', 'success'); closeModal('editModal'); showOkOverlayThen('words.php?id=' + classId); }
        }

        // ==================== CSV Import ====================
        async function importCsv() {
            const fi = document.querySelector('input[name="csv_file"]');
            if (!fi.files[0]) { showToast('请选择文件', 'error'); return; }
            const fd = new FormData(); fd.append('csv_file', fi.files[0]); fd.append('action', 'import_csv'); fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
            if (d.success) { showToast('成功导入' + d.imported + '个，跳过' + d.skipped + '个', 'success'); closeModal('importModal'); if (d.skipped_list && d.skipped_list.length > 0) await customAlert('跳过的单词：\n' + d.skipped_list.map(w => w.word + ' (编号' + w.index + ')').join('\n'), 'CSV导入结果'); showOkOverlayThen('words.php?id=' + classId); } else { showToast(d.error || '导入失败', 'error'); }
        }

        // ==================== Create Task ====================
        function createTask() { if (selectedIds.size === 0) { showToast('请先选择单词', 'error'); return; } document.getElementById('taskDate').value = '<?php echo date('Y-m-d'); ?>'; document.getElementById('overwriteInput').value = '0'; document.getElementById('taskDateModal').classList.add('active'); }

        async function confirmCreateTask() {
            const date = document.getElementById('taskDate').value;
            const label = document.getElementById('taskLabel').value.trim();
            document.getElementById('selectedIds').value = JSON.stringify([...selectedIds]);
            document.getElementById('taskDateInput').value = date;
            document.getElementById('taskLabelInput').value = label;
            const fd = new FormData(document.getElementById('taskForm'));
            fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('words.php?id=' + classId, { method: 'POST', body: fd })).json();
            if (d.success) { showToast('创建成功', 'success'); closeModal('taskDateModal'); if (await customConfirm('是否跳转到默写任务页面？', '创建成功')) showOkOverlayThen('task.php?id=' + classId); else showOkOverlayThen('words.php?id=' + classId); }
            else if (d.need_confirm) { if (await customConfirm('该日期已有任务，是否覆盖？', '覆盖确认')) { document.getElementById('overwriteInput').value = '1'; confirmCreateTask(); } }
            else if (d.error) { showToast(d.error, 'error'); }
        }

        // ==================== Follow-along ====================
        let followController = null;
        let followPaused = false;
        let followCollapsed = false;
        let followCollapseTimer = null;
        let followSessionSeq = 0;   // 会话序号：收尾延时只对本会话生效
        function showFollowModal() {
            const selCount = selectedIds.size;
            const opt = document.getElementById('followSelectedOpt');
            document.getElementById('followSelectedOpt').textContent = '已选单词 (' + selCount + '个)';
            if (selCount === 0) {
                opt.disabled = true;
                opt.textContent = '已选单词 (未选择)';
                document.getElementById('followScope').value = 'all';
            } else {
                opt.disabled = false;
            }
            document.getElementById('followModal').classList.add('active');
        }
        function closeFollowModal() {
            // 只关弹窗，不停止正在进行的跟读
            closeModal('followModal');
        }
        function expandFollowBubble() {
            followCollapsed = false;
            clearTimeout(followCollapseTimer);
            document.getElementById('followBubble').style.display = 'none';
            document.getElementById('followPlayer').style.display = 'block';
            followCollapseTimer = setTimeout(collapseFollowToBubble, 3000);
        }
        function collapseFollowToBubble() {
            if (!followController || followPaused) return;
            followCollapsed = true;
            clearTimeout(followCollapseTimer);
            document.getElementById('followPlayer').style.display = 'none';
            document.getElementById('followBubble').style.display = 'flex';
        }
        function startFollow() {
            const scope = document.getElementById('followScope').value;
            // isNaN 判空，允许合法 0 值（缓冲 0 有效）
            const repeatRaw = parseInt(document.getElementById('followRepeat').value, 10);
            const repeat = isNaN(repeatRaw) || repeatRaw < 1 ? 1 : Math.min(5, repeatRaw);
            const bufferRaw = parseFloat(document.getElementById('followBuffer').value);
            const buffer = isNaN(bufferRaw) ? 0.5 : Math.max(-0.5, Math.min(5, bufferRaw));
            const shuffle = document.getElementById('followShuffle').checked;
            closeModal('followModal');

            let words;
            if (scope === 'selected') {
                words = wordsArray.filter(w => selectedIds.has(w.id)).map(w => w.word);
            } else {
                words = wordsArray.map(w => w.word);
            }
            if (words.length === 0) { showToast('没有可跟读的单词', 'error'); return; }
            if (shuffle) shuffleArray(words);   // 只打乱播放顺序，单词库卡片位置不动

            // 高亮定位：小写单词 -> 卡片元素 映射（大小写安全、无选择器注入风险）
            const cardMap = new Map();
            document.querySelectorAll('.word-card[data-word-db]').forEach(function(c) {
                const k = (c.getAttribute('data-word-db') || '').toLowerCase();
                if (k && !cardMap.has(k)) cardMap.set(k, c);
            });

            // Save follow settings
            fetch('settings.php?id=' + classId, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=save_settings&follow_repeat=' + repeat + '&follow_buffer=' + buffer + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN) }).catch(function(){});

            followSessionSeq++;
            const mySeq = followSessionSeq;
            document.getElementById('followPlayer').style.display = 'block';
            document.getElementById('followBubble').style.display = 'none';
            followCollapsed = false;
            clearTimeout(followCollapseTimer);
            document.getElementById('followProgress').textContent = '0/' + words.length;
            document.getElementById('followWordDisplay').textContent = '准备中...';
            followPaused = false;
            document.getElementById('followPauseBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停';

            followController = startFollowAlong(words, { repeat: repeat, buffer: buffer, volume: <?php echo $settings['volume_' . $classId] ?? $settings['default_volume'] ?? 80; ?> }, function(info) {
                if (info.done) {
                    clearTimeout(followCollapseTimer);
                    followController = null;
                    followCollapsed = false;
                    document.querySelectorAll('.word-card.follow-highlight').forEach(function(c) { c.classList.remove('follow-highlight'); });
                    if (info.stopped) {
                        document.getElementById('followPlayer').style.display = 'none';
                        document.getElementById('followBubble').style.display = 'none';
                    } else {
                        // 显示完成态 N/N 片刻后再收尾
                        document.getElementById('followProgress').textContent = words.length + '/' + words.length;
                        document.getElementById('followWordDisplay').textContent = '完成';
                        showToast('跟读完成', 'success');
                        setTimeout(function() {
                            if (mySeq === followSessionSeq) {
                                document.getElementById('followPlayer').style.display = 'none';
                                document.getElementById('followBubble').style.display = 'none';
                            }
                        }, 800);
                    }
                } else {
                    // Always update display text
                    document.getElementById('followWordDisplay').textContent = info.word;
                    document.getElementById('followProgress').textContent = info.index + '/' + info.total;
                    // Highlight and scroll to current word card
                    document.querySelectorAll('.word-card.follow-highlight').forEach(function(c) { c.classList.remove('follow-highlight'); });
                    var card = cardMap.get(info.word.toLowerCase());
                    if (card) {
                        card.classList.add('follow-highlight');
                        scrollCardToCenter(card, false);
                    }
                    // Only show the bar if not already collapsed by user scroll
                    if (!followCollapsed) {
                        document.getElementById('followPlayer').style.display = 'block';
                        document.getElementById('followBubble').style.display = 'none';
                        clearTimeout(followCollapseTimer);
                        followCollapseTimer = setTimeout(collapseFollowToBubble, 3000);
                    }
                }
            });
            // Set initial collapse timer
            followCollapseTimer = setTimeout(collapseFollowToBubble, 3000);
        }
        function toggleFollowPause() {
            if (!followController) return;
            const btn = document.getElementById('followPauseBtn');
            if (followPaused) {
                followController.resume();
                followPaused = false;
                followCollapsed = false;
                clearTimeout(followCollapseTimer);
                document.getElementById('followPlayer').style.display = 'block';
                document.getElementById('followBubble').style.display = 'none';
                followCollapseTimer = setTimeout(collapseFollowToBubble, 3000);
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>暂停';
                btn.style.background = '#ff9800';
            } else {
                followController.pause();
                followPaused = true;
                clearTimeout(followCollapseTimer);
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-2px;margin-right:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>继续';
                btn.style.background = 'var(--blue)';
            }
        }
        function stopFollow() {
            if (followController) { followController.stop(); followController = null; }
            clearTimeout(followCollapseTimer);
            followCollapsed = false;
            document.getElementById('followPlayer').style.display = 'none';
            document.getElementById('followBubble').style.display = 'none';
            followPaused = false;
        }

        // ==================== Init ====================
        // 脚本位于 </body> 前，DOM 已解析完成，直接执行定位，不再等 window load。
        // （window load 会等 Google Fonts/图片全部加载完——字体 CDN 慢或被墙时可能延迟
        //   数秒甚至一直不触发，这就是"加载完还要等 4-5 秒才开始定位 / 有的机器直接没定位"的根因。）
        initMarquee();
        if ('IntersectionObserver' in window) {
            try {
                const io = new IntersectionObserver(function(entries) {
                    entries.forEach(function(en) {
                        if (en.isIntersecting) initMarqueeFor(en.target);
                    });
                }, { rootMargin: '200px 0px' });
                document.querySelectorAll('.word-card').forEach(card => io.observe(card));
            } catch (e) {}
        }
        // Click card body to toggle selection
        document.querySelectorAll('.word-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.speaker') || e.target.closest('.pron-btn') || e.target.closest('.edit-btn') || e.target.closest('.checkbox')) return;
                const cb = this.querySelector('.checkbox'); if (cb) cb.click();
            });
        });

        // 执行定位（高亮优先级：搜索参数 > 历史"用这些单词重新创建" > 最近创建任务的最后一个单词）
        function runInitialLocate() {
            const params = new URLSearchParams(location.search);
            const highlightId = params.get('highlight');
            if (highlightId) {
                const hc = document.querySelector('.word-card[data-id="' + highlightId + '"]');
                // 搜索定位：持久高亮，直到用户操作才清除（locate-highlight）
                if (hc) setTimeout(() => { scrollCardToCenter(hc, true); }, 50);
                return;
            }
            const ri = localStorage.getItem('recreate_word_ids');
            if (ri) {
                localStorage.removeItem('recreate_word_ids');
                try {
                    const ids = JSON.parse(ri).slice(0, 20);
                    ids.forEach(id => { const c = document.querySelector('.word-card[data-id="' + id + '"]'); if (c) { selectedIds.add(id); c.classList.add('selected'); } });
                    document.getElementById('selectedCount').textContent = selectedIds.size;
                    // 定位到这批单词中的第一个并持久高亮，方便用户确认要重新创建任务的单词
                    const first = document.querySelector('.word-card[data-id="' + ids[0] + '"]');
                    if (first) setTimeout(() => { scrollCardToCenter(first, true); }, 50);
                    if (selectedIds.size > 0) showToast('已选择 ' + selectedIds.size + ' 个单词，可直接创建任务', 'success');
                } catch(e) {}
                return;
            }
            const li = <?php echo $lastWordIndex; ?>;
            const lid = <?php echo $lastWordId === null ? 'null' : json_encode($lastWordId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            if ((li >= 0 || lid) && wordsArray.length > 0) {
                const c = document.querySelector(lid ? '.word-card[data-id="' + lid + '"]' : '.word-card[data-index="' + li + '"]');
                if (c) setTimeout(() => { scrollCardToCenter(c, true); }, 50);
            }
        }
        // 批量导入完成后跳回本页时恢复勾选状态（保持与 load 阶段一致，提前到定位前）
        (function() {
            const bi = localStorage.getItem('batch_import_selected');
            if (bi) {
                localStorage.removeItem('batch_import_selected');
                try {
                    const ids = JSON.parse(bi).slice(0, 20);
                    ids.forEach(id => { const c = document.querySelector('.word-card[data-id="' + id + '"]'); if (c) { selectedIds.add(id); c.classList.add('selected'); } });
                    document.getElementById('selectedCount').textContent = selectedIds.size;
                } catch(e) {}
            }
        })();
        runInitialLocate();

        // 字体/图片加载完后再做两件事：
        // 1) 重测跑马灯（字体加载会改变文本宽度）
        // 2) 若定位高亮仍在（用户尚未操作），字体加载可能改变卡片高度/换行 → 微调定位防漂移
        window.addEventListener('load', () => {
            initMarquee();
            const hl = document.querySelector('.word-card.locate-highlight');
            if (hl) setTimeout(() => { scrollCardToCenter(hl, true); }, 80);
        });
        document.fonts && document.fonts.ready && document.fonts.ready.then(function() {
            initMarquee();
            const hl = document.querySelector('.word-card.locate-highlight');
            if (hl) setTimeout(() => { scrollCardToCenter(hl, true); }, 80);
        });
    </script>
</body>
</html>
