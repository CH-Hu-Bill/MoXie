# S2a 版本治理与独立发布 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Web 与 APP 各自拥有 2.0.0 版本号、独立 tag/Release、双向最低版本检查；APP 分包为 arm64/armeabi-v7a；加入 Codespaces 开发环境。一键更新放 S2b。

**Architecture:** Web 用 `web/inc/version.php` 作为唯一版本源，`web/version.php` 输出 JSON；`check_version` 返回 `latest/min_supported/force_update/web_version/min_web_version`。旧 APP 忽略新字段，API 保持兼容；新 APP 启动时硬阻断低于最低版本或服务器过旧。GitHub Actions 用 `web-v*` 发布源码 zip、`app-v*` 发布两个 ABI 的 APK，并同步 `apk-latest` 指针。

**Tech Stack:** PHP 7.4+、Flutter 3.50.1（固定）、Gradle 9.1.0（S2 暂保留 `--android-skip-build-dependency-validation`）、GitHub Actions、Codespaces。

**Spec:** `docs/superpowers/specs/2026-11-20-v2-design.md`

## Global Constraints

- 不引入数据库；不改现有 API 字段语义，只增字段。
- 旧 APP 1.2.2 必须继续可用；服务端不按 APP 版本拒绝普通写请求。
- 版本号格式 `MAJOR.MINOR.PATCH`；APP build number 递增。
- 不提交 `web/data/`、`web/inc/config.php`、keystore、`api_config.dart`。
- 所有 Release 资产命名固定，下载页/更新器依赖这些名字。
- CI 固定 Flutter `3.50.1`；S2 暂不升级 Gradle，保留 skip flag。

## Review Focus

- 旧 APP 1.2.2 调 `check_version` 时，新增字段不影响其解析。
- 服务器过旧（`web_version < min_web_version`）时，新 APP 必须阻断而不是继续写数据。
- APP 低于 `min_supported` 时只能去下载，不能关闭对话框继续用。
- 分包后 `apk-latest` 仍同时提供 arm64/armv7；下载页不能只给一个包。
- `web-v*` 的 zip 不能包含 `web/data/`、`web/inc/config.php`、`web/apk/`。
- 版本号在 `version.php`/`pubspec.yaml`/`build.yml`/README 必须一致。

---

## Task 1: Web 版本源与 JSON 接口

**Files:**
- Create: `web/inc/version.php`
- Create: `web/version.php`
- Modify: `web/inc/head.php`（注入 `LW_WEB_VERSION`）
- Modify: `web/admin.php`（后台显示 Web 版本）
- Test: `tests/php/version_test.php`

**Interfaces:**
- `LW_WEB_VERSION = '2.0.0'`
- `LW_API_VERSION = 2`
- `LW_MIN_APP_VERSION = '2.0.0'`
- `LW_MIN_WEB_VERSION = '2.0.0'`
- `GET version.php` 返回：
  ```json
  {"web_version":"2.0.0","api_version":2,"min_app_version":"2.0.0","min_web_version":"2.0.0","released_at":"2026-11-20"}
  ```

- [ ] **Step 1: 写测试**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/version.php';
checkSame('2.0.0', LW_WEB_VERSION, 'web version');
checkSame(2, LW_API_VERSION, 'api version');
checkSame('2.0.0', LW_MIN_APP_VERSION, 'min app');
checkSame('2.0.0', LW_MIN_WEB_VERSION, 'min web');
done();
```

- [ ] **Step 2: 运行失败**

Run: `php tests/php/version_test.php`
Expected: FAIL，文件不存在。

- [ ] **Step 3: 实现版本文件与 JSON 端点**

`web/inc/version.php` 定义常量 + `lwVersionPayload(): array`。`web/version.php` 设置 `Content-Type: application/json`、`Cache-Control: no-store`，输出 `lwVersionPayload()`。

`web/inc/head.php` 在 `<head>` 内输出：
```html
<script>window.LW_WEB_VERSION=<?php echo json_encode(LW_WEB_VERSION); ?>;</script>
```
需要 `require_once __DIR__ . '/version.php';`。

`web/admin.php` 在导航栏右侧加 `<span>Web v2.0.0</span>`（实际用 `LW_WEB_VERSION`）。

- [ ] **Step 4: 测试 + curl**

Run:
```bash
php tests/php/version_test.php
php -l web/inc/version.php; php -l web/version.php
curl.exe -s http://127.0.0.1:8000/version.php
```
Expected: `checks=4 failures=0`；JSON 含 `web_version`。

- [ ] **Step 5: 提交**

```bash
git add web/inc/version.php web/version.php web/inc/head.php web/admin.php tests/php/version_test.php
git commit -m "feat(version): Web 2.0.0 版本源与 JSON 接口"
```

---

## Task 2: 扩展 check_version + APP 启动阻断

**Files:**
- Modify: `web/app_api.php`（`check_version`）
- Modify: `app/myapp/lib/config/api_config.example.dart`（`minWebVersion`）
- Modify: `app/myapp/lib/screens/home_screen.dart`（启动检查）
- Modify: `app/myapp/lib/screens/profile/profile_screen.dart`（强制更新对话框）
- Modify: `.github/workflows/build.yml`（生成 `minWebVersion`）
- Test: `tests/php/version_test.php`（服务端字段）

**Interfaces:**
- `check_version` 返回新增：
  - `min_supported`: string
  - `force_update`: bool（`current < min_supported`）
  - `web_version`: string
  - `min_web_version`: string
  - `api_version`: int
- `ApiConfig.minWebVersion = '2.0.0'`。
- `HomeScreen` 启动后调用 `AuthProvider.checkVersion()`：
  - `force_update == true` → 不可关闭对话框，只有“去下载”；
  - `web_version < min_web_version` → 不可关闭对话框“服务器版本过旧，请联系管理员更新”；
  - 否则 `has_update` → 现有可关闭提示。

- [ ] **Step 1: 服务端实现 + 测试**

在 `check_version` 中 `require_once __DIR__ . '/inc/version.php';`，按上面字段返回。测试断言 `force_update` 对 `1.2.2` 为 true、对 `2.0.0` 为 false（用纯函数 `appVersionGate($current, $latest, $minSupported, $webVersion, $minWeb)` 放到 `app_lib.php`，便于测试）。

- [ ] **Step 2: APP 启动检查**

`HomeScreen.initState` 的 postFrame 里调用 `_checkVersionGate()`。对话框：
```dart
showDialog(
  barrierDismissible: false,
  builder: (_) => AlertDialog(
    title: Text('需要更新 APP'),
    content: Text('当前版本 ${ApiConfig.appVersion} 低于最低支持版本 $min。请下载 2.0.0。'),
    actions: [TextButton(onPressed: () => Clipboard.setData(...), child: Text('复制下载地址'))],
  ),
);
```
复制 `ApiConfig.baseUrl + '/download_app.php'`，不新增依赖。

- [ ] **Step 3: 固定版本号**

`pubspec.yaml` → `2.0.0+5`；`api_config.example.dart` → `appVersion='2.0.0'`、`minWebVersion='2.0.0'`；`build.yml` 生成代码里 `appVersion='2.0.0'`、`minWebVersion='2.0.0'`。

- [ ] **Step 4: 测试 + analyze**

Run:
```bash
php tests/php/version_test.php
php -l web/app_api.php
```
CI: `flutter analyze --no-fatal-warnings --no-fatal-infos`.

- [ ] **Step 5: 提交**

```bash
git add web/app_api.php web/inc/app_lib.php tests/php/version_test.php app/myapp/lib/config/api_config.example.dart app/myapp/lib/screens/home_screen.dart app/myapp/lib/screens/profile/profile_screen.dart app/myapp/pubspec.yaml .github/workflows/build.yml
git commit -m "feat(version): 双向最低版本检查与 APP 启动阻断"
```

---

## Task 3: APP 分包 + 固定 Flutter

**Files:**
- Modify: `.github/workflows/build.yml`
- Modify: `app/myapp/pubspec.yaml`（2.0.0+5）

**Interfaces:**
- 构建命令：`flutter build apk --release --split-per-abi --android-skip-build-dependency-validation`
- 产物：`app-arm64-v8a-release.apk`、`app-armeabi-v7a-release.apk`。
- Flutter 固定 `3.50.1`。

- [ ] **Step 1: 修改 workflow**

`subosito/flutter-action@v2` 加 `flutter-version: '3.50.1'`。构建命令加 `--split-per-abi`。Artifact 上传两个 APK。

- [ ] **Step 2: 手动跑一次**

Run: `gh workflow run build.yml --ref feat/2.0`
Expected: 两个 APK artifact；`Upload test APK` 成功。

- [ ] **Step 3: 记录体积**

下载 artifact，记录 arm64/armv7 大小；目标明显小于 70.9 MB。

- [ ] **Step 4: 提交**

```bash
git add .github/workflows/build.yml app/myapp/pubspec.yaml
git commit -m "build(app): 固定 Flutter 3.50.1 并分包 arm64/armv7"
```

---

## Task 4: APP 正式 Release + apk-latest 指针

**Files:**
- Modify: `.github/workflows/build.yml`

**Interfaces:**
- `on.push.tags: ['app-v*']` + `workflow_dispatch`。
- tag 构建：Release `app-v${VERSION}`，资产两个 APK。
- 同步 `apk-latest` Release（同名资产、`overwrite_files: true`）。
- 非 tag 手动构建只上传 artifact，不发 Release。

- [ ] **Step 1: 实现版本提取**

```bash
VERSION=$(grep '^version:' pubspec.yaml | awk '{print $2}' | cut -d'+' -f1)
echo "VERSION=$VERSION" >> $GITHUB_ENV
```

- [ ] **Step 2: Release 两个 tag**

`softprops/action-gh-release@v2` 两次：`tag_name: app-v${VERSION}`（仅 tag 事件）和 `tag_name: apk-latest`（仅 tag 事件）。`files` 两个 APK。

- [ ] **Step 3: 验证**

打 tag `app-v2.0.0` 推送，检查两个 Release 都有两个 APK；`apk-latest` 指向同一文件。

- [ ] **Step 4: 提交**

```bash
git add .github/workflows/build.yml
git commit -m "ci(app): 按版本发布 APK 并维护 apk-latest"
```

---

## Task 5: Web 源码 Release

**Files:**
- Create: `.github/workflows/release-web.yml`

**Interfaces:**
- `on.push.tags: ['web-v*']`
- 资产：`ListenWrite-web-${VERSION}.zip`，包含仓库源码，排除 `web/data/`、`web/inc/config.php`、`web/apk/`、`runtime/`、`offline/`。
- Release tag 即 `web-v${VERSION}`。

- [ ] **Step 1: 实现 workflow**

```yaml
- run: |
    VERSION="${GITHUB_REF_NAME#web-v}"
    git archive --format=zip --prefix=ListenWrite-web-${VERSION}/ -o ListenWrite-web-${VERSION}.zip HEAD
- uses: softprops/action-gh-release@v2
  with:
    tag_name: ${{ github.ref_name }}
    files: ListenWrite-web-*.zip
```

`git archive` 不含 ignored 的 `web/data`/`config.php`/`apk`；在 workflow 里再 `zip -d` 防御性排除。

- [ ] **Step 2: 验证**

打 tag `web-v2.0.0`，检查 Release 资产可通过 `unzip -l` 看到 `web/version.php`，且没有 `data/`、`config.php`。

- [ ] **Step 3: 提交**

```bash
git add .github/workflows/release-web.yml
git commit -m "ci(web): 按 web-v* tag 发布源码 zip"
```

---

## Task 6: 下载页双 ABI + Codespaces

**Files:**
- Modify: `web/download_app.php`
- Create: `.devcontainer/devcontainer.json`
- Create: `.devcontainer/README.md`

**Interfaces:**
- 下载页两个按钮：
  - `https://github.com/CH-Hu-Bill/MoXie/releases/download/apk-latest/app-arm64-v8a-release.apk`
  - `.../app-armeabi-v7a-release.apk`
- 保留“其他版本” GitHub Release 链接。
- Codespaces 镜像包含 Flutter + Android SDK；`postCreateCommand` 运行 `flutter pub get`。

- [ ] **Step 1: 修改下载页**

用 `app_versions.json` 的版本/说明；两个 `<a>` 按钮；说明“大多数手机选 arm64，较旧手机选 armeabi-v7a”。

- [ ] **Step 2: 写 devcontainer**

`ghcr.io/cirruslabs/flutter:stable` + Java 17；`postCreateCommand: cd app/myapp && flutter pub get`；README 写明 `flutter analyze`/`test`/`build` 命令与 secrets 注意事项。

- [ ] **Step 3: 验证**

浏览器打开 `download_app.php`，两个链接可点；Codespaces 配置通过 JSON 校验（`python -m json.tool` 或 `node -e`）。

- [ ] **Step 4: 提交**

```bash
git add web/download_app.php .devcontainer/
git commit -m "feat(release): 双 ABI 下载页与 Codespaces 开发环境"
```

---

## Task 7: 2.0.0 版本文档与发布

**Files:**
- Modify: `README.md`
- Modify: `app/README.md`
- Modify: `HANDOVER.md`（仅版本/发布段落，保留用户现有 dev.ps1 改动）
- Modify: `web/docs/使用说明.md`（版本与下载说明）

- [ ] **Step 1: 统一版本号**

Web `2.0.0`、APP `2.0.0`；四处一致（`version.php`、`pubspec`、`build.yml`、README）。

- [ ] **Step 2: 写 2.0.0 发布说明**

列出 S0/S1/S2 内容；注明 HTTP 支持与风险；注明 APK 两个 ABI；注明旧 APP 1.2.2 仍可用。

- [ ] **Step 3: 打 tag**

```bash
git tag web-v2.0.0
git tag app-v2.0.0
git push origin web-v2.0.0 app-v2.0.0
```

- [ ] **Step 4: 验证 Release**

两个 tag 的 Release 资产齐全；下载页两个 APK 链接 200。

---

## 执行方式

- 用户选择分步验收；本计划按 Task 1→7 顺序执行。
- 推荐 Native（本会话直接实现），每完成一个 Task 报告；Task 3/4/5 需要 GitHub Actions 实跑，等待结果。
- 不使用独立 worktree，继续在 `feat/2.0` 原地开发。
- S2b（一键更新 + 维护模式 + 备份/迁移/回滚）单独出计划，避免与发布链路混在一起。
