/* =========================================================
 * 人生模拟器 · 事件库：校园（13-22 岁）
 * 升学/中考/高考/毕业等关键节点由引擎统一处理
 * ========================================================= */
(function (root) {
  "use strict";
  var EV = root.EVENTS || (root.EVENTS = []);
  function E(o) { EV.push(o); }

  E({ id: "s_puberty", age: [13, 13], must: true, text: "你进入了青春期，个子猛蹿，声音也变了。", fx: { ph: 1, lo: 1 } });

  /* ---------- 初中 ---------- */
  E({ id: "s_hard", age: [13, 15], w: 8, text: "你挑灯夜战，刷题到深夜。", fx: { in: 1, hp: -1 } });
  E({ id: "s_letter", age: [13, 15], w: 5, once: true, text: "你收到了一封情书。", ch: [
    { t: "偷偷谈起了恋爱", fx: { ha: 2 }, run: function (s) { s.flags.puppy = 1; } },
    { t: "收心专心学习", fx: { in: 1 } }
  ] });
  E({ id: "s_rebel", age: [13, 15], w: 6, once: true, text: "叛逆期的你和父母大吵了一架。", fx: { ha: -1 } });
  E({ id: "s_basket", age: [13, 16], w: 6, once: true, text: "你迷上了篮球，放学就泡在球场。", fx: { ph: 1, ha: 1 } });
  E({ id: "s_rank", age: [13, 15], w: 5, once: true, c: function (s) { return s.in >= 8; },
    text: "期末考试你冲进了年级前十！", fx: { in: 1, ha: 1 }, cls: "good" });
  E({ id: "s_teacher", age: [13, 15], w: 6, text: "你被班主任叫去办公室谈话。", fx: { ha: -1 } });
  E({ id: "s_novel", age: [13, 15], w: 6, text: "上课偷看小说，书被老师没收了。", fx: { ha: -1 } });
  E({ id: "s_physics", age: [14, 15], w: 4, once: true, c: function (s) { return s.in >= 9; },
    text: "你在物理竞赛中崭露头角。", fx: { in: 2 }, cls: "good" });
  E({ id: "s_netgame", age: [13, 16], w: 5, once: true, text: "你接触到了网络游戏。", ch: [
    { t: "及时收手", fx: { in: 1 } },
    { t: "继续沉迷", fx: { ha: 2, in: -2 } }
  ] });

  /* ---------- 高中 ---------- */
  E({ id: "s_club", age: [16, 17], w: 5, once: true, c: function (s) { return s.flags.hs !== "voc"; },
    text: "你加入了学校的社团，认识了不少朋友。", fx: { ha: 1 } });
  E({ id: "s_allnight", age: [16, 18], w: 6, once: true, c: function (s) { return s.flags.hs !== "voc"; },
    text: "高考临近，学习氛围越来越紧张。", ch: [
    { t: "劳逸结合，早睡早起", fx: { ph: 1 } },
    { t: "拼命熬夜刷题", fx: { in: 2, ph: -1 } }
  ] });
  E({ id: "s_mock", age: [17, 18], w: 6, once: true, c: function (s) { return s.flags.hs !== "voc"; },
    text: function (s) { return s.in >= 8 ? "模拟考你发挥出色，名列前茅。" : "模拟考成绩不太理想，你有些焦虑。"; },
    fx: function (s) { return s.in >= 8 ? { in: 1, ha: 1 } : { ha: -1 }; } });
  E({ id: "s_break", age: [18, 18], w: 5, once: true, c: function (s) { return s.flags.hs !== "voc"; },
    text: "临考前你心态有些崩。", fx: { ha: -2 }, ch: [
    { t: "自我调整，深呼吸", fx: { ha: 1 } },
    { t: "向老师求助", fx: { ha: 1, in: 1 } }
  ] });
  E({ id: "s_confess", age: [18, 18], w: 5, once: true, text: "毕业在即，你对暗恋的人犹豫要不要表白。", ch: [
    { t: "勇敢表白", run: function (s) {
      if (s.lo >= 6 || chance(0.3)) { fx(s, { ha: 2 }); s.flags.love = 1; return "对方红着脸答应了，你们拥有了一个甜蜜的夏天。"; }
      fx(s, { ha: -1 }); return "对方婉拒了你，但你不留遗憾。";
    } },
    { t: "把心意藏在心底", fx: { ha: -1 } }
  ] });

  /* ---------- 大学 ---------- */
  E({ id: "s_union", age: [19, 20], w: 5, once: true, c: function (s) { return !!s.flags.uni; },
    text: "你加入了学生会，组织能力见长。", fx: { ha: 1 } });
  E({ id: "s_scholar", age: [19, 22], w: 4, once: true, c: function (s) { return !!s.flags.uni && s.in >= 8; },
    text: "你凭借优异的成绩拿到了奖学金！", fx: { mo: 8000, in: 1 }, cls: "good" });
  E({ id: "s_skip", age: [19, 22], w: 6, c: function (s) { return !!s.flags.uni; },
    text: "你翘课在宿舍打游戏。", fx: { ha: 1, in: -1 } });
  E({ id: "s_loveuni", age: [19, 22], w: 5, once: true, c: function (s) { return !!s.flags.uni && !s.flags.married; },
    text: "有人向你表白了。", ch: [
    { t: "接受，开始一段恋爱", fx: { ha: 2 }, run: function (s) { s.flags.lover = 1; } },
    { t: "婉拒，专注学业", fx: { in: 1 } }
  ] });
  E({ id: "s_acm", age: [19, 22], w: 4, once: true, c: function (s) { return !!s.flags.uni && s.in >= 9; },
    text: "你参加程序设计大赛并拿到了名次！", fx: { in: 2 }, cls: "good" });
  E({ id: "s_parttime", age: [19, 22], w: 5, once: true, c: function (s) { return !!s.flags.uni; },
    text: "你做了半年家教兼职，赚到了第一桶金。", fx: { mo: 6000 } });
  E({ id: "s_paper", age: [20, 22], w: 4, once: true, c: function (s) { return !!s.flags.uni && s.in >= 9; },
    text: "你的论文被学术期刊收录了！", fx: { in: 2 }, cls: "good" });
  E({ id: "s_talk", age: [19, 22], w: 6, c: function (s) { return !!s.flags.uni; },
    text: "宿舍夜谈，你们聊理想聊到凌晨。", fx: { ha: 1 } });
  E({ id: "s_lib", age: [19, 22], w: 5, once: true, c: function (s) { return !!s.flags.uni; },
    text: "你在图书馆泡了一整年，读了很多书。", fx: { in: 1 },
    run: function (s) { s.flags.books = (s.flags.books || 0) + 1; } });
})(typeof window !== "undefined" ? window : globalThis);
