# ListenWrite 项目交接文档

> 沟通用**中文**。动手前请先通读本文件 + `web/风格.md` + 各 README。
> **本项目已改为「本地运行」，原线上服务器已下线，不再有任何远程部署。**

---

## 1. 项目概况

- **ListenWrite（默写史记）**：班级单词/句子/作文默写 + 班级史记应用，**monorepo**。
- `web/`：网站端 + 全部后端 API（**PHP 7.4+，JSON 文件存储，无数据库**，原生 HTML/CSS/JS）。
- `app/myapp/`：Flutter **Android** 客户端（Provider、CachedNetworkImage、video_player、flutter_quill、shared_preferences）。
- 设计系统：**Hand-Drawn 手绘风**，规范见 `web/风格.md`（必读：wobbly 圆角、硬偏移阴影、纸纹理、字体、红 `#ff4d4d` 蓝 `#2d5da1`）。APP 端 token 见 `app/myapp/lib/theme/app_theme.dart`。
- 开源协议：**GPL-3.0**（根 `LICENSE`）。

## 2. 本地运行（核心）

### 开发机（Linux/macOS，本仓库开发环境）

```bash
./scripts/dev.sh            # 监听 0.0.0.0:8000，自动打印内网地址，多进程
PORT=8080 ./scripts/dev.sh  # 指定端口
```

- `web/router.php` 是内置服务器的安全路由：拦截 `/data/`、`/inc/`、`/bin/`、`/vendor/`、`*.json` 等；并为 `/fonts/`、`/lib/` 输出长缓存。
- 本地配置 `web/inc/config.php`（gitignored，含 `app_secret`、`admin_password`）。

### 最终用户（Windows）

双击 `scripts\install.bat`（自动请求管理员权限），会：

1. 安装便携 PHP 8.2（启用 zip/gd/curl/mbstring/openssl）与 ffmpeg 到 `runtime/`
   - **离线兜底**：若根目录存在 `offline/php.zip` 与 `offline/ffmpeg.zip`，脚本优先使用，**无需联网**（适合网络差的电脑）
   - 否则从官方源下载（PHP：windows.php.net；ffmpeg：gyan.dev → GitHub BtbN 兜底）
2. 生成 `web/inc/config.php`（随机密钥）
3. 注册**开机自启计划任务**、**放行防火墙入站端口**并启动服务
4. 控制台打印内网访问地址

> 防火墙：服务以隐藏窗口监听 `0.0.0.0`，脚本会按端口创建入站放行规则（卸载时移除），
> 否则防火墙弹窗被忽略时手机 APP 连不上。

`scripts\update.bat` 更新（git 仓库走 `git pull`；非 git 解压版自动从 GitHub 下载最新源码覆盖，
两者都保留 `web\data\` 与 `web\inc\config.php`），`scripts\uninstall.bat` 卸载自启，`scripts\run.bat` 手动前台启动。

> 说明：Windows 下 PHP 内置服务器为单进程，暂不支持 `PHP_CLI_SERVER_WORKERS`。

## 3. 数据与隐私

- 全局：`web/data/classes.json`、`settings.json`、`app_versions.json`、`announcements.json`、`users/{uid}.json`、`users/tokens.json`。
- 班级隔离：`web/data/classes/{classId}/`（`words.json` / `tasks.json` / `history.json` / `gallery.json` / `uploads/` / `thumbs/` / `pronunciations/`）。
- 知识库 `words.json` 每条含 `type`（word/sentence/essay）+ 可选 `title`；旧数据缺省视为 word。
- **隐私**：不导出/不分享用户名、密码、个人列传（vlog）；超级导出中的错题仅聚合计数。
- `web/data/` 已被 `.htaccess` 与 `router.php` 双重保护，禁止直链。

## 4. AI 接口（按班级）

- 每个班级在「设置 → AI 设置」配置：**OpenAI 兼容 / Anthropic** 二选一 + 接口地址 + 密钥 + 模型名，支持「测试连接」。
- 无全局 AI 配置；未配置时相关功能提示「AI 服务不可用」。
- 实现见 `web/inc/api.php` 的 `AIClient`（端点自动补全、双协议）。
- 密钥存 `settings.json` 的 `ai_{classId}`（本地），会随超级导出一起打包。

## 5. APP 构建

- 通过 GitHub Actions（`.github/workflows/build.yml`）构建，产物发布到 **GitHub Release**（tag `apk-latest`）。
- 本机配置低，**不要在本机跑 `flutter build apk`**，用 CI。
- `app/myapp/lib/config/api_config.dart` gitignored；CI 可由 `API_BASE_URL` secret 生成（未设置则用 `api_config.example.dart`）。
- 改 APP 代码需 bump 版本（`.github/workflows/build.yml`、`pubspec.yaml`、`api_config.example.dart`、根 README 四处一致），并在 Release 带上版本更新日志。
- `.github/workflows/install-windows.yml`：在 `windows-latest` 用 **Windows PowerShell 5.1** 实跑 `install.ps1`（在线 / 离线两条路径），
  校验 PHP/ffmpeg 解压与运行、php.ini 扩展、config 无 BOM、计划任务与防火墙规则、HTTP 200、受保护路径 403、`update.ps1`、卸载清理。改动相关脚本会自动触发。

## 6. 本地校验命令

```bash
php -l web/xxx.php      # PHP 语法
node --check web/xxx.js # JS 语法
flutter analyze         # APP 静态检查（0 error）
flutter test            # 6 个通过；widget_test 模板测试为既有失败
```

## 7. 注意事项

- 不要提交：`web/inc/config.php`、`web/data/*`、`api_config.dart`、`opencode.json`、`runtime/`、`.serena/`。
- 修改 Web 页面后，若改了 `common.css`/`common.js`，请提升引用处的 `?v=` 版本号（缓存穿透）。
- `web/inc/db.php` 会为所有非 CLI 请求发送 `Cache-Control: no-store`。
- 有不清楚的先读代码/README/风格.md，再问用户，不要臆测。

---

## 8. 下次继续：待办清单（重点：APP 端）

> 需求源头：根目录 `最终优化.md`（原始优化需求，含 Web/APP 全部细节，务必先读）。
> 本轮已完成 Web 端绝大部分 + APP 的「服务器端点设置」。以下主要是 **APP 端（Phase F）**，约需一周后继续。

### 8.1 APP 端（Phase F，已完成，待随版本发布）

> 已完成（**1.2.1**）：知识库句子/作文支持、服务端 `type/title` 暴露、AI 直译补全。
> 发布：改 APP 需 bump 版本四处一致（`.github/workflows/build.yml` / `pubspec.yaml` /
> `lib/config/api_config.example.dart` / 根 `README.md`），CI 自动出包到 Release `apk-latest`。

1. **端点设置**：✅ 1.0.17 完成（首次启动手输、HTTP/HTTPS、运行时覆盖 `ApiConfig.baseUrl`）。
   - 可继续增强：端点不可用时提示「服务器未开机 / 更换端点」；可选多端点记忆与探活。
2. **知识库三板块**：✅ APP 端与网页端一致，二级标签栏 **单词 / 句子 / 作文**（带数量角标），
   每个板块独立列表与滚动；句子卡片上英下中（`MarqueeText` 跑马灯，可手动拖动），
   作文卡片显示标题+摘要、点击进入 `EssayDetailScreen` 全文。
3. **错题本**：✅ 支持句子与作文（按类型渲染、可增删）。
4. **搜索 / 刷新 / 定位**：✅ 定位沿用「扫描式定位」并提示「正在定位…」；每个板块独立定位到
   该类型最近一次默写位置（服务端 `get_library_meta` 的 `last_ids`），搜索命中自动切板块并定位。
   后台每 30s + 切回前台时轮询内容指纹 `rev`，有变化才静默重载（保持滚动位置）——
   修复「其他设备新增内容不出现、重进也不刷新」。
5. **数据模型对齐**：✅ `models/word.dart` 增加 `type/title`；`WordMatch` 同步；
   `ApiService.addWord` 支持 `type/title`，新增 `aiWord`。服务端 `app_api.php` 的
   `get_words`（按类型排序）/`add_word`/`ai_word`/`get_task_detail`/`get_wrong_words`/
   `search_all`/`get_completed_tasks` 全部输出 `type/title`。
6. **展示 / 默写 / 听写**：APP 任务详情按类型渲染（作文点击进详情）。
   APP 端**暂无独立「默写 / 听写」模式**（该模式目前仅 Web 端）。
7. **AI**：✅ 服务端按班级配置；`ai_word` 对句子/作文走「直译」提示词（不意译），
   APP 添加弹窗提供「AI 补全 / AI 直译」按钮。
8. **发布**：见上。当前版本 **1.2.2**。

### 8.2 非 APP 待办

- ✅ **视频缩略图跨平台化（已完成）**：`web/inc/db.php` 的 `generateVideoThumb()` 已改为**直接调用 ffmpeg**
  （`proc_open`，`resolveFfmpegBinary()` 依次查找 `LISTENWRITE_FFMPEG` → `runtime/ffmpeg/bin` →
  `web/bin` → PATH），去掉 Composer/php-ffmpeg 依赖，兼容 Windows。
- ✅ **Windows 脚本编码修复（已完成）**：`install.ps1` / `update.ps1` 必须是 **UTF-8 with BOM**
  （否则中文版 PowerShell 5.1 按 GBK 读会解析失败）；`.bat` 加 `chcp 65001`；生成的 `config.php`
  用 `[IO.File]::WriteAllText(..., UTF8Encoding($false))` 写成**无 BOM**（避免 BOM 污染 PHP 输出）。
- ✅ **Windows 安装脚本真机验证（已完成）**：`.github/workflows/install-windows.yml` 在 `windows-latest`
  上用 PowerShell 5.1 实跑，**在线下载**与**离线包**两条路径均通过；并补上**防火墙入站放行**（否则手机 APP 连不上），
  卸载时自动移除规则。
- **CI（可选）**：`API_BASE_URL` 目前仍可覆盖内置默认；Release 已附固定版本日志，后续可改为自动读取。
- **网页端视频媒体缓存优化（可选）**：图集视频偶发重复缓冲。

### 8.3 关键实现备忘（本轮改动）

- **定位算法**：三种类型**各自独立**回溯「最近一个包含该类型的任务」，取其中该类型最后一项
  （`words.php` 的 `$lastPos` / `$lastId`；客户端 `locateForTab` / `switchTab`）。
- **缓存**：所有 HTML/JSON `Cache-Control: no-store`；`/fonts/`、`/lib/` 走 `router.php` 输出 `immutable`；
  改 `common.css` / `common.js` 必须提升引用处的 `?v=`。
- **字体自托管**：`web/fonts/`（来自 app assets）；Quill 本地 `web/lib/quill/`（原 jsdelivr）。
- **超级导入导出**：`web/export.php`（勾选导出）、`web/index.php` 的 `import_class`（zip 白名单校验、隐私排除、
  过期 pending 任务导入后置为 completed）；上传上限在 `scripts/dev.sh` / `scripts/install.ps1` 里设为 300M。
- **AI**：`web/inc/api.php` 的 `AIClient`（OpenAI 兼容 / Anthropic 双协议，按班级 `settings.json` 的 `ai_{cid}`）。
- **Windows 一键脚本**：`scripts/` 下的 `install.bat/.ps1`、`update.*`、`uninstall.bat`、`run.bat`、`run-hidden.vbs`；
  `runtime/` 存放下载的 PHP/ffmpeg（gitignore）。`install.ps1` 以脚本上一级目录为项目根。
- **本地运行**：`./scripts/dev.sh`（Linux）监听 `0.0.0.0:8000`，`web/router.php` 负责安全拦截与静态缓存。

---

## 9. 维护轮次（Web 体验优化，已完成）

> 需求源头：根目录 `维护.txt`。均为 Web 端改动，**未动 APP**（APP 仅随 MVP 功能发布）。

1. **内网主网址**：新增 `web/inc/net.php` —— 探测局域网 IPv4（`lw_lan_ip()`：HTTP_HOST 优先 → 多来源候选按网段打分择优，避免误选 Docker/WSL 的 172.16-31 段；带 10 分钟文件缓存 `web/data/.lan_ip_cache.json`），`lw_lan_url()` 组装 `http://<ip>:<port>`（端口取 `SERVER_PORT`，默认 8000）。
   - 注入：`web/inc/head.php` 暴露 `window.LAN_URL`（并据班级设置给 `<html>` 加 `font-en-andika`）；`web/main.php` 引导弹窗新增「内网访问」步；`web/settings.php` 顶部新增「内网主网址」卡片（复制按钮）。
2. **英文字体（可选 Andika，仅影响英文）**：`web/fonts/Andika-{Regular,Bold,Italic}.ttf`（源自 `web/fonts/Andika-7.000.zip`，OFL 协议），`common.css` 加 `@font-face` 与 `html.font-en-andika { --font-body/--font-heading: 'Andika', 中文字体… }`。中文始终回落手写字体。
   - 班级设置页新增「英文字体」分段切换，存 `settings.json` 的 `font_{classId}`（空=默认，`andika`=教学字体）；`head.php` 读取后加 `<html>` class；引导弹窗新增说明步。
3. **跑马灯修复（长单词/释义卡住不动）**：根因是部分安卓 WebView / 智能教学设备**不支持在 `@keyframes` 中插值 CSS 变量**（`var(--mx)`），动画停在起点。改用 **Web Animations API** 直接驱动 `transform`（`common.js` 新增 `applyScrollMarquee()/resetScrollMarquee()/marqueeSpanOf()`，`words.php` 的 `initKbMarquee`、`display.js` 的 `fitWordMarquees` 复用），并移除 `common.css`/`display.css`/`words.php` 里 `.scrollable span` 的 CSS `animation` 与已无用的 `@keyframes marquee`/`dWordMarquee`。
   - 缓存版本：`common.css?v=15`、`common.js?v=13`、`display.css?v=12`、`display.js?v=19`。
4. **文档与仓库美化**：更新 `web/docs/使用说明.md`（内网访问、知识库三板块、设置/英文字体、FAQ）与 `web/README.md`（知识库三板块、`net.php`、自托管字体 + Andika、设置项）；根 `README.md` 加**网站图标 + 功能亮点 + 界面预览**；删除无用的 `web/project-features/`。
   - 截图：沿用 `web/docs/images/`（如需更新 UI 截图，覆盖同名文件即可，清单见 `web/docs/使用说明.md`）。
5. **离线包**：以上改动后需重打 `ListenWrite-USB.zip`。

---

## 10. 维护轮次（作文思维导图 MVP，Web 已完成，APP 待做）

> 需求源头：根目录 `维护.txt` 第 10 条 + 与用户的问答确认。**仅 Web 端已实现**，需用户验证后再做 APP。

- **数据**：`words.json` 作文条目新增可选 `mindmap`（Markdown 大纲字符串，≤3 层 ≤20 节点 ≤40 字符/节点）。旧作文不动、不补生成。
- **生成**：`inc/api.php` 新增 `AIClient::generateEssayMindmap($classId,$title,$text)` + `normalizeMindmapOutline()`（规整/限长）。`words.php` 的 `add_word` 在 `type=essay & gen_mindmap=1 & 已配置 AI` 时调用并写入 `mindmap`；添加模态出现**可选项复选框**（仅 `AIClient::isConfigured($classId)` 为真时）。
- **渲染**：`common.js` 新增 `parseMindmapOutline()` / `renderMindmap()`（自绘 SVG 树状图，手绘铅笔风，零依赖，`textContent` 防 XSS）；样式在 `common.css`（`.mindmap-*`）。
- **展示**：`words.php` 作文**详情弹窗**展示导图；`task.php` 作文卡内嵌 `.mindmap-holder` + `mindmapData`，**展示模式不显示导图**，**默写模式**在 `mindmap_{classId}` 开启且该作文有导图时隐藏中英文、改显导图。
- **设置**：`settings.php` 新增「作文默写方式」（思维导图 / 中文，默认思维导图，存 `mindmap_{classId}`）；`main.php` 引导弹窗新增说明步。
- **API**：`app_api.php` 的 `appWordOut` 增加 `mindmap` 字段（供 APP 阶段使用）。
- **缓存版本**：`common.css?v=17`、`common.js?v=14`。
- **编辑重生成**：`update_word` 中，作文若原本已有 `mindmap` 且英文/标题有变化 → 自动重新生成；AI 不可用则保存正文、保留旧导图并返回 `mindmap_warning`（前端提示）。英文/标题未变则沿用旧导图。添加模态的勾选项改为手绘风 `.hd-check`（样式在 `common.css`）。
- **APP 阶段待办**：作文详情页 / 知识库作文 / 任务详情 / 搜索展示导图，以及 APP 端「按导图/中文默写」；`app_api` 的 `add_word` 也支持 `gen_mindmap`。完成后 bump APP 版本并发布。

---

## 11. 展示大屏适配（已完成）

> 需求：大屏未跟上「知识库三板块」，需显示单词 + 句子 + 作文，并调整任务取法。

- `display.php` 的 `displayRecentTaskWords()` 重写：内容含 `words/sentences/essays`（各带 type/title）。
- **任务取法**：优先最近一次 **pending**（未完成）任务；无未完成则取历史中最近一次 **completed** 任务（`displayPickLatestTask($list,$preferEarliestCreated)`：日期最晚优先，同日 pending 取最早创建、completed 取最晚创建）。上限：单词 20 / 句子 10 / 作文 8。
- 渲染：`display.php`（首屏 PHP 直出）与 `display.js` 的 `renderWords()` 均按「单词网格 + 句子一行条 + 作文卡片」三段输出；作文按作文库卡片样式（标题 + 英文 + 中文，多行截断 `-webkit-line-clamp`）；`fitWords()` 会扣除句子/作文区高度。
- 英文字体已随全局设置（`head.php` 给 `<html>` 加 `font-en-andika`，`display.css` 用 `var(--font-heading/body)`）；占位 emoji 已去除。
- 缓存版本：`display.css?v=13`、`display.js?v=20`。

---

## 12. APP 端作文思维导图展示（1.2.2）

- 模型：`app/myapp/lib/models/word.dart` 新增 `mindmap` 字段 + `hasMindmap`。
- 组件：`app/myapp/lib/widgets/mind_map_view.dart` —— 解析 Markdown 大纲（嵌套 `- ` 列表）→ 自绘手绘风树状图（白底 + 铅笔描边 + 硬阴影 + 贝塞尔连线；超宽可横向滚动；零第三方依赖，样式对齐 Web）。
- 展示：`essay_detail_screen.dart` 标题下展示「思维导图（英文要点）」区块；`EssayCard` 新增「导图」标记（`study_screen` / `task_detail_screen` 传 `hasMindmap: w.hasMindmap`）。
- 数据来源：`web/app_api.php` 的 `appWordOut` 已含 `mindmap`（`get_words` / `get_task_detail` / `search_all`）。
- 版本 **1.2.2**（`.github/workflows/build.yml` / `pubspec.yaml` / `api_config.example.dart` / 根 `README.md` 四处一致；本地 `api_config.dart` 同步）。
- **未做**（如需另开轮次）：APP 端「按导图默写」（APP 暂无默写模式）、APP 添加作文时生成导图（`app_api` 的 `add_word` 支持 `gen_mindmap`）。

---

## 13. 完整使用说明与截图清单（文字部分已完成）

- `web/docs/使用说明.md` 重写为 **19 章完整手册**：目录、产品简介、安装、班级、主页、知识库、发音跟读、任务、记录、史记、图集、大屏、设置、AI、导出导入、APP、管理员、数据备份、FAQ、限额速查。
- `web/help.php`：标题自动生成 GitHub 风格 slug id（保留中英文、去标点、空格转 `-`），文档内目录锚点可跳转；`#` 锚点链接在当前页打开；加入平滑滚动与 `scroll-margin-top`。
- `web/docs/截图清单.md`：Web 32 张 + APP 17 张，逐张写明入口、操作状态、画面必含元素、优先级与文件名；含拍摄准备与交付方式。
- 根 `README.md`、`web/README.md` 增加完整手册入口；同步修正 `web/README.md` 中过期描述（完成/取消按钮、周末大礼包规则、班级史记导出）。
- **截图交付（2026-10-18）**：Web 31 张（`01`–`30`、`32`）、APP 15 张（`A01`、`A02`、`A04`–`A12`、`A14`–`A17`）已放入 `web/docs/images/` 并插入使用说明；根 README「界面预览」已换成功能主页 / 知识库 / 作文导图 / 展示大屏 / APP 单词库 / APP 导图。`31`、`A03`、`A13` 暂缺（不影响使用）。

---

## 14. Web 全局字号微调（2026-10-18）

- 实际使用反馈整体字号偏小；在 `web/common.css` 末尾统一上调整体 UI 字号：
  - 常用组件（按钮、输入框、标签、菜单、状态栏、任务/知识库/史记列表等）+1px；
  - 知识库句子 / 作文、任务句子 / 作文、单词卡释义等阅读型文字 +1–2px；
  - 对页面内联 `font-size: 11–16px` 用属性选择器统一 +1px，覆盖设置 / 管理后台等大量内联说明文字。
- `help.php` 使用说明正文字号 16→17px，标题、表格同步 +1–2px。
- 缓存版本：`common.css?v=18`（`inc/head.php`）。
- **未改**：展示大屏（`display.css` 自身布局与 clamp 字号）、APP（Flutter 字号已较大）。
- 已重打离线包。

---

## 15. Word 版使用说明（2026-10-18）

- 新增 `web/docs/ListenWrite使用说明.docx`（约 6.5MB，A4，46 张配图、25 个表格）。
- 生成方式：由 `web/docs/使用说明.md` 经 pandoc 转换，再用 python-docx 设置中文字体、标题层级、页眉页脚、表格样式与图片宽度。
- 目录为 Word 域：打开文档时自动更新；如未更新，右键目录 → 更新域。页脚含「第 X 页 / 共 Y 页」。
- 正文 11pt / 1.5 倍行距，章标题 18pt，节标题 14pt，表格 9pt；图片 Web 6.2in、APP 3.2in。
- **注意**：Markdown 说明更新后，Word 版需重新生成；当前 docx 与 md 内容一致（仅 md 顶部多了 Word 版下载链接）。
