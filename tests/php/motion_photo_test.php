<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/motion_photo.php';

$fixture = __DIR__ . '/fixtures/motion_sample.jpg';
check(is_file($fixture), 'motion fixture exists');
$bytes = file_get_contents($fixture);
$range = motionPhotoFindVideoRange($bytes);
check($range !== null, 'video range found');
checkSame(2223, $range['length'] ?? 0, 'video length matches appended mp4');
$video = motionPhotoExtractVideo($fixture);
check($video !== null && is_file($video), 'video extracted');
check($video !== null && filesize($video) > 0, 'video non-empty');
if ($video) {
    $head = file_get_contents($video);
    checkSame('ftyp', substr($head, 4, 4), 'extracted bytes are mp4');
    @unlink($video);
}

$plain = __DIR__ . '/fixtures/plain.jpg';
check(is_file($plain), 'plain fixture exists');
checkSame(null, motionPhotoExtractVideo($plain), 'plain jpeg is not motion');
done();
