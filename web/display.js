/* =========================================================
   壁纸展示大屏页 JS — 跑马灯 / 轮询 / 图集轮播 / 竖线拖拽 / 切班
   ========================================================= */

(function() {
'use strict';

var root = document.getElementById('displayRoot');
if (!root) return;

var isContentView = root.dataset.view === 'content';
var POLL_MS = 60000;          // 公告/单词/图集轮询
var ROTATE_MS = 30000;        // 图集切换
var gallery = (typeof DISPLAY_GALLERY !== 'undefined' && DISPLAY_GALLERY) ? DISPLAY_GALLERY : [];
var galleryIdx = 0;
var galleryTimer = null;
var pollTimer = null;
var polling = false;
var reducedMotion = false;
var lastWords = (typeof DISPLAY_WORDS !== 'undefined' && DISPLAY_WORDS) ? DISPLAY_WORDS : { task: null, items: [] };
/* 单词区内容指纹：任务日期/状态/标签 + 单词序列，用于 60s 轮询 diff（比 JSON.stringify 全量轻） */
function wordsFingerprint(w) {
    if (!w || !w.task) return 'none';
    return (w.task.id || '') + '#' + (w.task.date || '') + '#' + (w.task.status || '') + '#' + (w.task.label || '') + '|'
        + (w.items || []).map(function(it) { return it.word + '|' + it.meaning + '|' + it.pos; }).join(';');
}

/* 图集轮播断点记忆（localStorage）：
   同设备/浏览器记住当前班级的轮播位置，下次打开从上次位置继续。
   图集内容（指纹=url序列）变化时自动从头开始。 */
var STORE_IDX = 'display_gallery_idx';
var STORE_FP = 'display_gallery_fp';
/* 图集区展示模式：carousel（图片轮播，默认）/ motto（好好学习 天天向上 文字占位）。
   壁纸场景下图片轮播可能分散注意力，切换到文字占位保持专注。localStorage 记忆。 */
var STORE_MODE = 'display_gallery_mode';
var galleryMode = 'carousel';

function loadGalleryMode() {
    try {
        var m = localStorage.getItem(STORE_MODE);
        if (m === 'carousel' || m === 'motto') galleryMode = m;
    } catch (e) {}
}
function applyGalleryMode() {
    root.dataset.galleryMode = galleryMode;
    var btns = document.querySelectorAll('.d-mode-btn');
    btns.forEach(function(b) {
        b.classList.toggle('active', b.dataset.mode === galleryMode);
    });
    if (galleryMode === 'motto') {
        // 文字占位模式：停掉轮播计时/解码，避免后台空转
        stopGallery();
        stopDescScroll();
        var v = document.getElementById('dGalleryPic');
        if (v && v.tagName === 'VIDEO') { try { v.pause(); } catch (e) {} }
        return;
    }
    // 轮播模式：若当前项还没渲染/计时，重新渲染以挂上"就绪后计时"钩子
    if (gallery.length > 0 && galleryIdx < gallery.length) {
        renderGalleryItem(gallery[galleryIdx], false);
        saveGalleryState();
    }
    if (gallery.length > 1) preloadUpcoming();
}
function switchGalleryMode(mode) {
    if (mode !== 'carousel' && mode !== 'motto') return;
    if (galleryMode === mode) return;
    galleryMode = mode;
    try { localStorage.setItem(STORE_MODE, galleryMode); } catch (e) {}
    applyGalleryMode();
}

function galleryFingerprint(list) {
    try { return (list || []).map(function(it) { return it.url; }).join('|'); } catch (e) { return ''; }
}
/* 轮询 diff 用：url+type+description 全含，描述改动也会触发重渲染（位置指纹只管 url 序列） */
function galleryDiffFingerprint(list) {
    try { return (list || []).map(function(it) { return (it.url || '') + '#' + (it.type || '') + '#' + (it.description || ''); }).join('|'); } catch (e) { return ''; }
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
    if (!isContentView || gallery.length < 2) return;
    if (galleryMode === 'motto') return; // 文字占位模式不恢复轮播
    var v = document.getElementById('dGalleryPic');
    if (v && v.tagName === 'VIDEO') { try { if (v.paused) v.play().catch(function() {}); } catch (e) {} }
    if (_itemReady && !galleryTimer) {
        // 已就绪项：后台暂停后回来直接重 arm 15s 计时
        galleryTimer = setTimeout(galleryTick, ROTATE_MS);
    } else if (!_itemReady && !galleryTimer) {
        startGallery();
    }
}
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // 后台暂停：停视频解码 + 清轮播计时器（前台恢复时重 arm）
        var v = document.getElementById('dGalleryPic');
        if (v && v.tagName === 'VIDEO') { try { v.pause(); } catch (e) {} }
        if (galleryTimer) { clearTimeout(galleryTimer); galleryTimer = null; }
    } else {
        onVisible();
    }
});

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
   图集轮播（预加载下一张 + 淡入 + GIF 动图无缝轮播）
   ========================================================= */
var gallerySizeCache = {}; // url -> {w,h} 避免重复解码探针

/* 预加载辅助：把隐藏 <video> 挂到 DOM，浏览器才会真正开始下载视频数据。
   未挂载到 DOM 的 <video> 即使 preload=auto 也只取头部（metadata），
   轮播到视频时仍要现场拉流 → 黑屏。这是此前"预加载没发力"的根因。 */
function attachHiddenVideo(url, onMeta) {
    var pv = document.createElement('video');
    pv.preload = 'auto';
    pv.muted = true;
    pv.setAttribute('muted', '');
    pv.playsInline = true;
    pv.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:2px;height:2px;visibility:hidden;';
    if (onMeta) pv.onloadedmetadata = onMeta;
    pv.src = url;
    pv.load();
    if (document.body) document.body.appendChild(pv);
    // 加载完成后移除隐藏元素（HTTP 缓存已接管，轮播到它时秒开）
    var done = function() { try { if (pv && pv.parentNode) pv.parentNode.removeChild(pv); } catch (e) {} };
    pv.addEventListener('loadeddata', done, { once: true });
    pv.addEventListener('error', done, { once: true });
    return pv;
}

var galleryPreloading = {}; // url -> true，防同 URL 并发重复预载（两轮轮播重叠时只下载一份）

function preloadImage(item) {
    var url = typeof item === 'string' ? item : item.url;
    var type = typeof item === 'object' ? (item.type || 'static') : 'static';
    if (gallerySizeCache[url] || galleryPreloading[url]) return;
    galleryPreloading[url] = true;
    if (type === 'mp4') {
        var pv = attachHiddenVideo(url, function() {
            var w = this.videoWidth, h = this.videoHeight;
            if (w && h) gallerySizeCache[url] = { w: w, h: h };
            delete galleryPreloading[url];
        });
        pv.addEventListener('error', function() { delete galleryPreloading[url]; }, { once: true });
        return;
    }
    var img = new Image();
    img.decoding = 'async'; // 异步解码，避免阻塞主线程
    img.onload = function() {
        try {
            gallerySizeCache[url] = { w: img.naturalWidth, h: img.naturalHeight };
        } catch (e) {}
        delete galleryPreloading[url];
    };
    img.onerror = function() { delete galleryPreloading[url]; };
    img.src = url;
}

/* 预载当前之后两张（不跨首尾即可，循环轮播） */
function preloadUpcoming() {
    if (gallery.length < 2) return; // 单图集无需预载（且会重复下载自己）
    for (var i = 1; i < gallery.length && i <= 2; i++) {
        var next = gallery[(galleryIdx + i) % gallery.length];
        if (next) preloadImage(next);
    }
}

/* 按图片自然尺寸设置容器适配比例：
   宽幅/竖幅等极端比例也完整 contain 显示，不被裁切。 */
function fitGalleryContainer(wrap, width, height) {
    if (!wrap || !width || !height) return;
    var ratio = width / height;
    // 比例钳制到 [1/2.2, 2.2]：避免极端图把容器撑得过大/过小
    var clamped = Math.min(2.2, Math.max(1 / 2.2, ratio));
    wrap.style.aspectRatio = String(clamped);
}

function renderGalleryItem(item, fade) {
    var wrap = document.getElementById('dGalleryImg');
    var desc = document.getElementById('dGalleryDesc');
    var descScroll = document.getElementById('dGalleryDescScroll');
    if (!wrap || !desc || !descScroll) return;
    // 新一项：描述"是否已完整滚完"复位
    _descDone = false;
    _descWaitStart = 0;
    if (!item) {
        wrap.innerHTML = '<div class="d-placeholder d-placeholder-sm">暂无图集</div>';
        wrap.style.aspectRatio = '';
        descScroll.textContent = '';
        desc.setAttribute('data-empty', '1');
        stopDescScroll();
        return;
    }
    // 新一轮渲染：复位计时状态；就绪钩子（onload/playing）触发 armGalleryNext()
    _itemReady = false;
    if (_stallTimer) { clearTimeout(_stallTimer); _stallTimer = null; }
    _stallTimer = setTimeout(function() { if (!_itemReady) armGalleryNext(); }, ROTATE_MS * 2);
    var existing = document.getElementById('dGalleryPic');
    var isMp4 = item.type === 'mp4';

    // 探知尺寸以适配容器（已缓存则直接应用）
    var cached = gallerySizeCache[item.url];
    if (cached) {
        fitGalleryContainer(wrap, cached.w, cached.h);
    } else if (isMp4) {
        // 主播放器已在播同一视频且尺寸就绪：直接取尺寸，避免再起隐藏探针重复完整下载
        if (existing && existing.tagName === 'VIDEO' && existing.videoWidth && existing.currentSrc.indexOf(item.url) !== -1) {
            gallerySizeCache[item.url] = { w: existing.videoWidth, h: existing.videoHeight };
            fitGalleryContainer(wrap, existing.videoWidth, existing.videoHeight);
        } else {
            // 探测用隐藏 video 挂载到 DOM（不挂载则可能取不到尺寸），同时顺带完成数据预载
            attachHiddenVideo(item.url, function() {
                var w = this.videoWidth, h = this.videoHeight;
                if (w && h) {
                    gallerySizeCache[item.url] = { w: w, h: h };
                    fitGalleryContainer(wrap, w, h);
                }
            });
        }
    } else {
        var probe = new Image();
        probe.onload = function() {
            gallerySizeCache[item.url] = { w: probe.naturalWidth, h: probe.naturalHeight };
            fitGalleryContainer(wrap, probe.naturalWidth, probe.naturalHeight);
        };
        probe.src = item.url;
    }

    if (isMp4) {
        // 视频：静音循环自动播放（大屏场景无声音开关）
        if (existing && existing.tagName === 'VIDEO') {
            existing.src = item.url;
            existing.poster = item.thumb_url || '';
            // 复用元素时也补加载占位（先清掉上一项残留的占位，防多轮累积叠加）
            wrap.querySelectorAll('.d-gallery-loading').forEach(function(el) { if (el.parentNode) el.parentNode.removeChild(el); });
            var ldOld = document.createElement('div');
            ldOld.className = 'd-gallery-loading';
            ldOld.textContent = '视频加载中…';
            wrap.appendChild(ldOld);
            var remOld = function() { if (ldOld && ldOld.parentNode) ldOld.parentNode.removeChild(ldOld); };
            var failOld = function() {
                // 加载失败：移除占位、显示「视频不可用」并推进轮播（防占位永久残留/黑屏）
                remOld();
                var fb = document.createElement('div');
                fb.className = 'd-gallery-loading';
                fb.textContent = '视频不可用';
                wrap.appendChild(fb);
                armGalleryNext();
            };
            existing.addEventListener('playing', remOld, { once: true });
            existing.addEventListener('loadeddata', remOld, { once: true });
            existing.addEventListener('error', failOld, { once: true });
            existing.addEventListener('playing', armGalleryNext, { once: true });
            existing.addEventListener('loadeddata', armGalleryNext, { once: true });
            try { existing.play(); } catch (e) {}
        } else {
            wrap.innerHTML = '';
            var v = document.createElement('video');
            v.id = 'dGalleryPic';
            v.setAttribute('muted', '');
            v.setAttribute('loop', '');
            v.setAttribute('autoplay', '');
            v.setAttribute('playsinline', '');
            v.muted = true; // 确保 muted 属性，浏览器 autoplay 策略要求
            v.loop = true;
            v.autoplay = true;
            v.playsInline = true;
            v.preload = 'auto';
            v.poster = item.thumb_url || '';
            v.src = item.url;
            // 显示加载提示，避免视频缓冲时一片黑
            var ld = document.createElement('div');
            ld.className = 'd-gallery-loading';
            ld.textContent = '视频加载中…';
            wrap.appendChild(ld);
            wrap.appendChild(v);
            var tryPlay = function() { try { v.play().catch(function() {}); } catch (e) {} };
            v.addEventListener('loadeddata', tryPlay, { once: true });
            v.addEventListener('canplay', tryPlay, { once: true });
            // 兜底：muted 已设，直接尝试播放
            setTimeout(tryPlay, 150);
            v.addEventListener('playing', function() { if (ld && ld.parentNode) ld.parentNode.removeChild(ld); });
            // 开始播放/数据就绪后开始 15s 计时
            v.addEventListener('playing', armGalleryNext, { once: true });
            v.addEventListener('loadeddata', armGalleryNext, { once: true });
            // 加载失败：占位改「视频不可用」并推进轮播（防黑屏/占位累积）
            v.addEventListener('error', function() {
                if (ld && ld.parentNode) ld.parentNode.removeChild(ld);
                var fb = document.createElement('div');
                fb.className = 'd-gallery-loading';
                fb.textContent = '视频不可用';
                wrap.appendChild(fb);
                armGalleryNext();
            }, { once: true });
        }
    } else if (item.type === 'gif') {
        // GIF：直接更新 src，浏览器无缝继续/重播动画
        if (existing && existing.tagName === 'IMG') {
            existing.src = item.url; existing.alt = item.description || '';
            existing.onload = function() { armGalleryNext(); };
        }
        else {
            wrap.innerHTML = '';
            var g = document.createElement('img');
            g.id = 'dGalleryPic';
            g.alt = item.description || '';
            g.src = item.url;
            g.onload = function() { armGalleryNext(); };
            wrap.appendChild(g);
        }
    } else if (existing) {
        // 静态图：淡出旧图后替换，加载中显示占位
        existing.classList.add('swapping');
        setTimeout(function() {
            if (!fade) { wrap.innerHTML = ''; }
            var img = document.createElement('img');
            img.id = 'dGalleryPic';
            img.alt = item.description || '';
            img.src = item.url;
            if (fade) img.classList.add('swapping');
            wrap.innerHTML = '';
            // 加载占位（避免空白），图片 ready 后移除并开始计时
            var ld = document.createElement('div');
            ld.className = 'd-gallery-loading';
            ld.textContent = '加载中…';
            wrap.appendChild(ld);
            img.onload = function() { if (ld && ld.parentNode) ld.parentNode.removeChild(ld); armGalleryNext(); };
            img.onerror = function() { if (ld && ld.parentNode) ld.parentNode.removeChild(ld); armGalleryNext(); };
            wrap.appendChild(img);
            void img.offsetWidth;
            img.classList.remove('swapping');
        }, fade ? 200 : 0);
    } else {
        wrap.innerHTML = '';
        var img2 = document.createElement('img');
        img2.id = 'dGalleryPic';
        img2.alt = item.description || '';
        img2.src = item.url;
        var ld2 = document.createElement('div');
        ld2.className = 'd-gallery-loading';
        ld2.textContent = '加载中…';
        wrap.appendChild(ld2);
        img2.onload = function() { if (ld2 && ld2.parentNode) ld2.parentNode.removeChild(ld2); armGalleryNext(); };
        img2.onerror = function() { if (ld2 && ld2.parentNode) ld2.parentNode.removeChild(ld2); armGalleryNext(); };
        wrap.appendChild(img2);
    }
    descScroll.textContent = item.description || '';
    desc.setAttribute('data-empty', item.description ? '0' : '1');
    // 描述溢出自动滚动（壁纸页纯自动，无用户打断）
    setTimeout(function() { startDescScroll(); }, 0);
}

/* 描述自动滚动（来回往返式，参考单词跑马灯）：缓慢滚到底 → 停留 → 缓慢滚回顶部 → 停留，循环。
   用 requestAnimationFrame 逐帧驱动，平滑无跳变；溢出时在内层滚动容器上叠加渐变蒙版
   （mask 固定在容器视口，不随文字滚动）。壁纸页纯自动、无用户打断。
   _descDone：描述是否已至少完整读过一遍（滚到底一次）——轮播 15s 到时若还没读完，
   则等读完后再等 1s 才切换，避免文字被切走。 */
var _descScrollHandle = null;
var _descDone = false;
function stopDescScroll() {
    if (_descScrollHandle) { cancelAnimationFrame(_descScrollHandle.raf); _descScrollHandle = null; }
    var d = document.getElementById('dGalleryDescScroll');
    if (d) { d.scrollTop = 0; d.classList.remove('is-overflow'); }
}
function startDescScroll() {
    stopDescScroll();
    var d = document.getElementById('dGalleryDescScroll');
    var box = document.getElementById('dGalleryDesc');
    if (!d || !box || box.getAttribute('data-empty') === '1') { _descDone = true; return; }
    var max = d.scrollHeight - d.clientHeight;
    if (max <= 4) { _descDone = true; return; } // 内容不溢出，视为已读完（不加蒙版）
    d.classList.add('is-overflow'); // 溢出才叠上下渐变蒙版
    var speed = 22;           // px/s，缓慢
    var pos = 0, dir = 1, reachedBottom = false;
    var last = performance.now(), pauseUntil = 0;
    var st = {};
    var tick = function(now) {
        if (_descScrollHandle !== st) return; // 已被 stop 或重新开始
        if (document.hidden) { _descScrollHandle.raf = requestAnimationFrame(tick); return; } // 后台暂停，回前台继续
        var dt = (now - last) / 1000; last = now;
        if (now < pauseUntil) { _descScrollHandle.raf = requestAnimationFrame(tick); return; }
        pos += dir * speed * dt;
        if (pos >= max) {
            pos = max; dir = -1; pauseUntil = now + 1200;
            if (!reachedBottom) { reachedBottom = true; _descDone = true; } // 完整读过一遍
        }
        else if (pos <= 0) { pos = 0; dir = 1; pauseUntil = now + 1200; }
        d.scrollTop = pos;
        _descScrollHandle.raf = requestAnimationFrame(tick);
    };
    _descScrollHandle = st;
    _descScrollHandle.raf = requestAnimationFrame(tick);
}

/* 图集轮播计时：等当前项【加载完成/开始播放】之后，再开始计 15s。
   - renderGalleryItem 渲染后，在图片 onload / 视频 playing 时调用 armGalleryNext() 启动计时；
   - 若加载卡死（如网络异常），_stallTimer 兜底（2 倍时长后仍推进），避免永久停留；
   - 预加载保证轮到时基本秒开，所以"等加载完再计时"不会让用户觉得卡。 */
var _itemReady = false;  // 当前项是否已加载/开始播放（准备开始计时）
var _stallTimer = null;  // 加载卡死兜底定时器
var _descWaitStart = 0;  // 15s 到点但描述未读完的时间戳（用于超时兜底）

function armGalleryNext() {
    if (_itemReady) return;
    if (gallery.length < 2) return; // 单图集无需轮播（否则每 15s 重渲染同一项）
    _itemReady = true;
    if (_stallTimer) { clearTimeout(_stallTimer); _stallTimer = null; } // 就绪后清兜底定时器
    if (galleryTimer) { clearTimeout(galleryTimer); galleryTimer = null; }
    // 当前项已显示满 ROTATE_MS 后尝试切换（描述若没读完则等它读完再切）
    galleryTimer = setTimeout(galleryTick, ROTATE_MS);
}

/* 轮播到点：描述已读完 → 立即切；没读完 → 等读完后再停 1s 切（防文字被切走）；
   描述滚动异常导致永远读不完时，最多再等 ROTATE_MS*2 后强制切。 */
function galleryTick() {
    if (gallery.length === 0) { stopGallery(); return; }
    if (!_descDone) {
        if (!_descWaitStart) _descWaitStart = Date.now();
        if (Date.now() - _descWaitStart > ROTATE_MS * 2) { _descDone = true; }
        else { galleryTimer = setTimeout(galleryTick, 250); return; }
        galleryTimer = setTimeout(nextGallery, 1000); // 读完/超时后停 1s 再切
        return;
    }
    nextGallery(); // 15s 时已读完：立即切
}

function nextGallery() {
    if (gallery.length < 2) return; // 单图集不轮播（避免每 15s 重渲染同一项）
    galleryIdx = (galleryIdx + 1) % gallery.length;
    // render 内部会在该项就绪后自动 armGalleryNext() 开始计时
    renderGalleryItem(gallery[galleryIdx], !reducedMotion);
    saveGalleryState();
    // 预加载后两张（图片用 Image 预热缓存；视频用挂载到 DOM 的隐藏 <video> 真正缓冲）
    preloadUpcoming();
}

function startGallery() {
    if (gallery.length < 2) { stopGallery(); return; }
    // 当前项若尚未渲染/未开始计时，则重新渲染以挂上"就绪后计时"钩子
    if (!_itemReady && !galleryTimer) renderGalleryItem(gallery[galleryIdx], false);
}

/* =========================================================
   数据轮询
   ========================================================= */
function stopPolling() {
    polling = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}
function stopGallery() {
    if (galleryTimer) { clearTimeout(galleryTimer); galleryTimer = null; }
    if (_stallTimer) { clearTimeout(_stallTimer); _stallTimer = null; }
    _itemReady = false;
    _descDone = true;
    _descWaitStart = 0;
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
                // 单词内容 diff：用轻量指纹（任务信息+单词序列），无变化跳过重建 DOM
                var newWords = data.words || { task: null, items: [] };
                if (wordsFingerprint(newWords) !== wordsFingerprint(lastWords)) {
                    lastWords = newWords;
                    renderWords(newWords);
                }
                var newGallery = data.gallery || [];
                if (galleryDiffFingerprint(newGallery) !== galleryDiffFingerprint(gallery)) {
                    gallery = newGallery;
                    galleryIdx = 0;
                    saveGalleryState();
                    // motto（文字占位）模式下不渲染轮播，只更新数据；切回轮播时再渲染
                    if (galleryMode === 'motto') return;
                    renderGalleryItem(gallery.length ? gallery[0] : null, false);
                    if (gallery.length > 1) {
                        preloadUpcoming(); // 首帧渲染后立即预载后两张（render 就绪后会自行计时）
                    } else stopGallery();
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
    var task = words && words.task;
    var items = words && words.items ? words.items : [];
    var header = '<div class="d-words-header"><span class="d-words-title">最近默写</span>';
    if (task && task.date) {
        header += '<span class="d-words-date">' + escHtml(task.date) + ' 周' + weekdayCn(task.date) + '</span>';
    }
    if (task && task.label) {
        header += '<span class="d-words-tag">' + escHtml(task.label) + '</span>';
    }
    header += '</div>';
    if (items.length === 0) {
        zone.innerHTML = header + '<div class="d-placeholder">最近暂无默写任务 ✍️</div>';
        return;
    }
    var cards = items.map(function(w) {
        // 词性独立成行放卡片顶部：不再与单词同行混排（词性过长会把整行挤成省略号，
        // 并让跑马灯位移量与内容错位失效）；main 现在只含单词，溢出检测/滚动纯净
        var posRow = w.pos ? '<div class="d-word-pos-row"><span class="d-word-pos">' + escHtml(w.pos) + '</span></div>' : '';
        var mean = w.meaning ? '<div class="d-word-mean"><span class="d-word-scroll">' + escHtml(w.meaning) + '</span></div>' : '';
        return '<div class="d-word">' + posRow + '<div class="d-word-main"><span class="d-word-scroll">' + escHtml(w.word) + '</span></div>' + mean + '</div>';
    }).join('');
    zone.innerHTML = header + '<div class="d-word-grid">' + cards + '</div>';
    fitWords();
    fitWordMarquees();
    marqueeAfterFonts();
}

/* 日期 → 中文星期（如 2026-08-21 → 周五） */
function weekdayCn(dateStr) {
    try {
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return '';
        return '日一二三四五六'.charAt(d.getDay());
    } catch (e) { return ''; }
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
    // （词性已独立成行，main 内只有单词文本，直接取长度即可）
    var lens = [];
    cards.forEach(function(c) {
        var t = c.querySelector('.d-word-main');
        if (t) lens.push(t.textContent.trim().length || 1);
    });
    lens.sort(function(a, b) { return a - b; });
    var maxLen = lens.length ? lens[Math.min(lens.length - 1, Math.floor(lens.length * 0.75))] : 1;
    // 字号：受卡片宽度、卡片高度、代表性单词长度三者共同约束
    var fsByW = cardW * 0.34;
    var fsByH = cardH * 0.42; // 留出顶部词性行与底部释义行的高度
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
 * 双 rAF：等 fitWords 的 CSS 变量（--word-fs 等）真正应用到布局后再测，
 * 避免单 rAF 时字号还没生效导致测不到溢出（"单词不滚动、只截断"的另一个潜在根因）。
 */
function fitWordMarquees() {
    var grid = document.getElementById('dWords');
    if (!grid) return;
    var els = grid.querySelectorAll('.d-word-main, .d-word-mean');
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            els.forEach(function(el) {
                el.classList.remove('scrollable');
                el.style.removeProperty('--mx');
                el.style.removeProperty('--md');
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
    stopDescScroll(); // 隐藏视图里不继续跑描述滚动 rAF
    var v = document.getElementById('dGalleryPic');
    if (v && v.tagName === 'VIDEO') { try { v.pause(); } catch (e) {} }
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
    // 图集区展示模式（轮播/好好学习 天天向上）：
    //  carousel → 重挂"就绪后计时"钩子（服务端直出的首帧无 onload/playing 钩子）+ 预载后两张；
    //  motto    → 停轮播/解码，显示文字占位。
    loadGalleryMode();
    applyGalleryMode();
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
window.switchGalleryMode = switchGalleryMode;

})();
