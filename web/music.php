<?php
/**
 * ============================================================
 * Folia 音乐 — 启动/未安装提示页
 * ============================================================
 *
 * 由主界面「Folia 音乐」卡片以新标签页打开。
 *   - 已安装且运行中：手绘转场后跳转到 Folia 原版页面
 *   - 已安装但未启动：显示等待页并轮询，服务起来后自动进入
 *   - 未安装：提示管理员在服务器上运行安装脚本，不自动执行
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/folia.php';

$classId = reqGet('id');
if (!$classId) {
    header('Location: index.php');
    exit;
}
$classes = Database::getClasses();
if (!isset($classes[$classId])) {
    header('Location: index.php');
    exit;
}
$class = $classes[$classId];
requireClassAuth($classId, $class);

$pageTitle = 'Folia 音乐';
$backUrl = 'main.php?id=' . urlencode($classId);
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$state = folia_state();
$foliaUpdate = $preview ? ['available' => false] : folia_update_available();
$foliaUrl = $preview && (string)$state['url'] === '' ? 'https://folia-major.vercel.app/' : (string)$state['url'];
$showTransition = $preview || $state['running'];
$statusUrl = 'folia_status.php';

require 'inc/head.php';
?>
</head>
<body class="folia-page">

<?php if ($showTransition): ?>
    <main class="folia-shell" id="foliaTransition">
        <section class="folia-sheet folia-sheet-launch" aria-label="正在进入 Folia">
            <div class="folia-kicker">LISTENWRITE × FOLIA</div>
            <h1>接下来，完全交给 Folia</h1>
            <p class="folia-lead">音乐、歌词和舞台，由 Folia 接管。</p>

            <div class="folia-pencil-line" aria-hidden="true">
                <svg viewBox="0 0 420 18" preserveAspectRatio="none">
                    <path d="M4 12 C 78 3, 128 16, 202 8 S 342 13, 416 6" />
                </svg>
            </div>

            <div class="folia-notes" aria-hidden="true">
                <span>♪</span><span>♫</span><span>♪</span>
            </div>

            <div class="folia-progress" aria-hidden="true"><span></span></div>
            <p class="folia-hint">已在新标签页打开 · 随时可以切回 ListenWrite</p>
            <?php if ($preview): ?>
            <div class="folia-preview-note">预览模式 · 当前没有安装 Folia，本页不会自动跳转；可以点下方按钮查看公开演示。</div>
            <?php endif; ?>
            <?php if ($foliaUpdate['available']): ?>
            <div class="folia-update-panel">
                <strong>发现已审核新版本 v<?php echo htmlspecialchars((string)$foliaUpdate['latest'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <p>更新会重启 Folia，正在播放的音乐会中断。可以先用当前版本听歌。</p>
                <div class="folia-update-actions">
                    <button type="button" class="btn btn-primary" id="foliaContinue">先听歌</button>
                    <button type="button" class="folia-copy" id="foliaShowUpdate">查看更新方法</button>
                </div>
                <pre class="folia-code" id="foliaUpdateCmd" hidden><code>bash scripts/folia/update.sh</code></pre>
            </div>
            <?php endif; ?>
            <div class="folia-actions">
                <a class="folia-link-strong" href="<?php echo htmlspecialchars($foliaUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $preview ? '打开公开 Folia 演示' : '如果没有自动进入，点这里'; ?></a>
                <a class="folia-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
            </div>
        </section>
    </main>
    <script>
    (function () {
        var target = <?php echo json_encode($foliaUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        var hasUpdate = <?php echo $foliaUpdate['available'] ? 'true' : 'false'; ?>;
        var isPreview = <?php echo $preview ? 'true' : 'false'; ?>;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function enterFolia() {
            if (target) window.location.replace(target);
        }
        var continueBtn = document.getElementById('foliaContinue');
        if (continueBtn) continueBtn.addEventListener('click', enterFolia);
        var showUpdate = document.getElementById('foliaShowUpdate');
        var updateCmd = document.getElementById('foliaUpdateCmd');
        if (showUpdate && updateCmd) {
            showUpdate.addEventListener('click', function () {
                updateCmd.hidden = !updateCmd.hidden;
                showUpdate.textContent = updateCmd.hidden ? '查看更新方法' : '收起更新方法';
            });
        }
        if (!hasUpdate && !isPreview) {
            setTimeout(enterFolia, reduce ? 450 : 1800);
        }
    })();
    </script>

<?php elseif ($state['installed']): ?>
    <main class="folia-shell">
        <section class="folia-sheet folia-sheet-wait" aria-label="正在启动 Folia">
            <div class="folia-kicker">FOLIA · STARTING</div>
            <h1>正在唤醒 Folia</h1>
            <p class="folia-lead">已经安装，但音乐服务还没有响应。正在自动重试。</p>
            <div class="folia-wait-dots" aria-hidden="true"><span></span><span></span><span></span></div>
            <p class="folia-status" id="foliaWaitText">如果长时间没有进入，请管理员检查 Docker 服务和容器状态。</p>
            <div class="folia-actions">
                <button type="button" class="btn btn-primary" id="foliaRetry">立即重试</button>
                <a class="folia-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
            </div>
        </section>
    </main>
    <script>
    (function () {
        var statusUrl = <?php echo json_encode($statusUrl); ?>;
        var waitText = document.getElementById('foliaWaitText');
        var retry = document.getElementById('foliaRetry');
        var attempts = 0;
        var timer = null;

        function enter(url) {
            if (timer) clearTimeout(timer);
            window.location.replace(url);
        }

        async function check() {
            attempts += 1;
            try {
                var response = await fetch(statusUrl + '?t=' + Date.now(), { cache: 'no-store' });
                var data = await response.json();
                if (data && data.running && data.url) {
                    if (waitText) waitText.textContent = 'Folia 已就绪，正在进入…';
                    enter(data.url);
                    return;
                }
                if (data && data.installed === false) {
                    if (waitText) waitText.textContent = '没有检测到 Folia 安装信息，请刷新页面查看安装提示。';
                    return;
                }
            } catch (e) {
                // 服务还没起来时保持安静，继续重试
            }
            if (waitText && attempts > 1) {
                waitText.textContent = '正在等待 Folia 启动…（第 ' + attempts + ' 次检查）';
            }
            if (attempts < 40) timer = setTimeout(check, 2500);
            else if (waitText) waitText.textContent = '仍然没有响应。请管理员查看 Docker 容器日志，或运行启动脚本。';
        }

        if (retry) retry.addEventListener('click', check);
        check();
    })();
    </script>

<?php else: ?>
    <main class="folia-shell">
        <section class="folia-sheet folia-sheet-install" aria-label="Folia 尚未安装">
            <div class="folia-kicker">OPTIONAL · FOLIA</div>
            <h1>Folia 还没有安装</h1>
            <p class="folia-lead">Folia 是可选的音乐组件，不会随 ListenWrite 自动安装。需要管理员在服务器上手动运行安装脚本。</p>

            <div class="folia-code-wrap">
                <div class="folia-code-label">在 ListenWrite 根目录执行</div>
                <pre class="folia-code"><code id="foliaInstallCmd">bash scripts/folia/install.sh</code></pre>
                <button type="button" class="folia-copy" id="foliaCopy">复制命令</button>
            </div>

            <p class="folia-note">需要 Docker Engine 与 Docker Compose v2；首次安装需要联网，会拉取数百 MB 镜像。安装脚本只在你手动执行时运行。</p>

            <div class="folia-actions">
                <button type="button" class="btn btn-primary" id="foliaRecheck">重新检测</button>
                <a class="folia-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
            </div>
            <p class="folia-status" id="foliaInstallStatus">安装完成后，点「重新检测」。</p>
        </section>
    </main>
    <script>
    (function () {
        var statusUrl = <?php echo json_encode($statusUrl); ?>;
        var recheck = document.getElementById('foliaRecheck');
        var copy = document.getElementById('foliaCopy');
        var status = document.getElementById('foliaInstallStatus');
        var command = 'bash scripts/folia/install.sh';

        if (copy) {
            copy.addEventListener('click', async function () {
                try {
                    await navigator.clipboard.writeText(command);
                    copy.textContent = '已复制';
                    setTimeout(function () { copy.textContent = '复制命令'; }, 1600);
                } catch (e) {
                    if (status) status.textContent = '复制失败，请手动选择上面的命令。';
                }
            });
        }

        if (recheck) {
            recheck.addEventListener('click', async function () {
                recheck.disabled = true;
                if (status) status.textContent = '正在检测…';
                try {
                    var response = await fetch(statusUrl + '?t=' + Date.now(), { cache: 'no-store' });
                    var data = await response.json();
                    if (data && data.running && data.url) {
                        if (status) status.textContent = '已检测到 Folia，正在进入…';
                        window.location.replace(data.url);
                        return;
                    }
                    if (data && data.installed) {
                        if (status) status.textContent = '已经安装，但服务还没启动。请管理员检查 Docker 容器。';
                    } else {
                        if (status) status.textContent = '仍未检测到安装信息。请确认脚本已在服务器上执行完成。';
                    }
                } catch (e) {
                    if (status) status.textContent = '检测失败，请确认 ListenWrite 服务正常。';
                } finally {
                    recheck.disabled = false;
                }
            });
        }
    })();
    </script>
<?php endif; ?>

</body>
</html>
