# ListenWrite APP

Flutter 客户端，配合 [web/](../../web/) 后端使用。功能包括单词库管理、错题本、任务、个人史记（Vlog）、班级史记、画廊、搜索等。

## 技术栈

- **Flutter** (Dart 3.5+)
- **状态管理**：Provider
- **网络**：http（MultipartRequest）
- **本地缓存**：shared_preferences
- **图片选取**：image_picker
- **HTML 渲染**：flutter_widget_from_html（Vlog 内容）
- **字体**：google_fonts（Kalam 标题 + Patrick Hand 正文）

## 目录结构

```
lib/
├── main.dart                      # 入口
├── config/
│   ├── api_config.dart            # 实际配置（gitignore，CI 从 Secret 生成）
│   └── api_config.example.dart    # 模板（本机测试用）
├── models/                        # 数据模型
│   ├── user.dart
│   ├── class_info.dart
│   ├── word.dart
│   ├── task.dart
│   ├── gallery_item.dart
│   └── vlog_entry.dart            # 含 SearchResult / WordMatch / TaskMatch
├── services/
│   ├── api_service.dart           # HTTP 客户端，封装所有 API 调用
│   └── storage_service.dart       # 本地缓存（token、班级、单词等）
├── providers/
│   └── auth_provider.dart         # 全局状态：登录、班级、授权
├── theme/
│   └── app_theme.dart             # Hand-Drawn 主题（颜色、字体、阴影）
├── widgets/
│   └── hand_drawn.dart            # 通用组件（Card、Button、Input、TabBar 等）
└── screens/
    ├── splash_screen.dart         # 启动页（自动登录判断）
    ├── home_screen.dart           # 底部导航（5 Tab）
    ├── class_selection_screen.dart # 班级选择/绑定
    ├── auth/
    │   └── login_screen.dart      # 登录/注册
    ├── study/
    │   ├── study_screen.dart      # 学习 Tab（单词库/错题本/任务）
    │   └── task_detail_screen.dart
    ├── life/
    │   └── life_screen.dart       # 生活 Tab（我的史记/班级史记）
    ├── search/
    │   └── search_screen.dart     # 搜索 Tab
    ├── gallery/
    │   └── gallery_screen.dart    # 画廊 Tab
    └── profile/
        └── profile_screen.dart    # 我的 Tab
```

## 构建 APK

### 方式一：GitHub Actions（推荐）

无需本地 Flutter 环境，CI 自动构建。

1. **设置 Secret**：仓库 **Settings → Secrets → Actions** → 添加 `API_BASE_URL`，值为后端地址（如 `https://example.com`，不带 `/app_api.php`，不带尾部斜杠）
2. **触发构建**：推送改动到 `main` 分支（需改动 `app/myapp/**` 下文件），或手动在 Actions 页面 **Run workflow**
3. **下载 APK**：构建完成后，在 run 详情页底部 Artifacts 下载 `listenwrite-release.apk`

> 如果未设置 `API_BASE_URL` Secret，CI 会使用 `api_config.example.dart` 中的本地地址（`127.0.0.1:8000`），仅适合本机调试。

### 方式二：本地构建

```bash
cd app/myapp
flutter pub get
flutter build apk --release
```

需先创建 `lib/config/api_config.dart`（从 `api_config.example.dart` 复制并修改 `baseUrl`）。

## 本机调试

1. 启动后端（仓库根目录）：
   ```bash
   php -S 0.0.0.0:8000 -t web
   ```
2. 复制配置：
   ```bash
   cp lib/config/api_config.example.dart lib/config/api_config.dart
   ```
3. `flutter run`（需连接 Android 设备或模拟器）

> **注意**：Android 设备上的 `localhost` 指向设备自身，无法访问电脑上的 PHP 服务器。真机测试需将 `baseUrl` 改为电脑局域网 IP，或使用模拟器（`10.0.2.2` 映射宿主机）。

## API 对接

所有 API 通过 `POST app_api.php` 调用，`action` 字段区分接口。详见 [web/README.md](../../web/README.md) 的「APP API 说明」章节。

核心流程：
- **首次使用**：登录（自动注册）→ 选择班级（有口令需输入）→ 进入功能页
- **非首次**：自动登录 → 校验班级口令是否变更 → 正常使用或提示重新输入
- **每次启动**：检查版本更新（Profile 页可手动触发）
