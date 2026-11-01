/* =========================================================
 * 人生模拟器 · 事件库：特殊事件（修仙 / 奇遇 / 意外）
 * ========================================================= */
(function (root) {
  "use strict";
  var EV = root.EVENTS || (root.EVENTS = []);
  function E(o) { EV.push(o); }

  /* ---------- 修仙线 ---------- */
  E({ id: "x_cult", age: [25, 45], w: 40, once: true, cls: "special",
    c: function (s) { return hasT(s, "t_tianming") && !s.flags.cult; },
    text: "你在古玩市场淘到一本残破的《无名道经》，深夜翻阅时竟有灵气入体——你踏上了修仙之路！",
    fx: { hp: 10 },
    run: function (s) { s.flags.cult = 1; } });
  E({ id: "x_cult_train", age: [18, 500], w: 6, c: function (s) { return (s.flags.cult || 0) >= 1; },
    text: "你闭关修炼，气息愈发绵长。", fx: { in: 1, hp: 2 } });
  E({ id: "x_cult_secret", age: [18, 500], w: 5, c: function (s) { return (s.flags.cult || 0) >= 1; },
    text: "你探寻了一处上古秘境。",
    run: function (s) {
      if (chance(0.6)) { var m = ri(20000, 100000); fx(s, { mo: m }); return "你在秘境中寻得宝物，卖了 " + m + " 元。"; }
      fx(s, { hp: -10 }); return "秘境凶险，你受了点伤。";
    } });
  E({ id: "x_cult_demon", age: [18, 500], w: 4, c: function (s) { return (s.flags.cult || 0) >= 2; },
    text: "一头妖兽拦住了你的去路。",
    run: function (s) {
      if (chance(0.5 + s.ph * 0.02)) { fx(s, { ph: 1 }); return "你奋力击退了妖兽，实力更进一步。"; }
      fx(s, { hp: -15 }); return "你被妖兽所伤，狼狈逃脱。";
    } });
  E({ id: "x_cult_xinmo", age: [18, 500], w: 3, once: true, c: function (s) { return (s.flags.cult || 0) >= 2; },
    text: "心魔来袭，你在走火入魔的边缘。", ch: [
    { t: "坚守本心", run: function (s) {
      if (chance(0.5 + s.in * 0.03)) { fx(s, { ha: 2, in: 1 }); return "你战胜了心魔，道心通明。"; }
      s.flags.cult = Math.max(0, s.flags.cult - 1); fx(s, { hp: -10 }); return "你心境受损，修为倒退。";
    } },
    { t: "放弃修炼", run: function (s) { s.flags.cult = 0; fx(s, { ha: -2 }); return "你散尽修为，回归凡尘。"; } }
  ] });

  /* ---------- 奇遇 ---------- */
  E({ id: "x_ufo", age: [6, 80], w: 1, once: true, text: "你目击了疑似 UFO 的不明飞行物！", fx: { ha: 1 } });
  E({ id: "x_meteor", age: [6, 80], w: 2, once: true, text: "你看到了流星雨，许下了一个愿望。", fx: { ha: 1 } });
  E({ id: "x_findmoney", age: [10, 70], w: 3, text: "你在路上捡到了钱。", fx: { mo: 500 } });
  E({ id: "x_phone", age: [16, 70], w: 3, text: "你的手机摔坏了，只好换新的。", fx: { mo: -2000 } });
  E({ id: "x_idol", age: [14, 50], w: 2, once: true, text: "你在街头偶遇了自己的偶像！", fx: { ha: 2 } });
  E({ id: "x_rainbow", age: [5, 90], w: 3, text: "雨后天边挂起了一道彩虹。", fx: { ha: 1 } });
  E({ id: "x_thief", age: [20, 80], w: 2, once: true, cls: "bad", text: "家里进了贼，损失不小。", fx: { mo: -5000, ha: -1 } });
  E({ id: "x_award", age: [18, 70], w: 2, text: "商场抽奖，你中了一等奖。", fx: { mo: 2000 } });
  E({ id: "x_oldfriend", age: [30, 80], w: 3, text: "多年未见的老友突然来访，你们彻夜长谈。", fx: { ha: 1 } });
  E({ id: "x_dream", age: [10, 90], w: 3, text: "你做了一个光怪陆离的梦，醒来若有所失。" });
  E({ id: "x_genius", age: [3, 12], w: 2, once: true, c: function (s) { return s.in >= 8; },
    text: "邻里都说你是神童，对你寄予厚望。", fx: { in: 1 } });
  E({ id: "x_marathon", age: [20, 60], w: 2, once: true, c: function (s) { return s.ph >= 8; },
    text: "你完成了人生第一个全程马拉松！", fx: { ph: 1, ha: 2 }, cls: "good" });

  /* ---------- 意外 ---------- */
  E({ id: "x_accident", age: [18, 90], w: 0.5, cls: "bad",
    text: "你遭遇了一场严重的车祸。", fx: { hp: -35 },
    run: function (s) { s.flags.lastHit = "车祸重伤"; } });
})(typeof window !== "undefined" ? window : globalThis);
