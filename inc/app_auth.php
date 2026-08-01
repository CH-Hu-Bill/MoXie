<?php

const APP_TOKEN_TTL = 2592000;

function appJson($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function appError($message, $code = null, $status = 400) {
    $result = ['success' => false, 'error' => $message];
    if ($code !== null) $result['code'] = $code;
    appJson($result, $status);
}

function appDataDefaults($data) {
    if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
    if (!isset($data['next_uid']) || !is_int($data['next_uid'])) $data['next_uid'] = 1;
    if (!isset($data['tokens']) || !is_array($data['tokens'])) $data['tokens'] = [];
    return $data;
}

function appUsername() {
    return trim((string)($_POST['username'] ?? $_POST['name'] ?? ''));
}

function appValidateCredentials($name, $password) {
    $length = mb_strlen($name, 'UTF-8');
    if ($length < 2 || $length > 30) appError('用户名长度须为2-30个字符');
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) appError('用户名包含无效字符');
    if (strlen($password) < 8) appError('密码至少8位');
}

function appFindUserId($data, $name) {
    foreach ($data['users'] as $uid => $user) {
        if (($user['name'] ?? '') === $name) return (string)$uid;
    }
    return null;
}

function appNewToken(&$data, $userId) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = time() + APP_TOKEN_TTL;
    foreach ($data['tokens'] as $key => $record) {
        if ((int)($record['expires_at'] ?? 0) <= time()) unset($data['tokens'][$key]);
    }
    $data['tokens'][$hash] = ['user_id' => $userId, 'expires_at' => $expires];
    return [$token, $expires];
}

function appAuthToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($header), $m)) return $m[1];
    return trim((string)($_POST['token'] ?? ''));
}

function appRequireAuth() {
    $token = appAuthToken();
    if ($token === '') appError('请先登录', 'AUTH_REQUIRED', 401);
    $hash = hash('sha256', $token);
    $data = appDataDefaults(Database::read('app_data.json'));
    $record = $data['tokens'][$hash] ?? null;
    if (!$record || (int)($record['expires_at'] ?? 0) <= time()) {
        if ($record) Database::update('app_data.json', function($current) use ($hash) {
            $current = appDataDefaults($current);
            unset($current['tokens'][$hash]);
            return $current;
        });
        appError('登录已过期，请重新登录', 'TOKEN_EXPIRED', 401);
    }
    $userId = (string)($record['user_id'] ?? '');
    if (!isset($data['users'][$userId])) appError('用户不存在', 'AUTH_INVALID', 401);
    return [$userId, $data['users'][$userId], $hash];
}

function appStrictId($value, $field) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        appError($field . ' 无效');
    }
    return $value;
}

function appClassVersion($class) {
    if (array_key_exists('auth_version', $class)) return 'v:' . (string)$class['auth_version'];
    return 'p:' . hash('sha256', (string)($class['password_hash'] ?? ''));
}

function appRequireClass($userId, $classId) {
    $classes = Database::getClasses();
    if (!isset($classes[$classId])) appError('班级不存在');
    $data = appDataDefaults(Database::read('app_data.json'));
    $user = $data['users'][$userId] ?? [];
    if (!in_array($classId, $user['class_ids'] ?? [], true)) appError('请先绑定班级', 'CLASS_NOT_BOUND', 403);
    $current = appClassVersion($classes[$classId]);
    $stored = $user['class_auth_versions'][$classId] ?? null;
    if (!is_string($stored) || !hash_equals($current, $stored)) {
        Database::update('app_data.json', function($latest) use ($userId, $classId) {
            $latest = appDataDefaults($latest);
            if (!isset($latest['users'][$userId])) return null;
            $ids = $latest['users'][$userId]['class_ids'] ?? [];
            $latest['users'][$userId]['class_ids'] = array_values(array_filter($ids, function($id) use ($classId) { return $id !== $classId; }));
            unset($latest['users'][$userId]['class_auth_versions'][$classId]);
            return $latest;
        });
        appError('班级口令已变更，请重新绑定', 'CLASS_AUTH_EXPIRED', 403);
    }
    return $classes[$classId];
}
