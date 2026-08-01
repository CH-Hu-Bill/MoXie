<?php

require_once 'inc/db.php';

$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) { http_response_code(404); exit('下载链接无效'); }

// GC: 顺带清理过期的导出文件 (读取 → 删物理文件 → 清记录)
downloadGcExpired();

$record = Database::read('exports.json')[hash('sha256', $token)] ?? null;
if (!is_array($record) || (int)($record['expires_at'] ?? 0) <= time()) { http_response_code(410); exit('下载链接已过期'); }
$storedName = (string)($record['path'] ?? '');
// 支持 .pdf、.html、.zip、.tar 四种导出文件
$isPdf = preg_match('/\A[a-f0-9]{48}\.pdf\z/D', $storedName) && basename($storedName) === $storedName;
$isHtml = preg_match('/\A[a-f0-9]{48}\.html\z/D', $storedName) && basename($storedName) === $storedName;
$isZip = preg_match('/\A[a-f0-9]{48}\.zip\z/D', $storedName) && basename($storedName) === $storedName;
$isTar = preg_match('/\A[a-f0-9]{48}\.tar\z/D', $storedName) && basename($storedName) === $storedName;
$isCsv = preg_match('/\A[a-f0-9]{48}\.csv\z/D', $storedName) && basename($storedName) === $storedName;
if (!$isPdf && !$isHtml && !$isZip && !$isTar && !$isCsv) { http_response_code(404); exit('文件不存在'); }
$file = __DIR__ . '/data/exports/' . $storedName;
if (!is_file($file)) { http_response_code(404); exit('文件不存在'); }
$filename = preg_replace('/[\r\n"]+/', '_', (string)($record['filename'] ?? 'export'));
// 根据文件类型设置 Content-Type 和默认文件名
if ($isHtml) {
    header('Content-Type: text/html; charset=UTF-8');
    $defaultName = 'export.html';
} elseif ($isZip) {
    header('Content-Type: application/zip');
    $defaultName = 'export.zip';
} elseif ($isTar) {
    header('Content-Type: application/x-tar');
    $defaultName = 'export.tar';
} elseif ($isCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    $defaultName = 'export.csv';
} else {
    header('Content-Type: application/pdf');
    $defaultName = 'export.pdf';
}
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . $defaultName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
readfile($file);

/**
 * 清理过期的导出文件: 删除 expires_at <= now 的物理文件和记录
 * 在每次下载请求时顺带执行，避免单独的定时任务。
 */
function downloadGcExpired() {
    $now = time();
    $exportsDir = __DIR__ . '/data/exports';
    Database::update('exports.json', function($exports) use ($now, $exportsDir) {
        if (!is_array($exports)) return [];
        $changed = false;
        foreach ($exports as $key => $record) {
            if (!is_array($record)) {
                unset($exports[$key]);
                $changed = true;
                continue;
            }
            if ((int)($record['expires_at'] ?? 0) <= $now) {
                // 删除物理文件（支持 .pdf、.html、.zip、.tar）
                $storedName = (string)($record['path'] ?? '');
                $isPdf = preg_match('/\A[a-f0-9]{48}\.pdf\z/D', $storedName) && basename($storedName) === $storedName;
                $isHtml = preg_match('/\A[a-f0-9]{48}\.html\z/D', $storedName) && basename($storedName) === $storedName;
                $isZip = preg_match('/\A[a-f0-9]{48}\.zip\z/D', $storedName) && basename($storedName) === $storedName;
                $isTar = preg_match('/\A[a-f0-9]{48}\.tar\z/D', $storedName) && basename($storedName) === $storedName;
$isCsv = preg_match('/\A[a-f0-9]{48}\.csv\z/D', $storedName) && basename($storedName) === $storedName;
                if ($isPdf || $isHtml || $isZip || $isTar || $isCsv) {
                    $filePath = $exportsDir . '/' . $storedName;
                    if (is_file($filePath)) @unlink($filePath);
                }
                unset($exports[$key]);
                $changed = true;
            }
        }
        // 返回 null 表示不写入 (无变更时避免无谓写盘)
        return $changed ? $exports : null;
    });
}
