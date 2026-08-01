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
                $dir = dirname(Database::getFilePath('classes.json'));
                foreach (scandir($dir) ?: [] as $f) {
                    if (preg_match('/\Apersonal_history_[A-Za-z0-9_-]+_' . preg_quote($delId, '/') . '\.json\z/D', $f)) Database::delete($f);
                }
                foreach (['words_','tasks_','history_'] as $pre) Database::delete($pre . $delId . '.json');
                $upDir = Database::getUploadsDirectory($delId);
                if (is_dir($upDir)) { foreach (scandir($upDir) ?: [] as $f) { if ($f !== '.' && $f !== '..') @unlink($upDir . DIRECTORY_SEPARATOR . $f); } @rmdir($upDir); }
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
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理员 - ListenWrite</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#eef1f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.container{width:90%;max-width:640px}
h1{text-align:center;color:#333;margin-bottom:24px;font-size:24px}
.login-box{background:#fff;border-radius:14px;padding:32px 28px;box-shadow:0 2px 12px rgba(0,0,0,0.08);text-align:center}
.login-box input{width:100%;padding:12px 16px;border:2px solid #ddd;border-radius:10px;font-size:16px;text-align:center;outline:none;margin-bottom:16px}
.login-box input:focus{border-color:#4a90d9}
.login-box button{padding:12px 32px;background:#4a90d9;color:#fff;border:none;border-radius:10px;font-size:16px;cursor:pointer;font-weight:600}
.login-box .err{color:#e53935;margin-top:8px;font-size:14px}
.panel{background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,0.08);margin-bottom:16px}
.panel h2{font-size:18px;color:#333;margin-bottom:16px;border-bottom:1px solid #eee;padding-bottom:10px}
.class-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f5f5f5}
.class-row:last-child{border-bottom:none}
.class-row .cname{font-weight:600;color:#333;font-size:15px}
.class-row .cactions{display:flex;gap:8px}
.btn-sm{padding:6px 14px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;transition:all .12s}
.btn-danger{background:#fff;color:#e53935;border:1.5px solid #e53935}
.btn-danger:hover{background:#ffebee}
.btn-warn{background:#fff;color:#ff9800;border:1.5px solid #ff9800}
.btn-warn:hover{background:#fff8e1}
.msg{padding:10px 18px;border-radius:10px;margin-bottom:12px;font-size:14px}
.msg.success{background:#e8f5e9;color:#43a047}
.msg.error{background:#ffebee;color:#e53935}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:1000;align-items:center;justify-content:center}
.modal.active{display:flex}
.modal-content{background:#fff;padding:24px;border-radius:14px;width:90%;max-width:380px}
.modal-content h3{font-size:17px;margin-bottom:14px;text-align:center}
.modal-content input{width:100%;padding:10px 14px;border:2px solid #ddd;border-radius:8px;font-size:15px;margin-bottom:12px;outline:none}
.modal-content input:focus{border-color:#4a90d9}
.modal-btns{display:flex;gap:8px}
.modal-btns button{flex:1;padding:10px;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600}
.btn-cancel{background:#e0e0e0;color:#333}
.btn-submit{background:#4a90d9;color:#fff}
.logout-btn{display:block;margin-top:12px;text-align:center;color:#999;font-size:13px;cursor:pointer}
.ver-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.ver-row input[type=text]{padding:10px;border:2px solid #ddd;border-radius:8px;font-size:14px;outline:none}
.ver-row input[type=text]:focus{border-color:#4a90d9}
.ver-row label{font-size:12px;color:#666;display:block;margin-bottom:2px}
.ver-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:#f7f9fc;border-radius:10px}
.ver-meta .k{display:block;font-size:11px;color:#888;margin-bottom:2px}
.ver-meta b{font-size:14px;color:#333}
.ver-hint{margin-top:10px;font-size:12px;color:#888;line-height:1.5}
.ver-hint code{background:#f0f0f0;padding:1px 6px;border-radius:4px}
.ver-log-title{font-size:15px;margin:18px 0 10px;color:#333}
.ver-log-wrap{overflow-x:auto;border:1px solid #eee;border-radius:10px}
.ver-log{width:100%;border-collapse:collapse;font-size:12px}
.ver-log th,.ver-log td{padding:8px 10px;border-bottom:1px solid #f0f0f0;text-align:left;vertical-align:top}
.ver-log th{background:#fafafa;color:#666;font-weight:600;white-space:nowrap}
.ver-log tr:last-child td{border-bottom:none}
.ver-log code{background:#f3f5f8;padding:1px 6px;border-radius:4px}
.ver-log .muted{color:#999}
</style>
</head>
<body>
<div class="container">
<h1>ListenWrite 管理</h1>

<?php if (!$isAuthed): ?>
<div class="login-box">
    <h2 style="margin-bottom:18px;">管理员登录</h2>
    <form method="post">
        <input type="hidden" name="action" value="admin_login">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="password" name="password" placeholder="请输入管理员密码" autofocus>
        <button type="submit">登录</button>
        <?php if (isset($loginError)): ?><div class="err"><?php echo $loginError; ?></div><?php endif; ?>
    </form>
</div>
<?php else: ?>

<?php if ($msg): ?><div class="msg <?php echo strpos($msg,'已')!==false||strpos($msg,'成功')!==false||strpos($msg,'发布')!==false?'success':'error'; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<div class="panel">
    <h2>班级列表 (<?php echo count($classes); ?>个)</h2>
    <?php if (empty($classes)): ?>
        <p style="color:#999;text-align:center;padding:20px;">暂无班级</p>
    <?php else: ?>
        <?php foreach ($classes as $id => $c): ?>
        <div class="class-row">
            <span class="cname"><?php echo htmlspecialchars($c['name']); ?><?php if (!empty($c['password_hash'])) echo ' 🔒'; ?></span>
            <div class="cactions">
                <button type="button" class="btn-sm btn-warn" onclick="showResetPw('<?php echo $id; ?>','<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">重置口令</button>
                <button type="button" class="btn-sm btn-danger" onclick="showDelete('<?php echo $id; ?>','<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">删除</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>📱 APP 版本发布</h2>
    <div class="ver-meta">
        <div><span class="k">当前线上版本</span><b><?php echo htmlspecialchars((string)($versionData['latest'] ?? '1.0')); ?></b></div>
        <div><span class="k">发布记录</span><b><?php echo count($versionHistory); ?> 条</b></div>
        <div><span class="k">服务时间</span><b><?php echo date('Y-m-d H:i:s'); ?></b></div>
    </div>
    <form method="post" class="ver-form">
        <input type="hidden" name="action" value="publish_version">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="ver-row">
            <div>
                <label>版本号 *</label>
                <input type="text" name="version" placeholder="1.1.0" style="width:110px" required pattern="\d+\.\d+(\.\d+)?$" title="例如 1.1 或 1.1.0">
            </div>
            <div>
                <label>发布通道</label>
                <select name="channel" style="padding:10px;border:2px solid #ddd;border-radius:8px;font-size:14px;">
                    <option value="stable">stable（正式）</option>
                    <option value="beta">beta（测试）</option>
                </select>
            </div>
            <div style="flex:1;min-width:200px">
                <label>更新说明 / Release Notes</label>
                <input type="text" name="notes" placeholder="例如：修复登录闪退；优化错题本导出" style="width:100%" maxlength="500">
            </div>
            <button type="submit" class="btn-submit" style="padding:10px 20px;white-space:nowrap">发布新版本</button>
        </div>
        <p class="ver-hint">发布后 APP 端 <code>check_version</code> 将与当前客户端版本比较；用户可稍后更新（非强更）。</p>
    </form>

    <h3 class="ver-log-title">发布日志</h3>
    <?php if (empty($versionHistory)): ?>
        <p style="color:#999;font-size:13px;padding:8px 0;">暂无发布记录</p>
    <?php else: ?>
    <div class="ver-log-wrap">
        <table class="ver-log">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>版本</th>
                    <th>变更</th>
                    <th>通道</th>
                    <th>说明</th>
                    <th>来源 IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($versionHistory as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($row['published_at'] ?? $row['date'] ?? '-')); ?></td>
                    <td><code><?php echo htmlspecialchars((string)($row['version'] ?? '')); ?></code></td>
                    <td class="muted"><?php
                        $prev = (string)($row['previous'] ?? '');
                        $cur = (string)($row['version'] ?? '');
                        echo $prev !== '' ? htmlspecialchars($prev . ' → ' . $cur) : htmlspecialchars($cur);
                    ?></td>
                    <td><?php echo htmlspecialchars((string)($row['channel'] ?? 'stable')); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['notes'] ?? '')); ?></td>
                    <td class="muted"><?php echo htmlspecialchars((string)($row['ip'] ?? '-')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<form method="post">
    <input type="hidden" name="action" value="logout">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="logout-btn" type="submit">退出登录</button>
</form>

<?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <h3>⚠️ 删除班级</h3>
        <p style="text-align:center;margin-bottom:12px;color:#666;">请输入班级名确认删除：<br><b id="deleteClassName"></b></p>
        <form method="post">
            <input type="hidden" name="action" value="delete_class">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="class_id" id="deleteClassId">
            <input type="text" name="confirm_name" placeholder="输入班级名确认" required>
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="document.getElementById('deleteModal').classList.remove('active')">取消</button>
                <button type="submit" class="btn-submit" style="background:#e53935;">确认删除</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="resetPwModal">
    <div class="modal-content">
        <h3>🔑 重置班级口令</h3>
        <p style="text-align:center;margin-bottom:12px;color:#666;">班级：<b id="resetPwClassName"></b></p>
        <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="class_id" id="resetPwClassId">
            <input type="password" name="new_password" placeholder="新口令（至少4位字母或数字）" minlength="4" pattern="[a-zA-Z0-9]+" required>
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="document.getElementById('resetPwModal').classList.remove('active')">取消</button>
                <button type="submit" class="btn-submit">确认修改</button>
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
