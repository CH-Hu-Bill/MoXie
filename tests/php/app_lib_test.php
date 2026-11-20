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

$gate = appVersionGate('1.2.2', '2.0.0', '2.0.0', '2.0.0', '2.0.0');
checkSame(true, $gate['has_update'], 'old app has update');
checkSame(true, $gate['force_update'], 'old app forced');
checkSame(false, $gate['server_too_old'], 'server new enough');

$gate = appVersionGate('2.0.0', '2.0.0', '2.0.0', '1.9.0', '2.0.0');
checkSame(false, $gate['force_update'], 'current app not forced');
checkSame(true, $gate['server_too_old'], 'old server blocked');

done();
