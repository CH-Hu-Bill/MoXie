<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/app_lib.php';

// ---- 稳定性：同样内容必须得到同样指纹 ----
$words = [
    ['id' => 'w1', 'type' => 'word', 'word' => 'apple', 'meaning' => '苹果', 'pos' => 'n.', 'created_at' => '2026-01-01 10:00:00'],
    ['id' => 'w2', 'type' => 'sentence', 'word' => 'Hello', 'meaning' => '你好', 'created_at' => '2026-01-02 10:00:00'],
];
checkSame(appWordsRev($words), appWordsRev($words), 'words rev stable');
checkSame(16, strlen(appWordsRev($words)), 'words rev length 16');

// ---- 内容变化必须改变指纹 ----
$changed = $words;
$changed[0]['meaning'] = '苹果（水果）';
check(appWordsRev($words) !== appWordsRev($changed), 'meaning change alters words rev');

$added = $words;
$added[] = ['id' => 'w3', 'type' => 'word', 'word' => 'pear', 'meaning' => '梨'];
check(appWordsRev($words) !== appWordsRev($added), 'new item alters words rev');

// ---- 任务：状态/条目变化都能识别，顺序无关 ----
$tasks = [
    't1' => ['id' => 't1', 'status' => 'pending', 'date' => '2026-11-20', 'label' => '1', 'word_ids' => ['a', 'b']],
    't2' => ['id' => 't2', 'status' => 'completed', 'date' => '2026-11-19', 'label' => '2', 'word_ids' => ['c']],
];
$tasksSwapped = ['t2' => $tasks['t2'], 't1' => $tasks['t1']];
checkSame(appTasksRev($tasks), appTasksRev($tasksSwapped), 'tasks rev ignores ordering');

$completed = $tasks;
$completed['t1']['status'] = 'completed';
check(appTasksRev($tasks) !== appTasksRev($completed), 'status change alters tasks rev');

$fewer = $tasks;
$fewer['t1']['word_ids'] = ['a'];
check(appTasksRev($tasks) !== appTasksRev($fewer), 'word_ids change alters tasks rev');

// ---- 错题：按用户维度；标记时间也算变化 ----
$wrong = ['w1' => ['marked_at' => '2026-11-20 10:00:00'], 'w5' => ['marked_at' => '2026-11-20 11:00:00']];
$wrongSwapped = ['w5' => $wrong['w5'], 'w1' => $wrong['w1']];
checkSame(appWrongRev($wrong), appWrongRev($wrongSwapped), 'wrong rev ignores key ordering');
checkSame(appWrongRev([]), appWrongRev([]), 'empty wrong rev stable');
check(appWrongRev($wrong) !== appWrongRev([]), 'wrong map change alters rev');

$remarked = $wrong;
$remarked['w1']['marked_at'] = '2026-11-21 09:00:00';
check(appWrongRev($wrong) !== appWrongRev($remarked), 'marked_at change alters wrong rev');

// ---- 图集：描述/实况视频变化都能识别 ----
$gallery = [
    ['id' => 'g1', 'image' => 'a.jpg', 'type' => 'image', 'description' => 'x', 'uploaded_at' => '2026-11-20 10:00:00'],
    ['id' => 'g2', 'image' => 'b.jpg', 'type' => 'live', 'live_video' => 'b.mp4', 'description' => 'y', 'uploaded_at' => '2026-11-20 11:00:00'],
];
checkSame(appGalleryRev($gallery), appGalleryRev($gallery), 'gallery rev stable');

$descChanged = $gallery;
$descChanged[0]['description'] = 'x2';
check(appGalleryRev($gallery) !== appGalleryRev($descChanged), 'description change alters gallery rev');

$liveGone = $gallery;
unset($liveGone[1]['live_video']);
check(appGalleryRev($gallery) !== appGalleryRev($liveGone), 'live_video change alters gallery rev');

// ---- 聚合：四个区块各自独立 ----
$revs = appDataRevs($words, $tasks, $wrong, $gallery);
checkSame(['words', 'tasks', 'wrong', 'gallery'], array_keys($revs), 'rev keys');
check($revs['words'] !== $revs['tasks'], 'blocks have distinct revs');
$revs2 = appDataRevs($words, $tasks, $wrong, $gallery);
checkSame($revs, $revs2, 'aggregate rev stable');

// 只改图集：其它区块指纹保持不变（客户端据此只刷新图集）
$gallery2 = $gallery;
$gallery2[0]['description'] = 'z';
$revs3 = appDataRevs($words, $tasks, $wrong, $gallery2);
checkSame($revs['words'], $revs3['words'], 'words rev untouched by gallery change');
checkSame($revs['tasks'], $revs3['tasks'], 'tasks rev untouched by gallery change');
check($revs['gallery'] !== $revs3['gallery'], 'gallery rev changed');

done();
