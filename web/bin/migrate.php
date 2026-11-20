<?php
/**
 * CLI 迁移入口：php web/bin/migrate.php [--data-dir=web/data]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/migrations.php';

$dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
foreach ($argv as $arg) {
    if (strpos($arg, '--data-dir=') === 0) {
        $dataDir = substr($arg, strlen('--data-dir='));
    }
}

try {
    $result = lwRunMigrations($dataDir);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
