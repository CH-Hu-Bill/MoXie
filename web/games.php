<?php
require_once 'inc/db.php';
require_once 'inc/security.php';
require_once 'inc/game_time.php';

$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

$limit = game_limit_seconds($classId);
$used = game_today_seconds($classId);
$remaining = game_remaining_seconds($classId);
$exhausted = $remaining !== null && $remaining <= 0;

$pageTitle = '小游戏';
$backUrl = 'main.php?id=' . urlencode($classId);
$className = $class['name'];
$rightContent = '';
require 'inc/head.php';
?>
<style>
.game-page{max-width:900px;margin:0 auto;padding:20px 18px 40px;}
.game-hero{text-align:center;margin:8px 0 24px;}
.game-hero h1{font-family:var(--font-heading);font-size:clamp(30px,5vw,44px);color:var(--pencil);margin:0 0 8px;}
.game-hero p{color:#6f6a63;font-size:15px;line-height:1.8;margin:0;}
.game-time{display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:8px 16px;background:var(--post-it);border:2px dashed var(--pencil);border-radius:var(--wobbly-sm);font-size:14px;color:var(--pencil);}
.game-time b{font-size:20px;color:var(--red);}
.game-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;}
.game-tile{display:block;position:relative;background:var(--white);border:2px solid var(--pencil);border-radius:var(--wobbly);padding:20px;box-shadow:var(--shadow-md);text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s;}
.game-tile:hover{transform:translateY(-2px) rotate(-.2deg);box-shadow:var(--shadow-lg);}
.game-tile.disabled{opacity:.62;filter:grayscale(.35);cursor:not-allowed;}
.game-tile.disabled:hover{transform:none;box-shadow:var(--shadow-md);}
.game-icon{font-size:34px;line-height:1;margin-bottom:10px;}
.game-tile h2{font-family:var(--font-heading);font-size:24px;color:var(--pencil);margin:0 0 6px;}
.game-tile p{font-size:13px;color:#777;line-height:1.7;margin:0 0 14px;}
.game-meta{font-size:12px;color:#8a857e;border-top:1.5px dashed var(--old-paper);padding-top:10px;}
.game-play{display:inline-block;margin-top:10px;padding:6px 14px;background:var(--blue);color:var(--white);border-radius:var(--wobbly-sm);font-size:13px;font-weight:700;}
.game-note{margin-top:24px;text-align:center;font-size:12px;color:#999;line-height:1.8;}
</style>
</head>
<body>
<?php require 'inc/header.php'; ?>
<div class="game-page">
    <div class="game-hero">
        <h1>小游戏</h1>
        <p>课间放松一下。游戏由 ListenWrite 内置，不打开外站网址。</p>
        <div class="game-time">
            今日已玩 <b><?php echo htmlspecialchars(game_format_seconds($used), ENT_QUOTES, 'UTF-8'); ?></b>
            <?php if ($limit === null): ?>
                · 不限时
            <?php else: ?>
                · 剩余 <b><?php echo htmlspecialchars(game_format_seconds($remaining), ENT_QUOTES, 'UTF-8'); ?></b>
            <?php endif; ?>
        </div>
    </div>

    <div class="game-grid">
        <?php if ($exhausted): ?>
        <div class="game-tile disabled" aria-disabled="true">
        <?php else: ?>
        <a class="game-tile" href="game.php?id=<?php echo urlencode($classId); ?>&amp;game=2048">
        <?php endif; ?>
            <div class="game-icon">🔢</div>
            <h2>2048</h2>
            <p>合并数字，冲击 2048。支持方向键和触屏滑动。</p>
            <div class="game-meta">
                <?php if ($exhausted): ?>
                    今天的游戏时间已经用完，明天再来。
                <?php else: ?>
                    今日剩余：<?php echo $limit === null ? '不限时' : htmlspecialchars(game_format_seconds($remaining), ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
            <?php if (!$exhausted): ?><span class="game-play">开始游戏 →</span><?php endif; ?>
        <?php echo $exhausted ? '</div>' : '</a>'; ?>
    </div>

    <p class="game-note">游戏时长按班级统一计算，每天 0 点重置。只支持内置游戏；外站游戏无法可靠限制时长，因此暂不接入。</p>
</div>
<script src="common.js?v=14"></script>
</body>
</html>
