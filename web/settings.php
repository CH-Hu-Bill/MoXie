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
 *   follow_repeat    — 跟读每词朗读次数 1-5 (按班级)
 *   follow_buffer    — 跟读缓冲时间 -0.5~5 秒 (按班级; 每遍读完停顿 = 音频时长 + 此值, 负数提前, 实际停顿 ≥0)
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
$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];

// Check password protection
requireClassAuth($classId, $class);
$settings = Database::getSettings();

// ===== AI 设置（按班级，Web + APP 共用）=====
if (isset($_POST['action']) && $_POST['action'] === 'save_ai') {
    requireCsrf();
    header('Content-Type: application/json');
    $aiProvider = reqPost('ai_provider');
    if (!in_array($aiProvider, ['openai', 'anthropic'], true)) {
        unset($settings['ai_' . $classId]);
        Database::saveSettings($settings);
        echo json_encode(['success' => true]); exit;
    }
    $aiEndpoint = trim(reqPost('ai_endpoint'));
    $aiKey      = trim(reqPost('ai_api_key'));
    $aiModel    = trim(reqPost('ai_model'));
    if ($aiEndpoint === '' || $aiKey === '' || $aiModel === '') {
        echo json_encode(['success' => false, 'error' => '请填写完整的接口地址、API 密钥和模型名']); exit;
    }
    if (strlen($aiEndpoint) > 500 || strlen($aiKey) > 500 || strlen($aiModel) > 200) {
        echo json_encode(['success' => false, 'error' => '输入内容过长']); exit;
    }
    $settings['ai_' . $classId] = [
        'provider' => $aiProvider,
        'endpoint' => $aiEndpoint,
        'api_key'  => $aiKey,
        'model'    => $aiModel,
    ];
    Database::saveSettings($settings);
    echo json_encode(['success' => true]); exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'ai_test') {
    requireCsrf();
    header('Content-Type: application/json');
    require_once 'inc/api.php';
    $cfg = [
        'provider' => reqPost('ai_provider'),
        'endpoint' => trim(reqPost('ai_endpoint')),
        'api_key'  => trim(reqPost('ai_api_key')),
        'model'    => trim(reqPost('ai_model')),
    ];
    if (!in_array($cfg['provider'], ['openai', 'anthropic'], true)
        || $cfg['endpoint'] === '' || $cfg['api_key'] === '' || $cfg['model'] === '') {
        echo json_encode(['success' => false, 'error' => '请先完整填写格式、接口地址、密钥和模型名']); exit;
    }
    $cfg['endpoint'] = AIClient::normalizeEndpoint($cfg['provider'], $cfg['endpoint']);
    echo json_encode(AIClient::testConfig($cfg)); exit;
}

// Handle save
if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    requireCsrf(); // CSRF校验
    // 听写参数按班级隔离存储: volume_{classId} / interval_{classId} / repeat_{classId}
    if (isset($_POST['default_volume'])) $settings['volume_' . $classId] = max(0, min(100, intval($_POST['default_volume'])));
    if (isset($_POST['default_interval'])) $settings['interval_' . $classId] = max(1, min(20, floatval($_POST['default_interval'])));
    if (isset($_POST['default_repeat'])) $settings['repeat_' . $classId] = max(1, min(10, intval($_POST['default_repeat'])));
    if (isset($_POST['default_repeat_interval'])) $settings['repeat_interval_' . $classId] = max(0.5, min(5, floatval($_POST['default_repeat_interval'])));
    // 跟读参数同样按班级隔离存储（与听写/朗读一致）
    if (isset($_POST['follow_repeat'])) $settings['follow_repeat_' . $classId] = max(1, min(5, intval($_POST['follow_repeat'])));
    if (isset($_POST['follow_buffer'])) $settings['follow_buffer_' . $classId] = max(-0.5, min(5, floatval($_POST['follow_buffer'])));
    // 图集公开 API 密钥保护（按班级存储）
    $galleryKeyEnabled = reqPost('gallery_api_enabled') === '1';
    $galleryApiKey = trim(reqPost('gallery_api_key'));
    if ($galleryKeyEnabled && (strlen($galleryApiKey) < 8 || strlen($galleryApiKey) > 128)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'API 密钥需为 8-128 位字符']);
        exit;
    }
    $settings['gallery_api_key_' . $classId] = $galleryKeyEnabled ? $galleryApiKey : '';
    // 展示大屏 token（按班级存储，用于带 token 链接免口令直达壁纸页）
    if (isset($_POST['display_token'])) {
        $displayToken = trim(reqPost('display_token'));
        if ($displayToken !== '' && !preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', $displayToken)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => '展示大屏 token 需为 8-64 位字母/数字/-_']);
            exit;
        }
        $settings['display_token_' . $classId] = $displayToken;
    }
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
// 跟读参数按班级读取（兼容旧版全局键作回退）
$followRepeat = $settings['follow_repeat_' . $classId] ?? $settings['follow_repeat'] ?? 1;
$followBuffer = $settings['follow_buffer_' . $classId] ?? $settings['follow_buffer'] ?? 0.5;
$galleryApiKey = (string)($settings['gallery_api_key_' . $classId] ?? '');
$galleryApiEnabled = $galleryApiKey !== '';
$displayToken = (string)($settings['display_token_' . $classId] ?? '');
// AI 配置（按班级）
$aiCfg = $settings['ai_' . $classId] ?? null;
$aiProvider = (is_array($aiCfg) && in_array(($aiCfg['provider'] ?? ''), ['openai', 'anthropic'], true)) ? $aiCfg['provider'] : 'none';
$aiEndpoint = is_array($aiCfg) ? (string)($aiCfg['endpoint'] ?? '') : '';
$aiApiKey   = is_array($aiCfg) ? (string)($aiCfg['api_key'] ?? '') : '';
$aiModel    = is_array($aiCfg) ? (string)($aiCfg['model'] ?? '') : '';
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
    <style>
        .ai-seg { display: flex; gap: 8px; flex-wrap: wrap; }
        .ai-seg-btn { flex: 1; min-width: 92px; padding: 8px 10px; border: 2px solid var(--pencil); border-radius: var(--wobbly-sm); background: var(--white); cursor: pointer; font-family: var(--font-heading); font-size: 14px; color: var(--pencil); box-shadow: 2px 2px 0 var(--pencil); transition: transform .1s, box-shadow .1s; }
        .ai-seg-btn:hover { transform: translate(-1px, -1px); box-shadow: 3px 3px 0 var(--pencil); }
        .ai-seg-btn.active { background: var(--blue); color: #fff; border-color: var(--blue); box-shadow: 2px 2px 0 var(--pencil); }
    </style>
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
                    <span style="font-size:14px;color:var(--pencil);">缓冲时间（-0.5 到 5 秒）：停顿 = 音频时长 + 此值（负数提前，最短 0 秒）</span>
                    <span style="font-size:14px;color:var(--blue);font-weight:bold;" id="followBufValue"><?php echo $followBuffer; ?> 秒</span>
                </div>
                <input type="number" class="input" id="followBufInput" value="<?php echo $followBuffer; ?>" min="-0.5" max="5" step="0.5" oninput="document.getElementById('followBufValue').textContent = this.value + ' 秒'">
            </div>
        </div>
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>图集公开 API
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <input type="checkbox" id="galleryApiEnabled" <?php echo $galleryApiEnabled ? 'checked' : ''; ?> style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;">
                <label for="galleryApiEnabled" style="font-size:14px;color:var(--pencil);cursor:pointer;">启用 API 密钥保护</label>
            </div>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;" id="galleryApiKeyRow">
                <input type="text" class="input" id="galleryApiKey" value="<?php echo htmlspecialchars($galleryApiKey, ENT_QUOTES, 'UTF-8'); ?>" placeholder="API 密钥（8-128 位字符）" maxlength="128" style="flex:1;">
                <button type="button" class="btn btn-sm" onclick="generateApiKey()" style="white-space:nowrap;">随机生成</button>
            </div>
            <div style="font-size:12px;color:#888;line-height:1.8;">
                <div>调用方式：<code class="tag">gallery_api.php?class_id=<?php echo htmlspecialchars($classId); ?>&amp;apikey=你的密钥</code></div>
                <div id="galleryApiHint"><?php echo $galleryApiEnabled ? '已启用：不带密钥访问将返回 403。' : '未启用：任何人不带密钥即可访问本班图集接口。'; ?></div>
                <div>接口每次随机返回一张图集图片及其描述，同一设备连续两次不会重复。</div>
            </div>
        </div>
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>展示大屏
            </div>
            <div style="font-size:13px;color:#666;line-height:1.7;margin-bottom:12px;">
                配置后生成「带 token 的展示链接」：把该链接设为壁纸（如 Lively Wallpaper），无需键盘输入班级口令即可直达壁纸页。展示页只读展示数据，不涉及任何修改操作。
            </div>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
                <input type="text" class="input" id="displayToken" value="<?php echo htmlspecialchars($displayToken, ENT_QUOTES, 'UTF-8'); ?>" placeholder="展示 token（8-64 位字母/数字/-_）" maxlength="64" style="flex:1;">
                <button type="button" class="btn btn-sm" onclick="generateDisplayToken()" style="white-space:nowrap;">随机生成</button>
            </div>
            <div style="font-size:12px;color:#888;line-height:1.8;">
                <div>带 token 链接：<code class="tag" id="displayLinkText" style="word-break:break-all;"><?php echo htmlspecialchars('display.php?id=' . $classId . ($displayToken !== '' ? '&token=' . $displayToken : ''), ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div style="margin-top:6px;"><button type="button" class="btn btn-sm btn-secondary" onclick="copyDisplayLink()" style="white-space:nowrap;">复制链接</button></div>
                <div id="displayTokenHint" style="margin-top:6px;"><?php echo $displayToken !== '' ? '已配置：该链接可免口令打开壁纸页。' : '未配置：展示页仍需班级口令验证。'; ?></div>
            </div>
        </div>
        <div class="card mb-3">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>AI 设置（本班级）
            </div>
            <div style="font-size:13px;color:#666;line-height:1.7;margin-bottom:12px;">
                配置后本班级的 AI 功能（智能补全、翻译等）使用该接口，仅对本班级生效，Web 与 APP 共用。
            </div>
                <div class="mb-4">
                    <div style="font-size:14px;color:var(--pencil);margin-bottom:8px;">端点格式</div>
                    <div class="ai-seg" id="aiSeg">
                        <button type="button" class="ai-seg-btn" data-value="none">未配置</button>
                        <button type="button" class="ai-seg-btn" data-value="openai">OpenAI 兼容</button>
                        <button type="button" class="ai-seg-btn" data-value="anthropic">Anthropic</button>
                    </div>
                    <input type="hidden" id="aiProvider" value="<?php echo htmlspecialchars($aiProvider, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            <div id="aiFields">
                <div class="mb-4">
                    <div style="font-size:14px;color:var(--pencil);margin-bottom:8px;">接口地址</div>
                    <input type="text" id="aiEndpoint" class="input" value="<?php echo htmlspecialchars($aiEndpoint, ENT_QUOTES, 'UTF-8'); ?>" placeholder="如 https://api.deepseek.com" style="width:100%;">
                    <div style="font-size:12px;color:#888;margin-top:6px;line-height:1.6;">可只填域名，自动补全 <code class="tag">/v1/chat/completions</code>；Anthropic 可填 <code class="tag">https://api.anthropic.com</code></div>
                </div>
                <div class="mb-4">
                    <div style="font-size:14px;color:var(--pencil);margin-bottom:8px;">API 密钥</div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="password" id="aiApiKey" class="input" value="<?php echo htmlspecialchars($aiApiKey, ENT_QUOTES, 'UTF-8'); ?>" placeholder="sk-..." style="flex:1;">
                        <button type="button" class="btn btn-sm" onclick="toggleAiKey()" id="aiKeyToggle" style="white-space:nowrap;">显示</button>
                    </div>
                </div>
                <div class="mb-4">
                    <div style="font-size:14px;color:var(--pencil);margin-bottom:8px;">模型名</div>
                    <input type="text" id="aiModel" class="input" value="<?php echo htmlspecialchars($aiModel, ENT_QUOTES, 'UTF-8'); ?>" placeholder="如 deepseek-chat / claude-3-5-sonnet-latest / gpt-4o-mini" style="width:100%;">
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary" onclick="testAiConnection()" id="aiTestBtn" style="font-size:14px;">测试连接</button>
                <button type="button" class="btn btn-primary" onclick="saveAiSettings()" id="aiSaveBtn" style="font-size:14px;">保存 AI 设置</button>
            </div>
            <div id="aiHint" style="font-size:12px;color:#888;margin-top:10px;line-height:1.7;"></div>
        </div>
        <div class="card mb-3" style="text-align:center;padding:18px;">
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <button onclick="try{localStorage.removeItem('guide_done')}catch(e){};showOkOverlayThen('main.php?id=<?php echo rawurlencode($classId); ?>')" class="btn btn-secondary" style="font-size:14px;">重新查看引导弹窗</button>
                <a href="help.php" class="btn btn-secondary" style="font-size:14px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg> 打开完整使用说明</a>
            </div>
        </div>
        <button class="btn btn-primary mt-3" style="width:100%" onclick="saveSettings()">保存设置</button>
    </div>
    <div class="toast" id="toast"></div>

    <script src="common.js?v=12"></script>
    <script>var speakRepeat = <?php echo $defaultRepeat; ?>;</script>
    <script>
        function generateApiKey() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            const bytes = new Uint8Array(32);
            if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
            else { for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256); }
            let key = '';
            for (let i = 0; i < bytes.length; i++) key += chars[bytes[i] % chars.length];
            document.getElementById('galleryApiKey').value = key;
        }
        function updateGalleryApiHint() {
            const enabled = document.getElementById('galleryApiEnabled').checked;
            document.getElementById('galleryApiKeyRow').style.opacity = enabled ? '1' : '0.5';
            document.getElementById('galleryApiHint').textContent = enabled
                ? '已启用：不带密钥访问将返回 403。'
                : '未启用：任何人不带密钥即可访问本班图集接口。';
        }
        document.getElementById('galleryApiEnabled').addEventListener('change', updateGalleryApiHint);
        updateGalleryApiHint();
        // ===== 展示大屏 token =====
        function generateDisplayToken() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            const bytes = new Uint8Array(24);
            if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
            else { for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256); }
            let key = '';
            for (let i = 0; i < bytes.length; i++) key += chars[bytes[i] % chars.length];
            document.getElementById('displayToken').value = key;
            updateDisplayLink();
        }
        function updateDisplayLink() {
            const token = document.getElementById('displayToken').value.trim();
            const base = 'display.php?id=<?php echo $classId; ?>';
            document.getElementById('displayLinkText').textContent = token ? base + '&token=' + token : base;
            document.getElementById('displayTokenHint').textContent = token
                ? '已配置：该链接可免口令打开壁纸页。'
                : '未配置：展示页仍需班级口令验证。';
        }
        function copyDisplayLink() {
            const link = document.getElementById('displayLinkText').textContent.trim();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(location.origin + '/' + link).then(function() {
                    showToast('链接已复制', 'success');
                }).catch(function() { showToast('复制失败，请手动复制', 'error'); });
            } else {
                const ta = document.createElement('textarea');
                ta.value = location.origin + '/' + link;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); showToast('链接已复制', 'success'); } catch(e) { showToast('复制失败，请手动复制', 'error'); }
                document.body.removeChild(ta);
            }
        }
        document.getElementById('displayToken').addEventListener('input', updateDisplayLink);
        async function saveSettings() {
            const fd = new FormData();
            fd.append('action', 'save_settings');
            fd.append('default_volume', document.getElementById('volumeSlider').value);
            fd.append('default_interval', document.getElementById('intervalInput').value);
            fd.append('default_repeat', document.getElementById('repeatInput').value);
            fd.append('default_repeat_interval', document.getElementById('repeatIntInput').value);
            fd.append('follow_repeat', document.getElementById('followRepInput').value);
            fd.append('follow_buffer', document.getElementById('followBufInput').value);
            fd.append('gallery_api_enabled', document.getElementById('galleryApiEnabled').checked ? '1' : '0');
            fd.append('gallery_api_key', document.getElementById('galleryApiKey').value.trim());
            fd.append('display_token', document.getElementById('displayToken').value.trim());
            fd.append('csrf_token', CSRF_TOKEN);
            const d = await (await fetch('settings.php?id=<?php echo $classId; ?>', { method: 'POST', body: fd })).json();
            if (d.success) {
                showToast('设置已保存', 'success');
            } else {
                showToast(d.error || '保存失败', 'error');
            }
        }
        // ===== AI 设置 =====
        function updateAiHint() {
            const p = document.getElementById('aiProvider').value;
            const fields = document.getElementById('aiFields');
            fields.style.opacity = (p === 'none') ? '0.45' : '1';
            fields.style.pointerEvents = (p === 'none') ? 'none' : '';
            const hint = document.getElementById('aiHint');
            if (p === 'none') hint.textContent = '未配置：AI 相关功能将提示「AI 服务不可用」。';
            else if (p === 'openai') hint.textContent = 'OpenAI 兼容：请求发往 {接口地址}/v1/chat/completions，使用 Bearer 鉴权。';
            else hint.textContent = 'Anthropic：请求发往 {接口地址}/v1/messages，使用 x-api-key 鉴权。';
        }
        function toggleAiKey() {
            const inp = document.getElementById('aiApiKey');
            const btn = document.getElementById('aiKeyToggle');
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            btn.textContent = show ? '隐藏' : '显示';
        }
        function aiPayload() {
            const fd = new FormData();
            fd.append('ai_provider', document.getElementById('aiProvider').value);
            fd.append('ai_endpoint', document.getElementById('aiEndpoint').value.trim());
            fd.append('ai_api_key', document.getElementById('aiApiKey').value.trim());
            fd.append('ai_model', document.getElementById('aiModel').value.trim());
            fd.append('csrf_token', CSRF_TOKEN);
            return fd;
        }
        async function saveAiSettings() {
            const btn = document.getElementById('aiSaveBtn');
            btn.disabled = true; btn.textContent = '保存中...';
            try {
                const fd = aiPayload(); fd.append('action', 'save_ai');
                const d = await (await fetch('settings.php?id=<?php echo $classId; ?>', { method: 'POST', body: fd })).json();
                if (d.success) showToast('AI 设置已保存', 'success');
                else showToast(d.error || '保存失败', 'error');
            } catch(e) { showToast('网络异常，请重试', 'error'); }
            btn.disabled = false; btn.textContent = '保存 AI 设置';
        }
        async function testAiConnection() {
            const btn = document.getElementById('aiTestBtn');
            btn.disabled = true; btn.textContent = '测试中...';
            try {
                const fd = aiPayload(); fd.append('action', 'ai_test');
                const d = await (await fetch('settings.php?id=<?php echo $classId; ?>', { method: 'POST', body: fd })).json();
                if (d.success) showToast(d.message || '连接成功', 'success');
                else showToast(d.error || '连接失败', 'error');
            } catch(e) { showToast('网络异常，请重试', 'error'); }
            btn.disabled = false; btn.textContent = '测试连接';
        }
        function syncAiSeg() {
            const p = document.getElementById('aiProvider').value;
            document.querySelectorAll('#aiSeg .ai-seg-btn').forEach(function(b) {
                b.classList.toggle('active', b.dataset.value === p);
            });
        }
        document.querySelectorAll('#aiSeg .ai-seg-btn').forEach(function(b) {
            b.addEventListener('click', function() {
                document.getElementById('aiProvider').value = this.dataset.value;
                syncAiSeg();
                updateAiHint();
            });
        });
        syncAiSeg();
        updateAiHint();
    </script>
</body>
</html>
