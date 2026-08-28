<?php
/**
 * 使用说明页面 — 把 docs/使用说明.md 渲染成手绘风 HTML
 *
 * 访问：help.php（公开，无需登录）
 * 文档：docs/使用说明.md（Markdown，图片用相对路径 images/xxx.png）
 *
 * 轻量 Markdown 渲染：支持标题/列表/粗体/斜体/行内代码/代码块/
 * 引用/图片/链接/分隔线/表格（覆盖说明文档常见语法）。
 */
$docFile = __DIR__ . '/docs/使用说明.md';
$html = '<div class="help-empty">使用说明文档尚未编写（docs/使用说明.md）</div>';
if (is_file($docFile)) {
    $md = file_get_contents($docFile);
    // 去除 BOM
    if (substr($md, 0, 3) === "\xEF\xBB\xBF") $md = substr($md, 3);
    $html = renderMarkdown($md);
}

function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 行内语法：粗体/斜体/行内代码/图片/链接 */
function inlineMd($s) {
    $s = esc($s);
    // 图片 ![alt](url)
    $s = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1" loading="lazy">', $s);
    // 链接 [text](url)
    $s = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s);
    // 行内代码 `code`
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    // 粗体 **text**
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    // 斜体 *text*（避免与粗体冲突，粗体已处理）
    $s = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $s);
    return $s;
}

/** 块级渲染：处理段落/列表/标题/引用/代码块/表格 */
function renderMarkdown($md) {
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $out = '';
    $inCode = false;
    $codeBuf = [];
    $listType = null; // 'ul' | 'ol' | null
    $inTable = false;
    $tableRows = [];

    $flushList = function () use (&$out, &$listType) {
        if ($listType) { $out .= "</$listType>\n"; $listType = null; }
    };
    $flushTable = function () use (&$out, &$inTable, &$tableRows) {
        if (!$inTable) return;
        if (!empty($tableRows)) {
            $out .= '<table><thead><tr>';
            foreach ($tableRows[0] as $h) $out .= '<th>' . inlineMd(trim($h)) . '</th>';
            $out .= '</tr></thead><tbody>';
            for ($i = 1; $i < count($tableRows); $i++) {
                $out .= '<tr>';
                foreach ($tableRows[$i] as $c) $out .= '<td>' . inlineMd(trim($c)) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>' . "\n";
        }
        $inTable = false;
        $tableRows = [];
    };

    foreach ($lines as $line) {
        $t = rtrim($line);

        // 代码块开始/结束
        if (preg_match('/^```/', $t)) {
            if (!$inCode) {
                $flushList(); $flushTable();
                $inCode = true; $codeBuf = [];
            } else {
                $out .= '<pre><code>' . esc(implode("\n", $codeBuf)) . '</code></pre>' . "\n";
                $inCode = false;
            }
            continue;
        }
        if ($inCode) { $codeBuf[] = $t; continue; }

        // 空行
        if ($t === '') { $flushList(); $flushTable(); continue; }

        // 标题
        if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
            $flushList(); $flushTable();
            $level = strlen($m[1]);
            $out .= "<h$level>" . inlineMd($m[2]) . "</h$level>\n";
            continue;
        }

        // 分隔线
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $t)) {
            $flushList(); $flushTable();
            $out .= '<hr>' . "\n";
            continue;
        }

        // 表格
        if (strpos($t, '|') !== false && preg_match('/^\s*\|/', $t)) {
            $cells = array_map('trim', explode('|', trim($t, '|')));
            if (preg_match('/^:?-+:?$/', trim($cells[0])) || (count($cells) > 1 && preg_match('/^:?-+:?$/', trim($cells[1])))) {
                // 分隔行，跳过
                continue;
            }
            if (!$inTable) $inTable = true;
            $tableRows[] = $cells;
            continue;
        }

        // 无序列表
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $t, $m)) {
            if ($listType !== 'ul') { $flushList(); $out .= "<ul>\n"; $listType = 'ul'; }
            $out .= '<li>' . inlineMd($m[1]) . "</li>\n";
            continue;
        }
        // 有序列表
        if (preg_match('/^\s*(\d+)\.\s+(.*)$/', $t, $m)) {
            if ($listType !== 'ol') { $flushList(); $out .= "<ol>\n"; $listType = 'ol'; }
            $out .= '<li>' . inlineMd($m[2]) . "</li>\n";
            continue;
        }

        // 引用
        if (preg_match('/^\s*>\s?(.*)$/', $t, $m)) {
            $flushList(); $flushTable();
            $out .= '<blockquote>' . inlineMd($m[1]) . "</blockquote>\n";
            continue;
        }

        // 普通段落（合并连续行）
        $flushList(); $flushTable();
        $out .= '<p>' . inlineMd($t) . "</p>\n";
    }
    $flushList(); $flushTable();
    if ($inCode) $out .= '<pre><code>' . esc(implode("\n", $codeBuf)) . '</code></pre>' . "\n";
    return $out;
}

$pageTitle = '使用说明';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $pageTitle; ?> · ListenWrite</title>
<style>
    :root {
        --pencil: #2d2d2d;
        --blue: #2d5da1;
        --red: #ff4d4d;
        --white: #fffdf7;
        --paper: #fffdf7;
        --old-paper: #f3ede3;
        --wobbly: 16px;
        --shadow-sm: 3px 3px 0 var(--pencil);
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;
        background: var(--old-paper);
        color: var(--pencil);
        line-height: 1.75;
    }
    .help-wrap {
        max-width: 820px;
        margin: 28px auto 48px;
        padding: 20px;
    }
    .help-card {
        background: var(--white);
        border: 2px solid var(--pencil);
        border-radius: var(--wobbly);
        box-shadow: 6px 6px 0 rgba(45, 45, 45, 0.9);
        padding: 30px 38px 40px;
    }
    .help-card h1 { font-size: 26px; border-bottom: 2px solid var(--old-paper); padding-bottom: 12px; margin-top: 0; }
    .help-card h2 { font-size: 20px; margin-top: 28px; color: var(--blue); }
    .help-card h3 { font-size: 17px; margin-top: 20px; }
    .help-card h4 { font-size: 15px; margin-top: 16px; }
    .help-card p { margin: 10px 0; }
    .help-card ul, .help-card ol { padding-left: 26px; }
    .help-card li { margin: 5px 0; }
    .help-card code { background: #f0eadf; border: 1px solid #ddd6c8; border-radius: 4px; padding: 1px 6px; font-size: 0.9em; }
    .help-card pre {
        background: #2d2d2d; color: #e8e8e8; border-radius: 10px;
        padding: 14px 16px; overflow-x: auto; font-size: 13px; line-height: 1.5;
    }
    .help-card pre code { background: none; border: none; color: inherit; padding: 0; }
    .help-card blockquote {
        border-left: 4px solid var(--blue);
        background: rgba(45, 93, 161, 0.06);
        margin: 12px 0; padding: 8px 16px; border-radius: 0 8px 8px 0;
    }
    .help-card a { color: var(--blue); }
    .help-card img {
        max-width: 100%; height: auto; display: block;
        margin: 14px auto; border: 2px solid var(--pencil);
        border-radius: 10px; box-shadow: 4px 4px 0 rgba(45,45,45,0.25);
    }
    .help-card table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 14px; }
    .help-card th, .help-card td { border: 1.5px solid var(--pencil); padding: 8px 12px; text-align: left; }
    .help-card th { background: var(--old-paper); }
    .help-card hr { border: none; border-top: 2px dashed #ccc; margin: 24px 0; }
    .help-empty {
        background: var(--white); border: 2px dashed #ccc; border-radius: var(--wobbly);
        padding: 40px; text-align: center; color: #999;
    }
    .help-back {
        display: inline-block; margin-top: 20px; padding: 8px 22px;
        background: var(--white); border: 2px solid var(--pencil); border-radius: 10px;
        box-shadow: 3px 3px 0 var(--pencil); text-decoration: none; color: var(--pencil);
        font-size: 14px;
    }
    .help-back:hover { border-color: var(--blue); color: var(--blue); }
    @media (max-width: 640px) {
        .help-wrap { margin: 12px auto 28px; padding: 10px; }
        .help-card { padding: 20px 18px 28px; }
    }
</style>
</head>
<body>
    <div class="help-wrap">
        <div class="help-card">
            <?php echo $html; ?>
            <div style="text-align:center;">
                <a class="help-back" href="javascript:history.length > 1 ? history.back() : location.href='index.php'">← 返回</a>
            </div>
        </div>
    </div>
</body>
</html>
