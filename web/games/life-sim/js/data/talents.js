/* =========================================================
 * 人生模拟器 · 天赋数据
 * rarity: 1=普通 2=稀有 3=史诗 4=传说
 * hooks.init(s) 出生时执行；hooks.year(s) 每年执行
 * 运行时可用的引擎辅助全局函数：
 *   chance(p) 概率判定 / ri(a,b) 随机整数 / fx(s, obj) 施加属性变化
 *   hasT(s, id) 是否拥有某天賦
 * ========================================================= */
(function (root) {
  "use strict";

  function T(id, name, desc, rarity, hooks) {
    return {
      id: id, name: name, desc: desc, rarity: rarity,
      init: hooks && hooks.init,
      year: hooks && hooks.year
    };
  }

  root.TALENTS = [
    /* ---------------- 普通 ---------------- */
    T("t_le", "乐天派", "天生乐观，更容易感到快乐", 1, {
      year: function (s) { if (chance(0.4)) fx(s, { ha: 1 }); }
    }),
    T("t_chihuo", "吃货", "人生在世，吃字当头", 1, {
      year: function (s) {
        fx(s, { ha: 1, mo: -600 });
        if (s.age % 8 === 0) fx(s, { ph: 1 });
      }
    }),
    T("t_zhai", "宅", "家里蹲才是王道", 1, {
      year: function (s) {
        if (chance(0.35)) fx(s, { ha: 1 });
        if (s.age % 6 === 0) fx(s, { ph: -1 });
      }
    }),
    T("t_yundong", "运动控", "生命在于运动", 1, {
      year: function (s) { if (s.age % 3 === 0) fx(s, { ph: 1 }); }
    }),
    T("t_shuchong", "书虫", "读书破万卷，下笔如有神", 1, {
      year: function (s) { if (s.age % 3 === 0) fx(s, { in: 1 }); }
    }),
    T("t_jiejian", "节俭", "省到就是赚到", 1, {
      year: function (s) { fx(s, { mo: 3000 }); }
    }),
    T("t_yueguang", "月光族", "今朝有酒今朝醉", 1, {
      year: function (s) { fx(s, { mo: -3000, ha: 1 }); }
    }),
    T("t_haoren", "好人缘", "朋友多了路好走", 1, {
      year: function (s) { if (chance(0.25)) fx(s, { mo: 2000 }); }
    }),
    T("t_aixiao", "爱笑", "爱笑的人运气不会太差", 1, {
      year: function (s) { if (chance(0.5)) fx(s, { ha: 1 }); }
    }),
    T("t_bolixin", "玻璃心", "容易多愁善感", 1, {
      year: function (s) { if (chance(0.35)) fx(s, { ha: -1 }); }
    }),
    T("t_tiewei", "铁胃", "吃嘛嘛香，不容易生病", 1),
    T("t_guomin", "过敏体质", "换季就容易生病", 1),
    T("t_jinshi", "近视", "看不清的世界", 1, {
      init: function (s) { fx(s, { lo: -1 }); }
    }),
    T("t_maiba", "麦霸", "KTV 永远的主角", 1, {
      year: function (s) { if (chance(0.3)) fx(s, { ha: 1 }); }
    }),
    T("t_youxi", "游戏迷", "电子游戏是第九艺术", 1, {
      year: function (s) {
        if (chance(0.4)) fx(s, { ha: 1 });
        if (chance(0.08)) fx(s, { in: -1 });
      }
    }),
    T("t_maonu", "猫奴", "猫咪就是全世界", 1, {
      year: function (s) { fx(s, { ha: 1, mo: -1000 }); }
    }),
    T("t_shekon", "社恐", "人多的地方会紧张，恋爱概率降低", 1, {
      year: function (s) { if (chance(0.3)) fx(s, { ha: 1 }); }
    }),
    T("t_sheNiu", "社牛", "自来熟，人脉王，升职恋爱更顺利", 1),
    T("t_zaoqi", "早起鸟", "清晨的空气最清新", 1, {
      year: function (s) { if (chance(0.25)) fx(s, { ph: 1 }); }
    }),
    T("t_yemao", "夜猫子", "深夜才是灵感高峰", 1, {
      year: function (s) {
        if (chance(0.25)) fx(s, { in: 1 });
        if (chance(0.1)) fx(s, { hp: -2 });
      }
    }),

    /* ---------------- 稀有 ---------------- */
    T("t_xueba", "学霸", "考试如有神助（各类考试 +30 分）", 2, {
      year: function (s) { if (chance(0.2)) fx(s, { in: 1 }); }
    }),
    T("t_yundongjian", "运动健将", "从小就是体育尖子", 2, {
      init: function (s) { fx(s, { ph: 3 }); }
    }),
    T("t_tianshengli", "天生丽质", "从小好看到大", 2, {
      init: function (s) { fx(s, { lo: 3 }); }
    }),
    T("t_congming", "聪明绝顶", "学什么都快", 2, {
      init: function (s) { fx(s, { in: 3 }); }
    }),
    T("t_xiaokang", "小康之家", "家里条件还不错", 2, {
      init: function (s) { fx(s, { mo: 80000 }); }
    }),
    T("t_xingyun", "幸运儿", "运气总是不错", 2),
    T("t_jinli", "锦鲤", "转发这条锦鲤，好事自然来", 2, {
      year: function (s) {
        fx(s, { mo: 6000 });
        if (chance(0.05)) fx(s, { mo: 20000 });
      }
    }),
    T("t_kangya", "抗压强", "心理素质过硬，快乐不易下降", 2),
    T("t_qingshang", "情商高", "会说话，更会做人", 2),
    T("t_koucai", "口才好", "嘴皮子利索，薪资 +10%", 2),
    T("t_licai", "理财高手", "钱生钱的学问（每年存款 +5%）", 2, {
      year: function (s) { if (s.mo > 0) fx(s, { mo: Math.floor(s.mo * 0.05) }); }
    }),
    T("t_fuye", "副业达人", "八小时之外也有收入", 2, {
      year: function (s) { if (s.age >= 22) fx(s, { mo: 12000 }); }
    }),
    T("t_yangsheng", "养生达人", "保温杯里泡枸杞（30 岁后每年健康 +2）", 2, {
      year: function (s) {
        if (s.age >= 30) fx(s, { hp: 2 });
        fx(s, { mo: -3000 });
      }
    }),
    T("t_tieren", "铁人", "几乎从不生病", 2),
    T("t_yishu", "艺术细胞", "对美有天然的感知，可走艺术道路", 2, {
      year: function (s) { if (chance(0.2)) fx(s, { ha: 1 }); }
    }),
    T("t_biancheng", "编程天赋", "代码写起来如有神助（程序员薪资 +30%）", 2, {
      year: function (s) {
        if (s.flags.job === "programmer" && s.age % 2 === 0) fx(s, { in: 1 });
      }
    }),
    T("t_shangye", "商业头脑", "天生的生意人，创业成功率大幅提升", 2),
    T("t_jianchi", "坚持不懈", "认准的事绝不放弃（修仙突破率 +5%）", 2),

    /* ---------------- 史诗 ---------------- */
    T("t_fuerdai", "富二代", "出生就在罗马", 3, {
      init: function (s) { s.we = 10; fx(s, { mo: 800000 }); }
    }),
    T("t_tiancai", "天才", "智商超群（各类考试额外 +50 分）", 3, {
      init: function (s) { fx(s, { in: 5 }); }
    }),
    T("t_meiyan", "盛世美颜", "颜值天花板", 3, {
      init: function (s) { fx(s, { lo: 5 }); }
    }),
    T("t_shenli", "天生神力", "体格异于常人", 3, {
      init: function (s) { fx(s, { ph: 5 }); }
    }),
    T("t_zhujue", "主角光环", "仿佛拿了主角剧本，好运常伴", 3),
    T("t_caipiao", "彩票达人", "买彩票总有惊喜", 3, {
      year: function (s) {
        if (chance(0.03)) {
          fx(s, { mo: 1000000 });
          s.flags.lottery = (s.flags.lottery || 0) + 1000000;
        } else if (chance(0.1)) {
          fx(s, { mo: 5000 });
        }
      }
    }),
    T("t_baozu", "包租公", "名下有房，躺着收租", 3, {
      year: function (s) { fx(s, { mo: 60000 }); }
    }),
    T("t_changsheng", "长生", "衰老得比常人慢得多", 3),
    T("t_bulao", "不老", "岁月不在你脸上留下痕迹", 3),

    /* ---------------- 传说 ---------------- */
    T("t_tianming", "天命之人", "冥冥之中自有天意，你或许能踏上修仙之路", 4),
    T("t_shikong", "时空旅者", "死亡时有一次逆转时空的机会", 4),
    T("t_rensheng", "人生赢家", "赢在起跑线，全属性 +2", 4, {
      init: function (s) { fx(s, { lo: 2, in: 2, ph: 2, ha: 2 }); }
    }),
    T("t_caishen", "财神附体", "财运亨通（起步 200 万，每年收益 8%）", 4, {
      init: function (s) { fx(s, { mo: 2000000 }); },
      year: function (s) { if (s.mo > 0) fx(s, { mo: Math.floor(s.mo * 0.08) }); }
    })
  ];
})(typeof window !== "undefined" ? window : globalThis);
