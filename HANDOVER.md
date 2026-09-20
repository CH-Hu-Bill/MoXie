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
8. **发布**：见上。当前版本 **1.2.1**。

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
