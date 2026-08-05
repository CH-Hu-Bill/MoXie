# ListenWrite · 默写史记

班级默写 + 班级史记应用。本仓库为 **monorepo**，包含 Web 端（PHP 后端 + 浏览器前端）与 APP 端（Flutter Android 客户端）。

- **APP 名称**：ListenWrite　**Android 包名**：`billspace.listenwrite.flutter`　**当前版本**：`1.0.2`
- 历史版本与更新说明见 [app/README.md](app/README.md) 与 Web 端 `data/app_versions.json`

## 仓库结构

```
.
├── web/                          # Web 端 + 后端 API（PHP + JSON 文件存储，无数据库）
├── app/myapp/                    # Flutter APP 客户端（Android）
├── .github/workflows/build.yml   # CI: 自动构建 APK
└── README.md
```

## 模块说明

| 目录 | 说明 | 文档 |
|------|------|------|
| [`web/`](web/) | 网站端 + APP 全部后端 API。PHP 7.4+，GD，cURL，JSON 文件存储 | [web/README.md](web/README.md) |
| [`app/myapp/`](app/myapp/) | Flutter APP 客户端，通过 `app_api.php` 与后端交互 | [app/myapp/README.md](app/myapp/README.md) |

## 快速开始

### Web 端部署

```bash
cd web
cp inc/config.example.php inc/config.php   # 填入真实密钥
# 将 web/ 配置为 Web 服务器文档根目录
```

首次部署时，旧版 `app_data.json` 会在首次 API 请求时自动迁移为 `data/users/{uid}.json` + `tokens.json` 结构，旧文件重命名为 `app_data.json.bak`。

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
| APP | Flutter（Dart 3.5+），Provider 状态管理，flutter_quill 富文本编辑器，cached_network_image 图片缓存 |
| APP 字体 | ZCOOL KuaiLe（标题）+ Ma Shan Zheng（正文），打包到 APK |
| APP 音频 | just_audio（有道词典 TTS 在线发音） |
| APP 图标 | 自定义图标，多密度 mipmap（48~192px） |
| CI/CD | GitHub Actions（Flutter stable + Java 17） |

## 数据存储

```
data/
├── classes.json              # 全局班级注册表
├── settings.json             # 全局设置
├── app_versions.json         # APP 版本发布记录
├── exports.json + exports/   # 临时导出文件
├── ratelimit.json            # 限流计数
├── users/                    # 用户数据（每个用户独立文件）
│   ├── u1.json
│   ├── u2.json
│   └── tokens.json           # 登录令牌 + next_uid
└── classes/                  # 班级数据
    └── {classId}/
        ├── words.json
        ├── tasks.json
        ├── history.json
        ├── gallery.json
        ├── personal_history_{uid}.json
        └── uploads/
```

## 安全

- `web/inc/config.php`（真实密钥）→ `.gitignore` 忽略，不入库
- `web/data/` 运行时数据 → 忽略，仅保留 `.htaccess`
- `app/myapp/lib/config/api_config.dart`（含服务器地址）→ 忽略；CI 通过 GitHub Secret `API_BASE_URL` 动态生成
- 部署安全加固指南见 [web/README.md](web/README.md) 的「部署安全加固」章节
