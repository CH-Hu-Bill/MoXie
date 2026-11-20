<?php
/**
 * Web / API 版本唯一来源。
 *
 * 发布 Web 时同步修改这里的版本号，并打 tag `web-v{version}`。
 * 旧 APP 仍可读取 check_version 的旧字段；新增字段只增不改。
 */

const LW_WEB_VERSION = '2.0.1';
const LW_API_VERSION = 2;
const LW_MIN_APP_VERSION = '2.0.0';
const LW_MIN_WEB_VERSION = '2.0.0';
const LW_RELEASED_AT = '2026-11-20';

function lwVersionPayload(): array {
    return [
        'web_version' => LW_WEB_VERSION,
        'api_version' => LW_API_VERSION,
        'min_app_version' => LW_MIN_APP_VERSION,
        'min_web_version' => LW_MIN_WEB_VERSION,
        'released_at' => LW_RELEASED_AT,
    ];
}
