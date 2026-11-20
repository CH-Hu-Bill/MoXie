<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/app_lib.php';

$words = [
    ['id' => 'w1', 'word' => 'a', 'meaning' => '甲'],
    ['id' => 'w2', 'word' => 'b', 'meaning' => '乙'],
];
$out = appApplyWrongFlags($words, ['w1' => ['marked_at' => '2026-01-01']]);
checkSame(true, $out[0]['is_wrong'], 'wrong word flagged');
checkSame(false, $out[1]['is_wrong'], 'normal word not flagged');
done();
