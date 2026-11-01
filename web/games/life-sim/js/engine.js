/* =========================================================
 * 人生模拟器 · 核心引擎（纯逻辑，无 DOM 依赖，可单测）
 * ========================================================= */
(function (root) {
  "use strict";

  /* ---------------- 随机工具 ---------------- */
  function rand(a, b) { return a + Math.random() * (b - a); }
  function ri(a, b) { return Math.floor(rand(a, b + 1)); }
  function chance(p) { return Math.random() < p; }
  function pick(arr) { return arr && arr.length ? arr[Math.floor(Math.random() * arr.length)] : null; }

  function safe(fn, fallback) {
    try { return fn(); }
    catch (e) { if (root.console && root.console.warn) root.console.warn("[engine]", e); return fallback; }
  }

  var FX_LABELS = { lo: "颜值", in: "智力", ph: "体质", we: "家境", ha: "快乐", mo: "金钱", hp: "健康" };

  function sanitize(n) { return (typeof n === "number" && isFinite(n)) ? n : 0; }

  /* 施加属性变化，返回变化摘要 */
  function fx(s, obj) {
    if (!obj) return [];
    if (typeof obj === "function") obj = safe(function () { return obj(s); }, null) || {};
    var chips = [];
    for (var k in obj) {
      if (!FX_LABELS[k]) continue;
      var v = sanitize(obj[k]);
      if (v === 0) continue;
      if (k === "mo") s.mo += v;
      else if (k === "hp") s.hp = Math.min(100, s.hp + v);
      else if (k === "ha") s.ha = Math.min(15, Math.max(0, s.ha + v));
      else s[k] = Math.max(0, s[k] + v);
      chips.push({ k: k, v: v, label: FX_LABELS[k] });
    }
    return chips;
  }

  function hasT(s, id) { return s.talentIds.indexOf(id) >= 0; }

  var NAMES = ["林小满", "苏晚晴", "陈嘉树", "江远帆", "沈知夏", "顾一诺", "周予安", "温以晴",
    "陆则铭", "许清欢", "叶蓁蓁", "秦朗", "方沐阳", "程诗雨", "韩东来", "白若曦"];
  function randName() { return pick(NAMES); }

  /* ---------------- 天赋映射 ---------------- */
  var TALENT_MAP = {};
  (root.TALENTS || []).forEach(function (t) { TALENT_MAP[t.id] = t; });

  /* ---------------- 职业表 ---------------- */
  var JOBS = [
    { id: "street", name: "街头小贩", edu: 0, salary: 30000 },
    { id: "waiter", name: "服务员", edu: 0, salary: 40000 },
    { id: "factory", name: "工厂技工", edu: 0, salary: 50000 },
    { id: "courier", name: "快递员", edu: 0, salary: 60000, c: function (s) { return s.ph >= 4; } },
    { id: "driver", name: "司机", edu: 1, salary: 60000 },
    { id: "sales", name: "销售", edu: 1, salary: 70000, c: function (s) { return s.lo >= 4; } },
    { id: "clerk", name: "公司文员", edu: 2, salary: 80000 },
    { id: "teacher", name: "教师", edu: 3, salary: 90000, c: function (s) { return s.in >= 6; } },
    { id: "civil", name: "公务员", edu: 3, salary: 100000 },
    { id: "lawyer", name: "律师", edu: 3, salary: 160000, c: function (s) { return s.in >= 7; } },
    { id: "finance", name: "金融分析师", edu: 3, salary: 180000, c: function (s) { return s.in >= 8 || s.we >= 7; } },
    { id: "programmer", name: "程序员", edu: 3, salary: 200000, c: function (s) { return s.in >= 6; } },
    { id: "doctor", name: "医生", edu: 4, salary: 150000, c: function (s) { return s.in >= 7; } },
    { id: "artist", name: "艺术家", edu: 0, salary: 50000, c: function (s) { return hasT(s, "t_yishu"); } }
  ];

  var CULT_LEVELS = ["炼气期", "筑基期", "金丹期", "元婴期", "化神期", "渡劫期"];

  /* ---------------- 工具 ---------------- */
  function mkEntry(text, chips, cls) { return { text: text, chips: chips || [], cls: cls || "" }; }

  function fmtMoney(n) {
    n = Math.round(sanitize(n));
    var neg = n < 0 ? "-" : "";
    var a = Math.abs(n);
    if (a >= 100000000) return neg + (a / 100000000).toFixed(2) + " 亿";
    if (a >= 10000) return neg + Math.round(a / 10000) + " 万";
    return neg + a + " 元";
  }

  function clampInt(v, min, max) {
    v = Math.round(sanitize(v));
    return Math.max(min, Math.min(max, v));
  }

  function examBonus(s) {
    return (hasT(s, "t_xueba") ? 30 : 0) + (hasT(s, "t_tiancai") ? 50 : 0);
  }

  function isStudying(s) {
    var a = s.age, f = s.flags;
    if (a >= 6 && a <= 15) return true;
    if (a >= 16 && a <= 18 && f.hs) return true;
    if (f.uni && a >= 18 && a <= 22) return true;
    if (f.grad && a >= 22 && a <= 25) return true;
    return false;
  }

  /* ---------------- 状态创建 ---------------- */
  function createState(talentIds, alloc, mode) {
    alloc = alloc || {};
    var lo = clampInt(alloc.lo, 0, 10);
    var inV = clampInt(alloc.in, 0, 10);
    var ph = clampInt(alloc.ph, 0, 10);
    var we = clampInt(alloc.we, 0, 10);
    var spent = lo + inV + ph + we;
    var s = {
      age: 0, lo: lo, in: inV, ph: ph, we: we,
      name: randName(),
      ha: 5 + Math.max(0, 20 - spent),
      hp: 100,
      mo: we * 20000,
      talentIds: (talentIds || []).slice(),
      flags: {},
      used: {},
      haSum: 0,
      startWe: we,
      alive: true,
      mode: mode === "raise" ? "raise" : "casual"
    };
    s.talentIds.forEach(function (id) {
      var t = TALENT_MAP[id];
      if (t && t.init) safe(function () { t.init(s); });
    });
    return s;
  }

  /* ---------------- 事件执行 ---------------- */
  function resolveText(s, t) {
    if (typeof t === "function") return safe(function () { return String(t(s)); }, "");
    return t || "";
  }

  function condOk(s, ev) {
    if (!ev.c) return true;
    return safe(function () { return !!ev.c(s); }, false);
  }

  function runEvent(s, ev, entries) {
    var text = resolveText(s, ev.text);
    var chips = ev.fx ? fx(s, ev.fx) : [];
    var extra = "";
    if (ev.run) {
      var r = safe(function () { return ev.run(s); }, "");
      if (typeof r === "string" && r) extra = r;
    }
    var full = text + (extra ? (text ? " " : "") + extra : "");
    if (full) entries.push(mkEntry(full, chips, ev.cls));
    if (ev.once) s.used[ev.id] = 1;
  }

  function makeChoice(s, ev) {
    var opts = (ev.ch || []).filter(function (o) {
      if (!o.c) return true;
      return safe(function () { return !!o.c(s); }, false);
    });
    if (!opts.length) return null;
    return { title: resolveText(s, ev.text), opts: opts, evId: ev.id };
  }

  function resolveChoice(s, pending, idx) {
    var entries = [];
    if (!pending || !pending.opts) return entries;
    var opt = pending.opts[idx];
    if (!opt) return entries;
    var chips = opt.fx ? fx(s, opt.fx) : [];
    var extra = "";
    if (opt.run) {
      var r = safe(function () { return opt.run(s); }, "");
      if (typeof r === "string" && r) extra = r;
    }
    var txt = "【选择】" + opt.t + "。" + (extra ? " " + extra : "") + (opt.log ? " " + opt.log : "");
    entries.push(mkEntry(txt, chips, "milestone"));
    return entries;
  }

  /* ---------------- 求职 ---------------- */
  function assignJob(s, jobId) {
    var job = null;
    for (var i = 0; i < JOBS.length; i++) if (JOBS[i].id === jobId) { job = JOBS[i]; break; }
    if (!job) job = JOBS[0];
    s.flags.job = job.id;
    s.flags.jobName = job.name;
    s.flags.salary = job.salary;
    s.flags.salaryMult = 1;
    s.flags.working = true;
    s.flags.jobYears = 0;
    return job;
  }

  function findJob(s) {
    var edu = s.flags.eduLevel || 0;
    var list = JOBS.filter(function (j) {
      if (j.edu > edu) return false;
      if (j.c) return safe(function () { return !!j.c(s); }, false);
      return true;
    });
    if (!list.length) list = [JOBS[0]];
    var total = 0;
    list.forEach(function (j) { total += 10 + j.salary / 20000; });
    var r = Math.random() * total, job = list[0];
    for (var i = 0; i < list.length; i++) {
      r -= 10 + list[i].salary / 20000;
      if (r <= 0) { job = list[i]; break; }
    }
    assignJob(s, job.id);
    return mkEntry("你找到了一份工作：" + job.name + "（年薪 " + fmtMoney(job.salary) + "）。", [], "milestone");
  }

  /* ---------------- 升学节点 ---------------- */
  function setUni(s, tier, name) {
    s.flags.uni = tier;
    s.flags.uniName = name;
    s.flags.eduLevel = Math.max(s.flags.eduLevel || 0, tier === "junior" ? 2 : 3);
  }

  function zhongkao(s, entries) {
    var score = Math.min(s.in, 12) * 8 + ri(-20, 20) + Math.round(examBonus(s) * 0.5);
    if (score >= 85) {
      s.flags.hs = "key";
      entries.push(mkEntry("中考你发挥出色，考入了重点高中！", [], "good"));
    } else if (score >= 60) {
      s.flags.hs = "normal";
      entries.push(mkEntry("中考结束，你进入了一所普通高中。", [], "milestone"));
    } else {
      s.flags.hs = "voc";
      entries.push(mkEntry("中考成绩不理想，你去了职业技术学校。", [], "milestone"));
    }
  }

  function gaokao(s, entries) {
    var score = Math.round(350 + Math.min(s.in, 12) * 20 + examBonus(s) + ri(-40, 40));
    score = Math.min(750, Math.max(200, score));
    s.flags.gkScore = score;
    var msg;
    if (score >= 650) { setUni(s, "top", "清北大学"); s.flags.uniTop = 1; msg = "高考 " + score + " 分！你被顶尖学府清北大学录取！"; }
    else if (score >= 590) { setUni(s, "985", "临江大学（985）"); msg = "高考 " + score + " 分，你被 985 名校临江大学录取！"; }
    else if (score >= 540) { setUni(s, "tier1", "州立大学（一本）"); msg = "高考 " + score + " 分，你考上了一本院校。"; }
    else if (score >= 470) { setUni(s, "tier2", "城市学院（二本）"); msg = "高考 " + score + " 分，你考上了一所二本大学。"; }
    else if (score >= 400) { setUni(s, "junior", "专科学校"); msg = "高考 " + score + " 分，你被一所专科学校录取。"; }
    else {
      entries.push(mkEntry("高考 " + score + " 分，你落榜了。", [], "bad"));
      return {
        title: "落榜了，接下来怎么办？",
        opts: [
          { t: "复读一年", run: function (s2) { s2.flags.retake = 1; fx(s2, { in: 1 }); return "你选择复读，埋头再战一年。"; } },
          { t: "直接找工作", run: function (s2) { s2.flags.eduLevel = Math.max(s2.flags.eduLevel || 0, 1); s2.flags.needJob = 1; return "你决定步入社会闯荡。"; } }
        ]
      };
    }
    entries.push(mkEntry(msg, [], score >= 650 ? "special" : "good"));
    return null;
  }

  function gradChoice(s, entries) {
    entries.push(mkEntry("你大学毕业了，站在人生的岔路口。", [], "milestone"));
    var opts = [];
    if (s.in >= 7) {
      opts.push({ t: "考研深造", run: function (s2) {
        if (chance(0.5 + s2.in * 0.03)) { s2.flags.grad = 1; return "你成功上岸，成为一名研究生！"; }
        s2.flags.needJob = 1; return "考研失利，你决定先找工作。";
      } });
    }
    opts.push({ t: "考公务员", run: function (s2) {
      if (chance(0.2 + s2.in * 0.02)) { assignJob(s2, "civil"); return "你考上了公务员，端上了铁饭碗！"; }
      s2.flags.needJob = 1; return "考公未果，你转向了招聘市场。";
    } });
    if (s.mo >= 100000) {
      opts.push({ t: "创业", run: function (s2) {
        var p = 0.3 + (hasT(s2, "t_shangye") ? 0.25 : 0) + s2.in * 0.015;
        if (chance(p)) {
          s2.flags.founder = 1; assignJob(s2, "street");
          s2.flags.job = "founder"; s2.flags.jobName = "创始人"; s2.flags.salary = 500000;
          return "创业成功！你的公司活了下来。";
        }
        fx(s2, { mo: -100000, ha: -2 }); s2.flags.needJob = 1;
        return "创业失败了，学费昂贵。";
      } });
    }
    opts.push({ t: "直接找工作", run: function (s2) { s2.flags.needJob = 1; return "你投出了人生第一份简历。"; } });
    return { title: "毕业去向，你决定……", opts: opts };
  }

  /* ---------------- 年度节点 ---------------- */
  function milestones(s, entries) {
    var a = s.age, f = s.flags;

    if (f.retake) { f.retake = 0; return gaokao(s, entries); }

    if (a === 6) entries.push(mkEntry("你上小学了，成为一名小学生。", [], "milestone"));
    else if (a === 13) entries.push(mkEntry("你升入了初中，课业一下子忙了起来。", [], "milestone"));
    else if (a === 15) zhongkao(s, entries);
    else if (a === 16) {
      if (f.hs === "voc") { entries.push(mkEntry("你进入职业技术学校，学习一技之长。", [], "milestone")); f.eduLevel = Math.max(f.eduLevel || 0, 1); }
      else if (f.hs === "key") entries.push(mkEntry("你进入了重点高中，学习氛围浓厚。", [], "milestone"));
      else if (f.hs) entries.push(mkEntry("你进入了普通高中。", [], "milestone"));
    }
    else if (a === 18) {
      if (f.hs === "voc") { entries.push(mkEntry("你从职校毕业了。", [], "milestone")); f.needJob = 1; }
      else if (f.hs) { var pend = gaokao(s, entries); if (pend) return pend; }
    }
    else if (a === 19) {
      if (f.uni) entries.push(mkEntry("你进入" + (f.uniName || "大学") + "报到，大学生活开始了！", [], "milestone"));
    }
    else if (a === 22) {
      if (f.uni && !f.grad) { var pend2 = gradChoice(s, entries); if (pend2) return pend2; }
    }
    else if (a === 25) {
      if (f.grad) { f.gradDone = 1; f.eduLevel = Math.max(f.eduLevel || 0, 4); entries.push(mkEntry("你硕士毕业了。", [], "milestone")); f.needJob = 1; }
    }
    else if (a === 60) {
      if (f.working) {
        f.pension = Math.floor((f.salary || 0) * (f.salaryMult || 1) * 0.4);
        f.working = false; f.retired = 1;
        entries.push(mkEntry("你光荣退休，开始领取养老金（每年 " + fmtMoney(f.pension) + "）。", [], "milestone"));
      }
    }

    /* 待业求职 */
    if (f.needJob || (!f.working && !f.retired && !isStudying(s) && a >= 18)) {
      if (f.needJob || chance(0.75)) {
        f.needJob = 0;
        entries.push(findJob(s));
      }
    }
    return null;
  }

  /* ---------------- 年度结算 ---------------- */
  function economy(s) {
    var f = s.flags;
    if (f.working && f.salary) {
      var income = f.salary * (f.salaryMult || 1);
      if (hasT(s, "t_koucai")) income *= 1.1;
      if (hasT(s, "t_biancheng") && f.job === "programmer") income *= 1.3;
      s.mo += Math.floor(income);
      f.jobYears = (f.jobYears || 0) + 1;
      if (f.job === "programmer" && chance(0.35)) s.hp -= 2;
      if (s.age < 55) {
        var pp = 0.1 + (hasT(s, "t_qingshang") ? 0.08 : 0) + (hasT(s, "t_sheNiu") ? 0.05 : 0);
        if (chance(pp)) f.salaryMult = (f.salaryMult || 1) + 0.15;
      }
    } else if (f.retired && f.pension) {
      s.mo += f.pension;
    }
    if (s.age >= 18) {
      var cost = (f.retired ? 12000 : 15000) + (f.children || 0) * 15000;
      s.mo -= cost;
    }
  }

  function illnessCheck(s, entries) {
    if (s.age < 1) return;
    var p = 0.04 + (s.age > 55 ? 0.03 : 0) + (s.hp < 50 ? 0.03 : 0);
    if (hasT(s, "t_tiewei")) p *= 0.6;
    if (hasT(s, "t_tieren")) p *= 0.3;
    if (hasT(s, "t_guomin")) p *= 1.6;
    if (hasT(s, "t_yangsheng")) p *= 0.7;
    if (chance(p)) {
      var names = ["重感冒", "肠胃炎", "流感", "高烧", "急性过敏"];
      var dmg = ri(3, 8), cost = ri(800, 4000);
      s.hp -= dmg; s.mo -= cost;
      s.flags.illness = (s.flags.illness || 0) + 1;
      s.flags.lastHit = "疾病";
      entries.push(mkEntry("你得了" + pick(names) + "，看病花了 " + cost + " 元。",
        [{ k: "hp", v: -dmg, label: "健康" }], "bad"));
    }
  }

  function cultYear(s, entries) {
    var f = s.flags;
    if (!f.cult) return;
    if (f.cult < 6) {
      var p = 0.10 + s.in * 0.012 + (hasT(s, "t_jianchi") ? 0.05 : 0);
      if (chance(p)) {
        f.cult += 1;
        fx(s, { hp: 15, ph: 1 });
        entries.push(mkEntry("灵气涌动，你突破至「" + CULT_LEVELS[f.cult - 1] + "」，寿元大增！", [], "special"));
      }
    } else {
      if (chance(0.25)) {
        var sp = 0.45 + s.ph * 0.01 + s.in * 0.01;
        if (chance(sp)) {
          f.ascend = 1;
          entries.push(mkEntry("天雷滚滚，劫云散去——你渡劫成功，破碎虚空，飞升成仙！", [], "special"));
          die(s, "飞升成仙", entries, true);
        } else {
          s.hp -= 40;
          s.flags.lastHit = "渡劫失败";
          entries.push(mkEntry("渡劫失败，天雷重伤了你。", [{ k: "hp", v: -40, label: "健康" }], "bad"));
        }
      }
    }
  }

  function aging(s) {
    if (s.age < 50 && s.hp < 100) s.hp = Math.min(100, s.hp + (s.age < 30 ? 1 : 0.5));
    if (s.age >= 35) {
      var decay = (s.age - 35) * 0.05 + (s.age > 55 ? (s.age - 55) * 0.10 : 0);
      decay *= (1.25 - Math.min(s.ph, 25) / 25);
      if (hasT(s, "t_changsheng")) decay *= 0.5;
      if (s.flags.cult) decay *= Math.max(0.1, 1 - 0.15 * s.flags.cult);
      if (s.flags.chronic) decay += 2;
      s.hp -= decay * rand(0.6, 1.4);
    }
    if (s.age >= 50 && !hasT(s, "t_bulao") && s.age % 3 === 0 && s.lo > 1) s.lo -= 1;
    s.ha += (5 - s.ha) * 0.12;
  }

  function spouseCheck(s, entries) {
    if (s.flags.married && s.flags.spouseAlive && s.age > 60 && chance(0.02)) {
      s.flags.spouseAlive = 0;
      fx(s, { ha: -3 });
      entries.push(mkEntry("你的爱人先你一步离开了人世，你悲痛不已。", [], "bad"));
    }
  }

  /* ---------------- 死亡与结算 ---------------- */
  function die(s, cause, entries, skipRevive) {
    if (!skipRevive && hasT(s, "t_shikong") && !s.flags.revived) {
      s.flags.revived = 1;
      s.hp = 50;
      entries.push(mkEntry("时空逆转！你在命运的岔路口被拉了回来，奇迹般活了下来。", [], "special"));
      return;
    }
    s.alive = false;
    s.causeOfDeath = cause;
    var cls = cause === "飞升成仙" ? "special" : "bad";
    entries.push(mkEntry("【终章】" + cause + " —— 你在 " + s.age + " 岁时走完了这一生。", [], cls));
    finalize(s);
  }

  function deathCheck(s, entries) {
    if (!s.alive) return;
    if (s.hp <= 0) {
      var cause = s.flags.lastHit ? s.flags.lastHit + "，最终不治" : "疾病缠身";
      die(s, cause, entries);
      return;
    }
    if (s.age > 70) {
      var p = Math.pow(s.age - 70, 2) * 0.00012 * (1.4 - Math.min(s.ph, 25) / 25);
      if (hasT(s, "t_changsheng")) p *= 0.5;
      if (s.flags.cult) p *= Math.max(0.05, 1 - 0.2 * s.flags.cult);
      if (chance(p)) { die(s, "在睡梦中安详离世", entries); return; }
    }
  }

  function finalize(s) {
    var achs = (root.ACHIEVEMENTS || []).filter(function (a) {
      return safe(function () { return !!a.cond(s); }, false);
    });
    var score = Math.round(
      s.age * 1.5 +
      (s.lo + s.in + s.ph + s.ha) * 2 +
      Math.max(0, Math.min(s.mo, 10000000)) / 333333 +
      achs.length * 8 +
      (s.flags.cult || 0) * 30 +
      (s.flags.ascend ? 500 : 0)
    );
    var title;
    if (s.flags.ascend) title = "飞升上仙";
    else if (score < 120) title = "坎坷一生";
    else if (score < 180) title = "平凡之路";
    else if (score < 240) title = "幸福美满";
    else if (score < 300) title = "人生赢家";
    else if (score < 360) title = "传奇人生";
    else title = "旷世奇才";
    s.result = {
      age: s.age,
      cause: s.causeOfDeath,
      score: score,
      title: title,
      achs: achs,
      stats: {
        lo: Math.round(s.lo), in: Math.round(s.in), ph: Math.round(s.ph),
        ha: Math.round(s.ha * 10) / 10, mo: s.mo
      }
    };
  }

  /* ---------------- 养成模式：每年行动安排 ---------------- */
  function makeActionChoice(s) {
    var nextAge = s.age + 1;
    var opts = [];
    opts.push({ id: "study", t: "📚 刻苦学习", d: "智力↑ 快乐↓ 健康↓", c: function () { return nextAge >= 3; },
      run: function (s2) {
        var g = 0.5 + Math.random() * 0.5 + (isStudying(s2) ? 0.3 : 0)
          + ((hasT(s2, "t_xueba") || hasT(s2, "t_tiancai")) ? 0.3 : 0)
          + (hasT(s2, "t_shuchong") ? 0.2 : 0);
        fx(s2, { in: Math.round(g * 10) / 10, ha: -0.5, hp: -1 });
        return "这一年你埋头苦读，学识渐长。";
      } });
    opts.push({ id: "train", t: "🏃 锻炼体魄", d: "体质↑ 健康↑", c: function () { return nextAge >= 6; },
      run: function (s2) {
        var g = 0.4 + Math.random() * 0.4
          + ((hasT(s2, "t_yundong") || hasT(s2, "t_yundongjian")) ? 0.3 : 0);
        fx(s2, { ph: Math.round(g * 10) / 10, hp: 3, ha: 0.5 });
        return "这一年你挥汗如雨，身体更结实了。";
      } });
    opts.push({ id: "work", t: "💰 打工搞钱", d: "金钱↑ 健康↓", c: function () { return nextAge >= 12; },
      run: function (s2) {
        var m;
        if (s2.flags.working && s2.flags.salary) m = Math.floor(s2.flags.salary * (s2.flags.salaryMult || 1) * 0.12);
        else if (nextAge >= 18) m = ri(8000, 20000);
        else m = ri(1000, 4000);
        if (hasT(s2, "t_fuye")) m = Math.floor(m * 1.4);
        fx(s2, { mo: m, hp: -1, ha: -0.5 });
        return "这一年你业余打拼，赚了 " + fmtMoney(m) + "。";
      } });
    opts.push({ id: "social", t: "🤝 社交聚会", d: "快乐↑ 有机率颜值↑", c: function () { return nextAge >= 6; },
      run: function (s2) {
        var o = { ha: 1 + (hasT(s2, "t_haoren") ? 0.5 : 0) };
        if (chance(0.4)) o.lo = 0.5;
        fx(s2, o);
        return "这一年你结识了新朋友，圈子更大了。";
      } });
    opts.push({ id: "play", t: "🎮 尽情玩乐", d: "快乐↑↑ 健康↑", run: function (s2) {
        fx(s2, { ha: 2 + (hasT(s2, "t_youxi") ? 0.5 : 0), hp: 1 });
        return "这一年你玩得尽兴，心情大好。";
      } });
    opts.push({ id: "rest", t: "😴 休养身心", d: "健康↑↑", run: function (s2) {
        fx(s2, { hp: 5 + (hasT(s2, "t_yangsheng") ? 2 : 0), ha: 0.5 });
        return "这一年你好好休养，精力恢复了。";
      } });
    opts = opts.filter(function (o) { return !o.c || safe(function () { return !!o.c(s); }, false); });
    opts.forEach(function (o) {
      var orig = o.run;
      o.run = function (s2) { s2.flags.acted = s2.age + 1; return orig ? orig(s2) : ""; };
    });
    return { title: "🌱 " + nextAge + " 岁 · 这一年怎么安排？", opts: opts, isAction: true };
  }

  /* ---------------- 主循环：推演一年 ---------------- */
  function runYear(s) {
    var entries = [];
    if (!s.alive) return { entries: entries, pending: null, dead: true };
    /* 养成模式：先选择本年行动，再推演 */
    if (s.mode === "raise" && s.age >= 2 && (s.flags.acted || 0) !== s.age + 1) {
      return { entries: entries, pending: makeActionChoice(s), dead: false };
    }
    s.age++;

    /* 天赋年度效果 */
    s.talentIds.forEach(function (id) {
      var t = TALENT_MAP[id];
      if (t && t.year) safe(function () { t.year(s); });
    });

    /* 关键节点 */
    var pending = milestones(s, entries);

    if (!pending && s.alive) {
      /* 必发事件 */
      (root.EVENTS || []).forEach(function (ev) {
        if (!ev.must || s.used[ev.id]) return;
        if (ev.age[0] > s.age || ev.age[1] < s.age) return;
        if (!condOk(s, ev)) return;
        s.used[ev.id] = 1;
        if (ev.ch && ev.ch.length) {
          var pc = makeChoice(s, ev);
          if (pc && !pending) pending = pc;
          return;
        }
        runEvent(s, ev, entries);
      });

      /* 随机事件（最多 2 条） */
      if (!pending) {
        var cands = (root.EVENTS || []).filter(function (ev) {
          if (ev.must) return false;
          if (ev.once && s.used[ev.id]) return false;
          if (ev.age[0] > s.age || ev.age[1] < s.age) return false;
          return condOk(s, ev);
        });
        var count = 0, guard = 0;
        while (count < 2 && cands.length && guard++ < 60) {
          var total = 0;
          cands.forEach(function (c) { total += (c.w || 10); });
          var r = Math.random() * total, ev2 = null;
          for (var i = 0; i < cands.length; i++) {
            r -= (cands[i].w || 10);
            if (r <= 0) { ev2 = cands[i]; break; }
          }
          if (!ev2) ev2 = cands[cands.length - 1];
          cands = cands.filter(function (c) { return c !== ev2; });
          if (ev2.ch && ev2.ch.length) {
            var pc2 = makeChoice(s, ev2);
            if (pc2) { pending = pc2; if (ev2.once) s.used[ev2.id] = 1; break; }
            continue;
          }
          runEvent(s, ev2, entries);
          count++;
        }
      }
    }

    /* 年度结算 */
    economy(s);
    illnessCheck(s, entries);
    if (s.alive) cultYear(s, entries);
    aging(s);
    spouseCheck(s, entries);
    if (s.alive) deathCheck(s, entries);

    if (!entries.length) entries.push(mkEntry("这一年平淡如水，波澜不惊。"));
    s.haSum += Math.max(0, s.ha);
    return { entries: entries, pending: pending, dead: !s.alive };
  }

  /* ---------------- 天赋抽取 ---------------- */
  function rollTalents() {
    var weights = [
      { r: 1, w: 55 }, { r: 2, w: 27 }, { r: 3, w: 13 }, { r: 4, w: 5 }
    ];
    var byR = { 1: [], 2: [], 3: [], 4: [] };
    (root.TALENTS || []).forEach(function (t) { if (byR[t.rarity]) byR[t.rarity].push(t); });
    var all = root.TALENTS || [];
    var picked = [], usedIds = {}, guard = 0;
    while (picked.length < 10 && guard++ < 500) {
      var total = 0;
      weights.forEach(function (x) { total += x.w; });
      var r = Math.random() * total, rarity = 1;
      for (var i = 0; i < weights.length; i++) {
        r -= weights[i].w;
        if (r <= 0) { rarity = weights[i].r; break; }
      }
      var pool = byR[rarity].filter(function (t) { return !usedIds[t.id]; });
      if (!pool.length) pool = all.filter(function (t) { return !usedIds[t.id]; });
      if (!pool.length) break;
      var t = pick(pool);
      usedIds[t.id] = 1;
      picked.push(t);
    }
    return picked;
  }

  /* 供 UI 在“选择”后立即结算生死 */
  function checkDeath(s) {
    var entries = [];
    if (s && s.alive) deathCheck(s, entries);
    return entries;
  }

  /* ---------------- 模板码：16 位数字 编解码 ----------------
   * 位布局（共 43 bit，< 2^53 安全整数）：
   * [天赋索引 3×6bit][颜值4][智力4][体质4][家境4][模式1][校验8]
   * 天赋索引 63 表示空槽；输出补零至 16 位字符串。 */
  function encodeTemplate(talentIds, alloc, mode) {
    var T = root.TALENTS || [];
    alloc = alloc || {};
    var idx = (talentIds || []).slice(0, 3).map(function (id) {
      for (var i = 0; i < T.length; i++) if (T[i].id === id) return i;
      return 63;
    });
    while (idx.length < 3) idx.push(63);
    idx.sort(function (a, b) { return a - b; });
    var lo = clampInt(alloc.lo, 0, 10), inV = clampInt(alloc.in, 0, 10),
        ph = clampInt(alloc.ph, 0, 10), we = clampInt(alloc.we, 0, 10);
    var modeBit = mode === "raise" ? 1 : 0;
    var tBits = idx[0] * 4096 + idx[1] * 64 + idx[2];
    var v = tBits;
    v = v * 16 + lo; v = v * 16 + inV; v = v * 16 + ph; v = v * 16 + we;
    v = v * 2 + modeBit;
    var sum = (tBits * 7 + lo * 13 + inV * 17 + ph * 19 + we * 23 + modeBit * 29 + 101) % 256;
    v = v * 256 + sum;
    var str = String(v);
    while (str.length < 16) str = "0" + str;
    return str;
  }

  function decodeTemplate(str) {
    str = String(str || "").replace(/\s/g, "");
    if (!/^\d{16}$/.test(str)) return { err: "模板码需为 16 位数字" };
    var v = Number(str);
    if (!isFinite(v)) return { err: "模板码无效" };
    var sum = v % 256; v = Math.floor(v / 256);
    var modeBit = v % 2; v = Math.floor(v / 2);
    var we = v % 16; v = Math.floor(v / 16);
    var ph = v % 16; v = Math.floor(v / 16);
    var inV = v % 16; v = Math.floor(v / 16);
    var lo = v % 16; v = Math.floor(v / 16);
    var tBits = v;
    if (tBits >= 262144) return { err: "模板码无效" };
    var expect = (tBits * 7 + lo * 13 + inV * 17 + ph * 19 + we * 23 + modeBit * 29 + 101) % 256;
    if (expect !== sum) return { err: "模板码校验失败，请检查输入" };
    var T = root.TALENTS || [];
    var ids = [];
    [Math.floor(tBits / 4096) % 64, Math.floor(tBits / 64) % 64, tBits % 64].forEach(function (ix) {
      if (ix !== 63 && T[ix] && ids.indexOf(T[ix].id) < 0) ids.push(T[ix].id);
    });
    if (!ids.length) return { err: "模板码中无有效天赋" };
    return { talents: ids, alloc: { lo: lo, in: inV, ph: ph, we: we }, mode: modeBit ? "raise" : "casual" };
  }

  /* ---------------- 导出 ---------------- */
  root.LifeEngine = {
    createState: createState,
    runYear: runYear,
    resolveChoice: resolveChoice,
    rollTalents: rollTalents,
    fmtMoney: fmtMoney,
    checkDeath: checkDeath,
    encodeTemplate: encodeTemplate,
    decodeTemplate: decodeTemplate,
    TALENT_MAP: TALENT_MAP,
    JOBS: JOBS
  };

  /* 数据脚本使用的全局辅助 */
  root.chance = chance;
  root.ri = ri;
  root.fx = fx;
  root.hasT = hasT;
  root.pick = pick;
  root.randName = randName;

})(typeof window !== "undefined" ? window : globalThis);
