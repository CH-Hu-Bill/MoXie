# 人生重开模拟器来源与改动

- 使用仓库：https://github.com/AliyunWorkbench/lifeRestart
- 上游原项目：https://github.com/VickScarlet/lifeRestart
- License: MIT（Copyright (c) 2021 神戸小鳥，见同目录 `LICENSE`）

## 为 ListenWrite 离线包所做的改动

- 不使用 webpack 构建，直接以浏览器原生 ES Modules 加载 `src/`，避免额外打包依赖。
- jQuery 从 CDN 改为本地 `vendor/jquery.min.js`。
- `data/*.json` 改名为 `data/*.txt`：内容仍是 JSON，但 ListenWrite 的本地路由会拦截 `.json` 直链，改名后才能被游戏读取。
- 去掉 GitHub / Discord 外链、Google Fonts 外链、Service Worker 注册和 PWA manifest，保证离线运行。
- 主题 CSS 改为 `view/light.css`、`view/dark.css` 的相对路径，适配 `game.php` 的 iframe。
- 新增 `view/landscape-fix.css`：横屏且高度不足时重排天赋抽卡、属性分配、人生轨迹和总结页，避免列表被压扁或遮挡。
- 入口改为 `dist/bundle.js`（esbuild 打包，target es2017）：兼容不支持 ES Modules 的浏览器/内嵌 WebView，并把三份 JSON 数据打进 bundle，避免服务器拦截 `.txt` 时卡在加载中。重新构建方式见 `BUILD.md`。
