<?php
/**
 * ============================================================
 * 请求参数安全读取 + 纯文本消毒
 * ============================================================
 *
 * reqGet() / reqPost(): 安全读取 GET/POST 参数。
 *   当参数以 ?x[]=1 形式传入（值为数组）时一律视为空字符串，
 *   避免 (string) 强制转换数组触发 "Array to string conversion"
 *   警告，在 display_errors 开启时泄露服务器绝对路径。
 *
 * sanitizePlainText(): 将用户输入消毒为纯文本（去除 HTML 标签、
 *   实体编码与危险控制字符），用于单词/释义/描述等纯文本字段的
 *   写入端消毒，防止存储型 XSS。展示端仍应使用 htmlspecialchars
 *   转义（双重防护）。
 *
 * 使用方式:
 *   $classId = reqGet('class_id');
 *   $word    = sanitizePlainText(reqPost('word'));
 *   $pw      = reqPost('password', reqPost('class_password'));
 * ============================================================
 */

/** 从指定超全局数组安全读取标量值（数组/对象返回默认值） */
function reqScalar($source, $key, $default = '') {
    if (!isset($source[$key])) return $default;
    $v = $source[$key];
    return is_scalar($v) ? (string)$v : $default;
}

/** 安全读取 $_GET 参数 */
function reqGet($key, $default = '') {
    return reqScalar($_GET, $key, $default);
}

/** 安全读取 $_POST 参数 */
function reqPost($key, $default = '') {
    return reqScalar($_POST, $key, $default);
}

/**
 * 纯文本消毒：去除 HTML 标签、实体编码和危险控制字符。
 * 用于写入端，保证入库内容即使将来未转义渲染也不会构成 XSS。
 */
function sanitizePlainText($value) {
    $value = (string)$value;
    // 先解码实体，使 &lt;script&gt; 这类编码攻击暴露为真实标签再剥离
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    // 移除除 \t\n\r 外的控制字符
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    return trim($value);
}
