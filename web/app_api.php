<?php

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

$action = trim((string)($_POST['action'] ?? ''));
if ($action === '') appError('缺少 action 参数');

// 获取客户端 IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

// 限流辅助: 超限时直接返回 429
function appRateLimit($bucket, $identity, $max, $windowSec) {
    list($allow, $retry) = RateLimiter::check($bucket, $identity, $max, $windowSec);
    if (!$allow) {
        header('Retry-After: ' . $retry);
        appError('操作过于频繁，请' . $retry . '秒后再试', 'RATE_LIMITED', 429);
    }
}

if ($action === 'register' || $action === 'claim_legacy') {
    // 限流: 登录类操作 10次/5分钟
    appRateLimit('login', $clientIp, 10, 300);
    $name = appUsername();
    $password = (string)($_POST['password'] ?? '');
    appValidateCredentials($name, $password);
    $response = null;
    Database::update('app_data.json', function($data) use ($action, $name, $password, &$response) {
        $data = appDataDefaults($data);
        $uid = appFindUserId($data, $name);
        if ($uid !== null && !empty($data['users'][$uid]['password_hash'])) {
            $response = ['success' => false, 'error' => '用户名已存在'];
            return null;
        }
        if ($uid !== null && $action === 'register') {
            // 安全折中：旧账号仅凭同名可一次性设置密码，以保留原有学习数据。
            $data['users'][$uid]['legacy_claimed_at'] = date('Y-m-d H:i:s');
        } elseif ($uid === null) {
            if ($action === 'claim_legacy') {
                $response = ['success' => false, 'error' => '旧用户不存在'];
                return null;
            }
            do { $uid = 'u' . $data['next_uid']++; } while (isset($data['users'][$uid]));
            $data['users'][$uid] = ['name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'class_ids' => [], 'class_auth_versions' => [], 'wrong_words' => []];
        }
        $data['users'][$uid]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        list($token, $expires) = appNewToken($data, $uid);
        $response = ['success' => true, 'data' => ['user_id' => $uid, 'name' => $name, 'class_ids' => $data['users'][$uid]['class_ids'] ?? [], 'token' => $token, 'expires_at' => $expires, 'is_new' => empty($data['users'][$uid]['legacy_claimed_at'])]];
        return $data;
    });
    if ($response === null) appError('保存失败', null, 500);
    appJson($response, !empty($response['success']) ? 200 : 409);
}

if ($action === 'login') {
    // 限流: 登录类操作 10次/5分钟
    appRateLimit('login', $clientIp, 10, 300);
    $name = appUsername();
    $password = (string)($_POST['password'] ?? '');
    appValidateCredentials($name, $password);
    $data = appDataDefaults(Database::read('app_data.json'));
    $uid = appFindUserId($data, $name);
    // 用户不存在 → 自动注册（合并登录/注册入口，新手友好）
    if ($uid === null) {
        $response = null;
        Database::update('app_data.json', function($latest) use ($name, $password, &$response) {
            $latest = appDataDefaults($latest);
            $existing = appFindUserId($latest, $name);
            if ($existing !== null && !empty($latest['users'][$existing]['password_hash'])) {
                $response = ['success' => false, 'error' => '用户名已存在'];
                return null;
            }
            do { $uid = 'u' . $latest['next_uid']++; } while (isset($latest['users'][$uid]));
            $latest['users'][$uid] = ['name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'class_ids' => [], 'class_auth_versions' => [], 'wrong_words' => []];
            $latest['users'][$uid]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            list($token, $expires) = appNewToken($latest, $uid);
            $response = ['success' => true, 'data' => ['user_id' => $uid, 'name' => $name, 'class_ids' => [], 'token' => $token, 'expires_at' => $expires, 'is_new' => true]];
            return $latest;
        });
        if ($response === null) appError('注册失败', null, 500);
        if (!empty($response['success'])) appJson($response);
        appJson($response, 409);
    }
    if (empty($data['users'][$uid]['password_hash'])) {
        // 旧用户首次设置密码
        Database::update('app_data.json', function($latest) use ($uid, $password, &$response) {
            $latest = appDataDefaults($latest);
            if (!isset($latest['users'][$uid])) return null;
            $latest['users'][$uid]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $latest['users'][$uid]['legacy_claimed_at'] = date('Y-m-d H:i:s');
            list($token, $expires) = appNewToken($latest, $uid);
            $user = $latest['users'][$uid];
            $response = ['success' => true, 'data' => ['user_id' => $uid, 'name' => $user['name'], 'class_ids' => $user['class_ids'] ?? [], 'token' => $token, 'expires_at' => $expires, 'is_new' => false]];
            return $latest;
        });
        if ($response === null) appError('登录失败', null, 500);
        appJson($response);
    }
    if (!password_verify($password, $data['users'][$uid]['password_hash'])) appError('用户名或密码错误', null, 401);
    $response = null;
    Database::update('app_data.json', function($latest) use ($uid, &$response) {
        $latest = appDataDefaults($latest);
        if (!isset($latest['users'][$uid])) return null;
        list($token, $expires) = appNewToken($latest, $uid);
        $user = $latest['users'][$uid];
        $response = ['success' => true, 'data' => ['user_id' => $uid, 'name' => $user['name'], 'class_ids' => $user['class_ids'] ?? [], 'token' => $token, 'expires_at' => $expires, 'is_new' => false]];
        return $latest;
    });
    if ($response === null) appError('登录失败', null, 500);
    appJson($response);
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
    $data = appDataDefaults(Database::read('app_data.json'));
    $user = $data['users'][$userId] ?? [];
    appJson(['success' => true, 'data' => [
        'user_id' => $userId, 'name' => $user['name'] ?? '',
        'class_ids' => $user['class_ids'] ?? [],
        'consent_map' => $user['consent_map'] ?? (object)[],
    ]]);
}

if ($action === 'delete_account') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    Database::update('app_data.json', function($data) use ($userId, $tokenHash) {
        $data = appDataDefaults($data);
        unset($data['users'][$userId], $data['tokens'][$tokenHash]);
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
    $allow = (string)($_POST['consent'] ?? $_POST['allow'] ?? '0') === '1';
    Database::update('app_data.json', function($data) use ($userId, $allow) {
        $data = appDataDefaults($data);
        if (!isset($data['users'][$userId])) return null;
        $data['users'][$userId]['consent'] = $allow;
        if (!isset($data['users'][$userId]['consent_map']) || !is_array($data['users'][$userId]['consent_map'])) {
            $data['users'][$userId]['consent_map'] = [];
        }
        // 同步到已绑定的所有班级
        foreach (($data['users'][$userId]['class_ids'] ?? []) as $cid) {
            $data['users'][$userId]['consent_map'][$cid] = $allow;
        }
        return $data;
    });
    appJson(['success' => true, 'data' => ['consent' => $allow]]);
}

if ($action === 'check_version') {
    $current = trim((string)($_POST['current_version'] ?? '1.0'));
    // 兼容 1.0.0 vs 1.0
    if (preg_match('/^\d+\.\d+\.\d+$/', $current)) {
        // keep
    }
    $versions = Database::read('app_versions.json');
    if (!is_array($versions)) $versions = ['latest' => '1.0', 'history' => []];
    $latest = (string)($versions['latest'] ?? '1.0');
    $hasUpdate = version_compare($latest, $current, '>');
    // 若 latest=1.0 且 current=1.0.0，视为无更新
    if (!$hasUpdate && version_compare($current, $latest, '>')) {
        // APP 三位版本号可能高于两位服务端版本
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

if ($action === 'get_csrf_token') {
    list($userId, $authUser, $tokenHash) = appRequireAuth();
    // 为 APP 生成 CSRF token（基于 session 或随机生成）
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['app_csrf_' . $userId] = $csrf;
    appJson(['success' => true, 'data' => ['csrf_token' => $csrf]]);
}


list($userId, $authUser, $tokenHash) = appRequireAuth();

if ($action === 'logout') {
    Database::update('app_data.json', function($data) use ($tokenHash) {
        $data = appDataDefaults($data);
        unset($data['tokens'][$tokenHash]);
        return $data;
    });
    appJson(['success' => true]);
}

if ($action === 'bind_class') {
    // 限流: 班级口令尝试 10次/5分钟
    appRateLimit('bindpw', $clientIp, 10, 300);
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    $classes = Database::getClasses();
    if (!isset($classes[$classId])) appError('班级不存在');
    $hash = $classes[$classId]['password_hash'] ?? '';
    $classPassword = (string)($_POST['password'] ?? $_POST['class_password'] ?? '');
    if ($hash !== '' && !password_verify($classPassword, $hash)) appError('口令错误');
    $version = appClassVersion($classes[$classId]);
    Database::update('app_data.json', function($data) use ($userId, $classId, $version) {
        $data = appDataDefaults($data);
        if (!isset($data['users'][$userId])) return null;
        $ids = $data['users'][$userId]['class_ids'] ?? [];
        if (!in_array($classId, $ids, true)) $ids[] = $classId;
        $data['users'][$userId]['class_ids'] = $ids;
        $data['users'][$userId]['class_auth_versions'][$classId] = $version;
        if (!isset($data['users'][$userId]['wrong_words'][$classId])) $data['users'][$userId]['wrong_words'][$classId] = [];
        // 新绑定班级：若未单独设置过，继承全局 consent
        if (!isset($data['users'][$userId]['consent_map']) || !is_array($data['users'][$userId]['consent_map'])) {
            $data['users'][$userId]['consent_map'] = [];
        }
        if (!array_key_exists($classId, $data['users'][$userId]['consent_map'])) {
            $data['users'][$userId]['consent_map'][$classId] = !empty($data['users'][$userId]['consent']);
        }
        return $data;
    });
    appJson(['success' => true, 'data' => ['class_id' => $classId, 'class_name' => $classes[$classId]['name'], 'auth_version' => (int)($classes[$classId]['auth_version'] ?? 1)]]);
}

if ($action === 'unbind_class') {
    $classId = appStrictId($_POST['class_id'] ?? '', 'class_id');
    Database::update('app_data.json', function($data) use ($userId, $classId) {
        $data = appDataDefaults($data);
        $ids = $data['users'][$userId]['class_ids'] ?? [];
        $data['users'][$userId]['class_ids'] = array_values(array_filter($ids, function($id) use ($classId) { return $id !== $classId; }));
        unset($data['users'][$userId]['class_auth_versions'][$classId], $data['users'][$userId]['wrong_words'][$classId]);
        return $data;
    });
    appJson(['success' => true]);
}

$classActions = ['verify_class_password', 'get_words', 'search_word', 'add_word', 'ai_word', 'mark_wrong', 'unmark_wrong', 'toggle_favorite', 'get_favorites', 'get_tasks', 'get_task_detail', 'export_task_csv', 'export_task_text', 'complete_task', 'cancel_task', 'get_completed_tasks', 'search_all', 'export_words_pdf', 'export_wrong_csv', 'export_wrong_text', 'get_wrong_words', 'export_personal_history', 'get_class_history', 'get_personal_history', 'save_personal_history', 'upload_image', 'get_gallery', 'save_gallery', 'delete_gallery', 'set_consent', 'get_consent'];
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
        $query = trim((string)($_POST['query'] ?? ''));
        if ($action === 'search_word' && $query === '') appError('参数不全');
        $data = appDataDefaults(Database::read('app_data.json'));
        $wrong = $data['users'][$userId]['wrong_words'][$classId] ?? [];
        $favIds = $data['users'][$userId]['favorites'] ?? [];
        $list = [];
        foreach ($words as $word) {
            if ($action === 'search_word' && mb_stripos((string)$word['word'], $query) === false && mb_stripos((string)$word['meaning'], $query) === false) continue;
            $list[] = ['id' => $word['id'], 'word' => $word['word'], 'meaning' => $word['meaning'], 'pos' => $word['pos'] ?? '', 'is_wrong' => isset($wrong[$word['id']]), 'is_favorite' => in_array($word['id'], $favIds, true)];
        }
        $total = count($list);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_POST['per_page'] ?? 20)));
        $paged = array_slice($list, ($page - 1) * $perPage, $perPage);
        appJson(['success' => true, 'data' => ['words' => $paged, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'add_word':
        $word = trim((string)($_POST['word'] ?? ''));
        $meaning = trim((string)($_POST['meaning'] ?? ''));
        $pos = trim((string)($_POST['pos'] ?? ''));
        if ($word === '' || $meaning === '') appError('单词和释义不能为空');
        if (mb_strlen($word) > 100 || mb_strlen($meaning) > 500 || mb_strlen($pos) > 50) appError('输入内容过长');
        $newId = bin2hex(random_bytes(8));
        $duplicate = false;
        Database::updateClassData($classId, 'words', function($words) use ($word, $meaning, $pos, $newId, &$duplicate) {
            foreach ($words as $existing) if (mb_strtolower($existing['word']) === mb_strtolower($word)) { $duplicate = true; return null; }
            $words[] = ['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos, 'created_at' => date('Y-m-d')];
            return $words;
        });
        if ($duplicate) appError('单词已存在: ' . $word);
        appJson(['success' => true, 'data' => ['id' => $newId, 'word' => $word, 'meaning' => $meaning, 'pos' => $pos]]);

    case 'ai_word':
        // 限流: AI 调用 40次/小时 (按用户)
        appRateLimit('ai', $userId, 40, 3600);
        $word = trim((string)($_POST['word'] ?? ''));
        if ($word === '' || mb_strlen($word) > 100) appError('请输入有效单词');
        $prompt = "你是一个英语词典助手。为英文单词提供简洁准确的中文释义和标准词性缩写，严格返回JSON：\n{\"word\":\"" . addslashes($word) . "\",\"meaning\":\"中文释义\",\"pos\":\"词性\"}";
        $result = DeepSeekAPI::call([['role' => 'system', 'content' => '你是专业英语词典助手，只返回JSON。'], ['role' => 'user', 'content' => $prompt]], 512, 30);
        if (!$result['success']) appJson($result);
        $parsed = json_decode($result['content'], true);
        if (!is_array($parsed)) appError('AI返回格式解析失败，请重试');
        appJson(['success' => true, 'data' => ['word' => trim($parsed['word'] ?? $word), 'meaning' => trim($parsed['meaning'] ?? ''), 'pos' => trim($parsed['pos'] ?? '')]]);

    case 'mark_wrong':
        $wordId = appStrictId($_POST['word_id'] ?? '', 'word_id');
        $exists = false;
        foreach (Database::getWords($classId) as $word) if ((string)$word['id'] === $wordId) { $exists = true; break; }
        if (!$exists) appError('单词不存在');
        $wrong = (string)($_POST['wrong'] ?? '1') === '1';
        Database::update('app_data.json', function($data) use ($userId, $classId, $wordId, $wrong) {
            $data = appDataDefaults($data);
            if (!isset($data['users'][$userId]['wrong_words'][$classId])) $data['users'][$userId]['wrong_words'][$classId] = [];
            if ($wrong) $data['users'][$userId]['wrong_words'][$classId][$wordId] = ['marked_at' => date('Y-m-d H:i:s')];
            else unset($data['users'][$userId]['wrong_words'][$classId][$wordId]);
            return $data;
        });
        appJson(['success' => true, 'data' => ['is_wrong' => $wrong]]);

    case 'get_tasks':
        $type = trim((string)($_POST['type'] ?? 'pending'));
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
        $data = appDataDefaults(Database::read('app_data.json'));
        $map = [];
        foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $wrongMap = $data['users'][$userId]['wrong_words'][$classId] ?? [];
        $list = [];
        foreach ($wrongMap as $wid => $info) if (isset($map[$wid])) {
            $list[] = ['word_id' => $wid, 'word' => $map[$wid]['word'], 'meaning' => $map[$wid]['meaning'], 'pos' => $map[$wid]['pos'] ?? '', 'marked_at' => $info['marked_at'] ?? ''];
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
            foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid])) $taskWords[] = ['id' => $wid, 'word' => $map[$wid]['word'], 'meaning' => $map[$wid]['meaning'], 'pos' => $map[$wid]['pos'] ?? ''];
            $list[] = ['id' => $task['id'] ?? $tid, 'date' => $task['date'], 'label' => $task['label'] ?? '', 'words' => $taskWords];
        }
        usort($list, function($a, $b) { return $b['date'] <=> $a['date']; });
        appJson(['success' => true, 'data' => $list]);

    case 'search_all':
        $query = trim((string)($_POST['query'] ?? ''));
        if ($query === '') appError('参数不全');
        $words = Database::getWords($classId); $map = []; $wordMatches = [];
        foreach ($words as $word) {
            $map[(string)$word['id']] = $word;
            if (mb_stripos($word['word'], $query) !== false || mb_stripos($word['meaning'], $query) !== false) $wordMatches[] = ['id' => $word['id'], 'word' => $word['word'], 'meaning' => $word['meaning'], 'pos' => $word['pos'] ?? '', 'matched' => 'word', 'type' => 'word'];
        }
        $pending = []; $history = [];
        foreach (Database::getTasks($classId) as $tid => $task) {
            $matched = [];
            foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid]) && (mb_stripos($map[$wid]['word'], $query) !== false || mb_stripos($map[$wid]['meaning'], $query) !== false)) $matched[] = ['id' => $wid, 'word' => $map[$wid]['word'], 'meaning' => $map[$wid]['meaning'], 'pos' => $map[$wid]['pos'] ?? '', 'matched' => 'word'];
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
        $directory = __DIR__ . '/data/exports';
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
        $start = trim((string)($_POST['start_date'] ?? '')); $end = trim((string)($_POST['end_date'] ?? ''));
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
        $directory = __DIR__ . '/data/exports';
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
        $month = trim((string)($_POST['month'] ?? ''));
        if ($month !== '' && !preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)) appError('month 格式无效');
        $history = historySanitizeEntries(Database::getClassData($classId, 'history'));
        if ($month !== '') $history = array_filter($history, function($entryDate) use ($month) { return strncmp($entryDate, $month . '-', 8) === 0; }, ARRAY_FILTER_USE_KEY);
        appJson(['success' => true, 'data' => $history]);

    case 'get_personal_history':
        $month = trim((string)($_POST['month'] ?? ''));
        if ($month !== '' && !preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)) appError('month 格式无效');
        $history = historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $userId));
        if ($month !== '') $history = array_filter($history, function($entryDate) use ($month) { return strncmp($entryDate, $month . '-', 8) === 0; }, ARRAY_FILTER_USE_KEY);
        appJson(['success' => true, 'data' => $history]);

    case 'save_personal_history':
        $date = trim((string)($_POST['date'] ?? '')); $content = (string)($_POST['content'] ?? '');
        if (!historyStrictDate($date) || $date !== date('Y-m-d')) appError('只能保存今天的史记');
        try {
            $content = historySanitizeHtml($content);
        } catch (LengthException $e) {
            appError('内容过长');
        } catch (Exception $e) {
            appError('内容格式无效');
        }
        $entry = historyNormalizeEntry([
            'content' => $content,
            'title' => $_POST['title'] ?? '',
            'mood' => $_POST['mood'] ?? historyDefaultMood(),
            'weather' => $_POST['weather'] ?? historyDefaultWeather(),
            'location' => $_POST['location'] ?? '',
            'tags' => isset($_POST['tags']) ? json_decode((string)$_POST['tags'], true) : [],
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
        // APP 端图片上传 (需登录 + 班级校验，复用 GD 压缩逻辑)
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) appError('请选择图片');
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            appError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? '图片超过服务器上传限制' : '图片上传失败');
        }
        // 体积限制: 5MB
        if (($file['size'] ?? 0) <= 0 || $file['size'] > 5242880) appError('图片最大允许 5MB', null, 413);
        if (!is_uploaded_file($file['tmp_name'])) appError('上传文件无效');

        // MIME 校验: 仅允许 JPEG/PNG/WebP
        $info = @getimagesize($file['tmp_name']);
        $allowedTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($allowedTypes[$info[2]])) appError('仅支持 JPEG、PNG 或 WebP 图片');
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1) appError('图片尺寸无效');
        // 像素上限: 2500万
        if ($width * $height > 25000000) appError('图片像素过大', null, 413);
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) appError('服务器未启用 GD 扩展', null, 503);

        // GD 解码
        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!function_exists($loaders[$info[2]])) appError('服务器 GD 不支持该图片格式', null, 503);
        $source = @$loaders[$info[2]]($file['tmp_name']);
        if (!$source) appError('图片内容损坏或无法解码');

        // 缩放到最大边 1600px
        $maxEdge = 1600;
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) { imagedestroy($source); appError('图片处理失败', null, 500); }

        // 非 JPEG 图片保留透明通道
        if ($info[2] !== IMAGETYPE_JPEG) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
        }
        if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($source); imagedestroy($target); appError('图片缩放失败', null, 500);
        }

        // 生成随机文件名并保存
        $uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploads';
        $classDirectory = $uploadRoot . DIRECTORY_SEPARATOR . $classId;
        if (!is_dir($classDirectory) && !mkdir($classDirectory, 0750, true) && !is_dir($classDirectory)) {
            imagedestroy($source); imagedestroy($target); appError('上传目录不可用', null, 500);
        }
        $extension = $allowedTypes[$info[2]];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $classDirectory . DIRECTORY_SEPARATOR . $filename;
        $writers = [
            IMAGETYPE_JPEG => function($image, $p) { return imagejpeg($image, $p, 82); },
            IMAGETYPE_PNG  => function($image, $p) { return imagepng($image, $p, 7); },
            IMAGETYPE_WEBP => function($image, $p) { return imagewebp($image, $p, 82); },
        ];
        $saved = $writers[$info[2]]($target, $path);
        imagedestroy($source);
        imagedestroy($target);
        if (!$saved) { @unlink($path); appError('图片保存失败', null, 500); }
        @chmod($path, 0640);

        // 返回相对 URL (与 historySanitizeHtml 允许的格式一致)
        appJson(['success' => true, 'data' => ['url' => 'upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($filename)]]);

    case 'set_consent':
        // 按班级存储 consent，兼容旧全局键
        $allow = (string)($_POST['consent'] ?? $_POST['allow'] ?? '0') === '1';
        Database::update('app_data.json', function($data) use ($userId, $classId, $allow) {
            $data = appDataDefaults($data);
            if (!isset($data['users'][$userId]['consent_map']) || !is_array($data['users'][$userId]['consent_map'])) {
                $data['users'][$userId]['consent_map'] = [];
            }
            $data['users'][$userId]['consent_map'][$classId] = $allow;
            // 向后兼容: 同步旧全局键
            $data['users'][$userId]['consent'] = $allow;
            return $data;
        });
        appJson(['success' => true, 'allow' => $allow, 'data' => ['consent' => $allow, 'class_id' => $classId]]);

    case 'get_consent':
        // 按班级读取 consent，回退到旧全局键
        $data = appDataDefaults(Database::read('app_data.json'));
        $consentMap = $data['users'][$userId]['consent_map'] ?? [];
        $allow = isset($consentMap[$classId]) ? (bool)$consentMap[$classId] : (!empty($data['users'][$userId]['consent']) ? true : false);
        appJson(['success' => true, 'allow' => $allow, 'data' => ['consent' => $allow, 'class_id' => $classId]]);


    case 'unmark_wrong':
        $wordId = appStrictId($_POST['word_id'] ?? '', 'word_id');
        Database::update('app_data.json', function($data) use ($userId, $classId, $wordId) {
            $data = appDataDefaults($data);
            if (isset($data['users'][$userId]['wrong_words'][$classId][$wordId])) {
                unset($data['users'][$userId]['wrong_words'][$classId][$wordId]);
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
        Database::update('app_data.json', function($data) use ($userId, $wordId, &$isFav) {
            $data = appDataDefaults($data);
            if (!isset($data['users'][$userId]['favorites'])) $data['users'][$userId]['favorites'] = [];
            if (in_array($wordId, $data['users'][$userId]['favorites'], true)) {
                $data['users'][$userId]['favorites'] = array_values(array_diff($data['users'][$userId]['favorites'], [$wordId]));
                $isFav = false;
            } else {
                $data['users'][$userId]['favorites'][] = $wordId;
                $isFav = true;
            }
            return $data;
        });
        appJson(['success' => true, 'data' => ['is_favorite' => $isFav]]);

    case 'get_favorites':
        $data = appDataDefaults(Database::read('app_data.json'));
        $favIds = $data['users'][$userId]['favorites'] ?? [];
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
        $data = appDataDefaults(Database::read('app_data.json'));
        $favIds = $data['users'][$userId]['favorites'] ?? [];
        $wrongMap = $data['users'][$userId]['wrong_words'][$classId] ?? [];
        $words = [];
        foreach (($task['word_ids'] ?? []) as $wid) if (isset($map[$wid])) {
            $words[] = [
                'id' => $wid,
                'word' => $map[$wid]['word'],
                'meaning' => $map[$wid]['meaning'],
                'pos' => $map[$wid]['pos'] ?? '',
                'is_favorite' => in_array($wid, $favIds, true),
                'is_wrong' => isset($wrongMap[$wid]),
            ];
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
        foreach (($tasks[$taskId]['word_ids'] ?? []) as $wid) if (isset($map[$wid])) {
            $csvRows[] = '"' . str_replace('"', '""', $map[$wid]['word']) . '","' . str_replace('"', '""', $map[$wid]['meaning']) . '","' . ($map[$wid]['pos'] ?? '') . '"';
            $textLines[] = $map[$wid]['word'] . ' ' . $map[$wid]['meaning'] . ' ' . ($map[$wid]['pos'] ?? '');
        }
        if (!count($textLines)) appError('任务中没有可导出的单词');
        if ($action === 'export_task_csv') {
            $csv = implode("
", $csvRows);
            $directory = __DIR__ . '/data/exports';
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
            appJson(['success' => true, 'data' => ['text' => implode("
", $textLines)]]);
        }

    case 'export_wrong_csv':
    case 'export_wrong_text':
        $data = appDataDefaults(Database::read('app_data.json'));
        $wrongMap = $data['users'][$userId]['wrong_words'][$classId] ?? [];
        $map = []; foreach (Database::getWords($classId) as $word) $map[(string)$word['id']] = $word;
        $csvRows = ["ï»¿单词,释义,词性"];
        $textLines = [];
        foreach ($wrongMap as $wid => $info) if (isset($map[$wid])) {
            $csvRows[] = '"' . str_replace('"', '""', $map[$wid]['word']) . '","' . str_replace('"', '""', $map[$wid]['meaning']) . '","' . ($map[$wid]['pos'] ?? '') . '"';
            $textLines[] = $map[$wid]['word'] . ' ' . $map[$wid]['meaning'] . ' ' . ($map[$wid]['pos'] ?? '');
        }
        if (!count($textLines)) appError('错题本为空，无可导出内容');
        if ($action === 'export_wrong_csv') {
            $csv = implode("
", $csvRows);
            $directory = __DIR__ . '/data/exports';
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
            appJson(['success' => true, 'data' => ['text' => implode("
", $textLines)]]);
        }

    case 'get_gallery':
        $gallery = Database::getClassData($classId, 'gallery');
        if (!is_array($gallery)) $gallery = [];
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
        $total = count($gallery);
        $items = array_slice($gallery, ($page - 1) * $perPage, $perPage);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $result = [];
        foreach ($items as $item) {
            $result[] = ['id' => $item['id'] ?? '', 'image_url' => $base . '/upload.php?class_id=' . rawurlencode($classId) . '&file=' . rawurlencode($item['image'] ?? ''), 'description' => (string)($item['description'] ?? ''), 'uploaded_at' => (string)($item['uploaded_at'] ?? '')];
        }
        appJson(['success' => true, 'data' => ['items' => $result, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => ($page * $perPage) < $total]]);

    case 'save_gallery':
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) appError('请选择图片');
        $img = $_FILES['image'];
        if (($img['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) appError('图片上传失败');
        if (($img['size'] ?? 0) > 12582912) appError('图片最大 12MB', null, 413);
        $info = @getimagesize($img['tmp_name']);
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($allowed[$info[2]])) appError('仅支持 JPEG、PNG、WebP');
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($desc === '' || mb_strlen($desc) > 500) appError('描述不能为空且不超过500字');
        $uploadDir = Database::getUploadsDirectory($classId);
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0750, true);
        $ext = $allowed[$info[2]];
        $fname = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = $uploadDir . DIRECTORY_SEPARATOR . $fname;
        if (!move_uploaded_file($img['tmp_name'], $path)) appError('保存失败');
        $id = bin2hex(random_bytes(16));
        Database::updateClassData($classId, 'gallery', function($latest) use ($id, $fname, $desc) {
            if (!is_array($latest)) $latest = [];
            array_unshift($latest, ['id' => $id, 'image' => $fname, 'description' => $desc, 'uploaded_at' => date('Y-m-d H:i:s')]);
            return $latest;
        });
        appJson(['success' => true, 'data' => ['id' => $id]]);

    case 'delete_gallery':
        $gid = appStrictId($_POST['id'] ?? '', 'id');
        Database::updateClassData($classId, 'gallery', function($latest) use ($gid, $classId) {
            if (!is_array($latest)) return null;
            foreach ($latest as $i => $item) if (($item['id'] ?? '') === $gid) {
                $p = Database::getUploadsDirectory($classId) . DIRECTORY_SEPARATOR . ($item['image'] ?? '');
                if (is_file($p)) @unlink($p);
                array_splice($latest, $i, 1);
                return $latest;
            }
            return null;
        });
        appJson(['success' => true]);


    default:
        appError('未知 action: ' . $action);
}
