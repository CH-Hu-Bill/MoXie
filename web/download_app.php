<?php
/**
 * ============================================================
 * 下载 APP 页面
 * ============================================================
 *
 * 展示最新版安装包信息 + 下载按钮 + 更新说明 + 历史发布记录。
 * APK 由后台 admin.php 上传，只保留最新一版（apk/listenwrite-release.apk）。
 * 页面公开可访问（无需班级口令），供学生/家长直接下载安装。
 * ============================================================
 */
require_once 'inc/db.php';
$versionData = Database::read('app_versions.json');
if (!is_array($versionData)) $versionData = ['latest' => '1.0', 'history' => []];
$apkInfo = $versionData['apk'] ?? null;
$latest = (string)($versionData['latest'] ?? '1.0');
$history = array_reverse($versionData['history'] ?? []);

/** 本地方法：字节数 → 人类可读（不依赖 admin.php 的函数） */
function appDownloadFormatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

$pageTitle = '下载 APP';
require 'inc/head.php';
?>
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;">
    <div style="width:100%;max-width:520px;">
        <div class="card" style="text-align:center;padding:32px 26px;">
            <h1 style="font-family:var(--font-heading);font-size:30px;color:var(--pencil);margin-bottom:6px;">📲 ListenWrite APP</h1>
            <p style="font-size:14px;color:var(--pencil);opacity:0.65;margin-bottom:22px;">安卓版 · 与网页端共用同一账号数据</p>

            <?php if ($apkInfo): ?>
                <div style="background:var(--post-it);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);padding:16px;margin-bottom:18px;">
                    <div style="font-family:var(--font-heading);font-size:18px;color:var(--pencil);">
                        最新版本 <span style="color:var(--red);">v<?php echo htmlspecialchars((string)($apkInfo['version'] ?? $latest)); ?></span>
                    </div>
                    <div style="font-size:13px;color:var(--pencil);margin-top:6px;line-height:1.8;">
                        <div>上传时间：<?php echo htmlspecialchars((string)($apkInfo['uploaded_at'] ?? '-')); ?></div>
                        <div>文件大小：<?php echo htmlspecialchars((string)($apkInfo['size_human'] ?? appDownloadFormatBytes($apkInfo['size'] ?? 0))); ?></div>
                        <?php if (!empty($apkInfo['notes'])): ?>
                            <div style="margin-top:4px;text-align:left;border-top:1.5px dashed var(--pencil);padding-top:8px;">
                                <div style="font-weight:700;margin-bottom:2px;">本次更新：</div>
                                <?php echo nl2br(htmlspecialchars((string)$apkInfo['notes'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="apk/listenwrite-release.apk" download="listenwrite-release.apk"
                   class="btn btn-primary" style="font-size:17px;padding:14px 26px;text-decoration:none;display:inline-block;width:100%;box-sizing:border-box;">
                    ⬇️ 下载最新版 APP
                </a>
            <?php else: ?>
                <div style="background:var(--old-paper);border:2px solid var(--pencil);border-radius:var(--wobbly-sm);padding:22px 16px;margin-bottom:18px;">
                    <div style="font-family:var(--font-heading);font-size:16px;color:var(--pencil);">安装包暂未上架</div>
                    <p style="font-size:13px;color:var(--pencil);opacity:0.65;margin-top:8px;line-height:1.6;">请稍后再来，或联系老师获取安装包。</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
                <div style="text-align:left;margin-top:26px;">
                    <div style="font-family:var(--font-heading);font-size:15px;color:var(--pencil);margin-bottom:10px;border-bottom:2px solid var(--old-paper);padding-bottom:8px;">版本记录</div>
                    <div style="max-height:260px;overflow-y:auto;font-size:12px;color:var(--pencil);line-height:1.7;">
                        <?php foreach ($history as $row): ?>
                            <div style="padding:8px 4px;border-bottom:1.5px solid var(--old-paper);">
                                <span style="font-family:var(--font-heading);font-weight:700;">v<?php echo htmlspecialchars((string)($row['version'] ?? '')); ?></span>
                                <span style="opacity:0.5;margin-left:6px;"><?php echo htmlspecialchars((string)($row['date'] ?? '')); ?></span>
                                <?php if (!empty($row['notes'])): ?>
                                    <div style="opacity:0.75;margin-top:2px;"><?php echo htmlspecialchars((string)$row['notes']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top:24px;">
                <a href="index.php" style="font-size:13px;color:var(--blue);text-decoration:none;">← 返回</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
