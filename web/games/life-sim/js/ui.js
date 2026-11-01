/* =========================================================
 * 人生模拟器 · 界面交互层（立绘舞台版）
 * ========================================================= */
(function () {
  "use strict";

  var LE = window.LifeEngine;
  function $(id) { return document.getElementById(id); }

  var RARITY_NAMES = { 1: "普通", 2: "稀有", 3: "史诗", 4: "传说" };
  var ATTR_DEFS = [
    { k: "lo", name: "颜值", desc: "影响恋爱与机遇" },
    { k: "in", name: "智力", desc: "影响学业与职业" },
    { k: "ph", name: "体质", desc: "影响健康与寿命" },
    { k: "we", name: "家境", desc: "影响起步资金" }
  ];
  var LS_KEY = "lifesim_achievements";

  var ui = {
    talents: [],
    picked: [],
    alloc: { lo: 0, in: 0, ph: 0, we: 0 },
    mode: "raise", /* 默认养成模式 */
    state: null,
    pending: null,
    autoTimer: null,
    autoOn: false,
    lifeLog: [],
    stageKey: "",
    bubbleTimer: null
  };

  /* ---------------- 通用 ---------------- */
  function showScreen(id) {
    var list = document.querySelectorAll(".screen");
    for (var i = 0; i < list.length; i++) list[i].classList.remove("active");
    $(id).classList.add("active");
    window.scrollTo(0, 0);
  }

  function loadAchs() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || "[]"); }
    catch (e) { return []; }
  }
  function saveAchs(list) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(list)); } catch (e) { /* 忽略 */ }
  }

  function toast(msg) {
    var el = document.createElement("div");
    el.className = "toast";
    el.textContent = msg;
    $("toast-wrap").appendChild(el);
    setTimeout(function () { el.classList.add("out"); }, 2200);
    setTimeout(function () { el.remove(); }, 2700);
  }

  function pickRand(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

  /* ---------------- 立绘舞台 ---------------- */
  var STAGES = [
    { max: 2, key: "baby", img: "img/char_baby.png", label: "襁褓" },
    { max: 12, key: "child", img: "img/char_child.png", label: "童年" },
    { max: 24, key: "teen", img: "img/char_teen.png", label: "青春" },
    { max: 64, key: "adult", img: "img/char_adult.png", label: "立业" },
    { max: 999, key: "elder", img: "img/char_elder.png", label: "暮年" }
  ];

  var TAP_LINES = {
    baby: ["咿咿呀呀～", "咕噜咕噜 🍼", "(吐了个泡泡)", "小手抓住了你的手指", "哇！(开心地蹬腿)"],
    child: ["嘿嘿，陪我玩嘛！", "今天作业写好啦～", "我想吃冰淇淋！", "看我翻跟头！", "长大想当宇航员！"],
    teen: ["别管我啦……", "这首歌超好听，听吗？", "考试有点紧张", "朋友约我打球去！", "我有自己的打算"],
    adult: ["最近有点忙……", "谢谢你一直陪着我", "工资到账！", "今晚加个菜？", "压力有点大，抱抱"],
    elder: ["想当年啊……", "身体还硬朗着呢", "常回来看看呀", "给你织了件毛衣", "日子过得真快啊"]
  };

  var ACTION_FX = {
    study: { emoji: "📚", bubble: "埋头苦读中……", chips: ["智力+1", "快乐-0.5"] },
    train: { emoji: "💪", bubble: "挥汗锻炼中……", chips: ["体质+1", "健康+3"] },
    work: { emoji: "💰", bubble: "努力搞钱中……", chips: ["金钱↑", "健康-1"] },
    social: { emoji: "🎉", bubble: "出门交朋友啦～", chips: ["快乐+1"] },
    play: { emoji: "🎈", bubble: "玩得好开心！", chips: ["快乐+2", "健康+1"] },
    rest: { emoji: "💤", bubble: "好好休息中……", chips: ["健康+5"] }
  };

  function stageFor(age) {
    for (var i = 0; i < STAGES.length; i++) if (age <= STAGES[i].max) return STAGES[i];
    return STAGES[STAGES.length - 1];
  }

  /* 预加载全部立绘，切换不闪 */
  STAGES.forEach(function (st) { var im = new Image(); im.src = st.img; });

  function updateCharacter() {
    var s = ui.state;
    if (!s) return;
    var st = stageFor(s.age);
    $("stage-label").textContent = st.label;
    var sprite = $("char-sprite");
    if (ui.stageKey !== st.key) {
      ui.stageKey = st.key;
      sprite.src = st.img;
      sprite.classList.remove("pop");
      void sprite.offsetWidth; /* 重置动画 */
      sprite.classList.add("pop");
      var stage = $("life-stage");
      stage.className = "life-stage stage-" + st.key;
    }
  }

  function say(text, ms) {
    var b = $("bubble");
    b.textContent = text;
    b.classList.remove("hidden");
    b.classList.remove("bubble-in");
    void b.offsetWidth;
    b.classList.add("bubble-in");
    if (ui.bubbleTimer) clearTimeout(ui.bubbleTimer);
    ui.bubbleTimer = setTimeout(function () { b.classList.add("hidden"); }, ms || 2600);
  }

  function spawnParticles(text, n) {
    var layer = $("fx-layer");
    n = n || 6;
    for (var i = 0; i < n; i++) {
      var sp = document.createElement("span");
      sp.className = "fx-p";
      sp.textContent = text;
      sp.style.left = (30 + Math.random() * 40) + "%";
      sp.style.animationDelay = (Math.random() * 0.35) + "s";
      sp.style.fontSize = (18 + Math.random() * 14) + "px";
      layer.appendChild(sp);
      (function (el) { setTimeout(function () { el.remove(); }, 1800); })(sp);
    }
  }

  function spawnChipsFx(chips) {
    var layer = $("fx-layer");
    chips.forEach(function (c, i) {
      var sp = document.createElement("span");
      sp.className = "fx-chip " + (c.v > 0 ? "up" : "down");
      sp.textContent = c.label + (c.v > 0 ? " +" : " ") + c.v;
      sp.style.left = (34 + Math.random() * 24) + "%";
      sp.style.animationDelay = (i * 0.12) + "s";
      layer.appendChild(sp);
      setTimeout(function () { sp.remove(); }, 1900);
    });
  }

  function tapCharacter() {
    var s = ui.state;
    if (!s || !s.alive) return;
    /* 事件抉择弹窗打开时不响应（弹窗本身遮住了舞台） */
    if (ui.pending && !ui.pending.isAction) return;
    var st = stageFor(s.age);
    say(pickRand(TAP_LINES[st.key] || TAP_LINES.child));
    spawnParticles("💗", 5);
    var sprite = $("char-sprite");
    sprite.classList.remove("wiggle");
    void sprite.offsetWidth;
    sprite.classList.add("wiggle");
  }

  /* ---------------- 标题界面 ---------------- */
  function refreshAchCount() {
    var n = loadAchs().length;
    var total = (window.ACHIEVEMENTS || []).length;
    $("ach-count").textContent = n > 0 ? "🏆 已解锁成就 " + n + " / " + total : "🏆 成就系统：" + total + " 个成就等你解锁";
  }

  /* ---------------- 天赋抽取 ---------------- */
  function rollTalentScreen() {
    ui.talents = LE.rollTalents();
    ui.picked = [];
    renderTalents();
    showScreen("screen-talent");
  }

  function renderTalents() {
    var wrap = $("talent-list");
    wrap.innerHTML = "";
    ui.talents.forEach(function (t, i) {
      var card = document.createElement("div");
      card.className = "talent-card rarity-" + t.rarity + (ui.picked.indexOf(t.id) >= 0 ? " selected" : "");
      card.style.animationDelay = (i * 0.05) + "s";
      card.innerHTML =
        '<div class="t-name">' + t.name + '</div>' +
        '<div class="t-rarity">' + RARITY_NAMES[t.rarity] + '</div>' +
        '<div class="t-desc">' + t.desc + '</div>';
      card.addEventListener("click", function () { toggleTalent(t.id); });
      wrap.appendChild(card);
    });
    var btn = $("btn-talent-ok");
    btn.textContent = "确认天赋 (" + ui.picked.length + "/3)";
    btn.disabled = ui.picked.length === 0;
  }

  function toggleTalent(id) {
    var idx = ui.picked.indexOf(id);
    if (idx >= 0) ui.picked.splice(idx, 1);
    else if (ui.picked.length < 3) ui.picked.push(id);
    renderTalents();
  }

  /* ---------------- 属性分配 ---------------- */
  function spentPoints() {
    return ui.alloc.lo + ui.alloc.in + ui.alloc.ph + ui.alloc.we;
  }

  function renderAttrs() {
    var wrap = $("attr-list");
    wrap.innerHTML = "";
    ATTR_DEFS.forEach(function (d) {
      var row = document.createElement("div");
      row.className = "attr-row";
      row.innerHTML =
        '<div class="a-label">' + d.name + '<small>' + d.desc + '</small></div>' +
        '<div class="a-bar"><div class="a-bar-fill" style="width:' + (ui.alloc[d.k] * 10) + '%"></div></div>' +
        '<div class="a-step">' +
        '<button class="step-btn" data-k="' + d.k + '" data-d="-1">−</button>' +
        '<span class="a-val">' + ui.alloc[d.k] + '</span>' +
        '<button class="step-btn" data-k="' + d.k + '" data-d="1">＋</button>' +
        '</div>';
      wrap.appendChild(row);
    });
    var left = 20 - spentPoints();
    $("pts-left").textContent = "剩余 " + left + " 点" + (left > 0 ? "（将转化为快乐）" : "");
    var btns = wrap.querySelectorAll(".step-btn");
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener("click", function () {
        var k = this.getAttribute("data-k");
        var d = parseInt(this.getAttribute("data-d"), 10);
        var nv = ui.alloc[k] + d;
        if (nv < 0 || nv > 10) return;
        if (d > 0 && spentPoints() >= 20) return;
        ui.alloc[k] = nv;
        renderAttrs();
      });
    }
  }

  /* ---------------- HUD 状态条 ---------------- */
  var STAT_DEFS = [
    { k: "lo", name: "颜值", icon: "✨", cap: 15 },
    { k: "in", name: "智力", icon: "🧠", cap: 15 },
    { k: "ph", name: "体质", icon: "💪", cap: 15 },
    { k: "ha", name: "快乐", icon: "😊", cap: 15 },
    { k: "hp", name: "健康", icon: "❤️", cap: 100 }
  ];

  function buildStatBars() {
    var wrap = $("stat-bars");
    wrap.innerHTML = "";
    STAT_DEFS.forEach(function (d) {
      var el = document.createElement("div");
      el.className = "stat-chip";
      el.innerHTML = '<span class="sc-icon">' + d.icon + '</span><span class="sc-name">' + d.name +
        '</span><b class="sc-val" id="val-' + d.k + '">0</b>';
      wrap.appendChild(el);
    });
  }

  function updateStats() {
    var s = ui.state;
    if (!s) return;
    $("age-num").textContent = s.age;
    STAT_DEFS.forEach(function (d) {
      var val = $("val-" + d.k);
      if (val) val.textContent = Math.round(s[d.k] * 10) / 10;
    });
    var mo = $("val-mo");
    if (mo) {
      mo.textContent = "💰 " + LE.fmtMoney(s.mo);
      mo.classList.toggle("neg", s.mo < 0);
    }
  }

  /* ---------------- 日记 ---------------- */
  function appendEntries(entries) {
    var s = ui.state;
    var log = $("life-log");
    var yearDiv = document.createElement("div");
    yearDiv.className = "log-year";
    var body = "";
    entries.forEach(function (en) {
      var chips = "";
      (en.chips || []).forEach(function (c) {
        chips += '<span class="chip ' + (c.v > 0 ? "up" : "down") + '">' + c.label + (c.v > 0 ? "+" : "") + c.v + '</span>';
      });
      body += '<div class="log-entry ' + (en.cls || "") + '">' + en.text +
        (chips ? '<span class="chips">' + chips + '</span>' : "") + '</div>';
      ui.lifeLog.push(s.age + " 岁：" + en.text);
    });
    yearDiv.innerHTML = '<div class="log-age">' + s.age + ' 岁</div><div class="log-body">' + body + '</div>';
    log.appendChild(yearDiv);
    log.scrollTop = log.scrollHeight;
    /* 舞台上的最新动态 */
    var feed = $("year-feed");
    feed.innerHTML = '<div class="yf-age">' + s.age + ' 岁</div>' + body;
  }

  function openDiary() {
    $("diary-drawer").classList.add("open");
    $("diary-mask").classList.remove("hidden");
  }
  function closeDiary() {
    $("diary-drawer").classList.remove("open");
    $("diary-mask").classList.add("hidden");
  }

  /* ---------------- 选择（行动栏 / 事件弹窗） ---------------- */
  function buildOptButton(o, idx, compact) {
    var b = document.createElement("button");
    b.className = "choice-opt";
    b.style.animationDelay = (idx * 0.05) + "s";
    var main = document.createElement("span");
    main.className = "opt-main";
    var t = document.createElement("span");
    t.className = "opt-t";
    t.textContent = o.t;
    main.appendChild(t);
    if (o.d) {
      var d = document.createElement("span");
      d.className = "opt-d";
      d.textContent = o.d;
      main.appendChild(d);
    }
    b.appendChild(main);
    b.addEventListener("click", function () { pickChoice(idx); });
    return b;
  }

  function showChoice(pending) {
    ui.pending = pending;
    if (pending.isAction) {
      /* 养成行动 → 底部行动栏 */
      var grid = $("action-grid");
      grid.innerHTML = "";
      pending.opts.forEach(function (o, idx) {
        grid.appendChild(buildOptButton(o, idx, true));
      });
      grid.classList.remove("hidden");
      $("casual-bar").classList.add("hidden");
      say("这一年想做什么呀？", 3200);
    } else {
      /* 事件抉择 → 中央弹窗 */
      $("action-grid").classList.add("hidden");
      var box = $("choice-box");
      box.innerHTML = "";
      var title = document.createElement("div");
      title.className = "c-title";
      title.textContent = "⚡ " + pending.title;
      box.appendChild(title);
      var opts = document.createElement("div");
      opts.className = "c-opts";
      pending.opts.forEach(function (o, idx) { opts.appendChild(buildOptButton(o, idx)); });
      box.appendChild(opts);
      var hint = document.createElement("div");
      hint.className = "c-hint";
      hint.textContent = "可按数字键 1-" + pending.opts.length + " 快速选择";
      box.appendChild(hint);
      $("choice-modal").classList.remove("hidden");
    }
    $("btn-next").disabled = true;
  }

  function hideChoiceUI() {
    $("choice-modal").classList.add("hidden");
  }

  function pickChoice(idx) {
    if (!ui.pending) return;
    var wasAction = !!ui.pending.isAction;
    var opt = ui.pending.opts[idx];
    var entries = LE.resolveChoice(ui.state, ui.pending, idx);
    ui.pending = null;
    hideChoiceUI();
    $("btn-next").disabled = false;
    /* 互动反馈 */
    if (wasAction && opt && ACTION_FX[opt.id]) {
      var afx = ACTION_FX[opt.id];
      say(afx.bubble);
      spawnParticles(afx.emoji, 7);
      var sprite = $("char-sprite");
      sprite.classList.remove("wiggle");
      void sprite.offsetWidth;
      sprite.classList.add("wiggle");
    }
    appendEntries(entries);
    if (entries.length && entries[0].chips && entries[0].chips.length) spawnChipsFx(entries[0].chips);
    updateStats();
    updateCharacter();
    /* 选择可能直接影响生死 */
    var extra = LE.checkDeath(ui.state);
    if (extra && extra.length) {
      appendEntries(extra);
      if (!ui.state.alive) { onDeath(); return; }
    }
    if (ui.state.mode === "raise") {
      /* 养成模式：选完自动结算本年，并带出下一年安排 */
      renderContinueBar();
      setTimeout(function () { if (!ui.pending && ui.state && ui.state.alive) nextYear(); }, wasAction ? 750 : 500);
    } else if (ui.autoOn && ui.state.alive) {
      startTimer();
    }
  }

  function nextYear() {
    var s = ui.state;
    if (!s || !s.alive || ui.pending) return;
    var r = LE.runYear(s);
    if (r.entries.length) appendEntries(r.entries);
    updateStats();
    updateCharacter();
    /* 养成模式：结算完本年后，直接取下一年的行动选择（引擎不会推进年龄） */
    if (!r.pending && !r.dead && s.mode === "raise" && s.age >= 2 && s.alive) {
      var r2 = LE.runYear(s);
      if (r2.pending) r = r2;
    }
    if (r.pending) { showChoice(r.pending); pauseTimer(); }
    if (r.dead) { onDeath(); }
  }

  function onDeath() {
    stopAuto();
    $("char-sprite").classList.add("is-dead");
    $("action-grid").classList.add("hidden");
    $("casual-bar").classList.add("hidden");
    say("……", 1500);
    setTimeout(showSummary, 900);
  }

  function pauseTimer() {
    if (ui.autoTimer) { clearInterval(ui.autoTimer); ui.autoTimer = null; }
    $("btn-auto").textContent = "⏩ 自动播放";
  }

  function stopAuto() {
    ui.autoOn = false;
    pauseTimer();
  }

  function startTimer() {
    if (ui.autoTimer) return;
    if (!ui.state || !ui.state.alive) return;
    $("btn-auto").textContent = "⏸ 暂停";
    ui.autoTimer = setInterval(function () {
      if (!ui.state || !ui.state.alive) { stopAuto(); return; }
      if (ui.pending) return;
      nextYear();
    }, 420);
  }

  function toggleAuto() {
    if (ui.autoOn) { stopAuto(); return; }
    ui.autoOn = true;
    startTimer();
  }

  /* ---------------- 总结 ---------------- */
  function showSummary() {
    var s = ui.state;
    if (!s || !s.result) return;
    var r = s.result;

    var owned = loadAchs();
    var newOnes = [];
    r.achs.forEach(function (a) {
      if (owned.indexOf(a.id) < 0) { owned.push(a.id); newOnes.push(a); }
    });
    saveAchs(owned);
    newOnes.forEach(function (a, i) {
      setTimeout(function () { toast("🏆 解锁成就：" + a.name); }, 600 + i * 700);
    });

    var finalImg = stageFor(r.age).img;
    var statsHtml =
      '<div class="sum-cell"><div class="v">' + r.age + '</div><div class="k">寿命</div></div>' +
      '<div class="sum-cell"><div class="v">' + r.stats.lo + '</div><div class="k">颜值</div></div>' +
      '<div class="sum-cell"><div class="v">' + r.stats.in + '</div><div class="k">智力</div></div>' +
      '<div class="sum-cell"><div class="v">' + r.stats.ph + '</div><div class="k">体质</div></div>' +
      '<div class="sum-cell"><div class="v">' + r.stats.ha + '</div><div class="k">快乐</div></div>' +
      '<div class="sum-cell"><div class="v">' + LE.fmtMoney(r.stats.mo) + '</div><div class="k">财富</div></div>';

    var achsHtml = "";
    if (r.achs.length) {
      r.achs.forEach(function (a, i) {
        var isNew = newOnes.some(function (n) { return n.id === a.id; });
        achsHtml += '<span class="ach-badge' + (isNew ? " new" : "") + '" style="animation-delay:' + (i * 0.08) + 's" title="' + a.desc + '">' + a.name + '</span>';
      });
    } else {
      achsHtml = '<span class="hint">这一生没有解锁任何成就……</span>';
    }

    $("summary-panel").innerHTML =
      '<img class="sum-char" src="' + finalImg + '" alt="人生剪影">' +
      '<div class="sum-cause">' + r.cause + '</div>' +
      '<div class="sum-title">' + r.title + '</div>' +
      '<div class="sum-score">✦ 人生评分 ' + r.score + ' ✦</div>' +
      '<div class="sum-grid">' + statsHtml + '</div>' +
      '<div class="sum-achs">' + achsHtml + '</div>' +
      '<div class="sum-log">' + ui.lifeLog.join("<br>") + '</div>' +
      '<div class="toolbar center">' +
      '<button id="btn-again" class="btn btn-primary btn-lg">再活一次 🔄</button>' +
      '<button id="btn-export-sum" class="btn btn-ghost">📤 导出模板码</button>' +
      '<button id="btn-home" class="btn btn-ghost">回到主页</button>' +
      '</div>';

    $("btn-again").addEventListener("click", function () { rollTalentScreen(); });
    $("btn-export-sum").addEventListener("click", openExport);
    $("btn-home").addEventListener("click", function () { refreshAchCount(); showScreen("screen-title"); });
    showScreen("screen-summary");
  }

  /* ---------------- 模态框 & 模板码 ---------------- */
  function openModal(html) {
    $("modal-body").innerHTML = html;
    $("modal-wrap").classList.remove("hidden");
  }
  function closeModal() { $("modal-wrap").classList.add("hidden"); }

  function copyText(t) {
    function fallback() {
      var ta = document.createElement("textarea");
      ta.value = t; ta.style.position = "fixed"; ta.style.opacity = "0";
      document.body.appendChild(ta); ta.select();
      try { document.execCommand("copy"); toast("📋 已复制到剪贴板"); }
      catch (e) { toast("复制失败，请手动长按复制"); }
      ta.remove();
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function () { toast("📋 已复制到剪贴板"); }, fallback);
    } else fallback();
  }

  function openExport() {
    var code = LE.encodeTemplate(ui.picked, ui.alloc, ui.mode);
    openModal(
      '<div class="m-title">📤 导出模板码</div>' +
      '<p class="m-sub">把当前的天赋 + 属性 + 模式组合分享给朋友</p>' +
      '<div class="code-view">' + code + '</div>' +
      '<div class="toolbar center"><button id="btn-copy" class="btn btn-primary">📋 复制模板码</button></div>');
    $("btn-copy").addEventListener("click", function () { copyText(code); });
  }

  function openImport() {
    openModal(
      '<div class="m-title">📥 导入模板码</div>' +
      '<p class="m-sub">粘贴 16 位数字模板码，还原一套天赋与属性搭配</p>' +
      '<input id="code-input" class="code-input" maxlength="16" inputmode="numeric" autocomplete="off" placeholder="例如 0001234567890123">' +
      '<div class="toolbar center"><button id="btn-do-import" class="btn btn-primary">确认导入</button></div>');
    $("btn-do-import").addEventListener("click", function () {
      var r = LE.decodeTemplate($("code-input").value);
      if (r.err) { toast("❌ " + r.err); return; }
      closeModal();
      applyTemplate(r);
    });
  }

  function applyTemplate(r) {
    ui.mode = r.mode;
    syncModeSwitch();
    ui.picked = r.talents.slice();
    ui.alloc = { lo: r.alloc.lo, in: r.alloc.in, ph: r.alloc.ph, we: r.alloc.we };
    var T = window.TALENTS || [];
    var pool = [];
    r.talents.forEach(function (id) {
      for (var i = 0; i < T.length; i++) if (T[i].id === id) { pool.push(T[i]); break; }
    });
    var guard = 0;
    while (pool.length < 10 && guard++ < 30) {
      LE.rollTalents().forEach(function (t) {
        if (pool.length >= 10) return;
        var dup = pool.some(function (p) { return p.id === t.id; });
        if (!dup) pool.push(t);
      });
    }
    ui.talents = pool;
    renderTalents();
    showScreen("screen-talent");
    toast("✅ 模板已导入：" + r.talents.length + " 个天赋 · " + (r.mode === "raise" ? "养成" : "休闲") + "模式");
  }

  function syncModeSwitch() {
    var cards = $("mode-switch").querySelectorAll(".mode-card");
    for (var i = 0; i < cards.length; i++) {
      cards[i].classList.toggle("active", cards[i].getAttribute("data-mode") === ui.mode);
    }
  }

  /* ---------------- 开局 ---------------- */
  function startLife() {
    ui.state = LE.createState(ui.picked, ui.alloc, ui.mode);
    ui.pending = null;
    ui.lifeLog = [];
    ui.stageKey = "";
    $("life-log").innerHTML = "";
    $("year-feed").innerHTML = "";
    $("choice-modal").classList.add("hidden");
    $("char-sprite").classList.remove("is-dead");
    $("btn-next").disabled = false;
    buildStatBars();
    updateStats();
    updateCharacter();
    stopAuto();
    /* 出生播报 */
    var s = ui.state;
    var birth = document.createElement("div");
    birth.className = "log-year";
    birth.innerHTML = '<div class="log-age">0 岁</div><div class="log-body">' +
      '<div class="log-entry special">🎉 一声啼哭，你来到了这个世界，取名「' + s.name + '」。</div></div>';
    $("life-log").appendChild(birth);
    ui.lifeLog.push("0 岁：你来到了这个世界，取名「" + s.name + "」。");
    $("year-feed").innerHTML = '<div class="yf-age">0 岁</div>' +
      '<div class="log-entry special">🎉 你来到了这个世界，取名「' + s.name + '」。</div>';
    /* 按模式显示底部栏 */
    if (s.mode === "raise") {
      $("action-grid").classList.remove("hidden");
      $("casual-bar").classList.add("hidden");
      renderIdleActionBar();
    } else {
      $("action-grid").classList.add("hidden");
      $("casual-bar").classList.remove("hidden");
    }
    say("哇～我出生啦！", 3000);
    spawnParticles("🎉", 6);
    showScreen("screen-life");
  }

  /* 养成模式下，未到选择年龄（0-1 岁）时行动栏显示「陪伴成长」 */
  function renderIdleActionBar() {
    var grid = $("action-grid");
    grid.innerHTML = "";
    var b = document.createElement("button");
    b.className = "choice-opt idle-grow";
    b.innerHTML = '<span class="opt-main"><span class="opt-t">🌱 陪伴成长 · 下一年</span>' +
      '<span class="opt-d">宝宝还小，先悉心照料吧</span></span>';
    b.addEventListener("click", nextYear);
    grid.appendChild(b);
  }

  /* 选择结算后的过渡按钮（防止自动推进被打断时无路可走） */
  function renderContinueBar() {
    var grid = $("action-grid");
    grid.innerHTML = "";
    var b = document.createElement("button");
    b.className = "choice-opt idle-grow";
    b.innerHTML = '<span class="opt-main"><span class="opt-t">⏳ 时光流转……</span>' +
      '<span class="opt-d">点击继续</span></span>';
    b.addEventListener("click", nextYear);
    grid.appendChild(b);
  }

  /* ---------------- 事件绑定 ---------------- */
  function bind() {
    $("btn-start").addEventListener("click", rollTalentScreen);
    $("btn-reroll").addEventListener("click", function () {
      ui.talents = LE.rollTalents();
      ui.picked = [];
      renderTalents();
    });
    $("btn-talent-ok").addEventListener("click", function () {
      renderAttrs();
      showScreen("screen-attr");
    });
    $("btn-attr-back").addEventListener("click", function () { showScreen("screen-talent"); });
    $("btn-begin").addEventListener("click", startLife);
    $("btn-next").addEventListener("click", nextYear);
    $("btn-auto").addEventListener("click", toggleAuto);
    $("char-zone").addEventListener("click", tapCharacter);
    $("btn-diary").addEventListener("click", openDiary);
    $("btn-diary-close").addEventListener("click", closeDiary);
    $("diary-mask").addEventListener("click", closeDiary);

    var cards = $("mode-switch").querySelectorAll(".mode-card");
    for (var i = 0; i < cards.length; i++) {
      cards[i].addEventListener("click", function () {
        ui.mode = this.getAttribute("data-mode");
        syncModeSwitch();
      });
    }

    $("btn-import").addEventListener("click", openImport);
    $("btn-export").addEventListener("click", openExport);
    $("modal-close").addEventListener("click", closeModal);
    $("modal-wrap").addEventListener("click", function (e) {
      if (e.target === this) closeModal();
    });

    document.addEventListener("keydown", function (e) {
      if (!$("modal-wrap").classList.contains("hidden")) {
        var onBtn = document.activeElement && document.activeElement.tagName === "BUTTON";
        if (e.code === "Enter" && !onBtn && $("btn-do-import")) $("btn-do-import").click();
        if (e.code === "Escape") closeModal();
        return;
      }
      if (!$("screen-life").classList.contains("active")) return;
      if (ui.pending && e.key >= "1" && e.key <= "9") {
        var idx = parseInt(e.key, 10) - 1;
        if (idx < ui.pending.opts.length) { e.preventDefault(); pickChoice(idx); }
        return;
      }
      if ((e.code === "Space" || e.code === "Enter") && !ui.pending) {
        e.preventDefault(); nextYear();
      }
    });
  }

  bind();
  syncModeSwitch();
  refreshAchCount();
})();
