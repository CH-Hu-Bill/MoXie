/* =========================================================
 * 人生模拟器 · 事件库：成年（职场 / 家庭 / 生活）
 * ========================================================= */
(function (root) {
  "use strict";
  var EV = root.EVENTS || (root.EVENTS = []);
  function E(o) { EV.push(o); }

  /* ---------- 职场 ---------- */
  E({ id: "w_promote", age: [24, 55], w: 4, cls: "good",
    c: function (s) { return !!s.flags.working; },
    text: "你的努力被看到了，获得了晋升！", fx: { ha: 1 },
    run: function (s) {
      s.flags.salaryMult = (s.flags.salaryMult || 1) + 0.2;
      s.flags.promotions = (s.flags.promotions || 0) + 1;
    } });
  E({ id: "w_overtime", age: [23, 55], w: 6,
    c: function (s) { return !!s.flags.working; },
    text: "连续加班赶项目，你累得够呛，但拿到了加班费。",
    fx: function (s) { return { hp: -5, mo: Math.floor((s.flags.salary || 0) * 0.15) }; } });
  E({ id: "w_bonus", age: [24, 58], w: 5, cls: "good",
    c: function (s) { return !!s.flags.working; },
    text: "今年效益不错，你拿到了丰厚的年终奖。",
    fx: function (s) { return { mo: Math.floor((s.flags.salary || 0) * 0.5) }; } });
  E({ id: "w_conflict", age: [24, 55], w: 5,
    c: function (s) { return !!s.flags.working; },
    text: "你和同事闹了矛盾，气氛有些僵。", fx: { ha: -1 } });
  E({ id: "w_leader", age: [26, 55], w: 4, once: true,
    c: function (s) { return !!s.flags.working; },
    text: "领导很赏识你，把重要的项目交给了你。", fx: { ha: 1, in: 1 } });
  E({ id: "w_jobchange", age: [26, 50], w: 4, once: true,
    c: function (s) { return !!s.flags.working; },
    text: "有猎头向你抛来了橄榄枝。", ch: [
    { t: "跳槽", run: function (s) {
      if (chance(0.6)) { s.flags.salaryMult = (s.flags.salaryMult || 1) + 0.25; return "新东家待遇更好，薪资大涨。"; }
      s.flags.salaryMult = Math.max(0.8, (s.flags.salaryMult || 1) - 0.1); return "新公司不如预期，你有些后悔。";
    } },
    { t: "稳一稳，先不动", fx: { ha: 1 } }
  ] });
  E({ id: "w_layoff", age: [35, 48], w: 3, once: true, cls: "bad",
    c: function (s) { return !!s.flags.working && !s.flags.founder; },
    text: "公司裁员的风声传到了你的部门。", ch: [
    { t: "拼命表现保住岗位", run: function (s) {
      if (chance(0.5 + s.ph * 0.02)) { fx(s, { hp: -3 }); return "你熬过了裁员潮，保住了工作。"; }
      s.flags.working = false; s.flags.job = null; fx(s, { ha: -2, mo: Math.floor((s.flags.salary || 0) * 0.3) }); return "你还是被裁了，拿到了 N+1 赔偿。";
    } },
    { t: "拿赔偿主动走人", run: function (s) {
      fx(s, { mo: Math.floor((s.flags.salary || 0) * 0.5), ha: -1 });
      s.flags.working = false; s.flags.job = null; return "你拿着赔偿休息了一段时间。";
    } }
  ] });
  E({ id: "w_startup", age: [26, 45], w: 3, once: true,
    c: function (s) { return !!s.flags.working && s.mo >= 100000 && !s.flags.founder; },
    text: "你发现了一个不错的创业机会。", ch: [
    { t: "辞职创业", run: function (s) {
      var p = 0.3 + (hasT(s, "t_shangye") ? 0.25 : 0) + s.in * 0.015;
      if (chance(p)) {
        s.flags.founder = 1; s.flags.job = "founder"; s.flags.jobName = "创始人";
        s.flags.salary = 500000; s.flags.salaryMult = 1;
        fx(s, { ha: 2 }); return "创业成功！你的公司活了下来，你成了老板。";
      }
      fx(s, { mo: -100000, ha: -2 }); s.flags.working = false; s.flags.job = null;
      return "创业失败了，学费昂贵。";
    } },
    { t: "还是安稳打工吧", fx: { ha: 1 } }
  ] });
  E({ id: "w_ipo", age: [32, 60], w: 2, once: true,
    c: function (s) { return !!s.flags.founder && !s.flags.ipo; },
    text: "你的公司启动了上市计划。",
    run: function (s) {
      if (chance(0.5)) {
        s.flags.ipo = 1; fx(s, { mo: 5000000, ha: 3 });
        return "公司成功上市！你实现了财富自由。";
      }
      fx(s, { ha: -1 }); return "上市计划搁浅了，你决定稳扎稳打。";
    } });
  E({ id: "w_stock", age: [26, 60], w: 4, once: true,
    c: function (s) { return s.mo >= 50000; },
    text: "朋友向你推荐了一支「内部消息」股票。", ch: [
    { t: "重仓买入", run: function (s) {
      if (chance(0.45)) { var g = Math.floor(s.mo * 0.3); fx(s, { mo: g }); return "股票大涨，你赚了 " + g + " 元！"; }
      var l = Math.floor(s.mo * 0.4); fx(s, { mo: -l, ha: -2 }); return "股票暴跌，你亏了 " + l + " 元。";
    } },
    { t: "小额试试水", run: function (s) {
      if (chance(0.5)) { fx(s, { mo: 8000 }); return "小赚了一笔。"; }
      fx(s, { mo: -8000 }); return "小亏了一笔，就当交学费。";
    } },
    { t: "不碰", fx: { ha: 1 } }
  ] });
  E({ id: "w_house", age: [28, 45], w: 4, once: true,
    c: function (s) { return s.mo >= 300000 && !s.flags.house; },
    text: "你考虑买一套属于自己的房子。", ch: [
    { t: "买下它", fx: { mo: -300000, ha: 2 }, run: function (s) { s.flags.house = 1; } },
    { t: "先租房", fx: { ha: 0 } }
  ] });
  E({ id: "w_car", age: [25, 55], w: 4, once: true,
    c: function (s) { return s.mo >= 150000 && !s.flags.car; },
    text: "你考虑买一辆车。", ch: [
    { t: "买！", fx: { mo: -100000, ha: 2 }, run: function (s) { s.flags.car = 1; } },
    { t: "再等等", fx: { ha: 0 } }
  ] });
  E({ id: "w_lottery", age: [20, 60], w: 4, once: true, text: "路口的彩票店新开了张。", ch: [
    { t: "买一注试试", run: function (s) {
      var luck = (hasT(s, "t_xingyun") ? 1 : 0) + (hasT(s, "t_zhujue") ? 2 : 0);
      if (chance(0.02 + luck * 0.01)) {
        fx(s, { mo: 500000 }); s.flags.lottery = (s.flags.lottery || 0) + 500000;
        return "天呐，你中了 50 万大奖！";
      }
      fx(s, { mo: -10 }); return "没中，就当做了公益。";
    } },
    { t: "不买", fx: { ha: 0 } }
  ] });

  /* ---------- 婚恋家庭 ---------- */
  E({ id: "w_date", age: [22, 45], w: 6,
    c: function (s) { return !s.flags.married && !s.flags.dating && (!s.flags.dateCd || s.age - s.flags.dateCd >= 3); },
    text: "有人给你介绍了一个相亲对象。", ch: [
    { t: "去见一面", run: function (s) {
      s.flags.dateCd = s.age;
      var p = 0.35 + s.lo * 0.03 + s.we * 0.01
        + ((hasT(s, "t_qingshang") || hasT(s, "t_sheNiu")) ? 0.1 : 0)
        - (hasT(s, "t_shekon") ? 0.15 : 0);
      if (chance(p)) {
        s.flags.dating = 1; s.flags.spouseName = randName();
        return "你们一见如故，开始交往了。";
      }
      return "彼此没什么眼缘，不了了之。";
    } },
    { t: "暂时不想谈", fx: { ha: 0 }, run: function (s) { s.flags.dateCd = s.age; return ""; } }
  ] });
  E({ id: "w_marry", age: [25, 50], w: 6,
    c: function (s) { return !!s.flags.dating && !s.flags.married && (!s.flags.marryCd || s.age - s.flags.marryCd >= 2); },
    text: function (s) { return "你和" + (s.flags.spouseName || "恋人") + "感情稳定，谈婚论嫁。"; }, ch: [
    { t: "步入婚姻殿堂", fx: { mo: -80000, ha: 3 },
      run: function (s) { s.flags.married = 1; s.flags.everMarried = 1; s.flags.spouseAlive = 1; } },
    { t: "再等等", fx: { ha: -1 }, run: function (s) {
      s.flags.marryCd = s.age;
      if (chance(0.3)) { s.flags.dating = 0; return "等着等着，感情渐渐淡了。"; }
      return "对方表示理解，你们继续相处。";
    } }
  ] });
  E({ id: "w_child", age: [26, 45], w: 6,
    c: function (s) { return !!s.flags.married && (s.flags.children || 0) < 3 && (!s.flags.childCd || s.age - s.flags.childCd >= 3); },
    text: "你们在考虑要不要个孩子。", ch: [
    { t: "要一个", fx: { mo: -20000, ha: 2 },
      run: function (s) { s.flags.childCd = s.age; s.flags.children = (s.flags.children || 0) + 1; },
      log: "一声啼哭，你的孩子出生了！" },
    { t: "享受二人世界", fx: { ha: 1 }, run: function (s) { s.flags.childCd = s.age; return ""; } }
  ] });
  E({ id: "w_childstudy", age: [30, 55], w: 5,
    c: function (s) { return (s.flags.children || 0) >= 1; },
    text: "辅导孩子写作业，你血压飙升。", fx: { ha: -1 } });
  E({ id: "w_anniv", age: [27, 70], w: 4,
    c: function (s) { return !!s.flags.married; },
    text: "结婚纪念日，你们重温了当年的约会。", fx: { ha: 1 } });
  E({ id: "w_quarrel", age: [27, 65], w: 5,
    c: function (s) { return !!s.flags.married && (!s.flags.quarrelCd || s.age - s.flags.quarrelCd >= 3); },
    text: "你和爱人因为琐事大吵了一架。", ch: [
    { t: "坐下来好好沟通", fx: { ha: 1 }, run: function (s) { s.flags.quarrelCd = s.age; return ""; } },
    { t: "冷战", fx: { ha: -2 }, run: function (s) { s.flags.quarrelCd = s.age; return ""; } }
  ] });
  E({ id: "w_parentsick", age: [35, 60], w: 3, once: true, cls: "bad",
    text: "父母生病住院，你放下工作赶去照顾。", fx: { mo: -30000, ha: -2 } });
  E({ id: "w_parentdie", age: [45, 70], w: 2, once: true, cls: "bad",
    text: "父母走了。你第一次如此清晰地感受到，自己成了挡在死亡面前的人。", fx: { ha: -3 } });

  /* ---------- 生活 ---------- */
  E({ id: "w_travel", age: [25, 65], w: 4,
    c: function (s) { return s.mo >= 20000; },
    text: "你休了年假，出去旅行了一趟。", fx: { mo: -8000, ha: 2 },
    run: function (s) { s.flags.travels = (s.flags.travels || 0) + 1; } });
  E({ id: "w_pet", age: [24, 60], w: 3, once: true, c: function (s) { return !s.flags.pet; },
    text: "你遇到了一只流浪猫。", ch: [
    { t: "带回家养", fx: { mo: -2000, ha: 2 }, run: function (s) { s.flags.pet = 1; } },
    { t: "喂点火腿肠", fx: { ha: 1 } }
  ] });
  E({ id: "w_gym", age: [23, 55], w: 4, once: true, text: "你办了张健身卡，开始撸铁。", fx: { mo: -3000, ph: 1 } });
  E({ id: "w_serious", age: [40, 90], w: 1.5, cls: "bad",
    c: function (s) { return s.hp < 60; },
    text: "你大病了一场，在医院躺了半个月。", fx: { hp: -12, mo: -80000 },
    run: function (s) { s.flags.illness = (s.flags.illness || 0) + 1; s.flags.lastHit = "大病"; } });
  E({ id: "w_hobby", age: [25, 70], w: 4, once: true, text: "你培养了一个新爱好，生活多了几分乐趣。", fx: { ha: 1 } });
  E({ id: "w_readadult", age: [23, 80], w: 4, text: "你静下心来读完了一本好书。", fx: { in: 1 },
    run: function (s) { s.flags.books = (s.flags.books || 0) + 1; } });
  E({ id: "w_wedding", age: [24, 60], w: 4, text: "你参加了朋友的婚礼，随了份子钱。", fx: { mo: -1000, ha: 1 } });
  E({ id: "w_midlife", age: [45, 55], w: 3, once: true, text: "中年危机悄然而至，你开始怀疑人生的意义。", fx: { ha: -2 }, ch: [
    { t: "运动健身，挥洒汗水", fx: { ph: 1 } },
    { t: "寻求心理咨询", fx: { ha: 2, mo: -3000 } }
  ] });
  E({ id: "w_artist", age: [25, 60], w: 5,
    c: function (s) { return s.flags.job === "artist"; },
    text: "你的作品参加了一场重要展览。",
    run: function (s) {
      s.flags.fame = (s.flags.fame || 0) + 1;
      if (chance(0.3)) { s.flags.fame += 1; fx(s, { mo: 20000, ha: 1 }); return "作品被人收藏，你名声渐起。"; }
      fx(s, { ha: 1 }); return "业内反响不错，你继续打磨作品。";
    } });
  E({ id: "w_doctor", age: [25, 60], w: 4,
    c: function (s) { return s.flags.job === "doctor"; },
    text: "一台紧急手术推到了你面前。",
    run: function (s) {
      if (chance(0.7)) { fx(s, { ha: 1, in: 1 }); return "手术成功，家属握着你的手连连道谢。"; }
      fx(s, { ha: -2 }); return "手术失败了，你难过了很久。";
    } });
  E({ id: "w_coder", age: [23, 45], w: 4,
    c: function (s) { return s.flags.job === "programmer"; },
    text: "你修复了一个困扰团队一周的重大 Bug。", fx: { in: 1, ha: 1 } });
})(typeof window !== "undefined" ? window : globalThis);
