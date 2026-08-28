# ListenWrite 项目交接文档

> 本机后续开发交给新的 agent。请先通读本文件 + `web/风格.md` + 各 README 再动手。
> 一切沟通用**中文**。改完代码必须：**更新 README → 部署线上 → 推送远端**（三步缺一不可）。

---

## 1. 项目概况

- **ListenWrite（默写史记）**：班级单词默写 + 班级史记应用，**monorepo**。
- `web/`：网站端 + 全部后端 API（**PHP 7.4+，JSON 文件存储，无数据库**，原生 HTML/CSS/JS）。
- `app/myapp/`：Flutter **Android** 客户端（Provider 状态管理，CachedNetworkImage，video_player 等）。
- 设计系统：**Hand-Drawn 手绘风**，规范见 `web/风格.md`（必读：wobbly 圆角、硬偏移阴影、纸纹理、ZCOOLKuaiLe+MaShanZheng 字体、红 `#ff4d4d` 蓝 `#2d5da1`）。APP 端 token 见 `app/myapp/lib/theme/app_theme.dart`。
- 详细部署/API/安全说明：`web/README.md`、`app/myapp/README.md`、根 `README.md`。

## 2. 关键入口文件

| 文件 | 作用 |
|------|------|
| `web/main.php` | 主页（含全局搜索，单词组分页加载） |
| `web/words.php` | 单词库（定位居中、搜索高亮持久化） |
| `web/task.php` / `web/history.php` | 默写任务 / 历史 |
| `web/gallery.php` | 图集（上传/画廊/灯箱，描述自动滚动，视频 ffmpeg 首帧 poster） |
| `web/display.php` + `display.js` + `display.css` | 壁纸投屏大屏页（图集轮播、描述自动滚动、视频预加载） |
| `web/app_api.php` | APP 后端 API（全部 POST、JSON） |
| `web/inc/db.php` | 数据层（JSON 原子写 + 文件锁）+ 视频首帧缩略图生成 |
| `app/myapp/lib/screens/gallery/gallery_screen.dart` | APP 画廊 Tab（视频首帧贴纸、描述自动滚动） |
| `app/myapp/lib/widgets/hand_drawn.dart` | 手绘组件：`MarqueeText`（单词跑马灯）、`AutoScrollText`（纵向自动滚动描述）、`WordCard` 等 |

## 3. Git 双账户与推送规范（重要）

**本机两个 GitHub 账户均已登录 git（Windows 凭据管理器），推送不需要 SSH 密钥。**

| 账户 | 用途 | 仓库 |
|------|------|------|
| **大号 `CH-Hu-Bill`** | **源码更新一律推这里；APK 构建也走这里**（2026-08-28 起配额已恢复，改 `app/myapp/**` 推送即自动触发 CI，或手动 `workflow_dispatch`） | `CH-Hu-Bill/MoXie`（remote `origin`） |
| **小号 `HUBILLHANDSOME`** | 备用（大号配额再次受限时才用） | `HUBILLHANDSOME/Moie-APK-2`（只含 `app/myapp/**` + workflow，已配 secrets） |

**推送/构建规范（每次执行前）：**
1. **先告诉用户「用哪个账户做推送/触发 CI 构建」，用户确认后再执行**。
2. **源码更新 → 大号**：直接 `git push origin main`（凭据管理器自动用 CH-Hu-Bill）。
3. **APK 构建 → 小号**：把最新 `app/myapp` 同步到 `HUBILLHANDSOME/Moie-APK-2` 后触发 `workflow_dispatch`；同步需用 **HUBILLHANDSOME 的 token**（URL 带 token 或切换凭据；如遇凭据用的是大号导致 403，向用户要小号 token）。小号仓库**不要**用 `origin` 当默认 remote（避免混）。
4. 网络提示：本机直连 GitHub 偶尔不通，默认走代理 `http.proxy=127.0.0.1:7899`（git 已配置）；如直连失败且代理正常，直接用默认配置推。
5. 推送前 `git status` 检查，**只 stage 本次相关文件**；勿提交本地临时文件（`opencode.json`、`key.properties`、`api_config.dart`、自动生成的 `linux/macos/windows/flutter/generated_plugin*`）。

## 4. 部署线上（每次改 web/ 后必做）

- **SFTP**：主机 `moxie.billspace.top`，端口 `22`，用户 `moxie-agent`，私钥 `C:\Users\LUOHU\.ssh\id_ed25519`。
  - **SFTP-only，无 shell**（不能用 ssh_exec 执行命令）。
  - 服务端根目录 = 站点根 `/www/wwwroot/moxie.billspace.top`。
  - 部署时保持相对结构（`inc/`、`bin/` 子目录原样），**上传后回读 diff 确认一致**。
  - `bin/ffmpeg`、`bin/ffprobe` 必须是 LF 换行（CRLF 会毁掉 shebang）。
- **宝塔 MCP**（服务端运维，本机已配好）：chmod/chown、查 PHP-FPM 日志、跑缩略图 cron、看 ffmpeg、改 `.user.ini` 等都用它。
- 站点已配置：`open_basedir=/www/wwwroot/moxie.billspace.top/:/tmp/`、`display_errors=Off`、`memory_limit=256M`（在 `.user.ini`，**不要乱改**）。
- 视频首帧缩略图：由 **CLI 计划任务**（每分钟）`cron_gallery_thumbs.php` 生成（FPM 禁用了 exec/proc_open，php-ffmpeg 只能在 CLI 跑）；接口 `video_thumb.php` 只读不生成。

## 5. APK 构建（大号 CI → GitHub Release）

1. 改 APP 代码后**必须 bump 版本**：`.github/workflows/build.yml` 的 `appVersion` + `app/myapp/pubspec.yaml` `version` + `app/myapp/lib/config/api_config.example.dart` `appVersion` + 根 `README.md` 第 5 行，四处一致。
2. **构建流程（2026-08-28 起）**：推送大号 `main`（改动 `app/myapp/**` 自动触发）或 `workflow_dispatch` → 构建后 APK **发布到 GitHub Release（tag `apk-latest`，覆盖式）**，从仓库 Releases 页下载。
   - **不再用 Artifact**（免费 500MB 配额反复被 "storage quota has been hit" 拦截，删除旧制品也要 6-12h 重算）；**不再用小号**。
   - Release 下载：`gh release download apk-latest --repo CH-Hu-Bill/MoXie`（gh 已登录大号）。
3. 下载后核对签名：`apksigner verify --print-certs`（SHA-256 应为 `bfed770f39ab26791aa56f0bd386b5c144d85588e8731da7be91fcb88f8a3223`）。
4. **发布**：上传 APK 到服务器 `apk/listenwrite-release.apk` + 在 `app_versions.json` 写版本记录（`Database::update`），APP 端 `check_version` 即弹更新。
5. record 插件用 **^6.2.1**（5.x 的 record_linux 与新 platform interface 不兼容导致 CI 编译失败；v7 要求 AGP 9 勿升）。

## 6. 最近工作状态（截至 2026-08-28）

- **main** = `origin/main`；1.0.12 已构建发布（大号 Release `apk-latest`），线上 check_version/下载页验证通过。
- **2026-08-28 批次 2（全球发音，1.0.12 已发布）**：
  - 后端：`inc/audio_guard.php`（M4A magic + mvhd 时长≤10s≤2MB，自测 5/5）+ `pronunciation.php` 输出端点（Range+immutable，免鉴权靠 32hex 随机文件名）+ app_api 3 个 action（列表/上传限流 20 次/h/删除；每人每单词 1 条重录覆盖）
  - 数据：`data/classes/{cid}/pronunciations.json` + `pronunciations/{wordId}/{32hex}.m4a`
  - Web：words.php 卡片地球按钮 → 手绘风发音列表弹窗播放（只听不录）
  - APP：record ^6.2.1 + path_provider + RECORD_AUDIO 权限 + GlobePronButton（长按录音松手上传/短按播放列表）；WordCard 加 classId/wordId（默写场景不传即隐藏）；有道标准发音保留（两个按钮并存）
  - 顺手修：MarqueeText 冷字体设备滚动失效（300/800/1600ms 延迟重测）；display 词性改卡片顶部独立行（不再与单词同行挤爆）
- **2026-08-28 批次 1（5 bug 修复）**：手写板 Pointer Events/touch-action/锁滚动/高分屏；上传拖拽+粘贴（绕 Windows 触屏文件对话框卡死）；图集 8/9 事故（丢 65 条，描述已导出 `data/classes/6a7173c7e84ab/lost_gallery_20260809.md`，补回 6 张孤儿图）；灯箱视频加载进度；web 端 emoji→SVG
- **⚠️ 服务器运维铁律**：在服务器跑 PHP 脚本**必须 `runuser -u www --`**——用 root 跑写出的文件 root 所有，PHP-FPM（www 用户）读不了会直接挂页面（8/28 图集因此打不开一次）；文件所有权规范 = `www:www` + ACL `u:moxie-agent:rwx`
- **待用户验证**：手写板触屏（重做/颜色框已加固但根因未定，画画是否正常/是否先撤销过/取色窗是否弹出待确认）

## 7. 本地校验命令

```bash
php -l web/xxx.php                       # PHP 语法
node --check web/xxx.js                  # JS 语法
flutter analyze                          # APP 静态检查（0 error；info 级 const 建议是既有存量，可忽略）
flutter test                             # 6 个通过；test/widget_test.dart 的 counter 模板测试是既有失败，与业务无关
```

## 8. 本机限制（务必知道）

- **机器配置低**（i3-7100 双核 / 8GB 内存）：**不要在本机跑 `flutter build apk`**，必 OOM/超时，用 CI。
- 之前为本地构建做过低配改造（gradle 低内存 + 镜像），**已全部还原**，工作区干净。
- 本地 `api_config.dart` 是 gitignored 模板（`127.0.0.1:8000`），**勿提交**；CI 用 `API_BASE_URL` secret 生成。
- 视频缩略图 cron 每分钟跑一次，正常会输出 `scanned=N generated=0 failed=0`。

## 9. MCP / 工具

- 本机已配好全部 MCP：**baota**（宝塔运维）、**ssh**（SFTP）、**context7**（库文档）、**flutter 专用 agent**（analyze/test/build/gen）。
- 有不清楚的，先读代码/README/风格.md，再**问用户**，不要臆测。
