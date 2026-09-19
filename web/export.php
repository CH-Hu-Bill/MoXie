<?php
/**
 * ============================================================
 * 超级导出 — 将班级数据打包为 zip
 * ============================================================
 *
 * URL 参数:
 *   ?id={classId} — 班级ID (必填)
 *
 * POST:
 *   action=export_zip, groups[]=words|gallery|tasks|history|settings|pron|wrong
 *   → 直接输出 zip 下载
 *
 * 隐私：不导出任何用户账号信息（用户名/密码/个人列传/vlog）。
 * 错题仅导出「按单词聚合的计数」，不含用户标识。
 * ============================================================
 */
require_once 'inc/db.php';
require_once 'inc/security.php';

$classId = reqGet('id');
if (!$classId) { header('Location: index.php'); exit; }
$classes = Database::getClasses();
if (!isset($classes[$classId])) { header('Location: index.php'); exit; }
$class = $classes[$classId];
requireClassAuth($classId, $class);

$csrfToken = csrfToken();

function exportJson($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}
function exportFail($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'export_zip') {
    requireCsrf();
    $groups = $_POST['groups'] ?? [];
    if (!is_array($groups)) $groups = [];
    $allowed = ['words', 'gallery', 'tasks', 'history', 'settings', 'pron', 'wrong'];
    $groups = array_values(array_intersect($allowed, $groups));
    if (empty($groups)) exportFail('请至少选择一项数据');
    if (!class_exists('ZipArchive')) exportFail('服务器未启用 zip 扩展');

    $classDir = Database::getClassDir($classId);
    $tmp = tempnam(sys_get_temp_dir(), 'lw_export_');
    if ($tmp === false) exportFail('无法创建临时文件', 500);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); exportFail('无法创建压缩包', 500); }

    $manifest = [
        'type'        => 'listenwrite-class-export',
        'version'     => 1,
        'class_name'  => (string)($class['name'] ?? ''),
        'exported_at' => date('Y-m-d H:i:s'),
        'groups'      => $groups,
    ];
    $zip->addFromString('manifest.json', exportJson($manifest));

    if (in_array('words', $groups, true)) {
        $zip->addFromString('words.json', exportJson(Database::getWords($classId)));
    }
    if (in_array('tasks', $groups, true)) {
        $zip->addFromString('tasks.json', exportJson(Database::getTasks($classId)));
    }
    if (in_array('history', $groups, true)) {
        $hist = Database::getClassData($classId, 'history');
        $zip->addFromString('history.json', exportJson(is_array($hist) ? $hist : []));
    }
    if (in_array('gallery', $groups, true)) {
        $gal = Database::getClassData($classId, 'gallery');
        $zip->addFromString('gallery.json', exportJson(is_array($gal) ? $gal : []));
        $upDir = $classDir . '/uploads';
        if (is_dir($upDir)) {
            foreach (scandir($upDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $upDir . '/' . $f;
                if (is_file($p) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $f)) {
                    $zip->addFile($p, 'uploads/' . $f);
                }
            }
        }
    }
    if (in_array('pron', $groups, true)) {
        $pron = Database::getClassData($classId, 'pronunciations');
        $zip->addFromString('pronunciations.json', exportJson(is_array($pron) ? $pron : []));
        $pronDir = $classDir . '/pronunciations';
        if (is_dir($pronDir)) {
            foreach (scandir($pronDir) as $wid) {
                if ($wid === '.' || $wid === '..' || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $wid)) continue;
                $wdir = $pronDir . '/' . $wid;
                if (!is_dir($wdir)) continue;
                foreach (scandir($wdir) as $f) {
                    if ($f === '.' || $f === '..' || !preg_match('/\A[a-f0-9]{32}\.m4a\z/', $f)) continue;
                    $p = $wdir . '/' . $f;
                    if (is_file($p)) $zip->addFile($p, 'pronunciations/' . $wid . '/' . $f);
                }
            }
        }
    }
    if (in_array('settings', $groups, true)) {
        $allSettings = Database::getSettings();
        $suffix = '_' . $classId;
        $sub = [];
        foreach ($allSettings as $k => $v) {
            if (strlen($k) > strlen($suffix) && substr($k, -strlen($suffix)) === $suffix) {
                $sub[substr($k, 0, -strlen($suffix))] = $v; // 含 ai / volume / display_token / gallery_api_key 等
            }
        }
        $zip->addFromString('settings_class.json', exportJson($sub));
    }
    if (in_array('wrong', $groups, true)) {
        // 仅聚合计数，不含用户标识
        $agg = [];
        foreach (Database::getAllUserIds() as $uid) {
            $u = Database::getUser($uid);
            if (isset($u['wrong_words'][$classId]) && is_array($u['wrong_words'][$classId])) {
                foreach ($u['wrong_words'][$classId] as $wid => $_) {
                    $agg[(string)$wid] = ($agg[(string)$wid] ?? 0) + 1;
                }
            }
        }
        $zip->addFromString('wrong_words.json', exportJson($agg));
    }

    $zip->close();

    $safeName = str_replace(['"', "\r", "\n", '\\', '/'], '', (string)($class['name'] ?? 'class'));
    if ($safeName === '') $safeName = 'class';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safeName . '_班级导出.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
?>
<?php $pageTitle = '超级导出'; require 'inc/head.php'; ?>
<body>
    <script>var CSRF_TOKEN='<?php echo $csrfToken; ?>';</script>
    <?php
    $backUrl = 'main.php?id=' . $classId;
    $className = $class['name'];
    $pageTitle = '超级导出';
    require 'inc/header.php';
    ?>
    <div class="content">
        <div class="card mb-3" style="max-width:640px;margin:0 auto;">
            <div style="font-size:16px;font-weight:bold;color:var(--pencil);margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid var(--old-paper);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pencil)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>超级导出
            </div>
            <div style="font-size:13px;color:#666;line-height:1.7;margin-bottom:14px;">
                选择要打包的数据，导出为 zip。可用于备份，或在「选择班级」页导入以创建新班级。<br>
                <span style="color:var(--red);">隐私说明：</span>不会导出用户名、密码、个人列传（vlog）等用户隐私数据；错题仅导出聚合计数，不含用户标识。
            </div>
            <form method="post" action="export.php?id=<?php echo rawurlencode($classId); ?>" id="exportForm">
                <input type="hidden" name="action" value="export_zip">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <?php
                $opts = [
                    'words'    => '知识库（单词 / 句子 / 作文）',
                    'gallery'  => '班级图集（含图片、视频）',
                    'tasks'    => '默写任务',
                    'wrong'    => '错题记录（聚合计数）',
                    'history'  => '班级史记',
                    'settings' => '班级设置（含 AI 接口配置）',
                    'pron'     => '全球发音录音',
                ];
                foreach ($opts as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:8px 4px;cursor:pointer;font-size:14px;color:var(--pencil);border-bottom:1px dashed var(--old-paper);">
                    <input type="checkbox" name="groups[]" value="<?php echo $key; ?>" checked style="width:18px;height:18px;accent-color:var(--blue);">
                    <span><?php echo htmlspecialchars($label); ?></span>
                </label>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary mt-4" style="width:100%;">导出并下载 zip</button>
            </form>
        </div>
    </div>
    <div class="toast" id="toast"></div>
    <script src="common.js?v=12"></script>
</body>
</html>
