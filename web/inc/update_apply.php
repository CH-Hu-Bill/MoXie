<?php
/**
 * Web 一键更新执行器：备份 → 下载 → 校验 → 解压 → 迁移 → 替换 → 回滚。
 *
 * 只由管理员后台或 CLI（web/bin/apply_update.php）调用。
 */
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/maintenance.php';

function lwHttpGet(string $url, int $timeout = 60): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'ListenWrite/' . LW_WEB_VERSION,
    ];
    $ca = lwCaBundle();
    if ($ca !== null) $options[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? (string)$body : null;
}

function lwDownloadFile(string $url, string $dest, int $timeout = 300): bool {
    if (!lwIsAllowedAssetUrl($url)) return false;
    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;
    $ch = curl_init($url);
    $options = [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'ListenWrite/' . LW_WEB_VERSION,
    ];
    $ca = lwCaBundle();
    if ($ca !== null) $options[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($ok === false || $code !== 200 || !is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        return false;
    }
    return true;
}

/**
 * 递归复制目录，排除指定的相对路径（如 data、apk、inc/config.php）。
 */
function lwCopyTree(string $src, string $dst, array $excludeRel = []): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst) && !@mkdir($dst, 0750, true) && !is_dir($dst)) return false;
    $exclude = array_fill_keys(array_map(function ($v) {
        return str_replace('\\', '/', trim($v, '/'));
    }, $excludeRel), true);
    $items = @scandir($src);
    if ($items === false) return false;
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $from = $src . DIRECTORY_SEPARATOR . $name;
        $to = $dst . DIRECTORY_SEPARATOR . $name;
        $rel = str_replace('\\', '/', substr($from, strlen($src) + 1));
        if (isset($exclude[$rel])) continue;
        if (isset($exclude[$name]) && dirname($rel) === '.') continue;
        if (is_dir($from)) {
            if (!lwCopyTree($from, $to, $excludeRel)) return false;
        } else {
            if (!@copy($from, $to)) return false;
            @chmod($to, 0640);
        }
    }
    return true;
}

/**
 * 把目录打成 zip（根前缀 web/），排除 data/apk/backups/.update 与 inc/config.php。
 */
function lwZipWebDir(string $webDir, string $zipPath): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $skipTop = ['data' => true, 'apk' => true, 'backups' => true, '.update' => true];
    $addDir = function (string $dir, string $rel) use (&$addDir, $zip, $skipTop): void {
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            if ($rel === '' && isset($skipTop[$name])) continue;
            if ($childRel === 'inc/config.php') continue;
            if (is_dir($path)) {
                $zip->addEmptyDir('web/' . $childRel);
                $addDir($path, $childRel);
            } else {
                $zip->addFile($path, 'web/' . $childRel);
            }
        }
    };
    $addDir($webDir, '');
    return $zip->close();
}

function lwSafeExtract(string $zipPath, string $destDir): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $norm = str_replace('\\', '/', $name);
        if ($norm === '' || strpos($norm, '../') !== false || strpos($norm, '/..') !== false || strpos($norm, ':') !== false || strpos($norm, '/') === 0) {
            $zip->close();
            return false;
        }
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0750, true) && !is_dir($destDir)) {
        $zip->close();
        return false;
    }
    $ok = $zip->extractTo($destDir);
    $zip->close();
    return $ok;
}

/** 在解压目录中找到包含 version.php 与 inc/version.php 的 web 根。 */
function lwFindWebRoot(string $dir, int $depth = 0): ?string {
    if ($depth > 3) return null;
    if (is_file($dir . '/version.php') && is_file($dir . '/inc/version.php')) return $dir;
    $items = @scandir($dir);
    if ($items === false) return null;
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            $found = lwFindWebRoot($path, $depth + 1);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function lwRemoveTree(string $dir): void {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) lwRemoveTree($path); else @unlink($path);
    }
    @rmdir($dir);
}

/**
 * 备份 web/data 顶层 JSON + inc/config.php + 当前 web 代码。
 *
 * @return array{dir:string,json_dir:string,code_zip:string,config:string}
 */
function lwBackupWeb(string $webDir, string $backupRoot): array {
    $stamp = date('Ymd-His');
    $dir = $backupRoot . DIRECTORY_SEPARATOR . $stamp;
    $jsonDir = $dir . DIRECTORY_SEPARATOR . 'json';
    if (!is_dir($jsonDir)) @mkdir($jsonDir, 0750, true);
    $dataDir = $webDir . DIRECTORY_SEPARATOR . 'data';
    foreach (glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        @copy($file, $jsonDir . DIRECTORY_SEPARATOR . basename($file));
    }
    $config = $webDir . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($config)) @copy($config, $dir . DIRECTORY_SEPARATOR . 'config.php');
    $codeZip = $dir . DIRECTORY_SEPARATOR . 'web-code.zip';
    lwZipWebDir($webDir, $codeZip);
    return ['dir' => $dir, 'json_dir' => $jsonDir, 'code_zip' => $codeZip, 'config' => $dir . DIRECTORY_SEPARATOR . 'config.php'];
}

function lwRestoreWeb(string $webDir, array $backup, string $dataDir): bool {
    $ok = true;
    if (is_file($backup['code_zip'] ?? '')) {
        $tmp = $dataDir . DIRECTORY_SEPARATOR . '.update' . DIRECTORY_SEPARATOR . 'restore';
        lwRemoveTree($tmp);
        if (lwSafeExtract($backup['code_zip'], $tmp)) {
            $root = lwFindWebRoot($tmp);
            if ($root !== null) {
                $ok = lwCopyTree($root, $webDir, ['data', 'apk', 'backups', '.update', 'inc/config.php']) && $ok;
            } else {
                $ok = false;
            }
        } else {
            $ok = false;
        }
        lwRemoveTree($tmp);
    }
    foreach (glob(($backup['json_dir'] ?? '') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $ok = @copy($file, $dataDir . DIRECTORY_SEPARATOR . basename($file)) && $ok;
    }
    if (is_file($backup['config'] ?? '')) {
        $ok = @copy($backup['config'], $webDir . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'config.php') && $ok;
    }
    return $ok;
}

/** 用新代码的 CLI 迁移器执行迁移（避免当前请求内函数名冲突）。 */
function lwRunMigrationsCli(string $newWebDir, string $dataDir): array {
    $script = $newWebDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'migrate.php';
    if (!is_file($script)) throw new RuntimeException('更新包缺少 web/bin/migrate.php');
    if (!function_exists('proc_open')) throw new RuntimeException('PHP 未启用 proc_open，无法执行迁移');
    $cmd = [PHP_BINARY, $script, '--data-dir=' . $dataDir];
    $pipes = [];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('无法启动迁移进程');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException('迁移失败：' . trim($err ?: $out));
    }
    $json = json_decode(trim((string)$out), true);
    return is_array($json) ? $json : ['raw' => trim((string)$out)];
}

/**
 * 执行更新。调用方必须先做管理员/CSRF/URL 校验。
 *
 * @return array{ok:bool,version:string,message:string,backup_dir:string}
 */
function lwApplyWebUpdate(array $release, string $webDir = ''): array {
    $webDir = $webDir !== '' ? $webDir : dirname(__DIR__);
    $dataDir = $webDir . DIRECTORY_SEPARATOR . 'data';
    $version = (string)($release['version'] ?? '');
    $result = ['ok' => false, 'version' => $version, 'message' => '', 'backup_dir' => ''];
    if ($version === '' || empty($release['zip_url']) || empty($release['sha_url'])) {
        $result['message'] = '更新信息不完整';
        return $result;
    }
    if (!lwIsAllowedAssetUrl($release['zip_url']) || !lwIsAllowedAssetUrl($release['sha_url'])) {
        $result['message'] = '更新地址不在白名单';
        return $result;
    }

    lwSetMaintenance(true, '正在更新到 v' . $version, $dataDir);
    lwWriteUpdateStatus(['stage' => 'backup', 'message' => '备份数据与代码', 'version' => $version], $dataDir);
    $backup = lwBackupWeb($webDir, $dataDir . DIRECTORY_SEPARATOR . 'backups');
    $result['backup_dir'] = $backup['dir'];
    if (!is_file($backup['code_zip'])) {
        lwWriteUpdateStatus(['stage' => 'error', 'message' => '备份代码失败，已取消更新', 'version' => $version], $dataDir);
        lwSetMaintenance(false, '', $dataDir);
        $result['message'] = '备份代码失败，已取消更新';
        return $result;
    }

    try {
        lwWriteUpdateStatus(['stage' => 'download', 'message' => '下载更新包', 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        $tmpZip = $dataDir . DIRECTORY_SEPARATOR . '.update' . DIRECTORY_SEPARATOR . 'package.zip';
        if (!lwDownloadFile($release['zip_url'], $tmpZip)) throw new RuntimeException('下载更新包失败');
        $shaText = lwHttpGet($release['sha_url']);
        $expected = $shaText !== null ? lwParseSha256($shaText) : null;
        if ($expected === null) throw new RuntimeException('无法读取 sha256');
        if (!hash_equals($expected, (string)hash_file('sha256', $tmpZip))) throw new RuntimeException('更新包校验失败');

        lwWriteUpdateStatus(['stage' => 'extract', 'message' => '解压更新包', 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        $staging = $dataDir . DIRECTORY_SEPARATOR . '.update' . DIRECTORY_SEPARATOR . 'staging';
        lwRemoveTree($staging);
        if (!lwSafeExtract($tmpZip, $staging)) throw new RuntimeException('解压失败');
        $newWeb = lwFindWebRoot($staging);
        if ($newWeb === null) throw new RuntimeException('更新包结构不正确（找不到 web 目录）');

        lwWriteUpdateStatus(['stage' => 'migrate', 'message' => '执行数据迁移', 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        lwRunMigrationsCli($newWeb, $dataDir);

        lwWriteUpdateStatus(['stage' => 'copy', 'message' => '替换程序文件', 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        if (!lwCopyTree($newWeb, $webDir, ['data', 'apk', 'backups', '.update', 'inc/config.php'])) {
            throw new RuntimeException('复制程序文件失败');
        }
        if (function_exists('opcache_reset')) @opcache_reset();
        lwSetMaintenance(false, '', $dataDir);
        lwWriteUpdateStatus(['stage' => 'done', 'message' => '已更新到 v' . $version . '，请刷新页面', 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        lwRemoveTree($staging);
        @unlink($tmpZip);
        $result['ok'] = true;
        $result['message'] = '已更新到 v' . $version;
    } catch (Throwable $e) {
        $result['message'] = $e->getMessage();
        lwWriteUpdateStatus(['stage' => 'error', 'message' => $e->getMessage(), 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        $restored = lwRestoreWeb($webDir, $backup, $dataDir);
        if (!$restored) {
            lwWriteUpdateStatus(['stage' => 'error', 'message' => $e->getMessage() . '；回滚也失败，请手动恢复 ' . $backup['dir'], 'version' => $version, 'backup_dir' => $backup['dir']], $dataDir);
        }
        // 出错时保留维护模式，管理员可在后台手动退出；数据/代码已尽力回滚
    }
    return $result;
}
