# 单文件网页游戏来源

- 上游项目：https://github.com/wangzifan396-wzf/mini-browser-games
- 上游 commit：`02106d2f52963fdaace080280b9f065f885d77b0`
- 许可证：MIT（见同目录 `LICENSE`）
- 作者：wangzifan396-wzf

本目录只收录经过筛选的少量单文件游戏，不一次性打包全部 106 款：

- `garden-front.html` — 芽阵前线（塔防）
- `snake-game.html` — 霓虹贪吃蛇
- `gem-garden.html` — 晶糖花园
- `pixel-clues.html` — 像素线索
- `sudoku-studio.html` — 数独工坊

这些文件是上游单文件 HTML 原样使用的，未改写游戏内部逻辑；ListenWrite 只负责
外层游戏列表、班级每日时长和 iframe 包装。

单文件游戏的手机适配由各游戏自身决定；已抽查页面没有外部 CDN 依赖（仅 SVG
命名空间 URL）。
