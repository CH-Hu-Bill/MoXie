<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/history.php';

$refA = str_repeat('a', 32) . '.jpg';
$refB = str_repeat('b', 32) . '.jpg';
$refC = str_repeat('c', 32) . '.jpg';
$refs = historyCollectReferencedUploadNames(
    [['content' => '<img src="upload.php?class_id=x&file=' . $refA . '">']],
    [['image' => $refB]],
    [['image' => $refC]]
);
check(isset($refs[$refA]), 'history ref collected');
check(isset($refs[$refB]), 'gallery ref collected');
check(isset($refs[$refC]), 'trash ref collected');

$dir = sys_get_temp_dir() . '/lw-hc-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$unref = str_repeat('d', 32) . '.jpg';
foreach ([$refA, $refB, $refC, $unref] as $f) file_put_contents($dir . '/' . $f, 'x');
$deleted = historyPruneUploads($dir, $refs);
check(in_array($unref, $deleted, true), 'unreferenced deleted');
check(!file_exists($dir . '/' . $unref), 'unreferenced file gone');
check(file_exists($dir . '/' . $refA), 'history file kept');
check(file_exists($dir . '/' . $refB), 'gallery file kept');
check(file_exists($dir . '/' . $refC), 'trash file kept');
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);
done();
