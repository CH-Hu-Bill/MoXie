<?php
/**
 * 全局配置文件 (示例)
 * 
 * 使用方法：
 *   1. 复制此文件为 config.php：cp config.example.php inc/config.php
 *   2. 填入你的 DeepSeek API 密钥和管理员密码
 *   3. config.php 已被 .gitignore 忽略，不会提交到仓库
 */
return [
    'app_secret' => getenv('APP_SECRET') ?: 'replace-with-at-least-32-random-characters',
    'allowed_origins' => ['*'],
    'deepseek' => [
        'api_key'  => 'sk-your-api-key-here',
        'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
        'model'    => 'deepseek-chat',
    ],
    'admin_password'    => 'change-this-password',
    'admin_session_ttl' => 1800,
];
