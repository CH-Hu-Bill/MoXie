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
    document.querySelectorAll('.word-card .word').forEach(el => {
        el.style.fontSize = '';
        const len = el.textContent.trim().length;
        if (len > 16) el.style.fontSize = '20px';
        else if (len > 12) el.style.fontSize = '24px';
        else if (len > 9) el.style.fontSize = '28px';
        else if (len > 7) el.style.fontSize = '32px';
    });
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            document.querySelectorAll('.word-card .word, .word-card .meaning').forEach(el => {
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

function showOkOverlayThen(url) {
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
    if (url) showPageLeaving();
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

// =========================================================
// 跟读播放器 (Read-along Audio Player) — 基于音频自然时长
// =========================================================
var _followState = null;

/**
 * 开始跟读播放 (基于音频自然结束时间)
 * @param {string[]} words - 单词列表
 * @param {object} opts - { repeat(次), buffer(秒, 单词间缓冲时间), volume(0-100) }
 * @param {function} onUpdate - 回调 { word, index, total, done, stopped }
 * @returns {object} { stop, pause, resume }
 */
function startFollowAlong(words, opts, onUpdate) {
    stopFollowAlong();
    var st = {
        playing: true,
        paused: false,
        words: words,
        index: 0,
        repeat: opts.repeat || 1,
        buffer: (opts.buffer || 0.5) * 1000,
        volume: (opts.volume || 80) / 100,
        timer: null,
        currentAudio: null,
        currentRepeat: 0
    };
    _followState = st;

    function playNext() {
        if (!st.playing || st.paused) return;
        if (st.index >= st.words.length) {
            st.playing = false;
            if (onUpdate) onUpdate({ done: true });
            return;
        }
        var word = st.words[st.index];
        st.currentRepeat = 0;
        if (onUpdate) onUpdate({ word: word, index: st.index + 1, total: st.words.length });
        playWordRepeat();
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
            st.timer = setTimeout(playNext, st.buffer);
            return;
        }
        // 如果上一次还在播放，先停掉
        if (st.currentAudio) { st.currentAudio.pause(); st.currentAudio = null; }
        var word = st.words[st.index];
        var audio = new Audio('https://dict.youdao.com/dictvoice?audio=' + encodeURIComponent(word) + '&type=1');
        audio.volume = st.volume;
        st.currentAudio = audio;
        var played = false;
        audio.onended = function() {
            if (st.currentAudio !== audio) return;
            st.currentAudio = null;
            if (!st.playing || st.paused) return;
            st.currentRepeat++;
            played = true;
            if (st.currentRepeat >= st.repeat) {
                st.index++;
                if (st.index >= st.words.length) {
                    st.playing = false;
                    if (onUpdate) onUpdate({ done: true });
                    return;
                }
                st.timer = setTimeout(playNext, st.buffer);
            } else {
                playWordRepeat();
            }
        };
        audio.onerror = function() {
            st.currentAudio = null;
            if (!st.playing || st.paused) return;
            if (!played) {
                st.currentRepeat++;
                if (st.currentRepeat >= st.repeat) {
                    st.index++;
                    if (st.index >= st.words.length) {
                        st.playing = false;
                        if (onUpdate) onUpdate({ done: true });
                        return;
                    }
                    st.timer = setTimeout(playNext, st.buffer);
                } else {
                    playWordRepeat();
                }
            }
        };
        audio.play().catch(function() {
            st.currentAudio = null;
            if (!st.playing || st.paused) return;
            if (!played) {
                st.currentRepeat++;
                if (st.currentRepeat >= st.repeat) {
                    st.index++;
                    if (st.index >= st.words.length) {
                        st.playing = false;
                        if (onUpdate) onUpdate({ done: true });
                        return;
                    }
                    st.timer = setTimeout(playNext, st.buffer);
                } else {
                    playWordRepeat();
                }
            }
        });
    }

    playNext();

    return {
        stop: function() {
            st.playing = false;
            if (st.timer) { clearTimeout(st.timer); st.timer = null; }
            if (st.currentAudio) { st.currentAudio.pause(); st.currentAudio = null; }
            _speakSeq++;
            if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
            if (onUpdate) onUpdate({ done: true, stopped: true });
        },
        pause: function() {
            st.paused = true;
            if (st.timer) { clearTimeout(st.timer); st.timer = null; }
            if (st.currentAudio) { st.currentAudio.pause(); st.currentAudio = null; }
            _speakSeq++;
            if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
        },
        resume: function() {
            if (!st.playing || !st.paused) return;
            st.paused = false;
            if (st.currentAudio) {
                st.currentAudio = null;
            }
            playNext();
        }
    };
}

function stopFollowAlong() {
    if (_followState) {
        if (_followState.timer) { clearTimeout(_followState.timer); _followState.timer = null; }
        _followState.playing = false;
        // 停止 speak() 的播放队列
        _speakSeq++;
        if (_currentAudio) { _currentAudio.pause(); _currentAudio = null; }
        _followState = null;
    }
}

