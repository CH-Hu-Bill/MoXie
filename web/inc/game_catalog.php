<?php
/**
 * 内置小游戏目录。source 可以是 PHP 文件（return HTML）或单文件 HTML。
 * 新增游戏时同时检查 game_time_api.php 是否通过本目录取白名单。
 */
return [
    '2048' => [
        'title' => '2048',
        'desc' => '合并数字，冲击 2048。支持方向键和触屏滑动。',
        'meta' => '数字 · 2–5 分钟',
        'icon' => '<div class="game-icon game-icon-2048" aria-hidden="true"><span>2</span><span>0</span><span>4</span><span>8</span></div>',
        'source' => __DIR__ . '/game_2048.php',
        'base' => 'games/2048/',
        'auto_start' => true,
        'soft_controls' => false,
    ],
    'sudoku' => [
        'title' => '数独工坊',
        'desc' => '经典、对角线和杀手数独，支持候选笔记、撤回和提示。',
        'meta' => '益智 · 5–15 分钟',
        'icon' => '<div class="game-icon game-icon-mini game-icon-sudoku" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"><rect x="10" y="10" width="44" height="44" rx="4" fill="#e0f2fe" stroke="#2b2b2b"/><path d="M25 10v44M39 10v44M10 25h44M10 39h44" stroke="#2b2b2b"/><path d="M17 18h3M32 32h3M47 46h3" stroke="#2d5da1"/></svg></div>',
        'source' => __DIR__ . '/../games/mini/sudoku-studio.html',
        'base' => 'games/mini/',
        'auto_start' => false,
        'soft_controls' => false,
    ],
];
