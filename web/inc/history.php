<?php

const HISTORY_CONTENT_MAX_BYTES = 1048576;
const HISTORY_DATA_IMAGE_MAX_BYTES = 786432;

function historyStrictDate($value) {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return false;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function historySanitizeHtml($html) {
    if (!is_string($html) || strlen($html) > HISTORY_CONTENT_MAX_BYTES) {
        throw new LengthException('内容过长');
    }

    // 预过滤: 移除已知的危险模式 (条件注释、XML 声明等)
    $html = preg_replace('/<!--\[if[\s\S]*?\]\]>|<!\[endif\]-->/i', '', $html);
    $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html);

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new InvalidArgumentException('内容格式无效');

    $allowedTags = ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'ol', 'ul', 'li', 'blockquote', 'span', 'a', 'img', 'sub', 'sup', 'div'];
    // dropTags 中的标签会被完全删除 (含子节点)；其他不在 allowedTags 中的标签会被 unwrap (保留子节点文本)
    $dropTags = ['script', 'style', 'svg', 'iframe', 'form', 'object', 'embed', 'math', 'template', 'noscript', 'base', 'link', 'meta', 'frame', 'frameset', 'applet', 'col', 'colgroup'];
    $dataImageBytes = 0;
    $nodes = [];
    foreach ($dom->getElementsByTagName('*') as $node) $nodes[] = $node;

    foreach (array_reverse($nodes) as $node) {
        $tag = strtolower($node->nodeName);
        if ($tag === 'body') continue;
        if (in_array($tag, $dropTags, true)) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
            continue;
        }
        if (!in_array($tag, $allowedTags, true)) {
            if ($node->parentNode) while ($node->firstChild) $node->parentNode->insertBefore($node->firstChild, $node);
            if ($node->parentNode) $node->parentNode->removeChild($node);
            continue;
        }

        $attributes = [];
        foreach ($node->attributes as $attribute) $attributes[] = $attribute->name;
        foreach ($attributes as $name) {
            $lower = strtolower($name);
            $value = trim($node->getAttribute($name));
            $keep = false;
            // 拒绝所有事件处理属性和已知危险属性
            if (stripos($name, 'on') === 0 || in_array($lower, ['srcset', 'formaction', 'xlink:href', 'xml:base', 'xmlns', 'data', 'code', 'codebase'], true)) {
                $keep = false;
            } elseif ($lower === 'class') {
                // 保留布局类；把 ql-color-/ql-background- 类转为内联 style（兼容 web Quill 保存的颜色）
                $classes = [];
                $styleAdd = [];
                foreach (preg_split('/\s+/', $value) as $class) {
                    if ($class === '') continue;
                    if (preg_match('/\Aql-color-([0-9a-fA-F]{3,8})\z/D', $class, $m)) {
                        $styleAdd['color'] = '#' . strtolower($m[1]);
                    } elseif (preg_match('/\Aql-background-([0-9a-fA-F]{3,8})\z/D', $class, $m)) {
                        $styleAdd['background-color'] = '#' . strtolower($m[1]);
                    } elseif (preg_match('/\Aql-(?:align-(?:center|right|justify)|indent-[1-8]|direction-rtl)\z/D', $class)
                        || preg_match('/\Aimg-(?:left|center|right|selected)\z/D', $class)) {
                        $classes[] = $class;
                    }
                }
                if ($classes) {
                    $node->setAttribute('class', implode(' ', $classes));
                } else {
                    $node->removeAttribute('class');
                }
                if ($styleAdd) {
                    $existing = trim((string)$node->getAttribute('style'));
                    $parts = $existing !== '' ? array_filter(array_map('trim', explode(';', $existing))) : [];
                    $seen = [];
                    foreach ($parts as $p) {
                        if (preg_match('/\A([a-zA-Z-]+)\s*:/', $p, $m)) $seen[strtolower($m[1])] = true;
                    }
                    foreach ($styleAdd as $prop => $val) {
                        if (!isset($seen[$prop])) $parts[] = $prop . ':' . $val;
                    }
                    $node->setAttribute('style', implode(';', $parts));
                }
                if ($classes || $styleAdd) $keep = true;
            } elseif ($lower === 'style') {
                $safe = [];
                foreach (explode(';', $value) as $declaration) {
                    // 显式拒绝 url()、expression()、javascript: 等危险 CSS 值 (深度防御)
                    if (preg_match('/url\s*\(|expression\s*\(|javascript:|vbscript:|@import|behavior\s*:/i', $declaration)) continue;
                    if (preg_match('/\A\s*(color|background-color)\s*:\s*(#[0-9a-fA-F]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*[0-9.]+\s*\)|hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\)|hsla\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*,\s*[0-9.]+\s*\)|[a-zA-Z]+)\s*\z/D', $declaration, $match)) $safe[] = strtolower($match[1]) . ':' . $match[2];
                    elseif (preg_match('/\A\s*page-break-(?:after|before|inside)\s*:\s*(?:auto|always|avoid|left|right)\s*\z/D', $declaration, $match)) $safe[] = strtolower(trim($declaration));
                    // 允许 text-align（富文本编辑器对齐按钮生成）
                    elseif (preg_match('/\A\s*text-align\s*:\s*(?:left|center|right|justify)\s*\z/D', $declaration, $match)) $safe[] = strtolower(trim($declaration));
                    // 允许 max-height/max-width（图片自适应一页内显示，防止超高溢出）
                    elseif (preg_match('/\A\s*max-(?:height|width)\s*:\s*\d{1,4}(?:px|mm|%)?\s*\z/D', $declaration, $match)) $safe[] = strtolower(trim($declaration));
                    // 允许 width:auto（配合 max-height 自适应缩放）
                    elseif (preg_match('/\A\s*width\s*:\s*auto\s*\z/D', $declaration, $match)) $safe[] = strtolower(trim($declaration));
                }
                if ($safe) { $node->setAttribute('style', implode(';', $safe)); $keep = true; }
            } elseif ($tag === 'a' && $lower === 'href') {
                // 拒绝 javascript:、data: 等危险协议
                if (preg_match('/\A(?:https?:|mailto:|tel:|\/|#)/i', $value) && !preg_match('/javascript:|data:/i', $value)) {
                    $keep = true;
                }
            } elseif ($tag === 'a' && $lower === 'target' && in_array($value, ['_blank', '_self'], true)) {
                $keep = true;
            } elseif ($tag === 'img' && $lower === 'src') {
                if (preg_match('/\Adata:image\/(png|jpeg);base64,([A-Za-z0-9+\/=\r\n]+)\z/Di', $value, $match)) {
                    // 拒绝 data:image/svg (svg 不在允许的 MIME 列表中)
                    $decoded = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
                    if ($decoded !== false && strlen($decoded) > 0) {
                        // 验证解码后的内容确实是图片 (检查文件头)
                        $isJpeg = substr($decoded, 0, 3) === "\xff\xd8\xff";
                        $isPng = substr($decoded, 0, 8) === "\x89PNG\r\n\x1a\n";
                        if ($isJpeg || $isPng) {
                            $dataImageBytes += strlen($decoded);
                            $keep = $dataImageBytes <= HISTORY_DATA_IMAGE_MAX_BYTES;
                        }
                    }
                } elseif (preg_match('/\Ahttps?:\/\//i', $value)) {
                    $keep = true;
                } elseif (preg_match('/\Aupload\.php\?class_id=[A-Za-z0-9][A-Za-z0-9_-]*&(?:amp;)?file=[a-f0-9]{32}\.(?:jpg|png|webp)\z/Di', $value)) {
                    $keep = true;
                }
            } elseif ($tag === 'img' && in_array($lower, ['alt', 'width', 'height'], true)) {
                $keep = $lower === 'alt' || preg_match('/\A\d{1,4}\z/D', $value);
            } elseif (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3'], true) && $lower === 'align') {
                // 允许对齐属性（execCommand justify 生成）
                if (in_array($value, ['left', 'center', 'right', 'justify'], true)) $keep = true;
            }
            if (!$keep) $node->removeAttribute($name);
        }
        if ($tag === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
        if ($tag === 'img' && !$node->hasAttribute('src') && $node->parentNode) $node->parentNode->removeChild($node);
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $result = '';
    if ($body) foreach ($body->childNodes as $child) $result .= $dom->saveHTML($child);
    if (strlen($result) > HISTORY_CONTENT_MAX_BYTES) throw new LengthException('内容过长');
    return $result;
}

/**
 * 构建合并的史记条目（班级 + 可选个人），按日期范围筛选。
 * 用于 PDF / HTML / 长图 三种导出格式共享数据准备逻辑。
 * @param array $history 已消毒的班级史记数组
 * @param string $classId 班级ID
 * @param string $start 开始日期（空=不限）
 * @param string $end 结束日期（空=不限）
 * @param bool $includePersonal 是否包含已同意的个人史记
 * @return array key=日期，value=条目数组 [{title,content}, ...]
 */
function historyBuildMergedEntries($history, $classId, $start, $end, $includePersonal) {
    $merged = [];
    foreach ($history as $dateKey => $entry) {
        if (($start !== '' && $dateKey < $start) || ($end !== '' && $dateKey > $end)) continue;
        $merged[$dateKey][] = ['title' => '班级史记', 'content' => $entry['content']];
    }
    if ($includePersonal) {
        foreach (Database::getAllUsers() as $uid => $user) {
            // 兼容两种 consent 存储：新 consent_map[classId] 和旧 consent
            $consentMap = $user['consent_map'] ?? [];
            $allowed = isset($consentMap[$classId]) ? (bool)$consentMap[$classId] : (!empty($user['consent']) ? true : false);
            if (!$allowed || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', (string)$uid)) continue;
            foreach (historySanitizeEntries(Database::getClassData($classId, 'personal_history_' . $uid)) as $dateKey => $entry) {
                if (($start !== '' && $dateKey < $start) || ($end !== '' && $dateKey > $end)) continue;
                $merged[$dateKey][] = ['title' => '个人列传 - ' . (string)($user['name'] ?? $uid), 'content' => $entry['content'], 'author_uid' => $uid, 'author_name' => (string)($user['name'] ?? $uid)];
            }
        }
    }
    ksort($merged);
    return $merged;
}

/**
 * 把合并后的条目数组渲染为 HTML body（每天一个 section）。
 * 用于 PDF / HTML 导出共享渲染逻辑。
 * @param array $merged historyBuildMergedEntries 的返回值
 * @return string HTML body 内容
 */
function historyRenderBody($merged) {
    $body = '';
    foreach ($merged as $dateKey => $items) {
        $body .= '<section class="day-section">';
        $body .= '<div class="day-header"><span class="day-date">' . htmlspecialchars($dateKey, ENT_QUOTES, 'UTF-8') . '</span></div>';
        foreach ($items as $item) {
            $body .= '<div class="entry"><h2>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</h2><div class="entry-content">' . $item['content'] . '</div></div>';
        }
        $body .= '</section>';
    }
    return $body;
}

function historyDefaultMood() { return '😊'; }
function historyDefaultWeather() { return '☀️'; }

function historySanitizeTitle($title) {
    $title = trim(preg_replace('/\s+/u', ' ', (string)$title));
    if (function_exists('mb_substr')) $title = mb_substr($title, 0, 80, 'UTF-8');
    else $title = substr($title, 0, 80);
    return $title;
}

function historySanitizeLocation($location) {
    $location = trim(preg_replace('/\s+/u', ' ', (string)$location));
    if (function_exists('mb_substr')) $location = mb_substr($location, 0, 40, 'UTF-8');
    else $location = substr($location, 0, 40);
    return $location;
}

function historySanitizeMood($mood) {
    $mood = (string)$mood;
    return in_array($mood, HISTORY_MOODS, true) ? $mood : historyDefaultMood();
}

function historySanitizeWeather($weather) {
    $weather = (string)$weather;
    return in_array($weather, HISTORY_WEATHERS, true) ? $weather : historyDefaultWeather();
}

function historySanitizeTags($tags) {
    if (is_string($tags)) {
        $tags = preg_split('/\s*[,，、]\s*/u', $tags, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($tags)) return [];
    $out = [];
    foreach ($tags as $tag) {
        $tag = trim(preg_replace('/\s+/u', ' ', (string)$tag));
        if ($tag === '') continue;
        if (function_exists('mb_substr')) $tag = mb_substr($tag, 0, 20, 'UTF-8');
        else $tag = substr($tag, 0, 20);
        if ($tag === '' || in_array($tag, $out, true)) continue;
        $out[] = $tag;
        if (count($out) >= 12) break;
    }
    return $out;
}

/**
 * 规范化单条史记为完整 vlog 结构（兼容旧 content-only 数据）
 */
function historyNormalizeEntry(array $entry) {
    $content = '';
    try {
        $content = historySanitizeHtml((string)($entry['content'] ?? ''));
    } catch (Exception $e) {
        $content = '';
    }
    $delta = (string)($entry['delta'] ?? '');
    if ($delta !== '' && strlen($delta) <= 2097152) {
        json_decode($delta, true);
        if (json_last_error() !== JSON_ERROR_NONE) $delta = '';
    } else {
        $delta = '';
    }
    return [
        'content' => $content,
        'delta' => $delta,
        'title' => historySanitizeTitle($entry['title'] ?? ''),
        'mood' => historySanitizeMood($entry['mood'] ?? historyDefaultMood()),
        'weather' => historySanitizeWeather($entry['weather'] ?? historyDefaultWeather()),
        'location' => historySanitizeLocation($entry['location'] ?? ''),
        'tags' => historySanitizeTags($entry['tags'] ?? []),
        'updated_at' => is_string($entry['updated_at'] ?? null) ? (string)$entry['updated_at'] : '',
    ];
}

function historySanitizeEntries(array $history) {
    $safe = [];
    foreach ($history as $date => $entry) {
        if (!historyStrictDate($date) || !is_array($entry)) continue;
        $safe[$date] = historyNormalizeEntry($entry);
    }
    return $safe;
}

/**
 * 从富文本内容中提取引用的图片文件名
 * 匹配 upload.php?class_id=xxx&file=xxxxxxxx.ext 格式的图片引用
 * @param string $content HTML 内容
 * @return array 图片文件名列表
 */
function historyExtractImageRefs($content) {
    $refs = [];
    if (!is_string($content)) return $refs;
    // 匹配 upload.php?class_id=xxx&file=xxxx.jpg/png/webp
    if (preg_match_all('/upload\.php\?class_id=[A-Za-z0-9_-]+&(?:amp;)?file=([a-f0-9]{32}\.(?:jpg|png|webp))/i', $content, $matches)) {
        foreach ($matches[1] as $file) $refs[$file] = true;
    }
    // 匹配 data/uploads/xxx/xxxx.ext 格式
    if (preg_match_all('/data\/uploads\/[A-Za-z0-9_-]+\/([a-f0-9]{32}\.(?:jpg|png|webp))/i', $content, $matches)) {
        foreach ($matches[1] as $file) $refs[$file] = true;
    }
    return array_keys($refs);
}

/**
 * 清理该班级史记不再引用的图片文件
 * 扫描 data/classes/{classId}/uploads/ 目录，删除不在任何史记条目引用列表中的图片
 * @param string $classId 班级ID
 * @param string $historyKey 史记数据键 (如 'history')
 */
/**
 * 把 HTML 中的 upload.php?class_id=xxx&file=xxx 图片 URL
 * 转换为 data URI base64 内嵌，用于 HTML 导出和长图导出。
 *
 * 安全性：classId 和 filename 严格正则校验，防路径穿越。
 */
function historyRewriteImageUrlsToBase64($html) {
    $pattern = '/upload\.php\?class_id=([A-Za-z0-9][A-Za-z0-9_-]*)&(?:amp;)?file=([a-f0-9]{32}\.(?:jpg|png|webp))/i';
    return preg_replace_callback($pattern, function($m) {
        $path = Database::getUploadedImagePath($m[1], $m[2]);
        if ($path === null) return $m[0];
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) === 0 || strlen($data) > 4 * 1024 * 1024) return $m[0];
        $ext = strtolower(pathinfo($m[2], PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }, $html);
}

/**
 * 递归提取 DOM 节点为结构化块（文本/图片/标题/引用/列表项）。
 * 用于长图 canvas 绘制和压缩包 TXT 生成。
 */
function historyExtractBlocks($node, &$blocks) {
    if (!$node) return;
    if ($node->nodeType === 3) { // 文本节点
        $text = trim($node->textContent);
        if ($text !== '') $blocks[] = ['type' => 'text', 'text' => $text];
        return;
    }
    if ($node->nodeType !== 1) return;
    $tag = strtolower($node->nodeName);
    if ($tag === 'img') {
        $src = $node->getAttribute('src');
        if ($src) $blocks[] = ['type' => 'image', 'src' => $src];
        return;
    }
    if ($tag === 'br') { $blocks[] = ['type' => 'text', 'text' => '']; return; }
    if ($tag === 'blockquote') {
        $blocks[] = ['type' => 'quote', 'text' => trim($node->textContent)];
        return;
    }
    if (in_array($tag, ['h1','h2','h3'], true)) {
        $blocks[] = ['type' => 'heading', 'text' => trim($node->textContent), 'level' => (int)substr($tag, 1)];
        return;
    }
    if ($tag === 'li') {
        $blocks[] = ['type' => 'listitem', 'text' => trim($node->textContent)];
        return;
    }
    if (in_array($tag, ['ul','ol','p','div','span','section'], true)) {
        if ($node->childNodes->length === 0) {
            $text = trim($node->textContent);
            if ($text !== '') $blocks[] = ['type' => 'text', 'text' => $text];
            return;
        }
        foreach ($node->childNodes as $child) historyExtractBlocks($child, $blocks);
        return;
    }
    // 其他标签当文本
    $text = trim($node->textContent);
    if ($text !== '') $blocks[] = ['type' => 'text', 'text' => $text];
}

function historyCleanupOrphanImages($classId, $historyKey) {
    $uploadsDir = Database::getUploadsDirectory($classId);
    if (!is_dir($uploadsDir)) return;

    // 收集所有史记条目引用的图片
    $history = Database::getClassData($classId, $historyKey);
    $referenced = [];
    foreach ($history as $entry) {
        $content = (string)($entry['content'] ?? '');
        foreach (historyExtractImageRefs($content) as $file) {
            $referenced[$file] = true;
        }
    }

    // 扫描目录，删除不再引用的图片文件
    $items = @scandir($uploadsDir);
    if ($items === false) return;
    foreach ($items as $filename) {
        if ($filename === '.' || $filename === '..') continue;
        // 只清理已知格式的图片文件
        if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/D', $filename)) continue;
        if (!isset($referenced[$filename])) {
            $path = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) @unlink($path);
        }
    }
}

/**
 * 安全文件名（过滤非法字符，限制长度）
 */
function historyExportSafeFilename($filename) {
    $filename = preg_replace('~[\x00-\x1F\x7F\\/\":*?<>|]+~u', '_', (string)$filename);
    $filename = trim((string)$filename, " .\t\r\n");
    if ($filename === '') $filename = 'export';
    return strlen($filename) > 240 ? substr($filename, 0, 236) : $filename;
}

// ========== 常量（定义在文件末尾以避免与函数交叉引用） ==========
const HISTORY_MOODS = ['😊', '🥰', '😌', '😢', '😤', '🤩', '😴'];
const HISTORY_WEATHERS = ['☀️', '⛅', '☁️', '🌧️', '⛈️', '🌨️', '🌬️'];
