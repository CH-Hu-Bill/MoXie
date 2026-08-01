# ListenWrite · 网站端（WangZhan）

班级默写 + 班级史记的 Web 应用。基于 **PHP + JSON 文件存储**（无数据库），通过浏览器使用；同时为同名 APP 提供 REST 风格的后端 API。

> 本仓库仅包含 **Web 端 / 后端** 代码，APP 客户端源码不在本仓库内。

---

## 目录

- [功能概览](#功能概览)
- [技术栈与运行要求](#技术栈与运行要求)
- [目录结构](#目录结构)
- [部署指南](#部署指南)
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
| **班级史记** (`history_book.php`) | Vlog 风格日记，Quill 富文本编辑器 + 月历导航；仅今日可编辑；个人列传（需授权）；支持 PDF / HTML / 长图导出 |
| **班级图集** (`gallery.php` / `gallery_api.php`) | 图片上传 + 画廊展示；提供公开分页 API（基于 IP + 日期轮换排序，防同设备重复） |
| **设置** (`settings.php`) | 听写 / 朗读 / 跟读参数，按班级隔离存储 |
| **管理后台** (`admin.php`) | 班级删除（级联清理）、重置班级口令、APP 版本发布（含渠道/日志） |
| **APP 后端 API** (`app_api.php`) | 用户注册 / 登录 / token 鉴权、单词 / 任务 / 错题本 / 收藏、史记、图集、导出等完整接口 |

---

## 技术栈与运行要求

| 项 | 要求 |
|----|------|
| PHP | **7.4+**（使用 `password_hash` / `random_bytes` / `mb_*` 等） |
| PHP 扩展 | **GD**（图片安全处理与缩放）、**cURL**（调用 DeepSeek AI） |
| Web 服务器 | Nginx / Apache，文档根指向本目录 |
| 数据库 | 无，使用 JSON 文件存储（`inc/db.php` 封装，原子写入 + 文件锁） |
| 前端依赖 | 原生 HTML/CSS/JS；富文本编辑器 [Quill 2.x](https://quilljs.com)（CDN 引入） |
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
├── common.css             # 公共样式
├── common.js              # 公共脚本（TTS / Toast / 跟读 / 页面过渡等）
├── shiyin.mp3             # 任务提示音
├── inc/                   # 核心库
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

4. **Web 根**：将文档根指向本目录。Nginx 示例：
   ```nginx
   root /var/www/listenwrite;
   location ~ \.php$ {
       fastcgi_pass unix:/run/php/php-fpm.sock;
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
   }
   ```

5. **确认防护生效**：访问 `https://你的域名/data/classes.json` 应返回 403（`.htaccess` 在 Apache 下生效；Nginx 需另行配置 `location /data/ { deny all; }`）。

6. **访问**：
   - Web 端：`https://你的域名/`
   - 管理后台：`https://你的域名/admin.php`
   - APP 接口：`https://你的域名/app_api.php`

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
| `gallery.php` | 图集：上传 / 删除 / 画廊 | `?id={classId}` |
| `gallery_api.php` | 图集公开 API | `?class_id={id}` `?page=` `?per_page=` `?apikey=` |
| `settings.php` | 听写 / 朗读 / 跟读参数 | `?id={classId}` |
| `admin.php` | 管理后台（独立 Session，30 分钟有效） | — |
| `app_api.php` | APP 全部 API（POST `action=...`） | — |
| `upload.php` | 图片上传(POST) / 查看(GET) | `?class_id=` `?file=` |
| `download.php` | 导出文件下载（一次性 token） | `?token=` |

---

## 数据存储说明

所有数据以 JSON 文件存于 `data/`，由 `inc/db.php` 统一管理（原子写入：临时文件 + 排他锁 + rename；并发更新用 `.lock` 文件加锁）。

| 文件 | 内容 | 敏感级别 |
|------|------|----------|
| `classes.json` | 班级列表（id / name / `password_hash` / `auth_version` / created_at） | 高 |
| `words_{classId}.json` | 单词数组（id / word / meaning / pos / created_at） | 中 |
| `tasks_{classId}.json` | 任务（id / date / label / word_ids / status / weekend_week） | 中 |
| `settings.json` | 全局设置（键名按功能+班级组合，如 `volume_{classId}`、`weekend_week_{classId}`、`gallery_api_key_{classId}`） | 中 |
| `app_data.json` | APP 用户（name / `password_hash` / tokens / class_ids / wrong_words / consent_map） | **极高** |
| `app_versions.json` | APP 版本与发布日志（latest / history） | 中 |
| `history_{classId}.json` | 班级史记正文（key=日期，含 content/title/mood/weather/location/tags） | 中 |
| `personal_history_{uid}_{classId}.json` | 个人列传（隐私，需 consent 授权） | 高 |
| `gallery_{classId}.json` | 图集元数据（id / image / description / uploaded_at） | 中 |
| `exports.json` + `exports/` | 临时导出文件与下载 token（短时有效，自动 GC） | 高 |
| `ratelimit.json` | 限流计数（滑动窗口） | 低 |
| `uploads/{classId}/` | 上传图片（经 GD 重编码，文件名为随机哈希） | 中 |

> `data/` 及 `data/uploads/` 均有 `.htaccess`（`Deny all`）保护，图片只能通过 `upload.php` 控制访问。Nginx 环境需手动添加 `deny all` 规则。

---

## APP API 说明

入口：`POST app_api.php`，参数 `action=...`（表单或 JSON）。使用 Bearer token 鉴权（`Authorization: Bearer <token>`），token 经 sha256 哈希后存于 `app_data.json`，有效期 30 天。

### 主要接口分组

| 分组 | action |
|------|--------|
| 账号 | `register` `claim_legacy` `login` `auto_login` `logout` `delete_account` `get_profile` |
| 班级 | `get_classes` `check_class` `bind_class` `unbind_class` `verify_class_password` `get_my_classes` |
| 单词 | `get_words` `search_word` `add_word` `ai_word` |
| 任务 | `get_tasks` `get_task_detail` `complete_task` `cancel_task` `get_completed_tasks` `search_all` |
| 错题 / 收藏 | `mark_wrong` `unmark_wrong` `get_wrong_words` `toggle_favorite` `get_favorites` |
| 史记 | `get_class_history` `get_personal_history` `save_personal_history` `export_personal_history` |
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

## 安全机制

- **CSRF**：所有 POST 操作校验 `csrf_token`（`inc/security.php`），管理后台使用独立 CSRF。
- **班级鉴权**：口令用 `password_hash(PASSWORD_DEFAULT)` 存储；通过 HMAC-SHA256 签名的 Cookie 维持会话；`auth_version` 版本号控制——重置口令即版本号 +1，所有旧 Cookie 立即失效。
- **APP token**：`bin2hex(random_bytes(32))` 生成，仅存 sha256 哈希；过期自动清理。
- **限流**：登录 / 绑定口令 / AI 调用均有持久滑动窗口限流（`inc/ratelimit.php`）。
- **富文本 XSS 消毒**：`inc/history.php` 的 `historySanitizeHtml` 使用 DOMDocument 白名单过滤标签与属性，拒绝 `script/iframe/on*` 等危险内容，CSS 仅放行安全声明。
- **图片安全**：上传经 GD 重编码（防恶意图片），限制尺寸 / 像素 / 格式（JPEG/PNG/WebP），最长边缩放至 1600px。
- **路径穿越防护**：所有 classId / 文件名均经严格正则校验（`inc/db.php` `validateId` / `validateFilename`）。
- **管理后台**：失败 5 次锁定 5 分钟；Session 30 分钟超时；`session_regenerate_id` 防固定。
- **数据保护**：`data/` 目录禁止 Web 直链（`.htaccess`）。

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
