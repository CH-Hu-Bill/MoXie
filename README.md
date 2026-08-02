# ListenWrite

班级默写 + 班级史记应用。本仓库为 **monorepo**，包含 Web 端与 APP 端两部分。

## 仓库结构

```
.
├── web/            # Web 端 + 后端 API（PHP + JSON 文件存储，无数据库）
├── app/myapp/      # Flutter APP 客户端（Android）
├── .github/
│   └── workflows/
│       └── build.yml  # CI: 自动构建 APK 并上传 Artifact
└── 风格.md          # 设计规范（Hand-Drawn 手绘风格）
```

## 模块说明

| 目录 | 说明 | 文档 |
|------|------|------|
| [`web/`](web/) | 网站端（浏览器使用）+ APP 全部后端 API。基于 PHP，数据存于 JSON 文件 | [web/README.md](web/README.md) |
| [`app/myapp/`](app/myapp/) | Flutter APP 客户端，通过 `app_api.php` 与后端交互 | [app/myapp/README.md](app/myapp/README.md) |
| [`风格.md`](风格.md) | 设计规范（Hand-Drawn 手绘风格） | — |

## 快速开始

### Web 端部署

```bash
cd web
cp inc/config.example.php inc/config.php   # 填入真实密钥
# 将 web/ 配置为 Web 服务器文档根目录
```

详细部署、配置、API、安全机制见 [web/README.md](web/README.md)。

### APP 端构建

APP 端通过 GitHub Actions 自动构建 APK，无需本地 Flutter 环境。

1. 在仓库 **Settings → Secrets → Actions** 添加 `API_BASE_URL`，值为后端地址（如 `https://example.com`，不带尾部斜杠）
2. 推送代码到 `main` 分支（改动 `app/myapp/**` 路径下文件时触发）
3. 在 **Actions** 页面下载构建好的 APK Artifact

详见 [app/myapp/README.md](app/myapp/README.md)。

## 技术栈

- **Web/后端**：PHP 7.4+，GD，cURL，JSON 文件存储（无数据库）
- **APP**：Flutter（Dart），Provider 状态管理，google_fonts + http + image_picker

## 安全

- `web/inc/config.php`（真实密钥）已被 `.gitignore` 忽略，不入库
- `web/data/` 运行时数据已被忽略，仅保留 `.htaccess` 安全配置
- `app/myapp/lib/config/api_config.dart`（含服务器地址）已被 `.gitignore` 忽略；CI 通过 GitHub Secret `API_BASE_URL` 动态生成
- 部署前请修改默认管理员密码与 `app_secret`，详见 web 端文档
