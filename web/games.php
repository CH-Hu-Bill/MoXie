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

// 近 7 天游玩时长曲线（数据来自 classes/{classId}/game_time.json）
$weekStats = game_recent_stats($classId, 7);
$weekTotal = 0;
$weekPeak = 0;
foreach ($weekStats as $day) {
    $weekTotal += (int)$day['seconds'];
    $weekPeak = max($weekPeak, (int)$day['seconds']);
}
// 纵轴取整到 5 分钟，避免曲线贴着顶部或在底部压成一条线
$chartPeak = (int)(ceil(max(60, $weekPeak) / 300) * 300);
$chartW = 700; $chartH = 200;
$chartLeft = 26; $chartRight = 674;
$chartTop = 18; $chartBottom = 142;
$chartPoints = [];
$chartCount = count($weekStats);
foreach ($weekStats as $i => $day) {
    $x = $chartLeft + ($chartCount > 1 ? $i * ($chartRight - $chartLeft) / ($chartCount - 1) : 0);
    $y = $chartBottom - min(1, (int)$day['seconds'] / max(1, $chartPeak)) * ($chartBottom - $chartTop);
    $chartPoints[] = ['x' => round($x, 1), 'y' => round($y, 1), 'day' => $day];
}
$chartLine = implode(' ', array_map(function ($p) { return $p['x'] . ',' . $p['y']; }, $chartPoints));
$chartArea = 'M ' . $chartPoints[0]['x'] . ',' . $chartBottom . ' L ' . implode(' L ', array_map(function ($p) { return $p['x'] . ',' . $p['y']; }, $chartPoints)) . ' L ' . $chartPoints[$chartCount - 1]['x'] . ',' . $chartBottom . ' Z';
$chartGrid = [];
for ($gi = 0; $gi <= 4; $gi++) {
    $chartGrid[] = round($chartBottom - ($gi / 4) * ($chartBottom - $chartTop), 1);
}
$chartFmt = function (int $sec): string {
    if ($sec < 60) return $sec . ' 秒';
    if ($sec < 3600) return intdiv($sec, 60) . ' 分' . ($sec % 60 ? ' ' . ($sec % 60) . ' 秒' : '');
    return intdiv($sec, 3600) . ' 小时 ' . intdiv($sec % 3600, 60) . ' 分';
};

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
.game-icon-white{width:76px;height:76px;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:5px;padding:7px;background:#fff;border:2px solid var(--pencil);border-radius:15px 9px 17px 9px;box-shadow:3px 3px 0 var(--pencil);transform:rotate(2deg);}
.game-icon-white span{display:block;border-radius:4px;background:#f2f0eb;border:1.5px solid #d8d4cc;}
.game-icon-white span.on{background:#242424;border-color:#242424;}
.game-icon-kof{width:76px;height:76px;display:flex;align-items:center;justify-content:center;gap:2px;background:#181820;border:2px solid var(--pencil);border-radius:16px 9px 18px 9px;box-shadow:3px 3px 0 var(--pencil);transform:rotate(-2deg);font-family:var(--font-mono);}
.game-icon-kof span{font-size:13px;font-weight:800;color:#fff;background:#3f86e0;border-radius:7px;padding:4px 3px;line-height:1;}
.game-icon-kof span:last-child{background:#d95043;}
.game-icon-kof b{font-size:9px;color:#e9c84a;letter-spacing:.04em;}
.game-icon-life{width:76px;height:76px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fff4de;border:2px solid var(--pencil);border-radius:16px 10px 15px 9px;box-shadow:3px 3px 0 var(--pencil);transform:rotate(1.5deg);font-family:var(--font-heading);font-weight:700;color:#9b5a2e;line-height:1;font-size:26px;}
.game-icon-life span:last-child{margin-top:3px;color:#c9783f;}
.game-tile h2{font-family:var(--font-heading);font-size:24px;color:var(--pencil);margin:0 0 6px;}
.game-tile p{font-size:13px;color:#777;line-height:1.7;margin:0 0 14px;}
.game-meta{font-size:12px;color:#8a857e;border-top:1.5px dashed var(--old-paper);padding-top:10px;}
.game-play{display:inline-block;margin-top:10px;padding:6px 14px;background:var(--blue);color:var(--white);border-radius:var(--wobbly-sm);font-size:13px;font-weight:700;}
.game-chart{position:relative;background:var(--white);border:2px solid var(--pencil);border-radius:var(--wobbly);box-shadow:var(--shadow-md);padding:16px 18px 6px;margin:0 0 22px;overflow:hidden;}
.game-chart::before{content:"";position:absolute;width:88px;height:22px;left:50%;top:-11px;transform:translateX(-50%) rotate(-1.5deg);background:rgba(255,232,150,.78);border-left:1px dashed rgba(120,100,40,.24);border-right:1px dashed rgba(120,100,40,.24);pointer-events:none;}
.game-chart-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:2px;}
.game-chart-kicker{display:block;font-family:var(--font-mono);font-size:10px;letter-spacing:.14em;color:var(--blue);font-weight:700;margin-bottom:3px;}
.game-chart-head h2{font-family:var(--font-heading);font-size:21px;line-height:1.2;color:var(--pencil);margin:0;}
.game-chart-total{text-align:right;flex:0 0 auto;}
.game-chart-total b{display:block;font-family:var(--font-heading);font-size:19px;line-height:1.2;color:var(--red);}
.game-chart-total span{font-size:10px;color:#8a857e;}
.game-chart-body{position:relative;margin-top:2px;}
.game-chart svg{display:block;width:100%;height:auto;overflow:visible;}
.game-chart-day{font-family:var(--font-heading);font-size:16px;font-weight:700;}
.game-chart-weekday{font-family:var(--font-heading);font-size:12px;}
.game-chart-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#9b958c;pointer-events:none;background:rgba(255,255,255,.38);}
.game-chart-foot{display:flex;justify-content:space-between;gap:8px;font-size:10px;color:#9a948b;padding:0 2px 4px;}
@media(max-width:600px){.game-chart{padding:14px 12px 4px;margin-bottom:18px}.game-chart-head h2{font-size:18px}.game-chart-total b{font-size:16px}.game-chart-total span{font-size:9px}.game-chart-day{font-size:19px}.game-chart-weekday{display:none}.game-chart-foot{font-size:9px}}
.game-note{margin-top:24px;text-align:center;font-size:12px;color:#999;line-height:1.8;}
</style>
</head>
<body>
<?php require 'inc/header.php'; ?>
<div class="game-page">
    <section class="game-chart" aria-label="近 7 天游戏时长曲线">
        <div class="game-chart-head">
            <div>
                <span class="game-chart-kicker">PLAY TIME · LAST 7 DAYS</span>
                <h2>每天玩了多久</h2>
            </div>
            <div class="game-chart-total">
                <b><?php echo htmlspecialchars($chartFmt($weekTotal), ENT_QUOTES, 'UTF-8'); ?></b>
                <span>7 天累计</span>
            </div>
        </div>
        <div class="game-chart-body">
            <svg viewBox="0 0 <?php echo $chartW; ?> <?php echo $chartH; ?>" role="img" aria-label="近 7 天每天游戏时长曲线">
                <defs>
                    <linearGradient id="gameChartFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#5b8fd6" stop-opacity=".34"/>
                        <stop offset="100%" stop-color="#5b8fd6" stop-opacity=".02"/>
                    </linearGradient>
                </defs>
                <?php foreach ($chartGrid as $gy): ?>
                <line x1="<?php echo $chartLeft; ?>" y1="<?php echo $gy; ?>" x2="<?php echo $chartRight; ?>" y2="<?php echo $gy; ?>" stroke="#d9d3c7" stroke-width="1" stroke-dasharray="3 5" opacity=".75"/>
                <?php endforeach; ?>
                <path d="<?php echo htmlspecialchars($chartArea, ENT_QUOTES, 'UTF-8'); ?>" fill="url(#gameChartFill)"/>
                <polyline points="<?php echo htmlspecialchars($chartLine, ENT_QUOTES, 'UTF-8'); ?>" fill="none" stroke="#2d5da1" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <?php foreach ($chartPoints as $pi => $pt): ?>
                    <?php $isToday = ($pi === $chartCount - 1); $ts = strtotime($pt['day']['date']); ?>
                    <circle cx="<?php echo $pt['x']; ?>" cy="<?php echo $pt['y']; ?>" r="<?php echo $isToday ? 5.5 : 4; ?>" fill="<?php echo $isToday ? '#e05252' : '#fff'; ?>" stroke="<?php echo $isToday ? '#e05252' : '#2d5da1'; ?>" stroke-width="2.5">
                        <title><?php echo htmlspecialchars(date('n/j', $ts) . ' · ' . $chartFmt((int)$pt['day']['seconds']), ENT_QUOTES, 'UTF-8'); ?></title>
                    </circle>
                    <text x="<?php echo $pt['x']; ?>" y="170" text-anchor="middle" class="game-chart-day" fill="<?php echo $isToday ? '#c94747' : '#77716a'; ?>"><?php echo $isToday ? '今天' : date('n/j', $ts); ?></text>
                    <text x="<?php echo $pt['x']; ?>" y="188" text-anchor="middle" class="game-chart-weekday" fill="#aaa39a"><?php echo $isToday ? 'TODAY' : '周' . ['日','一','二','三','四','五','六'][(int)date('w', $ts)]; ?></text>
                <?php endforeach; ?>
            </svg>
            <?php if ($weekTotal === 0): ?><div class="game-chart-empty">还没有游玩记录，玩一局就会开始统计</div><?php endif; ?>
        </div>
        <div class="game-chart-foot"><span>每天 0 点重置 · 按班级统计</span><span>最高单日 <?php echo htmlspecialchars($chartFmt($weekPeak), ENT_QUOTES, 'UTF-8'); ?></span></div>
    </section>

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
