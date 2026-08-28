<?php
/**
 * 管理员页面 — 班级管理 + APP 版本发布
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
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
$adminPassword = $config['admin_password'] ?? 'change-this-password';
$sessionTtl = $config['admin_session_ttl'] ?? 1800;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
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
    if (count($failures) >= $maxFailures) {
        $_SESSION['admin_login_failures'] = $failures;
        $loginError = '登录失败次数过多，请10分钟后重试';
    } elseif (hash_equals((string)$adminPassword, (string)reqPost('password'))) {
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
}
$versionData = Database::read('app_versions.json');
if (!is_array($versionData)) $versionData = ['latest' => '1.0', 'history' => []];
$versionHistory = array_reverse($versionData['history'] ?? []);
?><?php $pageTitle = '管理后台'; require 'inc/head.php'; ?>
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

<?php if ($msg): ?><div class="card <?php echo strpos($msg,'已')!==false||strpos($msg,'成功')!==false||strpos($msg,'发布')!==false?'card-post-it':''; ?>" style="padding:10px 18px;margin-bottom:12px;font-size:14px;<?php echo strpos($msg,'已')!==false||strpos($msg,'成功')!==false||strpos($msg,'发布')!==false?'':'color:var(--red);'; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<!-- 分区导航：长页面快速跳转 -->
<div style="position:sticky;top:0;z-index:100;display:flex;gap:8px;flex-wrap:wrap;background:var(--paper);padding:8px 0;margin-bottom:14px;border-bottom:2px solid var(--pencil);">
    <a href="#sec-classes" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">🏫 班级管理</a>
    <a href="#sec-app" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">📱 APP 发布</a>
    <a href="#sec-announce" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg> 公告管理</a>
    <a href="#sec-display" class="btn btn-sm btn-secondary" style="text-decoration:none;margin:0;">🖥️ 大屏设置</a>
    <span style="flex:1;"></span>
    <form method="post" style="display:inline;margin:0;">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-sm" style="margin:0;opacity:0.5;color:var(--pencil);">退出登录</button>
    </form>
</div>

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
            form.closest('form').addEventListener('submit', function(e) {
                var startVal = form.value;
                if (startVal && startVal > serverNow) {
                    if (!confirm('开始时间（' + startVal + '）晚于服务器当前时间（' + serverNow + '），公告需到点才显示。确定要发布吗？')) {
                        e.preventDefault();
                    }
                }
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
                        <form method="post" style="display:inline;" onsubmit="return confirm('确认删除此公告？')">
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
