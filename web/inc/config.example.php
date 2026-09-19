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
    'allowed_origins' => ['*'],
    'admin_password'    => 'change-this-password',
    'admin_session_ttl' => 1800,
];
