# ListenWrite · 网站端（WangZhan）

班级默写 + 班级史记的 Web 应用。基于 **PHP + JSON 文件存储**（无数据库），通过浏览器使用；同时为同名 APP 提供 REST 风格的后端 API。

> 本仓库包含 **Web 端 / 后端** 与 **APP 客户端**（Flutter）完整源码。

---

## 目录

- [功能概览](#功能概览)
- [技术栈与运行要求](#技术栈与运行要求)
- [目录结构](#目录结构)
- [部署指南](#部署指南)
- [部署安全加固](#部署安全加固)
- [核心入口说明](#核心入口说明)
- [数据存储说明](#数据存储说明)
- [APP API 说明](#app-api-说明)
- [安全机制](#安全机制)
- [配置项详解](#配置项详解)

---

## 功能概览

| 模块 | 说明 |
|------|------|
| **班级管理** | 创建 / 选择 / 切换 / 删除班级；班级口令保护（`password_hash` + 版本号控制，改口令即让所有设备失效） |
| **单词库** (`words.php`) | 单词 CRUD、TTS 发音、AI 批量导入（粘贴单词列表 → DeepSeek 自动补全释义/词性 → 预览确认）、CSV 导入 / 导出 |
| **默写任务** (`task.php`) | 三种模式：**看词**(show) / **默写**(hide) / **听写**(dict)；听写状态机（音量/间隔/朗读次数可调，支持暂停）；短按完成、长按取消 |
| **默写记录** (`history.php`) | 历史任务列表（按日期倒序）、单词详情、一键重新创建任务 |
| **周末大礼包** | 周末从本周已默写单词中随机抽 20 个组成加练任务，周一随机决定本周是否开启 |
| **班级史记** (`history_book.php`) | Vlog 风格日记，Quill 富文本编辑器 + 月历导航；仅今日可编辑（带时钟容差，详见下文）；个人列传（需授权）；支持 PDF / HTML / 长图导出 |
| **班级图集** (`gallery.php` / `gallery_api.php`) | 图片上传 + 画廊展示；提供公开分页 API（基于 IP + 日期轮换排序，防同设备重复） |
| **设置** (`settings.php`) | 听写 / 朗读 / 跟读参数，按班级隔离存储 |
| **管理后台** (`admin.php`) | 班级删除（级联清理）、重置班级口令、APP 版本发布（含渠道/日志）、**全服公告管理**（内容/颜色/班级/平台/时间/可关闭） |
| **全服公告** | 管理员发布的公告按班级 + 平台 + 时间段投送；Web 端嵌入顶部状态栏跑马灯（可关闭，存 localStorage）；APP 端顶部横幅显示（可关闭，存 SharedPreferences）。后台时间选择已预填服务器当前时间（默认立即生效），并醒目显示服务器时间 |
| **APP 后端 API** (`app_api.php`) | 用户注册 / 登录 / token 鉴权、单词 / 任务 / 错题本 / 收藏、史记、图集、导出等完整接口 |

---

## 技术栈与运行要求

| 项 | 要求 |
|----|------|
| PHP | **7.4+**（使用 `password_hash` / `random_bytes` / `mb_*` 等） |
| PHP 扩展 | **GD**（图片安全处理与缩放）、**cURL**（调用 DeepSeek AI） |
| Web 服务器 | Nginx / Apache，文档根指向本目录 |
| 数据库 | 无，使用 JSON 文件存储（`inc/db.php` 封装，原子写入 + 文件锁） |
| 前端依赖 | 原生 HTML/CSS/JS；**Hand-Drawn 设计系统**（手绘风格，纸纹理背景，wobbly 不规则边框，硬阴影）；[ZCOOL KuaiLe](https://fonts.google.com/specimen/ZCOOL+KuaiLe) + [Ma Shan Zheng](https://fonts.google.com/specimen/Ma+Shan+Zheng) 中文字体；富文本编辑器 [Quill 2.x](https://quilljs.com)（CDN 引入） |
| 外部服务 | [DeepSeek API](https://platform.deepseek.com)（AI 导入单词、名言翻译；可选，不配置则相关功能不可用） |

> 无需 Composer。项目通过 `require_once` 手动加载，无第三方 PHP 依赖包。

---

## 目录结构

```
.
├── index.php              # 入口：班级选择 / 创建 / 口令验证
├── main.php               # 功能主页（卡片网格 + 统计 + 名言 + 周末大礼包）
├── words.php              # 单词库（CRUD / AI 导入 / CSV）
├── task.php               # 默写任务（看词/默写/听写）
├── history.php            # 默写记录
├── history_book.php       # 班级史记（Vlog 日记 + 导出）
├── gallery.php            # 班级图集（上传 / 画廊）
├── gallery_api.php        # 图集公开 API
├── settings.php           # 听写 / 朗读 / 跟读设置
├── admin.php              # 管理后台
├── app_api.php            # APP 后端 API（全部接口）
├── upload.php             # 图片上传 / 查看（GD 安全处理）
├── download.php           # 导出文件下载（token + 自动 GC）
├── common.css             # 公共样式（CSS 变量设计令牌、组件系统、Hand-Drawn 风格）
├── common.js              # 公共脚本（TTS / Toast / 跟读 / 页面过渡 / 跑马灯等）
├── favicon.png            # 网站图标（与 APP 图标一致）
├── 风格.md                # 设计规范（Hand-Drawn 手绘风格）
├── shiyin.mp3             # 任务提示音
├── inc/                   # 核心库
│   ├── head.php           # 统一 HTML <head>（Google Fonts、meta、common.css）
│   ├── header.php         # 统一状态栏（返回按钮、班级名、标题、右侧操作区）
│   ├── db.php             # JSON 存储层（原子写 + 文件锁 + 班级数据隔离）
│   ├── security.php       # CSRF / 班级鉴权（HMAC 签名 Cookie）/ token
│   ├── api.php            # DeepSeek API 封装
│   ├── history.php        # 史记：HTML 消毒 / 导出渲染 / 图片清理
│   ├── app_auth.php       # APP 用户 / token / 班级绑定鉴权
│   ├── ratelimit.php      # 持久限流器（滑动窗口）
│   ├── config.php         # 真实配置（被 .gitignore 忽略，不入库）
│   └── config.example.php # 配置模板（入库）
├── data/                  # 运行时数据（被 .htaccess 保护，禁止 Web 直链）
│   ├── .htaccess          # Deny all
│   └── uploads/.htaccess  # Deny all（图片经 upload.php 控制访问）
└── project-features/
    └── project-features.html  # 功能总览文档
```

---

## 部署指南

1. **上传代码**：将本目录内容上传到服务器网站根目录。

2. **创建配置文件**：复制模板并填入真实密钥
   ```bash
   cp inc/config.example.php inc/config.php
   ```
   编辑 `inc/config.php`，至少修改：
   - `deepseek.api_key` — 你的 DeepSeek API 密钥
   - `admin_password` — 管理后台密码
   - `app_secret` — 至少 32 位随机字符串（用于 Cookie 签名和 token 校验）
   - `allowed_origins` — 生产环境建议改为你的域名列表

3. **权限**：确保 `data/` 目录可写（Web 进程用户可读写创建文件）。
   ```bash
   chmod -R 755 data/
   ```

4. **Web 服务器配置**：将文档根指向本目录，并**配置安全规则**（这是部署中最关键的一步，详见下方 [部署安全加固](#部署安全加固)）。最小 Nginx 示例：
   ```nginx
   server {
       listen 443 ssl;
       server_name example.com;
       root /var/www/listenwrite;
       index index.php;

       location ~ \.php$ {
           fastcgi_pass unix:/run/php/php-fpm.sock;
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }
   }
   ```

5. **访问**：
   - Web 端：`https://你的域名/`
   - 管理后台：`https://你的域名/admin.php`
   - APP 接口：`https://你的域名/app_api.php`

---

## 部署安全加固

> ⚠️ **这是部署时必须完成的步骤。** 源码层面无法阻止工具（如 wget/HTTrack）爬取数据文件，以下配置由 Web 服务器在请求入口拦截。

### Nginx 完整安全配置

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/listenwrite;
    index index.php;

    # ---- 基础安全头 ----
    add_header X-Content-Type-Options  "nosniff" always;
    add_header X-Frame-Options         "DENY" always;
    add_header X-XSS-Protection        "1; mode=block" always;
    add_header Referrer-Policy         "no-referrer" always;
    add_header Permissions-Policy      "camera=(), microphone=(), geolocation=()" always;

    # ---- 禁止访问敏感目录和文件 ----
    # data/ 目录（所有 JSON 数据 + 上传图片）
    location /data/        { deny all; }

    # inc/ 目录（PHP 源码，防止源码泄露）
    location /inc/         { deny all; }

    # 配置文件（即使 .php 会被解析，再加一层保险）
    location ~ /inc/config\.php$ { deny all; }

    # 禁止下载 .json 文件（即使不在 data/ 下）
    location ~ \.json$     { deny all; }

    # 禁止直接访问隐藏文件（.htaccess / .git / .env 等）
    location ~ /\.         { deny all; }

    # 禁止目录浏览
    autoindex off;

    # ---- PHP 处理 ----
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # 隐藏 PHP 版本
        fastcgi_hide_header X-Powered-By;
    }

    # ---- 限流（防暴力破解） ----
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
    location = /admin.php {
        limit_req zone=login burst=3 nodelay;
        # admin.php 本身也是 PHP，需要交给 fastcgi
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location = /app_api.php {
        limit_req zone=login burst=10 nodelay;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Apache (.htaccess)

项目已自带 `data/.htaccess`（`Deny all`）。建议在网站根目录额外添加或修改：

```apache
# 禁止访问 inc/ 目录（防止 PHP 源码泄露）
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^inc/ - [F,L]
</IfModule>

# 禁止直接访问 .json 文件
<FilesMatch "\.json$">
    Require all denied
</FilesMatch>

# 隐藏 PHP 版本
<IfModule mod_headers.c>
    Header unset X-Powered-By
</IfModule>

# 禁止目录浏览
Options -Indexes
```

### 其他建议

| 措施 | 说明 |
|------|------|
| **HTTPS 强制** | 全站 HTTPS，配合 HSTS（`Strict-Transport-Security` header） |
| **Fail2ban** | 监控 PHP 错误日志和 403 响应，自动封禁异常 IP |
| **文件权限** | `data/` 目录 `chmod 700`，`inc/config.php` `chmod 600`，Web 进程用户只读 |
| **PHP 配置** | `expose_php = Off`，`display_errors = Off`（生产环境），`open_basedir` 限制到网站目录 |
| **定期备份** | `data/` 目录定期备份（rsync/cron），这是唯一的数据存储 |
| **WAF** | 可选 Cloudflare 免费计划，自带 DDoS 防护和恶意爬虫拦截 |

### 时间与时区处理（重要）

服务器 PHP 时区统一为 `Asia/Shanghai`（`inc/db.php` 中 `date_default_timezone_set`）。所有"今日"判定、公告时间段、任务日期均以**服务器系统时钟**为准。

> **⚠️ 务必保证服务器系统时钟准确**：未启用 NTP 同步时，服务器时钟会漂移。若服务器比用户设备慢（如慢 50 分钟），会出现：
> - 管理员发布"立即生效"的公告，实际要等服务器时钟走到开始时间才显示；
> - 客户端跨午夜后认定的"今天"与服务器不一致，导致"只能保存今天的记录"被误拒绝。
>
> **校准命令**（Debian/Ubuntu + systemd）：
> ```bash
> timedatectl set-ntp true
> timedatectl status        # 确认 "System clock synchronized: yes"
> date                      # 确认当前时间准确
> ```

为容忍轻微的时钟偏差（如跨午夜），史记"仅今日可编辑"判定使用 `historyIsEditableDate()`（`inc/history.php`）：
- 服务器的"今天" → 可编辑；
- 服务器的"昨天"（服务器时间 03:00 前）→ 可编辑（容忍客户端时钟快于服务器）；
- 服务器的"明天"（服务器时间 21:00 后）→ 可编辑（容忍客户端时钟慢于服务器）。

Web 端日历的"今天"以**浏览器本机时钟**计算（与 APP 端手机时钟一致），保存校验在服务器端以 `historyIsEditableDate()` 兜底。公告后台的时间选择框已预填服务器当前时间，并醒目显示服务器时间，避免因设备时钟偏差导致"发布后不显示"。

**公告时间段比较使用 `strtotime()` 时间戳**（`inc/header.php` / `app_api.php` / `admin.php`），对 `2026-08-06 11:54` 与旧版 `2026-08-06T08:00`（含 T）格式均能正确解析，损坏的时间数据会被安全跳过。

---

## 核心入口说明

| 文件 | 作用 | 关键 URL 参数 |
|------|------|--------------|
| `index.php` | 班级选择 / 创建 / 口令验证；Cookie 记忆上次班级 | `?switch=1` 强制切换；`?need_auth={id}` 触发口令弹窗 |
| `main.php` | 功能主页：卡片入口、统计、随机名言（可 AI 翻译）、周末大礼包 | `?id={classId}` |
| `words.php` | 单词库：卡片网格、选中建任务、AI 批量导入、CSV | `?id={classId}` |
| `task.php` | 默写任务：列表视图 + 执行视图（看词/默写/听写） | `?id={classId}` `?task_id={taskId}` |
| `history.php` | 默写记录：历史任务、重新创建 | `?id={classId}` `?task_id={taskId}` |
| `history_book.php` | 班级史记：月历 + Quill 编辑器 + 导出 | `?id={classId}` |
| `app_api.php` `get_announcements` | 获取当前有效公告（按班级 + 平台 + 时间段筛选） | POST `class_id` `platform` |
| `gallery.php` | 图集：上传 / 删除 / 画廊 | `?id={classId}` |
| `gallery_api.php` | 图集公开 API | `?class_id={id}` `?page=` `?per_page=` `?apikey=` |
| `settings.php` | 听写 / 朗读 / 跟读参数 | `?id={classId}` |
| `admin.php` | 管理后台（独立 Session，30 分钟有效） | — |
| `app_api.php` | APP 全部 API（POST `action=...`） | — |
| `upload.php` | 图片上传(POST) / 查看(GET) | `?class_id=` `?file=` |
| `download.php` | 导出文件下载（一次性 token） | `?token=` |

---

## 数据存储说明

所有数据以 JSON 文件存于 `data/`，由 `inc/db.php` 统一管理（原子写入：临时文件 + 排他锁 + rename；并发更新用 `.lock` 文件加锁）。**班级相关数据按班级隔离为独立子目录** `data/classes/{classId}/`，全局数据留在 `data/` 根。

```
data/
├── classes.json                # 全局班级注册表
├── settings.json               # 全局设置
├── app_versions.json           # APP 版本发布日志
├── announcements.json          # 全服公告列表
├── exports.json + exports/     # 临时导出文件与 token
├── ratelimit.json              # 限流计数
├── users/                      # APP 用户数据（每个用户独立文件）
│   ├── u1.json                 #   用户数据（name / password_hash / class_ids / wrong_words / consent_map）
│   ├── u2.json
│   └── tokens.json             #   登录令牌（sha256哈希） + next_uid
└── classes/
    └── {classId}/
        ├── words.json              # 单词
        ├── tasks.json              # 任务
        ├── history.json            # 班级史记
        ├── gallery.json            # 图集元数据
        ├── personal_history_{uid}.json  # 个人列传
        └── uploads/                # 上传图片
```

| 路径 | 内容 | 敏感级别 |
|------|------|----------|
| `classes.json` | 班级列表（id / name / `password_hash` / `auth_version` / created_at） | 高 |
| `classes/{classId}/words.json` | 单词数组（id / word / meaning / pos / created_at） | 中 |
| `classes/{classId}/tasks.json` | 任务（id / date / label / word_ids / status / weekend_week） | 中 |
| `settings.json` | 全局设置（键名按功能+班级组合，如 `volume_{classId}`、`weekend_week_{classId}`、`gallery_api_key_{classId}`） | 中 |
| `users/{uid}.json` | APP 用户数据（name / password_hash / class_ids / wrong_words / consent_map） | **极高** |
| `users/tokens.json` | 登录令牌 sha256 哈希 + next_uid | **极高** |
| `app_versions.json` | APP 版本与发布日志（latest / history） | 中 |
| `announcements.json` | 全服公告（id/content/color/target_classes/target_platforms/allow_close/start_time/end_time） | 中 |
| `classes/{classId}/history.json` | 班级史记正文（key=日期，含 content/delta/title/mood/weather/location/tags） | 中 |
| `classes/{classId}/personal_history_{uid}.json` | 个人列传（隐私，需 consent 授权；`delta` 为 APP 端 Delta JSON 无损格式，Web 忽略。APP 导出时颜色统一为 6 位 `#RRGGBB`） | 高 |
| `classes/{classId}/gallery.json` | 图集元数据（id / image / description / uploaded_at） | 中 |
| `exports.json` + `exports/` | 临时导出文件与下载 token（短时有效，自动 GC） | 高 |
| `ratelimit.json` | 限流计数（滑动窗口） | 低 |
| `classes/{classId}/uploads/` | 上传图片（经 GD 重编码，文件名为随机哈希） | 中 |

> `data/` 目录有 `.htaccess`（`Deny all`）保护，递归禁止所有子目录的 Web 直链，图片只能通过 `upload.php` 控制访问。Nginx 环境需手动配置 `location /data/ { deny all; }`。

---

## APP API 说明

入口：`POST app_api.php`，参数 `action=...`（表单或 JSON）。使用 Bearer token 鉴权（`Authorization: Bearer <token>`），token 经 sha256 哈希后存于 `data/users/tokens.json`，有效期 30 天。

### 主要接口分组

| 分组 | action |
|------|--------|
| 账号 | `register` `claim_legacy` `login` `auto_login` `logout` `delete_account` `get_profile` |
| 班级 | `get_classes` `check_class` `bind_class` `unbind_class` `verify_class_password` `get_my_classes` |
| 单词 | `get_words` `search_word` `add_word` `ai_word` |
| 任务 | `get_tasks` `get_task_detail` `complete_task` `cancel_task` `get_completed_tasks` `search_all` |
| 错题 / 收藏 | `mark_wrong` `unmark_wrong` `get_wrong_words` `toggle_favorite` `get_favorites` |
| 史记 | `get_class_history` `get_personal_history` `save_personal_history` `export_personal_history` |
| 公告 | `get_announcements` |
| 图集 | `get_gallery` `save_gallery` `delete_gallery` `upload_image` |
| 授权 | `set_global_consent` `set_consent` `get_consent` |
| 版本 | `check_version` |
| 导出 | `export_words_pdf` `export_task_csv` `export_task_text` `export_wrong_csv` `export_wrong_text` |
| 其它 | `get_csrf_token` |

### 限流策略

| 桶 | 限制 |
|----|------|
| `login` | 10 次 / 5 分钟（按 IP） |
| `bindpw` | 10 次 / 5 分钟（按 IP） |
| `ai` | 40 次 / 小时（按用户） |

超限返回 `429` + `Retry-After` 头。

### CORS

由 `inc/config.php` 的 `allowed_origins` 控制。`['*']` 允许所有来源（开发方便，生产不安全）；生产环境应改为域名白名单。

---

## 前端设计

采用 **Hand-Drawn 手绘风格**，模拟纸笔/便签的课堂氛围。完整设计规范见 [`风格.md`](风格.md)。

- **颜色**：暖纸底色（`#fdfbf7`）、铅笔黑（`#2d2d2d`）、红色修正笔（`#ff4d4d`）、蓝色圆珠笔（`#2d5da1`）
- **字体**：标题 ZCOOL KuaiLe（站酷快乐体），正文 Ma Shan Zheng（马山正楷），Google Fonts 引入
- **组件**：CSS 变量统一管理（`common.css`），`.btn` / `.card` / `.input` / `.word-card` 等组件类
- **交互**：wobbly 不规则边框（`border-radius: 255px 15px 225px 15px / 15px 225px 15px 255px`），硬阴影「按平」动效，卡片 hover 微旋转
- **模板**：`inc/head.php` 统一 `<head>` 元数据与字体加载，`inc/header.php` 统一状态栏

---

## 安全机制

- **CSRF**：所有 POST 操作校验 `csrf_token`（`inc/security.php`），管理后台使用独立 CSRF。
- **班级鉴权**：口令用 `password_hash(PASSWORD_DEFAULT)` 存储；通过 HMAC-SHA256 签名的 Cookie 维持会话；`auth_version` 版本号控制——重置口令即版本号 +1，所有旧 Cookie 立即失效。
- **APP token**：`bin2hex(random_bytes(32))` 生成，仅存 sha256 哈希；过期自动清理。
- **限流**：登录 / 绑定口令 / AI 调用均有持久滑动窗口限流（`inc/ratelimit.php`）。
- **富文本 XSS 消毒**：`inc/history.php` 的 `historySanitizeHtml` 使用 DOMDocument 白名单过滤标签与属性，拒绝 `script/iframe/on*` 等危险内容，CSS 仅放行安全声明。
- **图片安全**：上传经 GD 重编码（防恶意图片），限制尺寸 / 像素 / 格式（JPEG/PNG/WebP），最长边缩放至 1600px。
- **路径穿越防护**：所有 classId / 文件名均经严格正则校验（`inc/db.php` `validateId` / `validateFilename`）。
- **管理后台**：失败 5 次锁定 5 分钟；Session 30 分钟超时；`session_regenerate_id` 防固定。
- **数据保护**：`data/` 目录禁止 Web 直链（`.htaccess`）。**生产环境必须额外配置 Web 服务器规则**，拦截 `inc/`、`.json`、隐藏文件等，详见 [部署安全加固](#部署安全加固)。

---

## 配置项详解

配置文件 `inc/config.php`（不入库，由 `config.example.php` 复制）：

```php
return [
    // Cookie 签名 / token 校验密钥（≥32 位随机字符）
    'app_secret' => 'replace-with-at-least-32-random-characters',

    // 允许的跨域来源；生产环境改为域名白名单
    'allowed_origins' => ['*'],

    // 图片上传限制
    'upload_max_bytes'  => 12582912,   // 12MB
    'upload_max_pixels' => 25000000,   // 2500 万像素
    'upload_max_edge'   => 1600,       // 最长边像素上限

    // DeepSeek AI 配置（AI 导入单词、名言翻译）
    'deepseek' => [
        'api_key'  => 'sk-your-deepseek-api-key-here',
        'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
        'model'    => 'deepseek-chat',
    ],

    // 管理后台密码与 Session 有效期（秒）
    'admin_password'    => 'change-this-password',
    'admin_session_ttl' => 1800,
];
```

> `app_secret` 也支持通过环境变量 `APP_SECRET` 注入（优先级高于配置文件）。当其长度不足 32 位时程序会抛出异常。
