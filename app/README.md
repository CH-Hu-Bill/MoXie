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
│   └── gallery_item.dart       # 画廊模型
├── services/
│   ├── api_service.dart        # API 请求封装
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
    └── hand_drawn_widgets.dart # 手绘风格通用组件
```

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