<?php
/**
 * ============================================================
 * 设置页面
 * ============================================================
 *
 * 配置项 (存储在 data/settings.json):
 *   default_volume   — 默认音量 0-100 (听写模式初始值)
 *   default_interval — 默认单词间隔 (秒, 1-20) (听写模式初始值)
 *   default_repeat   — 单词朗读次数 1-10 (发音按钮重复次数)
 *
 * 同步机制:
 *   听写准备环节修改音量/间隔后，开始听写时自动回写 settings.json
 *
 * URL 参数:
 *   ?id={classId} — 班级ID (必填，用于返回主页)
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
$csrfToken = csrfToken(); // CSRF令牌，供前端POST使用
$classId = $_GET['id'] ?? '';
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];

// Check password protection
requireClassAuth($classId, $class);
$settings = Database::getSettings();

// Handle save
if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    requireCsrf(); // CSRF校验
    // 听写参数按班级隔离存储: volume_{classId} / interval_{classId} / repeat_{classId}
    if (isset($_POST['default_volume'])) $settings['volume_' . $classId] = max(0, min(100, intval($_POST['default_volume'])));
    if (isset($_POST['default_interval'])) $settings['interval_' . $classId] = max(1, min(20, floatval($_POST['default_interval'])));
    if (isset($_POST['default_repeat'])) $settings['repeat_' . $classId] = max(1, min(10, intval($_POST['default_repeat'])));
    if (isset($_POST['default_repeat_interval'])) $settings['repeat_interval_' . $classId] = max(0.5, min(5, floatval($_POST['default_repeat_interval'])));
    if (isset($_POST['follow_repeat'])) $settings['follow_repeat'] = max(1, min(5, intval($_POST['follow_repeat'])));
    if (isset($_POST['follow_buffer'])) $settings['follow_buffer'] = max(0, min(5, floatval($_POST['follow_buffer'])));
    Database::saveSettings($settings);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// 听写参数按班级读取: 班级键 -> default_* 全局默认 -> 固定默认值
$defaultVolume = $settings['volume_' . $classId] ?? $settings['default_volume'] ?? 80;
$defaultInterval = $settings['interval_' . $classId] ?? $settings['default_interval'] ?? 5;
$defaultRepeat = $settings['repeat_' . $classId] ?? $settings['default_repeat'] ?? 1;
$defaultRepeatInterval = $settings['repeat_interval_' . $classId] ?? $settings['default_repeat_interval'] ?? 1;
$followRepeat = $settings['follow_repeat'] ?? 1;
$followBuffer = $settings['follow_buffer'] ?? 0.5;
?>
<?php $pageTitle = '设置'; require 'inc/head.php'; ?>
</head>
<body>
    <script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
    <?php
    $backUrl = 'main.php?id=' . $classId;
    $className = $class['name'];
    $pageTitle = '设置';
    require 'inc/header.php';
    ?>
    <div class="content">
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>听写设置
            </div>
            <div class="mb-4">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">默认音量</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="volumeValue"><?php echo $defaultVolume; ?>%</span>
                </div>
                <input type="range" id="volumeSlider" min="0" max="100" value="<?php echo $defaultVolume; ?>" oninput="document.getElementById('volumeValue').textContent = this.value + '%'" style="accent-color:var(--blue);width:100%;">
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">默认单词间隔</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="intervalValue"><?php echo $defaultInterval; ?> 秒</span>
                </div>
                <input type="number" class="input" id="intervalInput" value="<?php echo $defaultInterval; ?>" min="1" max="20" step="0.5" oninput="document.getElementById('intervalValue').textContent = this.value + ' 秒'">
            </div>
        </div>
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>朗读设置
            </div>
            <div class="mb-4">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">单词朗读次数</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="repeatValue"><?php echo $defaultRepeat; ?> 次</span>
                </div>
                <input type="number" class="input" id="repeatInput" value="<?php echo $defaultRepeat; ?>" min="1" max="10" step="1" oninput="document.getElementById('repeatValue').textContent = this.value + ' 次'">
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">重复朗读间隔</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="repeatIntValue"><?php echo $defaultRepeatInterval; ?> 秒</span>
                </div>
                <input type="number" class="input" id="repeatIntInput" value="<?php echo $defaultRepeatInterval; ?>" min="0.5" max="5" step="0.5" oninput="document.getElementById('repeatIntValue').textContent = this.value + ' 秒'">
            </div>
        </div>
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>跟读设置
            </div>
            <div class="mb-4">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">每个单词朗读次数</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="followRepValue"><?php echo $followRepeat; ?> 次</span>
                </div>
                <input type="number" class="input" id="followRepInput" value="<?php echo $followRepeat; ?>" min="1" max="5" step="1" oninput="document.getElementById('followRepValue').textContent=this.value+' 次'">
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:14px;color:var(--pencil);">缓冲时间（朗读完单词后的额外等待）</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="followBufValue"><?php echo $followBuffer; ?> 秒</span>
                </div>
                <input type="number" class="input" id="followBufInput" value="<?php echo $followBuffer; ?>" min="0" max="5" step="0.5" oninput="document.getElementById('followBufValue').textContent = this.value + ' 秒'">
            </div>
        </div>
        <div class="card mb-3" style="text-align:center;padding:18px;">
            <button onclick="try{localStorage.removeItem('guide_done')}catch(e){};showOkOverlayThen('main.php?id=<?php echo rawurlencode($classId); ?>')" class="btn btn-secondary" style="font-size:14px;">重新查看使用说明</button>
        </div>
        <button class="btn btn-primary mt-3" style="width:100%" onclick="saveSettings()">保存设置</button>
    </div>
    <div class="toast" id="toast"></div>

    <script src="common.js?v=3"></script>
    <script>var speakRepeat = <?php echo $defaultRepeat; ?>;</script>
    <script>
        async function saveSettings() {
            const fd = new FormData();
            fd.append('action', 'save_settings');
            fd.append('default_volume', document.getElementById('volumeSlider').value);
            fd.append('default_interval', document.getElementById('intervalInput').value);
            fd.append('default_repeat', document.getElementById('repeatInput').value);
            fd.append('default_repeat_interval', document.getElementById('repeatIntInput').value);
            fd.append('follow_repeat', document.getElementById('followRepInput').value);
            fd.append('follow_buffer', document.getElementById('followBufInput').value);
            fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('settings.php?id=<?php echo $classId; ?>', { method: 'POST', body: fd })).json();
            if (d.success) {
                showToast('设置已保存', 'success');
            }
        }
    </script>
</body>
</html>
