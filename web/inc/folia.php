<?php
/**
 * ============================================================
 * Folia 在线音乐 — 入口地址
 * ============================================================
 *
 * main 分支只做“入口 + 转场”，不在本机部署 Folia。
 *
 * 默认打开官方公开 Web 版：
 *   https://folia-major.vercel.app/
 *
 * 如果要换成自己部署的 Folia、或其它成品地址：
 *   1. 修改下面的 FOLIA_DEFAULT_URL
 *   2. 或者设置环境变量 LW_FOLIA_URL
 *
 * 注意：公开实例需要联网；登录/曲库数据由 Folia 自己的服务处理，
 * 与 ListenWrite 的班级数据无关。
 * ============================================================
 */

if (!defined('FOLIA_DEFAULT_URL')) {
    define('FOLIA_DEFAULT_URL', 'https://folia-major.vercel.app/');
}

function folia_public_url(): string
{
    $url = getenv('LW_FOLIA_URL');
    if (!is_string($url) || trim($url) === '') {
        $url = FOLIA_DEFAULT_URL;
    }
    $url = trim($url);

    if (!preg_match('#^https?://#i', $url)) {
        return FOLIA_DEFAULT_URL;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return FOLIA_DEFAULT_URL;
    }

    return rtrim($url, '/');
}
