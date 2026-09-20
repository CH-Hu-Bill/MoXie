<?php

date_default_timezone_set('Asia/Shanghai');

require_once 'inc/db.php';
require_once 'inc/history.php';
$config = require 'inc/config.php';

$allowedOrigins = $config['allowed_origins'] ?? ['*'];
if (!is_array($allowedOrigins)) $allowedOrigins = [$allowedOrigins];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require_once 'inc/api.php';
require_once 'inc/app_auth.php';
require_once 'inc/ratelimit.php';

Database::migrateAppData();

$action = trim(reqPost('action'));
if ($action === '') appError('缺少 action 参数');

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

function appRateLimit($bucket, $identity, $max, $windowSec) {
    list($allow, $retry) = RateLimiter::check($bucket, $identity, $max, $windowSec);
    if (!$allow) {
        header('Retry-After: ' . $retry);
        appError('操作过于频繁，请' . $retry . '秒后再试', 'RATE_LIMITED', 429);
    }
}

/**
 * 知识库条目统一序列化（单词/句子/作文）。旧数据缺省视为 word。
 */
function appWordOut($w) {
    $type = $w['type'] ?? 'word';
    if (!in_array($type, ['word', 'sentence', 'essay'], true)) $type = 'word';
    return [
        'id' => $w['id'],
        'word' => $w['word'],
        'meaning' => $w['meaning'],
        'pos' => $w['pos'] ?? '',
        'type' => $type,
        'title' => $w['title'] ?? '',
    ];
}

/** 类型排序权重：单词 → 句子 → 作文 */
function appTypeRank($w) {
    $t = $w['type'] ?? 'word';
    return $t === 'sentence' ? 1 : ($t === 'essay' ? 2 : 0);
}

/** 按类型稳定排序（PHP 7.4 的 usort 不稳定，用原下标兜底） */
function appStableSortByType($list) {
    $decorated = [];
    foreach ($list as $i => $item) $decorated[] = [$i, $item];
    usort($decorated, function($a, $b) {
        $ra = appTypeRank($a[1]); $rb = appTypeRank($b[1]);
        if ($ra !== $rb) return $ra <=> $rb;
        return $a[0] <=> $b[0];
    });
    $out = [];
    foreach ($decorated as $d) $out[] = $d[1];
    return $out;
}

if ($action === 'register' || $action === 'claim_legacy') {
    appRateLimit('login', $clientIp, 10, 300);
    $name = appUsername();
    $password = reqPost('password');
    appValidateCredentials($name, $password);
    $uid = appFindUserId($name);
    if ($uid !== null) {
        $user = Database::getUser($uid);
        if ($user && !empty($user['password_hash'])) {
            appError('用户名已存在', null, 409);
        }
    }
    if ($uid === null) {
        if ($action === 'claim_legacy') {
            appError('旧用户不存在');
        }
        $tokens = Database::getTokens();
        do { $uid = 'u' . $tokens['next_uid']++; } while (Database::getUser($uid) !== null);
        Database::saveUser($uid, ['name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'class_ids' => [], 'class_auth_versions' => [], 'wrong_words' => [], 'favorites' => []]);
        Database::updateTokens(function($latest) use ($tokens) { $latest['next_uid'] = $tokens['next_uid']; return $latest; });
    } else {
        $user = Database::getUser($uid);
        if ($user && $action === 'register') {
            Database::updateUser($uid, function($latest) {
                $latest['legacy_claimed_at'] = date('Y-m-d H:i:s');
                return $latest;
            });
        }
    }
    Database::updateUser($uid, function($latest) use ($password) {
        $latest['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        return $latest;
    });
    $updated = Database::getUser($uid);
    $tokens = Database::getTokens();
    list($token, $expires) = appNewToken($tokens, $uid);
    Database::saveTokens($tokens);
    appJson(['success' => true, 'data' => [
        'user_id' => $uid, 'name' => $name,
        'class_ids' => $updated['class_ids'] ?? [],
        'token' => $token, 'expires_at' => $expires,
        'is_new' => empty($updated['legacy_claimed_at']),
    ]]);
}

if ($action === 'login') {
    appRateLimit('login', $clientIp, 10, 300);
    $name = appUsername();
    $password = reqPost('password');
    appValidateCredentials($name, $password);
    $uid = appFindUserId($name);

    if ($uid === null) {
        $tokens = Database::getTokens();
        do { $uid = 'u' . $tokens['next_uid']++; } while (Database::getUser($uid) !== null);
        $user = ['name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'class_ids' => [], 'class_auth_versions' => [], 'wrong_words' => [], 'favorites' => []];
        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        Database::saveUser($uid, $user);
        Database::updateTokens(function($latest) use ($tokens) { $latest['next_uid'] = $tokens['next_uid']; return $latest; });
        list($token, $expires) = appNewToken($tokens, $uid);
        Database::saveTokens($tokens);
        appJson(['success' => true, 'data' => [
            'user_id' => $uid, 'name' => $name, 'class_ids' => [],
            'token' => $token, 'expires_at' => $expires, 'is_new' => true,
        ]]);
    }

    $user = Database::getUser($uid);
    if (empty($user['password_hash'])) {
        Database::updateUser($uid, function($latest) use ($password) {
            $latest['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $latest['legacy_claimed_at'] = date('Y-m-d H:i:s');
            return $latest;
        });
        $user = Database::getUser($uid);
        $tokens = Database::getTokens();
        list($token, $expires) = appNewToken($tokens, $uid);
        Database::saveTokens($tokens);
        appJson(['success' => true, 'data' => [
            'user_id' => $uid, 'name' => $user['name'],
            'class_ids' => $user['class_ids'] ?? [],
            'token' => $token, 'expires_at' => $expires, 'is_new' => false,
            'consent' => !empty($user['consent']),
            'consent_map' => $user['consent_map'] ?? (object)[],
        ]]);
    }
    if (!password_verify($password, $user['password_hash'])) appError('用户名或密码错误', null, 401);
    $tokens = Database::getTokens();
    list($token, $expires) = appNewToken($tokens, $uid);
    Database::saveTokens($tokens);
    appJson(['success' => true, 'data' => [
        'user_id' => $uid, 'name' => $user['name'],
        'class_ids' => $user['class_ids'] ?? [],
        'token' => $token, 'expires_at' => $expires, 'is_new' => false,
        'consent' => !empty($user['consent']),
        'consent_map' => $user['consent_map'] ?? (object)[],
    ]]);
}

if ($action === 'get_classes') {
    $list = [];
    foreach (Database::getClasses() as $cid => $class) {
        $list[] = ['id' => $cid, 'name' => $class['name'], 'has_password' => !empty($class['password_hash'])];
    }
    appJson(['success' => true, 'data' => $list]);
}

if ($action === 'auto_login') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    appJson(['success' => true, 'data' => [
        'user_id' => $userId, 'name' => $authUser['name'] ?? '',
        'class_ids' => $authUser['class_ids'] ?? [],
        'consent' => !empty($authUser['consent']),
        'consent_map' => $authUser['consent_map'] ?? (object)[],
    ]]);
}

if ($action === 'delete_account') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    Database::deleteUser($userId);
    Database::updateTokens(function($data) use ($tokenHash) {
        unset($data['tokens'][$tokenHash]);
        return $data;
    });
    appJson(['success' => true]);
}

if ($action === 'get_my_classes') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    $classes = Database::getClasses();
    $list = [];
    foreach (($authUser['class_ids'] ?? []) as $cid) {
        if (isset($classes[$cid])) $list[] = ['class_id' => $cid, 'class_name' => $classes[$cid]['name']];
    }
    appJson(['success' => true, 'data' => ['classes' => $list]]);
}

if ($action === 'check_class') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    $classes = Database::getClasses();
    if (!isset($classes[$classId])) appError('班级不存在或已被删除，请重新选择班级', 'CLASS_DELETED', 404);
    $current = appClassVersion($classes[$classId]);
    $stored = $authUser['class_auth_versions'][$classId] ?? null;
    if (!is_string($stored) || !hash_equals($current, $stored)) {
        appJson(['success' => true, 'data' => ['ok' => false, 'need_relogin' => true, 'message' => '班级口令已变更，请重新输入密码']]);
    }
    appJson(['success' => true, 'data' => ['ok' => true, 'need_relogin' => false, 'message' => '']]);
}

if ($action === 'get_profile') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    appJson(['success' => true, 'data' => [
        'user_id' => $userId, 'name' => $authUser['name'] ?? '',
        'class_ids' => $authUser['class_ids'] ?? [],
        'consent' => !empty($authUser['consent']),
        'consent_map' => $authUser['consent_map'] ?? (object)[],
    ]]);
}

if ($action === 'set_global_consent') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    $allow = reqPost('consent', reqPost('allow', '0')) === '1';
    Database::updateUser($userId, function($data) use ($allow) {
        $data['consent'] = $allow;
        if (!isset($data['consent_map']) || !is_array($data['consent_map'])) {
            $data['consent_map'] = [];
        }
        foreach (($data['class_ids'] ?? []) as $cid) {
            $data['consent_map'][$cid] = $allow;
        }
        return $data;
    });
    appJson(['success' => true, 'data' => ['consent' => $allow]]);
}

if ($action === 'check_version') {
    $current = trim(reqPost('current_version', '1.0'));
    $versions = Database::read('app_versions.json');
    if (!is_array($versions)) $versions = ['latest' => '1.0', 'history' => []];
    $latest = (string)($versions['latest'] ?? '1.0');
    $hasUpdate = version_compare($latest, $current, '>');
    if ($hasUpdate && version_compare($current, $latest, '>=')) {
        $hasUpdate = false;
    }
    $notes = '';
    foreach (($versions['history'] ?? []) as $v) {
        if (($v['version'] ?? '') === $latest) { $notes = $v['notes'] ?? ''; break; }
    }
    appJson(['success' => true, 'data' => [
        'latest' => $latest, 'has_update' => $hasUpdate, 'notes' => $notes,
    ]]);
}

if ($action === 'get_announcements') {
    list($userId, $authUser) = appRequireAuth();
    $classId = trim(reqPost('class_id'));
    $platform = trim(reqPost('platform', 'app'));
    $announcements = Database::getAnnouncements();
    $nowTs = time();
    $result = [];
    foreach ($announcements as $ann) {
        $annStartTs = strtotime((string)($ann['start_time'] ?? ''));
        $annEndTs = strtotime((string)($ann['end_time'] ?? ''));
        if ($annStartTs === false || $annEndTs === false || $annStartTs > $nowTs || $annEndTs < $nowTs) continue;
        if (!in_array('all', $ann['target_classes'] ?? []) && !in_array($classId, $ann['target_classes'] ?? [])) continue;
        if (!in_array($platform, $ann['target_platforms'] ?? [])) continue;
        $result[] = $ann;
    }
    appJson(['success' => true, 'data' => $result]);
}

if ($action === 'get_csrf_token') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['app_csrf_' . $userId] = $csrf;
    appJson(['success' => true, 'data' => ['csrf_token' => $csrf]]);
}


list($userId, $authUser, $tokenHash) = appRequireAuth();

if ($action === 'logout') {
    Database::updateTokens(function($data) use ($tokenHash) {
        unset($data['tokens'][$tokenHash]);
        return $data;
    });
    appJson(['success' => true]);
}

if ($action === 'bind_class') {
    appRateLimit('bindpw', $clientIp, 10, 300);
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    $classes = Database::getClasses();
    if (!isset($classes[$classId])) appError('班级不存在');
    $hash = $classes[$classId]['password_hash'] ?? '';
    $classPassword = reqPost('password', reqPost('class_password'));
    if ($hash !== '' && !password_verify($classPassword, $hash)) appError('口令错误');
    $version = appClassVersion($classes[$classId]);
    Database::updateUser($userId, function($data) use ($classId, $version) {
        $ids = $data['class_ids'] ?? [];
        if (!in_array($classId, $ids, true)) $ids[] = $classId;
        $data['class_ids'] = $ids;
        $data['class_auth_versions'][$classId] = $version;
        if (!isset($data['wrong_words'][$classId])) $data['wrong_words'][$classId] = [];
        if (!isset($data['consent_map']) || !is_array($data['consent_map'])) {
            $data['consent_map'] = [];
        }
        if (!array_key_exists($classId, $data['consent_map'])) {
            $data['consent_map'][$classId] = !empty($data['consent']);
        }
        return $data;
    });
    appJson(['success' => true, 'data' => ['class_id' => $classId, 'class_name' => $classes[$classId]['name'], 'auth_version' => (int)($classes[$classId]['auth_version'] ?? 1)]]);
}

if ($action === 'unbind_class') {
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    Database::updateUser($userId, function($data) use ($classId) {
        $ids = $data['class_ids'] ?? [];
        $data['class_ids'] = array_values(array_filter($ids, function($id) use ($classId) { return $id !== $classId; }));
        unset($data['class_auth_versions'][$classId], $data['wrong_words'][$classId]);
        return $data;
    });
    appJson(['success' => true]);
}

$classActions = ['verify_class_password', 'get_words', 'search_word', 'add_word', 'ai_word', 'mark_wrong', 'unmark_wrong', 'toggle_favorite', 'get_favorites', 'get_tasks', 'get_task_detail', 'export_task_csv', 'export_task_text', 'complete_task', 'cancel_task', 'get_completed_tasks', 'search_all', 'export_words_pdf', 'export_wrong_csv', 'export_wrong_text', 'get_wrong_words', 'export_personal_history', 'get_class_history', 'get_personal_history', 'get_authorized_vlogs', 'save_personal_history', 'upload_image', 'get_gallery', 'save_gallery', 'update_gallery', 'delete_gallery', 'set_consent', 'get_consent', 'get_pronunciations', 'upload_pronunciation', 'delete_pronunciation'];
$classId = null;
if (in_array($action, $classActions, true)) {
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    appRequireClass($userId, $classId);
}

switch ($action) {
    case 'verify_class_password':
        appJson(['success' => true]);

    case 'get_words':
    case 'search_word':
        $words = Database::getWords($classId);
        $query = trim(reqPost('query'));
        if ($action === 'search_word' && $query === '') appError('参数不全');
        $user = Database::getUser($userId);
        $wrong = $user['wrong_words'][$classId] ?? [];
        $favIds = $user['favorites'] ?? [];
        $list = [];
        foreach ($words as $word) {
            if ($action === 'search_word' && mb_stripos((string)$word['word'], $query) === false && mb_stripos((string)$word['meaning'], $query) === false) continue;
            $list[] = array_merge(appWordOut($word), [
                'is_wrong' => isset($wrong[$word['id']]),
                'is_favorite' => in_array($word['id'], $favIds, true),
            ]);
        }
        // 知识库列表按 单词 → 句子 → 作文 顺序（组内保持原序），便于 APP 单页顺序展示
        if ($action === 'get_words') {
            $list = appStableSortByType($list);
        }
        $total = count($list);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_POST['per_page'] ?? 20)));
        $paged = array_slice($list, ($page - 1) * $perPage, $perPage);
        appJson(['success' => true, 'data' => ['words' => $paged, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'add_word':
        $type = reqPost('type', 'word');
        if (!in_array($type, ['word', 'sentence', 'essay'], true)) appError('类型无效');
        $word = sanitizePlainText(reqPost('word'));
        $meaning = sanitizePlainText(reqPost('meaning'));
        $pos = $type === 'word' ? sanitizePlainText(reqPost('pos')) : '';
        $title = $type === 'essay' ? sanitizePlainText(reqPost('title')) : '';
        if ($word === '' || $meaning === '') appError('内容和释义不能为空');
        $maxContent = $type === 'essay' ? 10000 : ($type === 'sentence' ? 1000 : 100);
        $maxMeaning = $type === 'word' ? 500 : 20000;
        if (mb_strlen($word) > $maxContent || mb_strlen($meaning) > $maxMeaning || mb_strlen($pos) > 50 || mb_strlen($title) > 100) appError('输入内容过长');
        $newId = bin2hex(random_bytes(8));
        $duplicate = false;
        Database::updateClassData($classId, 'words', function($words) use ($word, $meaning, $pos, $title, $type, $newId, &$duplicate) {
            foreach ($words as $existing) if ((($existing['type'] ?? 'word') === $type) && mb_strtolower((string)$existing['word']) === mb_strtolower($word)) { $duplicate = true; return null; }
            $item = ['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'type' => $type, 'created_at' => date('Y-m-d')];
            if ($title !== '') $item['title'] = $title;
            $words[] = $item;
            return $words;
        });
        if ($duplicate) appError('内容已存在');
        appJson(['success' => true, 'data' => appWordOut(['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'type' => $type, 'title' => $title])]);

    case 'ai_word':
        appRateLimit('ai', $userId, 40, 3600);
        $type = reqPost('type', 'word');
        if (!in_array($type, ['word', 'sentence', 'essay'], true)) $type = 'word';
        $word = trim(reqPost('word'));
        $maxLen = $type === 'essay' ? 10000 : ($type === 'sentence' ? 1000 : 100);
        if ($word === '' || mb_strlen($word) > $maxLen) appError('请输入有效内容');
        if ($type === 'word') {
            $prompt = "你是一个英语词典助手。为英文单词提供简洁准确的中文释义和标准词性缩写，严格返回JSON：\n{\"word\":\"" . addslashes($word) . "\",\"meaning\":\"中文释义\",\"pos\":\"词性\"}";
            $sys = '你是专业英语词典助手，只返回JSON。';
            $maxTokens = 512;
        } elseif ($type === 'sentence') {
            $prompt = "把下面的英文句子直译成中文，尽量逐词对应、不要意译、不要润色，只返回中文译文本身，不要任何解释：\n" . $word;
            $sys = '你是英语句子直译助手，只输出中文译文。';
            $maxTokens = 1024;
        } else {
            $prompt = "把下面的英文逐句直译成中文，尽量逐词对应、不要意译、不要润色，保留原有换行与段落，只返回中文译文本身，不要任何解释：\n" . $word;
            $sys = '你是英语直译助手，只输出中文译文。';
            $maxTokens = 4096;
        }
        $result = AIClient::call($classId, [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $prompt]], $maxTokens, 60);
        if (!$result['success']) appJson($result);
        $content = trim((string)$result['content']);
        if ($type === 'word') {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) appError('AI返回格式解析失败，请重试');
            appJson(['success' => true, 'data' => ['word' => trim($parsed['word'] ?? $word), 'meaning' => trim($parsed['meaning'] ?? ''), 'pos' => trim($parsed['pos'] ?? ''), 'type' => 'word']]);
        } else {
            appJson(['success' => true, 'data' => ['word' => $word, 'meaning' => $content, 'pos' => '', 'type' => $type]]);
        }

    case 'mark_wrong':
        $wordId = appStrictId($_POST['word_id'] ?? '', 'word_id');
        $exists = false;
        foreach (Database::getWords($classId) as $word) if ((string)$word['id'] === $wordId) { $exists = true; break; }
        if (!$exists) appError('单词不存在');
        $wrong = reqPost('wrong', '1') === '1';
        Database::updateUser($userId, function($data) use ($classId, $wordId, $wrong) {
            if (!isset($data['wrong_words'][$classId])) $data['wrong_words'][$classId] = [];
            if ($wrong) $data['wrong_words'][$classId][$wordId] = ['marked_at' => date('Y-m-d H:i:s')];
            else unset($data['wrong_words'][$classId][$wordId]);
            return $data;
        });
        appJson(['success' => true, 'data' => ['is_wrong' => $wrong]]);

    case 'get_tasks':
        $type = trim(reqPost('type', 'pending'));
        if (!in_array($type, ['pending', 'history'], true)) appError('type 须为 pending 或 history');
        $map = [];
        foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $list = [];
        foreach (Database::getTasks($classId) as $tid => $task) {
            $status = $task['status'] ?? '';
            if ($type === 'pending' && $status !== 'pending') continue;
            if ($type === 'history' && !in_array($status, ['completed', 'cancelled'], true)) continue;
            $list[] = ['id' => $task['id'] ?? $tid, 'date' => $task['date'] ?? '', 'label' => $task['label'] ?? '', 'status' => $status, 'word_count' => count($task['word_ids'] ?? []), 'weekend_week' => $task['weekend_week'] ?? '', 'created_at' => $task['created_at'] ?? ''];
        }
        if ($type === 'pending') usort($list, function($a, $b) { return $a['date'] <=> $b['date']; });
        else usort($list, function($a, $b) { return $b['date'] <=> $a['date']; });
        $total = count($list);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
        $paged = array_slice($list, ($page - 1) * $perPage, $perPage);
        appJson(['success' => true, 'data' => ['tasks' => $paged, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'complete_task':
    case 'cancel_task':
        $taskId = appStrictId($_POST['task_id'] ?? '', 'task_id');
        $changed = false;
        $newStatus = $action === 'complete_task' ? 'completed' : 'cancelled';
        Database::updateClassData($classId, 'tasks', function($tasks) use ($taskId, $newStatus, &$changed) {
            if (!isset($tasks[$taskId]) || ($tasks[$taskId]['status'] ?? '') !== 'pending') return null;
            $tasks[$taskId]['status'] = $newStatus;
            $changed = true;
            return $tasks;
        });
        if (!$changed) appError('任务不存在或状态不可修改', null, 409);
        if ($newStatus === 'completed') {
            Database::update('settings.json', function($settings) use ($classId, $taskId) { $settings['last_task_id_' . $classId] = $taskId; return $settings; });
        }
        appJson(['success' => true, 'data' => ['id' => $taskId, 'status' => $newStatus]]);

    case 'get_wrong_words':
        $user = Database::getUser($userId);
        $map = [];
        foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $wrongMap = $user['wrong_words'][$classId] ?? [];
        $list = [];
        foreach ($wrongMap as $wid => $info) if (isset($map[$wid])) {
            $list[] = array_merge(appWordOut($map[$wid]), ['word_id' => $wid, 'marked_at' => $info['marked_at'] ?? '']);
        }
        $total = count($list);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_POST['per_page'] ?? 20)));
        $paged = array_slice($list, ($page - 1) * $perPage, $perPage);
        appJson(['success' => true, 'data' => ['words' => $paged, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'get_completed_tasks':
        $words = Database::getWords($classId); $map = [];
        foreach ($words as $word) $map[(string)$word['id']] = $word;
        $list = [];
        foreach (Database::getTasks($classId) as $tid => $task) {
            if (($task['status'] ?? '') !== 'completed') continue;
            $taskWords = [];
            foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid])) $taskWords[] = appWordOut($map[$wid]);
            $list[] = ['id' => $task['id'] ?? $tid, 'date' => $task['date'], 'label' => $task['label'] ?? '', 'words' => $taskWords];
        }
        usort($list, function($a, $b) { return $b['date'] <=> $a['date']; });
        appJson(['success' => true, 'data' => $list]);

    case 'search_all':
        $query = trim(reqPost('query'));
        if ($query === '') appError('参数不全');
        $words = Database::getWords($classId); $map = []; $wordMatches = [];
        foreach ($words as $word) {
            $map[(string)$word['id']] = $word;
            if (mb_stripos($word['word'], $query) !== false || mb_stripos($word['meaning'], $query) !== false) $wordMatches[] = array_merge(appWordOut($word), ['matched' => 'word']);
        }
        $pending = []; $history = [];
        foreach (Database::getTasks($classId) as $tid => $task) {
            $matched = [];
            foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid]) && (mb_stripos($map[$wid]['word'], $query) !== false || mb_stripos($map[$wid]['meaning'], $query) !== false)) $matched[] = array_merge(appWordOut($map[$wid]), ['matched' => 'word']);
            if (!$matched) continue;
            $item = ['id' => $task['id'] ?? $tid, 'date' => $task['date'], 'label' => $task['label'] ?? '', 'status' => $task['status'] ?? '', 'matched_words' => $matched, 'matched' => 'word'];
            if (($task['status'] ?? '') === 'pending') { $item['type'] = 'pending_task'; $pending[] = $item; }
            elseif (in_array($task['status'] ?? '', ['completed', 'cancelled'], true)) { $item['type'] = 'history_task'; $history[] = $item; }
        }
        usort($history, function($a, $b) { return $b['date'] <=> $a['date']; });
        $total = count($wordMatches) + count($pending) + count($history);
        $data = [
            'words' => ['items' => array_slice($wordMatches, 0, 10), 'total' => count($wordMatches)],
            'pending_tasks' => ['items' => array_slice($pending, 0, 10), 'total' => count($pending)],
            'history_tasks' => ['items' => array_slice($history, 0, 10), 'total' => count($history)],
            'total' => $total,
            'has_more' => count($wordMatches) > 10 || count($pending) > 10 || count($history) > 10
        ];
        appJson(['success' => true, 'data' => $data]);

    case 'export_words_pdf':
        $taskId = appStrictId($_POST['task_id'] ?? '', 'task_id');
        $tasks = Database::getTasks($classId);
        if (!isset($tasks[$taskId])) appError('任务不存在');
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $rows = ''; $count = 0;
        foreach (($tasks[$taskId]['word_ids'] ?? []) as $wid) if (isset($map[$wid])) { $count++; $rows .= '<tr><td>' . htmlspecialchars($map[$wid]['word'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($map[$wid]['meaning'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($map[$wid]['pos'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>'; }
        $date = htmlspecialchars($tasks[$taskId]['date'] ?? '', ENT_QUOTES, 'UTF-8'); $label = htmlspecialchars($tasks[$taskId]['label'] ?? '', ENT_QUOTES, 'UTF-8');
        $html = "<!doctype html><html><head><meta charset='utf-8'><style>body{font-family:\"Noto Sans SC\",\"PingFang SC\",\"Microsoft YaHei\",sans-serif;color:#222;line-height:1.8}h1{text-align:center}.meta{text-align:center;color:#666;margin-bottom:24px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #bbb;text-align:left}th{background:#eee}</style></head><body><h1>单词表 - $label</h1><div class='meta'>日期：$date　词数：$count</div><table><thead><tr><th>单词</th><th>释义</th><th>词性</th></tr></thead><tbody>$rows</tbody></table></body></html>";
        $directory = Database::getExportsDirectory();
        if (!is_dir($directory)) mkdir($directory, 0750, true);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) appError('无法创建导出目录', 'IO_ERROR', 500);
        $token = bin2hex(random_bytes(32));
        $storedName = bin2hex(random_bytes(24)) . '.html';
        $safeName = historyExportSafeFilename('单词表-' . ($tasks[$taskId]['date'] ?? '') . '.html');
        if (file_put_contents($directory . '/' . $storedName, $html, LOCK_EX) === false) appError('导出文件保存失败', 'IO_ERROR', 500);
        $expires = time() + 600;
        $hash = hash('sha256', $token);
        try {
            Database::update('exports.json', function($exports) use ($hash, $userId, $classId, $expires, $storedName, $safeName) {
                foreach ($exports as $key => $record) if ((int)($record['expires_at'] ?? 0) <= time()) unset($exports[$key]);
                $exports[$hash] = ['owner_user' => $userId, 'owner_class' => $classId, 'expires_at' => $expires, 'path' => $storedName, 'filename' => $safeName];
                return $exports;
            });
        } catch (Throwable $e) { @unlink($directory . '/' . $storedName); appError($e->getMessage(), 'DB_ERROR', 500); }
        appJson(['success' => true, 'download_url' => 'download.php?token=' . rawurlencode($token) . '&type=html']);

    case 'export_personal_history':
        $start = trim(reqPost('start_date')); $end = trim(reqPost('end_date'));
        if (($start !== '' && !historyStrictDate($start)) || ($end !== '' && !historyStrictDate($end)) || ($start !== '' && $end !== '' && $start > $end)) appError('日期范围无效');
        $entries = historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $userId));
        $body = ''; $count = 0;
        foreach ($entries as $dateKey => $entry) {
            if ($dateKey === date('Y-m-d') || ($start !== '' && $dateKey < $start) || ($end !== '' && $dateKey > $end)) continue;
            $count++; $body .= '<section><h2>' . htmlspecialchars($dateKey, ENT_QUOTES, 'UTF-8') . '</h2>' . $entry['content'] . '</section>';
        }
        if (!$count) appError('没有可导出的记录');
        $body = historyRewriteImageUrlsToBase64($body);
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:"Noto Sans SC","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.8;max-width:800px;margin:40px auto;padding:20px}h1{text-align:center}section{margin-bottom:24px}img{max-width:100%;border-radius:6px}</style></head><body><h1>个人列传</h1>' . $body . '</body></html>';
        $directory = Database::getExportsDirectory();
        if (!is_dir($directory)) mkdir($directory, 0750, true);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) appError('无法创建导出目录', 'IO_ERROR', 500);
        $token = bin2hex(random_bytes(32));
        $storedName = bin2hex(random_bytes(24)) . '.html';
        $safeName = historyExportSafeFilename('个人列传.html');
        if (file_put_contents($directory . '/' . $storedName, $html, LOCK_EX) === false) appError('导出文件保存失败', 'IO_ERROR', 500);
        $expires = time() + 600;
        $hash = hash('sha256', $token);
        try {
            Database::update('exports.json', function($exports) use ($hash, $userId, $classId, $expires, $storedName, $safeName) {
                foreach ($exports as $key => $record) if ((int)($record['expires_at'] ?? 0) <= time()) unset($exports[$key]);
                $exports[$hash] = ['owner_user' => $userId, 'owner_class' => $classId, 'expires_at' => $expires, 'path' => $storedName, 'filename' => $safeName];
                return $exports;
            });
        } catch (Throwable $e) { @unlink($directory . '/' . $storedName); appError($e->getMessage(), 'DB_ERROR', 500); }
        appJson(['success' => true, 'download_url' => 'download.php?token=' . rawurlencode($token) . '&type=html']);

    case 'get_class_history':
        $month = trim(reqPost('month'));
        if ($month !== '' && !preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)) appError('month 格式无效');
        $history = historySanitizeEntries(Database::getClassData($classId, 'history'));
        if ($month !== '') $history = array_filter($history, function($entryDate) use ($month) { return strncmp($entryDate, $month . '-', 8) === 0; }, ARRAY_FILTER_USE_KEY);
        appJson(['success' => true, 'data' => $history]);

    case 'get_personal_history':
        $month = trim(reqPost('month'));
        if ($month !== '' && !preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)) appError('month 格式无效');
        $history = historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $userId));
        if ($month !== '') $history = array_filter($history, function($entryDate) use ($month) { return strncmp($entryDate, $month . '-', 8) === 0; }, ARRAY_FILTER_USE_KEY);
        appJson(['success' => true, 'data' => $history]);

    case 'get_authorized_vlogs':
        $month = trim(reqPost('month'));
        if ($month !== '' && !preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)) appError('month 格式无效');
        $result = [];
        foreach (Database::getAllUsers() as $uid => $u) {
            if ((string)$uid === $userId) continue;
            $consentMap = $u['consent_map'] ?? [];
            $allowed = isset($consentMap[$classId]) ? (bool)$consentMap[$classId] : (!empty($u['consent']) ? true : false);
            if (!$allowed || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', (string)$uid)) continue;
            $personalHistory = historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $uid));
            if ($month !== '') $personalHistory = array_filter($personalHistory, function($entryDate) use ($month) { return strncmp($entryDate, $month . '-', 8) === 0; }, ARRAY_FILTER_USE_KEY);
            if (empty($personalHistory)) continue;
            $result[] = ['author_uid' => (string)$uid, 'author_name' => (string)($u['name'] ?? $uid), 'entries' => $personalHistory];
        }
        appJson(['success' => true, 'data' => $result]);

    case 'save_personal_history':
        $date = trim(reqPost('date')); $content = reqPost('content');
        if (!historyIsEditableDate($date)) appError('只能保存今天的史记');
        try {
            $content = historySanitizeHtml($content);
        } catch (LengthException $e) {
            appError('内容过长');
        } catch (Exception $e) {
            appError('内容格式无效');
        }
        $delta = reqPost('delta');
        if ($delta !== '' && strlen($delta) <= 2097152) {
            json_decode($delta, true);
            if (json_last_error() !== JSON_ERROR_NONE) $delta = '';
        } elseif ($delta !== '') {
            $delta = '';
        }
        $entry = historyNormalizeEntry([
            'content' => $content,
            'delta' => $delta,
            'title' => $_POST['title'] ?? '',
            'mood' => $_POST['mood'] ?? historyDefaultMood(),
            'weather' => $_POST['weather'] ?? historyDefaultWeather(),
            'location' => $_POST['location'] ?? '',
            'tags' => reqPost('tags') !== '' ? json_decode(reqPost('tags'), true) : [],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $entry['content'] = $content;
        $entry['updated_at'] = date('Y-m-d H:i:s');
        Database::updateClassData($classId, 'personal_history_' . $userId, function($history) use ($date, $entry) {
            $history[$date] = $entry;
            return $history;
        });
        appJson(['success' => true, 'data' => ['entry' => $entry]]);

    case 'upload_image':
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) appError('请选择图片');
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            appError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? '图片超过服务器上传限制' : '图片上传失败');
        }
        if (($file['size'] ?? 0) <= 0) appError('上传文件无效');
        if (!is_uploaded_file($file['tmp_name'])) appError('上传文件无效');
        // 按类型分段限值（与 save_gallery / gallery.php / upload.php 一致）：GIF 16MB / MP4 15MB / 图片 12MB
        $isGifUp = false;
        $isMp4Up = false;
        if (is_file($file['tmp_name'])) {
            $infoUp = @getimagesize($file['tmp_name']);
            $isGifUp = is_array($infoUp) && ($infoUp[2] ?? 0) === IMAGETYPE_GIF;
            if (!$isGifUp) $isMp4Up = Database::isMp4File($file['tmp_name']);
        }
        if ($isMp4Up) {
            if (($file['size'] ?? 0) > Database::MP4_MAX_BYTES) appError('视频最大 15MB', null, 413);
        } else {
            $maxBytesUp = $isGifUp ? Database::GIF_MAX_BYTES : Database::UPLOAD_MAX_BYTES;
            if (($file['size'] ?? 0) > $maxBytesUp) appError($isGifUp ? 'GIF 动图最大 16MB' : '图片最大 12MB', null, 413);
        }

        try {
            $filename = Database::saveUploadedImage($classId, $file['tmp_name']);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $code = (str_contains($msg, '像素') || str_contains($msg, '12MB') || str_contains($msg, '15MB') || str_contains($msg, '16MB') || str_contains($msg, '秒') || str_contains($msg, '分辨率')) ? 413 : 500;
            appError($msg, null, $code);
        }

        appJson(['success' => true, 'data' => ['url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($filename)]]);

    // ===== 全球发音 =====
    // pronunciations.json 结构: {wordId: {uid: {file,name,duration,size,uploaded_at}}}
    // 音频文件: data/classes/{cid}/pronunciations/{wordId}/{32hex}.m4a（文件名随机不可枚举，输出端点无需鉴权）

    case 'get_pronunciations':
        $wordId = reqPost('word_id');
        if (!is_string($wordId) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $wordId)) appError('单词参数无效');
        $pron = Database::getClassData($classId, 'pronunciations');
        $wordPron = (is_array($pron) && isset($pron[$wordId]) && is_array($pron[$wordId])) ? $pron[$wordId] : [];
        $items = [];
        foreach ($wordPron as $uid => $p) {
            if (!is_array($p) || empty($p['file'])) continue;
            if (!is_file(Database::getClassDir($classId) . '/pronunciations/' . $wordId . '/' . $p['file'])) continue; // 自愈跳过已丢文件
            $items[] = [
                'uid' => (string)$uid,
                'name' => (string)($p['name'] ?? '同学'),
                'duration' => (float)($p['duration'] ?? 0),
                'size' => (int)($p['size'] ?? 0),
                'uploaded_at' => (string)($p['uploaded_at'] ?? ''),
                'url' => 'pronunciation.php?class_id=' . rawurlencode($classId) . '&word_id=' . rawurlencode($wordId) . '&file=' . rawurlencode($p['file']),
            ];
        }
        appJson(['success' => true, 'data' => ['items' => $items, 'mine' => isset($wordPron[(string)$userId])]]);

    case 'upload_pronunciation':
        appRateLimit('pron_up', $userId, 20, 3600);
        $wordId = reqPost('word_id');
        if (!is_string($wordId) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $wordId)) appError('单词参数无效');
        // 单词必须存在
        $wordExists = false;
        foreach (Database::getWords($classId) as $w) {
            if (($w['id'] ?? '') === $wordId) { $wordExists = true; break; }
        }
        if (!$wordExists) appError('单词不存在');
        if (!isset($_FILES['audio']) || !is_array($_FILES['audio'])) appError('请选择录音');
        $audio = $_FILES['audio'];
        if (($audio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) appError('录音上传失败');
        if (!is_uploaded_file($audio['tmp_name'])) appError('上传文件无效');

        require_once 'inc/audio_guard.php';
        try {
            $info = AudioGuard::validateM4a($audio['tmp_name'], 2 * 1048576); // ≤2MB / ≤10s / M4A magic
        } catch (RuntimeException $e) {
            appError($e->getMessage(), null, 413);
        }

        $dir = Database::getClassDir($classId) . '/pronunciations/' . $wordId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) appError('存储目录创建失败');
        $oldFile = null;
        $pronOld = Database::getClassData($classId, 'pronunciations');
        if (isset($pronOld[$wordId][(string)$userId]['file'])) $oldFile = $pronOld[$wordId][(string)$userId]['file'];

        $newFile = bin2hex(random_bytes(16)) . '.m4a';
        $dest = $dir . '/' . $newFile;
        if (!@move_uploaded_file($audio['tmp_name'], $dest)) appError('录音保存失败');
        $userName = (string)(Database::getUser($userId)['name'] ?? '同学');
        $ok = Database::updateClassData($classId, 'pronunciations', function($latest) use ($wordId, $userId, $newFile, $userName, $info) {
            if (!is_array($latest)) $latest = [];
            if (!isset($latest[$wordId]) || !is_array($latest[$wordId])) $latest[$wordId] = [];
            $latest[$wordId][(string)$userId] = [
                'file' => $newFile,
                'name' => $userName,
                'duration' => $info['duration'],
                'size' => $info['size'],
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];
            return $latest;
        });
        if ($ok === false) { @unlink($dest); appError('数据保存失败，请重试'); }
        if ($oldFile && $oldFile !== $newFile) @unlink($dir . '/' . $oldFile); // 重录覆盖：清理旧文件
        appJson(['success' => true, 'data' => [
            'duration' => $info['duration'],
            'url' => 'pronunciation.php?class_id=' . rawurlencode($classId) . '&word_id=' . rawurlencode($wordId) . '&file=' . rawurlencode($newFile),
        ]]);

    case 'delete_pronunciation':
        $wordId = reqPost('word_id');
        if (!is_string($wordId) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $wordId)) appError('单词参数无效');
        $oldFile = null;
        $ok = Database::updateClassData($classId, 'pronunciations', function($latest) use ($wordId, $userId, &$oldFile) {
            if (!is_array($latest) || !isset($latest[$wordId][(string)$userId])) return null; // 无记录 → 保持原样
            $oldFile = $latest[$wordId][(string)$userId]['file'] ?? null;
            unset($latest[$wordId][(string)$userId]);
            if (empty($latest[$wordId])) unset($latest[$wordId]);
            return $latest;
        });
        if ($ok === false) appError('删除失败，请重试');
        if ($oldFile) @unlink(Database::getClassDir($classId) . '/pronunciations/' . $wordId . '/' . $oldFile);
        appJson(['success' => true]);

    case 'set_consent':
        $allow = reqPost('consent', reqPost('allow', '0')) === '1';
        Database::updateUser($userId, function($data) use ($classId, $allow) {
            if (!isset($data['consent_map']) || !is_array($data['consent_map'])) {
                $data['consent_map'] = [];
            }
            $data['consent_map'][$classId] = $allow;
            $data['consent'] = $allow;
            return $data;
        });
        appJson(['success' => true, 'allow' => $allow, 'data' => ['consent' => $allow, 'class_id' => $classId]]);

    case 'get_consent':
        $user = Database::getUser($userId);
        $consentMap = $user['consent_map'] ?? [];
        $allow = isset($consentMap[$classId]) ? (bool)$consentMap[$classId] : (!empty($user['consent']) ? true : false);
        appJson(['success' => true, 'allow' => $allow, 'data' => ['consent' => $allow, 'class_id' => $classId]]);


    case 'unmark_wrong':
        $wordId = appStrictId($_POST['word_id'] ?? '', 'word_id');
        Database::updateUser($userId, function($data) use ($classId, $wordId) {
            if (isset($data['wrong_words'][$classId][$wordId])) {
                unset($data['wrong_words'][$classId][$wordId]);
            }
            return $data;
        });
        appJson(['success' => true, 'data' => ['is_wrong' => false]]);

    case 'toggle_favorite':
        $wordId = appStrictId($_POST['word_id'] ?? '', 'word_id');
        $exists = false;
        foreach (Database::getWords($classId) as $word) if ((string)$word['id'] === $wordId) { $exists = true; break; }
        if (!$exists) appError('单词不存在');
        $isFav = false;
        Database::updateUser($userId, function($data) use ($wordId, &$isFav) {
            if (!isset($data['favorites'])) $data['favorites'] = [];
            if (in_array($wordId, $data['favorites'], true)) {
                $data['favorites'] = array_values(array_diff($data['favorites'], [$wordId]));
                $isFav = false;
            } else {
                $data['favorites'][] = $wordId;
                $isFav = true;
            }
            return $data;
        });
        appJson(['success' => true, 'data' => ['is_favorite' => $isFav]]);

    case 'get_favorites':
        $user = Database::getUser($userId);
        $favIds = $user['favorites'] ?? [];
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $list = [];
        foreach ($favIds as $wid) if (isset($map[$wid])) {
            $list[] = ['id' => $wid, 'word' => $map[$wid]['word'], 'meaning' => $map[$wid]['meaning'], 'pos' => $map[$wid]['pos'] ?? '', 'is_favorite' => true];
        }
        appJson(['success' => true, 'data' => ['words' => $list, 'total' => count($list)]]);

    case 'get_task_detail':
        $taskId = appStrictId($_POST['task_id'] ?? '', 'task_id');
        $tasks = Database::getTasks($classId);
        if (!isset($tasks[$taskId])) appError('任务不存在');
        $task = $tasks[$taskId];
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $user = Database::getUser($userId);
        $favIds = $user['favorites'] ?? [];
        $wrongMap = $user['wrong_words'][$classId] ?? [];
        $words = [];
        foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid])) {
            $words[] = array_merge(appWordOut($map[$wid]), [
                'is_favorite' => in_array($wid, $favIds, true),
                'is_wrong' => isset($wrongMap[$wid]),
            ]);
        }
        appJson(['success' => true, 'data' => [
            'id' => $task['id'] ?? $taskId, 'date' => $task['date'] ?? '', 'label' => $task['label'] ?? '',
            'status' => $task['status'] ?? '', 'words' => $words, 'word_count' => count($words),
            'weekend_week' => $task['weekend_week'] ?? '', 'created_at' => $task['created_at'] ?? '',
        ]]);

    case 'export_task_csv':
    case 'export_task_text':
        $taskId = appStrictId($_POST['task_id'] ?? '', 'task_id');
        $tasks = Database::getTasks($classId);
        if (!isset($tasks[$taskId])) appError('任务不存在');
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $csvRows = ["ï»¿单词,释义,词性"];
        $textLines = [];
        $csvSafe = function($v) { $v = trim((string)$v); if ($v !== '' && preg_match('/^[=+\-@]/', $v)) $v = "'" . $v; return $v; };
        foreach (($tasks[$taskId]['word_ids'] ?? []) as $wid) if (isset($map[$wid])) {
            $csvRows[] = '"' . str_replace('"', '""', $csvSafe($map[$wid]['word'])) . '","' . str_replace('"', '""', $csvSafe($map[$wid]['meaning'])) . '","' . $csvSafe($map[$wid]['pos'] ?? '') . '"';
            $textLines[] = $map[$wid]['word'] . ' ' . $map[$wid]['meaning'] . ' ' . ($map[$wid]['pos'] ?? '');
        }
        if (!count($textLines)) appError('任务中没有可导出的单词');
        if ($action === 'export_task_csv') {
            $csv = implode("\n", $csvRows);
            $directory = Database::getExportsDirectory();
        if (!is_dir($directory)) mkdir($directory, 0750, true);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) appError('无法创建导出目录', 'IO_ERROR', 500);
            $token = bin2hex(random_bytes(32));
            $storedName = bin2hex(random_bytes(24)) . '.csv';
            $safeName = $classId . '-' . ($tasks[$taskId]['label'] ?? 'task') . '.csv';
            if (file_put_contents($directory . '/' . $storedName, $csv, LOCK_EX) === false) appError('导出文件保存失败', 'IO_ERROR', 500);
            $expires = time() + 600;
            $hash = hash('sha256', $token);
            Database::update('exports.json', function($exports) use ($hash, $userId, $classId, $expires, $storedName, $safeName) {
                foreach ($exports as $key => $record) if ((int)($record['expires_at'] ?? 0) <= time()) unset($exports[$key]);
                $exports[$hash] = ['owner_user' => $userId, 'owner_class' => $classId, 'expires_at' => $expires, 'path' => $storedName, 'filename' => $safeName];
                return $exports;
            });
            appJson(['success' => true, 'data' => ['download_url' => 'download.php?token=' . rawurlencode($token) . '&type=csv', 'filename' => $safeName]]);
        } else {
            appJson(['success' => true, 'data' => ['text' => implode("\n", $textLines)]]);
        }

    case 'export_wrong_csv':
    case 'export_wrong_text':
        $user = Database::getUser($userId);
        $wrongMap = $user['wrong_words'][$classId] ?? [];
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $csvRows = ["ï»¿单词,释义,词性"];
        $textLines = [];
        $csvSafe = function($v) { $v = trim((string)$v); if ($v !== '' && preg_match('/^[=+\-@]/', $v)) $v = "'" . $v; return $v; };
        foreach ($wrongMap as $wid => $info) if (isset($map[$wid])) {
            $csvRows[] = '"' . str_replace('"', '""', $csvSafe($map[$wid]['word'])) . '","' . str_replace('"', '""', $csvSafe($map[$wid]['meaning'])) . '","' . $csvSafe($map[$wid]['pos'] ?? '') . '"';
            $textLines[] = $map[$wid]['word'] . ' ' . $map[$wid]['meaning'] . ' ' . ($map[$wid]['pos'] ?? '');
        }
        if (!count($textLines)) appError('错题本为空，无可导出内容');
        if ($action === 'export_wrong_csv') {
            $csv = implode("\n", $csvRows);
            $directory = Database::getExportsDirectory();
        if (!is_dir($directory)) mkdir($directory, 0750, true);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) appError('无法创建导出目录', 'IO_ERROR', 500);
            $token = bin2hex(random_bytes(32));
            $storedName = bin2hex(random_bytes(24)) . '.csv';
            $safeName = $classId . '-错题本-' . date('Ymd') . '.csv';
            if (file_put_contents($directory . '/' . $storedName, $csv, LOCK_EX) === false) appError('导出文件保存失败', 'IO_ERROR', 500);
            $expires = time() + 600;
            $hash = hash('sha256', $token);
            Database::update('exports.json', function($exports) use ($hash, $userId, $classId, $expires, $storedName, $safeName) {
                foreach ($exports as $key => $record) if ((int)($record['expires_at'] ?? 0) <= time()) unset($exports[$key]);
                $exports[$hash] = ['owner_user' => $userId, 'owner_class' => $classId, 'expires_at' => $expires, 'path' => $storedName, 'filename' => $safeName];
                return $exports;
            });
            appJson(['success' => true, 'data' => ['download_url' => 'download.php?token=' . rawurlencode($token) . '&type=csv', 'filename' => $safeName]]);
        } else {
            appJson(['success' => true, 'data' => ['text' => implode("\n", $textLines)]]);
        }

    case 'get_gallery':
        $gallery = Database::getClassData($classId, 'gallery');
        if (!is_array($gallery)) $gallery = [];
        $valid = [];
        $cleaned = false;
        foreach ($gallery as $item) {
            if (($item['image'] ?? '') !== '' && Database::getUploadedImagePath($classId, $item['image']) !== null) {
                $valid[] = $item;
            } else { $cleaned = true; }
        }
        if ($cleaned) {
            // 清理在文件锁内进行，避免与 save_gallery 并发时用旧数组覆盖丢条目
            Database::updateClassData($classId, 'gallery', function($latest) use ($classId) {
                if (!is_array($latest)) return null;
                $valid = [];
                foreach ($latest as $item) {
                    if (($item['image'] ?? '') !== '' && Database::getUploadedImagePath($classId, $item['image']) !== null) {
                        $valid[] = $item;
                    }
                }
                return $valid;
            });
            $gallery = $valid;
        }
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
        $total = count($gallery);
        $items = array_slice($gallery, ($page - 1) * $perPage, $perPage);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $result = [];
        foreach ($items as $item) {
            $imgFile = (string)($item['image'] ?? '');
            $ext = strtolower(pathinfo($imgFile, PATHINFO_EXTENSION));
            $isMp4 = $ext === 'mp4';
            $result[] = [
                'id' => $item['id'] ?? '',
                'image_url' => $base . '/upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($imgFile),
                'type' => $ext === 'gif' ? 'gif' : ($isMp4 ? 'mp4' : 'static'),
                'description' => (string)($item['description'] ?? ''),
                'uploaded_at' => (string)($item['uploaded_at'] ?? ''),
                // MP4 首帧缩略图：APP 端视频卡片直接显示该图（缓存命中秒开），点进去才加载真视频
                'thumb_url' => $isMp4 ? ($base . '/video_thumb.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($imgFile)) : null,
            ];
        }
        appJson(['success' => true, 'data' => ['items' => $result, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'save_gallery':
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) appError('请选择图片');
        $img = $_FILES['image'];
        if (($img['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) appError('图片上传失败');
        $tmpName = $img['tmp_name'] ?? '';
        $isGif = false;
        $isMp4 = false;
        if (is_string($tmpName) && is_file($tmpName)) {
            $info = @getimagesize($tmpName);
            $isGif = is_array($info) && ($info[2] ?? 0) === IMAGETYPE_GIF;
            if (!$isGif) $isMp4 = Database::isMp4File($tmpName);
        }
        if ($isMp4) {
            if (($img['size'] ?? 0) > Database::MP4_MAX_BYTES) appError('视频最大 15MB', null, 413);
        } else {
            $maxBytes = $isGif ? Database::GIF_MAX_BYTES : Database::UPLOAD_MAX_BYTES;
            if (($img['size'] ?? 0) > $maxBytes) appError($isGif ? 'GIF 动图最大 16MB' : '图片最大 12MB', null, 413);
        }
        $desc = sanitizePlainText(reqPost('description'));
        if ($desc === '' || mb_strlen($desc) > 500) appError('描述不能为空且不超过500字');
        try {
            $fname = Database::saveUploadedImage($classId, $img['tmp_name']);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            appError($msg, null, (str_contains($msg, '像素') || str_contains($msg, '12MB') || str_contains($msg, '15MB') || str_contains($msg, '16MB') || str_contains($msg, '秒') || str_contains($msg, '分辨率')) ? 413 : 500);
        }
        $id = bin2hex(random_bytes(16));
        // 首帧缩略图由 CLI 计划任务 cron_gallery_thumbs.php 生成（FPM 无法 exec，此处不内联调用）
        try {
            $res = Database::updateClassData($classId, 'gallery', function($latest) use ($id, $fname, $desc) {
                if (!is_array($latest)) $latest = [];
                array_unshift($latest, ['id' => $id, 'image' => $fname, 'description' => $desc, 'uploaded_at' => date('Y-m-d H:i:s')]);
                return $latest;
            });
            if ($res === false) throw new RuntimeException('数据保存失败');
        } catch (Throwable $e) {
            // 写库失败：回滚已落盘的文件与缩略图，避免孤儿文件泄漏
            Database::deleteUploadedImage($classId, $fname);
            Database::deleteVideoThumb($classId, $fname);
            appError('保存失败，请重试');
        }
        appJson(['success' => true, 'data' => ['id' => $id]]);

    case 'delete_gallery':
        $gid = appStrictId($_POST['id'] ?? '', 'id');
        Database::updateClassData($classId, 'gallery', function($latest) use ($gid, $classId) {
            if (!is_array($latest)) return null;
            foreach ($latest as $i => $item) if (($item['id'] ?? '') === $gid) {
                Database::deleteUploadedImage($classId, $item['image'] ?? '');
                Database::deleteVideoThumb($classId, $item['image'] ?? '');
                array_splice($latest, $i, 1);
                return $latest;
            }
            return null;
        });
        appJson(['success' => true]);

    case 'update_gallery':
        $gid = appStrictId(reqPost('id'), 'id');
        $desc = sanitizePlainText(reqPost('description'));
        if ($desc === '' || mb_strlen($desc) > 500) appError('描述不能为空且不超过500字');
        $updated = false;
        Database::updateClassData($classId, 'gallery', function($latest) use ($gid, $desc, &$updated) {
            if (!is_array($latest)) return null;
            foreach ($latest as $i => $item) if (($item['id'] ?? '') === $gid) {
                $latest[$i]['description'] = $desc;
                $updated = true;
                return $latest;
            }
            return null;
        });
        appJson(['success' => $updated]);


    default:
        appError('未知 action: ' . $action);
}