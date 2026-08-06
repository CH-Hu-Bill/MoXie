# APP 端源码

ListenWrite APP 客户端，基于 Flutter 开发，支持 Android / iOS。

- **应用名称**：ListenWrite
- **Android 包名**：`billspace.listenwrite.flutter`
- **当前版本**：1.0.2

> **注意**：GitHub Actions 自动构建的 APK 会通过 Secret `API_BASE_URL` 写入正式服务器地址；未设置该 Secret 时回退到 `http://127.0.0.1:8000/web`（本地测试）。如需连接真实服务器，请配置 GitHub Secret 或本地复制 `api_config.dart` 修改 `baseUrl` 后重新打包。

## 技术栈

- **Flutter** 3.x（Dart 3.5+）
- **状态管理**：Provider
- **HTTP 请求**：http
- **本地存储**：SharedPreferences
- **字体**：Google Fonts（Kalam + Patrick Hand）
- **日历**：table_calendar
- **图片选择**：image_picker
- **设计风格**：两端统一 **Hand-Drawn 手绘风格**，设计规范详见 [`web/风格.md`](../web/风格.md)

## 快速开始

```bash
# 1. 安装依赖
cd app/myapp
flutter pub get

# 2. 配置 API 地址
cp lib/config/api_config.example.dart lib/config/api_config.dart
# 编辑 api_config.dart，修改 baseUrl 为你的服务器地址
# 本地测试默认：http://localhost:8000/web/app_api.php

# 3. 运行
flutter run
```

## 项目结构

```
lib/
├── main.dart                   # 入口
├── config/
│   ├── api_config.dart         # API 配置（不入库，从 example 复制）
│   └── api_config.example.dart # 配置模板
├── theme/
│   └── app_theme.dart          # Hand-Drawn 手绘风格主题
├── models/
│   ├── user.dart               # 用户模型
│   ├── class_info.dart         # 班级模型
│   ├── word.dart               # 单词模型
│   ├── task.dart               # 任务模型
│   ├── gallery_item.dart       # 画廊模型
│   ├── vlog_entry.dart         # Vlog 条目（content=HTML，delta=Delta JSON）
│   └── announcement.dart       # 公告模型
├── services/
│   ├── api_service.dart        # API 请求封装（含 get_announcements）
│   └── storage_service.dart   # 本地存储
├── providers/
│   └── auth_provider.dart      # 认证状态管理
├── screens/
│   ├── home/                   # 启动页 + 主页
│   ├── auth/                   # 登录 / 注册
│   ├── class_selection/        # 班级选择 / 绑定
│   ├── study/                  # 学习（单词库/任务/错题本）
│   ├── life/                   # 生活（Vlog 日历）
│   ├── search/                 # 搜索
│   ├── gallery/                # 画廊
│   └── profile/                # 我的
└── widgets/
    ├── hand_drawn_widgets.dart # 手绘风格通用组件
    ├── rich_text_editor.dart   # Quill 富文本编辑器（HTML + Delta JSON 双存）
    ├── announcement_banner.dart # 公告横幅（AppBar 下方；文本未超出静态居中、超出时双副本无缝循环滚动 + 两侧渐变蒙板，可关闭）
    ├── announcement_marquee.dart # 无缝跑马灯组件（双副本 + 实际渲染宽度测量，拼接精确）
    └── fullscreen_announcement_overlay.dart # 超级霸屏全屏层（切 tab/进子页时展示 1~5 秒，点击跳过，半透明底不遮横幅）
```

## 富文本颜色保存说明

- 编辑器保存时**同时写入** `content`（HTML，供 Web 端）与 `delta`（Delta JSON，供 APP 无损还原）。
- 加载时**优先使用 `delta`**，无 `delta`（如 Web 端创建的旧数据）回退到 HTML 转换。
- 因此 APP 端设置的字体颜色等格式可完全保留，同时不影响 Web 端读取。
- **颜色格式归一化**：flutter_quill 的 `colorToHex` 输出 8 位 ARGB 色值（如 `#FF1E88E5`），而 HTML 转换库 `vsc_quill_delta_to_html` 默认会丢弃 8 位色值，导致 `content` HTML 丢失颜色、阅读视图变黑。`getHtml()`/`getDelta()` 在导出前统一把 `color`/`background` 的 8 位 `#AARRGGBB` 归一化为 6 位 `#RRGGBB`（alpha 固定为 FF，丢弃无影响），确保颜色在阅读视图、Web 端与服务器消毒后均完整保留。

## "仅今日可编辑"判定

- APP 端以**手机本地时钟**判定"今天"（`life_screen.dart` 的 `_todayStr()`），因此设备时钟应保持准确。
- 服务器端保存校验使用容错判定（`web/inc/history.php` 的 `historyIsEditableDate()`），容忍服务器与设备跨午夜时最多 1 天的时钟偏差（服务器"昨天"在 03:00 前可编辑、服务器"明天"在 21:00 后可编辑），避免因服务器时钟漂移误拒绝当日记录。
- **建议保持服务器系统时钟 NTP 同步**（`timedatectl set-ntp true`），这是各类时间功能一致性的根本保证。

## 打包

提交代码到 main 分支后，GitHub Actions 自动构建 APK。

构建产物在 Actions → Build APK → Artifacts 下载（`listenwrite-release.apk`）。

> **⚠️ 版本发布流程**：
> 1. 修改 `pubspec.yaml` 中的 `version`（如 `1.0.2+1`）
> 2. 同步更新 `lib/config/api_config.example.dart` 与 `.github/workflows/build.yml` 中的 `appVersion`
> 3. 推送触发 CI 构建，产物即为正式 APK

## 与后端的对接

- API 入口：`POST http://localhost:8000/web/app_api.php`
- 鉴权方式：`Authorization: Bearer <token>`
- 完整接口清单见 [`../web/README.md`](../web/README.md) 的「APP API 说明」章节