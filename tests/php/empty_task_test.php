<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/tasks.php';

$tasks = [
    't1' => ['id' => 't1', 'status' => 'completed', 'word_ids' => ['w1']],
    't2' => ['id' => 't2', 'status' => 'pending', 'word_ids' => ['w1', 'w2']],
    't3' => ['id' => 't3', 'status' => 'pending', 'word_ids' => ['w2']],
];
$r = tasksRemoveWordAndPrune($tasks, 'w1');
check(!isset($r['tasks']['t1']), 'empty completed task removed');
checkSame(['w2'], array_values($r['tasks']['t2']['word_ids']), 'non-empty task keeps other word');
check(isset($r['tasks']['t3']), 'unrelated task kept');
checkSame(['t1'], array_values($r['removed_ids']), 'removed id reported');
done();
