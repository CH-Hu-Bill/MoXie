/* =========================================================
 * 人生模拟器 · 事件库：老年（60 岁+）
 * ========================================================= */
(function (root) {
  "use strict";
  var EV = root.EVENTS || (root.EVENTS = []);
  function E(o) { EV.push(o); }

  E({ id: "o_square", age: [60, 90], w: 5, text: "你加入了社区广场舞队伍，跳得越来越带劲。", fx: { ph: 1, ha: 1 } });
  E({ id: "o_chess", age: [62, 95], w: 4, text: "你在公园和老友下棋，棋艺渐长。", fx: { in: 1, ha: 1 } });
  E({ id: "o_grand", age: [62, 90], w: 4, once: true, c: function (s) { return (s.flags.children || 0) >= 1; },
    text: "你抱上了孙子，乐得合不拢嘴。", fx: { ha: 2 }, cls: "good" });
  E({ id: "o_checkup", age: [60, 95], w: 4, text: "你去做了一次全面体检。", fx: { mo: -2000, hp: 3 } });
  E({ id: "o_chronic", age: [65, 95], w: 3, once: true, cls: "bad",
    text: "你被确诊了慢性病，需要长期调养。", fx: { hp: -8 },
    run: function (s) { s.flags.chronic = 1; s.flags.illness = (s.flags.illness || 0) + 1; } });
  E({ id: "o_fall", age: [75, 100], w: 3, cls: "bad", text: "你不小心摔了一跤，伤了筋骨。", fx: { hp: -12 } });
  E({ id: "o_reunion", age: [60, 100], w: 4, c: function (s) { return (s.flags.children || 0) >= 1; },
    text: "春节儿孙回家团聚，家里热闹非凡。", fx: { ha: 2 } });
  E({ id: "o_lonely", age: [65, 100], w: 4, c: function (s) { return !s.flags.married && !(s.flags.children > 0); },
    text: "一个人生活的你，偶尔感到孤独。", fx: { ha: -2 } });
  E({ id: "o_memory", age: [65, 100], w: 5, text: "你翻着老照片，回忆往昔岁月。", fx: { ha: 1 } });
  E({ id: "o_will", age: [70, 95], w: 2, once: true, text: "你平静地立好了遗嘱，把一切都安排妥当。" });
  E({ id: "o_golden", age: [68, 90], w: 2, once: true, c: function (s) { return !!s.flags.married; },
    text: "你和爱人迎来了金婚纪念，半个世纪的相守。", fx: { ha: 3 }, cls: "special" });
  E({ id: "o_tour", age: [62, 85], w: 3, c: function (s) { return s.mo >= 20000; },
    text: "你报了老年旅行团，出去散了散心。", fx: { mo: -5000, ha: 2 },
    run: function (s) { s.flags.travels = (s.flags.travels || 0) + 1; } });
  E({ id: "o_callig", age: [62, 95], w: 4, text: "你开始练习书法，修身养性。", fx: { ha: 1, in: 1 } });
  E({ id: "o_hospital", age: [55, 100], w: 3, c: function (s) { return s.hp < 40; }, cls: "bad",
    text: "你住院接受了一段时间的治疗。", fx: { mo: -50000, hp: 12 },
    run: function (s) { s.flags.illness = (s.flags.illness || 0) + 1; } });
})(typeof window !== "undefined" ? window : globalThis);
