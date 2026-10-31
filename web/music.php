<?php
/**
 * ============================================================
 * 音乐播放器 — 手绘转场页 / 未配置提示
 * ============================================================
 *
 * 由主界面「音乐播放器」卡片以新标签页打开。
 * 班级在设置页配置一个播放器地址；配置后转场结束自动跳转。
 * 未配置时提示班级去设置页填写，不使用任何默认播放器。
 *
 * URL 参数：
 *   ?id={classId}   班级 ID（用于鉴权和返回）
 *   ?preview=1      只看转场，不自动跳转（设计预览用）
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/music.php';

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

$pageTitle = '音乐播放器';
$backUrl = 'main.php?id=' . urlencode($classId);
$settingsUrl = 'settings.php?id=' . urlencode($classId);
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$musicUrl = music_get_class_url($classId);

require 'inc/head.php';
?>
</head>
<body class="music-page">

<main class="music-shell">
    <?php if ($musicUrl === ''): ?>
    <section class="music-sheet music-sheet-empty" aria-label="音乐播放器尚未配置">
        <div class="music-kicker">LISTENWRITE × MUSIC</div>
        <h1>这个班还没有配置音乐播放器</h1>
        <p class="music-lead">请班级管理员到「设置 → 音乐播放器地址」填写链接。配置后，同学们点卡片就能直接进入。</p>

        <div class="music-pencil-line" aria-hidden="true">
            <svg viewBox="0 0 420 18" preserveAspectRatio="none">
                <path d="M4 12 C 78 3, 128 16, 202 8 S 342 13, 416 6" />
            </svg>
        </div>

        <div class="music-actions">
            <a class="music-link-strong" href="<?php echo htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8'); ?>">去设置音乐播放器</a>
            <a class="music-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
        </div>
    </section>
    <?php else: ?>
    <section class="music-sheet music-sheet-launch" aria-label="正在进入音乐播放器">
        <div class="music-kicker">LISTENWRITE × MUSIC</div>
        <h1>接下来，交给音乐</h1>
        <p class="music-lead">音乐、歌词和舞台，由本班选择的播放器接管。</p>

        <div class="music-pencil-line" aria-hidden="true">
            <svg viewBox="0 0 420 18" preserveAspectRatio="none">
                <path d="M4 12 C 78 3, 128 16, 202 8 S 342 13, 416 6" />
            </svg>
        </div>

        <div class="music-notes" aria-hidden="true">
            <span>♪</span><span>♫</span><span>♪</span>
        </div>

        <div class="music-progress" aria-hidden="true"><span></span></div>
        <p class="music-hint">在线播放器需要联网 · 已在新标签页打开 · 随时可以切回 ListenWrite</p>

        <?php if ($preview): ?>
        <div class="music-preview-note">预览模式 · 本页不会自动跳转；可以点下方按钮打开音乐播放器。</div>
        <?php endif; ?>

        <div class="music-actions">
            <a class="music-link-strong" href="<?php echo htmlspecialchars($musicUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $preview ? '打开音乐播放器' : '如果没有自动进入，点这里'; ?></a>
            <a class="music-link" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">返回主界面</a>
        </div>
    </section>

    <script>
    (function () {
        var target = <?php echo json_encode($musicUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        var preview = <?php echo $preview ? 'true' : 'false'; ?>;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!preview) {
            setTimeout(function () {
                if (target) window.location.replace(target);
            }, reduce ? 450 : 1800);
        }
    })();
    </script>
    <?php endif; ?>
</main>

</body>
</html>
