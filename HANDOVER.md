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

## 5. APK 构建（默认走小号）

1. 改 APP 代码后**必须 bump 版本**：`.github/workflows/build.yml` 的 `appVersion` + `app/myapp/pubspec.yaml` `version` + 根 `README.md` 第 5 行，三者一致。
2. **源码更新仍推大号**（`push origin main`），但**出 APK 用小号**：
   - 把小号仓库同步到最新 `app/myapp`（用 `git ls-files` 拷跟踪文件，保持 `app/myapp/` 目录结构 + `.github/workflows/build.yml`；**注意别把本地低配版 gradle.properties/settings.gradle.kts 带进去**，要用 git HEAD 的原始字节；`.gitattributes` 保证 LF）。
   - 小号仓库已配好 secrets：`API_BASE_URL=http://moxie.billspace.top`、`KEYSTORE_BASE64`（= `D:\Downloads\moxie.jks` 的 base64）、`KEY_STORE_PASS`/`KEY_KEY_PASS`=`billhandsome`、`KEY_ALIAS`=`hu`。
   - 触发 `workflow_dispatch` → 从新账户 Artifacts 下载 `listenwrite-release` APK。
   - 下载后用 `D:\android-sdk\build-tools\36.0.0\apksigner.bat verify --print-certs` 核对签名（SHA-256 应为 `bfed770f39ab26791aa56f0bd386b5c144d85588e8731da7be91fcb88f8a3223`）。
3. **大号 CI**：构建本身成功，但制品上传被 GitHub 免费配额拦截（每 6–12h 才重算一次，未恢复前上传必失败）；已清理制品到 ~105MB 并加 `retention-days:14`，配额恢复后大号也可用，但**当前一律用小号出包**。
4. **发布**：拿到 APK 后，在 `admin.php`「发布 APP 版本」录入版本号/更新说明（写入服务器 `data/app_versions.json`），APP 端 `check_version` 才会弹更新。

## 6. 最近工作状态（截至 2026-08-28）

- **main** = `origin/main`，线上 5 个改动文件 MD5 全 MATCH（gallery/main/history_book/admin/display）。
- **2026-08-28 批次（5 bug 修复，纯 web 端，无 APK）**：
  - 手写板触屏无效修复：Pointer Events 统一鼠标/触摸/笔 + `touch-action:none` + 弹窗锁 body 滚动 + 高分屏 dpr 渲染（触屏电脑根因：触摸手势被浏览器合成器接管滚动后面编辑器页）
  - 上传卡死缓解（Windows 触屏文件选择对话框自身卡死，网页侧绕行）：gallery 上传区拖拽 + Ctrl+V 粘贴、history_book 编辑器拖拽插图、accept 改纯 MIME
  - 图集数据事故确认：8/9 20:33~8/10 13:17 之间 1 号班 8/4~8/9 的 65 条图集（记录+文件）全部丢失，根因不可考（当晚有数据修复操作）；当前代码无批量删除路径（已复查）；65 条描述文字已导出到 `data/classes/6a7173c7e84ab/lost_gallery_20260809.md`；6 个孤儿文件已补回（1 号班 1 张 + 2 号班 5 张，描述标"（恢复）"）
  - 灯箱视频加载进度百分比（低带宽体验）+ preload=auto
  - web 端 emoji 全部换 SVG 线条图标（main.php 加 iconSvg() 助手，~30 处）
- **下一批（已规划待开工）**：「全球发音」功能——班级内共享、每人每单词 1 条（重录覆盖）、全部非默写单词卡片入口（APP 录音长按/短按播放，web 只听）；后端 3 个 API + audio_guard（M4A 时长≤10s ≤2MB）；出 1.0.12 APK **走大号**。
- **已推送大号**：main 与 origin/main 同步。
- **已完成（2026-08-21 批次）**：display 只显示最近未完成任务、admin APK 上传简化（自动关联最新版本）、1.0.11 发布上架、README 全面更新。

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
