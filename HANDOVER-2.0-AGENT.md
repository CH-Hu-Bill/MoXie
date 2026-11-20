# ListenWrite 2.0 交接文档（给下一位 Agent）

> 更新日期：2026-11-20
> 当前分支：`feat/2.0`（**尚未合并到 `main`**）
> 当前版本：Web **2.0.2**（本地工作树含 `01d214e` 状态接口修复）、APP **2.0.0**
> 沟通语言：中文。先读本文件 + `docs/superpowers/specs/2026-11-20-v2-design.md` + 两份 plan。

---

## 0. 先看这里：不要做的事

- **不要提交**：`web/data/`、`web/inc/config.php`、`web/apk/`、`app/myapp/lib/config/api_config.dart`、keystore、`runtime/`、`dist/`、`scripts/dev.ps1`、用户本地改过的 `HANDOVER.md`/`README.md`。
- **不要在本机跑** `flutter build apk`（本机无 Flutter/Java/Android SDK；全部走 GitHub Actions/Codespaces）。
- **不要把管理员密码、keystore、GitHub token 写进仓库或文档**。本地 `web/inc/config.php` 只有 `admin_password_hash`，密码由项目所有者保管；忘记可运行 `php scripts/rotate-secrets.php` 轮换。
- **不要移动/删除已有 tag 或 Release**（`app-v2.0.0`、`web-v2.0.0`、`web-v2.0.1`、`web-v2.0.2`、`apk-latest`）。
- **不要拷贝 Kompact 代码**（AGPL-3.0-only，与本项目 GPL-3.0 不兼容）；`motion_photos`、`file_picker` 是 MIT，可放心用。
- **不要改旧 APP 的接口字段语义**：1.2.2 仍要能用；新字段只增不改。
- 用户已明确：**公网 HTTP 可以接受**（后台显示明文风险警告），不强制 HTTPS；代码同时兼容 HTTPS 隧道/反代。

---

## 1. 项目与技术栈

- 仓库：`https://github.com/CH-Hu-Bill/MoXie`（public，GPL-3.0）。
- `web/`：PHP 7.4+（本机 PHP 8.4）、原生 HTML/CSS/JS、JSON 文件存储，无数据库。
- `app/myapp/`：Flutter（Dart 3.5+），Provider、video_player、flutter_quill、cached_network_image 等。
- 设计系统：Hand-Drawn 手绘风；规范 `web/风格.md`；APP token `app/myapp/lib/theme/app_theme.dart`。
- 本地数据：`web/data/classes/{classId}/`（words/tasks/history/gallery/uploads/thumbs 等）；`web/data/*.json` 全局。
- APP 构建：GitHub Actions `.github/workflows/build.yml`；正式包固定 Flutter **3.50.1**，Gradle 仍是 9.1.0，保留了 `--android-skip-build-dependency-validation`（Flutter 3.50 要求 Gradle >=9.3.1）。升级 Gradle 后可移除该 flag。

---

## 2. 当前发布状态

| 产物 | 版本/tag | 说明 |
|---|---|---|
| APP | `app-v2.0.0` | arm64 29.3 MB / armeabi-v7a 27.7 MB |
| APP 指针 | `apk-latest` | 同步两个 ABI 包（另有旧 1.2.2 fat 包 `app-release.apk`） |
| Web | `web-v2.0.0` | 首个 2.0 源码包 |
| Web | `web-v2.0.1` | 一键更新初版 |
| Web | `web-v2.0.2` | 精简更新包 17.4 MB + 超时/CA/离线 CLI |
| 本地 Web | 2.0.2 + `01d214e` | 比 tag `web-v2.0.2` 多一个状态接口鉴权修复 |

`main` 仍是旧版本（`51bce3c`）；**所有 2.0 改动都在 `feat/2.0`**。下一位 Agent 先 `git switch feat/2.0 && git pull`，确认无误后再合并到 `main`。

> 注意：`web-v2.0.2` 的 Release zip **不包含** `01d214e`（`update_status.php` 管理员会话校验）。若直接部署 2.0.2，`/update_status.php` 仍可匿名读状态（含本机备份路径）。建议下一位 Agent 发 **web-v2.0.3** 把该修复带上，或手动限制该端点。

---

## 3. 本机运行 / 测试

```powershell
# 启动（PowerShell 执行策略可能拦截，需 Bypass）
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 start -NoThumbs
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 status
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 stop
```

- 默认地址：`http://127.0.0.1:8000`，内网 `http://<本机IP>:8000`（当前示例 `192.168.12.164`）。
- 后台：`/admin.php`；当前管理员密码由所有者保管，不在仓库。登录失败可用 `php scripts/rotate-secrets.php` 轮换（会同时换 `app_secret`，班级登录 Cookie 失效，APP token 不受影响）。
- `dev.ps1` 会自动用 `-d extension=...` 开 `curl/gd/mbstring/exif/openssl/zip/fileinfo`；**直接跑 PATH 里的 `php` 默认没有 gd/zip**。

PHP 测试（`update_apply_test` 需要 zip 扩展）：

```powershell
Get-ChildItem tests/php/*_test.php | ForEach-Object {
  if ($_.Name -eq 'update_apply_test.php') { php -d extension=zip $_.FullName }
  else { php $_.FullName }
}
```

APP 检查（本机无 Flutter，走 Codespaces/Actions）：

```bash
cd app/myapp
flutter analyze --no-fatal-warnings --no-fatal-infos
flutter test test/gallery_item_test.dart
```

CI（网络经常抽风，push/tag 失败就重试）：

```bash
gh workflow run build.yml --ref feat/2.0
gh run watch <run-id> --exit-status
```

- `build.yml`：只在 `app-v*` tag 或手动触发；手动只上传 artifact，tag 才发 `app-v{version}` 并更新 `apk-latest`。
- `release-web.yml`：`web-v*` tag 自动打 `web/` 精简包 + sha256。更新包不含 `web/docs/images` 与 `*.docx`（运行时不需要；已有文件不会被删除）。
- `install-windows.yml`：Windows 安装脚本验收，未改。

---

## 4. 已完成（S0–S2）

### S0 数据安全 / 基础修复

1. **修复史记保存误删图集**：`web/inc/history.php` 的清理现在同时收集 `history` + `gallery` + `gallery_trash` 引用，只删 `uploads/` 中无人引用的 32hex jpg/png/webp。
2. **空任务**：`web/inc/tasks.php` `tasksRemoveWordAndPrune()`；删除最后一个条目时删任务并清理 `last_task_id`；`app_api get_tasks` 过滤 `word_count=0`。
3. **错题本**：`web/inc/app_lib.php` `appApplyWrongFlags()`；`get_wrong_words`/`search_all` 返回 `is_wrong`；APP `Word.fromJson` 兜底、错题列表强制 `isWrong:true`、搜索页同步。
4. **Web 搜索高亮**：`words.php`/`task.php`/`history.php` 在用户操作后清除高亮并从 URL 去掉 `highlight`。
5. **历史页侧边栏**：`.content-split` 固定 `calc(100vh - 52px)` + `min-height:0`，左侧内部滚动；`common.css?v=23`。
6. **安全基线**：`lwAllowedOrigin` 默认同源（`allowed_origins=[]`）；`lwStartSession`（strict mode/HttpOnly/SameSite/Secure）；管理员支持 `admin_password_hash`；`scripts/rotate-secrets.php`；CORS 不再 `*`；HTTP 后台红字警告。
7. **迁移骨架**：`web/inc/migrations.php`、`web/inc/migrations/001_init.php`、`web/bin/migrate.php`、`schema.json`。

### S1 实况照片

- 服务端 `web/inc/motion_photo.php`：
  - 解析 XMP `GCamera:MicroVideoOffset` / `Container:Directory` + `Item:Length`；
  - 回退扫描最后一个 `ftyp`；
  - 提取 MP4 到临时文件；`app_api save_gallery` / `gallery.php save_gallery` 自动保存为 `type=live` + `live_video`。
- 图集模型：`type`、`live_video`、`uploader_id/name`；`appGalleryItemOut` 输出 `type=live`、`live_video_url`、封面 `thumb_url`（静态图）。
- APP：
  - `motion_photos ^1.0.0`（MIT，纯 Dart）检测并提取；
  - `file_picker ^10.3.10` 提供“选原图”兜底（`allowCompression:false`）；
  - `gallery_screen.dart` 上传前预览 + “实况”角标；`GalleryItem.isLive/liveVideoUrl`；卡片角标；查看器播放按钮；
  - `ApiService.saveGallery(..., liveVideo:)` 双文件 multipart；服务端 `is_live=1`。
- Web：上传 JPEG 时自动提取；卡片“实况”；灯箱按 MP4 播放。
- 验证：合成 `tests/php/fixtures/motion_sample.jpg` 已跑通 Web 自动提取；APP `flutter analyze` + 单测通过、CI 出包成功。
- **未完成真机验收**：用户当前手机不支持动态照片，APP 实况上传/播放尚未在真机验证。

### S2a 版本治理与发布

- `web/inc/version.php`：`LW_WEB_VERSION`、`LW_API_VERSION`、`LW_MIN_APP_VERSION`、`LW_MIN_WEB_VERSION`。
- `web/version.php`：公开 JSON。
- `check_version` 新增：`min_supported`、`force_update`、`web_version`、`min_web_version`、`api_version`、`server_too_old`。
  - 旧 APP 1.2.2 忽略新字段；服务端不按版本拒绝普通写请求。
  - 新 APP 启动 `HomeScreen._checkVersionGate()`：低于最低版本不可关闭；服务器过旧阻断；`ProfileScreen` 也有兜底强制弹窗。
- APP `ApiConfig.minWebVersion='2.0.0'`，`appVersion='2.0.0'`；`pubspec 2.0.0+5`。
- APK 分包：arm64/armeabi-v7a；`build.yml` 固定 Flutter 3.50.1；`apk-latest` 指针。
- 下载页 `web/download_app.php` 双 ABI 按钮；`.devcontainer` Codespaces。

### S2b 后台一键更新

- `web/inc/update.php`：GitHub `web-v*` 检查、release 解析、sha256 解析、URL 白名单、内置 CA bundle `web/inc/cacert.pem`。
- `web/inc/maintenance.php`：`web/data/.maintenance`；`db.php` 对普通请求 503；放行 `admin.php`/`update_status.php`/`version.php`。
- `web/inc/update_apply.php`：
  - 备份 JSON/config + 当前代码 zip 到 `web/data/backups/<timestamp>/`；
  - 下载/本地 zip → sha 校验 → 解压 → 用新代码 `bin/migrate.php` CLI 跑迁移 → 复制 `web/`（排除 `data/apk/backups/.update/inc/config.php`）→ `opcache_reset`；
  - 失败恢复代码 + JSON/config，保留维护模式；管理员可手动退出。
- `web/admin.php`：顶部“🔄 系统更新”卡片（检查、开始更新、退出维护）；`update_status.php`（**要求管理员会话**）。
- `web/bin/apply_update.php`：离线/手动更新 CLI：
  ```bash
  php web/bin/apply_update.php --zip=path/to/ListenWrite-web-x.y.z.zip --version=x.y.z
  ```
- 已验证：
  - 本地精简包成功从 2.0.0 → 2.0.2（备份/迁移/复制/恢复服务）；
  - 错误 sha → 失败回滚、维护模式保留、手动退出后 200；
  - 维护模式普通页/APP 接口 503，admin/status/version 可用；
  - GitHub 检查更新成功（CA 修复后）。
- **未完成**：完整 GitHub 网络下载在开发机太慢（~25 KB/s，37 MB 包），已中止；之后把 Web 更新包精简到 17.4 MB、超时放宽到 1800s，并补了离线 CLI。用户网络下需再点一次“开始更新”验收。

---

## 5. 关键文件地图

### Web

| 文件 | 作用 |
|---|---|
| `web/inc/version.php` | Web/API/最低版本唯一来源 |
| `web/version.php` | 公开版本 JSON |
| `web/inc/update.php` | 更新检查/解析/CA |
| `web/inc/update_apply.php` | 备份/下载/迁移/替换/回滚 |
| `web/inc/maintenance.php` | 维护模式 + 状态文件 |
| `web/update_status.php` | 更新状态（管理员会话） |
| `web/admin.php` | 后台；含“系统更新”卡片 |
| `web/bin/apply_update.php` | 离线更新 CLI |
| `web/bin/migrate.php` | 迁移 CLI |
| `web/inc/migrations.php` | 迁移框架 |
| `web/inc/app_lib.php` | 纯函数：wrong flags / version gate / gallery out |
| `web/inc/motion_photo.php` | Motion Photo 提取 |
| `web/inc/tasks.php` | 删除空任务 |
| `web/inc/history.php` | 史记清理修复 |
| `web/app_api.php` | APP API；`save_gallery/get_gallery/check_version` |
| `web/gallery.php` | Web 图集；实况自动提取/展示 |
| `web/download_app.php` | 双 ABI 下载页 |
| `web/inc/cacert.pem` | Mozilla CA bundle（更新检查/下载用） |

### APP

| 文件 | 作用 |
|---|---|
| `app/myapp/lib/screens/gallery/gallery_screen.dart` | 实况识别/上传/预览/角标/播放 |
| `app/myapp/lib/models/gallery_item.dart` | `isLive/liveVideoUrl` |
| `app/myapp/lib/services/api_service.dart` | `_postWithFiles`/`saveGallery(..., liveVideo)` |
| `app/myapp/lib/screens/home_screen.dart` | 启动版本门禁 |
| `app/myapp/lib/screens/profile/profile_screen.dart` | 版本提示/强制更新兜底 |
| `app/myapp/lib/config/api_config.example.dart` | `appVersion/minWebVersion` 模板 |
| `app/myapp/pubspec.yaml` | 2.0.0+5；motion_photos/file_picker |

### CI

| 文件 | 作用 |
|---|---|
| `.github/workflows/build.yml` | APP 分包/发布/`apk-latest` |
| `.github/workflows/release-web.yml` | Web `web/` 精简源码包 |
| `.github/workflows/install-windows.yml` | Windows 安装脚本验收 |
| `.devcontainer/` | Codespaces（Flutter + Android SDK） |

---

## 6. 数据与接口

- `gallery.json` 新增：`type`（image/video/live）、`live_video`、`uploader_id/name`；旧条目缺省按扩展名推断。
- `app_api get_gallery` 返回：`type=live`、`live_video_url`、`thumb_url`（静态图）、`uploader_name`。
- `save_gallery` 接受 `is_live=1` + `live_video` 文件；也接受原始 Motion Photo 自动提取；失败退回静态并返回 `live_warning`。
- `check_version` 返回字段见 §4 S2a。
- `schema.json`：`{version, applied_at}`；当前迁移 001。
- 维护/更新文件：`web/data/.maintenance`、`web/data/.update/status.json`、`web/data/backups/<timestamp>/`（JSON/config/代码 zip）。

---

## 7. 已知问题 / 风险

1. **实况照片未真机验收**：用户手机不支持动态照片。需要一台支持 Motion Photo 的 Android 设备，或提供真实 Motion Photo JPEG（XMP `GCamera:MicroVideoOffset`）测试。`motion_photos` 检测/上传代码只过了 analyze/构建。
2. **一键更新网络未完整演练**：开发机 GitHub 下载 ~25 KB/s，37 MB 包会长时间占维护模式；已改 17.4 MB 精简包 + 1800s 超时。用户网络下需再验一次；失败可用 CLI `apply_update.php`。
3. **`update_status.php` 鉴权修复在 `01d214e`，未进 `web-v2.0.2` tag**：部署 2.0.2 前需发 2.0.3 或手动应用；否则状态接口匿名可读（含本机备份路径）。
4. **Flutter/Gradle 版本**：Flutter 3.50.1 要求 Gradle >=9.3.1；项目 9.1.0，靠 `--android-skip-build-dependency-validation` 出包。建议升级 Gradle 到 9.3.1 后移除 flag，并固定 Flutter 版本/提交 `pubspec.lock`。
5. **`flutter analyze` 存量 warning/info 多**：CI 用 `--no-fatal-warnings --no-fatal-infos`，只阻断 error；不要为了清 warning 大改无关代码。
6. **`web/data/app_versions.json` 本地不存在**：后台“当前线上版本”可能显示 1.0；`check_version` 会回退到 `LW_MIN_APP_VERSION`（2.0.0）。正式部署建议在后台“发布版本”发布 2.0.0，或创建该文件。
7. **Web 更新包不含 `web/docs/images` 与 `*.docx`**：更新不会刷新这些文档资源；改了文档截图/Word 需手动覆盖或用完整源码包。
8. **备份保留策略未做**：`web/data/backups` 每次更新约 31 MB；当前不自动清理，建议后续保留最近 5 份。
9. **`proc_open` 依赖**：迁移 CLI 用 `proc_open`；PHP-FPM 若禁用，一键更新会失败并保留维护模式。此时用 `web/bin/apply_update.php`（CLI）或手动复制。
10. **CA bundle**：`web/inc/cacert.pem` 是 Mozilla CA；如 GitHub 证书链变化，更新该文件。
11. **HTTP 明文**：用户已接受；后台常驻红字警告。若以后切 HTTPS，反代需传 `X-Forwarded-Proto/Host`，且 `trusted_proxies` 只信任 loopback/配置 IP。
12. **`web-v2.0.2` Release 的 zip 不含 `01d214e`**（见 3）。
13. **旧 fat APK**：`apk-latest` 仍有 `app-release.apk`（1.2.2），下载页不再引用；如介意可删除该 asset。
14. **本机 `runtime/cloudflared.exe`、`runtime/https-proxy.js`**：测试用，gitignored；`https-proxy.js` 是自签 HTTPS 测试反代，不是生产方案。
15. **网络**：GitHub push/tag/CI 经常连接重置；命令需重试。`gh` 已登录。

---

## 8. 下一步路线（原设计 S3–S6）

> 用户问的“放开限制”和“管理员侧边栏”对应下面 S4/S5/S6。

### S3 刷新 / 定位 / 搜索 / 任务

- `get_rev` 轻量指纹（words/tasks/wrong/gallery），10 秒轮询只拉变化区块。
- APP：去掉 `_autoLocated` 一次性标记；每次进学习 Tab/切类型/切班级都定位；搜索点击后跳转并定位；切班级清状态；画廊刷新合并不 reset；错题本/任务独立 rev。
- Web：分区刷新、搜索高亮/定位收口。
- 空任务/错题本已在 S0 做了基础修复，S3 继续补轮询与 UI。

### S4 史记 / 管理后台（**管理员侧边栏在这里**）

- 管理后台改为左侧 sidebar（总览/班级/内容/系统），保留现有 `#sec-*` 深链；公告编辑/预览/多班级；图集回收站入口；存储/备份/审计。
- 史记 10 秒自动保存、图片压缩、正文上限 2 MB、草稿/断网兜底。
- 公网 HTTPS 文档（用户已选 HTTP，文档保留切换方案）。
- **当前后台只有顶部锚点导航 + “系统更新”卡片，还没有 sidebar。**

### S5 图集 Web（**“放开限制”在这里**）

- 分片上传（8 MB/片，1 GB 视频硬上限；图片建议 15 MB、硬上限 100 MB）。
- 服务端 ffmpeg 压缩 worker + 前后对比 + 原图保留 7 天。
- 回收站 `gallery_trash.json` + `trash/` 7 天；后台恢复/彻底删除。
- 瀑布流、排序 asc/desc、描述搜索 + 定位；展示页移除图集轮播。
- 当前限制仍是：图 12 MB、GIF 16 MB、MP4 15 MB/30 秒；**尚未放开**。

### S6 图集 APP 对齐

- APP 单/多文件、预览、压缩、上传者、排序、瀑布流、搜索定位、删除/回收站入口、缓存上限。
- 与 Web S5 对齐后再发 APP 版本。

---

## 8.1 如果换机器 / 换 Agent（非常重要）

仓库**不包含**运行时数据、配置和 PHP/ffmpeg 运行时。换机器时必须从旧机器复制：

- `web/data/`（`classes.json`、`settings.json`、`announcements.json`、`app_versions.json`、`users/`、`classes/`、`uploads/` 等）
- `web/inc/config.php`（`admin_password_hash`、`app_secret`；**不要提交**）
- `runtime/`（便携 PHP + ffmpeg；或在目标机重新运行 `scripts\install.bat`）
- 可选：`dist/` 里的测试 APK、`offline/` 离线包

同时需要：

- 管理员密码**私下**交给新 Agent（不要写进仓库）；忘记可运行 `php scripts/rotate-secrets.php`。
- 新机器安装 GitHub CLI 并 `gh auth login`，确认有仓库写权限；Actions Secrets（`API_BASE_URL`、`KEYSTORE_BASE64`、`KEY_STORE_PASS`、`KEY_KEY_PASS`、`KEY_ALIAS`）已在仓库设置里，不要在本地重建 keystore。
- 新 Agent 先 `git switch feat/2.0 && git pull`，确认 `HANDOVER-2.0-AGENT.md` 在；启动 `dev.ps1 start -NoThumbs`；访问 `/version.php`、`/admin.php`、图集，确认数据在。
- 如果只换 Agent、不换机器：直接在当前目录继续，先 `dev.ps1 status`，不要重复 `install.bat`。

---

## 9. 交接检查清单（下一位 Agent 开始前）

- [ ] `git switch feat/2.0 && git pull`；确认 `git status` 只剩用户本地 `HANDOVER.md`/`README.md`/`scripts/dev.ps1`。
- [ ] 读 `docs/superpowers/specs/2026-11-20-v2-design.md`、`docs/superpowers/plans/2026-11-20-s0-s1-...md`、`docs/superpowers/plans/2026-11-20-s2-...md`、`docs/superpowers/plans/2026-11-20-s2b-...md`。
- [ ] 启动 `dev.ps1 start -NoThumbs`，确认 `http://127.0.0.1:8000/version.php` 返回 2.0.2（或更新）。
- [ ] 跑 PHP 测试（含 `-d extension=zip`）。
- [ ] 确认后台可登录、`Web v2.0.2`、“系统更新”卡片可见。
- [ ] 确认 `web/data/.maintenance` 不存在；`web/data/backups` 只保留需要的备份。
- [ ] 确认 `web/data/app_versions.json` 是否要发布 2.0.0（否则后台显示 1.0）。
- [ ] 与用户确认：先做 S3 还是先做 S4（侧边栏）/S5（图集限制）。
- [ ] 实况照片真机验收：等用户有支持设备或提供真实样本；不要在没有设备时声称已验证。

---

## 10. 给下一位 Agent 的建议

- 继续用 **Serena** 做 PHP 符号级编辑；Dart 没有 LSP，正常读写即可。
- 继续用 **Superpowers** 的 brainstorming → design/plan → executing 流程；本文件不是新设计，只是交接。
- 先做 **S3** 或用户指定的优先级；不要一次把 S3–S6 全铺开。
- 每次改 Web 版本：改 `web/inc/version.php`，打 `web-vX.Y.Z` tag；APP 改 `pubspec.yaml` + `api_config.example.dart` + `build.yml` 生成值，打 `app-vX.Y.Z` tag。
- 发布前检查：`app_versions.json` 的 latest/min、`download_app.php` 双 ABI、`apk-latest` 资产、Web 更新包 sha、备份/维护模式。
- 重要用户决策：HTTP 已接受；1 GB/分片、压缩、回收站、侧边栏、10 秒刷新尚未做；实况照片真机待验。

---

## 11. 参考链接

- 仓库：https://github.com/CH-Hu-Bill/MoXie
- Releases：https://github.com/CH-Hu-Bill/MoXie/releases
- APP 2.0.0：https://github.com/CH-Hu-Bill/MoXie/releases/tag/app-v2.0.0
- Web 2.0.2：https://github.com/CH-Hu-Bill/MoXie/releases/tag/web-v2.0.2
- 下载页：`http://<本机IP>:8000/download_app.php`
- 设计文档：`docs/superpowers/specs/2026-11-20-v2-design.md`
