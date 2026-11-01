/* =========================================================
 * 人生模拟器 · 事件库：童年（0-12 岁）
 * 事件字段：
 *   id 唯一 / age [min,max] / must 必定触发 / once 仅一次
 *   c(s) 条件 / text 文案(可为函数) / fx 效果(可为函数)
 *   ch [{t 选项文案, c 条件, fx 效果, run 自定义逻辑, log 追加文案}]
 *   run(s) 自定义逻辑 / w 权重 / cls 日志样式
 * ========================================================= */
(function (root) {
  "use strict";
  var EV = root.EVENTS || (root.EVENTS = []);
  function E(o) { EV.push(o); }

  /* ---------- 必定发生的童年节点 ---------- */
  E({ id: "c_birth", age: [0, 0], must: true, cls: "milestone", fx: { ha: 1 },
    text: function (s) {
      if (s.we >= 9) return "你出生了！这是个富得流油的家庭，你赢在了起跑线上。";
      if (s.we >= 6) return "你出生了！这是个小康之家，生活温馨而富足。";
      if (s.we >= 3) return "你出生了！这是个普通的家庭，日子平淡而踏实。";
      return "你出生了！家境贫寒，但父母把最好的都给了你。";
    } });
  E({ id: "c_name", age: [0, 0], must: true, text: "父母给你取了一个好名字，愿你一生平安顺遂。", fx: { ha: 1 } });
  E({ id: "c_walk", age: [1, 1], must: true, text: "你摇摇晃晃地学会了走路。", fx: { ph: 1 } });
  E({ id: "c_word", age: [1, 1], must: true, text: "你喊出了第一声「妈妈」，全家乐开了花。", fx: { in: 1 } });
  E({ id: "c_kid", age: [3, 3], must: true, text: "你上幼儿园了。", ch: [
    { t: "天天哭着要回家", fx: { ha: -1 } },
    { t: "很快交到小伙伴", fx: { ha: 1 }, run: function (s) { s.flags.friends = (s.flags.friends || 0) + 1; } }
  ] });
  E({ id: "c_pioneer", age: [7, 7], must: true, text: "你戴上了鲜艳的红领巾。", fx: { ha: 1 } });
  E({ id: "c_pgrad", age: [12, 12], must: true, cls: "milestone", text: "你小学毕业了，即将迈入中学的大门。" });

  /* ---------- 0-6 岁随机事件 ---------- */
  E({ id: "c_crawl", age: [0, 1], w: 5, once: true, text: "你在宝宝爬行比赛中拿了第一名！", fx: { ph: 1 } });
  E({ id: "c_candy", age: [1, 3], w: 8, text: "妈妈给你买了甜甜的糖果。", fx: { ha: 1 } });
  E({ id: "c_fall", age: [1, 4], w: 8, text: "你摔了一跤，哇哇大哭。", fx: { hp: -2 } });
  E({ id: "c_tv", age: [2, 6], w: 8, text: "你整天守着电视机看动画片。", fx: { ha: 1 } });
  E({ id: "c_icecream", age: [2, 8], w: 7, text: "你偷吃冰棍拉肚子了。", fx: { hp: -3 } });
  E({ id: "c_puzzle", age: [3, 7], w: 6, once: true, text: "你在拼图大赛里拿了第一名。", fx: { in: 1 } });
  E({ id: "c_fightkid", age: [4, 9], w: 6, text: "你和邻居小孩打了一架。", fx: { ph: 1, ha: -1 } });
  E({ id: "c_bike", age: [5, 10], w: 6, once: true, text: "爸爸教你学会了骑自行车。", fx: { ph: 1 } });
  E({ id: "c_redpack", age: [3, 12], w: 8, text: "过年收到的压岁钱被妈妈「帮你保管」了。",
    fx: function (s) { return { mo: 200 + s.we * 300 }; } });
  E({ id: "c_show", age: [4, 6], w: 6, once: true, text: "幼儿园举办文艺汇演，你登台表演。", ch: [
    { t: "唱一首儿歌", fx: { ha: 1 } },
    { t: "跳一支舞", fx: { lo: 1 } },
    { t: "朗诵诗歌", fx: { in: 1 } }
  ] });
  E({ id: "c_cold", age: [2, 10], w: 7,
    c: function (s) { return !s.flags.coldCd || s.age - s.flags.coldCd >= 3; },
    text: "你感冒了，要去医院打针。", fx: { hp: -2 }, ch: [
    { t: "哇哇大哭", fx: { ha: -1 }, run: function (s) { s.flags.coldCd = s.age; return ""; } },
    { t: "咬牙忍住", fx: { ph: 1 }, run: function (s) { s.flags.coldCd = s.age; return ""; } }
  ] });
  E({ id: "c_toy", age: [4, 10], w: 6, text: "你攒了好久的零花钱，买到了心仪的玩具。", fx: { mo: -200, ha: 2 } });
  E({ id: "c_zoo", age: [3, 10], w: 5, once: true, c: function (s) { return s.we >= 4; },
    text: "全家带你去动物园玩了一整天。", fx: { ha: 2 } });
  E({ id: "c_arcade", age: [6, 12], w: 6, once: true, text: "你接触到了电子游戏。", ch: [
    { t: "适度娱乐", fx: { ha: 1 } },
    { t: "沉迷其中", fx: { ha: 2, in: -1 } }
  ] });

  /* ---------- 7-12 岁小学随机事件 ---------- */
  E({ id: "c_monitor", age: [7, 12], w: 5, once: true, c: function (s) { return s.in >= 6; },
    text: "你被老师选为班干部。", fx: { ha: 1 } });
  E({ id: "c_math", age: [7, 12], w: 8,
    text: function (s) { return s.in >= 7 ? "数学测验你拿了高分，老师当众表扬了你。" : "数学测验成绩不理想，你被家长念叨了一顿。"; },
    fx: function (s) { return s.in >= 7 ? { in: 1, ha: 1 } : { ha: -1 }; } });
  E({ id: "c_sleepclass", age: [7, 12], w: 7, text: "上课打瞌睡，你被罚站了一节课。", fx: { ha: -1 } });
  E({ id: "c_olympiad", age: [9, 12], w: 5, once: true, c: function (s) { return s.in >= 8; },
    text: "你代表学校参加奥数竞赛并获了奖！", fx: { in: 2, ha: 1 }, cls: "good" });
  E({ id: "c_bully", age: [8, 13], w: 5, once: true, text: "你遇到了校园霸凌。", ch: [
    { t: "奋起反抗", run: function (s) { if (s.ph >= 5) { fx(s, { ph: 1, ha: 1 }); return "你勇敢地反击，对方再也不敢欺负你了。"; } fx(s, { hp: -5, ha: -1 }); return "你打不过对方，吃了点亏。"; } },
    { t: "告诉老师", fx: { ha: 1 }, log: "老师严肃处理了这件事。" },
    { t: "忍气吞声", fx: { ha: -2 } }
  ] });
  E({ id: "c_sports", age: [8, 12], w: 6, once: true,
    text: function (s) { return s.ph >= 7 ? "校运会上你夺得了短跑冠军！" : "校运会上你参加了比赛，重在参与。"; },
    fx: function (s) { return s.ph >= 7 ? { ph: 1, ha: 1 } : { ha: 1 }; } });
  E({ id: "c_swim", age: [7, 12], w: 5, once: true, text: "你学会了游泳。", fx: { ph: 1 } });
  E({ id: "c_harry", age: [8, 12], w: 5, once: true, text: "你迷上了《哈利·波特》，熬夜也要看完。",
    fx: { in: 1, ha: 1 }, run: function (s) { s.flags.books = (s.flags.books || 0) + 1; } });
  E({ id: "c_essay", age: [9, 12], w: 5, once: true, c: function (s) { return s.in >= 7; },
    text: "你的作文登上了校报！", fx: { in: 1, ha: 1 }, cls: "good" });
  E({ id: "c_crush", age: [10, 13], w: 5, once: true, text: "你偷偷喜欢上了同桌。", fx: { ha: 1 } });
  E({ id: "c_snack", age: [7, 12], w: 7, text: "你迷上了校门口五毛钱的辣条。", fx: { ph: -1, ha: 1 } });
  E({ id: "c_summer", age: [8, 12], w: 5, once: true, c: function (s) { return s.we >= 6; },
    text: "暑假全家出去旅游了一趟。", fx: { mo: -5000, ha: 2 },
    run: function (s) { s.flags.travels = (s.flags.travels || 0) + 1; } });
  E({ id: "c_piano", age: [7, 12], w: 5, once: true, c: function (s) { return s.we >= 6; },
    text: "家里送你去学了钢琴。", fx: { in: 1, mo: -3000 } });
  E({ id: "c_netbar", age: [11, 14], w: 5, once: true, text: "同学带你去了网吧。", ch: [
    { t: "查资料学习", fx: { in: 1 } },
    { t: "偷偷打游戏", fx: { ha: 2, in: -1 } }
  ] });
  E({ id: "c_homework", age: [7, 12], w: 7, text: "你忘带作业，被老师批评了。", fx: { ha: -1 } });
  E({ id: "c_bestfriend", age: [7, 12], w: 6, once: true, text: "你结识了一个无话不谈的好朋友。",
    fx: { ha: 1 }, run: function (s) { s.flags.friends = (s.flags.friends || 0) + 1; } });
})(typeof window !== "undefined" ? window : globalThis);
