# ListenWrite · 默写史记

班级默写 + 班级史记应用。本仓库为 **monorepo**，包含 Web 端（PHP 后端 + 浏览器前端）与 APP 端（Flutter Android 客户端）。

- **APP 名称**：ListenWrite　**Android 包名**：`billspace.listenwrite.flutter`　**当前版本**：`1.0.17`
- 历史版本与更新说明见 [app/README.md](app/README.md) 与 Web 端 `data/app_versions.json`
- **开源协议**：[GPL-3.0](LICENSE)
- **本应用为本地运行设计**：原线上服务器已下线，请在本地电脑启动服务，内网设备通过局域网访问。

## 仓库结构

```
.
├── web/                          # Web 端 + 后端 API（PHP + JSON 文件存储，无数据库）
├── app/myapp/                    # Flutter APP 客户端（Android）
├── dev.sh                        # 开发机（Linux/macOS）一键启动
├── install.bat / install.ps1     # Windows 用户一键安装（PHP+ffmpeg+自启）
├── update.bat / update.ps1       # Windows 一键更新
├── uninstall.bat                 # 移除开机自启
├── run.bat / run-hidden.vbs      # 手动 / 隐藏启动服务
├── .github/workflows/build.yml   # CI: 自动构建 APK 并发布到 Release
└── LICENSE
```

## 模块说明

| 目录 | 说明 | 文档 |
|------|------|------|
| [`web/`](web/) | 网站端 + APP 全部后端 API。PHP 7.4+，GD，cURL，JSON 文件存储 | [web/README.md](web/README.md) |
| [`app/myapp/`](app/myapp/) | Flutter APP 客户端，通过 `app_api.php` 与后端交互 | [app/myapp/README.md](app/myapp/README.md) |

## 快速开始

### 方式一：开发机（Linux / macOS）

```bash
./dev.sh            # 监听 0.0.0.0:8000，自动打印内网访问地址
# 浏览器打开 http://127.0.0.1:8000 或 http://<内网IP>:8000
```

首次会在 `web/inc/config.php` 生成本地配置（已被 gitignore）。

### 方式二：Windows 用户（一键）

1. 获取本仓库代码（`git clone` 或下载 ZIP）
2. 双击 **`install.bat`**（自动请求管理员权限）：
   - 自动安装便携 PHP 8.2 与 ffmpeg 到 `runtime/`
   - 生成 `web/inc/config.php`
   - 注册**开机自启**计划任务并启动服务
   - 控制台会打印内网访问地址（如 `http://192.168.x.x:8000`）
3. 其它设备连同一局域网，用该地址访问即可
4. 更新代码：双击 `update.bat`；卸载自启：`uninstall.bat`

> 部署、安全、数据存储、API 文档详见 [web/README.md](web/README.md)。

## APP 端构建

APP 端通过 GitHub Actions 自动构建 APK，无需本地 Flutter 环境。

1. 在**你自己的 fork/仓库** Settings → Secrets → Actions 添加 `API_BASE_URL`，值为你的后端地址（如 `http://192.168.1.10:8000`，不带尾部斜杠、不带 `/app_api.php`）
2. 推送代码到 `main` 分支（改动 `app/myapp/**` 触发），或在 Actions 页面手动 Run workflow
3. 构建完成后在仓库 **Releases** 页下载 `listenwrite-release.apk`

> 如果未设置 `API_BASE_URL` Secret，CI 会使用 `api_config.example.dart` 中的本地地址（`127.0.0.1:8000`）。

详见 [app/myapp/README.md](app/myapp/README.md)。

## 技术栈

| 模块 | 技术 |
|------|------|
| Web 后端 | PHP 7.4+，GD，cURL，JSON 文件存储（无数据库）；Composer 仅用于 php-ffmpeg（可选，视频首帧缩略图） |
| Web 前端 | 原生 HTML/CSS/JS，Quill 2.x 富文本编辑器（本地），按班级自定义 AI（OpenAI 兼容 / Anthropic） |
| APP | Flutter（Dart 3.5+），Provider，flutter_quill，cached_network_image |
| APP 字体 | ZCOOL KuaiLe + Ma Shan Zheng（打包到 APK；Web 端自托管于 `web/fonts/`） |
| APP 音频 | just_audio（有道词典 TTS 在线发音） |
| CI/CD | GitHub Actions（Flutter stable + Java 17） |

## 数据存储

```
data/
├── classes.json              # 全局班级注册表
├── settings.json             # 全局设置（含按班级的 AI 配置 ai_{classId}）
├── app_versions.json         # APP 版本发布记录
├── announcements.json        # 全服公告
├── ratelimit.json            # 限流计数
├── users/                    # APP 用户数据（每个用户独立文件）
└── classes/                  # 班级数据（words/tasks/history/gallery/...）
```

> `web/data/` 为运行时数据，已被 gitignore，仅保留 `.htaccess`（本地由 `router.php` 保护）。

## 安全

- `web/inc/config.php`（含密钥）→ `.gitignore` 忽略，不入库
- `web/data/` 运行时数据 → 忽略；`router.php` 拦截直链
- `app/myapp/lib/config/api_config.dart`（含服务器地址）→ 忽略
- 部署安全加固与数据说明见 [web/README.md](web/README.md)
