<?php
/**
 * Web 一键更新：GitHub Release 检查与校验解析。
 *
 * 只信任 CH-Hu-Bill/MoXie 的 web-v* Release 资产。
 */
require_once __DIR__ . '/version.php';

const LW_UPDATE_REPO = 'CH-Hu-Bill/MoXie';

/** Windows PHP 默认没有 CA 包；仓库内置 Mozilla CA bundle。 */
function lwCaBundle(): ?string {
    $file = __DIR__ . '/cacert.pem';
    return is_file($file) ? $file : null;
}

/** GitHub 只读 Token（可选）：用于避免匿名 API 60 次/小时限流导致的 403。 */
function lwGithubToken(): string {
    $env = getenv('GITHUB_TOKEN');
    if ($env === false || $env === '') $env = getenv('LW_GITHUB_TOKEN');
    if ($env !== false && $env !== '') return trim((string)$env);
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) return '';
    try {
        $config = require $file;
    } catch (Throwable $e) {
        return '';
    }
    return is_array($config) ? trim((string)($config['github_token'] ?? '')) : '';
}

function lwIsAllowedAssetUrl(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts)) return false;
    if (($parts['scheme'] ?? '') !== 'https') return false;
    if (($parts['host'] ?? '') !== 'github.com') return false;
    $path = (string)($parts['path'] ?? '');
    return strpos($path, '/CH-Hu-Bill/MoXie/releases/download/web-v') === 0;
}

function lwParseSha256(string $text): ?string {
    if (preg_match('/\b([a-f0-9]{64})\b/i', $text, $m)) return strtolower($m[1]);
    return null;
}

/**
 * 从 GitHub release JSON 提取 web 更新信息；不符合规则返回 null。
 *
 * @return array{version:string,tag:string,zip_url:string,sha_url:string,notes:string,published_at:string}|null
 */
function lwParseWebRelease(array $release): ?array {
    $tag = (string)($release['tag_name'] ?? '');
    if (strpos($tag, 'web-v') !== 0) return null;
    if (!empty($release['draft']) || !empty($release['prerelease'])) return null;
    $version = substr($tag, 5);
    if (!preg_match('/\A\d+\.\d+\.\d+\z/', $version)) return null;
    $zipUrl = null;
    $shaUrl = null;
    foreach (($release['assets'] ?? []) as $asset) {
        $name = (string)($asset['name'] ?? '');
        $url = (string)($asset['browser_download_url'] ?? '');
        if (!lwIsAllowedAssetUrl($url)) continue;
        if (substr($name, -4) === '.zip') $zipUrl = $url;
        if (substr($name, -7) === '.sha256') $shaUrl = $url;
    }
    if ($zipUrl === null || $shaUrl === null) return null;
    return [
        'version' => $version,
        'tag' => $tag,
        'zip_url' => $zipUrl,
        'sha_url' => $shaUrl,
        'notes' => (string)($release['body'] ?? ''),
        'published_at' => (string)($release['published_at'] ?? ''),
    ];
}

/**
 * 查询 GitHub 最新 web-v* release。
 *
 * @return array{ok:bool,current:string,latest:?string,has_update:bool,release:?array,error:string}
 */
function lwCheckWebUpdate(): array {
    $result = [
        'ok' => false,
        'current' => LW_WEB_VERSION,
        'latest' => null,
        'has_update' => false,
        'release' => null,
        'error' => '',
    ];
    if (!function_exists('curl_init')) {
        $result['error'] = 'PHP 未启用 curl';
        return $result;
    }
    $url = 'https://api.github.com/repos/' . LW_UPDATE_REPO . '/releases?per_page=30';
    $ch = curl_init($url);
    $headers = ['Accept: application/vnd.github+json'];
    $githubToken = lwGithubToken();
    if ($githubToken !== '') $headers[] = 'Authorization: Bearer ' . $githubToken;
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'ListenWrite/' . LW_WEB_VERSION,
        CURLOPT_HTTPHEADER => $headers,
    ];
    $ca = lwCaBundle();
    if ($ca !== null) $options[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        $detail = '';
        if (is_string($body) && $body !== '') {
            $j = json_decode($body, true);
            if (is_array($j) && isset($j['message'])) $detail = '：' . $j['message'];
        }
        if ($code === 403) {
            $result['error'] = 'GitHub API 403（可能是 API 限流）。请在 web/inc/config.php 配置只读 github_token 后重试' . $detail;
        } else {
            $result['error'] = $err !== '' ? $err : ('GitHub API HTTP ' . $code . $detail);
        }
        return $result;
    }
    $releases = json_decode((string)$body, true);
    if (!is_array($releases)) {
        $result['error'] = 'GitHub 返回格式错误';
        return $result;
    }
    $best = null;
    foreach ($releases as $rel) {
        if (!is_array($rel)) continue;
        $parsed = lwParseWebRelease($rel);
        if ($parsed === null) continue;
        if ($best === null || version_compare($parsed['version'], $best['version'], '>')) {
            $best = $parsed;
        }
    }
    if ($best === null) {
        $result['error'] = '未找到可用的 web-v* Release';
        return $result;
    }
    $result['ok'] = true;
    $result['latest'] = $best['version'];
    $result['has_update'] = version_compare($best['version'], LW_WEB_VERSION, '>');
    $result['release'] = $best;
    return $result;
}
