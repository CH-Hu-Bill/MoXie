<?php
/**
 * ============================================================
 * Folia 在线音乐 — 手绘转场页
 * ============================================================
 *
 * 由主界面「Folia 音乐」卡片以新标签页打开。
 * 转场结束后跳转到 Folia 在线版（默认官方公开实例）。
 * 本页不依赖本机 Docker / Folia 安装。
 *
 * URL 参数：
 *   ?id={classId}   班级 ID（用于鉴权和返回）
 *   ?preview=1      只看转场，不自动跳转（设计预览用）
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
$settings = Database::getSettings();
$classFoliaUrl = (string)($settings['folia_url_' . $classId] ?? '');
$foliaUrl = folia_public_url($classFoliaUrl);

require 'inc/head.php';
?>
</head>
<body class="folia-page">

<main class="folia-shell">
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
        <p class="folia-hint">在线版需要联网 · 已在新标签页打开 · 随时可以切回 ListenWrite</p>

        <?php if ($preview): ?>
        <div class="folia-preview-note">预览模式 · 本页不会自动跳转；可以点下方按钮查看 Folia 在线版。</div>
        <?php endif; ?>

        <div class="folia-actions">
            <a class="folia-link-strong" href="<?php echo htmlspecialchars($foliaUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $preview ? '打开 Folia 在线版' : '如果没有自动进入，点这里'; ?></a>
            <a class="folia-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
        </div>
    </section>
</main>

<script>
(function () {
    var target = <?php echo json_encode($foliaUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var preview = <?php echo $preview ? 'true' : 'false'; ?>;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!preview) {
        setTimeout(function () {
            if (target) window.location.replace(target);
        }, reduce ? 450 : 1800);
    }
})();
</script>

</body>
</html>
