<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/app_lib.php';

$base = 'https://example.test';
$live = appGalleryItemOut([
    'id' => 'g1',
    'image' => str_repeat('a', 32) . '.jpg',
    'live_video' => str_repeat('b', 32) . '.mp4',
    'type' => 'live',
    'description' => '实况',
    'uploaded_at' => '2026-11-20 12:00:00',
], 'c1', $base);
checkSame('live', $live['type'], 'live type');
check(str_contains($live['live_video_url'], 'upload.php'), 'live video url');
check(str_contains($live['live_video_url'], '.mp4'), 'live video extension');
check(str_contains($live['thumb_url'], '.jpg'), 'live cover is still image');

$static = appGalleryItemOut([
    'id' => 'g2',
    'image' => str_repeat('c', 32) . '.jpg',
    'description' => '普通',
    'uploaded_at' => '2026-11-20 12:00:00',
], 'c1', $base);
checkSame('static', $static['type'], 'static type');
checkSame(null, $static['live_video_url'], 'static has no live video');
done();
