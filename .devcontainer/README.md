# Codespaces / Dev Container

用于在云端开发 Flutter APP，避免本机安装 JDK/Flutter/Android SDK。

## 启动

1. GitHub 仓库 → Code → Codespaces → Create codespace on `feat/2.0`（或 `main`）。
2. 首次创建会自动执行 `cd app/myapp && flutter pub get`。
3. 建议 4 核 / 16 GB 配置；2 核 / 8 GB 可能在 Gradle 阶段较慢。

## 常用命令

```bash
cd app/myapp

# 静态检查（只阻断 error）
flutter analyze --no-fatal-warnings --no-fatal-infos

# 单元测试
flutter test test/gallery_item_test.dart

# 本地调试包（不签名发布）
flutter build apk --debug
```

## 端点配置

`lib/config/api_config.dart` 不入库。Codespaces 里复制模板后修改：

```bash
cp lib/config/api_config.example.dart lib/config/api_config.dart
# 编辑 baseUrl，例如 http://192.168.12.164:8000
```

GitHub Actions 构建时会用 `API_BASE_URL` Secret 自动生成该文件。

## 发布

正式 APK 只走 GitHub Actions：

```bash
git tag app-v2.0.0
git push origin app-v2.0.0
```

Actions 会构建 `arm64-v8a` 与 `armeabi-v7a` 两个包，并同步 `apk-latest` 指针。
**不要把 keystore、`key.properties`、真实 `API_BASE_URL` 提交到仓库。**
