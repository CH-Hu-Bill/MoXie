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

/**
 * 可信反向代理/隧道地址。只信任这些来源发来的 X-Forwarded-* 头。
 * 默认只信任本机（Caddy/Nginx/cloudflared 通常从 127.0.0.1 转发）。
 *
 * @return string[]
 */
function lwTrustedProxies(): array {
    static $proxies = null;
    if ($proxies !== null) return $proxies;
    $file = __DIR__ . '/config.php';
    $config = is_file($file) ? require $file : [];
    $value = $config['trusted_proxies'] ?? ['127.0.0.1', '::1'];
    $proxies = is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    return $proxies;
}

function isHttpsRequest() {
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
        return true;
    }
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && in_array($remote, lwTrustedProxies(), true)) {
        $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto !== '' && strtolower(trim(explode(',', $proto)[0])) === 'https') {
            return true;
        }
    }
    return false;
}

/**
 * 当前请求的站点根地址（不带末尾斜杠）。
 * HTTPS 反向代理/隧道下用可信的 X-Forwarded-Host 生成绝对 URL，避免混合内容。
 */
function lwBaseUrl(): string {
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && in_array($remote, lwTrustedProxies(), true)) {
        $fwdHost = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        if ($fwdHost !== '') $host = trim(explode(',', $fwdHost)[0]);
    }
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    if ($host === '') $host = 'localhost';
    return $scheme . '://' . $host;
}

/**
 * 计算允许的 CORS Origin；空列表表示只允许同源。
 *
 * @param string $origin 请求 Origin
 * @param string[] $allowedOrigins 配置
 * @return string|null
 */
function lwAllowedOrigin(string $origin, array $allowedOrigins): ?string {
    if ($origin === '') return null;
    if (in_array('*', $allowedOrigins, true)) return '*';
    return in_array($origin, $allowedOrigins, true) ? $origin : null;
}

/**
 * 统一的会话启动：严格模式 + HttpOnly/SameSite/Secure。
 */
function lwStartSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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
    $rawCookie = $_COOKIE[classAuthCookieName($classId)] ?? '';
    if (!is_scalar($rawCookie)) return false;
    $cookie = (string)$rawCookie;
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
    if (session_status() !== PHP_SESSION_ACTIVE) lwStartSession();
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
