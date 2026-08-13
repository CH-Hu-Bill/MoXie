/* =========================================================
   全局共享 JS — common.js
   ========================================================= */

// ---------- Toast 提示 ----------
let toastTimer = null;
function showToast(msg, type) {
    let t = document.getElementById('toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'toast';
        t.className = 'toast';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.className = 'toast ' + (type || '');
    t.style.display = 'block';
    requestAnimationFrame(() => { t.classList.add('show'); });
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => { t.style.display = 'none'; }, 300);
    }, 2500);
}

// ---------- TTS 发音 (有道词典) — 防重复播放 ----------
let _currentAudio = null;
let _speakSeq = 0;
function speak(word, times) {
    // 与跟读互斥：跟读进行中点单词发音，先停掉跟读会话（页面回调自动收尾 UI）
    if (_followState && _followState.playing) stopFollowAlong();
    _speakSeq++;
    const mySeq = _speakSeq;
    if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
    if (!times) times = (typeof speakRepeat !== 'undefined') ? speakRepeat : 1;
    const play = (n) => {
        if (n <= 0 || mySeq !== _speakSeq) return;
        const a = new Audio('https://dict.youdao.com/dictvoice?audio=' + encodeURIComponent(word) + '&type=1');
        _currentAudio = a;
        a.play().catch(() => {});
        a.onended = () => { if (_currentAudio === a) _currentAudio = null; if (n > 1 && mySeq === _speakSeq) setTimeout(() => play(n - 1), 400); };
    };
    play(times);
}

// ---------- 自定义确认框 ----------
function customConfirm(msg, title) {
    let modal = document.getElementById('confirmModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'confirmModal';
        modal.className = 'modal';
        modal.style.cssText = 'z-index:99999!important;position:fixed';
        modal.innerHTML = '<div class="modal-content" style="max-width:360px;">' +
            '<div class="modal-title" id="confirmTitle">确认</div>' +
            '<p style="text-align:center;color:#666;margin:12px 0;" id="confirmMsg"></p>' +
            '<div class="modal-btns">' +
                '<button type="button" class="cancel" id="confirmCancel">取消</button>' +
                '<button type="button" class="submit" id="confirmOk">确认</button>' +
            '</div></div>';
        modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('active'); });
        document.body.appendChild(modal);
    }
    return new Promise(resolve => {
        document.getElementById('confirmTitle').textContent = title || '确认';
        document.getElementById('confirmMsg').textContent = msg;
        modal.classList.add('active');
        document.getElementById('confirmCancel').style.display = '';
        document.getElementById('confirmOk').onclick = () => { modal.classList.remove('active'); resolve(true); };
        document.getElementById('confirmCancel').onclick = () => { modal.classList.remove('active'); resolve(false); };
    });
}

// ---------- 自定义提示框 ----------
function customAlert(msg, title) {
    let modal = document.getElementById('alertModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'alertModal';
        modal.className = 'modal';
        modal.style.cssText = 'z-index:99999!important;position:fixed';
        modal.innerHTML = '<div class="modal-content" style="max-width:360px;">' +
            '<div class="modal-title" id="alertTitle">提示</div>' +
            '<p style="text-align:center;color:#666;margin:12px 0;" id="alertMsg"></p>' +
            '<div class="modal-btns">' +
                '<button type="button" class="cancel" id="alertCancel" style="display:none;">取消</button>' +
                '<button type="button" class="submit" id="alertOk">确认</button>' +
            '</div></div>';
        modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('active'); });
        document.body.appendChild(modal);
    }
    return new Promise(resolve => {
        document.getElementById('alertTitle').textContent = title || '提示';
        document.getElementById('alertMsg').textContent = msg;
        modal.classList.add('active');
        document.getElementById('alertOk').onclick = () => { modal.classList.remove('active'); resolve(true); };
    });
}

// ---------- 关闭模态框 ----------
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// ---------- HTML 转义 ----------
function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ---------- 单词卡片跑马灯初始化 ----------
function initMarquee() {
    document.querySelectorAll('.word-card').forEach(card => initMarqueeFor(card));
}

// 单张卡片跑马灯测量（供 content-visibility 下卡片进入视口时调用）
function initMarqueeFor(card) {
    if (!card) return;
    card.querySelectorAll('.word, .meaning').forEach(el => {
        el.style.fontSize = '';
        const len = el.textContent.trim().length;
        if (el.classList.contains('word')) {
            if (len > 16) el.style.fontSize = '20px';
            else if (len > 12) el.style.fontSize = '24px';
            else if (len > 9) el.style.fontSize = '28px';
            else if (len > 7) el.style.fontSize = '32px';
        }
    });
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            card.querySelectorAll('.word, .meaning').forEach(el => {
                el.classList.remove('scrollable');
                el.style.removeProperty('--mx');
                el.style.removeProperty('--md');
                const over = el.scrollWidth - el.clientWidth;
                if (over > 4) {
                    el.classList.add('scrollable');
                    el.style.setProperty('--mx', '-' + (over + 10) + 'px');
                    el.style.setProperty('--md', Math.max(3, over / 35) + 's');
                }
            });
        });
    });
}

let _marqueeResizeTimer = null;
window.addEventListener('resize', () => {
    clearTimeout(_marqueeResizeTimer);
    _marqueeResizeTimer = setTimeout(initMarquee, 120);
});

// ---------- 超级霸屏（全屏公告）----------
// 霸屏层由 header.php 服务端渲染（位于状态栏下方），
// 通过 <html>.fs-active 控制显隐；仅站内点击跳转的新页面显示。

function fsOverlayEl() {
    return document.getElementById('fsOverlay');
}

function hideFs() {
    document.documentElement.classList.remove('fs-active');
}

function maybeShowFsOnLoad() {
    const ov = fsOverlayEl();
    if (!ov) return;
    let nav = false;
    try { nav = sessionStorage.getItem('__inapp_nav') === '1'; } catch (e) {}
    try { sessionStorage.removeItem('__inapp_nav'); } catch (e) {}
    if (!nav) return; // 刷新/直达：保持隐藏，无闪动
    document.documentElement.classList.add('fs-active');
    const seconds = parseInt(ov.getAttribute('data-seconds') || '1', 10) || 1;
    setTimeout(hideFs, Math.min(5, Math.max(1, seconds)) * 1000);
}

function markInAppNav() {
    try { sessionStorage.setItem('__inapp_nav', '1'); } catch (e) {}
}

// ---------- 全局页面离场反馈 ----------
function showPageLeaving() {
    let overlay = document.getElementById('pageLeavingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'pageLeavingOverlay';
        overlay.className = 'page-leaving-overlay';
        overlay.setAttribute('aria-label', '页面加载中');
        overlay.innerHTML = '<span class="page-loading-spinner" aria-hidden="true"></span>';
        document.body.appendChild(overlay);
    }
    document.documentElement.classList.add('page-leaving');
}

function hidePageLeaving() {
    document.documentElement.classList.remove('page-leaving');
    // 彻底移除蒙版元素，避免 bfcache 恢复或加载期间残留导致动画卡住
    const overlay = document.getElementById('pageLeavingOverlay');
    if (overlay) overlay.remove();
}
// 页面就绪/显示时立即隐藏离场蒙版（DOM 解析完成即可，不必等图片/字体等资源加载完）
hidePageLeaving();
document.addEventListener('DOMContentLoaded', hidePageLeaving);
window.addEventListener('load', hidePageLeaving);
window.addEventListener('pageshow', hidePageLeaving);
// 新页面加载后：站内跳转且存在超级公告则全屏展示配置时长（可点击跳过）
function initFsOverlay() {
    const ov = fsOverlayEl();
    if (ov && !ov.dataset.bound) {
        ov.dataset.bound = '1';
        ov.addEventListener('click', hideFs);
    }
}
document.addEventListener('DOMContentLoaded', initFsOverlay);
document.addEventListener('DOMContentLoaded', maybeShowFsOnLoad);
window.addEventListener('load', maybeShowFsOnLoad);
setTimeout(maybeShowFsOnLoad, 200);
// 页面离开前(进入 bfcache)移除蒙版，避免系统返回键恢复页面时蒙版残留卡住
window.addEventListener('pagehide', function() {
    document.documentElement.classList.remove('page-leaving');
    const overlay = document.getElementById('pageLeavingOverlay');
    if (overlay) overlay.remove();
});
// 页面重新可见时再兜底一次（覆盖 bfcache 恢复等场景）
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) hidePageLeaving();
});

function showOkOverlayThen(url) {
    markInAppNav();
    showPageLeaving();
    location.href = url;
}
function showOkOverlayOnly(callback) {
    showPageLeaving();
    if (typeof callback === 'function') requestAnimationFrame(callback);
}

function getSafePageUrl(link) {
    if (!link || link.target || link.hasAttribute('download')) return null;
    const raw = link.getAttribute('href');
    if (!raw || raw[0] === '#' || /^(?:javascript|mailto|tel):/i.test(raw)) return null;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return null;
    for (const key of url.searchParams.keys()) {
        if (/^(?:action|delete|logout|remove|signout|exit)$/i.test(key)) return null;
    }
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return null;
    return url;
}

document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const url = getSafePageUrl(event.target.closest('a'));
    if (url) { markInAppNav(); showPageLeaving(); }
}, true);

const prefetchedPages = new Set();
function prefetchPage(event) {
    if ((navigator.connection && (navigator.connection.saveData || /2g/.test(navigator.connection.effectiveType))) || document.visibilityState === 'hidden') return;
    const url = getSafePageUrl(event.target.closest('a'));
    if (!url || url.href === location.href || prefetchedPages.has(url.href)) return;
    prefetchedPages.add(url.href);
    const hint = document.createElement('link');
    hint.rel = 'prefetch';
    hint.href = url.href;
    hint.as = 'document';
    document.head.appendChild(hint);
}
document.addEventListener('pointerover', prefetchPage, { passive: true });
document.addEventListener('focusin', prefetchPage);
document.addEventListener('touchstart', prefetchPage, { passive: true });

// ---------- 关闭模态框（点击背景），在脚本加载时立即执行 ----------
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
});

// ---------- 数组乱序 (Fisher-Yates，原地打乱) ----------
function shuffleArray(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    }
    return arr;
}

// =========================================================
// 跟读播放器 (Read-along Audio Player) — 基于音频自然时长
// =========================================================
var _followState = null;

/**
 * 开始跟读播放 (基于音频自然结束时间)
 * 节奏：每遍朗读后自动停顿 = 音频实际播放时长 + buffer（长词停得久、短词停得短）
 * @param {string[]} words - 单词列表（播放顺序，可预先乱序）
 * @param {object} opts - { repeat(每词朗读次数), buffer(秒, 额外缓冲), volume(0-100) }
 * @param {function} onUpdate - 回调 { word, index, total, done, stopped }
 * @returns {object} { stop, pause, resume }
 */
function startFollowAlong(words, opts, onUpdate) {
    stopFollowAlong();
    // 数字解析用 isNaN 判空，允许合法 0 值（缓冲 0 / 音量 0 均有效）
    var repeat = parseInt(opts.repeat, 10);
    if (isNaN(repeat) || repeat < 1) repeat = 1;
    var buffer = parseFloat(opts.buffer);
    if (isNaN(buffer) || buffer < 0) buffer = 0.5;
    var volume = parseFloat(opts.volume);
    if (isNaN(volume)) volume = 80;
    volume = Math.max(0, Math.min(100, volume));

    var st = {
        playing: true,
        paused: false,
        words: words,
        index: 0,
        repeat: repeat,
        buffer: buffer * 1000,      // 缓冲毫秒（叠加在音频时长上）
        volume: volume / 100,
        timer: null,                // 停顿倒计时
        timerDeadline: 0,           // 停顿截止时间戳（暂停时保留剩余秒数）
        currentAudio: null,         // 保留元素，暂停后可断点续播
        currentRepeat: 0,
        playStartAt: 0,             // 本次播放 'playing' 时刻（ms）
        playedMs: 0,                // 当前遍已累计播放时长（ms）
        failed: false,              // 当前遍失败：跳过剩余重复直接下一词
        onUpdate: onUpdate
    };
    _followState = st;

    function playNext() {
        if (!st.playing || st.paused) return;
        if (st.index >= st.words.length) {
            st.playing = false;
            if (onUpdate) onUpdate({ done: true });
            return;
        }
        st.currentRepeat = 0;
        st.playedMs = 0;
        st.failed = false;
        if (onUpdate) onUpdate({ word: st.words[st.index], index: st.index + 1, total: st.words.length });
        playWordRepeat();
    }

    // 每遍结束后的停顿：音频实际时长 + 缓冲，然后进下一遍/下一词
    function afterPause() {
        if (!st.playing || st.paused) return;
        var pauseMs = st.playedMs + st.buffer;
        st.timerDeadline = performance.now() + pauseMs;
        st.timer = setTimeout(function() {
            st.timer = null;
            st.timerDeadline = 0;
            playWordRepeat();
        }, pauseMs);
    }

    function playWordRepeat() {
        if (!st.playing || st.paused) return;
        if (st.currentRepeat >= st.repeat) {
            st.index++;
            if (st.index >= st.words.length) {
                st.playing = false;
                if (onUpdate) onUpdate({ done: true });
                return;
            }
            playNext();
            return;
        }
        var word = st.words[st.index];
        var audio = new Audio('https://dict.youdao.com/dictvoice?audio=' + encodeURIComponent(word) + '&type=1');
        audio.volume = st.volume;
        st.currentAudio = audio;
        st.failed = false;
        st.playedMs = 0;
        st.playStartAt = 0;
        var done = false; // 本遍已收尾（成功或失败），防 onerror + play() 双重推进

        audio.onplaying = function() { st.playStartAt = performance.now(); };
        audio.onended = function() {
            if (st.currentAudio !== audio) return;
            st.currentAudio = null;
            if (done) return;
            done = true;
            // 实测播放时长；无 playing 事件时回退 audio.duration
            st.playedMs += st.playStartAt
                ? (performance.now() - st.playStartAt)
                : (isFinite(audio.duration) && audio.duration > 0 ? audio.duration * 1000 : 0);
            if (!st.playing || st.paused) return;
            st.currentRepeat++;
            afterPause();
        };
        var fail = function() {
            if (st.currentAudio === audio) st.currentAudio = null;
            if (done) return;
            done = true;
            st.failed = true;
            if (!st.playing || st.paused) return;
            st.currentRepeat = st.repeat; // 失败：跳过剩余重复，直接下一词（无停顿）
            playWordRepeat();
        };
        audio.onerror = fail;
        audio.play().catch(fail);
    }

    playNext();

    return {
        stop: function() {
            st.playing = false;
            st.paused = false;
            if (st.timer) { clearTimeout(st.timer); st.timer = null; }
            st.timerDeadline = 0;
            if (st.currentAudio) { st.currentAudio.pause(); st.currentAudio = null; }
            _speakSeq++;
            if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
            if (_followState === st) _followState = null;
            if (onUpdate) onUpdate({ done: true, stopped: true });
        },
        pause: function() {
            if (!st.playing) return;
            st.paused = true;
            if (st.timer) { clearTimeout(st.timer); st.timer = null; }
            if (st.currentAudio) {
                // 累计已播时长并保留元素，继续时从暂停处续播（不重读）
                if (st.playStartAt) {
                    st.playedMs += performance.now() - st.playStartAt;
                    st.playStartAt = 0;
                }
                st.currentAudio.pause();
            }
            _speakSeq++;
            if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
        },
        resume: function() {
            if (!st.playing || !st.paused) return;
            st.paused = false;
            if (st.currentAudio) {
                // 从暂停处续播当前遍
                st.currentAudio.play().catch(function() {
                    st.currentAudio = null;
                    if (!st.playing || st.paused) return;
                    st.failed = true;
                    st.currentRepeat = st.repeat;
                    playWordRepeat();
                });
                return;
            }
            if (st.timerDeadline) {
                // 停顿中途暂停过：按剩余时间继续倒计时
                var remaining = Math.max(0, st.timerDeadline - performance.now());
                st.timerDeadline = performance.now() + remaining;
                st.timer = setTimeout(function() {
                    st.timer = null;
                    st.timerDeadline = 0;
                    playWordRepeat();
                }, remaining);
                return;
            }
            playWordRepeat();
        }
    };
}

function stopFollowAlong() {
    if (_followState) {
        if (_followState.timer) { clearTimeout(_followState.timer); _followState.timer = null; }
        _followState.playing = false;
        // 停掉旧会话正在播放的音频（防止新会话开始时叠音）
        if (_followState.currentAudio) { _followState.currentAudio.pause(); _followState.currentAudio = null; }
        // 停止 speak() 的播放队列
        _speakSeq++;
        if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
        var cb = _followState.onUpdate;
        _followState = null;
        // 通知页面收尾 UI（关闭播放条/气泡、清除高亮）
        if (cb) cb({ done: true, stopped: true });
    }
}

