<?php
/**
 * ============================================================
 * 班级选择/创建页面
 * ============================================================
 *
 * 入口页。首次访问时选择已有班级或创建新班级。
 * Cookie current_class_id 记录用户上次选择的班级。
 * 已有班级时自动跳转到 main.php。
 *
 * URL 参数:
 *   ?switch=1 — 强制显示班级选择页 (用于切换班级)
 *
 * 数据依赖:
 *   data/classes.json — 班级列表
 *   Cookie: current_class_id — 当前班级ID (365天有效)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
$classes = Database::getClasses();

// Handle password verification — 必须在自动跳转检查之前
if (isset($_POST['action']) && $_POST['action'] === 'verify_class_password') {
    header('Content-Type: application/json');
    requireCsrf();
    $cid = $_POST['class_id'] ?? '';
    $pw = $_POST['password'] ?? '';
    if (!isset($classes[$cid])) { echo json_encode(['success' => false, 'error' => '班级不存在']); exit; }
    $hash = $classes[$cid]['password_hash'] ?? null;
    if (!$hash || password_verify($pw, $hash)) {
        setClassAuthCookie($cid, $classes[$cid]['auth_version'] ?? 1);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => '口令错误']);
    }
    exit;
}

$currentClassId = $_COOKIE['current_class_id'] ?? null;
$switching = isset($_GET['switch']);
$needAuth = $_GET['need_auth'] ?? null;
// 如果是鉴权失败跳转来的，不自动跳转，直接显示密码输入框
if ($currentClassId && isset($classes[$currentClassId]) && !$switching && !$needAuth) {
    header('Location: main.php?id=' . $currentClassId);
    exit;
}
$createError = '';
if (isset($_POST['action']) && $_POST['action'] === 'create_class') {
    requireCsrf();
    $name = trim($_POST['class_name'] ?? '');
    $password = trim($_POST['class_password'] ?? '');
    if ($name !== '' && mb_strlen($name) <= 30 && mb_strlen($password) >= 4 && preg_match('/^[a-zA-Z0-9]+$/', $password)) {
        $dup = false;
        foreach ($classes as $c) {
            if (mb_strtolower((string)($c['name'] ?? '')) === mb_strtolower($name)) { $dup = true; break; }
        }
        if ($dup) {
            $createError = '班级名已存在，请换一个名称';
        } else {
            $id = uniqid();
            $classes[$id] = ['id' => $id, 'name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'auth_version' => 1];
            Database::saveClasses($classes);
            setSecureCookie('current_class_id', $id, time() + 86400 * 365);
            setClassAuthCookie($id, 1);
            header('Location: main.php?id=' . $id);
            exit;
        }
    } else {
        $createError = '班级名或口令格式不正确';
    }
}
$hasNoClass = empty($classes);
$needAuth = $_GET['need_auth'] ?? null;
if (!empty($createError)) {
    $hasNoClass = $hasNoClass || true; // keep modal open via class below
}
if ($needAuth && isset($classes[$needAuth]) && !isClassAuthenticated($needAuth, $classes[$needAuth])) {
    echo '<script>var needAuthId = ' . json_encode($needAuth) . ';</script>';
}
$publicClasses = [];
foreach ($classes as $id => $class) {
    $publicClasses[$id] = [
        'id' => $id,
        'name' => $class['name'] ?? '',
        'has_password' => !empty($class['password_hash']),
        'authenticated' => isClassAuthenticated($id, $class),
    ];
}
$csrfToken = csrfToken();
$pageTitle = '选择班级';
require 'inc/head.php';
?>
<body style="display:flex;align-items:center;justify-content:center;">
    <div style="width:90%;max-width:560px;">
        <h1 style="text-align:center;font-family:var(--font-heading);font-size:32px;margin-bottom:24px;color:var(--pencil);">选择班级</h1>
        <div class="card" style="padding:20px;">
            <?php if (empty($classes)): ?>
                <div class="empty-state">还没有班级，点击下方按钮创建</div>
            <?php else: ?>
                <?php foreach ($classes as $id => $class): ?>
                    <div class="card" style="margin-bottom:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:14px 18px;"
                         onclick="selectClass('<?php echo $id; ?>')">
                        <span style="font-family:var(--font-heading);font-size:17px;"><?php echo htmlspecialchars($class['name']); ?></span>
                        <span style="color:var(--old-paper);font-size:20px;font-weight:700;">→</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top:16px;">
                <button class="btn btn-primary" style="width:100%;" onclick="document.getElementById('modal').classList.add('active')">+ 新建班级</button>
            </div>
        </div>
    </div>

    <div class="modal <?php echo ($hasNoClass || !empty($createError)) ? 'active' : ''; ?>" id="modal">
        <div class="modal-content">
            <div class="modal-title">创建新班级</div>
            <?php if (!empty($createError)): ?>
                <div style="color:var(--red);text-align:center;margin-bottom:12px;font-size:14px;"><?php echo htmlspecialchars($createError); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="create_class">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="text" name="class_name" class="input" placeholder="请输入班级名称" maxlength="30" required autofocus value="<?php echo htmlspecialchars($_POST['class_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom:14px;">
                <input type="password" name="class_password" class="input" placeholder="设置班级口令（至少4位字母或数字）" minlength="4" pattern="[a-zA-Z0-9]+" autocomplete="new-password" required style="margin-bottom:14px;">
                <div class="modal-btns">
                    <?php if (!$hasNoClass): ?>
                        <button type="button" class="cancel" onclick="document.getElementById('modal').classList.remove('active')">取消</button>
                    <?php endif; ?>
                    <button type="submit" class="submit">确认创建</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="pwModal">
        <div class="modal-content">
            <div class="modal-title" id="pwModalTitle">班级口令</div>
            <p style="text-align:center;color:var(--pencil);margin-bottom:12px;opacity:0.7;" id="pwClassName"></p>
            <input type="password" id="pwInput" class="input" placeholder="请输入班级口令" autocomplete="current-password" onkeydown="if(event.key==='Enter')submitPassword()" style="margin-bottom:8px;">
            <div style="color:var(--red);font-size:13px;text-align:center;margin-top:8px;min-height:18px;" id="pwError"></div>
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="document.getElementById('pwModal').classList.remove('active');document.getElementById('pwInput').value='';document.getElementById('pwError').textContent='';">取消</button>
                <button type="button" class="submit" onclick="submitPassword()">确认</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        function showToast(msg, type) {
            var t = document.getElementById('toast');
            t.textContent = msg; t.className = 'toast ' + (type || '');
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function() { t.classList.remove('show'); }, 3000);
        }

        var pendingClassId = null;
        var pendingAutoId = (typeof needAuthId !== 'undefined' && needAuthId) ? needAuthId : null;

        if (pendingAutoId) {
            showToast('班级口令已更改，请重新验证', 'warn');
            setTimeout(function() { showPwModal(pendingAutoId); }, 1500);
        }

        function showPwModal(classId) {
            pendingClassId = classId;
            var classes = <?php echo json_encode($publicClasses); ?>;
            document.getElementById('pwClassName').textContent = '「' + (classes[classId] ? classes[classId].name : classId) + '」';
            document.getElementById('pwInput').value = '';
            document.getElementById('pwError').textContent = '';
            document.getElementById('pwModal').classList.add('active');
            setTimeout(function() { document.getElementById('pwInput').focus(); }, 200);
        }

        async function submitPassword() {
            var pw = document.getElementById('pwInput').value.trim();
            if (!pw) { document.getElementById('pwError').textContent = '请输入口令'; return; }
            var btn = document.querySelector('#pwModal .submit');
            btn.disabled = true; btn.textContent = '验证中...';
            var fd = new FormData();
            fd.append('action', 'verify_class_password');
            fd.append('class_id', pendingClassId);
            fd.append('password', pw);
            fd.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
            try {
                var resp = await fetch('index.php', { method: 'POST', body: fd });
                if (!resp.ok) {
                    document.getElementById('pwError').textContent = '服务器错误 (' + resp.status + ')，请刷新页面重试';
                    btn.disabled = false; btn.textContent = '确认';
                    return;
                }
                var r = await resp.json();
                if (r.success) {
                    document.getElementById('pwModal').classList.remove('active');
                    location.href = 'main.php?id=' + pendingClassId;
                } else {
                    document.getElementById('pwError').textContent = r.error || '口令错误';
                }
            } catch(e) {
                document.getElementById('pwError').textContent = '网络异常: ' + (e.message || '请刷新重试');
            }
            btn.disabled = false; btn.textContent = '确认';
        }

        if (pendingAutoId) {
            setTimeout(function() { showPwModal(pendingAutoId); }, 300);
        }

        function selectClass(id) {
            var classes = <?php echo json_encode($publicClasses); ?>;
            var c = classes[id];
            if (c && c.has_password) {
                if (c.authenticated) {
                    location.href = 'main.php?id=' + id;
                    return;
                }
                showPwModal(id);
                return;
            }
            document.cookie = 'current_class_id=' + id + ';path=/;max-age=' + (86400 * 365) + ';SameSite=Lax';
            location.href = 'main.php?id=' + id;
        }
    </script>
</body>
</html>
