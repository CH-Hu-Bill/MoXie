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

function appUsername() {
    return trim(reqPost('username', reqPost('name')));
}

function appValidateCredentials($name, $password) {
    $length = mb_strlen($name, 'UTF-8');
    if ($length < 2 || $length > 30) appError('用户名长度须为2-30个字符');
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) appError('用户名包含无效字符');
    if (strlen($password) < 8) appError('密码至少8位');
}

function appFindUserId($name) {
    foreach (Database::getAllUserIds() as $uid) {
        $user = Database::getUser($uid);
        if ($user && ($user['name'] ?? '') === $name) return (string)$uid;
    }
    return null;
}

function appNewToken(&$tokens, $userId) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = time() + APP_TOKEN_TTL;
    // 清理过期令牌
    foreach ($tokens['tokens'] as $key => $record) {
        if ((int)($record['expires_at'] ?? 0) <= time()) unset($tokens['tokens'][$key]);
    }
    $tokens['tokens'][$hash] = ['user_id' => $userId, 'expires_at' => $expires];
    return [$token, $expires];
}

function appAuthToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($header), $m)) return $m[1];
    return trim(reqPost('token'));
}

function appRequireAuth() {
    $token = appAuthToken();
    if ($token === '') appError('请先登录', 'AUTH_REQUIRED', 401);
    $hash = hash('sha256', $token);
    $tokens = Database::getTokens();
    $record = $tokens['tokens'][$hash] ?? null;
    if (!$record || (int)($record['expires_at'] ?? 0) <= time()) {
        if ($record) Database::updateTokens(function($current) use ($hash) {
            unset($current['tokens'][$hash]);
            return $current;
        });
        appError('登录已过期，请重新登录', 'TOKEN_EXPIRED', 401);
    }
    $userId = (string)($record['user_id'] ?? '');
    $user = Database::getUser($userId);
    if ($user === null) appError('用户不存在', 'AUTH_INVALID', 401);
    return [$userId, $user, $hash];
}

function appStrictId($value, $field) {
    if (!is_scalar($value)) $value = '';
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
    $user = Database::getUser($userId);
    if ($user === null) appError('用户不存在');
    if (!in_array($classId, $user['class_ids'] ?? [], true)) appError('请先绑定班级', 'CLASS_NOT_BOUND', 403);
    $current = appClassVersion($classes[$classId]);
    $stored = $user['class_auth_versions'][$classId] ?? null;
    if (!is_string($stored) || !hash_equals($current, $stored)) {
        Database::updateUser($userId, function($latest) use ($classId) {
            $ids = $latest['class_ids'] ?? [];
            $latest['class_ids'] = array_values(array_filter($ids, function($id) use ($classId) { return $id !== $classId; }));
            unset($latest['class_auth_versions'][$classId]);
            return $latest;
        });
        appError('班级口令已变更，请重新绑定', 'CLASS_AUTH_EXPIRED', 403);
    }
    return $classes[$classId];
}