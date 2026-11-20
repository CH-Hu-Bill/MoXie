<?php
/**
 * 维护模式与更新状态文件。
 *
 * 维护模式：web/data/.maintenance 存在时，除管理后台/状态/版本接口外的
 * Web 请求一律返回 503，防止更新过程中写入数据。
 */

function lwDataDir(string $dataDir = ''): string {
    return rtrim($dataDir !== '' ? $dataDir : (__DIR__ . '/../data'), DIRECTORY_SEPARATOR);
}

function lwMaintenanceFile(string $dataDir = ''): string {
    return lwDataDir($dataDir) . DIRECTORY_SEPARATOR . '.maintenance';
}

function lwInMaintenance(string $dataDir = ''): bool {
    return is_file(lwMaintenanceFile($dataDir));
}

function lwSetMaintenance(bool $on, string $message = '', string $dataDir = ''): void {
    $dir = lwDataDir($dataDir);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $file = lwMaintenanceFile($dataDir);
    if (!$on) {
        @unlink($file);
        return;
    }
    @file_put_contents($file, json_encode([
        'since' => date('Y-m-d H:i:s'),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function lwUpdateStatusFile(string $dataDir = ''): string {
    return lwDataDir($dataDir) . DIRECTORY_SEPARATOR . '.update' . DIRECTORY_SEPARATOR . 'status.json';
}

function lwWriteUpdateStatus(array $data, string $dataDir = ''): void {
    $file = lwUpdateStatusFile($dataDir);
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $payload = array_merge(['updated_at' => date('Y-m-d H:i:s')], $data);
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

function lwReadUpdateStatus(string $dataDir = ''): array {
    $file = lwUpdateStatusFile($dataDir);
    if (!is_file($file)) return ['stage' => 'idle', 'message' => '尚未执行更新'];
    $json = json_decode((string)@file_get_contents($file), true);
    return is_array($json) ? $json : ['stage' => 'unknown', 'message' => '状态文件损坏'];
}
