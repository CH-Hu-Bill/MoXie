# ListenWrite

班级默写 + 班级史记应用。本仓库为 **monorepo**，包含 Web 端与 APP 端两部分。

## 仓库结构

```
.
├── web/   # Web 端 + 后端 API（PHP + JSON 文件存储，无数据库）
└── app/   # APP 客户端源码（Android / iOS，待补充）
```

## 模块说明

| 目录 | 说明 | 文档 |
|------|------|------|
| [`web/`](web/) | 网站端（浏览器使用）+ APP 全部后端 API。基于 PHP，数据存于 JSON 文件 | [web/README.md](web/README.md) |
| [`app/`](app/) | APP 客户端源码占位，后续补充 | [app/README.md](app/README.md) |

## 快速开始

### Web 端部署

```bash
cd web
cp inc/config.example.php inc/config.php   # 填入真实密钥
# 将 web/ 配置为 Web 服务器文档根目录
```

详细部署、配置、API、安全机制见 [web/README.md](web/README.md)。

### APP 端

源码待补充。APP 通过 `web/app_api.php` 与后端交互，接口契约见 web 端文档的「APP API 说明」章节。

## 技术栈

- **Web/后端**：PHP 7.4+，GD，cURL，JSON 文件存储（无数据库）
- **APP**：待定（见 `app/` 目录）

## 安全

- `web/inc/config.php`（真实密钥）已被 `.gitignore` 忽略，不入库
- `web/data/` 运行时数据已被忽略，仅保留 `.htaccess` 安全配置
- 部署前请修改默认管理员密码与 `app_secret`，详见 web 端文档
