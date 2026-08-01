<?php

function appSecret() {
    static $secret;
    if ($secret !== null) return $secret;

    $config = require __DIR__ . '/config.php';
    $secret = getenv('APP_SECRET');
    if ($secret === false || $secret === '') $secret = $config['app_secret'] ?? '';
    if (strlen($secret) < 32) {
        throw new RuntimeException('APP_SECRET or config app_secret must be at least 32 characters.');
    }
    return $secret;
}

function isHttpsRequest() {
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function base64UrlEncode($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode($value) {
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function classAuthCookieName($classId) {
    return 'class_auth_' . $classId;
}

function setSecureCookie($name, $value, $expires) {
    return setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function setClassAuthCookie($classId, $authVersion, $expires = null) {
    $expires = $expires ?: time() + 86400 * 365;
    $payload = base64UrlEncode(json_encode([
        'class_id' => (string) $classId,
        'auth_version' => (int) $authVersion,
        'exp' => $expires,
    ]));
    $signature = base64UrlEncode(hash_hmac('sha256', $payload, appSecret(), true));
    return setSecureCookie(classAuthCookieName($classId), $payload . '.' . $signature, $expires);
}

function isClassAuthenticated($classId, $class) {
    if (empty($class['password_hash'])) return true;
    $cookie = $_COOKIE[classAuthCookieName($classId)] ?? '';
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2) return false;
    $expected = base64UrlEncode(hash_hmac('sha256', $parts[0], appSecret(), true));
    if (!hash_equals($expected, $parts[1])) return false;
    $decoded = base64UrlDecode($parts[0]);
    $payload = $decoded === false ? null : json_decode($decoded, true);
    return is_array($payload)
        && isset($payload['class_id'], $payload['auth_version'], $payload['exp'])
        && (string) $payload['class_id'] === (string) $classId
        && (int) $payload['auth_version'] === (int) ($class['auth_version'] ?? 1)
        && (int) $payload['exp'] >= time();
}

function requireClassAuth($classId, $class) {
    if (!isClassAuthenticated($classId, $class)) {
        header('Location: index.php?need_auth=' . rawurlencode($classId));
        exit;
    }
}

function ensureSecuritySession() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

function csrfToken() {
    ensureSecuritySession();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    ensureSecuritySession();
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrf() {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => '请求已过期，请刷新页面重试']);
        exit;
    }
}
