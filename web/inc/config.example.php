<?php
/**
 * 全局配置文件 (示例)
 * 
 * 使用方法：
 *   1. 复制此文件为 config.php：cp config.example.php config.php
 *   2. config.php 已被 .gitignore 忽略，不会提交到仓库
 *
 * 说明：AI 接口不再使用全局配置，改为在每个班级的「设置 → AI 设置」中配置。
 */
return [
    'app_secret' => getenv('APP_SECRET') ?: 'replace-with-at-least-32-random-characters',
    'allowed_origins' => [],
    // 反向代理/隧道地址：只有这些来源的 X-Forwarded-Proto/Host 会被信任
    'trusted_proxies' => ['127.0.0.1', '::1'],
    // 二选一：优先使用 password_hash 生成的值；留空则回退明文（仅兼容旧配置）
    'admin_password'      => '',
    'admin_password_hash' => '',
    'admin_session_ttl'   => 1800,
    // 可选：GitHub 只读 Token（fine-grained / classic 均可），仅用于后台检查 Web 更新，避免匿名 API 限流 403
    'github_token'        => getenv('GITHUB_TOKEN') ?: '',
];
