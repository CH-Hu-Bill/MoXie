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
    'garden-front' => [
        'title' => '芽阵前线',
        'desc' => '选卡守线、种植物、挡怪潮。原创塔防，24 株植物、19 类敌人。',
        'meta' => '塔防 · 3–10 分钟',
        'icon' => '<div class="game-icon game-icon-mini game-icon-garden" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M32 55V28" stroke="#2b2b2b"/><path d="M32 34c-13 0-20-7-20-16 10 0 18 5 20 16Z" fill="#86efac"/><path d="M32 28c12 0 18-6 18-14-9 0-16 4-18 14Z" fill="#4ade80"/><circle cx="32" cy="20" r="6" fill="#facc15" stroke="#2b2b2b"/></svg></div>',
        'source' => __DIR__ . '/../games/mini/garden-front.html',
        'base' => 'games/mini/',
        'auto_start' => true,
        'soft_controls' => false,
    ],
    'snake-game' => [
        'title' => '霓虹贪吃蛇',
        'desc' => '星环远征、六种模式，支持方向键和滑动操作。',
        'meta' => '动作 · 2–5 分钟',
        'icon' => '<div class="game-icon game-icon-mini game-icon-snake" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16h18a8 8 0 0 1 0 16H18a8 8 0 0 0 0 16h26" stroke="#22c55e"/><circle cx="48" cy="48" r="3" fill="#22c55e"/><circle cx="50" cy="14" r="8" fill="#fef08a" stroke="#2b2b2b"/><path d="M46 13h.01M54 13h.01M47 18c2 2 6 2 8 0" stroke="#2b2b2b"/></svg></div>',
        'source' => __DIR__ . '/../games/mini/snake-game.html',
        'base' => 'games/mini/',
        'auto_start' => true,
        'soft_controls' => false,
    ],
    'gem-garden' => [
        'title' => '晶糖花园',
        'desc' => '交换晶糖、完成四季委托，带特殊糖块和小锤道具。',
        'meta' => '三消 · 3–8 分钟',
        'icon' => '<div class="game-icon game-icon-mini game-icon-gem" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"><path d="M20 14h24l10 14-22 26L10 28Z" fill="#c4b5fd" stroke="#2b2b2b"/><path d="M10 28h44M20 14l12 14 12-14M32 28v26" stroke="#fff"/></svg></div>',
        'source' => __DIR__ . '/../games/mini/gem-garden.html',
        'base' => 'games/mini/',
        'auto_start' => true,
        'soft_controls' => false,
    ],
    'pixel-clues' => [
        'title' => '像素线索',
        'desc' => '按行列数字推理涂格，12 张唯一解逻辑图鉴。',
        'meta' => '逻辑 · 5–10 分钟',
        'icon' => '<div class="game-icon game-icon-mini game-icon-pixel" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="11" y="11" width="42" height="42" rx="4" fill="#fef3c7" stroke="#2b2b2b"/><path d="M11 25h42M11 39h42M25 11v42M39 11v42" stroke="#d6c7a8"/><rect x="11" y="11" width="14" height="14" fill="#2b2b2b"/><rect x="25" y="25" width="14" height="14" fill="#2b2b2b"/><rect x="39" y="39" width="14" height="14" fill="#2b2b2b"/><rect x="39" y="11" width="14" height="14" fill="#2b2b2b"/></svg></div>',
        'source' => __DIR__ . '/../games/mini/pixel-clues.html',
        'base' => 'games/mini/',
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
        'auto_start' => true,
        'soft_controls' => false,
    ],
];
