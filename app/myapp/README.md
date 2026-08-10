# ListenWrite APP

Flutter Android 客户端，配合 [web/](../../web/) 后端使用。

## 功能概览

| Tab | 功能 |
|-----|------|
| 学习 | 单词库（卡片+点读+添加，下拉刷新+30s 自动刷新）、错题本（标记/移出+导出）、任务（进行中/历史+详情） |
| 生活 | 个人史记（日历+富文本编辑器+图片上传，保存后自动切换查看模式，下拉刷新+30s 自动刷新）、他人史记（授权用户，可滚动查看）、班级史记（只读） |
| 搜索 | 全局搜索单词和任务，点击跳转+滚动定位+高亮 |
| 画廊 | 网格浏览+上传（图片/GIF/MP4 视频，本地校验格式/大小，视频≤30s/15MB 不压缩保留原始编码）+大图查看+图片缓存；支持展示 GIF 动图与 MP4 视频（缩略图进入视口才播放、大图可缩放/播放/声音开关）；支持修改图片描述；下拉刷新+30s 自动刷新 |
| 我的 | 用户信息、切换班级、Vlog 授权、检查更新、退出登录、注销账号。登录无闪屏（本地 loading 状态） |

## 技术栈

| 依赖 | 版本 | 用途 |
|------|------|------|
| flutter | sdk | 框架 |
| flutter_localizations | sdk | 国际化（flutter_quill 中文支持） |
| provider | ^6.1.0 | 状态管理 |
| http | ^1.2.0 | 网络请求（MultipartRequest） |
| shared_preferences | ^2.2.0 | 本地缓存（token、班级、单词等） |
| image_picker | ^1.0.0 | 图片/视频选取（Vlog + 画廊） |
| flutter_widget_from_html | ^0.15.0 | Vlog HTML 内容渲染 |
| just_audio | ^0.9.39 | 单词发音（有道词典 TTS） |
| flutter_quill | ^11.5.1 | 富文本编辑器（Vlog 编辑，与网站端 Quill 对齐） |
| flutter_quill_extensions | ^11.0.0 | Quill 图片嵌入支持 |
| flutter_quill_delta_from_html | ^1.5.3 | HTML → Quill Delta 转换（加载已有内容） |
| vsc_quill_delta_to_html | ^1.0.5 | Quill Delta → HTML 转换（保存到服务器，inline styles 模式） |
| cached_network_image | ^3.4.1 | 图片缓存（画廊网格静态图+大图查看，避免重复下载） |
| visibility_detector | ^0.4.0+2 | GIF/MP4 缩略图视口检测（进入视口才加载播放，省流量+性能） |
| video_player | ^2.13.0 | MP4 视频播放（画廊缩略图静音循环 + 大图播放/声音开关） |
| flutter_lints | ^4.0.0 | 代码规范（dev） |

**字体**（打包到 APK，离线可用）：
- `ZCOOLKuaiLe` → 标题字体（`assets/fonts/ZCOOLKuaiLe-Regular.ttf`）
- `MaShanZheng` → 正文字体（`assets/fonts/MaShanZheng-Regular.ttf`）

**Android 配置**：
- `compileSdk = 36`（`android/app/build.gradle.kts`）
- `android:usesCleartextTraffic="true"`（`AndroidManifest.xml`，允许 HTTP）
- `android:label="默写史记"`
- `FileProvider` 配置（`res/xml/file_paths.xml`，flutter_quill 图片剪贴板支持）
- App 图标：自定义图标，5 密度 mipmap（48/72/96/144/192px）

**国际化**：
- `locale: Locale('zh')`，`supportedLocales: [Locale('zh'), Locale('en')]`
- `FlutterQuillLocalizations.delegate` → flutter_quill 工具栏中文
- `flutter_localizations` → Material/Cupertino 组件中文

## 目录结构

```
lib/
├── main.dart                          # 入口，Consumer<AuthProvider> 按 authState 路由
├── config/
│   ├── api_config.dart                # 实际配置（gitignore，CI 从 Secret 生成）
│   └── api_config.example.dart        # 模板（本机测试用 127.0.0.1:8000）
├── models/                            # 数据模型
│   ├── user.dart                      # 用户（id/name/classIds/token/consent/consentMap）
│   ├── class_info.dart                # 班级（id/name/hasPassword）
│   ├── word.dart                      # 单词（id/word/meaning/pos/isWrong/isFavorite）
│   ├── task.dart                      # 任务（id/date/label/status/wordCount/words[]）
│   ├── gallery_item.dart              # 画廊项（id/imageUrl/description/uploadedAt）
│   └── vlog_entry.dart                # Vlog 条目 + SearchResult + WordMatch + TaskMatch
├── services/
│   ├── api_service.dart               # HTTP 客户端，封装全部 33 个 API action
│   ├── storage_service.dart           # SharedPreferences 缓存
│   └── tts_service.dart               # 有道词典 TTS（just_audio + 错误提示）
├── providers/
│   └── auth_provider.dart             # 全局状态：AuthState 枚举驱动页面路由
├── theme/
│   └── app_theme.dart                 # Hand-Drawn 主题（颜色/字体/阴影/圆角/PaperTexture）
├── widgets/
│   ├── hand_drawn.dart                # 通用组件库（见下）
│   └── rich_text_editor.dart          # 富文本编辑器（flutter_quill 封装，HTML↔Delta 转换）
└── screens/
    ├── splash_screen.dart             # 启动页（动画 + init）
    ├── home_screen.dart               # 底部导航 5 Tab（纯图标 + 口令失效检测）
    ├── class_selection_screen.dart     # 班级选择/绑定（+ Vlog 授权弹窗）
    ├── auth/login_screen.dart         # 登录/注册（新用户自动注册）
    ├── study/
    │   ├── study_screen.dart          # 学习 Tab（单词库/错题本/任务 三段切换）
    │   └── task_detail_screen.dart    # 任务详情（单词列表 + 高亮 + 导出）
    ├── life/
    │   └── life_screen.dart           # 生活 Tab（日历在上 + 内容在下，3 子 Tab）
    ├── search/
    │   └── search_screen.dart         # 搜索 Tab（单词+任务，高亮+跳转）
    ├── gallery/
    │   └── gallery_screen.dart        # 画廊 Tab（网格+上传+灯箱查看）
    └── profile/
        └── profile_screen.dart        # 我的 Tab（信息/班级/授权/更新/退出/注销）
```

### 通用组件（`widgets/hand_drawn.dart`）

| 组件 | 说明 |
|------|------|
| `HandDrawnCard` | wobbly 边框 + 硬阴影卡片，支持 rotation/onTap |
| `HandDrawnButton` | 手绘按钮，按下时阴影消失+位移（"press flat"效果） |
| `HandDrawnInput` | wobbly 边框输入框，聚焦时边框变蓝+加粗 |
| `WobblyTabBar` | wobbly 风格分段切换栏 |
| `StickyNote` | 便利贴标签（可旋转） |
| `WordCard` | 单词卡片：动态字号 + 跑马灯滚动 + 喇叭按钮 + 加/移错题本按钮 + 红色边框标记 + 搜索高亮 |
| `MarqueeText` | 长文本自动滚动（用户交互暂停，2 秒后恢复） |
| `LoadingOverlay` | 加载遮罩 |
| `EmptyState` | 空状态占位 |
| `RichTextEditor` | 富文本编辑器（flutter_quill），支持 HTML 导入/导出、自定义图片上传按钮、只读模式、光标+中文本地化 |

## 状态管理与路由

```
main.dart
  └─ Consumer<AuthProvider>
       ├─ AuthState.initial / loading → SplashScreen
       ├─ AuthState.unauthenticated   → LoginScreen
       └─ AuthState.authenticated
            ├─ hasClass → HomeScreen
            └─ no class → ClassSelectionScreen
```

`AuthProvider` 核心状态：
- `authState` — 枚举驱动全局路由（退出登录后自动回登录页，不会卡住）
- `user` — 当前用户信息
- `currentClassId` / `currentClassName` — 当前班级
- `myClasses` — 已绑定班级列表
- `isNewlyBound` — 新绑定标记（触发 Vlog 授权弹窗）

## 构建 APK

### 方式一：GitHub Actions（推荐）

1. **设置 Secret**：仓库 Settings → Secrets → Actions → 添加 `API_BASE_URL`
   - 值为后端地址（如 `http://moxie.billspace.top`）
   - 不带 `/app_api.php`，不带尾部斜杠
2. **触发构建**：推送改动到 `main`（需改动 `app/myapp/**`），或手动 Run workflow
3. **下载**：run 详情页 → Artifacts → `listenwrite-release.apk`

### 方式二：本地构建

```bash
cd app/myapp
cp lib/config/api_config.example.dart lib/config/api_config.dart
# 编辑 api_config.dart，修改 baseUrl 为你的服务器地址
flutter pub get
flutter build apk --release
```

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

> **注意**：Android 设备上的 `localhost` / `127.0.0.1` 指向设备自身，无法访问电脑上的 PHP 服务器。真机测试需将 `baseUrl` 改为电脑局域网 IP，或使用模拟器（`10.0.2.2` 映射宿主机）。推荐部署到服务器 + GitHub Secret 方式。

## API 对接

所有 API 通过 `POST app_api.php` 调用，`action` 字段区分接口。完整列表见 [web/README.md](../../web/README.md) 的「APP API 说明」章节。

### 核心流程

```
首次使用：
  登录（自动注册）→ 选择班级（有口令需输入）
  → 绑定成功 → 弹窗询问 Vlog 授权 → 进入功能页

非首次：
  启动 → auto_login 校验 token → check_class 校验班级口令
  → 正常使用 / 提示口令已失效 → 跳转班级选择

每次进入 Profile：
  check_version 检查更新
```

### 认证机制

- **Token**：`Authorization: Bearer <token>`，30 天 TTL，sha256 哈希存储
- **班级绑定**：绑定后存储 `auth_version`，口令变更时版本不匹配 → 403 `CLASS_AUTH_EXPIRED` → APP 自动跳转班级选择页
- **Vlog 授权**：`consent_map` 按班级存储，`set_global_consent` 同步到所有已绑定班级；登录/自动登录返回 consent 状态；SharedPreferences 本地缓存

## 已知问题与后续待办

1. **性能**：单词库列表加载全量数据时偏卡，可考虑虚拟列表优化
2. **Vlog 编辑器**：flutter_quill 与网站端 Quill JS 的 Delta 格式存在细微差异，复杂排版迁移可能不完美；字体颜色已通过 inlineStylesFlag + 正则后处理输出为内联样式
3. **TTS 容错**：有道词典对部分单词无发音记录，已加 SnackBar 提示
4. **iOS 适配**：代码已兼容，需 Mac + Xcode + Apple Developer 账号构建
5. **画廊上传**：已实现本地校验（JPEG/PNG/WebP + 12MB 上限）+ 服务器端二次校验
6. **同步刷新**：三屏均已实现 30s 自动刷新 + 手动下拉刷新，编辑/滚动中不打断
7. **媒体缓存**：画廊静态图使用 cached_network_image 缓存；GIF 动图用 flutter_cache_manager 磁盘缓存 + visibility_detector 视口检测（进入视口才播放、离开释放资源）；MP4 视频**网络流式播放优先**（服务器 HEAD/Range 支持，秒开边下边播），磁盘缓存作失败兜底，网格缩略图全局单例仅一个解码器（避免多实例卡顿）、离开视口彻底释放，大图播放/声音开关；单词/史记数据使用 SharedPreferences TTL 缓存（30s）
8. **`api_config.example.dart` 中 baseUrl 为 `127.0.0.1:8000/web`**：注意服务器端如果没有 `/web` 前缀（直接部署在根目录），需去掉 `/web`
