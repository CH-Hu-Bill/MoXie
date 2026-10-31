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

$gameId = reqGet('game');
$games = require __DIR__ . '/inc/game_catalog.php';
if (!isset($games[$gameId])) { header('Location: games.php?id=' . urlencode($classId)); exit; }

$game = $games[$gameId];
$csrfToken = csrfToken();
$ext = strtolower(pathinfo((string)$game['source'], PATHINFO_EXTENSION));
if ($ext === 'html') {
    $gameHtml = @file_get_contents($game['source']);
    if ($gameHtml === false) { header('Location: games.php?id=' . urlencode($classId)); exit; }
} else {
    $gameHtml = require $game['source'];
}
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($base === '.' || $base === '/') $base = '';
$gameBase = $base . '/' . $game['base'];
$gameHtml = preg_replace('/<head>/i', '<head><base href="' . htmlspecialchars($gameBase, ENT_QUOTES, 'UTF-8') . '">', $gameHtml, 1);
$gameHtml = preg_replace_callback(
    '~\b(src|href)="((?!https?:|//|/|data:|#)[^"]+)"~i',
    function ($m) use ($gameBase) {
        $rel = ltrim($m[2], './');
        return $m[1] . '="' . $gameBase . $rel . '"';
    },
    $gameHtml
);

$limit = game_limit_seconds($classId);
$remaining = game_remaining_seconds($classId);
$used = game_today_seconds($classId);

$pageTitle = $game['title'];
$backUrl = 'games.php?id=' . urlencode($classId);
$className = $class['name'];
$rightContent = '<span id="gameTimeLabel" style="font-size:12px;font-weight:700;color:var(--red);white-space:nowrap;">' . htmlspecialchars($remaining === null ? '不限时' : '剩余 ' . game_format_seconds($remaining), ENT_QUOTES, 'UTF-8') . '</span>';
require 'inc/head.php';
?>
<style>
html,body{height:100%;overflow:hidden;}
.game-wrap{height:calc(100dvh - 56px);min-height:0;display:flex;flex-direction:column;background:#faf8ef;}
.game-frame{flex:1;min-height:0;width:100%;border:0;display:block;background:#faf8ef;}
.game-timeup{display:none;position:absolute;inset:56px 0 0;z-index:50;align-items:center;justify-content:center;background:rgba(250,248,239,.96);padding:24px;}
.game-timeup.show{display:flex;}
.game-timeup-card{max-width:460px;width:100%;text-align:center;background:var(--white);border:3px solid var(--pencil);border-radius:var(--wobbly-lg);box-shadow:var(--shadow-lg);padding:28px 24px;}
.game-timeup-card h2{font-family:var(--font-heading);font-size:28px;color:var(--pencil);margin:0 0 10px;}
.game-timeup-card p{font-size:14px;line-height:1.8;color:#6f6a63;margin:0 0 18px;}
@media(max-width:600px){.game-wrap{height:calc(100dvh - 52px)}}
</style>
</head>
<body>
<?php require 'inc/header.php'; ?>
<div class="game-wrap">
    <iframe id="gameFrame" class="game-frame" title="<?php echo htmlspecialchars($game['title'], ENT_QUOTES, 'UTF-8'); ?>" src="about:blank" allow="fullscreen; autoplay"></iframe>
</div>
<div class="game-timeup" id="gameTimeUp">
    <div class="game-timeup-card">
        <h2>今天的游戏时间用完了</h2>
        <p id="gameTimeUpText">每天 0 点重置，明天再来吧。</p>
        <a class="btn btn-primary" href="games.php?id=<?php echo urlencode($classId); ?>" style="text-decoration:none;display:inline-block;">返回小游戏</a>
        <a class="btn btn-secondary" href="main.php?id=<?php echo urlencode($classId); ?>" style="text-decoration:none;display:inline-block;margin-left:8px;">回主界面</a>
    </div>
</div>
<script src="common.js?v=14"></script>
<script>
(function () {
    var classId = <?php echo json_encode($classId, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var gameId = <?php echo json_encode($gameId, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var csrf = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var gameHtml = <?php echo json_encode($gameHtml, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var frame = document.getElementById('gameFrame');
    var timeLabel = document.getElementById('gameTimeLabel');
    var overlay = document.getElementById('gameTimeUp');
    var overlayText = document.getElementById('gameTimeUpText');
    var sessionId = null, sessionToken = null;
    var remaining = <?php echo $remaining === null ? 'null' : (int)$remaining; ?>;
    var hbTimer = null, tickTimer = null, ended = false;

    function fmt(sec) {
        if (sec === null) return '不限时';
        sec = Math.max(0, parseInt(sec, 10) || 0);
        var m = Math.floor(sec / 60), s = sec % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function updateLabel() {
        if (timeLabel) timeLabel.textContent = remaining === null ? '不限时' : '剩余 ' + fmt(remaining);
    }
    function api(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('class_id', classId);
        fd.append('game', gameId);
        fd.append('csrf_token', csrf);
        if (sessionId) fd.append('session_id', sessionId);
        if (sessionToken) fd.append('session_token', sessionToken);
        if (extra) Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
        return fetch('game_time_api.php', { method: 'POST', body: fd, cache: 'no-store' })
            .then(function(r) { return r.json(); });
    }
    function stopTimers() {
        if (hbTimer) { clearInterval(hbTimer); hbTimer = null; }
        if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
    }
    function showTimeUp(message) {
        if (ended) return;
        ended = true;
        stopTimers();
        frame.style.display = 'none';
        overlay.classList.add('show');
        if (message && overlayText) overlayText.textContent = message;
        if (sessionId) {
            api('stop').catch(function() {});
            sessionId = null;
            sessionToken = null;
        }
    }
    function startTimers() {
        stopTimers();
        tickTimer = setInterval(function() {
            if (remaining === null || ended) return;
            remaining = Math.max(0, remaining - 1);
            updateLabel();
            if (remaining <= 0) showTimeUp('每天 0 点重置，明天再来吧。');
        }, 1000);
        hbTimer = setInterval(function() {
            if (document.hidden || ended || !sessionId) return;
            api('heartbeat').then(function(d) {
                if (!d.success) return;
                remaining = d.remaining_seconds;
                updateLabel();
                if (d.time_up || (remaining !== null && remaining <= 0)) showTimeUp('每天 0 点重置，明天再来吧。');
            }).catch(function() {});
        }, 10000);
    }
    function startGame() {
        api('start').then(function(d) {
            if (!d.success || !d.allowed) {
                showTimeUp((d && d.error) ? d.error : '今天的游戏时间已经用完，明天再来吧。');
                return;
            }
            sessionId = d.session_id;
            sessionToken = d.session_token;
            remaining = d.remaining_seconds;
            updateLabel();
            frame.srcdoc = gameHtml;
            frame.style.display = 'block';
            startTimers();
        }).catch(function() {
            showTimeUp('游戏时长服务暂时不可用，请刷新重试。');
        });
    }
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) stopTimers();
        else if (!ended && sessionId) { startTimers(); api('heartbeat').catch(function() {}); }
    });
    window.addEventListener('pagehide', function() {
        if (!sessionId) return;
        var fd = new FormData();
        fd.append('action', 'stop');
        fd.append('class_id', classId);
        fd.append('game', gameId);
        fd.append('csrf_token', csrf);
        fd.append('session_id', sessionId);
        fd.append('session_token', sessionToken);
        try { navigator.sendBeacon('game_time_api.php', fd); } catch (e) {}
    });
    updateLabel();
    startGame();
})();
</script>
</body>
</html>
