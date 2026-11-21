<?php
/**
 * 管理员页面 — 班级管理 + APP 版本发布
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/game_time.php';
require_once 'inc/version.php';
require_once 'inc/update_apply.php';
require_once 'inc/gallery_trash.php';
require_once 'inc/ratelimit.php';
// 后台页面一律禁止缓存：删除公告等操作后刷新必须拿到最新数据，
// 避免浏览器缓存导致"删了还在/改了没变"的错觉。
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$config = require 'inc/config.php';

/** 字节数 → 人类可读大小（如 35.2 MB） */
function self_FormatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
$adminPassword = (string)($config['admin_password'] ?? '');
$adminPasswordHash = (string)($config['admin_password_hash'] ?? '');
$sessionTtl = $config['admin_session_ttl'] ?? 1800;

lwStartSession();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['admin_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = reqPost('csrf_token');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403); exit('CSRF validation failed');
    }
}

$isAuthed = false;
if (!empty($_SESSION['admin_authed']) && !empty($_SESSION['admin_time'])) {
    if (time() - $_SESSION['admin_time'] < $sessionTtl) $isAuthed = true;
}

if (!$isAuthed && ($_POST['action'] ?? '') === 'admin_login') {
    $now = time();
    $failWindow = 600; // 10 分钟内累计
    $maxFailures = 10; // 10 次错误才临时封禁，避免误伤管理员（自 DoS）
    $failures = array_values(array_filter($_SESSION['admin_login_failures'] ?? [], function($t) use ($now, $failWindow) {
        return is_int($t) && $t > $now - $failWindow;
    }));
    $submittedPassword = (string)reqPost('password');
    $adminConfigured = $adminPasswordHash !== '' || $adminPassword !== '';
    $loginOk = $adminPasswordHash !== ''
        ? password_verify($submittedPassword, $adminPasswordHash)
        : ($adminPassword !== '' && hash_equals($adminPassword, $submittedPassword));
    // 跨会话/跨 Cookie 的 IP 限流：只对失败尝试计数，防止清 Cookie 绕过上面的 session 失败计数
    $loginIpAllowed = true;
    $loginRetry = 0;
    if (!$loginOk) {
        $loginIp = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        list($loginIpAllowed, $loginRetry) = RateLimiter::check('admin_login', $loginIp, 10, 600);
    }
    if (!$loginIpAllowed) {
        $loginError = '登录尝试过于频繁，请 ' . max(1, (int)$loginRetry) . ' 秒后再试';
    } elseif (count($failures) >= $maxFailures) {
        $_SESSION['admin_login_failures'] = $failures;
        $loginError = '登录失败次数过多，请10分钟后重试';
    } elseif (!$adminConfigured) {
        $loginError = '后台未配置管理员密码，请运行 scripts/rotate-secrets.php';
    } elseif ($loginOk) {
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true; $_SESSION['admin_time'] = $now;
        unset($_SESSION['admin_login_failures']);
        $isAuthed = true;
    } else {
        $failures[] = $now; $_SESSION['admin_login_failures'] = $failures;
        $loginError = '密码错误';
        // 渐进式延迟（0.3s 起，最多 2s），减缓暴力破解且不锁死合法管理员
        usleep(min(2000000, (int)count($failures) * 300000));
    }
}

if ($isAuthed && ($_POST['action'] ?? '') === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php'); exit;
}

if ($isAuthed && ($_POST['action'] ?? '') === 'check_web_update') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(lwCheckWebUpdate(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAuthed && ($_POST['action'] ?? '') === 'apply_web_update') {
    header('Content-Type: application/json; charset=UTF-8');
    @set_time_limit(0);
    @ignore_user_abort(true);
    $version = trim((string)reqPost('version'));
    $zipUrl = trim((string)reqPost('zip_url'));
    $shaUrl = trim((string)reqPost('sha_url'));
    if (!preg_match('/\A\d+\.\d+\.\d+\z/', $version) || !lwIsAllowedAssetUrl($zipUrl) || !lwIsAllowedAssetUrl($shaUrl)) {
        echo json_encode(['ok' => false, 'message' => '更新参数无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = lwApplyWebUpdate(['version' => $version, 'zip_url' => $zipUrl, 'sha_url' => $shaUrl]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAuthed && ($_POST['action'] ?? '') === 'exit_maintenance') {
    header('Content-Type: application/json; charset=UTF-8');
    lwSetMaintenance(false);
    lwWriteUpdateStatus(['stage' => 'idle', 'message' => '已手动退出维护模式']);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$msg = '';
if ($isAuthed) {
    $classes = Database::getClasses();

    if (isset($_POST['action']) && $_POST['action'] === 'delete_class') {
        $delId = trim(reqPost('class_id'));
        $confirmName = trim(reqPost('confirm_name'));
        if (isset($classes[$delId]) && $confirmName === $classes[$delId]['name']) {
            $deleted = false;
            $classes = Database::update('classes.json', function($latest) use ($delId, $confirmName, &$deleted) {
                if (!isset($latest[$delId]) || ($latest[$delId]['name'] ?? '') !== $confirmName) return null;
                unset($latest[$delId]); $deleted = true; return $latest;
            });
            if ($deleted) {
                Database::validateClassId($delId);
                foreach (Database::getAllUserIds() as $uid) {
                    Database::updateUser($uid, function($data) use ($delId) {
                        $data['class_ids'] = array_values(array_filter($data['class_ids'] ?? [], function($id) use ($delId) { return (string)$id !== $delId; }));
                        unset($data['class_auth_versions'][$delId], $data['wrong_words'][$delId]);
                        return $data;
                    });
                }
                Database::update('settings.json', function($s) use ($delId) {
                    $suffix = '_' . $delId;
                    foreach (array_keys($s) as $k) if (substr((string)$k, -strlen($suffix)) === $suffix) unset($s[$k]);
                    return $s;
                });
                Database::deleteClassDataDir($delId);
                $msg = '班级已删除';
            } else { $msg = '班级已变更，删除取消'; }
        } else { $msg = '班级名不匹配，删除取消'; }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $resetId = trim(reqPost('class_id'));
        $newPw = trim(reqPost('new_password'));
        if (isset($classes[$resetId]) && mb_strlen($newPw) >= 4 && preg_match('/^[a-zA-Z0-9]+$/', $newPw)) {
            $updated = false;
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $classes = Database::update('classes.json', function($latest) use ($resetId, $hash, &$updated) {
                if (!isset($latest[$resetId])) return null;
                $latest[$resetId]['password_hash'] = $hash;
                $latest[$resetId]['auth_version'] = (int)($latest[$resetId]['auth_version'] ?? 1) + 1;
                $updated = true; return $latest;
            });
            $msg = $updated ? '班级口令已重置（所有设备立即失效）' : '班级已不存在';
        } else { $msg = '口令格式错误（至少4位字母或数字）'; }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_game_limits') {
        $settings = Database::getSettings();
        if (!is_array($settings)) $settings = [];
        foreach ($classes as $cid => $c) {
            $raw = $_POST['game_limit'][$cid] ?? '';
            if (!is_scalar($raw)) $raw = '';
            $raw = trim((string)$raw);
            $minutes = ($raw !== '' && ctype_digit($raw)) ? (int)$raw : 0;
            $minutes = max(0, min(GAME_TIME_MAX_MINUTES, $minutes));
            if ($minutes > 0) $settings['game_limit_minutes_' . $cid] = $minutes;
            else unset($settings['game_limit_minutes_' . $cid]);
        }
        if (Database::saveSettings($settings)) $msg = '小游戏时长已保存';
        else $msg = '保存失败：服务器数据目录不可写';
    }

    if (isset($_POST['action']) && $_POST['action'] === 'publish_version') {
        $newVer = trim(reqPost('version'));
        $notes = trim(reqPost('notes'));
        $channel = trim(reqPost('channel', 'stable'));
        if (!in_array($channel, ['stable', 'beta'], true)) $channel = 'stable';
        if ($newVer === '' || !preg_match('/^\d+\.\d+(\.\d+)?$/', $newVer)) {
            $msg = '版本号格式无效（如 1.0 或 1.0.1）';
        } else {
            $prevLatest = '';
            $publishedAt = date('Y-m-d H:i:s');
            Database::update('app_versions.json', function($d) use ($newVer, $notes, $channel, $publishedAt, &$prevLatest) {
                if (!is_array($d)) $d = ['latest' => '1.0', 'history' => []];
                if (!isset($d['history']) || !is_array($d['history'])) $d['history'] = [];
                $prevLatest = (string)($d['latest'] ?? '1.0');
                $d['latest'] = $newVer;
                $d['history'][] = [
                    'version' => $newVer,
                    'previous' => $prevLatest,
                    'date' => date('Y-m-d'),
                    'published_at' => $publishedAt,
                    'channel' => $channel,
                    'notes' => $notes,
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                ];
                // 仅保留最近 50 条发布记录
                if (count($d['history']) > 50) $d['history'] = array_slice($d['history'], -50);
                return $d;
            });
            $msg = "发布成功：{$prevLatest} → {$newVer}（{$channel}） · {$publishedAt}";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'upload_apk') {
        // 上传最新 APK：版本号/更新日志自动从「最新发布版本」读取，无需重复填写。
        // 只保留最新一版（覆盖旧文件），并记录大小/时间到 app_versions.json 的 apk 字段。
        if (!isset($_FILES['apk_file']) || !is_uploaded_file($_FILES['apk_file']['tmp_name'] ?? '')) {
            $msg = '请选择要上传的 APK 文件';
        } else {
            $tmp = $_FILES['apk_file']['tmp_name'];
            $err = (int)($_FILES['apk_file']['error'] ?? UPLOAD_ERR_OK);
            $size = (int)($_FILES['apk_file']['size'] ?? 0);
            $ext = strtolower(pathinfo((string)($_FILES['apk_file']['name'] ?? ''), PATHINFO_EXTENSION));
            if ($err !== UPLOAD_ERR_OK) {
                $msg = 'APK 上传失败（错误码 ' . $err . '）';
            } elseif ($size <= 0) {
                $msg = 'APK 文件为空';
            } elseif ($ext !== 'apk') {
                $msg = '只允许上传 .apk 文件';
            } else {
                $apkDir = __DIR__ . '/apk';
                if (!is_dir($apkDir) && !mkdir($apkDir, 0775, true)) {
                    $msg = '无法创建 apk 目录，请检查权限';
                } else {
                    // 读取当前最新发布版本；未发布版本则提示先去「发布新版本」
                    $verData = Database::read('app_versions.json');
                    if (!is_array($verData)) $verData = ['latest' => '1.0', 'history' => []];
                    $latestVer = (string)($verData['latest'] ?? '');
                    if ($latestVer === '') {
                        $msg = '请先在「发布新版本」中发布版本，再上传 APK（上传会自动关联该版本的更新日志）';
                    } else {
                        $dest = $apkDir . '/listenwrite-release.apk';
                        if (move_uploaded_file($tmp, $dest)) {
                            @chmod($dest, 0644);
                            $uploadedAt = date('Y-m-d H:i:s');
                            // 自动关联最新发布版本的更新日志
                            $latestNotes = '';
                            foreach (($verData['history'] ?? []) as $v) {
                                if (($v['version'] ?? '') === $latestVer) { $latestNotes = (string)($v['notes'] ?? ''); break; }
                            }
                            Database::update('app_versions.json', function($d) use ($latestVer, $latestNotes, $uploadedAt, $size) {
                                if (!is_array($d)) $d = ['latest' => '1.0', 'history' => []];
                                $d['latest'] = $latestVer;
                                $d['apk'] = [
                                    'version' => $latestVer,
                                    'url' => 'apk/listenwrite-release.apk',
                                    'size' => $size,
                                    'size_human' => self_FormatBytes($size),
                                    'notes' => $latestNotes,
                                    'uploaded_at' => $uploadedAt,
                                ];
                                return $d;
                            });
                            $msg = "APK 已上传：v{$latestVer}（" . self_FormatBytes($size) . "），已自动关联该版本更新日志";
                        } else {
                            $msg = 'APK 保存失败，请检查目录权限';
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_announcement') {
        $content = sanitizePlainText(reqPost('content'));
        $color = trim(reqPost('color'));
        $targetClasses = $_POST['target_classes'] ?? [];
        $targetPlatforms = $_POST['target_platforms'] ?? [];
        $allowClose = !empty($_POST['allow_close']);
        $mode = ($_POST['mode'] ?? 'banner') === 'fullscreen' ? 'fullscreen' : 'banner';
        $fsSeconds = max(1, min(5, (int)($_POST['fullscreen_seconds'] ?? 1)));
        $startTime = str_replace('T', ' ', trim(reqPost('start_time')));
        $endTime = str_replace('T', ' ', trim(reqPost('end_time')));
        $startTs = strtotime($startTime);
        $endTs = strtotime($endTime);
        // 超级霸屏是大字全屏展示，超长内容会溢出（网页端和 APP 端都会）；
        // 顶部横幅可长一些（跑马灯无限滚动）。超长则直接拒绝并提示。
        $contentLen = mb_strlen($content);
        $fsLimit = 60;      // 超级霸屏：60 字以内，保证 28px 大字不溢出卡片
        $bannerLimit = 200; // 顶部横幅：跑马灯无限滚动，放宽到 200
        if ($mode === 'fullscreen' && $contentLen > $fsLimit) {
            $msg = '超级霸屏内容不能超过 ' . $fsLimit . ' 字（当前 ' . $contentLen . ' 字），请精简后重试';
        } elseif ($contentLen > $bannerLimit) {
            $msg = '公告内容不能超过 ' . $bannerLimit . ' 字（当前 ' . $contentLen . ' 字）';
        } elseif ($content === '' || $startTs === false || $endTs === false) {
            $msg = '请填写公告内容、开始时间和结束时间';
        } elseif (!preg_match('/\A#[0-9a-fA-F]{3,8}\z/D', $color)) {
            $msg = '颜色格式无效';
        } elseif ($startTs >= $endTs) {
            $msg = '结束时间必须晚于开始时间';
        } else {
            $announcements = Database::getAnnouncements();
            // 检查时间冲突：仅同 mode 冲突（横幅↔横幅、超级↔超级），跨 mode 可共存
            $conflict = false;
            foreach ($announcements as $ann) {
                $annStartTs = strtotime((string)($ann['start_time'] ?? ''));
                $annEndTs = strtotime((string)($ann['end_time'] ?? ''));
                $annMode = $ann['mode'] ?? 'banner';
                if ($annStartTs !== false && $annEndTs !== false && $annMode === $mode && $annStartTs < $endTs && $annEndTs > $startTs) {
                    $conflict = true;
                    $msg = '该时间段已有同类型公告（' . htmlspecialchars($ann['content']) . '）冲突';
                    break;
                }
            }
            if (!$conflict) {
                $announcements[] = [
                    'id' => 'ann_' . time(),
                    'content' => $content,
                    'color' => $color,
                    'mode' => $mode,
                    'fullscreen_seconds' => $fsSeconds,
                    'target_classes' => $targetClasses,
                    'target_platforms' => $targetPlatforms,
                    'allow_close' => $allowClose,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                if (Database::saveAnnouncements($announcements)) {
                    $msg = '公告已发布';
                } else {
                    $msg = '公告保存失败：服务器数据目录不可写，请检查权限';
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
        $delId = reqPost('id');
        // 用锁内更新做原子删除，避免"读旧→写"竞态导致删除被覆盖（表现为删了还在）
        $deleted = false;
        $ok = Database::update('announcements.json', function($d) use ($delId, &$deleted) {
            if (!is_array($d) || !isset($d['announcements']) || !is_array($d['announcements'])) return $d;
            $before = count($d['announcements']);
            $d['announcements'] = array_values(array_filter($d['announcements'], function($a) use ($delId) {
                return ($a['id'] ?? '') !== $delId;
            }));
            $deleted = count($d['announcements']) < $before;
            return $d;
        });
        if ($ok && $deleted) {
            $msg = '公告已删除（立即生效）';
        } elseif ($ok) {
            $msg = '未找到该公告（可能已被删除）';
        } else {
            $msg = '公告删除失败：服务器数据目录不可写';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_display_settings') {
        $margin = max(0, min(200, (int)($_POST['display_bottom_margin'] ?? 48)));
        $settings = Database::getSettings();
        if (!is_array($settings)) $settings = [];
        $settings['display_bottom_margin'] = $margin;
        if (Database::saveSettings($settings)) {
            $msg = '大屏壁纸设置已保存（底部避让 ' . $margin . 'px）';
        } else {
            $msg = '保存失败：服务器数据目录不可写';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'restore_gallery_trash') {
        $cid = trim(reqPost('class_id'));
        $tid = trim(reqPost('id'));
        if (isset($classes[$cid]) && preg_match('/\A[a-f0-9]{32}\z/D', $tid)) {
            $entry = galleryTrashForget($cid, $tid);
            if ($entry !== null) {
                unset($entry['deleted_at']);
                Database::updateClassData($cid, 'gallery', function ($latest) use ($entry) {
                    if (!is_array($latest)) $latest = [];
                    array_unshift($latest, $entry);
                    return $latest;
                });
                $msg = '已恢复 1 张图集到「' . htmlspecialchars($classes[$cid]['name']) . '」';
            } else {
                $msg = '回收站里没有这条记录（可能已被清理）';
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'purge_gallery_trash') {
        $cid = trim(reqPost('class_id'));
        $tid = trim(reqPost('id'));
        if (isset($classes[$cid]) && preg_match('/\A[a-f0-9]{32}\z/D', $tid)) {
            $entry = galleryTrashForget($cid, $tid);
            if ($entry !== null) {
                galleryTrashDeleteFiles($cid, $entry);
                $msg = '已彻底删除 1 张图集';
            } else {
                $msg = '回收站里没有这条记录（可能已被清理）';
            }
        }
    }
}
$versionData = Database::read('app_versions.json');
if (!is_array($versionData)) $versionData = ['latest' => '1.0', 'history' => []];
$versionHistory = array_reverse($versionData['history'] ?? []);
?><?php $pageTitle = '管理后台'; require 'inc/head.php'; ?>
<style>
/* ── S4 后台左侧导航 ── */
.admin-shell { display: flex; gap: 18px; align-items: flex-start; }
.admin-side {
    position: sticky; top: 16px; flex: 0 0 200px; width: 200px;
    background: var(--white); border: 2.5px solid var(--pencil);
    border-radius: var(--wobbly); box-shadow: var(--shadow-sm); padding: 14px 10px;
}
.admin-side-brand {
    font-family: var(--font-heading); font-size: 15px; padding: 0 8px 10px;
    border-bottom: 2px dashed var(--old-paper); margin-bottom: 6px;
}
.admin-side-group {
    font-size: 11px; letter-spacing: 0.08em; color: #9a9a9a; padding: 10px 8px 4px;
}
.admin-side-link {
    display: block; padding: 7px 10px; border-radius: var(--wobbly-sm);
    color: var(--pencil); text-decoration: none; font-size: 14px;
    border: 2px solid transparent; transition: background 0.12s, border-color 0.12s;
}
.admin-side-link:hover { background: var(--old-paper); }
.admin-side-link.active { background: var(--post-it); border-color: var(--pencil); box-shadow: var(--shadow-sm); }
.admin-side-details { margin: 0; }
.admin-side-details > summary {
    list-style: none; cursor: pointer; user-select: none;
    display: flex; align-items: center; justify-content: space-between;
}
.admin-side-details > summary::-webkit-details-marker { display: none; }
.admin-side-details > summary::after { content: '▾'; font-size: 12px; opacity: .65; }
.admin-side-details:not([open]) > summary::after { content: '▸'; }
.admin-side-children { padding-left: 6px; }
.admin-side-foot { margin-top: 12px; padding-top: 10px; border-top: 2px dashed var(--old-paper); }
.admin-main { flex: 1; min-width: 0; }
.admin-sections { display: flex; flex-direction: column; }
/* 视觉顺序（DOM 不动，保证 #sec-* 锚点与已有深链继续可用） */
#sec-overview { order: 1; } #sec-classes { order: 2; } #sec-trash { order: 3; }
#sec-announce { order: 4; } #sec-display { order: 5; } #sec-app { order: 6; } #sec-games { order: 7; } #sec-update { order: 8; }
@media (max-width: 880px) {
    .admin-shell { flex-direction: column; }
    .admin-side { position: static; width: 100%; flex: none; }
    .admin-side-nav { display: flex; flex-wrap: wrap; gap: 6px; }
    .admin-side-group { display: none; }
    .admin-side-link { border-color: var(--pencil); }
}
</style>
</head>
<body>
<div class="content">

<?php if (!$isAuthed): ?>
<div class="card" style="text-align:center;padding:32px 28px;max-width:400px;margin:40px auto;">
    <h2 style="font-family:var(--font-heading);margin-bottom:18px;font-size:20px;">管理员登录</h2>
    <form method="post">
        <input type="hidden" name="action" value="admin_login">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="password" name="password" class="input" placeholder="请输入管理员密码" autofocus style="text-align:center;margin-bottom:16px;">
        <button type="submit" class="btn btn-primary" style="width:100%;">登录</button>
        <?php if (isset($loginError)): ?><div style="color:var(--red);margin-top:8px;font-size:14px;"><?php echo $loginError; ?></div><?php endif; ?>
    </form>
</div>
<?php else: ?>

<?php if (!isHttpsRequest()): ?>
<div class="card" style="padding:12px 18px;margin-bottom:12px;border-color:var(--red);background:#fff2f2;color:var(--red);font-size:14px;line-height:1.7;">
    <strong>当前通过 HTTP 访问</strong>：管理员密码、班级口令和 APP token 会在链路上明文传输。公网使用请尽量配置 HTTPS 隧道/反向代理；当前仅建议在可信网络中使用。
</div>
<?php endif; ?>

<?php if ($msg): ?><div class="card <?php echo strpos($msg,'已')!==false||strpos($msg,'成功')!==false||strpos($msg,'发布')!==false?'card-post-it':''; ?>" style="padding:10px 18px;margin-bottom:12px;font-size:14px;<?php echo strpos($msg,'已')!==false||strpos($msg,'成功')!==false||strpos($msg,'发布')!==false?'':'color:var(--red);'; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<!-- S4：左侧导航 + 右侧内容。链接仍是 #sec-* 锚点，原有深链继续可用 -->
<div class="admin-shell">
<aside class="admin-side">
    <div class="admin-side-brand">📚 管理后台</div>
    <nav class="admin-side-nav">
        <div class="admin-side-group">总览</div>
        <a href="#sec-overview" class="admin-side-link">📊 概览</a>
        <div class="admin-side-group">班级</div>
        <a href="#sec-classes" class="admin-side-link">🏫 班级管理</a>
        <details class="admin-side-details" open>
            <summary class="admin-side-group">内容</summary>
            <div class="admin-side-children">
                <a href="#sec-announce" class="admin-side-link">📢 公告管理</a>
                <a href="#sec-display" class="admin-side-link">🖥️ 大屏设置</a>
                <a href="#sec-trash" class="admin-side-link">🗑️ 图集回收站</a>
            </div>
        </details>
        <details class="admin-side-details" open>
            <summary class="admin-side-group">系统</summary>
            <div class="admin-side-children">
                <a href="#sec-app" class="admin-side-link">📱 APP 发布</a>
                <a href="#sec-games" class="admin-side-link">🎮 小游戏时长</a>
                <a href="#sec-update" class="admin-side-link">🔄 系统更新</a>
            </div>
        </details>
    </nav>
    <div class="admin-side-foot">
        <div style="font-size:12px;opacity:0.55;padding:0 8px 8px;">Web v<?php echo htmlspecialchars(LW_WEB_VERSION, ENT_QUOTES, 'UTF-8'); ?></div>
        <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-sm" style="width:100%;opacity:0.55;color:var(--pencil);">退出登录</button>
        </form>
    </div>
</aside>
<div class="admin-main">
<div class="admin-sections">

<?php
$ovClasses = Database::getClasses();
$ovAnn = Database::getAnnouncements();
$ovUsers = Database::getAllUserIds();
$ovStat = function ($label, $value, $hint = '') {
    echo '<div style="border:2px solid var(--pencil);border-radius:var(--wobbly-sm);padding:10px 12px;background:var(--white);box-shadow:var(--shadow-sm);">';
    echo '<div style="font-size:11px;color:#999;margin-bottom:4px;">' . htmlspecialchars($label) . '</div>';
    echo '<div style="font-family:var(--font-heading);font-size:20px;">' . htmlspecialchars((string)$value) . '</div>';
    if ($hint !== '') echo '<div style="font-size:11px;color:#999;margin-top:2px;">' . htmlspecialchars($hint) . '</div>';
    echo '</div>';
};
?>
<div class="card" id="sec-overview" style="margin-bottom:16px;scroll-margin-top:16px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:10px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">📊 概览</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
        <?php
        $ovStat('班级', count(is_array($ovClasses) ? $ovClasses : []));
        $ovStat('学生账号', count(is_array($ovUsers) ? $ovUsers : []));
        $ovStat('公告', count(is_array($ovAnn) ? $ovAnn : []));
        $ovStat('APP 版本', (string)($versionData['latest'] ?? '1.0'));
        $ovStat('Web 版本', LW_WEB_VERSION);
        $ovStat('运行状态', lwInMaintenance() ? '维护中' : '正常');
        ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
        <a href="#sec-classes" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">新建/管理班级</a>
        <a href="#sec-announce" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">发布公告</a>
        <a href="#sec-update" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">检查更新</a>
    </div>
</div>

<div class="card" id="sec-update" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:10px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">🔄 系统更新</h2>
    <div style="font-size:14px;line-height:1.8;">
        当前 Web 版本：<b>v<?php echo htmlspecialchars(LW_WEB_VERSION, ENT_QUOTES, 'UTF-8'); ?></b>
        · <a href="https://github.com/CH-Hu-Bill/MoXie/releases" target="_blank" rel="noopener" style="color:var(--blue);">GitHub Releases</a>
    </div>
    <?php if (lwInMaintenance()): ?>
        <div style="margin-top:10px;padding:10px 12px;border:2px solid var(--red);background:#fff2f2;color:var(--red);border-radius:var(--wobbly-sm);font-size:14px;">
            当前处于维护模式。普通页面会返回 503；更新完成后会自动退出，也可手动退出。
        </div>
    <?php endif; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
        <button type="button" class="btn btn-primary" onclick="checkWebUpdate()">检查更新</button>
        <button type="button" class="btn btn-sm" onclick="exitMaintenance()">退出维护模式</button>
    </div>
    <div id="updateBox" style="margin-top:10px;font-size:14px;line-height:1.8;color:var(--pencil);"></div>
    <div style="margin-top:14px;padding-top:12px;border-top:2px dashed var(--old-paper);font-size:13px;line-height:1.8;color:var(--pencil);">
        <b>视频压缩队列</b>：超过 100MB 的视频上传后会显示「压缩中」，由后台 worker 自动处理。
        若长时间未完成，可在服务器执行
        <code style="background:var(--old-paper);padding:2px 6px;border-radius:4px;">php web/bin/compress_gallery.php</code>；
        建议用计划任务每分钟运行一次。
    </div>
</div>

<script>
const UPDATE_CSRF = <?php echo json_encode($csrfToken); ?>;
let _webRelease = null;
function escapeUpdateHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
    });
}
async function checkWebUpdate() {
    const box = document.getElementById('updateBox');
    box.textContent = '检查中…';
    const fd = new FormData();
    fd.append('action', 'check_web_update');
    fd.append('csrf_token', UPDATE_CSRF);
    try {
        const r = await fetch('admin.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) { box.textContent = '检查失败：' + (d.error || '未知错误'); return; }
        if (!d.has_update) {
            box.textContent = '当前已是最新版本 v' + d.current;
            return;
        }
        _webRelease = d.release;
        box.innerHTML = '<div>发现新版本 <b style="color:var(--red);">v' + escapeUpdateHtml(d.latest) + '</b></div>'
            + '<div style="white-space:pre-wrap;margin-top:8px;max-height:180px;overflow:auto;background:var(--old-paper);padding:10px;border-radius:var(--wobbly-sm);">'
            + escapeUpdateHtml(_webRelease.notes || '（无更新说明）') + '</div>'
            + '<div style="margin-top:10px;"><button type="button" class="btn btn-primary" onclick="startWebUpdate()">开始更新到 v' + escapeUpdateHtml(d.latest) + '</button></div>';
    } catch (e) {
        box.textContent = '检查失败：' + e;
    }
}
async function startWebUpdate() {
    if (!_webRelease) return;
    if (!await lwConfirm('将备份数据并更新到 v' + _webRelease.version + '，期间进入维护模式。', { title: '开始更新？', okText: '开始更新' })) return;
    const box = document.getElementById('updateBox');
    box.textContent = '更新中（备份 → 下载 → 校验 → 迁移 → 替换），可能需要 1–2 分钟，请勿关闭页面…';
    const fd = new FormData();
    fd.append('action', 'apply_web_update');
    fd.append('csrf_token', UPDATE_CSRF);
    fd.append('version', _webRelease.version);
    fd.append('zip_url', _webRelease.zip_url);
    fd.append('sha_url', _webRelease.sha_url);
    try {
        const r = await fetch('admin.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            box.textContent = '已更新到 v' + d.version + '，正在刷新…';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            box.textContent = '更新失败：' + (d.message || '未知错误') + '。若维护模式未退出，可点上方“退出维护模式”。';
        }
    } catch (e) {
        box.textContent = '请求失败：' + e + '。请刷新页面查看状态；若维护模式未退出，可手动退出。';
    }
}
async function exitMaintenance() {
    const fd = new FormData();
    fd.append('action', 'exit_maintenance');
    fd.append('csrf_token', UPDATE_CSRF);
    try {
        await fetch('admin.php', { method: 'POST', body: fd });
        location.reload();
    } catch (e) {
        alert('退出维护模式失败：' + e);
    }
}
</script>

<div class="card" id="sec-classes" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:16px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">班级列表 (<?php echo count($classes); ?>个)</h2>
    <?php if (empty($classes)): ?>
        <div class="empty-state"><p>暂无班级</p></div>
    <?php else: ?>
        <?php foreach ($classes as $id => $c): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:2px solid var(--old-paper);">
            <span style="font-weight:700;font-size:15px;font-family:var(--font-heading);"><?php echo htmlspecialchars($c['name']); ?><?php if (!empty($c['password_hash'])) echo ' 🔒'; ?></span>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="showResetPw('<?php echo $id; ?>','<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">重置口令</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="showDelete('<?php echo $id; ?>','<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">删除</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card" id="sec-games" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:16px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">🎮 小游戏时长</h2>
    <p style="font-size:13px;color:var(--pencil);opacity:0.75;line-height:1.7;margin-bottom:12px;">
        按班级设置每日上限，单位分钟。留空或填 0 = 不限时。每天 0 点自动重置，周末同样限制。
    </p>
    <?php if (empty($classes)): ?>
        <div class="empty-state"><p>暂无班级</p></div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action" value="save_game_limits">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:var(--old-paper);color:var(--pencil);">
                    <th style="text-align:left;padding:8px;">班级</th>
                    <th style="text-align:left;padding:8px;">今日已玩</th>
                    <th style="text-align:left;padding:8px;">今日剩余</th>
                    <th style="text-align:left;padding:8px;">近 7 天</th>
                    <th style="text-align:left;padding:8px;width:150px;">每日上限（分钟）</th>
                </tr></thead>
                <tbody>
                <?php foreach ($classes as $gid => $gc): ?>
                    <?php
                        $gLimit = game_limit_minutes($gid);
                        $gUsed = game_today_seconds($gid);
                        $gRemain = game_remaining_seconds($gid);
                        $g7 = array_sum(array_column(game_recent_stats($gid, 7), 'seconds'));
                    ?>
                    <tr style="border-bottom:1.5px solid var(--old-paper);">
                        <td style="padding:8px;font-weight:700;font-family:var(--font-heading);"><?php echo htmlspecialchars($gc['name']); ?></td>
                        <td style="padding:8px;"><?php echo htmlspecialchars(game_format_seconds($gUsed)); ?></td>
                        <td style="padding:8px;"><?php echo htmlspecialchars(game_format_seconds($gRemain)); ?></td>
                        <td style="padding:8px;"><?php echo htmlspecialchars(game_format_seconds($g7)); ?></td>
                        <td style="padding:8px;">
                            <input type="number" name="game_limit[<?php echo htmlspecialchars($gid, ENT_QUOTES, 'UTF-8'); ?>]" class="input" min="0" max="<?php echo GAME_TIME_MAX_MINUTES; ?>" step="1" value="<?php echo $gLimit > 0 ? $gLimit : ''; ?>" placeholder="不限时" style="width:120px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;"><button type="submit" class="btn btn-primary">保存小游戏时长</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card" id="sec-app" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:16px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">📱 APP 版本发布</h2>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--post-it);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);">
        <div><span style="display:block;font-size:11px;color:var(--pencil);margin-bottom:2px;opacity:0.7;">当前线上版本</span><b style="font-size:14px;"><?php echo htmlspecialchars((string)($versionData['latest'] ?? '1.0')); ?></b></div>
        <div><span style="display:block;font-size:11px;color:var(--pencil);margin-bottom:2px;opacity:0.7;">发布记录</span><b style="font-size:14px;"><?php echo count($versionHistory); ?> 条</b></div>
        <div><span style="display:block;font-size:11px;color:var(--pencil);margin-bottom:2px;opacity:0.7;">服务时间</span><b style="font-size:14px;"><?php echo date('Y-m-d H:i:s'); ?></b></div>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="publish_version">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">版本号 *</label>
                <input type="text" name="version" class="input" placeholder="1.1.0" style="width:110px" required pattern="\d+\.\d+(\.\d+)?$" title="例如 1.1 或 1.1.0">
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">发布通道</label>
                <select name="channel" style="padding:10px;border:2px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:14px;font-family:var(--font-body);background:var(--white);">
                    <option value="stable">stable（正式）</option>
                    <option value="beta">beta（测试）</option>
                </select>
            </div>
            <div style="flex:1;min-width:200px">
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">更新说明 / Release Notes</label>
                <input type="text" name="notes" class="input" placeholder="例如：修复登录闪退；优化错题本导出" style="width:100%" maxlength="500">
            </div>
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">发布新版本</button>
        </div>
        <p style="margin-top:10px;font-size:12px;color:var(--pencil);opacity:0.7;line-height:1.5;">发布后 APP 端 <code style="background:var(--old-paper);padding:1px 6px;border-radius:var(--wobbly-sm);">check_version</code> 将与当前客户端版本比较；用户可稍后更新（非强更）。</p>
    </form>

    <?php $apkInfo = $versionData['apk'] ?? null; ?>
    <div style="margin-top:14px;padding:12px;background:var(--post-it);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);">
        <div style="font-family:var(--font-heading);font-size:14px;margin-bottom:8px;">⬇️ 上传安装包（只保留最新一版）</div>
        <?php if ($apkInfo): ?>
        <div style="font-size:12px;color:var(--pencil);line-height:1.8;margin-bottom:8px;">
            <b>当前已上架：</b>v<?php echo htmlspecialchars((string)($apkInfo['version'] ?? '')); ?>
            · <?php echo htmlspecialchars((string)($apkInfo['size_human'] ?? '?')); ?>
            · <?php echo htmlspecialchars((string)($apkInfo['uploaded_at'] ?? '')); ?>
            <?php if (!empty($apkInfo['notes'])): ?><br><span style="opacity:0.7;">更新说明：<?php echo htmlspecialchars((string)$apkInfo['notes']); ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-size:12px;color:var(--pencil);opacity:0.6;margin-bottom:8px;">尚未上传过安装包。上传后自动关联「最新发布版本」的版本号与更新日志，生成「下载 APP」页面。</div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_apk">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px">
                    <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">APK 文件 *（.apk，仅保留最新一版）</label>
                    <input type="file" name="apk_file" accept=".apk" required style="font-size:12px;">
                </div>
                <button type="submit" class="btn btn-primary" style="white-space:nowrap;">上传</button>
            </div>
            <div style="margin-top:6px;font-size:12px;color:var(--pencil);opacity:0.7;">
                将自动关联「发布新版本」中的最新版本号（<?php echo htmlspecialchars((string)($versionData['latest'] ?? '未发布')); ?>）与其更新日志，无需重复填写。
            </div>
        </form>
    </div>

    <h3 style="font-family:var(--font-heading);font-size:15px;margin:18px 0 10px;color:var(--pencil);">发布日志</h3>
    <?php if (empty($versionHistory)): ?>
        <p style="color:var(--pencil);font-size:13px;padding:8px 0;opacity:0.7;">暂无发布记录</p>
    <?php else: ?>
    <div style="overflow-x:auto;border:2px solid var(--old-paper);border-radius:var(--wobbly-sm);">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">时间</th>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">版本</th>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">变更</th>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">通道</th>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">说明</th>
                    <th style="padding:8px 10px;border-bottom:2px solid var(--old-paper);text-align:left;vertical-align:top;background:var(--old-paper);color:var(--pencil);font-weight:700;white-space:nowrap;font-family:var(--font-heading);">来源 IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($versionHistory as $row): ?>
                <tr>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><?php echo htmlspecialchars((string)($row['published_at'] ?? $row['date'] ?? '-')); ?></td>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><code style="background:var(--old-paper);padding:1px 6px;border-radius:var(--wobbly-sm);"><?php echo htmlspecialchars((string)($row['version'] ?? '')); ?></code></td>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;color:var(--pencil);opacity:0.7;"><?php
                        $prev = (string)($row['previous'] ?? '');
                        $cur = (string)($row['version'] ?? '');
                        echo $prev !== '' ? htmlspecialchars($prev . ' → ' . $cur) : htmlspecialchars($cur);
                    ?></td>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><?php echo htmlspecialchars((string)($row['channel'] ?? 'stable')); ?></td>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><?php echo htmlspecialchars((string)($row['notes'] ?? '')); ?></td>
                    <td style="padding:8px 10px;border-bottom:2px solid var(--old-paper);vertical-align:top;color:var(--pencil);opacity:0.7;"><?php echo htmlspecialchars((string)($row['ip'] ?? '-')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
$announcements = Database::getAnnouncements();
$allClassIds = is_array($classes) ? array_keys($classes) : [];
$serverNowInput = date('Y-m-d\TH:i');
$serverEndInput = date('Y-m-d\TH:i', time() + 3600);
$displaySettings = Database::getSettings();
$displayBottomMargin = max(0, (int)($displaySettings['display_bottom_margin'] ?? 48));
?>
<div class="card" id="sec-display" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:16px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">🖥️ 大屏壁纸设置</h2>
    <p style="font-size:13px;color:var(--pencil);opacity:0.75;margin-bottom:12px;">
        壁纸展示页 <code style="background:var(--old-paper);padding:1px 6px;border-radius:var(--wobbly-sm);">display.php</code> 的底部避让高度（防止被电脑任务栏遮挡）。内容只占右侧可用区域（左侧快捷方式区通过页面上可拖拽竖线调整）。
    </p>
    <form method="post">
        <input type="hidden" name="action" value="save_display_settings">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">底部避让高度 (px)</label>
                <input type="number" name="display_bottom_margin" class="input" value="<?php echo (int)$displayBottomMargin; ?>" min="0" max="200" style="width:120px" required>
            </div>
            <button type="submit" class="btn btn-primary">保存设置</button>
        </div>
    </form>
</div>
<div class="card" id="sec-trash" style="margin-bottom:16px;scroll-margin-top:16px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:10px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;">🗑️ 图集回收站</h2>
    <p style="font-size:13px;color:#888;line-height:1.7;margin-bottom:10px;">
        班级里删除的图片 / 视频会在这里保留 7 天（班级端看不到回收站）。到期自动清理，期间可恢复或彻底删除。
    </p>
    <?php
    $trashTotal = 0;
    foreach ($classes as $cid => $c) {
        try { galleryTrashPurge($cid); } catch (Throwable $e) {}
        $trashItems = galleryTrashAll($cid);
        if (!$trashItems) continue;
        $trashTotal += count($trashItems);
        ?>
        <div style="margin-top:14px;">
            <div style="font-family:var(--font-heading);font-size:14px;margin-bottom:8px;">
                <?php echo htmlspecialchars($c['name']); ?>
                <span style="font-size:12px;color:#999;font-weight:400;">（<?php echo count($trashItems); ?> 条）</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">
                <?php foreach ($trashItems as $it):
                    $tImg = (string)($it['image'] ?? '');
                    $tIsMp4 = strtolower(pathinfo($tImg, PATHINFO_EXTENSION)) === 'mp4';
                    $tThumb = $tIsMp4
                        ? ('video_thumb.php?class_id=' . rawurlencode($cid) . '&file=' . rawurlencode($tImg))
                        : ('upload.php?class_id=' . rawurlencode($cid) . '&file=' . rawurlencode($tImg));
                    ?>
                    <div style="border:2px solid var(--pencil);border-radius:var(--wobbly-sm);background:var(--white);padding:8px;box-shadow:var(--shadow-sm);">
                        <img src="<?php echo htmlspecialchars($tThumb, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                             style="width:100%;height:120px;object-fit:cover;border-radius:6px;border:1.5px solid var(--old-paper);background:#f0f0f0;"
                             onerror="this.style.opacity=0.25;">
                        <div style="font-size:12px;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($it['description'] ?? '（无描述）')); ?>
                        </div>
                        <div style="font-size:11px;color:#999;margin-top:2px;">
                            <?php echo htmlspecialchars((string)($it['deleted_at'] ?? '')); ?> 删除 · 还剩 <?php echo galleryTrashDaysLeft($it); ?> 天
                        </div>
                        <div style="display:flex;gap:6px;margin-top:8px;">
                            <form method="post" style="flex:1;margin:0;">
                                <input type="hidden" name="action" value="restore_gallery_trash">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($cid, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)($it['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-sm" style="width:100%;">恢复</button>
                            </form>
                            <form method="post" style="flex:1;margin:0;"
                                  onsubmit="return lwConfirmSubmit(this, '彻底删除后文件不可恢复。', { okText: '彻底删除', danger: true })">
                                <input type="hidden" name="action" value="purge_gallery_trash">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($cid, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)($it['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="width:100%;">彻底删除</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    if ($trashTotal === 0) {
        echo '<div style="color:#888;font-size:13px;">回收站是空的</div>';
    }
    ?>
</div>

<div class="card" id="sec-announce" style="margin-bottom:16px;scroll-margin-top:60px;">
    <h2 style="font-family:var(--font-heading);font-size:18px;margin-bottom:16px;border-bottom:2px solid var(--old-paper);padding-bottom:10px;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg> 全服公告</h2>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--post-it);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);">
        <div><span style="display:block;font-size:11px;color:var(--pencil);margin-bottom:2px;opacity:0.7;">当前公告</span><b style="font-size:14px;"><?php echo count($announcements); ?> 条</b></div>
        <div><span style="display:block;font-size:11px;color:var(--red);margin-bottom:2px;font-weight:700;">服务器时间（以此为准）</span><b style="font-size:14px;color:var(--red);"><?php echo date('Y-m-d H:i'); ?></b></div>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save_announcement">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px">
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">公告内容 *</label>
                <input type="text" name="content" id="annContent" class="input" placeholder="例如：周五下午进行默写测试" style="width:100%" maxlength="200" required>
                <div style="font-size:11px;color:var(--pencil);opacity:0.6;margin-top:3px;"><span id="annCount">0</span>/<span id="annMax">200</span> 字 <span id="annLimitHint" style="display:none;color:var(--red);"></span></div>
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">颜色</label>
                <input type="color" name="color" value="#ff4d4d" style="width:44px;height:44px;border:2px solid var(--pencil);border-radius:var(--wobbly-sm);cursor:pointer;padding:2px;">
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">目标班级</label>
                <select name="target_classes[]" multiple style="height:80px;padding:6px;border:2px solid var(--pencil);border-radius:var(--wobbly-sm);font-size:12px;font-family:var(--font-body);background:var(--white);min-width:120px;">
                    <option value="all" selected>全部班级</option>
                    <?php foreach ($allClassIds as $cid): ?>
                    <option value="<?php echo htmlspecialchars($cid); ?>"><?php echo htmlspecialchars($classes[$cid]['name'] ?? $cid); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">投送平台</label>
                <div style="display:flex;gap:8px;padding:4px 0;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" name="target_platforms[]" value="app" checked> APP</label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" name="target_platforms[]" value="web" checked> Web</label>
                </div>
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">提醒类型</label>
                <div style="display:flex;gap:8px;padding:4px 0;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="mode" value="banner" checked onchange="toggleFsSec()"> 顶部横幅</label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="mode" value="fullscreen" onchange="toggleFsSec()"> 超级霸屏</label>
                </div>
            </div>
            <div id="fsSecWrap" style="display:none;">
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">霸屏时长(秒)</label>
                <input type="number" name="fullscreen_seconds" min="1" max="5" value="1" class="input" style="width:80px;">
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">允许关闭</label>
                <div style="padding:4px 0;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" name="allow_close" value="1" checked> 是</label>
                </div>
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">开始时间</label>
                <input type="datetime-local" name="start_time" class="input" style="width:180px" required value="<?php echo $serverNowInput; ?>">
            </div>
            <div>
                <label style="font-size:12px;color:var(--pencil);display:block;margin-bottom:2px;font-family:var(--font-heading);">结束时间</label>
                <input type="datetime-local" name="end_time" class="input" style="width:180px" required value="<?php echo $serverEndInput; ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">发布公告</button>
        </div>
        <p style="margin-top:8px;font-size:12px;color:var(--pencil);opacity:0.7;">已预填服务器当前时间（默认发布后立即生效，可自行改为预约时段）。公告仅在「开始~结束」时间段内显示。同一时间段同类公告只允许一条（顶部横幅与超级霸屏可共存）。</p>
    </form>
    <script>
    var ANN_FS_LIMIT = 60;
    var ANN_BANNER_LIMIT = 200;
    function annModeLimit() {
        return document.querySelector('input[name="mode"]:checked').value === 'fullscreen' ? ANN_FS_LIMIT : ANN_BANNER_LIMIT;
    }
    function toggleFsSec() {
        var fs = document.querySelector('input[name="mode"]:checked').value === 'fullscreen';
        document.getElementById('fsSecWrap').style.display = fs ? '' : 'none';
        var c = document.getElementById('annContent');
        var max = annModeLimit();
        if (c) {
            c.maxLength = max;
            document.getElementById('annMax').textContent = max;
            document.getElementById('annLimitHint').style.display = fs ? '' : 'none';
            document.getElementById('annLimitHint').textContent = '超级霸屏限制 ' + max + ' 字以内（大字全屏展示，过长会溢出）';
            updateAnnCount();
        }
    }
    function updateAnnCount() {
        var c = document.getElementById('annContent');
        if (!c) return;
        var len = (c.value || '').length;
        var max = annModeLimit();
        document.getElementById('annCount').textContent = len;
        document.getElementById('annCount').style.color = len > max ? 'var(--red)' : '';
    }
    document.addEventListener('DOMContentLoaded', function() {
        var c = document.getElementById('annContent');
        if (c) {
            c.addEventListener('input', updateAnnCount);
            var radios = document.querySelectorAll('input[name="mode"]');
            radios.forEach(function(r) { r.addEventListener('change', toggleFsSec); });
        }
        toggleFsSec();
    });
    </script>
    <script>
    (function() {
        var serverNow = '<?php echo $serverNowInput; ?>';
        var form = document.querySelector('form input[name="start_time"]');
        if (form) {
            var annForm = form.closest('form');
            annForm.addEventListener('submit', function(e) {
                var startVal = form.value;
                if (!startVal || startVal <= serverNow) return;
                if (annForm.dataset.lwOk === '1') return;
                e.preventDefault();
                lwConfirm('开始时间（' + startVal + '）晚于服务器当前时间（' + serverNow + '），公告需到点才显示。', { title: '这个公告还不会立刻显示', okText: '照常发布' })
                    .then(function(ok) { if (ok) { annForm.dataset.lwOk = '1'; annForm.submit(); } });
            });
        }
    })();
    </script>

    <?php if (count($announcements) > 0): ?>
    <h3 style="font-family:var(--font-heading);font-size:15px;margin:18px 0 10px;color:var(--pencil);">公告列表</h3>
    <div style="overflow-x:auto;border:2px solid var(--old-paper);border-radius:var(--wobbly-sm);">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">内容</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">类型</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">颜色</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">班级</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">平台</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">开始</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">结束</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">状态</th>
                    <th style="padding:6px 8px;border-bottom:2px solid var(--old-paper);text-align:left;background:var(--old-paper);color:var(--pencil);font-weight:700;font-family:var(--font-heading);">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($announcements as $ann): ?>
                <?php
                $now = time();
                $annStartTs = strtotime((string)($ann['start_time'] ?? ''));
                $annEndTs = strtotime((string)($ann['end_time'] ?? ''));
                $active = $annStartTs !== false && $annEndTs !== false && $annStartTs <= $now && $annEndTs >= $now;
                $future = $annStartTs !== false && $annEndTs !== false && $annStartTs > $now;
                ?>
                <tr>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($ann['content']); ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;white-space:nowrap;"><?php echo ($ann['mode'] ?? 'banner') === 'fullscreen' ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg> 霸屏' . (int)($ann['fullscreen_seconds'] ?? 1) . 's' : '顶部横幅'; ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:<?php echo htmlspecialchars($ann['color'] ?? '#ff4d4d'); ?>;border:1px solid var(--pencil);"></span></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><?php echo in_array('all', $ann['target_classes'] ?? []) ? '全部' : implode(', ', $ann['target_classes']); ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;"><?php echo implode(', ', $ann['target_platforms'] ?? []); ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;white-space:nowrap;"><?php echo htmlspecialchars($ann['start_time']); ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;white-space:nowrap;"><?php echo htmlspecialchars($ann['end_time']); ?></td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;">
                        <?php if ($active): ?><span style="color:var(--blue);font-weight:700;">进行中</span>
                        <?php elseif ($future): ?><span style="color:#888;">待生效</span>
                        <?php else: ?><span style="color:var(--red);">已过期</span><?php endif; ?>
                    </td>
                    <td style="padding:6px 8px;border-bottom:2px solid var(--old-paper);vertical-align:top;">
                        <form method="post" style="display:inline;" onsubmit="return lwConfirmSubmit(this, '确认删除此公告？', { okText: '删除', danger: true })">
                            <input type="hidden" name="action" value="delete_announcement">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($ann['id']); ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px;padding:2px 8px;">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// 侧边栏高亮：按可视顺序（flex order 已重排）取「最后一个进入滚动区顶部」的分区。
// 注意：本站的滚动容器是 .content，不是 window。
(function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.admin-side-link'));
    if (!links.length) return;
    var scroller = document.querySelector('.content');
    var sections = links
        .map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); })
        .filter(Boolean)
        .sort(function (a, b) { return a.offsetTop - b.offsetTop; });
    function setActive(id) {
        links.forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + id);
        });
    }
    function onScroll() {
        if (!sections.length) return;
        var base = scroller ? scroller.getBoundingClientRect().top : 0;
        var active = sections[0].id;
        for (var i = 0; i < sections.length; i++) {
            if (sections[i].getBoundingClientRect().top - base <= 120) active = sections[i].id;
        }
        setActive(active);
    }
    (scroller || window).addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('hashchange', function () { setActive(location.hash.slice(1)); });
    onScroll();
    if (location.hash) setActive(location.hash.slice(1));
})();
</script>

</div><!-- /admin-sections -->
</div><!-- /admin-main -->
</div><!-- /admin-shell -->

<?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <h3 class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg> 删除班级</h3>
        <p style="text-align:center;margin-bottom:12px;color:var(--pencil);">请输入班级名确认删除：<br><b id="deleteClassName"></b></p>
        <form method="post">
            <input type="hidden" name="action" value="delete_class">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="class_id" id="deleteClassId">
            <div class="form-group">
                <input type="text" class="input" name="confirm_name" placeholder="输入班级名确认" required>
            </div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="document.getElementById('deleteModal').classList.remove('active')">取消</button>
                <button type="submit" class="delete">确认删除</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="resetPwModal">
    <div class="modal-content">
        <h3 class="modal-title">🔑 重置班级口令</h3>
        <p style="text-align:center;margin-bottom:12px;color:var(--pencil);">班级：<b id="resetPwClassName"></b></p>
        <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="class_id" id="resetPwClassId">
            <div class="form-group">
                <input type="password" class="input" name="new_password" placeholder="新口令（至少4位字母或数字）" minlength="4" pattern="[a-zA-Z0-9]+" required>
            </div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="document.getElementById('resetPwModal').classList.remove('active')">取消</button>
                <button type="submit" class="submit">确认修改</button>
            </div>
        </form>
    </div>
</div>

<script>
function showDelete(id, name) {
    document.getElementById('deleteClassId').value = id;
    document.getElementById('deleteClassName').textContent = name;
    document.getElementById('deleteModal').classList.add('active');
}
function showResetPw(id, name) {
    document.getElementById('resetPwClassId').value = id;
    document.getElementById('resetPwClassName').textContent = name;
    document.getElementById('resetPwModal').classList.add('active');
}
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});
</script>
</body>
</html>
