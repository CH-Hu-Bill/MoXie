<?php
/**
 * 管理员页面 — 班级管理 + APP 版本发布
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
$config = require 'inc/config.php';
$adminPassword = $config['admin_password'] ?? 'change-this-password';
$sessionTtl = $config['admin_session_ttl'] ?? 1800;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['admin_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
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
    $failures = array_values(array_filter($_SESSION['admin_login_failures'] ?? [], function($t) use ($now) {
        return is_int($t) && $t > $now - 300;
    }));
    if (count($failures) >= 5) {
        $_SESSION['admin_login_failures'] = $failures;
        $loginError = '登录失败次数过多，请5分钟后重试';
    } elseif (hash_equals((string)$adminPassword, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true; $_SESSION['admin_time'] = $now;
        unset($_SESSION['admin_login_failures']);
        $isAuthed = true;
    } else {
        $failures[] = $now; $_SESSION['admin_login_failures'] = $failures;
        $loginError = '密码错误';
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
        $delId = trim((string)($_POST['class_id'] ?? ''));
        $confirmName = trim($_POST['confirm_name'] ?? '');
        if (isset($classes[$delId]) && $confirmName === $classes[$delId]['name']) {
            $deleted = false;
            $classes = Database::update('classes.json', function($latest) use ($delId, $confirmName, &$deleted) {
                if (!isset($latest[$delId]) || ($latest[$delId]['name'] ?? '') !== $confirmName) return null;
                unset($latest[$delId]); $deleted = true; return $latest;
            });
            if ($deleted) {
                Database::validateClassId($delId);
                Database::update('app_data.json', function($data) use ($delId) {
                    foreach (($data['users'] ?? []) as $uid => &$user) {
                        $user['class_ids'] = array_values(array_filter($user['class_ids'] ?? [], function($id) use ($delId) { return (string)$id !== $delId; }));
                        unset($user['class_auth_versions'][$delId], $user['wrong_words'][$delId]);
                    } unset($user); return $data;
                });
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
        $resetId = trim((string)($_POST['class_id'] ?? ''));
        $newPw = trim($_POST['new_password'] ?? '');
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
        $newVer = trim((string)($_POST['version'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $channel = trim((string)($_POST['channel'] ?? 'stable'));
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

<div class="card" style="margin-bottom:16px;">
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

<div class="card" style="margin-bottom:16px;">
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

<form method="post" style="text-align:center;margin-top:12px;">
    <input type="hidden" name="action" value="logout">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--pencil);opacity:0.5;">退出登录</button>
</form>

<?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <h3 class="modal-title">⚠️ 删除班级</h3>
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
