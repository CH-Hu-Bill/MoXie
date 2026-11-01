/* =========================================================
 * 人生模拟器 · 成就数据
 * cond(s) 在生命结束时检查
 * ========================================================= */
(function (root) {
  "use strict";

  function A(id, name, desc, cond) {
    return { id: id, name: name, desc: desc, cond: cond };
  }

  root.ACHIEVEMENTS = [
    A("a_jintiaopeng", "含着金汤匙", "出生时家境优渥（家境≥9）", function (s) { return s.startWe >= 9; }),
    A("a_qingbei", "学霸本霸", "考上顶尖名校", function (s) { return !!s.flags.uniTop; }),
    A("a_zhuangyuan", "高考状元", "高考总分 670 以上", function (s) { return (s.flags.gkScore || 0) >= 670; }),
    A("a_shuoshuo", "硕士毕业", "读完硕士研究生", function (s) { return !!s.flags.gradDone; }),
    A("a_fugao", "富豪", "去世时存款千万", function (s) { return s.mo >= 10000000; }),
    A("a_yiwan", "亿万富翁", "去世时存款过亿", function (s) { return s.mo >= 100000000; }),
    A("a_baisui", "百岁老人", "活到 100 岁", function (s) { return s.age >= 100; }),
    A("a_shouxing", "寿比南山", "活到 90 岁", function (s) { return s.age >= 90; }),
    A("a_jiehun", "告别单身", "结过一次婚", function (s) { return !!s.flags.everMarried; }),
    A("a_baitou", "白头偕老", "与爱人相伴到老", function (s) { return !!s.flags.married && !!s.flags.spouseAlive; }),
    A("a_ersun", "儿孙满堂", "养育了 3 个以上孩子", function (s) { return (s.flags.children || 0) >= 3; }),
    A("a_yingnian", "英年早逝", "30 岁前离开人世", function (s) { return s.age <= 30; }),
    A("a_juanwang", "卷王", "当程序员并获得 3 次以上晋升", function (s) { return s.flags.job === "programmer" && (s.flags.promotions || 0) >= 3; }),
    A("a_tangping", "躺平大师", "去世时快乐值爆表（≥12）", function (s) { return s.ha >= 12; }),
    A("a_yueguang", "月光传说", "去世时身无分文", function (s) { return s.mo <= 0; }),
    A("a_caipiao", "彩票之王", "累计中奖超过百万", function (s) { return (s.flags.lottery || 0) >= 1000000; }),
    A("a_shangshi", "创业大亨", "创办的公司成功上市", function (s) { return !!s.flags.ipo; }),
    A("a_quanneng", "全能选手", "去世时颜值智力体质快乐都≥8", function (s) { return s.lo >= 8 && s.in >= 8 && s.ph >= 8 && s.ha >= 8; }),
    A("a_dushu", "读万卷书", "累计读书 8 本以上", function (s) { return (s.flags.books || 0) >= 8; }),
    A("a_huanqiu", "环游世界", "累计旅行 5 次以上", function (s) { return (s.flags.travels || 0) >= 5; }),
    A("a_rumen", "修仙入门", "踏上修仙之路", function (s) { return (s.flags.cult || 0) >= 1; }),
    A("a_jindan", "金丹大道", "修炼至金丹期", function (s) { return (s.flags.cult || 0) >= 3; }),
    A("a_feisheng", "飞升成仙", "渡劫成功，飞升成仙", function (s) { return !!s.flags.ascend; }),
    A("a_chongsheng", "逆转时空", "使用时空旅者的复活机会", function (s) { return !!s.flags.revived; }),
    A("a_gongwu", "人民公仆", "担任公务员超过 20 年", function (s) { return s.flags.job === "civil" && (s.flags.jobYears || 0) >= 20; }),
    A("a_mingyi", "仁心医者", "作为医生行医到 50 岁", function (s) { return s.flags.job === "doctor" && s.age >= 50; }),
    A("a_yishujia", "著名艺术家", "艺术道路功成名就（声望≥3）", function (s) { return (s.flags.fame || 0) >= 3; }),
    A("a_xiaodaozuihou", "笑到最后", "80 岁以上且快乐≥10", function (s) { return s.age >= 80 && s.ha >= 10; }),
    A("a_bingyangzi", "药罐子", "累计生病 5 次以上", function (s) { return (s.flags.illness || 0) >= 5; }),
    A("a_yuyue", "愉悦一生", "平均快乐值保持在 9 以上", function (s) { return s.age > 0 && (s.haSum / s.age) >= 9; })
  ];
})(typeof window !== "undefined" ? window : globalThis);
