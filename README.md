# ListenWrite · 默写史记

班级默写 + 班级史记应用。本仓库为 **monorepo**，包含 Web 端（PHP 后端 + 浏览器前端）与 APP 端（Flutter Android 客户端）。

## 仓库结构

```
.
├── web/                          # Web 端 + 后端 API（PHP + JSON 文件存储，无数据库）
├── app/myapp/                    # Flutter APP 客户端（Android）
├── .github/workflows/build.yml   # CI: 自动构建 APK
├── 风格.md                        # 设计规范（Hand-Drawn 手绘风格）
└── README.md
```

## 模块说明

| 目录 | 说明 | 文档 |
|------|------|------|
| [`web/`](web/) | 网站端 + APP 全部后端 API。PHP 7.4+，GD，cURL，JSON 文件存储 | [web/README.md](web/README.md) |
| [`app/myapp/`](app/myapp/) | Flutter APP 客户端，通过 `app_api.php` 与后端交互 | [app/myapp/README.md](app/myapp/README.md) |
| [`风格.md`](风格.md) | 设计规范（Hand-Drawn 手绘风格） | — |

## 快速开始

### Web 端部署

```bash
cd web
cp inc/config.example.php inc/config.php   # 填入真实密钥
# 将 web/ 配置为 Web 服务器文档根目录
```

详细部署、安全加固、API 文档见 [web/README.md](web/README.md)。

### APP 端构建

APP 端通过 GitHub Actions 自动构建 APK，无需本地 Flutter 环境。

1. 在仓库 **Settings → Secrets → Actions** 添加 `API_BASE_URL`，值为后端地址（如 `http://moxie.billspace.top`，不带尾部斜杠，不带 `/app_api.php`）
2. 推送代码到 `main` 分支（改动 `app/myapp/**` 路径下文件时触发），或在 Actions 页面手动 Run workflow
3. 构建完成后在 run 详情页底部 Artifacts 下载 `listenwrite-release.apk`

> 如果未设置 `API_BASE_URL` Secret，CI 会使用 `api_config.example.dart` 中的本地地址（`127.0.0.1:8000`），仅适合本机调试。

详见 [app/myapp/README.md](app/myapp/README.md)。

## 技术栈

| 模块 | 技术 |
|------|------|
| Web 后端 | PHP 7.4+，GD，cURL，JSON 文件存储（无数据库，无 Composer） |
| Web 前端 | 原生 HTML/CSS/JS，Quill 2.x 富文本编辑器，DeepSeek API（AI 补全） |
| APP | Flutter（Dart 3.5+），Provider 状态管理，flutter_quill 富文本编辑器 |
| APP 字体 | ZCOOL KuaiLe（标题）+ Ma Shan Zheng（正文），打包到 APK |
| APP 音频 | just_audio（有道词典 TTS 在线发音） |
| APP 图标 | 自定义图标，多密度 mipmap（48~192px） |
| CI/CD | GitHub Actions（Flutter stable + Java 17） |

## 设计系统

两端统一采用 **Hand-Drawn 手绘风格**，详见 [`风格.md`](风格.md)。核心要素：

| 要素 | 值 |
|------|-----|
| 背景色 | `#fdfbf7`（暖纸白） |
| 文字色 | `#2d2d2d`（铅笔黑） |
| 强调色 | `#ff4d4d`（红色修正笔） |
| 次强调色 | `#2d5da1`（蓝色圆珠笔） |
| 便签色 | `#fff9c4`（便利贴黄） |
| 阴影 | 硬偏移无模糊：`4px 4px 0px 0px #2d2d2d` |
| 圆角 | 不规则 wobbly：`255px 15px 225px 15px / 15px 225px 15px 255px` |
| 纸张纹理 | 点阵：`radial-gradient(#e5e0d8 1px, transparent 1px)`，间距 24px |

## 安全

- `web/inc/config.php`（真实密钥）→ `.gitignore` 忽略，不入库
- `web/data/` 运行时数据 → 忽略，仅保留 `.htaccess`
- `app/myapp/lib/config/api_config.dart`（含服务器地址）→ 忽略；CI 通过 GitHub Secret `API_BASE_URL` 动态生成
- 部署安全加固指南见 [web/README.md](web/README.md) 的「部署安全加固」章节
