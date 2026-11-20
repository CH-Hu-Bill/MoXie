# S0+S1 数据安全与 APP 实况照片 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 先消除史记保存误删图集等数据风险，再让 APP 能识别、上传并播放安卓实况照片（Motion Photo），Web 端也能展示。

**Architecture:** Web 保持 PHP + JSON + `uploads/` 文件存储；新增 `web/inc/app_lib.php`（纯函数）、`web/inc/motion_photo.php`（提取 Motion Photo 内嵌 MP4）、`web/inc/migrations.php`（schema 版本骨架）。APP 新增纯 Dart 依赖 `motion_photos`（MIT），选图后本地提取 MP4，与静态图一起 multipart 上传；服务端存 `type=live` + `live_video`，同时保留原始 Motion Photo 自动提取作为 Web/旧 APP 兜底。

**Tech Stack:** PHP 7.4+（本机 8.4）、原生 JS/CSS、Flutter/Dart、`motion_photos ^1.0.0`、`xml ^6.2.2`（传递依赖）、PHP 原生测试脚本、GitHub Actions。

**Spec:** `docs/superpowers/specs/2026-11-20-v2-design.md`

## Global Constraints

- 继续使用 JSON + 文件存储，不引入数据库、不新增 Composer 依赖。
- 不提交 `web/data/`、`web/inc/config.php`、`web/apk/`、`app/myapp/lib/config/api_config.dart`、keystore。
- 新依赖必须 MIT/兼容 GPL-3.0；`motion_photos` 为 MIT，保留署名。
- S1 沿用现有上限：静态图 12 MB、GIF 16 MB、MP4 15 MB / 30 秒；1 GB/分片上传属于 S5，不在本计划。
- 改 `web/common.css` / `web/common.js` 时，必须同步提升引用处 `?v=` 版本号。
- 所有新增用户可见文案用中文，风格与现有手绘 UI 一致。
- 本机没有 Flutter/Java/Android SDK；APP 只通过 GitHub Actions/Codespaces 验证，不在本机 `flutter build`。
- 每个任务结束必须提交；不要混合无关改动。

## Review Focus

- 普通 JPEG 走同一上传路径时不能被误判为实况；必须是 `type=static`。
- 实况视频提取失败、超过 15 MB、MP4 guard 不通过时，必须退回静态图并保留上传，不能整单失败。
- 实况视频文件被删除/丢失后，图集刷新不能崩溃，卡片退回静态图。
- 保存史记不得删除图集文件，包括只被 `gallery_trash.json` 引用的文件（S5 会加）。
- 删除单词后：空任务从 pending/history 都消失；同一单词被多个任务引用时，非空任务只移除该词，不删除任务。
- 错题本刷新后 `isWrong` 不能丢；旧服务端不返回 `is_wrong` 时客户端兜底仍能移出。
- 删除最后一个词的任务时，`last_task_id_{classId}` 不能继续指向已删除任务。

---

## S0 数据安全

### Task 1: 修复史记保存误删图集

**Files:**
- Create: `tests/php/bootstrap.php`
- Create: `tests/php/history_cleanup_test.php`
- Modify: `web/inc/history.php`（`historyCleanupOrphanImages` 及其上方新增两个纯函数）

**Interfaces:**
- Produces:
  - `historyCollectReferencedUploadNames(array $history, array $gallery, array $trash): array` — 返回 `filename => true`；`history` 用 `historyExtractImageRefs` 解析，`gallery`/`trash` 读取 `image` 字段。
  - `historyPruneUploads(string $uploadsDir, array $referenced): array` — 只删除 `^[a-f0-9]{32}\.(jpg|png|webp)$` 且不在 `$referenced` 的文件，返回被删除文件名数组。
  - `historyCleanupOrphanImages($classId, $historyKey)` 改为读取 `gallery` 与 `gallery_trash` 后调用以上两个函数。

- [ ] **Step 1: 写失败测试**

`tests/php/bootstrap.php`：

```php
<?php
$GLOBALS['__checks'] = 0;
$GLOBALS['__failures'] = 0;
function check($cond, string $msg): void {
    $GLOBALS['__checks']++;
    if (!$cond) { $GLOBALS['__failures']++; fwrite(STDERR, "FAIL: $msg\n"); }
}
function checkSame($expected, $actual, string $msg): void {
    check($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function done(): void {
    echo "checks={$GLOBALS['__checks']} failures={$GLOBALS['__failures']}\n";
    exit($GLOBALS['__failures'] === 0 ? 0 : 1);
}
```

`tests/php/history_cleanup_test.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/history.php';

$refA = str_repeat('a', 32) . '.jpg';
$refB = str_repeat('b', 32) . '.jpg';
$refC = str_repeat('c', 32) . '.jpg';
$refs = historyCollectReferencedUploadNames(
    [['content' => '<img src="upload.php?class_id=x&file=' . $refA . '">']],
    [['image' => $refB]],
    [['image' => $refC]]
);
check(isset($refs[$refA]), 'history ref collected');
check(isset($refs[$refB]), 'gallery ref collected');
check(isset($refs[$refC]), 'trash ref collected');

$dir = sys_get_temp_dir() . '/lw-hc-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$unref = str_repeat('d', 32) . '.jpg';
foreach ([$refA, $refB, $refC, $unref] as $f) file_put_contents($dir . '/' . $f, 'x');
$deleted = historyPruneUploads($dir, $refs);
check(in_array($unref, $deleted, true), 'unreferenced deleted');
check(!file_exists($dir . '/' . $unref), 'unreferenced file gone');
check(file_exists($dir . '/' . $refA), 'history file kept');
check(file_exists($dir . '/' . $refB), 'gallery file kept');
check(file_exists($dir . '/' . $refC), 'trash file kept');
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);
done();
```

- [ ] **Step 2: 运行测试确认失败**

Run: `php tests/php/history_cleanup_test.php`
Expected: FAIL，提示 `historyCollectReferencedUploadNames` 未定义。

- [ ] **Step 3: 实现两个纯函数并改写清理入口**

在 `web/inc/history.php` 的 `historyCleanupOrphanImages` 上方新增上述两个函数；`historyCleanupOrphanImages` 内：

```php
$history = Database::getClassData($classId, $historyKey);
$gallery = Database::getClassData($classId, 'gallery');
$trash = Database::getClassData($classId, 'gallery_trash');
$referenced = historyCollectReferencedUploadNames(
    is_array($history) ? $history : [],
    is_array($gallery) ? $gallery : [],
    is_array($trash) ? $trash : []
);
historyPruneUploads(Database::getUploadsDirectory($classId), $referenced);
```

不要在 `historyPruneUploads` 里删除 gif/mp4 或 thumbs。

- [ ] **Step 4: 运行测试确认通过**

Run: `php tests/php/history_cleanup_test.php`
Expected: `checks=8 failures=0`，退出码 0。

- [ ] **Step 5: 语法检查 + 提交**

```bash
php -l web/inc/history.php
php -l tests/php/history_cleanup_test.php
git add web/inc/history.php tests/php/bootstrap.php tests/php/history_cleanup_test.php
git commit -m "fix(history): 保存史记时不再误删图集图片"
```

---

### Task 2: 删除空任务并清理历史

**Files:**
- Create: `web/inc/tasks.php`
- Create: `tests/php/empty_task_test.php`
- Modify: `web/words.php`（`delete_word` 分支）
- Modify: `web/app_api.php`（`get_tasks` 过滤 `word_count > 0`）

**Interfaces:**
- Produces: `tasksRemoveWordAndPrune(array $tasks, string $wordId): array`，返回 `['tasks' => array, 'removed_ids' => string[]]`。
  - 遍历每个任务，从 `word_ids` 移除 `$wordId`；
  - 若移除后 `word_ids` 为空，删除该任务并记录 `id`；
  - 非空任务保持原 status/date/label。
- Consumes: `Database::updateClassData($classId, 'tasks', ...)`。

- [ ] **Step 1: 写失败测试**

`tests/php/empty_task_test.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/tasks.php';

$tasks = [
    't1' => ['id' => 't1', 'status' => 'completed', 'word_ids' => ['w1']],
    't2' => ['id' => 't2', 'status' => 'pending', 'word_ids' => ['w1', 'w2']],
    't3' => ['id' => 't3', 'status' => 'pending', 'word_ids' => ['w2']],
];
$r = tasksRemoveWordAndPrune($tasks, 'w1');
check(!isset($r['tasks']['t1']), 'empty completed task removed');
checkSame(['w2'], array_values($r['tasks']['t2']['word_ids']), 'non-empty task keeps other word');
check(isset($r['tasks']['t3']), 'unrelated task kept');
checkSame(['t1'], array_values($r['removed_ids']), 'removed id reported');
done();
```

- [ ] **Step 2: 运行测试确认失败**

Run: `php tests/php/empty_task_test.php`
Expected: FAIL，`tasksRemoveWordAndPrune` 未定义。

- [ ] **Step 3: 实现 `tasksRemoveWordAndPrune`**

`web/inc/tasks.php`：

```php
<?php
function tasksRemoveWordAndPrune(array $tasks, string $wordId): array {
    $removed = [];
    foreach ($tasks as $tid => $task) {
        $ids = array_values(array_filter($task['word_ids'] ?? [], function($id) use ($wordId) {
            return (string)$id !== $wordId;
        }));
        if (count($ids) === 0) {
            $removed[] = (string)($task['id'] ?? $tid);
            unset($tasks[$tid]);
        } else {
            $tasks[$tid]['word_ids'] = $ids;
        }
    }
    return ['tasks' => $tasks, 'removed_ids' => $removed];
}
```

- [ ] **Step 4: 接入 `delete_word`**

`web/words.php` 顶部 `require_once 'inc/tasks.php';`。`delete_word` 分支在 `Database::saveWords` 后：

```php
$prune = ['removed_ids' => []];
Database::updateClassData($classId, 'tasks', function($tasks) use ($wordId, &$prune) {
    $r = tasksRemoveWordAndPrune(is_array($tasks) ? $tasks : [], $wordId);
    $prune = $r;
    return $r['tasks'];
});
foreach ($prune['removed_ids'] as $removedTaskId) {
    if (($settings['last_task_id_' . $classId] ?? '') === $removedTaskId) {
        unset($settings['last_task_id_' . $classId]);
        Database::saveSettings($settings);
    }
}
```

注意：`$settings` 在文件上部已加载；若为空，仍要调用。

- [ ] **Step 5: `get_tasks` 兜底过滤**

`web/app_api.php` 的 `get_tasks` 列表构造处，跳过 `word_count == 0` 的任务（保留现有 `word_count` 计算）。这样即使旧数据里有空任务，APP 也不显示。

- [ ] **Step 6: 运行测试 + 语法检查**

Run:
```bash
php tests/php/empty_task_test.php
php -l web/inc/tasks.php
php -l web/words.php
php -l web/app_api.php
```
Expected: `checks=4 failures=0`，三个 `No syntax errors`。

- [ ] **Step 7: 提交**

```bash
git add web/inc/tasks.php web/words.php web/app_api.php tests/php/empty_task_test.php
git commit -m "fix(tasks): 删除最后一个内容后移除空任务与历史"
```

---

### Task 3: 修复错题本 `is_wrong` 丢失

**Files:**
- Create: `tests/php/app_lib_test.php`
- Modify: `web/inc/app_lib.php`（新建，放 `appApplyWrongFlags`）
- Modify: `web/app_api.php`（`require_once`、`get_wrong_words`、`search_all`）
- Modify: `app/myapp/lib/screens/study/study_screen.dart`（`_loadWrongWords` 兜底）
- Modify: `app/myapp/lib/models/vlog_entry.dart`（`WordMatch.isWrong`）
- Modify: `app/myapp/lib/screens/search/search_screen.dart`（用 `w.isWrong` 初始化/显示）

**Interfaces:**
- Produces: `appApplyWrongFlags(array $words, array $wrongMap): array` — 对每个元素设置 `is_wrong` 为 `isset($wrongMap[(string)$w['id']])`。
- `WordMatch` 增加 `final bool isWrong;`，`fromJson` 读 `json['is_wrong'] ?? false`。

- [ ] **Step 1: 写服务端失败测试**

`tests/php/app_lib_test.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/app_lib.php';

$words = [
    ['id' => 'w1', 'word' => 'a', 'meaning' => '甲'],
    ['id' => 'w2', 'word' => 'b', 'meaning' => '乙'],
];
$out = appApplyWrongFlags($words, ['w1' => ['marked_at' => '2026-01-01']]);
checkSame(true, $out[0]['is_wrong'], 'wrong word flagged');
checkSame(false, $out[1]['is_wrong'], 'normal word not flagged');
done();
```

- [ ] **Step 2: 运行确认失败**

Run: `php tests/php/app_lib_test.php`
Expected: FAIL，`appApplyWrongFlags` 未定义。

- [ ] **Step 3: 实现并接入服务端**

`web/inc/app_lib.php`：

```php
<?php
function appApplyWrongFlags(array $words, array $wrongMap): array {
    foreach ($words as $i => $w) {
        $words[$i]['is_wrong'] = isset($wrongMap[(string)($w['id'] ?? '')]);
    }
    return $words;
}
```

`web/app_api.php` 顶部 `require_once 'inc/app_lib.php';`。

`get_wrong_words`：

```php
$list = appApplyWrongFlags($list, $wrongMap);
```

`search_all`：

```php
$user = Database::getUser($userId);
$wrongMap = $user['wrong_words'][$classId] ?? [];
$wordMatches = appApplyWrongFlags($wordMatches, $wrongMap);
```

任务里的 `matched_words` 同样在 `$matched` 构造完成后调用 `appApplyWrongFlags($matched, $wrongMap)`。

- [ ] **Step 4: 运行服务端测试**

Run: `php tests/php/app_lib_test.php`
Expected: `checks=2 failures=0`。

- [ ] **Step 5: 客户端兜底**

`study_screen.dart` 的 `_loadWrongWords`：

```dart
final words = (data['words'] as List)
    .map((w) => Word.fromJson(w as Map<String, dynamic>).copyWith(isWrong: true))
    .toList();
```

`vlog_entry.dart` 的 `WordMatch` 增加 `isWrong` 字段（构造器默认 `false`，`fromJson` 读 `is_wrong`）。

`search_screen.dart`：
- `_search()` 成功后把 `words/pending_tasks/history_tasks` 里 `isWrong == true` 的 id 加入 `_wrongWordIds`。
- `_buildWordMatchCard` 的 `isWrong` 改为 `_wrongWordIds.contains(w.id) || w.isWrong`。

- [ ] **Step 6: 静态检查 + 提交**

Run（CI/Codespaces）：`cd app/myapp && flutter analyze`
Expected: 0 error。

```bash
php -l web/inc/app_lib.php
php -l web/app_api.php
git add web/inc/app_lib.php web/app_api.php app/myapp/lib/screens/study/study_screen.dart app/myapp/lib/models/vlog_entry.dart app/myapp/lib/screens/search/search_screen.dart tests/php/app_lib_test.php
git commit -m "fix(wrong-book): 服务端返回 is_wrong 并在客户端兜底"
```

---

### Task 4: Web 搜索高亮随操作清除

**Files:**
- Modify: `web/words.php`（`runInitialLocate` 之后的交互清理）
- Modify: `web/task.php`（`highlightId` 的 `follow-highlight` 清理）
- Modify: `web/history.php`（支持 `highlight` 并同样清理）

**Interfaces:** 无新函数；行为契约：
- URL 带 `highlight` 首次进入时高亮；
- `pointerdown`/`wheel`/`keydown`/`touchstart`/打开任何弹窗/创建任务/上传完成 后移除高亮；
- 用 `history.replaceState` 去掉 URL 里的 `highlight`，刷新不再复现。

- [ ] **Step 1: 写手动验收步骤（浏览器）**

启动 `.\scripts\dev.ps1 start`，打开 `main.php` 搜索一个词，点击结果进入 `words.php?highlight=...`；滚动或打开编辑弹窗后，高亮应消失；刷新页面不应再高亮。

- [ ] **Step 2: 修改 `words.php`**

在 `clearLocateHighlight` 内同时移除 `.locate-highlight` 与 `.follow-highlight`，并：

```js
const u = new URL(location.href);
if (u.searchParams.has('highlight')) {
    u.searchParams.delete('highlight');
    history.replaceState(null, '', u.pathname + (u.search ? u.search : '') + u.hash);
}
```

给 `showAddModal`、`openEditModal`、`showBatchModal`、创建任务按钮的入口统一调用一次 `clearLocateHighlight()`（可在这些函数开头调用）。

- [ ] **Step 3: 修改 `task.php`**

第 381-383 行的高亮后，增加：

```js
['pointerdown','wheel','keydown','touchstart'].forEach(function(evt) {
    document.addEventListener(evt, function() {
        document.querySelectorAll('.word-card.follow-highlight').forEach(function(c){ c.classList.remove('follow-highlight'); });
    }, { passive: true });
});
```

并在点击“开始跟读”/复制/完成等按钮时清除。

- [ ] **Step 4: `history.php` 支持高亮**

在 `$highlightId = reqGet('highlight');` 后，服务端给对应 `data-id` 卡片加 `follow-highlight` class；前端同样注册上面的清理监听。

- [ ] **Step 5: 验证 + 提交**

Run: `php -l web/words.php && php -l web/task.php && php -l web/history.php`
Expected: 三个 `No syntax errors`。
浏览器按 Step 1 验证。

```bash
git add web/words.php web/task.php web/history.php
git commit -m "fix(search): 操作后清除搜索定位高亮"
```

---

### Task 5: 历史记录侧边栏固定视口滚动

**Files:**
- Modify: `web/common.css`（`.content-split` / `.sidebar` / `.main-panel`）
- Modify: `web/history.php`（如需加包裹 class，不改结构优先）

**Interfaces:** 无。

- [ ] **Step 1: 复现**

打开 `history.php?id=...`，构造 30 条历史；页面整体被撑高，左侧栏跟随页面滚动而不是内部滚动。

- [ ] **Step 2: 修改 CSS**

`web/common.css`：

```css
.content-split {
    display: flex;
    overflow: hidden;
    max-width: none !important;
    padding: 0 !important;
    height: calc(100vh - 68px);
    min-height: 0;
}
.sidebar { min-height: 0; }
.main-panel { min-height: 0; }
```

若 header 高度不是 68px，用 `history.php` 现有 header 实际高度替换；移动端保持现有 140px 横向滚动分支。

- [ ] **Step 3: 提升缓存版本**

`web/inc/head.php` 的 `common.css?v=` +1（当前 v=21）。

- [ ] **Step 4: 验证 + 提交**

浏览器确认：左侧栏内部滚动、右侧主面板独立滚动、移动端横向滚动正常。

```bash
git add web/common.css web/inc/head.php
git commit -m "fix(history): 侧边栏固定在视口内滚动"
```

---

### Task 6: 安全基线（CORS / 会话 / 配置模板）

**Files:**
- Create: `tests/php/security_test.php`
- Create: `scripts/rotate-secrets.php`
- Modify: `web/inc/security.php`（`lwAllowedOrigin`、`lwStartSession`）
- Modify: `web/app_api.php`（CORS 使用 `lwAllowedOrigin`）
- Modify: `web/admin.php`（`lwStartSession`、支持 `admin_password_hash`）
- Modify: `web/inc/config.example.php`（无可用默认值、`allowed_origins` 空）
- Modify: `web/inc/config.php`（本机实际配置，gitignored；用脚本轮换，不提交）

**Interfaces:**
- Produces:
  - `lwAllowedOrigin(string $origin, array $allowedOrigins): ?string` — 空 origin 返回 null；`*` 返回 `*`；精确匹配返回 origin；否则 null。
  - `lwStartSession(): void` — `session.use_strict_mode=1`，cookie `httponly=true`、`samesite=Lax`、`secure=isHttpsRequest()`，然后 `session_start()`。
  - `isHttpsRequest()` 扩展：除 `$_SERVER['HTTPS']`/443 外，若 `REMOTE_ADDR` 是 loopback 或 `config['trusted_proxies']` 中的地址，且 `X-Forwarded-Proto` 首个值为 `https`，返回 true。
  - `lwBaseUrl(): string` — 优先使用可信代理的 `X-Forwarded-Proto`/`X-Forwarded-Host`，否则用当前 `HTTPS`/`HTTP_HOST`；返回不带末尾斜杠的 `scheme://host`。
  - `app_api.php` 的 `$base` 与 `gallery_api.php` 的绝对 URL 全部改用 `lwBaseUrl()`，避免 HTTPS 页面/APP 拿到 `http://` 媒体地址。
  - `config.example.php` 增加 `trusted_proxies => ['127.0.0.1', '::1']`。
  - `admin.php` 登录校验：若 `admin_password_hash` 非空用 `password_verify`，否则回退 `hash_equals(admin_password)`；两者都空则拒绝登录并提示配置。
  - `scripts/rotate-secrets.php`：生成 32 字节 `app_secret` 与 20 字符随机管理员密码，写 `admin_password_hash`，打印密码一次。

- [ ] **Step 1: 写测试**

`tests/php/security_test.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/security.php';
checkSame(null, lwAllowedOrigin('', []), 'no origin');
checkSame(null, lwAllowedOrigin('https://a.test', []), 'empty allowlist denies');
checkSame('*', lwAllowedOrigin('https://a.test', ['*']), 'wildcard');
checkSame('https://a.test', lwAllowedOrigin('https://a.test', ['https://a.test']), 'exact');
checkSame(null, lwAllowedOrigin('https://b.test', ['https://a.test']), 'other origin denied');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
checkSame(true, isHttpsRequest(), 'trusted loopback proxy https');
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
checkSame(false, isHttpsRequest(), 'untrusted forwarded proto ignored');

$example = require __DIR__ . '/../../web/inc/config.example.php';
checkSame([], $example['allowed_origins'], 'example allowlist empty');
checkSame(['127.0.0.1', '::1'], $example['trusted_proxies'], 'example trusted proxies');
check(($example['admin_password'] ?? '') === '', 'example has no usable password');
check(($example['admin_password_hash'] ?? '') === '', 'example has no usable hash');
done();
```

- [ ] **Step 2: 运行确认失败**

Run: `php tests/php/security_test.php`
Expected: FAIL，`lwAllowedOrigin` 未定义。

- [ ] **Step 3: 实现 `lwAllowedOrigin` / `lwStartSession`**

在 `web/inc/security.php` 实现；`lwStartSession` 中 `session_set_cookie_params` 必须在 `session_start` 前调用，且只调用一次（用 `session_status()` 判断）。

- [ ] **Step 4: 接入**

`web/app_api.php` 第 9-17 行改为：

```php
$allowedOrigins = $config['allowed_origins'] ?? [];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = lwAllowedOrigin((string)$origin, is_array($allowedOrigins) ? $allowedOrigins : []);
if ($allowOrigin !== null) {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    if ($allowOrigin !== '*') header('Vary: Origin');
}
```

`web/admin.php` 第 24 行改为 `lwStartSession();`；第 21 行改为：

```php
$adminPassword = (string)($config['admin_password'] ?? '');
$adminPasswordHash = (string)($config['admin_password_hash'] ?? '');
```

登录校验用 hash 优先：

```php
$loginOk = $adminPasswordHash !== ''
    ? password_verify((string)reqPost('password'), $adminPasswordHash)
    : ($adminPassword !== '' && hash_equals($adminPassword, (string)reqPost('password')));
if (!$loginOk) { /* 现有失败计数/延迟 */ }
```

两者都为空时直接提示“后台未配置管理员密码，请运行 scripts/rotate-secrets.php”。

`web/inc/config.example.php`：`allowed_origins => []`、`admin_password => ''`、`admin_password_hash => ''`。

- [ ] **Step 5: 轮换本机密钥**

`scripts/rotate-secrets.php` 读取现有 `web/inc/config.php`，保留其他键，生成新 `app_secret` 与 `admin_password_hash`，写回；终端打印新密码。运行：

```bash
php scripts/rotate-secrets.php
```

把打印的密码记录到验收消息，不写入仓库/日志。

- [ ] **Step 6: 测试 + 提交**

Run: `php tests/php/security_test.php && php -l web/inc/security.php && php -l web/app_api.php && php -l web/admin.php`
Expected: `checks=12 failures=0` + 三个 `No syntax errors`。

```bash
git add web/inc/security.php web/app_api.php web/admin.php web/inc/config.example.php scripts/rotate-secrets.php tests/php/security_test.php
git commit -m "security: 收紧 CORS、会话与管理员口令"
```

---

### Task 7: Schema 版本与迁移骨架

**Files:**
- Create: `web/inc/migrations.php`
- Create: `web/inc/migrations/001_init.php`
- Create: `web/bin/migrate.php`
- Create: `tests/php/migrations_test.php`

**Interfaces:**
- Produces:
  - `lwSchemaVersion(string $dataDir): int` — 读 `schema.json` 的 `version`，缺省 0。
  - `lwRunMigrations(string $dataDir): array` — 返回 `['from'=>int,'to'=>int,'applied'=>int[]]`；每个迁移幂等；成功后原子写 `schema.json`。
  - `lwMigrations(): array` — `[1 => callable(string $dataDir): void]`。

- [ ] **Step 1: 写测试**

`tests/php/migrations_test.php`：临时目录，断言初始 0；`lwRunMigrations` 后为 1、`applied === [1]`；再次运行 `applied === []`。

- [ ] **Step 2: 运行确认失败**

Run: `php tests/php/migrations_test.php`
Expected: FAIL，函数未定义。

- [ ] **Step 3: 实现**

`001_init.php` 返回 callable，当前只确保目录存在（no-op 数据迁移）。`lwRunMigrations` 逐个执行 `<=` 当前版本的迁移，写 `schema.json`（`version/applied_at`）。`web/bin/migrate.php` 解析 `--data-dir=` 与 `--to=`，打印结果。

- [ ] **Step 4: 测试 + 提交**

Run: `php tests/php/migrations_test.php && php -l web/inc/migrations.php`
Expected: `checks=4 failures=0`。

```bash
git add web/inc/migrations.php web/inc/migrations/001_init.php web/bin/migrate.php tests/php/migrations_test.php
git commit -m "feat(schema): 增加版本化迁移骨架"
```

---

## S1 APP 实况照片

### Task 8: 服务端 Motion Photo 提取

**Files:**
- Create: `web/inc/motion_photo.php`
- Create: `tests/php/motion_photo_test.php`
- Create: `tests/php/fixtures/motion_sample.jpg`（优先从 MIT 的 `ente/motion_photos` 测试资产复制；若拿不到，用 `tests/php/make_motion_fixture.php` 生成）

**Interfaces:**
- Produces:
  - `motionPhotoFindVideoRange(string $bytes): ?array` — 返回 `['start'=>int,'length'=>int]`。
  - `motionPhotoExtractVideo(string $path): ?string` — 成功返回临时 MP4 路径（调用方负责删除），失败返回 null。
- 识别顺序：
  1. XMP `GCamera:MicroVideoOffset="N"`（从文件末尾倒数 N 字节）；
  2. XMP `Container:Directory` 中 `Item:Mime="video/mp4"` 附近的 `Item:Length="N"`；
  3. 回退：`strrpos($bytes, 'ftyp')`，从 `pos-4` 开始的 box size 校验后取到文件末尾；优先最后一个 `ftyp`。
- S1 只接受 JPEG（安卓 Motion Photo）；HEIC/Apple Live Photo 配对在 S5 的 Web 上传中启用。提取结果必须通过 `Database::isMp4File`。

- [ ] **Step 1: 写测试**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/motion_photo.php';

$fixture = __DIR__ . '/fixtures/motion_sample.jpg';
check(is_file($fixture), 'fixture exists');
$video = motionPhotoExtractVideo($fixture);
check($video !== null, 'video extracted');
check($video !== null && filesize($video) > 0, 'video non-empty');
if ($video) { check(str_contains(file_get_contents($video, false, null, 4, 4), 'ftyp'), 'video has ftyp'); @unlink($video); }

$plain = __DIR__ . '/fixtures/plain.jpg';
check(is_file($plain), 'plain fixture exists');
checkSame(null, motionPhotoExtractVideo($plain), 'plain jpeg not motion');
done();
```

- [ ] **Step 2: 运行确认失败**

Run: `php tests/php/motion_photo_test.php`
Expected: FAIL，函数未定义或 fixture 不存在。

- [ ] **Step 3: 实现提取**

用 `file_get_contents` 读文件（S1 上限 12 MB，内存安全）；XMP 用正则解析；回退扫描用 `strrpos`；截取后写 `tempnam(sys_get_temp_dir(), 'lw_live_')`，校验 `ftyp` 后返回路径。

- [ ] **Step 4: 测试 + 提交**

Run: `php tests/php/motion_photo_test.php && php -l web/inc/motion_photo.php`
Expected: `checks=5 failures=0`。

```bash
git add web/inc/motion_photo.php tests/php/motion_photo_test.php tests/php/fixtures/
git commit -m "feat(gallery): 提取 Motion Photo 内嵌视频"
```

---

### Task 9: 服务端图集支持 `type=live`

**Files:**
- Modify: `web/inc/app_lib.php`（新增 `appGalleryItemOut`）
- Modify: `web/app_api.php`（`save_gallery`、`get_gallery`）
- Modify: `web/gallery.php`（`save_gallery` 自动提取）
- Create: `tests/php/gallery_live_test.php`

**Interfaces:**
- Produces: `appGalleryItemOut(array $item, string $classId, string $base): array`，输出：
  ```php
  ['id'=>..., 'image_url'=>..., 'type'=>'static|gif|mp4|live',
   'description'=>..., 'uploaded_at'=>..., 'thumb_url'=>?string,
   'live_video_url'=>?string]
  ```
- 存储字段：`type`（`image|video|live`）、`live_video`（mp4 文件名）、`uploader_id`、`uploader_name`（S1 只写入，不展示）。
- `save_gallery` 接受可选 `$_FILES['live_video']` + `is_live=1`；若没有，则对图片调用 `motionPhotoExtractVideo` 自动提取。提取/保存失败时退回纯静态，返回 `live_warning`。

- [ ] **Step 1: 写测试**

构造一个 gallery item `['id'=>'g','image'=>'a.jpg','live_video'=>'b.mp4','description'=>'d','uploaded_at'=>'t']`，断言 `type === 'live'`、`live_video_url` 包含 `upload.php` 与 `b.mp4`；普通 item 断言 `type === 'static'`。

- [ ] **Step 2: 运行确认失败**

Run: `php tests/php/gallery_live_test.php`
Expected: FAIL，`appGalleryItemOut` 未定义。

- [ ] **Step 3: 实现格式化函数并接入 `get_gallery`**

`get_gallery` 用 `appGalleryItemOut` 替换内联数组；`live` 的 `thumb_url` 直接用静态图 URL（封面），`live_video_url` 指向 `upload.php?file=<live_video>`。

同时在 `get_gallery` 的自愈循环里：若 `live_video` 非空但文件不存在，清空 `live_video`、把 `type` 退回 `image`，不要让前端拿到 404 视频。

`delete_gallery`（`app_api.php` 与 `gallery.php`）在删除 `image`/thumb 的同时删除 `live_video` 与其缩略图。

- [ ] **Step 4: 扩展 `save_gallery`**

`web/app_api.php`：

```php
$liveTmp = null;
$liveFile = null;
if (isset($_FILES['live_video']) && ($_FILES['live_video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $liveTmp = $_FILES['live_video']['tmp_name'];
} else {
    $liveTmp = motionPhotoExtractVideo($img['tmp_name']); // 可能 null
}
// save image first, then video
// $liveFile = Database::saveMp4Image($classId, $liveTmp);
// 失败时 $liveWarning = '实况视频保存失败，已按普通图片上传';
```

`web/gallery.php` 同样在 `saveUploadedImage` 前尝试 `motionPhotoExtractVideo`，保存后写入 `type/live_video`。清理临时文件。

- [ ] **Step 5: 测试 + 语法 + 提交**

Run: `php tests/php/gallery_live_test.php && php -l web/app_api.php && php -l web/gallery.php && php -l web/inc/app_lib.php`
Expected: `checks=2 failures=0` + 三个 `No syntax errors`。

```bash
git add web/inc/app_lib.php web/app_api.php web/gallery.php tests/php/gallery_live_test.php
git commit -m "feat(gallery): 服务端存储与返回实况照片"
```

---

### Task 10: Web 图集展示实况

**Files:**
- Modify: `web/gallery.php`（网格与灯箱 JS）

**Interfaces:**
- 依赖 `?json=1` 返回的 `type`、`live_video`；不新增接口。

- [ ] **Step 1: 网格**

`galleryData.map` 中：

```js
var isLive = item.type === 'live' && item.live_video;
var liveUrl = isLive ? ('upload.php?class_id=' + classId + '&file=' + item.live_video) : '';
```

静态图仍显示 `item.image`；`isLive` 时加 `<span class="gif-badge" style="background:var(--blue);">实况</span>`，`onclick` 传 `liveUrl` 与 `thumbUrl=url`。

- [ ] **Step 2: 灯箱**

`openLightbox` 已支持 MP4（`isMp4` + `thumbUrl`）。实况走同一分支：`openLightbox(liveUrl, item.id, true, url)`。关闭灯箱后恢复网格。

- [ ] **Step 3: 手动验证**

用 fixture 上传，确认网格显示“实况”，点击可播放；普通图片/MP4 行为不变。

- [ ] **Step 4: 提交**

```bash
git add web/gallery.php
git commit -m "feat(gallery): Web 图集展示实况照片"
```

---

### Task 11: APP 模型与多文件上传

**Files:**
- Modify: `app/myapp/lib/models/gallery_item.dart`
- Modify: `app/myapp/lib/services/api_service.dart`
- Create: `app/myapp/test/gallery_item_test.dart`

**Interfaces:**
- `GalleryItem` 增加 `final bool isLive; final String liveVideoUrl;`；`fromJson` 读 `type == 'live'` / `live_video_url`；`toCacheJson` 写回。
- `ApiService.saveGallery(String classId, File image, String description, {File? liveVideo})`；`liveVideo != null` 时字段带 `is_live=1`，文件键 `live_video`。
- 新增私有 `_postWithFiles(String action, Map<String,String> fields, Map<String,File> files)`；保留 `_postWithFile` 供其他接口使用。

- [ ] **Step 1: 写测试**

```dart
test('parses live gallery item', () {
  final item = GalleryItem.fromJson({
    'id': 'g', 'image_url': 'http://x/a.jpg', 'type': 'live',
    'live_video_url': 'http://x/b.mp4', 'description': 'd', 'uploaded_at': 't',
  });
  expect(item.isLive, true);
  expect(item.liveVideoUrl, 'http://x/b.mp4');
  expect(item.toCacheJson()['type'], 'live');
});
```

- [ ] **Step 2: 运行确认失败**

Run（CI/Codespaces）：`cd app/myapp && flutter test test/gallery_item_test.dart`
Expected: FAIL，`isLive` 不存在。

- [ ] **Step 3: 实现模型与 API**

`_postWithFiles` 用 `http.MultipartRequest`，逐个 `add`；每个文件 filename 用 `file.uri.pathSegments.last`，视频兜底 `live_${timestamp}.mp4`。超时仍 60 秒。

- [ ] **Step 4: 测试 + analyze**

Run: `flutter test test/gallery_item_test.dart && flutter analyze`
Expected: 测试通过，0 error。

- [ ] **Step 5: 提交**

```bash
git add app/myapp/lib/models/gallery_item.dart app/myapp/lib/services/api_service.dart app/myapp/test/gallery_item_test.dart
git commit -m "feat(app): 图集模型与 API 支持实况"
```

---

### Task 12: APP 识别并上传实况

**Files:**
- Modify: `app/myapp/pubspec.yaml`（`motion_photos: ^1.0.0`、`file_picker: ^8.0.0`）
- Modify: `app/myapp/lib/screens/gallery/gallery_screen.dart`

**Interfaces:**
- 依赖 `motion_photos`：`MotionPhotos(path).isMotionPhoto()`、`.getMotionVideoFile()`。
- 依赖 `file_picker`（MIT）作为“原图”兜底：`FilePicker.platform.pickFiles(type: FileType.image, allowMultiple: false, allowCompression: false)`；`allowCompression: false` 保证拿到原始字节，避免 `image_picker` 副本丢失 motion 元数据。
- `_pickAndUploadImage` 在大小/类型校验后检测；检测成功则：
  - 弹窗展示静态图预览 + “实况”角标；
  - 用户可以“作为实况上传”或“仅上传图片”；
  - 上传调用 `_uploadFile(File(image.path), desc, liveVideo: video)`。
- `_uploadFile(File file, String desc, {File? liveVideo})` 传给 `_api.saveGallery(..., liveVideo: liveVideo)`。

- [ ] **Step 1: 加依赖**

`pubspec.yaml` 增加 `motion_photos: ^1.0.0`；运行 `flutter pub get`（CI/Codespaces）。

- [ ] **Step 2: 实现检测与预览**

`_pickAndUploadImage`：

```dart
File? liveVideo;
bool isLive = false;
try {
  final mp = MotionPhotos(image.path);
  isLive = await mp.isMotionPhoto();
  if (isLive) liveVideo = await mp.getMotionVideoFile();
} catch (_) { isLive = false; liveVideo = null; }
```

描述弹窗内容加：`if (isLive) ... Text('检测到实况照片，将同时上传动图')` 与 `SwitchListTile`（“作为实况上传”，默认 true）。取消实况时 `liveVideo = null`。

同时在 `_showUploadMenu` 增加第三个入口“上传实况照片（原图）”：调用 `FilePicker.platform.pickFiles(...allowCompression: false)`，走同一套检测/预览/上传流程。这样即使 `image_picker` 的副本丢了 motion 元数据，用户仍能选到原图。

- [ ] **Step 3: 上传**

`_uploadFile` 增加可选 `liveVideo`，提示文案“上传实况中...”；失败时提示服务端返回的 `live_warning`（若存在）。

- [ ] **Step 4: analyze + 提交**

Run: `flutter analyze`
Expected: 0 error。

```bash
git add app/myapp/pubspec.yaml app/myapp/lib/screens/gallery/gallery_screen.dart
git commit -m "feat(app): 识别并上传安卓实况照片"
```

---

### Task 13: APP 展示实况

**Files:**
- Modify: `app/myapp/lib/screens/gallery/gallery_screen.dart`

**Interfaces:**
- 卡片：`item.isLive` 时在缩略图上加“实况”角标与播放图标。
- 查看器：`item.isLive` 时显示静态图 + 播放按钮；点击后用 `_VideoViewer` 播放 `item.liveVideoUrl`（静音循环，可开声音）。
- `liveVideoUrl` 为空或播放失败：退回静态图 + SnackBar“实况视频不可用”。

- [ ] **Step 1: 卡片角标**

在 `_buildCard` 的 `Stack` 中，`item.isLive` 时加 `_LiveBadge()`（手绘风小标签，蓝色）。

- [ ] **Step 2: 查看器播放**

`_GalleryViewerScreen` 增加 `_showLive()`：`Navigator.push` 到现有 `_VideoViewer(url: item.liveVideoUrl)`；静态图覆盖一个播放按钮。播放失败捕获异常并提示。

- [ ] **Step 3: analyze + 提交**

Run: `flutter analyze`
Expected: 0 error。

```bash
git add app/myapp/lib/screens/gallery/gallery_screen.dart
git commit -m "feat(app): 图集播放实况照片"
```

---

### Task 14: S1 云端测试包与验收

**Files:**
- Modify: `.github/workflows/build.yml`（S1 临时只保留 `workflow_dispatch`，增加 artifact 上传；S2 恢复 push 触发与正式 Release）
- Modify: `app/myapp/pubspec.yaml`（`version: 1.3.0+4` 作为测试版）
- Modify: `app/myapp/lib/config/api_config.example.dart`（`appVersion = '1.3.0'`）
- Modify: `README.md` / `app/README.md`（测试版说明）

**Interfaces:** 无。

- [ ] **Step 1: 版本 bump**

`pubspec.yaml` → `1.3.0+4`；`api_config.example.dart` → `1.3.0`；`build.yml` 生成的 `appVersion` 同步为 `1.3.0`（若仍硬编码）。

- [ ] **Step 2: S1 临时触发 + artifact**

把 `build.yml` 的 `on.push` 触发注释掉，只保留 `workflow_dispatch`，避免测试版覆盖 `apk-latest`。确认 `Release APK` 步骤带 `if: github.event_name != 'workflow_dispatch'`（S1 手动构建不发布 Release）。在构建步骤后增加：

```yaml
- name: Upload test APK
  if: github.event_name == 'workflow_dispatch'
  uses: actions/upload-artifact@v4
  with:
    name: listenwrite-1.3.0-test
    path: app/myapp/build/app/outputs/flutter-apk/app-release.apk
    retention-days: 7
```

- [ ] **Step 3: 推送并触发**

Run:
```bash
git push origin feat/2.0
gh workflow run build.yml --ref feat/2.0
gh run watch
```
Expected: 构建成功；Actions artifact 可下载；安装到手机后测试实况上传/播放。

- [ ] **Step 4: 真实样例验收**

让用户用手机拍一张实况照片，在 APP 图集选择并上传；确认：
- 卡片出现“实况”角标；
- 点击能播放；
- Web 图集同一张也能播放；
- 普通照片仍是静态。

- [ ] **Step 5: 提交**

```bash
git add .github/workflows/build.yml app/myapp/pubspec.yaml app/myapp/lib/config/api_config.example.dart README.md app/README.md
git commit -m "chore(app): 1.3.0 实况照片测试包"
```

---

## 执行记录

- 2026-11-20：S0 Task 1-7 完成并提交；PHP 测试 32 项检查通过；本机 HTTP/HTTPS 双入口验证；后台管理员密码已轮换（不在仓库记录）。
- 2026-11-20：S1 Task 8-13 代码完成；Web 端用 fixture 验证 `motion_sample.jpg` 自动提取 MP4 并写入 `type=live`；APP 1.3.0 测试包走 GitHub Actions artifact。
- 2026-11-20：用户确认公网采用方案 B（支持 HTTP，后台警告），代码保持 HTTP/HTTPS 双兼容。

## 执行方式

- 用户已选择“分步完成、逐个验收”。本计划按 **S0（Task 1-7）→ 验收 → S1（Task 8-14）→ 验收** 执行。
- 推荐 **Native**（本会话直接实现，每完成一个 Task 报告并等待验收），因为 S0/S1 的任务共享同一批文件，且用户需要在本机跑 `scripts\dev.ps1` 与真机安装。
- 不使用独立 worktree：本机 `web/data/`、`web/inc/config.php` 是实际运行数据，worktree 会缺失；在 `feat/2.0` 分支原地开发。
- 开始时创建分支并保留用户现有未提交改动：

```bash
git switch -c feat/2.0
git status
```

- S0 全部完成后运行一次全部 PHP 测试并报告；等待用户验收后再进入 S1：

```powershell
Get-ChildItem tests/php/*_test.php | ForEach-Object { php $_.FullName }
```
