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
| **班级图集** (`gallery.php` / `gallery_api.php`) | 图片/视频上传 + 画廊展示；支持 GIF 动图与 MP4 视频（炸弹防护 + 原样存储，视频≤30s/15MB）；**MP4 自动生成首帧缩略图**（ffmpeg，`video_thumb.php` 提供，加载中/列表先显示首帧预览）；支持修改描述；**灯箱布局为「左媒体 + 右描述整列」**，描述框宽度按媒体宽高比自适应（竖图更宽、横图更窄），描述过长在右列内**来回自动滚动**（缓慢、requestAnimationFrame 逐帧平滑、溢出时加边缘渐变蒙版防硬截断，鼠标悬停/触摸暂停、离开 2s 后恢复；描述存 JS 映射表按 id 取，不内嵌到 onclick，杜绝长描述/换行导致卡片打不开）；提供公开随机 API（每次随机返回一张图集图片，同一设备连续两次不重复） |
| **展示大屏** (`display.php`) | 壁纸投屏页（用于 Lively Wallpaper / 希沃大屏）：顶部公告跑马灯 + 今日默写单词大字海报 + 班级图集轮播，严格遵循手绘设计风格；班级鉴权状态机自动处理口令重置 / 班级删除 / cookie 失效；60s 轮询 + 图集预加载，性能友好；**图集描述过长在固定区域内来回自动滚动**（壁纸页纯自动、无手动打断，requestAnimationFrame 平滑 + 边缘渐变蒙版） |
| **设置** (`settings.php`) | 听写 / 朗读 / 跟读参数、图集公开 API 密钥保护、展示大屏 token，均按班级隔离存储 |
| **管理后台** (`admin.php`) | 班级删除（级联清理）、重置班级口令、APP 版本发布（含渠道/日志）、**全服公告管理**（内容/颜色/班级/平台/时间/可关闭） |
| **全服公告** | 公告分两类：**顶部横幅**（状态栏跑马灯，可关闭）与**超级霸屏**（mode=fullscreen）。超级霸屏：仅站内点击跳转时触发（刷新/直达/系统返回不触发），新页面**首帧即渲染**（无内容闪现），页面加载完成后开始计时展示 1~5 秒、点击任意处跳过；霸屏层只占状态栏（横幅）**下方**区域，不遮挡顶部横幅。两类同时间段可共存、同类互斥。后台时间选择已预填服务器当前时间（默认立即生效），并醒目显示服务器时间 |
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
| 视频首帧缩略图（可选） | **系统 ffmpeg 4.x**（`/usr/bin/ffmpeg` + `/usr/bin/ffprobe`）+ composer 包 [php-ffmpeg/php-ffmpeg](https://github.com/PHP-FFMpeg/PHP-FFMpeg) `^1.4`（`web/composer.json`）。用于 MP4 图集卡片/APP 端首帧预览图；**未安装时优雅降级**（视频卡片直接播放，不影响上传） |

> 核心业务零第三方 PHP 依赖（`require_once` 手动加载）。仅**视频首帧缩略图**一项可选依赖 Composer。

### 视频首帧缩略图依赖（Composer + ffmpeg）

新加入的 Composer 依赖仅用于为 MP4 图集生成首帧预览图（`video_thumb.php` 提供，APP 端视频卡片 / Web 图集卡片 / 展示大屏 poster 使用；未安装时优雅降级为直接播放视频）。

| 组件 | 说明 |
|------|------|
| 系统 ffmpeg | Ubuntu 包 `ffmpeg` / `ffprobe`，位于 `/usr/bin/`（本项目实测 4.4.2） |
| Composer 包 | `php-ffmpeg/php-ffmpeg ^1.4`（`web/composer.json`，已锁定） |
| `vendor/` | **只在服务器上存在，不入库**（`.gitignore` 已忽略）；`composer.json` + `composer.lock` 均已生成于服务器 |

**配置流程（服务器一次性完成，之后不要再动）：**

```bash
cd /www/wwwroot/你的站点
# 1) 安装系统 ffmpeg（若未装）
apt-get install -y ffmpeg
# 2) 安装 composer 依赖（PHP 7.4+ 需 composer）
composer require php-ffmpeg/php-ffmpeg:^1.4
# 完成后不要再 composer install / composer require / composer update（避免升级已装包）
```

**关键运行约束（务必了解）：**

- **PHP-FPM 禁用了全部进程执行函数**（`proc_open/exec/shell_exec/…`），而 php-ffmpeg 依赖 Symfony Process（`proc_open`）——因此 **FPM 内无法调用 ffmpeg**，缩略图必须由 **CLI 计划任务**生成：
  ```bash
  * * * * * /usr/bin/php /www/wwwroot/你的站点/cron_gallery_thumbs.php >> /tmp/gallery_thumbs_cron.log 2>&1
  ```
  `cron_gallery_thumbs.php` 每分钟扫描各班级 MP4，缺缩略图即生成（幂等；`/tmp` 锁防重叠；单次 50s 时间预算）。上传新视频后最长 1 分钟内补齐。
- **`open_basedir` 仅放行项目目录与 `/tmp`**：php-ffmpeg 的 BinaryDriver 会用 `file_exists()` 探测二进制，直接指向 `/usr/bin/ffmpeg` 会被拦截。因此使用 `bin/ffmpeg`、`bin/ffprobe` 两个**项目内包装脚本**（`exec /usr/bin/ffmpeg "$@"`，`exec` 不受 open_basedir 限制），`generateVideoThumb()` 已配置指向它们。
- **不要**在服务器上执行 `composer install/require`（避免改动已装包）；部署代码时 `composer.json` 一并上传，`vendor/` 保持服务器现状即可。
- 缩略图存放于 `data/classes/{classId}/thumbs/`（`data/` 受保护），文件名仍为 32 位 hex，`video_thumb.php` 输出 `Cache-Control: immutable` 长缓存。

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
├── display.php            # 展示大屏（壁纸投屏页）
├── display.css            # 展示大屏样式（独立，仅该页加载）
├── display.js             # 展示大屏脚本（跑马灯 / 轮询 / 轮播 / 竖线拖拽）
├── settings.php           # 听写 / 朗读 / 跟读设置
├── admin.php              # 管理后台
├── app_api.php            # APP 后端 API（全部接口）
├── upload.php             # 图片上传 / 查看（GD 安全处理）
├── video_thumb.php        # MP4 首帧缩略图（ffmpeg 生成 + 长缓存，供图集卡片/APP 用）
├── cron_gallery_thumbs.php # CLI 计划任务：每分钟为 MP4 图集生成缺失首帧缩略图（FPM 无法 exec）
├── bin/                   # ffmpeg/ffprobe 包装脚本（绕过 open_basedir 对 /usr/bin 的探测，须 LF）
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
│   ├── input.php          # 请求参数安全读取（reqGet/reqPost）+ 纯文本消毒（sanitizePlainText）
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
├── composer.json          # Composer 依赖（仅 php-ffmpeg，用于视频首帧缩略图；vendor/ 不入库）
├── project-features/
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
| **`.user.ini`** | 站点根目录 `.user.ini` 已配置：`open_basedir=/www/wwwroot/你的站点/:/tmp/` + `display_errors = Off` + `memory_limit = 256M`。`display_errors=Off` 防止 PHP 警告混入 JSON 响应导致 APP/前端"解析错误"（本仓库上传接口曾因此偶发报错）；`memory_limit=256M` 避免大图上传时 GD 内存耗尽（默认 128M 处理 25MP 图会 OOM） |
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
| `words.php` | 单词库：卡片网格、选中建任务、AI 批量导入、CSV；进入时自动定位到最近一次创建任务（pending）的最后一个单词并持久高亮（用户操作后清除）；`?highlight={wordId}` 可定位到指定单词 | `?id={classId}` `?highlight={wordId}` |
| `task.php` | 默写任务：列表视图 + 执行视图（看词/默写/听写） | `?id={classId}` `?task_id={taskId}` |
| `history.php` | 默写记录：历史任务、重新创建 | `?id={classId}` `?task_id={taskId}` |
| `history_book.php` | 班级史记：月历 + Quill 编辑器 + 导出 | `?id={classId}` |
| `display.php` | 壁纸投屏页（公告跑马灯 + 今日单词 + 图集轮播） | `?id={classId}` 指定班级；`?token={token}` 免口令直达（壁纸场景）；`?json=1` 轮询数据接口 |
| `app_api.php` `get_announcements` | 获取当前有效公告（按班级 + 平台 + 时间段筛选） | POST `class_id` `platform` |
| `gallery.php` | 图集：上传（图片/GIF/MP4）/ 编辑描述 / 删除 / 画廊，Lightbox 播放视频带声音开关 | `?id={classId}` |
| `gallery_api.php` | 图集公开 API | `?class_id={id}` `?apikey=`（每次返回一张随机图片） |
| `settings.php` | 听写 / 朗读 / 跟读参数、图集 API 密钥、展示大屏 token | `?id={classId}` |
| `admin.php` | 管理后台（独立 Session，30 分钟有效） | — |
| `app_api.php` | APP 全部 API（POST `action=...`） | — |
| `upload.php` | 图片上传(POST) / 查看(GET) | `?class_id=` `?file=` |
| `video_thumb.php` | MP4 首帧缩略图（无鉴权，文件名随机即凭证；未生成时现场 ffmpeg 生成） | `?class_id=` `?file=` |
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
        ├── uploads/                # 上传图片
        └── thumbs/                 # MP4 首帧缩略图（ffmpeg 生成，`{32hex}.jpg`）+ .lock
```

| 路径 | 内容 | 敏感级别 |
|------|------|----------|
| `classes.json` | 班级列表（id / name / `password_hash` / `auth_version` / created_at） | 高 |
| `classes/{classId}/words.json` | 单词数组（id / word / meaning / pos / created_at） | 中 |
| `classes/{classId}/tasks.json` | 任务（id / date / label / word_ids / status / weekend_week） | 中 |
| `settings.json` | 全局设置（键名按功能+班级组合，如 `volume_{classId}`、`weekend_week_{classId}`、`gallery_api_key_{classId}`、`display_bottom_margin` 大屏底部避让高度） | 中 |
| `users/{uid}.json` | APP 用户数据（name / password_hash / class_ids / wrong_words / consent_map） | **极高** |
| `users/tokens.json` | 登录令牌 sha256 哈希 + next_uid | **极高** |
| `app_versions.json` | APP 版本与发布日志（latest / history） | 中 |
| `announcements.json` | 全服公告（id/content/color/mode/fullscreen_seconds/target_classes/target_platforms/allow_close/start_time/end_time）。`mode`: banner(顶部横幅)/fullscreen(超级霸屏)；同时间段同类互斥、两类可共存 | 中 |
| `classes/{classId}/history.json` | 班级史记正文（key=日期，含 content/delta/title/mood/weather/location/tags） | 中 |
| `classes/{classId}/personal_history_{uid}.json` | 个人列传（隐私，需 consent 授权；`delta` 为 APP 端 Delta JSON 无损格式，Web 忽略。APP 导出时颜色统一为 6 位 `#RRGGBB`） | 高 |
| `classes/{classId}/gallery.json` | 图集元数据（id / image / description / uploaded_at） | 中 |
| `classes/{classId}/thumbs/` | MP4 首帧缩略图 JPEG（ffmpeg 生成，文件名=`{32hex}.jpg`；`.lock` 用于生成串行锁） | 低 |
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
| 图集 | `get_gallery` `save_gallery` `update_gallery` `delete_gallery` `upload_image` |
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

## 展示大屏（壁纸页）

`display.php` 专为 **Lively Wallpaper / 希沃白板大屏** 等「链接当壁纸」的场景设计，独立全屏布局（不套用状态栏）。

### 页面布局

```
┌────────────┬──────────────────────────────────────┐
│ 快捷方式区   │ 内容区 (左侧可拖拽竖线调整，默认 33.3%)     │
│ (不使用)    │ ┌──────────────────────────────────┐ │
│            │ │ 顶部条：公告跑马灯 + 班级名 + 切换按钮     │ │
│            │ │ 单词区 (2/3)：今日默写大字海报（≤20 词）    │ │
│            │ │ 图集区 (1/3)：左图 + 右侧描述            │ │
│            │ └──────────────────────────────────┘ │
│            │  ← 底部避让 (admin 配置 display_bottom_margin)
└────────────┴──────────────────────────────────────┘
```

- **公告**：仅 `mode=banner`（顶部跑马灯），超级霸屏通告不进壁纸页。跑马灯**复用顶部栏横幅的实现**（双副本 `translateX(-50%)` 无缝循环 + 左右 `mask` 渐隐遮罩），文本不溢出时居中显示
- **单词**：取今天最早创建的 `status=pending` 任务（按 `created_at` 排序取第一个），展示该任务的全部单词（上限 20）；当天有多个任务时只展示最早那个；无任务显示占位。**字号自适应**：按内容区宽高 + 单词数（75 分位长度）动态算列数与字号（16~64px），个别超长单词单独缩小该卡片并滚动展示，保证后排可读
- **图集**：仅班级图集 `gallery.json`，**固定 15s 节奏轮播**（setTimeout 锚定刻度，与加载耗时无关，放完自动从头循环）；**若 15s 时描述还没滚完，等它读完后再停 1s 才切换**，避免文字被切走；**预加载后两张**（视频用**挂载到 DOM 的隐藏 `<video preload=auto muted>`** 真正缓冲数据——未挂载的 video 浏览器不会下载，图片用 `Image` 预热缓存）；视频用 ffmpeg 首帧图作 `poster`，加载间隙显示"加载中…"占位而非黑屏；**描述过长时在描述卡片固定区域内来回自动滚动**（壁纸页纯自动、不可打断；requestAnimationFrame 逐帧驱动平滑、速度缓慢、端点停留；渐变蒙版挂在滚动容器上随视口固定，不随文字滚动、不啃底板边框）
- **可拖拽竖线**：调整可用区域左边界（存每台设备 `localStorage['display_left_pct']`），默认 33.3%
- **底部避让**：后台「大屏壁纸设置」配置 `display_bottom_margin`（px），防止被任务栏遮挡

### 班级鉴权状态机

| 场景 | 页面行为 |
|------|---------|
| 无 `current_class_id` cookie | 班级选择页 |
| cookie 的班级已被删除 | 自动回退到班级选择页 |
| 班级口令已重置（`auth_version` 递增） | 自动弹出该班口令弹窗 |
| cookie 过期 / 被篡改 | 同上，口令弹窗 |
| 班级无口令 | 直接进入，无需口令 |
| 正常 | 渲染壁纸内容 |

`?json=1` 轮询接口同样先做鉴权：口令重置返回 `{code:"need_auth"}`、班级删除返回 `{code:"class_not_found"}`，客户端**无刷新**回退到选择页/口令弹窗并暂停轮询。`?id={classId}` 可指定班级直达。

### 带 token 的免口令链接

壁纸场景（Lively Wallpaper / 希沃大屏）通常无法输入键盘口令。为此支持**展示大屏 token**：

- 在班级「设置」页的「展示大屏」卡片中配置一个随机 token（或点击「随机生成」），保存后复制生成的 `display.php?id={classId}&token={token}` 链接；
- 带**正确 token** 的链接访问展示页时**免口令直达**内容视图，无需输入班级口令；
- **安全性**：token 只解锁"只读展示页"，展示页不提供任何数据修改操作；token 直达**不授予该浏览器其他页面**（单词库/任务等可操作页面）任何权限，轮询数据接口同样依赖 URL 中的 token；
- token 存在 `settings.json` 的 `display_token_{classId}`，随时可在设置页重新生成（旧链接随即失效）；
- 口令弹窗在壁纸大屏上**不显示取消按钮**，避免退出后落在空白界面（口令输错需重输或刷新页面重新验证）。

### 性能设计

- 服务端首屏直出（无白屏）；`common.css` 未改动，样式在独立 `display.css`
- 60s 轮询一次（公告/单词/图集列表），图集 15s 纯客户端切换 + 预加载
- `document.hidden` 暂停全部定时器；`prefers-reduced-motion` 禁用动画
- 图片沿用服务器 GD 压缩（≤1600px）+ `upload.php` 缓存
---

## 安全机制

- **CSRF**：所有 POST 操作校验 `csrf_token`（`inc/security.php`），管理后台使用独立 CSRF。
- **请求参数安全**：所有 GET/POST 参数读取统一经 `inc/input.php` 的 `reqGet`/`reqPost`（数组参数 `?x[]=1` 一律视为空，杜绝 `Array to string conversion` 警告在 `display_errors` 开启时泄露服务器绝对路径）；单词 / 释义 / 图集描述 / 公告等纯文本字段写入端经 `sanitizePlainText` 消毒（解码实体 → strip_tags → 去控制字符），存储型 XSS 双保险。
- **班级鉴权**：口令用 `password_hash(PASSWORD_DEFAULT)` 存储；通过 HMAC-SHA256 签名的 Cookie 维持会话；`auth_version` 版本号控制——重置口令即版本号 +1，所有旧 Cookie 立即失效。
- **APP token**：`bin2hex(random_bytes(32))` 生成，仅存 sha256 哈希；过期自动清理。
- **限流**：登录 / 绑定口令 / AI 调用均有持久滑动窗口限流（`inc/ratelimit.php`）。
- **富文本 XSS 消毒**：`inc/history.php` 的 `historySanitizeHtml` 使用 DOMDocument 白名单过滤标签与属性，拒绝 `script/iframe/on*` 等危险内容，CSS 仅放行安全声明。
- **内联 JSON XSS 防护**：所有输出到 `<script>` 内联块的数据（班级名 / 单词 / 释义 / 图集描述 / 公告）统一用 `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`，杜绝 `</script>` 逃逸注入。
- **HTML 属性注入防护**：`speak()` 发音按钮等 `onclick` 内联调用均经 `htmlspecialchars(json_encode(..., JSON_HEX_*), ENT_QUOTES)` 双重转义，用户输入含引号无法逃逸属性。
- **CSV 公式注入防护**：导出 CSV（单词库 / 任务 / 错题本）时，以 `=` `+` `-` `@` 开头的单元格前缀 `'`，防止 Excel 打开时执行公式。
- **图片安全**：上传经 GD 重编码（防恶意图片），限制尺寸 / 像素 / 格式（JPEG/PNG/WebP），最长边缩放至 1600px；**GIF 动图**走独立 `inc/gif_guard.php` 校验（magic bytes + 帧数 ≤300 + 单帧像素×帧数 ≤8000 万 + 单边 ≤8000px + 单文件 ≤16MB），校验通过后原样存储保留动画；**MP4 视频**走 `inc/mp4_guard.php`（纯 PHP 解析 ftyp/mvhd，时长 ≤30s + 文件 ≤15MB + 结构校验），原样存储；图集文件输出 MIME 白名单含 `image/gif`/`video/mp4`，**支持 HTTP HEAD 与 Range（206 Partial Content，含后缀 `bytes=-N`）**，视频 seek/流式播放必需，Cache-Control 用 `immutable` 强缓存（文件名随机不可变）；路径穿越防护沿用 32 位 hex 文件名校验。
- **视频首帧缩略图**：由 `inc/db.php` 的 `generateVideoThumb()` 调用系统 ffmpeg（php-ffmpeg，`vendor/autoload.php`）提取第 0.1s 帧并缩放至 480px 宽；`video_thumb.php` 首次访问按班 `.lock` 串行生成（防并发打满 CPU），文件名仍为 32 位 hex（同上传文件名校验防穿越），输出 `Cache-Control: immutable` 长缓存；缩略图存放于 `data/classes/{classId}/thumbs/`（受 `data/` 目录保护）。生成失败返回 404，调用方降级为直接播放视频，不影响主流程。
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
