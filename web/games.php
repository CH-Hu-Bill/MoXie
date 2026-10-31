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
.game-icon-2048{width:76px;height:76px;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:4px;padding:6px;background:#faf8ef;border:2px solid var(--pencil);border-radius:14px 8px 16px 8px;box-shadow:3px 3px 0 var(--pencil);transform:rotate(-2deg);}
.game-icon-2048 span{display:flex;align-items:center;justify-content:center;border-radius:6px;background:#edc22e;color:#fff;font-family:var(--font-heading);font-size:20px;font-weight:700;line-height:1;box-shadow:inset 0 -2px 0 rgba(0,0,0,.12);}
.game-icon-2048 span:nth-child(2){background:#f2b179;}
.game-icon-2048 span:nth-child(3){background:#f59563;}
.game-icon-2048 span:nth-child(4){background:#f67c5f;}
.game-icon-mini{width:76px;height:76px;display:flex;align-items:center;justify-content:center;border:2px solid var(--pencil);border-radius:16px 9px 18px 9px;box-shadow:3px 3px 0 var(--pencil);}
.game-icon-mini svg{width:58px;height:58px;display:block;}
.game-icon-pixel{background:#fffbeb;transform:rotate(2deg);}
.game-icon-sudoku{background:#eff6ff;transform:rotate(-2deg);}
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

    <?php $gameList = require __DIR__ . '/inc/game_catalog.php'; ?>
    <div class="game-grid">
        <?php foreach ($gameList as $gid => $g): ?>
            <?php if ($exhausted): ?>
            <div class="game-tile disabled" aria-disabled="true">
            <?php else: ?>
            <a class="game-tile" href="game.php?id=<?php echo urlencode($classId); ?>&amp;game=<?php echo urlencode($gid); ?>">
            <?php endif; ?>
                <?php echo $g['icon']; ?>
                <h2><?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($g['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="game-meta">
                    <?php echo htmlspecialchars($g['meta'], ENT_QUOTES, 'UTF-8'); ?> ·
                    <?php if ($exhausted): ?>
                        今天的时间已经用完
                    <?php else: ?>
                        剩余：<?php echo $limit === null ? '不限时' : htmlspecialchars(game_format_seconds($remaining), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
                <?php if (!$exhausted): ?><span class="game-play">开始游戏 →</span><?php endif; ?>
            <?php echo $exhausted ? '</div>' : '</a>'; ?>
        <?php endforeach; ?>
    </div>

    <p class="game-note">游戏时长按班级统一计算，每天 0 点重置。只支持内置游戏；外站游戏无法可靠限制时长，因此暂不接入。</p>
</div>
<script src="common.js?v=14"></script>
</body>
</html>
