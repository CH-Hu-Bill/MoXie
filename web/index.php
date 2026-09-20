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
    $cid = reqPost('class_id');
    $pw = reqPost('password');
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
$needAuth = reqGet('need_auth');
// 如果是鉴权失败跳转来的，不自动跳转，直接显示密码输入框
if ($currentClassId && isset($classes[$currentClassId]) && !$switching && !$needAuth) {
    header('Location: main.php?id=' . $currentClassId);
    exit;
}
$createError = '';
if (isset($_POST['action']) && $_POST['action'] === 'create_class') {
    requireCsrf();
    $name = trim(reqPost('class_name'));
    $password = trim(reqPost('class_password'));
    if ($name !== '' && mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 30 && mb_strlen($password) >= 4 && preg_match('/^[a-zA-Z0-9]+$/', $password)) {
        $dup = false;
        foreach ($classes as $c) {
            if (mb_strtolower((string)($c['name'] ?? '')) === mb_strtolower($name)) { $dup = true; break; }
        }
        if ($dup) {
            $createError = '班级名已存在，请换一个名称';
        } else {
            $id = uniqid();
            $classes[$id] = ['id' => $id, 'name' => $name, 'created_at' => date('Y-m-d H:i:s'), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'auth_version' => 1];
            if (!Database::saveClasses($classes)) {
                // 写盘失败（名称编码异常使 json_encode 失败、或 web/data 不可写）：不能假装成功
                unset($classes[$id]);
                $createError = '保存失败：班级名称编码异常或 web/data 目录不可写，请重试';
            } else {
                setSecureCookie('current_class_id', $id, time() + 86400 * 365);
                setClassAuthCookie($id, 1);
                header('Location: main.php?id=' . $id);
                exit;
            }
        }
    } else {
        $createError = '班级名或口令格式不正确';
    }
}

// ---- 从 zip 导入班级（不含任何用户账号信息）----
if (isset($_POST['action']) && $_POST['action'] === 'import_class') {
    header('Content-Type: application/json; charset=UTF-8');
    requireCsrf();
    $newName = trim(reqPost('class_name'));
    $newPw = trim(reqPost('class_password'));
    if ($newName === '' || mb_strlen($newName) > 30) { echo json_encode(['success' => false, 'error' => '班级名称格式不正确']); exit; }
    if (mb_strlen($newPw) < 4 || !preg_match('/^[a-zA-Z0-9]+$/', $newPw)) { echo json_encode(['success' => false, 'error' => '口令需至少4位字母或数字']); exit; }
    foreach ($classes as $c) {
        if (mb_strtolower((string)($c['name'] ?? '')) === mb_strtolower($newName)) { echo json_encode(['success' => false, 'error' => '班级名已存在，请换一个名称']); exit; }
    }
    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'error' => '请选择要导入的 zip 文件']); exit; }
    if (!class_exists('ZipArchive')) { echo json_encode(['success' => false, 'error' => '服务器未启用 zip 扩展']); exit; }
    if (filesize($_FILES['zip_file']['tmp_name']) > 300 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => '文件过大（上限 300MB）']); exit; }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['zip_file']['tmp_name']) !== true) { echo json_encode(['success' => false, 'error' => '无法读取 zip 文件']); exit; }
    $manifestRaw = $zip->getFromName('manifest.json');
    $manifest = $manifestRaw ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'listenwrite-class-export') {
        $zip->close(); echo json_encode(['success' => false, 'error' => '不是有效的班级导出文件']); exit;
    }
    if ($zip->numFiles > 20000) { $zip->close(); echo json_encode(['success' => false, 'error' => '文件条目过多']); exit; }
    $totalUncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        $totalUncompressed += (int)($st['size'] ?? 0);
        if ($totalUncompressed > 1024 * 1024 * 1024) break;
    }
    if ($totalUncompressed > 1024 * 1024 * 1024) { $zip->close(); echo json_encode(['success' => false, 'error' => '压缩包解压后过大']); exit; }

    $newId = uniqid();
    $destDir = Database::getClassDir($newId);
    if (!is_dir($destDir) && !@mkdir($destDir, 0750, true) && !is_dir($destDir)) {
        $zip->close(); echo json_encode(['success' => false, 'error' => '无法创建班级目录']); exit;
    }
    $words = []; $tasks = []; $history = []; $gallery = []; $pron = []; $settingsSub = null;
    $uploadsDir = $destDir . '/uploads';
    $pronDir = $destDir . '/pronunciations';
    $badPath = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || strpos($name, '..') !== false) { $badPath = true; break; }
        if ($name === 'words.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $words = $d; }
        elseif ($name === 'tasks.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $tasks = $d; }
        elseif ($name === 'history.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $history = $d; }
        elseif ($name === 'gallery.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $gallery = $d; }
        elseif ($name === 'pronunciations.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $pron = $d; }
        elseif ($name === 'settings_class.json') { $d = json_decode($zip->getFromIndex($i), true); if (is_array($d)) $settingsSub = $d; }
        elseif (preg_match('#^uploads/([A-Za-z0-9._-]{1,128})$#', $name, $m)) {
            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0750, true);
            @file_put_contents($uploadsDir . '/' . $m[1], $zip->getFromIndex($i));
        }
        elseif (preg_match('#^pronunciations/([A-Za-z0-9_-]{1,64})/([a-f0-9]{32}\.m4a)$#', $name, $m)) {
            $d = $pronDir . '/' . $m[1];
            if (!is_dir($d)) @mkdir($d, 0750, true);
            @file_put_contents($d . '/' . $m[2], $zip->getFromIndex($i));
        }
        // 其它条目一律忽略（不导入任何用户数据）
    }
    $zip->close();
    if ($badPath) {
        Database::deleteClassDataDir($newId);
        echo json_encode(['success' => false, 'error' => '压缩包内含非法路径，已终止导入']); exit;
    }
    if (!empty($words)) Database::saveWords($newId, array_values($words));
    if (!empty($tasks)) {
        // 导入的进行中任务若已超过任务日期，视为已完成
        $importToday = date('Y-m-d');
        foreach ($tasks as $tid => $tk) {
            if (($tk['status'] ?? '') === 'pending' && ($tk['date'] ?? '') !== '' && $tk['date'] < $importToday) {
                $tasks[$tid]['status'] = 'completed';
            }
        }
        Database::saveTasks($newId, $tasks);
    }
    if (!empty($history)) Database::saveClassData($newId, 'history', $history);
    if (!empty($gallery)) Database::saveClassData($newId, 'gallery', $gallery);
    if (!empty($pron)) Database::saveClassData($newId, 'pronunciations', $pron);
    if (is_array($settingsSub)) {
        $allSettings = Database::getSettings();
        foreach ($settingsSub as $k => $v) {
            $allSettings[($k === 'ai' ? 'ai_' : $k . '_') . $newId] = $v;
        }
        Database::saveSettings($allSettings);
    }
    $classes[$newId] = ['id' => $newId, 'name' => $newName, 'created_at' => date('Y-m-d H:i:s'), 'password_hash' => password_hash($newPw, PASSWORD_DEFAULT), 'auth_version' => 1];
    Database::saveClasses($classes);
    setSecureCookie('current_class_id', $newId, time() + 86400 * 365);
    setClassAuthCookie($newId, 1);
    echo json_encode(['success' => true, 'class_id' => $newId]); exit;
}

$hasNoClass = empty($classes);
$needAuth = reqGet('need_auth');
if (!empty($createError)) {
    $hasNoClass = $hasNoClass || true; // keep modal open via class below
}
if ($needAuth && isset($classes[$needAuth]) && !isClassAuthenticated($needAuth, $classes[$needAuth])) {
    echo '<script>var needAuthId = ' . json_encode($needAuth, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
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
            <div style="margin-top:16px;display:flex;gap:10px;">
                <button class="btn btn-primary" style="flex:1;" onclick="document.getElementById('modal').classList.add('active')">+ 新建班级</button>
                <button class="btn btn-secondary" style="flex:1;" onclick="document.getElementById('importModal').classList.add('active')">导入班级</button>
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

    <div class="modal" id="importModal">
        <div class="modal-content">
            <div class="modal-title">导入班级</div>
            <div style="font-size:12px;color:#888;line-height:1.7;margin-bottom:12px;">
                选择之前「超级导出」的 zip 文件。导入后需在 APP 重新注册账号（导出文件不含任何用户信息）。
            </div>
            <form id="importForm">
                <div class="form-group"><input type="file" name="zip_file" accept=".zip" class="input" style="width:100%;"></div>
                <input type="text" id="importClassName" class="input" placeholder="新班级名称" maxlength="30" style="margin-bottom:14px;">
                <input type="password" id="importClassPw" class="input" placeholder="设置班级口令（至少4位字母或数字）" minlength="4" pattern="[a-zA-Z0-9]+" style="margin-bottom:14px;">
                <div style="color:var(--red);font-size:13px;text-align:center;min-height:18px;" id="importError"></div>
                <div class="modal-btns">
                    <button type="button" class="cancel" onclick="document.getElementById('importModal').classList.remove('active')">取消</button>
                    <button type="button" class="submit" onclick="importClass()">导入</button>
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
            var classes = <?php echo json_encode($publicClasses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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

        async function importClass() {
            const fi = document.querySelector('#importForm input[name="zip_file"]');
            const name = document.getElementById('importClassName').value.trim();
            const pw = document.getElementById('importClassPw').value.trim();
            const err = document.getElementById('importError');
            err.textContent = '';
            if (!fi.files[0]) { err.textContent = '请选择 zip 文件'; return; }
            if (!name) { err.textContent = '请输入班级名称'; return; }
            if (pw.length < 4 || !/^[a-zA-Z0-9]+$/.test(pw)) { err.textContent = '口令需至少4位字母或数字'; return; }
            const btn = document.querySelector('#importModal .submit');
            btn.disabled = true; btn.textContent = '导入中...';
            const fd = new FormData();
            fd.append('action', 'import_class');
            fd.append('zip_file', fi.files[0]);
            fd.append('class_name', name);
            fd.append('class_password', pw);
            fd.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
            try {
                const d = await (await fetch('index.php?switch=1', { method: 'POST', body: fd })).json();
                if (d.success) { location.href = 'main.php?id=' + d.class_id; }
                else { err.textContent = d.error || '导入失败'; }
            } catch(e) { err.textContent = '网络异常，请重试'; }
            btn.disabled = false; btn.textContent = '导入';
        }

        function selectClass(id) {
            var classes = <?php echo json_encode($publicClasses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
