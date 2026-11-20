# S2b 后台一键更新 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox syntax.

**Goal:** 管理员在后台点“检查更新 → 开始更新”，系统进入维护模式、备份 JSON/config、校验下载 `web-v*` 源码包、执行累积迁移、替换代码、清理 opcache、恢复服务；失败可回滚。

**Architecture:** PHP 同步执行更新（`ignore_user_abort(true)` + 先返回 JSON/`fastcgi_finish_request`），不依赖外部进程/UAC。更新期间 `web/inc/db.php` 对非 admin 请求返回 503 维护页；更新器用 `web/data/.update/` 暂存，`backups/<version>-<time>/` 备份 JSON/config + 当前代码 zip。仅允许 `github.com/CH-Hu-Bill/MoXie/releases/download/web-v*` 资产 + sha256。

**Spec:** `docs/superpowers/specs/2026-11-20-v2-design.md` 第 7 节。

## Global Constraints

- 仅管理员 + CSRF；只允许 GitHub 官方 release 资产。
- 不触碰 `web/data/` 媒体与 `web/inc/config.php`（备份但不覆盖）。
- 迁移必须幂等；失败回滚 JSON/config + 代码 zip。
- 不自动升级 PHP/ffmpeg；若目标要求运行时升级，显示人工提示。
- 维护模式可手动退出，防止更新器异常导致永久 503。

## Review Focus

- 更新失败时 `web/data/*.json` 必须恢复，不能留下半迁移数据。
- 源码 zip 不能包含 `web/data/classes`、`config.php`、`apk/`；校验失败必须拒绝。
- 维护模式不能锁死管理员；`admin.php`/`update_status.php`/`version.php` 始终可访问。
- 更新完成后旧页面应提示刷新；`opcache_reset()` 必须调用。
- 旧 APP 1.2.2 在更新期间不应写坏数据（维护模式拒绝写请求）。

---

## Task 1: GitHub 更新检查与校验解析

**Files:** Create `web/inc/update.php`; Create `tests/php/update_test.php`.

**Interfaces:**
- `lwParseWebRelease(array $release): ?array` → `['version','tag','zip_url','sha_url','notes','published_at']`；只接受 `web-v*`、非 draft、含 `.zip`/`.sha256`。
- `lwParseSha256(string $text): ?string` → 64 hex。
- `lwIsAllowedAssetUrl(string $url): bool` → `https://github.com/CH-Hu-Bill/MoXie/releases/download/web-v...`.

- [ ] 写测试（合法 release、非法 tag、缺资产、sha 解析、URL 白名单）→ 失败 → 实现 → 通过。
- [ ] `lwCheckWebUpdate()` 用 curl 拉 GitHub API，返回当前/最新/notes；失败返回错误。
- [ ] 提交。

## Task 2: 维护模式

**Files:** Create `web/inc/maintenance.php`; Modify `web/inc/db.php`; Create `web/update_status.php`.

**Interfaces:**
- `lwInMaintenance(): bool`；`lwSetMaintenance(bool)`。
- `db.php` 非 CLI 且在维护模式时，除非 `SCRIPT_NAME` 是 `admin.php`/`update_status.php`/`version.php`，输出 503 维护页并 exit。
- `update_status.php` 返回 `status.json`（stage/message/version/updated_at）。

- [ ] 测试 `lwInMaintenance` 文件读写（临时目录）。
- [ ] 接入 `db.php`；手动访问普通页 → 503，admin → 200。
- [ ] 提交。

## Task 3: 备份、下载、迁移、代码替换、回滚

**Files:** Create `web/bin/apply_update.php`; Modify `web/inc/update.php`.

**Interfaces:**
- `lwApplyWebUpdate(array $release): array`：
  1. `lwSetMaintenance(true)`；
  2. 备份 `web/data/*.json` + `web/inc/config.php` + 当前 `web/` 代码 zip 到 `backups/<version>-<time>/`；
  3. 下载 zip + sha，校验；
  4. 解压到 `web/data/.update/staging/`，定位 `web/`；
  5. `lwRunMigrations($dataDir)`（staging 的 migrations）；
  6. 递归复制 staging `web/` → `web/`，排除 `data/`、`apk/`、`inc/config.php`；
  7. `opcache_reset()`；`lwSetMaintenance(false)`；写 status done；
  8. 失败：恢复 JSON/config + 代码 zip，写 status error，保留维护模式并允许手动退出。

- [ ] 用临时目录 + 假 zip 测试 `lwCopyWebTree` 排除规则与回滚恢复。
- [ ] 下载/校验失败测试（错误 sha 必须拒绝）。
- [ ] 提交。

## Task 4: 后台 UI

**Files:** Modify `web/admin.php`.

- [ ] 顶部新增“系统更新”卡片：当前 Web 版本、检查按钮、目标版本/说明、开始更新按钮（二次确认）、状态文本。
- [ ] `POST action=apply_web_update`：CSRF + admin；校验 release；先返回 `{success:true,started:true}` 并 flush，再同步执行 `lwApplyWebUpdate`（`ignore_user_abort(true)`、`set_time_limit(0)`）。
- [ ] 前端每 2 秒 GET `update_status.php`，显示阶段；完成后提示刷新。
- [ ] 手动“退出维护模式”按钮（仅 admin）。
- [ ] 提交。

## Task 5: 验证

- [ ] 本机用当前 `web-v2.0.0` release 做一次真实更新（从 2.0.0 到 2.0.0 或临时 2.0.1）：备份生成、迁移 no-op、维护模式、恢复 200。
- [ ] 故意用错误 sha：更新失败，JSON 未变，维护模式可退出。
- [ ] `php -l` + 全部 PHP 测试 + 浏览器验收。
- [ ] 更新 HANDOVER/使用说明。

## 执行方式

- Native 本会话实现；每个 Task 提交并报告。
- 先做 Task 1-3 后端，再做 Task 4 UI；Task 5 用真实 release 演练。
