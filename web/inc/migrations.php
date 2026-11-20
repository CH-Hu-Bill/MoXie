<?php
/**
 * 数据迁移框架（S2 一键更新的基础）。
 *
 * 迁移必须幂等、只改数据形状；每个版本一个文件，返回 callable。
 */

function lwMigrations(): array {
    static $migrations = null;
    if ($migrations !== null) return $migrations;
    $migrations = [];
    $files = glob(__DIR__ . '/migrations/*.php') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        if (!preg_match('/\A(\d{3})_.*\.php\z/', basename($file), $m)) continue;
        $version = (int)$m[1];
        $fn = require $file;
        if (is_callable($fn)) $migrations[$version] = $fn;
    }
    ksort($migrations);
    return $migrations;
}

function lwSchemaVersion(string $dataDir): int {
    $file = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'schema.json';
    if (!is_file($file)) return 0;
    $json = json_decode((string)@file_get_contents($file), true);
    return is_array($json) ? (int)($json['version'] ?? 0) : 0;
}

/**
 * 执行尚未应用的迁移。
 *
 * @return array{from:int,to:int,applied:int[]}
 */
function lwRunMigrations(string $dataDir): array {
    $from = lwSchemaVersion($dataDir);
    $applied = [];
    foreach (lwMigrations() as $version => $fn) {
        if ($version <= $from) continue;
        $fn($dataDir);
        $applied[] = $version;
    }
    $to = $applied ? max($applied) : $from;
    if ($applied) {
        if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
        $file = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'schema.json';
        $payload = json_encode([
            'version' => $to,
            'applied_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('无法写入 schema.json');
        }
    }
    return ['from' => $from, 'to' => $to, 'applied' => $applied];
}
