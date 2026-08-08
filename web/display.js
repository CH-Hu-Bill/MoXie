/* =========================================================
   壁纸展示大屏页 JS — 跑马灯 / 轮询 / 图集轮播 / 竖线拖拽 / 切班
   ========================================================= */

(function() {
'use strict';

var root = document.getElementById('displayRoot');
if (!root) return;

var isContentView = root.dataset.view === 'content';
var POLL_MS = 60000;          // 公告/单词/图集轮询
var ROTATE_MS = 15000;        // 图集切换
var gallery = (typeof DISPLAY_GALLERY !== 'undefined' && DISPLAY_GALLERY) ? DISPLAY_GALLERY : [];
var galleryIdx = 0;
var galleryTimer = null;
var pollTimer = null;
var polling = false;
var reducedMotion = false;

/* 图集轮播断点记忆（localStorage）：
   同设备/浏览器记住当前班级的轮播位置，下次打开从上次位置继续。
   图集内容（指纹=url序列）变化时自动从头开始。 */
var STORE_IDX = 'display_gallery_idx';
var STORE_FP = 'display_gallery_fp';

function galleryFingerprint(list) {
    try { return (list || []).map(function(it) { return it.url; }).join('|'); } catch (e) { return ''; }
}
function loadGalleryState() {
    try {
        var idx = parseInt(localStorage.getItem(STORE_IDX), 10);
        if (isFinite(idx) && localStorage.getItem(STORE_FP) === galleryFingerprint(gallery) && idx >= 0 && idx < gallery.length) {
            galleryIdx = idx;
        }
    } catch (e) {}
}
function saveGalleryState() {
    try {
        localStorage.setItem(STORE_IDX, String(galleryIdx));
        localStorage.setItem(STORE_FP, galleryFingerprint(gallery));
    } catch (e) {}
}

try { reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
try { window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', function(ev) {
    reducedMotion = ev.matches;
}); } catch (e) {}

function onVisible() {
    if (document.hidden) return;
    if (isContentView && !polling) startPolling();
    if (isContentView && gallery.length > 1 && !galleryTimer) startGallery();
}
document.addEventListener('visibilitychange', onVisible);

/* =========================================================
   顶部跑马灯（参考 status-bar 横幅：测量后无缝滚动）
   ========================================================= */
function setupMarquee() {
    var marquee = document.getElementById('dMarquee');
    var track = document.getElementById('dMarqueeTrack');
    if (!marquee || !track) return;
    if (track.querySelector('.d-marquee-marquee')) return; // 已构建
    var avail = track.clientWidth;
    var textEl = track.querySelector('.d-marquee-text');
    if (!textEl) return;
    var tw = textEl.scrollWidth;
    if (tw <= avail + 4) {
        track.classList.remove('track-scroll');
        return;
    }
    // 无缝跑马灯：两份文本 + translateX(-50%) 恰好移动一个副本，无需像素测量
    var textHtml = textEl.outerHTML;
    track.innerHTML = '<div class="d-marquee-marquee">' + textHtml + textHtml + '</div>';
    var mq = track.querySelector('.d-marquee-marquee');
    mq.style.setProperty('--dur', Math.max(5, (avail + tw) / 40) + 's');
    track.classList.add('track-scroll');
}

function updateMarquee(ann) {
    var marquee = document.getElementById('dMarquee');
    var track = document.getElementById('dMarqueeTrack');
    if (!marquee || !track) return;
    var content = ann ? (ann.content || '') : '';
    var color = ann ? (ann.color || '#ff4d4d') : 'var(--old-paper)';
    marquee.style.color = color;
    marquee.dataset.content = content;
    track.innerHTML = '';
    if (!content) content = '今日无公告';
    var span = document.createElement('span');
    span.className = 'd-marquee-text';
    span.textContent = content;
    track.appendChild(span);
    track.classList.remove('track-scroll');
    setupMarquee();
}

/* =========================================================
   图集轮播（预加载下一张 + 淡入）
   ========================================================= */
function preloadImage(url) {
    var img = new Image();
    img.src = url;
}

function renderGalleryItem(item, fade) {
    var wrap = document.getElementById('dGalleryImg');
    var desc = document.getElementById('dGalleryDesc');
    if (!wrap || !desc) return;
    if (!item) {
        wrap.innerHTML = '<div class="d-placeholder d-placeholder-sm">暂无图集</div>';
        desc.textContent = '';
        return;
    }
    var existing = document.getElementById('dGalleryPic');
    if (existing) existing.classList.add('swapping');
    setTimeout(function() {
        if (!fade) { wrap.innerHTML = ''; }
        var img = document.createElement('img');
        img.id = 'dGalleryPic';
        img.alt = item.description || '';
        img.src = item.url;
        if (fade) img.classList.add('swapping');
        wrap.innerHTML = '';
        wrap.appendChild(img);
        // 触发回流后去掉类名以淡入
        void img.offsetWidth;
        img.classList.remove('swapping');
        if (existing) existing = null;
    }, fade ? 200 : 0);
    desc.textContent = item.description || '';
    desc.setAttribute('data-empty', item.description ? '0' : '1');
}

function nextGallery() {
    if (gallery.length === 0) return;
    galleryIdx = (galleryIdx + 1) % gallery.length;
    renderGalleryItem(gallery[galleryIdx], !reducedMotion);
    saveGalleryState();
    // 预加载再下一张
    var next = gallery[(galleryIdx + 1) % gallery.length];
    if (next) preloadImage(next.url);
}

function startGallery() {
    if (galleryTimer) clearInterval(galleryTimer);
    galleryTimer = setInterval(nextGallery, ROTATE_MS);
}

/* =========================================================
   数据轮询
   ========================================================= */
function stopPolling() {
    polling = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}
function stopGallery() {
    if (galleryTimer) { clearInterval(galleryTimer); galleryTimer = null; }
}

function startPolling() {
    if (polling) return;
    polling = true;
    pollTimer = setInterval(refresh, POLL_MS);
}

function refresh() {
    var url = 'display.php?json=1' + (DISPLAY_CID ? '&id=' + encodeURIComponent(DISPLAY_CID) : '');
    // 壁纸页带 token 打开时，轮询同样携带 token（免口令 cookie 失效的场景也能持续拉取）
    var token = new URLSearchParams(location.search).get('token');
    if (token) url += '&token=' + encodeURIComponent(token);
    fetch(url, { cache: 'no-store' })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data) return;
            if (data.ok) {
                isContentView = true;
                root.dataset.view = 'content';
                var cn = document.getElementById('dClassName');
                if (cn) cn.textContent = data.class_name || '';
                if (data.bottom_margin) {
                    root.style.setProperty('--bottom-margin', data.bottom_margin + 'px');
                }
                updateMarquee(data.announcement);
                renderWords(data.words || []);
                var newGallery = data.gallery || [];
                if (JSON.stringify(newGallery) !== JSON.stringify(gallery)) {
                    gallery = newGallery;
                    galleryIdx = 0;
                    saveGalleryState();
                    renderGalleryItem(gallery.length ? gallery[0] : null, false);
                    if (gallery.length > 1) startGallery(); else stopGallery();
                }
            } else if (data.code === 'need_auth' || data.code === 'class_not_found') {
                stopPolling();
                stopGallery();
                isContentView = false;
                root.dataset.view = 'selection';
                if (data.code === 'need_auth' && DISPLAY_CID) {
                    setTimeout(function() { showPw(DISPLAY_CID, true); }, 400);
                }
            }
        })
        .catch(function() { /* 网络波动忽略，下轮重试 */ });
}

/* =========================================================
   单词区渲染
   ========================================================= */
function renderWords(words) {
    var zone = document.getElementById('dWords');
    if (!zone) return;
    var header = '';
    if (words.length === 0) {
        zone.innerHTML = '<div class="d-words-header"><span class="d-words-title">今日默写</span><span class="d-words-date">' + todayStr() + '</span></div>'
            + '<div class="d-placeholder">今日暂无默写任务 ✍️</div>';
        return;
    }
    var cards = words.map(function(w) {
        var pos = w.pos ? '<span class="d-word-pos">' + escHtml(w.pos) + '</span>' : '';
        var mean = w.meaning ? '<div class="d-word-mean"><span class="d-word-scroll">' + escHtml(w.meaning) + '</span></div>' : '';
        return '<div class="d-word"><div class="d-word-main"><span class="d-word-scroll">' + escHtml(w.word) + '</span>' + pos + '</div>' + mean + '</div>';
    }).join('');
    zone.innerHTML = '<div class="d-words-header"><span class="d-words-title">今日默写</span><span class="d-words-date">' + todayStr() + '</span></div>'
        + '<div class="d-word-grid">' + cards + '</div>';
    fitWords();
    fitWordMarquees();
    marqueeAfterFonts();
}

/**
 * 自适应：按可用区域计算列数与字号，让全部单词以尽量大的字号整齐排布。
 * 列数 = ceil(sqrt(count * aspect))，字号 = min(卡片宽*系数, 卡片高*系数, 最长单词宽度约束)，并设上下限。
 */
function fitWords() {
    var zone = document.getElementById('dWords');
    var grid = zone && zone.querySelector('.d-word-grid');
    if (!zone || !grid) return;
    var cards = grid.querySelectorAll('.d-word');
    var n = cards.length;
    if (n === 0) return;
    var zoneW = zone.clientWidth;
    var zoneH = zone.clientHeight;
    // 减去 header 高度
    var header = zone.querySelector('.d-words-header');
    var headerH = header ? header.offsetHeight : 40;
    var availW = Math.max(100, zoneW - 4);
    var availH = Math.max(60, zoneH - headerH - 12);
    var GAP = 10;
    // 估算列数：偏向较少列数 → 卡片更宽 → 字号更大（壁纸优先可读性）
    var aspect = availW / availH;
    var cols = Math.ceil(Math.sqrt(n * aspect * 0.55));
    cols = Math.max(1, Math.min(n, cols, 8));
    var rows = Math.ceil(n / cols);
    var cardW = (availW - (cols - 1) * GAP) / cols;
    var cardH = (availH - (rows - 1) * GAP) / rows;
    // 单词长度（横向约束）—— 用 75 分位数，避免个别超长词把整屏压小
    var lens = [];
    cards.forEach(function(c) {
        var t = c.querySelector('.d-word-main');
        if (t) {
            var pos = t.querySelector('.d-word-pos');
            var txt = pos ? t.textContent.replace(pos.textContent, '') : t.textContent;
            lens.push(txt.trim().length || 1);
        }
    });
    lens.sort(function(a, b) { return a - b; });
    var maxLen = lens.length ? lens[Math.min(lens.length - 1, Math.floor(lens.length * 0.75))] : 1;
    // 字号：受卡片宽度、卡片高度、代表性单词长度三者共同约束
    var fsByW = cardW * 0.34;
    var fsByH = cardH * 0.5;
    var fsByLen = (cardW - 14) / (maxLen * 0.62);
    var fs = Math.floor(Math.min(fsByW, fsByH, fsByLen));
    fs = Math.max(16, Math.min(fs, 64));
    grid.style.setProperty('--d-cols', cols);
    grid.style.setProperty('--word-fs', fs + 'px');
    grid.style.setProperty('--word-pos-fs', Math.max(13, Math.round(fs * 0.45)) + 'px');
    grid.style.setProperty('--word-mean-fs', Math.max(14, Math.round(fs * 0.5)) + 'px');
    // 个别超长单词：略微缩小到基准字号的 85%（保留轻微溢出 → 由滚动跑马灯展示完整内容）
    cards.forEach(function(c) {
        var t = c.querySelector('.d-word-main');
        if (!t) return;
        var pos = t.querySelector('.d-word-pos');
        var txt = pos ? t.textContent.replace(pos.textContent, '') : t.textContent;
        var len = txt.trim().length || 1;
        if (len > maxLen) {
            t.style.fontSize = Math.max(14, Math.round(fs * 0.85)) + 'px';
        } else {
            t.style.fontSize = '';
        }
    });
}

/**
 * 长单词 / 长释义溢出检测 → 滚动跑马灯。
 * 复用单词库页 .scrollable 逻辑：在块级父元素上测 scrollWidth - clientWidth（span 的 scrollWidth 在 inline 上下文为 0）。
 * 滚动距离用相对像素 em 计算（随字号缩放），时长按溢出量换算秒。
 */
function fitWordMarquees() {
    var grid = document.getElementById('dWords');
    if (!grid) return;
    var els = grid.querySelectorAll('.d-word-main, .d-word-mean');
    els.forEach(function(el) {
        el.classList.remove('scrollable');
        el.style.removeProperty('--mx');
        el.style.removeProperty('--md');
        requestAnimationFrame(function() {
            var over = el.scrollWidth - el.clientWidth;
            if (over > 4) {
                var fs = parseFloat(getComputedStyle(el).fontSize) || 16;
                var overEm = (over + 8) / fs;
                el.classList.add('scrollable');
                el.style.setProperty('--mx', '-' + overEm.toFixed(2) + 'em');
                el.style.setProperty('--md', Math.max(3, over / 30) + 's');
            }
        });
    });
}

/** 字体加载完成后再测量一次（异步 Google Fonts 会改变宽度） */
function marqueeAfterFonts() {
    try {
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function() {
                setTimeout(fitWordMarquees, 120);
            });
        }
    } catch (e) {}
}

let _marqueeResizeTimer = null;
window.addEventListener('resize', () => {
    clearTimeout(_marqueeResizeTimer);
    _marqueeResizeTimer = setTimeout(function() { fitWords(); fitWordMarquees(); }, 150);
});

function todayStr() {
    var d = new Date();
    function p(n) { return n < 10 ? '0' + n : '' + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* =========================================================
   竖线拖拽
   ========================================================= */
function initDivider() {
    var divider = document.getElementById('divider');
    if (!divider) return;
    var startX = null, startLeft = null;
    function clampPct(v) { return Math.max(20, Math.min(70, v)); }
    function applyPct(pct) {
        root.style.setProperty('--content-left', pct + '%');
    }
    function load() {
        try {
            var saved = parseFloat(localStorage.getItem('display_left_pct'));
            if (isFinite(saved)) applyPct(clampPct(saved));
        } catch (e) {}
    }
    function save(pct) {
        try { localStorage.setItem('display_left_pct', String(pct)); } catch (e) {}
    }
    divider.addEventListener('pointerdown', function(ev) {
        ev.preventDefault();
        startX = ev.clientX;
        startLeft = parseFloat(getComputedStyle(root).getPropertyValue('--content-left')) || 33.333;
        divider.classList.add('dragging');
        divider.setPointerCapture(ev.pointerId);
    });
    divider.addEventListener('pointermove', function(ev) {
        if (startX === null) return;
        var dx = ev.clientX - startX;
        var pct = clampPct(startLeft + (dx / window.innerWidth) * 100);
        applyPct(pct);
    });
    divider.addEventListener('pointerup', function(ev) {
        if (startX === null) return;
        divider.classList.remove('dragging');
        var pct = clampPct(startLeft + ((ev.clientX - startX) / window.innerWidth) * 100);
        applyPct(pct);
        save(Math.round(pct * 10) / 10);
        startX = null;
        setTimeout(function() { fitWords(); fitWordMarquees(); }, 50);
    });
    divider.addEventListener('pointercancel', function() {
        divider.classList.remove('dragging');
        startX = null;
    });
    load();
}

/* =========================================================
   班级切换 / 口令
   ========================================================= */
var pendingClassId = null;

function openSelect() {
    // 服务端已渲染选择视图列表，直接切换视图即可（齿轮在内容视图）
    root.dataset.view = 'selection';
    stopPolling();
    stopGallery();
}

function selectClass(id) {
    var items = document.querySelectorAll('.d-class-item');
    var info = null;
    items.forEach(function(el) {
        if (el.dataset.cid === id) {
            info = {
                has_password: el.dataset.pw === '1',
                authenticated: el.dataset.auth === '1'
            };
        }
    });
    if (info && info.authenticated) {
        window.location.href = 'display.php?id=' + encodeURIComponent(id);
        return;
    }
    if (info && !info.has_password) {
        window.location.href = 'display.php?id=' + encodeURIComponent(id);
        return;
    }
    showPw(id, false);
}

function showPw(id, isAuthExpired) {
    pendingClassId = id;
    var listEl = document.getElementById('classList');
    var name = id;
    if (listEl) {
        var el = listEl.querySelector('.d-class-item[data-cid="' + id + '"]');
        if (el) name = el.querySelector('.d-class-name').textContent;
    }
    document.getElementById('pwClassName').textContent = '「' + name + '」';
    document.getElementById('pwInput').value = '';
    document.getElementById('pwError').textContent = isAuthExpired ? '班级口令已更改，请重新验证' : '';
    document.getElementById('pwModal').classList.add('active');
    setTimeout(function() { document.getElementById('pwInput').focus(); }, 200);
}

function closePw() {
    document.getElementById('pwModal').classList.remove('active');
    document.getElementById('pwInput').value = '';
    document.getElementById('pwError').textContent = '';
    pendingClassId = null;
}

function submitPassword() {
    var pw = document.getElementById('pwInput').value.trim();
    if (!pw) { document.getElementById('pwError').textContent = '请输入口令'; return; }
    var btn = document.querySelector('#pwModal .submit');
    btn.disabled = true; btn.textContent = '验证中...';
    var fd = new FormData();
    fd.append('action', 'verify_class_password');
    fd.append('class_id', pendingClassId);
    fd.append('password', pw);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('display.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(r) {
            if (r.success) {
                window.location.href = 'display.php?id=' + encodeURIComponent(pendingClassId);
            } else {
                document.getElementById('pwError').textContent = r.error || '口令错误';
            }
        })
        .catch(function(e) {
            document.getElementById('pwError').textContent = '网络异常: ' + (e.message || '请刷新重试');
        })
        .finally(function() {
            btn.disabled = false; btn.textContent = '确认';
        });
}

/* =========================================================
   初始化
   ========================================================= */
function init() {
    initDivider();

    if (!isContentView) {
        // 选择页/口令页：无需轮询
        return;
    }

    setupMarquee();
    if (!document.hidden) startPolling();
    // 从上次轮播位置继续（图集一致时才生效）
    loadGalleryState();
    if (gallery.length > 0 && galleryIdx < gallery.length) {
        if (galleryIdx !== 0) renderGalleryItem(gallery[galleryIdx], false);
        saveGalleryState();
    }
    if (gallery.length > 1) startGallery();
    setTimeout(function() { fitWords(); fitWordMarquees(); }, 0);
    marqueeAfterFonts();
    window.addEventListener('load', function() { setTimeout(function() { fitWords(); fitWordMarquees(); }, 60); marqueeAfterFonts(); });
}

/* 选择页标记需要口令/已认证信息 */
function tagClassItems() {
    var items = document.querySelectorAll('.d-class-item');
    items.forEach(function(el) {
        var c = DISPLAY_CLASSES[el.dataset.cid];
        if (c) {
            el.dataset.pw = c.has_password ? '1' : '0';
            el.dataset.auth = c.authenticated ? '1' : '0';
        }
    });
}

init();
tagClassItems();
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { setTimeout(setupMarquee, 0); });
} else {
    setTimeout(setupMarquee, 0);
}
window.addEventListener('load', function() { setTimeout(setupMarquee, 50); });

window.selectClass = selectClass;
window.showPw = showPw;
window.closePw = closePw;
window.submitPassword = submitPassword;
window.openSelect = openSelect;

})();
